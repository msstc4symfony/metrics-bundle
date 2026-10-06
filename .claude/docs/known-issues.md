# Известные проблемы и находки

## Устройство конфигурации, каталога метрик и DBAL-проводки

- Минимум Symfony 7.4 (`^7.4|^8.0`). Lowest-ячейка 7.4 без CI-only `conflict`
  `symfony/error-handler <7.4.17 || >=8.0,<8.1.5` даёт 18 risky kernel-тестов — см. `tooling.md`.
- Конфигурация — дерево `msstc4symfony_metrics` в `MetricsBundle::configure()` (`AbstractBundle`),
  `loadExtension()` пишет параметры `msstc4symfony_metrics.*` и импортирует `services.php`;
  `symfony/yaml` не нужен. `getPath()` переопределён на `src/`, иначе `AbstractBundle` вернул бы
  корень пакета и сломал импорт `@MetricsBundle/Presentation/Controller/`.
  `floatNode` оставляет целое как есть (`0` → `0`), поэтому `loadExtension()` приводит его к float;
  env-плейсхолдер остаётся строкой до runtime.
- Метрику профилирования объявляет и пишет только мост; своего кода профилирования у бандла нет.
  Дубликат имени метрики — `LogicException` в `MetricRepositoryFactory::create()` (с обоими
  кейсами в сообщении); один и тот же enum в списке дважды дубликатом не считается.
- Кейсы `MESSENGER_*` — в `MetricLabelEnum`, отдельного enum и пасса для Messenger нет. Без
  Messenger они есть в `metrics:list`, но не в `/_/metrics` (метрики регистрируются в реестре
  лениво, при первой записи).
- DBAL: метка `connection` — имя соединения DoctrineBundle. `AddDoctrineDBALMonitorPass`
  регистрирует по одному `NamedConnectionMiddleware` на соединение DoctrineBundle (id
  `msstc4symfony_metrics.doctrine.middleware.<name>`, аргумент `$connectionName`, тег
  `doctrine.middleware` с атрибутом `connection: <name>`). Middleware — `final readonly` с
  обязательным именем и годится для ручной проводки без DoctrineBundle. Без DoctrineBundle пасс
  ничего не регистрирует.

## HTTP-мониторинг: только транспорт и прозрачный `MonitoredResponse`

Ловушка: `class_exists(HttpClientInterface::class)` для интерфейса всегда `false` — пасс с такой
проверкой не срабатывает. Ревью (2026-09-30) нашло, что первоначальный дизайн пасса:
- декорировал и клиенты, и `http_client.transport` — каждый запрос считался дважды;
- у scoped-клиентов Symfony 7.4+/8 видел относительный URL — пустой host;
- читал статус в `request()` — терял конкурентность и проверку статуса в деструкторе;
- не имел `reset()` — тег `kernel.reset` переезжал на декоратор и терялся.
Первая переделка на `AsyncResponse` (2026-10-01) сломала контракт: idle-timeout
приходил как `TransportException` вместо `TimeoutException`, а отменённые запросы
писали длительность. Сейчас: только транспорт, прозрачная `MonitoredResponse`,
метрики при чтении ответа или в `stream()`; длительность — только у полностью
полученных ответов. Статус пишется и когда чтение бросает 4xx/5xx
(`HttpExceptionInterface`), и в деструкторе непрочитанного ответа; ошибки транспорта
статуса не дают. `toStream()` отдаёт буферизованный и перематываемый поток внутреннего
ответа (Psr18Client/HttplugClient делают `seek`), а длительность фиксирует read-фильтр
`CompletionStreamFilter` на EOF; закрытие недочитанного потока тоже его вызывает, так
что длительность у такого ответа будет частичной. Деструктор не пробрасывает ошибки
хранилища метрик. Явный вызов внутреннего `__destruct()` (как в `TraceableResponse`)
у Native/Amp дважды уменьшает счётчик активных ответов — только чуть раньше чистится
DNS-кеш, корректность не страдает. Тег ассемблеров
исправлен с `metrics.htp_client...` на `metrics.http_client.url_assembler`.

## `apc://`, `apcng://`, `inmemory://` уходили в InMemory

`parse_url('apc://')` === `false` → «malformed DSN» → InMemory, то есть метрики
APCu не шарились между воркерами. Исправлено разбором схем без хоста (в т.ч. `apc:///`,
`apcng://?prefix=x`). `redis://` без хоста по-прежнему ошибка — осознанно.

## Таблица `unknown` у запросов без `WHERE`

Регулярка `Statement::assembleTableName` требовала пробел после имени таблицы:
`SELECT * FROM users` давал `unknown`. Теперь граница слова.

## Бандл не запускался в приложении с Monolog

Цикл: `Storage\Factory` → `LoggerInterface` (задекорирован `HandlerDecorator`) →
`ErrorCollector` → `RegistryInterface` → `Adapter` → `Factory`. Контейнер не
собирался вообще; тесты компилер-пассов на голом `ContainerBuilder` этого не видели.
Исправлено: `Factory` пишет в свой канал `metrics_bundle` (`#[WithMonologChannel]`), канал
исключён из декорирования. Ловит `tests/Integration/ContainerCompileTest`
(настоящее ядро: Framework + Monolog + Metrics). `monolog/monolog ^3.5` объявлен явно —
атрибут и `Monolog\Level` есть только в 3.x.

## Опциональные классы вне resource-скана (2026-10-04 UTC)

`symfony/dependency-injection` 7.4.0 при resource-скане грузит каждый класс и падает на отсутствующем
интерфейсе: без `symfony/http-client` приложение не собиралось (`Interface
"Symfony\Contracts\HttpClient\ResponseInterface" not found` из `MonitoredResponse`; нашёл lowest-прогон
моста). Новые версии DI превращают это в ошибку определения и молчат. Поэтому из скана исключено всё,
что требует опциональной библиотеки (`Infrastructure/{Doctrine,Elastica,Monolog}/`, `HttpClient/*.php`,
`MessengerEventListener`), а нужные сервисы регистрируются с гардом (`services.php`) или пассами.
Исключённый путь всё равно даёт определение с тегом `container.excluded`, но класс не рефлексируется.
Тест: `tests/Integration/DependencyInjection/ServiceScanTest`. Воспроизведение: lowest-копия
(`symfony/*` 7.4.*, `--prefer-lowest`) с удалённым `symfony/http-client` — 17 ошибок до фикса, 0 после.
Новый класс с опциональной зависимостью в сканируемых каталогах — регрессия.
Ловушка: `URLAssembler/` исключать нельзя — `#[AutoconfigureTag]` на `AssemblerInterface` регистрирует
instanceof-тег `metrics.http_client.url_assembler`, только если интерфейс сам попал в скан (первая
версия фикса исключила весь `HttpClient/`, ассемблеры приложения молча теряли тег; тест
`ServiceScanTest::testAnApplicationAssemblerIsTaggedThroughTheInterfaceAttribute`).

## Маршрут `/_/metrics`

С `routing.controllers` (рецепт Symfony 7.4+) маршрут грузится сам — см. раздел «Маршрут `/_/metrics` на Symfony 7.4+ грузится сам» ниже.
Приложение, импортирующее только `../src/Controller/`, импортирует
`@MetricsBundle/Presentation/Controller/` (`type: attribute`).

## `symfony/monolog-bundle` — `^3.11|^4.0`

3.x не ставится на Symfony 8.

## Elastica: две точки измерения (с v1.1.0)

- **7**: `TimingTransport` подменяет транспорт соединений в `boot()` (список клиентов —
  `SaveElasticaClientsListPass`). Неуспех = транспорт бросил. Длительность — `Response::getQueryTime()`.
- **8**: транспортного API нет (клиент строит `elastic/transport` поверх PSR-18 в конструкторе),
  поэтому `DecorateElasticaClientsPass` на этапе компиляции кладёт `TimingHttpClient` в
  `transport_config.http_client`. Неуспех: исключение PSR-18; статус < 400 — успех + длительность;
  ≥ 400 — неуспех, только если тело не JSON-объект или в нём есть ключ верхнего уровня `error`
  (`404` c `found: false` и пустой `HEAD 404` — успех, как на 7); non-seekable тело при ≥ 400 —
  неуспех без чтения; тело, чтение/перемотка которого бросает, — неуспех, исключение не выпускается,
  перемотка повторяется под try (`isFailedErrorResponse()`).
- Сознательные расхождения с 7: не-JSON тело при ≥ 400 на 7 — успех (`Response::getData()`
  оборачивает в `['message' => …]`, `hasError()` = false), на 8 — неуспех; частичный отказ шардов
  (`200`, `_shards.failed` > 0) на 7 — неуспех (`PartialShardFailureException`), на 8 — успех.
- Метки одинаковы: путь без ведущего `/`. Проверено вживую (2026-10-05 UTC):
  `getCluster()->getHealth()` даёт `_cluster/state` + `_cluster/health` и на Elastica 7.3.2 /
  ES 7.17.29, и на Elastica 8.2.0 / ES 8.19.22 (`getCluster()` сначала грузит state).
- Расхождения на 8: каждая повторная попытка транспорта (`retries`, по умолчанию = число хостов) —
  отдельный запрос в метриках; хост с base path (`http://es:9200/prefix`) даёт метку с префиксом
  (`prefix/index/_search`), на 7 — без.
- Не измеряются на 8 (лог компиляции `Elastica client "<id>" is not measured: …`): конфиг не
  литеральный массив (DSN-строка, параметр, ссылка), `transport_config` не массив.
- `transport_config.http_client_config`/`http_client_options` Elastica применяет через адаптер по
  классу конкретного клиента (`AdapterOptions::HTTP_ADAPTERS`) и на обёртке бросала бы
  `HttpClientException`. Поэтому пасс убирает оба ключа из `transport_config` и передаёт их в
  `ConfiguredHttpClientFactory::create(<http_client|null>, <config>, <options>)` (рантайм, `@internal`),
  которая повторяет `Client::setTransportClientOptions()` (пустые → клиент как есть; иначе адаптер,
  та же ошибка) — результат оборачивается `TimingHttpClient`. FOSElasticaBundle 7 всегда задаёт
  `http_client_options` (headers/timeout). Классы elasticsearch-php 8 в фабрике названы строками
  (лок CI на 7, PHPStan анализирует и там), класс исключения — в static-свойстве: константу PHPStan
  сворачивает в литерал и на 7 ругается `class.notFound`/`argument.type`.
- FOSElasticaBundle регистрирует клиентов как `ChildDefinition('fos_elastica.client_prototype')`;
  класс — у абстрактного родителя, а наши пассы идут до `ResolveChildDefinitionsPass`, так что
  `getClass()` у клиента `null`. `ElasticaClientDefinitions::lineage()` идёт по цепочке родителей
  (класс и конфиг). Конфиг ищется по ключам `index_0` (так `ChildDefinition::replaceArgument(0)`
  хранит позиционный аргумент — FOS 6), `$config` (FOS 7), `0`; записывается назад тем же ключом через
  `replaceArgument()`, иначе после слияния родителя появился бы лишний позиционный аргумент.
- Подклассы `Elastica\Client` (FOSElasticaBundle) подхватываются на обеих версиях через
  `ElasticaClientDefinitions` (до v1.1.0 — только класс ровно `Elastica\Client`).
- Тестовый клиент Elastica 7 нельзя задать `['url' => 'http://host:9200']` — «Malformed URL»;
  `ElasticsearchKernel` разбирает `ELASTICSEARCH_URL` на `host`/`port`.

## Elastica: нормализация метки `path` (v1.2.0, 2026-10-06 UTC)

- `ElasticaPathSanitizer::sanitize()` (`@internal`, `Infrastructure/Elastica/`) — общая для 7 и 8:
  сегмент после `_doc|_create|_update|_source|_explain|_termvectors` (если до него есть непустой
  сегмент-индекс и после — непустой id) становится `:id`, затем весь путь проходит
  `HttpClient\PathSanitizer` (UUID → `:uuid`, цифры → `:id`, 24+ hex → `:hash`). `''` остаётся `''`
  (у `PathSanitizer` `''` → `/`), ведущий `/` сохраняется как был (на 7 путь с `/`, на 8 без).
  Индекс целиком из цифр/UUID/длинного hex тоже заменится — сознательно, правила общие.
- Флаг `msstc4symfony_metrics.elastica.sanitize_path` (`DecorateElasticaClientsPass::SANITIZE_PATH_PARAMETER`,
  default true). 7: `boot()` читает параметр и передаёт в `TimingTransport::init(..., $sanitizePath)`.
  8: пасс добавляет третьим аргументом `TimingHttpClient` строку `%…sanitize_path%`, только если
  параметр есть (голый `ContainerBuilder` в тестах пасса — аргументов два, дефолт true). Оба новых
  параметра — опциональные хвостовые, Roave BC к v1.1.0 чист.
- Сквозной тест без сети: `ElasticaPathLabelWiringTest` + `ElasticaWiringKernel` (7 — `NullTransport`
  объектом в `transport`, 8 — `Test\Support\StaticJsonPsr18Client` в `transport_config.http_client`).
- Находка: на 7 `Response::getQueryTime()` может вернуть `null` (так у ответа `NullTransport` по
  умолчанию) → `TypeError` в `ElasticaCollector::setRequestDuration()` внутри `TimingTransport::exec()`,
  запрос приложения падает. Боевой HTTP-транспорт время всегда ставит; в тесте ответ `NullTransport`
  задан с `setQueryTime()`. Не исправлено (вне задачи 1.2.0).

## CI-лок держит Elastica 7, хотя `composer-ci.json` разрешает `^7.3|^8.0`

`composer-ci.lock` намеренно на Elastica 7 (`composer update ruflin/elastica --with ruflin/elastica:^7.3`).
На лок-е с 8 PHPStan падает internal error: `TimingTransport extends AbstractTransport`,
которого в 8 нет, а phpstan-doctrine (`EntityNotFinalRule`) делает `class_exists()` на каждом
классе → fatal при автозагрузке; плюс `Request::getPath()`, `Response::getQueryTime()`,
`Client::getConnections()` в 8 отсутствуют. `phpstan.dist.neon` — шаблон стандарта, ни stubs,
ни `excludePaths` туда не добавить. Поэтому PHPStan/Rector/deptrac/Infection идут на 7, а
Elastica 8 покрывают ячейки PHPUnit (`composer update` → highest = 8; lowest = 7.3.0) и
job «Elasticsearch integration» (8.19.22). Не делать `composer update` лока без `--with ruflin/elastica:^7.3`.
`php-http/discovery` — явный require-dev (на 7 его никто не тянет, а
`DecorateElasticaClientsPass` ссылается на `Psr18ClientDiscovery`); его composer-плагин выключен
(`allow-plugins: false`).

## `Statement::execute()` и DBAL 3/4

DBAL 3 передаёт параметры в `execute($params)`, DBAL 4 параметр убрал. Метод
принимает `mixed $params = null` и форвардит `func_get_args()`. CI-лок держит DBAL 4;
DBAL 3 (3.10 + Symfony 7.4) проверен вручную 2026-10-01 — весь набор зелёный. На
Symfony 8 DBAL 3 не ставится (конфликт с `symfony/http-foundation`).

## Elastica: клиенты создаются в `boot()`

`wireElasticaTransports()` (только Elastica 7) достаёт каждый клиент и соединение при загрузке
ядра — один раз на воркер. На Elastica 8 клиенты остаются ленивыми: обёртка — в определении сервиса.

## BC check

Roave BC check (дефолт `bundle-standard`) блокирующий и сравнивает с последним стабильным тегом.

## Профилирование вынесено в мост (2026-10-01 UTC)

Метрику `profiling_span_duration_histogram_seconds` объявляет и пишет мост
`msstc4symfony/metrics-bridge-profiling` (свой enum, дописанный в `msstc4symfony_metrics.metric_enums`).
Своего процессора профилирования у бандла нет.

## DBAL-метрики не собирались с DoctrineBundle (2026-10-01 UTC)

Три ловушки, на которых пасс не срабатывает в реальном приложении (все учтены в текущей проводке):

1. DoctrineBundle объявляет соединения как `ChildDefinition('doctrine.dbal.connection')` **без
   класса** (`setClass()` — только при `wrapper_class`), поэтому фильтр
   `getClass() !== null && is_a(..., Connection::class)` их не находил.
2. Даже если бы нашёл — `Middleware` регистрировался без тега `doctrine.middleware`. DoctrineBundle
   применяет только тегированные middleware (`MiddlewaresPass` → `setMiddlewares` на
   `doctrine.dbal.<name>_connection.configuration`); нетегированный приватный сервис удалялся.
   Автоконфигурация (`registerForAutoconfiguration(Driver\Middleware)`) тоже не помогала:
   `Infrastructure/Doctrine/DBAL/` исключён из resource-скана (`services.php`), а `ResolveInstanceofConditionalsPass`
   (priority 100) отрабатывает раньше нашего пасса.
3. Порядок: оба пасса `TYPE_BEFORE_OPTIMIZATION` с priority 0, при равном приоритете — порядок
   регистрации бандлов. Flex дописывает новый бандл в конец `bundles.php`, т.е. после
   DoctrineBundle — тег появился бы уже после того, как `MiddlewaresPass` его прочитал.
   Проверено тестом: без priority вариант «DoctrineBundle первым» оставался пустым.

Исправление: пасс регистрирует тегированный `doctrine.middleware` (по
`NamedConnectionMiddleware` на соединение, см. начало файла) при наличии параметра
`doctrine.connections`, с `priority: AddDoctrineDBALMonitorPass::PRIORITY` (= 1). Определение,
заданное приложением (тот же id), не перетирается.

Попутно: DBAL отправляет запросы **без параметров** (`executeQuery()`/`executeStatement()` с
пустым `$params`) в `Driver\Connection::query()`/`exec()`, минуя `prepare()`. Middleware
оборачивал только `prepare()` → такие запросы не считались. Теперь замер вынесен в
`QueryMeter` (@internal), им пользуются `Statement::execute()` и `Connection::query()/exec()`.
`exec()` объявлен с возвратом `int` (DBAL 3 — `int`, DBAL 4 — `int|string`; строка только для
числа строк > PHP_INT_MAX, приводится `(int)`).

Метка `connection` — имя соединения DoctrineBundle.

Тест: `tests/Integration/Infrastructure/Doctrine/DBAL/DoctrineDbalMetricsTest` — настоящее ядро
(`TestKernel` подключает DoctrineBundle, sqlite in-memory), оба порядка бандлов (env
`metrics_first` переворачивает порядок). В minimal-профиле самопропускается.

### Дополнения (2026-10-01 UTC)

- Ловушка регулярки: `SELECT\s+.+?\s+FROM` — ленивый, берёт первый `FROM`. Жадный
  вариант с флагом `s` брал последний `FROM` (подзапрос), а на длинных `IN (...)` упирался в
  `pcre.backtrack_limit` → `preg_match` возвращал `false` → метка `unknown`. Разбирается только
  первые 16 KiB SQL (`QueryMeter::MAX_PARSED_SQL_LENGTH`); `FROM` дальше этого порога → `unknown`.
  Ленивый вариант тоже берёт `FROM` из скобок и комментариев — поэтому разбирается только верхний уровень (см. «Метка `table="partitioned"`» ниже).
- `TestKernel::hasDoctrine()` = DoctrineBundle **и** `ext-pdo_sqlite`; `pdo_sqlite` явно добавлен
  во вход `extensions` в `.github/workflows/checks.yml` (общий workflow не трогали).
- Хелпер выборки сэмплов из реестра — `tests/Support/RegistrySamples` (Unit и Integration); на него
  переведены все тесты.

## Маршрут, метка соединения, Messenger (2026-10-02 UTC)

### Маршрут `/_/metrics` на Symfony 7.4+ грузится сам

До Symfony 7.4 маршрут сам не регистрировался. С 7.4 FrameworkBundle
тегирует `routing.controller` каждый автоконфигурируемый сервис с `#[Route]`
(`registerAttributeForAutoconfiguration(Route::class)`), а рецепт `config/routes.yaml` импортирует
`resource: routing.controllers` (`AttributeServicesLoader`). Healthcheck-bundle «грузит маршруты
сам» ровно так — своего лоадера у него нет. `GetMetricsController` автоконфигурируется
(`autoconfigure()` в `services.php`), поэтому механизм работал и у нас; исходники не
менялись, добавлены тест и README. Двойной импорт (`routing.controllers` + ручной) безопасен:
`RouteCollection` хранит маршрут по имени `metrics-get`. Ловушка: если когда-нибудь выключить
автоконфигурацию контроллера, маршрут молча пропадёт у 7.4+-приложений —
`ContainerCompileTest::testMetricsRouteLoadsOnceThroughRoutingControllers` это ловит.

### Метка `connection` по имени соединения

Схема — в начале файла. Определение, исключённое из resource-скана, всё равно есть в
контейнере с тегом `container.excluded` — в тестах проверять и тег, а не только `hasDefinition()`.

### Messenger

Метрики — кейсы `MESSENGER_*` в `MetricLabelEnum`.
`AbstractCollector` находит метрику через `MetricRepository::find()` для любого enum, список
нужен только `findAll()`.

`MessengerEventListener`: `SendMessageToTransportsEvent` → `messenger_message_sent` по каждому
ключу `getSenders()` (повторы и отправка в failure-транспорт идут мимо `SendMessageMiddleware` —
не считаются; событие до `send()`, упавшая отправка тоже считается). Воркер:
`WorkerMessageReceivedEvent` (priority -1024, последним) запоминает старт, `Handled`/`Failed`
(priority 0 — после `SendFailedMessageForRetryListener` с 100, который выставляет `willRetry()`)
пишут счётчик и длительность. Старты — в `WeakMap` по **объекту сообщения**: воркер пересоздаёт
конверты, но объект сообщения тот же (сам Worker держит `keepalives[$envelope->getMessage()]`).
Одиночный слот + `kernel.reset` не годится: у batch-обработчиков ack приходит после `Received`
следующих сообщений и после `ResetServicesListener` (сбрасывает сервисы на каждом не-idle
`WorkerRunningEvent`) — длительность терялась бы. Запись удаляется на исходе и при
`shouldHandle() === false`, остальное уходит вместе с объектом (тест на `WeakReference`).
Синхронно обработанные сообщения воркер не видит.
Тест на ядре: `tests/Integration/Infrastructure/Messenger/MessengerMetricsTest` — транспорт
`in-memory://`, `messenger:consume async --limit=1` через `CommandTester`.

Бакеты гистограммы длительности начинаются с 5 мс (обычный обработчик — миллисекунды); менять их —
смена значений `le`, т.е. BC.

## Redis-хранилище не восстанавливалось после рестарта Redis (2026-10-02 UTC)

Симптом (Symfony 8.1, RoadRunner + `messenger:consume`, `redis://redis:6379?database=5`): после
`docker stop/start` Redis (~20 с) воркеры до конца жизни пишут `Redis server redis:6379 went away` и
`Cannot save metric ...`, счётчики заморожены. (`Failed to fetch key ...` — это лог Symfony Cache
приложения, не бандла.)

Причина — две вместе:
1. **phpredis 6** (проверено на 6.3.0): команда, потерявшая соединение, бросает `Connection lost` и
   переводит сокет в `REDIS_SOCK_STATUS_FAILED`; из этого состояния каждая следующая команда сразу
   бросает `Redis server host:port went away`, **даже когда сервер уже поднялся** — до явного
   `connect()`/`pconnect()`. Воспроизведено настоящим phpredis против фейкового RESP-сервера.
2. **promphp** (`PHPRedis` client / `RedisNg`) вызывает `connect()` один раз: флаг
   `connectionInitialized` после этого больше не сбрасывается.

Исправление: `ReconnectingRedisAdapter` (см. `architecture.md`). Решения:
- упавшая операция **не повторяется**: `EVAL` мог дойти до сервера до обрыва (read error) —
  повтор посчитал бы счётчик дважды. Теряется одна запись на обрыв;
- пока Redis лежит, каждая операция делает одну попытку подключения (timeout DSN, по умолчанию
  0.1 с) — как и при старте воркера с недоступным Redis;
- прочие исключения (например, `RuntimeException` от `json_encode` меток) соединение не сбрасывают;
- старый `\Redis` освобождается вместе со старым адаптером — утечки на долгоживущем процессе нет;
  с `persistent_connections=1` новый `\Redis` берёт persistent-сокет через `pconnect()`, PHP
  проверяет его живость;
- префикс promphp (`AbstractRedis::setPrefix`) статический — переживает пересоздание;
- cooldown между попытками (ревью, CR-006) сначала был отклонён, **потом добавлен** — см.
  раздел «Circuit breaker» ниже;
- конструктор обёртки подключается (строит адаптер) **сразу**: иначе ошибка конструирования
  (нет ext-redis / `RedisNg`) не дойдёт до `try` в `Factory::create()` и откат на `InMemory`
  сломается (тест `testConnectsEagerlySoTheFactoryCanFallBackOnConstructionErrors`).

Попутно (найдено ревью): номер БД из пути DSN (`redis://host/4`) **игнорировался** —
`parse_url()` отдаёт `/4`, `is_numeric('/4') === false`, запись шла в БД 0. Исправлено
(`ltrim('/')` + `^\d+$`), интеграционный тест ждёт `SELECT 3`. `?database=` по-прежнему главнее.
`persistent_connections=1` проверен тем же интеграционным тестом: новый `\Redis` + `pconnect()`
после рестарта сервера работает (на дефолтных ini phpredis 6.3).
`FailingOnceDownRedis` повторяет типизированные сигнатуры phpredis 6 — на ext-redis 5 тест
самопропускается.

Нижняя граница `promphp/prometheus_client_php` — `^2.13` (см. «prefer-lowest» ниже); на 2.6 `redisng://`
уходил в `InMemory` (класса `RedisNg` нет до 2.7). `AbstractRedis` и
`RedisClientException` появились только в 2.15, поэтому обёртка типизирована `Redis|RedisNg`, а
`catch` по отсутствующему классу безопасен; data set «redis client exception» в
`ReconnectingRedisAdapterTest` на promphp < 2.15 самопропускается.

Predis бандл не поддерживает (схемы `predis://` нет). Для справки: promphp-клиент `Predis`
проверяет `isConnected()` в каждом `ensureOpenConnection()`, а Predis после `CommunicationException`
сам рвёт соединение — он восстановился бы без обёртки. APCu/InMemory соединений не держат.

Тесты: `tests/Unit/Infrastructure/Storage/ReconnectingRedisAdapterTest` (фейк
`tests/Support/Redis/FailingOnceDownRedis` моделирует FAILED-состояние phpredis) и
`tests/Integration/Infrastructure/Storage/RedisReconnectTest` — настоящий phpredis против
`tests/Support/Redis/resp-server.php` (RESP-сервер на PHP, отвечает `+OK`, пишет команды в лог),
который убивается и поднимается на том же порту; проверяет AUTH + SELECT (из query и из пути DSN,
а также с `persistent_connections=1`) + `EVAL` до и после рестарта.
Нужны только `ext-redis` и `proc_open`, реальный Redis не нужен.

Недоступный Redis не уводит в `InMemory`: конструктор
адаптера не подключается, откат срабатывает только на ошибке конструирования (DSN, схема, нет класса).
Опция `ssl_verify_peer` попадает в `$options['ssl']`, но promphp её не использует при `connect()` —
README так и говорит; поддержка TLS — отдельная задача.

## Регрессия переподключения — 500 при остановленном Redis (2026-10-02 UTC)

Симптом на стенде (showcase, RoadRunner, prod): при остановленном Redis запросы в gateway отдавали 500,
в логе `ERROR Warning: Redis::connect(): php_network_getaddresses: getaddrinfo for redis failed: Name
or service not known` (`ErrorException`, `PHPRedis.php:158`).

Механика: без обёртки клиент phpredis после обрыва застревал в FAILED и отвечал «went away» без сетевых
вызовов. С `ReconnectingRedisAdapter` каждая операция переподключается, а `connect()` при ошибке DNS **сначала выдаёт PHP
warning**, потом бросает `RedisException`. Symfony `ErrorHandler` с `framework.php_errors.throw: true`
(по умолчанию **и в prod**) бросает warning как `ErrorException` прямо из `connect()`. phpredis 6.3 при
этом всё равно бросает `RedisException` и цепляет `ErrorException` как `previous` (проверено).

Воспроизведено локально:
- `/_/metrics` → 500 (`StorageException` никто не ловил — так было и до переподключения);
- `ErrorException` доходил до обработчика приложения (интеграционный тест без исправления падает именно на этом).

Обычный запрос в ядре (`TestKernel`, копия gateway под `php -S` в prod) у меня 500 не дал: коллекторы
ловят `Throwable`. Точный путь, которым исключение ушло наружу на стенде под RoadRunner, не найден.
Поэтому исправление закрывает **все** выходы, а не один.

Исправление:
- `ReconnectingRedisAdapter::guard()` на время вызова ставит свой `set_error_handler` для
  `E_WARNING|E_NOTICE|E_USER_WARNING|E_USER_NOTICE`. Обработчик запоминает текст и возвращает `true`,
  поэтому обработчик приложения warning не видит. Предупреждение не превращается в исключение:
  успешная операция с notice не ломается. Любой `Throwable` оборачивается в `StorageException`
  (текст warning дописывается в сообщение), соединение сбрасывается только по Redis-исключениям.
- Запись (`update*`) не бросает **никогда**: сэмпл теряется, ошибка пишется в логгер `Factory` (канал
  `metrics_bundle`, исключён из `HandlerDecorator`, поэтому рекурсии через `ErrorCollector` нет).
  Чтение (`collect`, `wipeStorage`) бросает `StorageException`.
- `GetMetricsController` ловит `Throwable` → `503` text/plain + лог в `metrics_bundle`
  (`#[WithMonologChannel]`, логгер — обязательный аргумент конструктора).
- `AbstractCollector::processException` и адаптер глотают исключения самого логгера.
- Ревью: логи во время простоя ограничены по частоте. Первая ошибка пишется как `error`, дальше
  раз в 60 с идёт `warning` с числом потерянных сэмплов, при восстановлении — `info`. Часы
  монотонные (`hrtime`), в тестах подменяются через третий аргумент конструктора. Без этого каждый
  запрос писал бы несколько ERROR с трейсом.
- Обработчик в `guard()` ставится на `E_ALL`, а всё, что не warning или notice, передаётся
  предыдущему обработчику. Если поставить его с маской, PHP отдаёт непойманные типы (deprecation)
  **встроенному** обработчику, минуя Symfony: на RoadRunner это `display_errors` в STDOUT, то есть
  порча relay.
- PHP не отдаёт маску, с которой был зарегистрирован предыдущий обработчик. Поэтому обработчик,
  поставленный приложением только на `E_WARNING`, теперь получит из `guard()` и `E_USER_DEPRECATED`.
  Symfony `ErrorHandler` регистрируется на `E_ALL` и фильтрует сам, так что его это не касается.
  Типы, выключенные в `error_reporting()`, дальше не передаются.
- Ограничение частоты логов хранится в экземпляре сервиса, то есть в процессе. Работает в
  долгоживущих воркерах (RoadRunner, `messenger:consume`). Под PHP-FPM / `php -S` контейнер
  собирается заново на каждый запрос, и каждый запрос во время простоя пишет свой `error`. Если
  Redis «мигает», каждое мигание даёт пару error + info.
- Backoff переподключения здесь снова отклонили, и это оказалось ошибкой — см. «Circuit breaker».
- Генерик `guard()` с `@template T` (S-1) не взят: с void-замыканиями PHPStan не выводит `T`, а
  ограниченный шаблон этого не исправляет (пробовали). `collect()` собирает сэмплы через
  замыкание по ссылке.
- `metrics:clear` при `StorageException` выводит сообщение и завершается с кодом 1.

Тесты:
- `ReconnectingRedisAdapterTest`: глобальный обработчик бросает `ErrorException`, фейк поднимает
  `E_USER_WARNING`, обработчик должен быть вызван 0 раз;
- `RedisReconnectTest::testUnreachableRedisNeverReachesTheApplicationErrorHandler`: настоящий phpredis,
  `metrics-unresolvable.invalid` и закрытый порт;
- `ContainerCompileTest::testUnreachableRedisNeverTurnsRequestsInto500`: ядро, `/no-such-page` → 404,
  `/_/metrics` → 503.

Ловушка: `json_encode` в `updateCounter` у promphp результат не проверяет, и невалидный UTF-8 в метке
уходит в `EVAL` как `false`. `RuntimeException` бросает только `encodeLabelValues` (summary) —
тест «не-сетевой ошибки» построен на summary.

## Circuit breaker — CR-006 всё-таки был нужен (2026-10-02 UTC)

**Почему откладывали.** Исходное требование было «одна попытка подключения на операцию». Локально
connection refused и NXDOMAIN отказывают за доли миллисекунды: в песочнице DNS падает мгновенно с
`Temporary failure in name resolution`. Поэтому стоимость попытки выглядела нулевой.

**Почему понадобилось.** На стенде (RoadRunner, контейнер Redis остановлен, Docker DNS не знает
`redis`) версия без backoff давала:

| Запрос | Время |
|---|---|
| POST /orders | 6.3 с вместо ~30 мс |
| POST с Idempotency-Key | 13.8 с — клиентский таймаут 10 с, chaos e2e падает |
| `/_/metrics` | 0.68 с |

Запрос делает десятки операций с метриками, а каждая попытка стоит сотни миллисекунд на
DNS/connect. **Вывод:** стоимость попытки зависит от окружения, и единственная её граница —
количество попыток.

**Как устроено сейчас.** `ReconnectingRedisAdapter::$retryAt` хранится в процессе.
- Сетевая ошибка (`RedisException` / `RedisClientException` / `StorageException`) выставляет
  `retryAt = now + backoff`.
- До `retryAt` `guard()` сразу бросает `StorageException`, не вызывая connect. Запись при этом
  теряется (учитывается в ограниченном логе), чтение отдаёт `StorageException`, `/_/metrics`
  сразу отвечает 503.
- После окна делается ровно одна попытка: успех сбрасывает `retryAt`, неудача снова открывает окно.
- Не-сетевая ошибка (например, невалидный UTF-8 в summary) окно не открывает.
- Часы монотонные (`hrtime`), в тестах подменяются третьим аргументом конструктора адаптера.
  `ClockInterface` из `symfony/clock` не взят: он про стеночное время, лишняя зависимость, и
  адаптер `@internal`.

**Опция.** `msstc4symfony_metrics.storage.reconnect_backoff_seconds` (floatNode, `min(0)`, по
умолчанию 5) превращается в одноимённый параметр и попадает в `Factory` через
`#[Autowire(param: …)]`. Параметр указан строкой: deptrac запрещает Infrastructure зависеть от
корня бандла и `DependencyInjection`. Значение 0 —
переподключение на каждой операции (без backoff).

**Тесты.**
- Unit: внутри окна 50 записей и чтение дают 0 переподключений (было 52); после окна одна попытка.
- Интеграция, настоящий phpredis:
  - 100 записей на неразрешимый хост — меньше 1 с;
  - 100 записей на «зависший» сокет (`stream_socket_server` без `accept`, SELECT ждёт
    `default_socket_timeout = 1`) — меньше 3 с. Без backoff это 100 × 1 с; замер скриптом: 3 записи
    занимают 3 с без backoff и 1 с с ним. Этот тест не зависит от того, как быстро отвечает DNS.
  - Восстановление после окна в 0.2 с.
- Тесты рестарта используют `Factory($logger, 0.0)`, потому что проверяют немедленное
  переподключение.

### Блокер на стенде «приложение не восстанавливает свой Redis» — причина не в бандле (2026-10-02 UTC)

Сигнал с A/B на стенде: без `ReconnectingRedisAdapter` после старта Redis readiness через ~8 с
возвращается к 200, с ним (без backoff) остаётся 406 навсегда (`cache.app save() returned false`, lock store `went away`).

Воспроизведено локально скриптом, который эмулирует воркер RoadRunner. Использованы копия gateway в
`$TMPDIR`, `kernel->handle()` в цикле, перезагрузка ядра при не-HTTP исключении (как
`OnExceptionRebootStrategy` в baldinof) и `tests/Support/Redis/resp-server.php` вместо Redis. В
gateway подставлялись `src` обеих версий бандла.

| Шаг | без обёртки | с обёрткой, без backoff | без обёртки и без скрейпов `/_/metrics` |
|---|---|---|---|
| Redis down: `/_/metrics` | **500 (RedisException) → перезагрузка ядра** | 503 | — |
| Redis back: readiness | 200 | **406 навсегда** | **406 навсегда** |

Вывод:
- **Почему стоит.** phpredis переводит в FAILED клиент приложения, чья команда попала на простой.
  Symfony cache/lock соединения сами не переподключаются: `RedisProxy::reset()` есть, но
  соединения не тегированы `kernel.reset`. RoadRunner пересоздаёт их только при перезагрузке ядра.
- **Почему версия без обёртки «восстанавливалась».** Prometheus скрейпит `/_/metrics` каждые 5 с. Там
  необработанный `RedisException` на скрейпе перезагружал ядро — побочный эффект, а не поведение,
  на которое стоит опираться. Без скрейпов она стоит точно так же.
- **Проверка изоляции.** Бандл не трогает чужие клиенты в процессе: обработчик ошибок снимается в
  `finally`, persistent-соединения выключены, глобальных опций phpredis бандл не ставит. Тест
  `RedisReconnectTest::testMetricsAdapterLeavesTheApplicationsOwnRedisClientsAlone`: клиент
  приложения, не попавший на простой, восстанавливается. Характеризующий тест `…StaysFailedWithoutAnyMetricsAdapter`:
  попавший на простой клиент остаётся FAILED и вообще без бандла.

Исправлять нужно в приложении (showcase), не в бандле. Варианты:
- `ForceKernelRebootEvent` при красной Redis-проверке;
- reset соединений между запросами (`kernel.reset` на сервисах соединений, лучше только если
  `isConnected()`/`ping` упал);
- `kernel_reboot.strategy: always` — дорого.

Возвращать 500 на `/_/metrics` ради побочного эффекта не стали. Поведение описано в README («Redis restarts in long-running workers»).

`resp-server.php` теперь отвечает как пустой Redis (`GET` → nil, `MGET` → nil-массив,
`SMEMBERS`/`KEYS`/`HGETALL` → пустой массив, `EVAL`/`DEL` → `:1`, `PING` → `PONG`). Иначе Symfony
cache, lock и `collect()` promphp спотыкались на `+OK`.

### Ревью circuit breaker (2026-10-02 UTC)

Исправлено:
- **Env-плейсхолдеры.** Строка-плейсхолдер (`%env(float:…)%`) проходит без проверки
  (`MetricsBundleConfigTest::testReconnectBackoffAcceptsAnEnvPlaceholder`).
- **Тест «после окна».** Переписан на подменяемые часы вместо `usleep` (0.2 с было рискованно на
  CI).
- **Зависший сервер.** Это сервер, который принимает соединение и молчит. promphp шлёт SELECT до
  `setOption(OPT_READ_TIMEOUT)`, поэтому handshake ждал `default_socket_timeout` (60 с в prod).
  Теперь, пока у текущего адаптера не было ни одного успешного вызова (флаг `$connected`; адаптер
  из конструктора тоже ещё не подключён), `guard()` ставит `default_socket_timeout =
  max(1, ceil(read_timeout))` и восстанавливает его в `finally`. Тест: ini = 5 с,
  `read_timeout=0.5`, 100 записей меньше чем за 3 с (без ограничения — 5 с), ini восстановлен.
- **Характеризующий тест phpredis.** Проверяет только `RedisException`, без текста, и вынесен в
  `#[Group('characterisation')]`.
- **Мелочи:**
  - именованные аргументы в `Factory`;
  - публичная константа стоит первой;
  - отрицательный backoff даёт `InvalidArgumentException`;
  - не-сетевая ошибка явно сбрасывает `retryAt`;
  - у теста изоляции добавлен docblock с его целью;
  - тест `wipeStorage()` внутри окна.

Второй проход ревью:
- Проверка восстановления ini перенесена внутрь `try`, иначе её подменял `finally` теста.
- `default_socket_timeout` только **понижается**: при `read_timeout=120` и ini=60 остаётся 60. При
  `read_timeout <= 0` не трогается.
- Отрицательный backoff из env-плейсхолдера пропускает `min(0)` конфигурации. Раньше конструктор
  адаптера бросал исключение, а `Factory` уходила в InMemory. Теперь `Factory` пишет warning и берёт
  5 с.
- Тест неразрешимого хоста не учитывает первый DNS-запрос: он зависит от резолвера раннера. Оставшиеся
  99 записей должны уложиться в 0.5 с.
- В тестах для backoff и handshake используются именованные аргументы.

## Разбор SQL, prefer-lowest, PHPStan level 10 (2026-10-02 UTC)

### Метка `table="partitioned"` на системных запросах Postgres

Ленивая регулярка `SELECT … FROM` берёт первый `FROM` где угодно: в аргументах функций
(`EXTRACT(EPOCH FROM created_at)` → `created_at`), в подзапросе списка колонок
(`(SELECT pg_get_expr(...) FROM pg_attrdef ...)` → `pg_attrdef`), в комментариях. В интроспекции
колонок DBAL (`PostgreSQLSchemaManager::selectTableColumns()`, `PostgreSQLMetadataProvider`) есть
комментарий `-- exclude partitions (tables that inherit from partitioned tables)` — отсюда
`partitioned`. Квотированные идентификаторы (`FROM "users"`) не матчились `\w+`, и поиск уходил к
следующему `FROM` — в том числе в комментарий.

Алгоритм (в `QueryLabeller`, см. «Фикс-проход по ревью разбора SQL» ниже):
1. режет SQL до 16 KiB, заменяет комментарии (`--`, `/* */`) пробелом, строковые литералы — `''`
   (экранирование `''` и `\'`);
2. сворачивает скобочные группы (рекурсивная PCRE `\((?:[^()]++|(?R))*+\)`) в плейсхолдеры `(#n)`;
3. ищет `SELECT … FROM`, `INSERT INTO`, `DELETE FROM`, `UPDATE` только на верхнем уровне; ключевое
   слово не может быть хвостом слова или квалифицированной колонкой (`(?<![\w.])`, `FROM\b`): ревью
   нашло `SELECT id, fromage FROM cheeses` → `age`, а `COUNT(*)FROM users` давал `unknown`;
   `FROM (#n)` (derived table) разбирается рекурсивно по содержимому группы `n`;
4. снимает кавычки `"…"`, `` `…` ``, `[…]` и префикс схемы.
Незакрытая скобка (обрезка на 16 KiB) остаётся как есть — тогда возможен `FROM` из неё. Литерал
Postgres, оканчивающийся обратным слешем (`'C:\'` при `standard_conforming_strings=on`), читается как
незакрытый — пограничный случай, у Doctrine значения идут параметрами.

### prefer-lowest: что было красным и почему

Воспроизведено локально 2026-10-02 UTC (PHP 8.4.17, до изменений):
- PHPUnit 10.5.62 не валидирует шаблон `phpunit.xml.dist` (`ignoreIndirectDeprecations` есть с 11.1,
  `failOnPhpunitDeprecation`/`displayDetailsOnPhpunitDeprecations` в 11.0–11.3 отсутствуют) — граница
  `>=11.4`. Фактически lowest ставит 11.5.50 (более ранние запрещает `roave/security-advisories`).
- `FactoryTest::testRedisngSchemeWithHostReturnsReconnectingAdapter` — promphp 2.6.0 без `RedisNg`.
- `RedisReconnectTest::testWritesRecoverAfterRedisRestart` ждал `AUTH user secret`, а приходил
  `AUTH secret`. Причина — **promphp**, не `symfony/cache`: имя пользователя DSN promphp передаёт в
  `AUTH` только с 2.13.0. Это реальный баг для ACL-пользователей Redis → граница `^2.13`.
- `RedisClientException` не найден (появился в promphp 2.15) — data set самопропускается.

### PHPStan level 10

11 ошибок в `InfoEventListener` (`opcache_get_status()` типизирован как `array<mixed>`) — сужение в
`collectOpcache()` через `number()`; заодно защита от деления на ноль при пустом пуле памяти OPcache.
7 ошибок `labelValuesFor()` в тестах исчезли вместе с хелперами (переход на `RegistrySamples`).
Запись baseline `isset.offset` (`sys_getloadavg()` на PHP 8.4 возвращает `array{float,float,float}|false`)
удалена — проверка стала `=== false`.

### Первый прогон CI с lowest-ячейкой: красная ячейка (run 36996017587)

`ContainerCompileTest::testUnreachableRedisNeverTurnsRequestsInto500` упал с `ErrorException: Caster::castObject():
Implicitly marking parameter $debugClass as nullable is deprecated` (старый `symfony/var-dumper`
без explicit nullable). Класс грузится лениво внутри `guard()`, а `guard()` передаёт не-warning типы
предыдущему обработчику — замыканию теста, которое бросало на **любой** тип. Обработчик Symfony так не
делает, так что ошибка была в тесте: замыкание теперь возвращает `false` для типов вне своей маски.
Локально не воспроизводилось из-за OPcache CLI (`opcache.enable_cli=On`): класс уже скомпилирован, и
deprecation времени компиляции не повторяется. Воспроизведение: `php -d opcache.enable_cli=0
vendor/bin/phpunit --filter testUnreachableRedisNeverTurnsRequestsInto500` в lowest-копии.

## Фикс-проход по ревью разбора SQL (2026-10-02 UTC)

Разбор SQL вынесен из `QueryMeter` в `QueryLabeller` (@internal, не `readonly`: LRU-кэш на 256
записей, ключ — `xxh128` от первых 16 KiB, тех же, что разбираются; метки зависят только от них).
`Connection` создаёт его и передаёт в `QueryMeter` (аргумент обязательный) — кэш на соединение.
`label()` помечен `@phpstan-impure`: иначе PHPStan считает повторный вызов с тем же SQL тем же
значением (`staticMethod.alreadyNarrowedType` в тесте LRU).

- **UNION из DBAL QueryBuilder**: `AbstractPlatform::getUnionSelectPartSQL()` оборачивает каждую часть
  в скобки → верхний уровень `(#0) UNION (#1)`, `STATEMENT` ничего не находил → `unknown`. Поэтому верхний уровень, начинающийся с `(#0)`, разбирается рекурсивно. На SQLite
  DBAL скобок не ставит — поэтому интеграционные тесты этого не ловили.
- **CTE**: при `WITH` собирается карта `имя (lowercase, без кавычек) → тело`; найденное имя из карты
  разрешается рекурсивно (CTE на CTE тоже). У рекурсивного CTE своё имя из карты удаляется перед
  рекурсией — иначе цикл; метка тогда — имя CTE. Тип запроса `WITH … SELECT` по-прежнему `other`.
- `ONLY` (после `FROM`/`UPDATE`/`DELETE FROM`) и `LATERAL` (после `FROM`) пропускаются;
  `"a""b"`/`` `a``b` `` раскавычиваются с unescape; префиксов `a.b.c` может быть сколько угодно.
- **Длительность**: `doctrine_query_duration_*` раньше включал время разбора SQL (на 15 КБ
  `SELECT 1 UNION …` без JIT ~300 мс — `SELECT\b.*?FROM` квадратичен при отсутствии `FROM`). Теперь
  `$duration` фиксируется сразу после `$query()`. Тест `testDurationExcludesLabelParsing` выключает
  `pcre.jit` через `ini_set`, чтобы разбор гарантированно был дорогим.
- **OPcache**: разбор статуса — `OpcacheSnapshot::fromStatus()` (чистая функция, `#[Exclude]`,
  @internal), покрыт тестами на массивах. `@param array<array-key, mixed>` на входе — осознанно:
  это недоверенный результат `opcache_get_status()` (PHPStan сам типизирует его `array<mixed>`),
  узкая `@phpstan-type`-форма на входе сломала бы вызов на уровне 10; выход DTO типизирован точно.
- `MetricCatalogTest::testEveryCaseIsPinned` сравнивает отсортированные множества — порядок case'ов
  не контракт (дубликаты по-прежнему ловятся).

### Известные ограничения разбора таблицы (решено не чинить, задокументировано в README)

- Функция в `FROM` (`FROM generate_series(1, 10) g`, `FROM unnest(...)`) → метка = имя функции.
  Отличить табличную функцию от таблицы без каталога нельзя; кардинальность меток ограничена.
- Строки Postgres `E'…\''`, dollar-quoting (`$$…$$`) и `#`-комментарии MySQL не распознаются как
  шум — `FROM` внутри них может стать меткой. Doctrine передаёт значения параметрами, на практике
  встречается только в рукописном DDL/функциях.
- `ONLY` распознаётся как ключевое слово: таблица с именем `only` (не резервировано в MySQL) даст
  следующее слово. Пограничный случай, принят.

