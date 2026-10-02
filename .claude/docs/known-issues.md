# Известные проблемы и находки

## HTTP-мониторинг не работал до `v1.1.0`, а включённый как был — вредил

`AddHttpClientMonitorPass` проверял `class_exists(HttpClientInterface::class)` —
для интерфейса всегда `false`; под этим сидел вызов незагруженной `tagged_iterator()`.
Когда пасс заработал, ревью (2026-09-30) нашло, что старый дизайн:
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

## Маршрут `/_/metrics` не регистрируется сам (Symfony < 7.4)

На Symfony 6.4–7.3 бандл не может добавить маршрут; приложение импортирует
`@MetricsBundle/Presentation/Controller/` (`type: attribute`). На 7.4+ с `routing.controllers`
маршрут грузится сам — см. раздел 1.3.0 ниже.

## `symfony/yaml` не был объявлен

`MetricsExtension` грузит `services.yaml`, пакет приходил транзитивно (deptrac).
Приложение без него падало при сборке контейнера. Теперь в `require`;
`bundle-standard` v1.5.0 проверяет это правилом.

## `symfony/monolog-bundle` — `^3.11|^4.0`

3.x не ставится на Symfony 8.

## Elastica 8 не поддерживается

`TimingTransport` построен на транспортном API Elastica 7 (`Connection`,
`AbstractTransport`), которого в 8 нет. `boot()` выходит, если нет
`AbstractTransport`, — метрики Elastica 8 молча не собираются. Поддержка 8 —
этап B. В CI зафиксирована `^7.3`, чтобы транспорт реально тестировался.

## `Statement::execute()` и DBAL 3/4

DBAL 3 передаёт параметры в `execute($params)`, DBAL 4 параметр убрал. Метод
принимает `mixed $params = null` и форвардит `func_get_args()`. CI-лок держит DBAL 4;
DBAL 3 (3.10 + Symfony 7.4) проверен вручную 2026-10-01 — весь набор зелёный. На
Symfony 8 DBAL 3 не ставится (конфликт с `symfony/http-foundation`).

## Elastica: клиенты создаются в `boot()`

`wireElasticaTransports()` достаёт каждый клиент и соединение при загрузке ядра —
один раз на воркер. Ленивая обёртка — этап B вместе с поддержкой Elastica 8.

## BC check: история

До тега `v1.1.0` последний тег `v1.0.0` был под старым namespace, и Roave видел «удалённые» классы;
job был `continue-on-error`. С `bundle-standard` v1.8.0 (metrics 1.4.0) BC check **блокирующий** и
сравнивает с последним стабильным тегом.

## Коммит `0df4722` содержит больше, чем в сообщении

Первый коммит A2 захватил заранее проиндексированные переименования
(`composer-integration.json` → `composer-ci.json`, `phpunit-integration.xml.dist`
→ `phpunit.xml.dist`, удаление Psalm). Переписать историю `main` не удалось;
сообщение следующего коммита описывает их корректно.

## Профилирование вынесено в мост (1.2.0, 2026-10-01 UTC)

`MetricProcessor` / `ProfilingCollector` / case `PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS` —
deprecated, живут до 2.0 ради BC. Мост `msstc4symfony/metrics-bridge-profiling` объявляет ту же
метрику своим enum (через `metrics_bundle.metric_enums`) и в compiler pass снимает с
`MetricProcessor` тег `EndSpanProcessorInterface` (сервис остаётся для ссылок приложения) —
иначе span писались бы дважды. Поэтому `MetricRepositoryFactory` схлопывает
одинаковые имена метрик (первое объявление побеждает). `MetricProcessor` анализируется
PHPStan: profiling-bundle стоит в `composer-ci.json`.

Переход `MetricProcessor` на `getDuration()` в 1.2 покрыт `tests/Unit/Framework/Profiling/MetricProcessorTest`.
Конструктор вызывает `trigger_deprecation` — срабатывает, только если процессор реально
используется (мост снимает с него тег end-процессора).
BC-джоб (Roave, тогда неблокирующий) на сравнении 1.2.0 с v1.1.0 был красным: базовая ревизия ставится со
своим `composer-ci.json` без profiling-bundle, и BetterReflection не находит
`EndSpanProcessorInterface` (7 × `[BC] SKIPPED`, реальных изменений API нет — проверено локально
2026-10-01 UTC). С 1.2.0 profiling-bundle в require-dev (vcs-репозиторий в `composer-ci.json`),
`MetricProcessor` под PHPStan и тестом — сравнения с базой >= v1.2.0 зелёные.
`symfony/deprecation-contracts` (для `trigger_deprecation`) до 1.4.0 не объявлялся: верификатор
требовал для всех `symfony/*` `^6.4|^7.0|^8.0`. С `bundle-standard` v1.8.0 contracts могут иметь свою
границу — в 1.4.0 объявлен `^2.5|^3` в обоих манифестах.

## DBAL-метрики не собирались с DoctrineBundle (исправлено в 1.2.1, 2026-10-01 UTC)

До 1.2.1 `AddDoctrineDBALMonitorPass` ни разу не срабатывал в реальном приложении. Три причины:

1. DoctrineBundle объявляет соединения как `ChildDefinition('doctrine.dbal.connection')` **без
   класса** (`setClass()` — только при `wrapper_class`), поэтому фильтр
   `getClass() !== null && is_a(..., Connection::class)` их не находил.
2. Даже если бы нашёл — `Middleware` регистрировался без тега `doctrine.middleware`. DoctrineBundle
   применяет только тегированные middleware (`MiddlewaresPass` → `setMiddlewares` на
   `doctrine.dbal.<name>_connection.configuration`); нетегированный приватный сервис удалялся.
   Автоконфигурация (`registerForAutoconfiguration(Driver\Middleware)`) тоже не помогала:
   `Infrastructure/Doctrine/DBAL/` исключён из `services.yaml`, а `ResolveInstanceofConditionalsPass`
   (priority 100) отрабатывает раньше нашего пасса.
3. Порядок: оба пасса `TYPE_BEFORE_OPTIMIZATION` с priority 0, при равном приоритете — порядок
   регистрации бандлов. Flex дописывает новый бандл в конец `bundles.php`, т.е. после
   DoctrineBundle — тег появился бы уже после того, как `MiddlewaresPass` его прочитал.
   Проверено тестом: без priority вариант «DoctrineBundle первым» оставался пустым.

Исправление: пасс регистрирует `Middleware` (autowire + тег `doctrine.middleware`, без
`connection` — на все соединения) при наличии параметра `doctrine.connections`, с
`priority: AddDoctrineDBALMonitorPass::PRIORITY` (= 1). Определение, заданное приложением
(тот же id), не перетирается.

Попутно: DBAL отправляет запросы **без параметров** (`executeQuery()`/`executeStatement()` с
пустым `$params`) в `Driver\Connection::query()`/`exec()`, минуя `prepare()`. Middleware
оборачивал только `prepare()` → такие запросы не считались. Теперь замер вынесен в
`QueryMeter` (@internal), им пользуются `Statement::execute()` и `Connection::query()/exec()`.
`exec()` объявлен с возвратом `int` (DBAL 3 — `int`, DBAL 4 — `int|string`; строка только для
числа строк > PHP_INT_MAX, приводится `(int)`).

Метка `connection` по-прежнему `host:dbname` (для sqlite in-memory — `localhost:db`), а не имя
соединения DoctrineBundle. Можно перейти на `ConnectionNameAwareInterface` DoctrineBundle, но это
меняет значения меток — отдельное решение.

Тест: `tests/Integration/Infrastructure/Doctrine/DBAL/DoctrineDbalMetricsTest` — настоящее ядро
(`TestKernel` подключает DoctrineBundle, sqlite in-memory), оба порядка бандлов (env
`metrics_first` переворачивает порядок). В minimal-профиле самопропускается.

### Дополнения 1.2.2 (2026-10-01 UTC)

- Без DoctrineBundle пасс снова регистрирует `Middleware` (autowire, **без** тега) — как до 1.2.1,
  для приложений, которые сами подключают его в свои соединения. С DoctrineBundle — с тегом.
- Разбор таблицы в 1.2.2: `SELECT\s+.+?\s+FROM` — ленивый, берётся первый `FROM`. Жадный
  вариант с флагом `s` брал последний `FROM` (подзапрос), а на длинных `IN (...)` упирался в
  `pcre.backtrack_limit` → `preg_match` возвращал `false` → метка `unknown`. Разбирается только
  первые 16 KiB SQL (`QueryMeter::MAX_PARSED_SQL_LENGTH`); `FROM` дальше этого порога → `unknown`.
  Ленивый вариант всё ещё брал `FROM` из скобок и комментариев — исправлено в 1.4.0 (см. ниже).
- `TestKernel::hasDoctrine()` = DoctrineBundle **и** `ext-pdo_sqlite`; `pdo_sqlite` явно добавлен
  во вход `extensions` в `.github/workflows/checks.yml` (общий workflow не трогали).
- Хелпер выборки сэмплов из реестра — `tests/Support/RegistrySamples` (Unit и Integration); с 1.4.0
  на него переведены все тесты.

## 1.3.0 (2026-10-02 UTC)

### Маршрут `/_/metrics` на Symfony 7.4+ грузится сам

Запись «маршрут не регистрируется сам» верна только для Symfony 6.4–7.3. С 7.4 FrameworkBundle
тегирует `routing.controller` каждый автоконфигурируемый сервис с `#[Route]`
(`registerAttributeForAutoconfiguration(Route::class)`), а рецепт `config/routes.yaml` импортирует
`resource: routing.controllers` (`AttributeServicesLoader`). Healthcheck-bundle «грузит маршруты
сам» ровно так — своего лоадера у него нет. `GetMetricsController` автоконфигурируется
(`_defaults.autoconfigure` в `services.yaml`), поэтому механизм работал и у нас; исходники не
менялись, добавлены тест и README. Двойной импорт (`routing.controllers` + ручной) безопасен:
`RouteCollection` хранит маршрут по имени `metrics-get`. Ловушка: если когда-нибудь выключить
автоконфигурацию контроллера, маршрут молча пропадёт у 7.4+-приложений —
`ContainerCompileTest::testMetricsRouteLoadsOnceThroughRoutingControllers` это ловит (на 6.4
самопропускается по `Kernel::VERSION_ID < 70400`: в CI-ячейке 6.4 закреплены только
framework-bundle/http-kernel/…, а `symfony/routing` резолвится в 7.4+ — класс `AttributeServicesLoader`
есть, но FrameworkBundle 6.4 не регистрирует лоадер → `Cannot load resource "routing.controllers"`;
первый прогон 1.3.0 упал на этом).

### Метка `connection` по имени соединения — opt-in

`metrics.doctrine.connection_label: name` → пасс регистрирует `NamedConnectionMiddleware`
(тег `doctrine.middleware`), `Middleware` остаётся без тега. `MiddlewaresPass` DoctrineBundle
клонирует определение на каждое соединение и вызывает `setConnectionName()`, если **класс
определения** реализует `ConnectionNameAwareInterface` — поэтому класс задаётся явно, а сам
`Middleware` (`final readonly`, публичный конструктор) не трогали. `Driver` получил
необязательный `?string $connectionName` (конструктор `@internal`). Если приложение само
определило сервис `Middleware::class`, режим `name` валит компиляцию (`LogicException`) — раньше
(до ревью) он молча игнорировался.
Без DoctrineBundle режим `name` ничего не меняет (имени нет). Определение, исключённое из
resource-скана, всё равно есть в контейнере с тегом `container.excluded` — в тестах проверять
и тег, а не только `hasDefinition()`.

### Messenger

Метрики объявлены отдельным `MessengerMetricLabelEnum`, а не кейсами `MetricLabelEnum`: Roave BC
check считает добавление кейса в enum BC-break (`[BC] ADDED: Case ... was added`, exhaustive
`match` у приложений). Проверено локально 2026-10-02 UTC. Enum дописывается в
`metrics_bundle.metric_enums` пассом `RegisterMessengerMetricsPass` после слияния параметров —
приложение, перечислившее свои enum, иначе потеряло бы его в `metrics:list`.
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

Бакеты гистограммы длительности начинаются с 5 мс (обычный обработчик — миллисекунды); менять их
после 1.3.0 — смена значений `le`, т.е. BC.

### `LogicException` без `ConnectionNameAwareInterface`

`AddDoctrineDBALMonitorPass` бросает `LogicException`, если выбран `name`, а DoctrineBundle без
`ConnectionNameAwareInterface`. В CI-профиле интерфейс всегда есть; с 1.4.0 ветку покрывает тест в
отдельном процессе с подменой class map (`testing.md`).

## 1.3.1 (2026-10-02 UTC): Redis-хранилище не восстанавливалось после рестарта Redis

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
- cooldown между попытками (ревью, CR-006) в 1.3.1 и 1.3.2 был отклонён, **в 1.3.3 добавлен** — см.
  раздел 1.3.3 ниже;
- конструктор обёртки подключается (строит адаптер) **сразу**: иначе ошибка конструирования
  (нет ext-redis / `RedisNg`) не дойдёт до `try` в `Factory::create()` и откат на `InMemory`
  сломается (тест `testConnectsEagerlySoTheFactoryCanFallBackOnConstructionErrors`).

Попутно (найдено ревью): номер БД из пути DSN (`redis://host/4`) **игнорировался** —
`parse_url()` отдаёт `/4`, `is_numeric('/4') === false`, запись шла в БД 0. Исправлено в 1.3.1
(`ltrim('/')` + `^\d+$`), интеграционный тест ждёт `SELECT 3`. `?database=` по-прежнему главнее.
`persistent_connections=1` проверен тем же интеграционным тестом: новый `\Redis` + `pconnect()`
после рестарта сервера работает (на дефолтных ini phpredis 6.3).
`FailingOnceDownRedis` повторяет типизированные сигнатуры phpredis 6 — на ext-redis 5 тест
самопропускается.

Нижняя граница `promphp/prometheus_client_php`: до 1.4.0 — `^2.6`, и `redisng://` на 2.6 уходил в
`InMemory` (класса `RedisNg` нет до 2.7). С 1.4.0 — `^2.13` (см. раздел 1.4.0). `AbstractRedis` и
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

README раньше утверждал, что недоступный Redis уводит в `InMemory`: это неверно — конструктор
адаптера не подключается, откат срабатывает только на ошибке конструирования (DSN, схема, нет класса).
Опция `ssl_verify_peer` попадает в `$options['ssl']`, но promphp её не использует при `connect()` —
README теперь так и говорит; поддержка TLS — отдельная задача.

## 1.3.2 (2026-10-02 UTC): регрессия 1.3.1 — 500 при остановленном Redis

Симптом на стенде (showcase, RoadRunner, prod): при остановленном Redis запросы в gateway отдавали 500,
в логе `ERROR Warning: Redis::connect(): php_network_getaddresses: getaddrinfo for redis failed: Name
or service not known` (`ErrorException`, `PHPRedis.php:158`).

Механика: в 1.3.0 клиент phpredis после обрыва застревал в FAILED и отвечал «went away» без сетевых
вызовов. В 1.3.1 каждая операция переподключается, а `connect()` при ошибке DNS **сначала выдаёт PHP
warning**, потом бросает `RedisException`. Symfony `ErrorHandler` с `framework.php_errors.throw: true`
(по умолчанию **и в prod**) бросает warning как `ErrorException` прямо из `connect()`. phpredis 6.3 при
этом всё равно бросает `RedisException` и цепляет `ErrorException` как `previous` (проверено).

Воспроизведено локально:
- `/_/metrics` → 500 (`StorageException` никто не ловил — так было и до 1.3.1);
- `ErrorException` доходил до обработчика приложения (интеграционный тест на v1.3.1 падает именно на этом).

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
  (`#[WithMonologChannel]`, логгер — необязательный аргумент конструктора, BC).
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
- Backoff переподключения в 1.3.2 снова отклонён, и это оказалось ошибкой — см. 1.3.3.
- Генерик `guard()` с `@template T` (S-1) не взят: с void-замыканиями PHPStan не выводит `T`, а
  ограниченный шаблон этого не исправляет (пробовали в 1.3.1). `collect()` собирает сэмплы через
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

## 1.3.3 (2026-10-02 UTC): circuit breaker — CR-006 всё-таки был нужен

**Почему откладывали.** Исходное требование было «одна попытка подключения на операцию». Локально
connection refused и NXDOMAIN отказывают за доли миллисекунды: в песочнице DNS падает мгновенно с
`Temporary failure in name resolution`. Поэтому стоимость попытки выглядела нулевой.

**Почему понадобилось.** На стенде (RoadRunner, контейнер Redis остановлен, Docker DNS не знает
`redis`) v1.3.2 давал:

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

**Опция.** `metrics.storage.reconnect_backoff_seconds` (floatNode, `min(0)`, по умолчанию 5)
превращается в параметр `metrics_bundle.storage.reconnect_backoff_seconds` и попадает в `Factory`
через `#[Autowire(param: …)]`. Параметр указан строкой, а не константой `MetricsExtension`:
deptrac запрещает зависимость Infrastructure → DependencyInjection. Значение 0 возвращает поведение
1.3.2 (переподключение на каждой операции).

**Тесты.**
- Unit: внутри окна 50 записей и чтение дают 0 переподключений (было 52); после окна одна попытка.
- Интеграция, настоящий phpredis:
  - 100 записей на неразрешимый хост — меньше 1 с;
  - 100 записей на «зависший» сокет (`stream_socket_server` без `accept`, SELECT ждёт
    `default_socket_timeout = 1`) — меньше 3 с. Без backoff это 100 × 1 с; замер скриптом: 3 записи
    занимают 3 с без backoff и 1 с с ним. Этот тест не зависит от того, как быстро отвечает DNS.
  - Восстановление после окна в 0.2 с.
- Тесты рестарта из 1.3.1 используют `Factory($logger, 0.0)`, потому что проверяют немедленное
  переподключение.

### Блокер на стенде «приложение не восстанавливает свой Redis» — причина не в бандле (2026-10-02 UTC)

Сигнал с A/B на стенде: на v1.3.0 после старта Redis readiness через ~8 с возвращается к 200, на
v1.3.2 остаётся 406 навсегда (`cache.app save() returned false`, lock store `went away`).

Воспроизведено локально скриптом, который эмулирует воркер RoadRunner. Использованы копия gateway в
`$TMPDIR`, `kernel->handle()` в цикле, перезагрузка ядра при не-HTTP исключении (как
`OnExceptionRebootStrategy` в baldinof) и `tests/Support/Redis/resp-server.php` вместо Redis. В
gateway подставлялись `src` бандла из тегов.

| Шаг | v1.3.0 | v1.3.2 | v1.3.0 без скрейпов `/_/metrics` |
|---|---|---|---|
| Redis down: `/_/metrics` | **500 (RedisException) → перезагрузка ядра** | 503 | — |
| Redis back: readiness | 200 | **406 навсегда** | **406 навсегда** |

Вывод:
- **Почему стоит.** phpredis переводит в FAILED клиент приложения, чья команда попала на простой.
  Symfony cache/lock соединения сами не переподключаются: `RedisProxy::reset()` есть, но
  соединения не тегированы `kernel.reset`. RoadRunner пересоздаёт их только при перезагрузке ядра.
- **Почему 1.3.0 «восстанавливался».** Prometheus скрейпит `/_/metrics` каждые 5 с. В 1.3.0
  необработанный `RedisException` на скрейпе перезагружал ядро — побочный эффект, а не поведение,
  на которое стоит опираться. Без скрейпов 1.3.0 стоит точно так же.
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

Возвращать 500 на `/_/metrics` ради побочного эффекта не стали. Поведенческое изменение описано в
CHANGELOG 1.3.3 и в README («Redis restarts in long-running workers»).

`resp-server.php` теперь отвечает как пустой Redis (`GET` → nil, `MGET` → nil-массив,
`SMEMBERS`/`KEYS`/`HGETALL` → пустой массив, `EVAL`/`DEL` → `:1`, `PING` → `PONG`). Иначе Symfony
cache, lock и `collect()` promphp спотыкались на `+OK`.

### Ревью 1.3.3 (2026-10-02 UTC)

Исправлено:
- **Env-плейсхолдеры.** `MetricsExtension` пропускает строку-плейсхолдер (`%env(float:…)%`) без
  проверки. Тест прогоняет `MergeExtensionConfigurationPass` и `resolveEnvPlaceholders`.
- **Тест «после окна».** Переписан на подменяемые часы вместо `usleep` (0.2 с было рискованно на
  CI).
- **Зависший сервер.** Это сервер, который принимает соединение и молчит. promphp шлёт SELECT до
  `setOption(OPT_READ_TIMEOUT)`, поэтому handshake ждал `default_socket_timeout` (60 с в prod).
  Теперь, пока у текущего адаптера не было ни одного успешного вызова (флаг `$connected`; адаптер
  из конструктора тоже ещё не подключён), `guard()` ставит `default_socket_timeout =
  max(1, ceil(read_timeout))` и восстанавливает его в `finally`. Тест: ini = 5 с,
  `read_timeout=0.5`, 100 записей меньше чем за 3 с (без ограничения — 5 с), ini восстановлен.
- **Рассинхрон имени параметра.** Строка в `#[Autowire]` у `Factory` сверяется с константой
  рефлексией (`MetricsExtensionTest::testFactoryIsWiredToTheBackoffParameter`).
- **Характеризующий тест phpredis.** Проверяет только `RedisException`, без текста, и вынесен в
  `#[Group('characterisation')]`.
- **Мелочи:**
  - именованные аргументы в `Factory`;
  - публичная константа стоит первой;
  - отрицательный backoff даёт `InvalidArgumentException`;
  - не-сетевая ошибка явно сбрасывает `retryAt`;
  - у теста изоляции добавлен docblock с его целью;
  - тест `wipeStorage()` внутри окна.

Второй проход ревью 1.3.3:
- Проверка восстановления ini перенесена внутрь `try`, иначе её подменял `finally` теста.
- `default_socket_timeout` только **понижается**: при `read_timeout=120` и ini=60 остаётся 60. При
  `read_timeout <= 0` не трогается.
- Отрицательный backoff из env-плейсхолдера пропускает `min(0)` конфигурации. Раньше конструктор
  адаптера бросал исключение, а `Factory` уходила в InMemory. Теперь `Factory` пишет warning и берёт
  5 с.
- Тест неразрешимого хоста не учитывает первый DNS-запрос: он зависит от резолвера раннера. Оставшиеся
  99 записей должны уложиться в 0.5 с.
- В тестах для backoff и handshake используются именованные аргументы.

## 1.4.0 (2026-10-02 UTC)

### Метка `table="partitioned"` на системных запросах Postgres

Ленивая регулярка 1.2.2 брала первый `FROM` где угодно: в аргументах функций
(`EXTRACT(EPOCH FROM created_at)` → `created_at`), в подзапросе списка колонок
(`(SELECT pg_get_expr(...) FROM pg_attrdef ...)` → `pg_attrdef`), в комментариях. В интроспекции
колонок DBAL (`PostgreSQLSchemaManager::selectTableColumns()`, `PostgreSQLMetadataProvider`) есть
комментарий `-- exclude partitions (tables that inherit from partitioned tables)` — отсюда
`partitioned`. Квотированные идентификаторы (`FROM "users"`) не матчились `\w+`, и поиск уходил к
следующему `FROM` — в том числе в комментарий.

Алгоритм (с 1.4.1 — в `QueryLabeller`, см. раздел 1.4.1 ниже):
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

Воспроизведено локально 2026-10-02 UTC (PHP 8.4.17, Symfony 6.4.0, до изменений):
- PHPUnit 10.5.62 не валидирует шаблон `phpunit.xml.dist` (`ignoreIndirectDeprecations` есть с 11.1,
  `failOnPhpunitDeprecation`/`displayDetailsOnPhpunitDeprecations` в 11.0–11.3 отсутствуют) — граница
  `>=11.4`. Фактически lowest ставит 11.5.50 (более ранние запрещает `roave/security-advisories`).
- `FactoryTest::testRedisngSchemeWithHostReturnsReconnectingAdapter` — promphp 2.6.0 без `RedisNg`.
- `RedisReconnectTest::testWritesRecoverAfterRedisRestart` ждал `AUTH user secret`, а приходил
  `AUTH secret`. Причина — **promphp**, не `symfony/cache`: имя пользователя DSN promphp передаёт в
  `AUTH` только с 2.13.0. Это реальный баг для ACL-пользователей Redis → граница `^2.13`.
- `RedisClientException` не найден (появился в promphp 2.15) — data set самопропускается.
- `Constant E_STRICT is deprecated` из `symfony/error-handler` 6.4.0 → `conflict <6.4.10` (оба
  манифеста), плюс CI-only `conflict` на error-handler/http-kernel ради risky (`tooling.md`).
После изменений: 277 тестов, 5 пропусков (2 APCu без `apc.enable_cli`, 2 `routing.controllers` на 6.4,
1 `RedisClientException` на promphp 2.13).

### PHPStan level 10

11 ошибок в `InfoEventListener` (`opcache_get_status()` типизирован как `array<mixed>`) — сужение в
`collectOpcache()` через `number()`; заодно защита от деления на ноль при пустом пуле памяти OPcache.
7 ошибок `labelValuesFor()` в тестах исчезли вместе с хелперами (переход на `RegistrySamples`).
Запись baseline `isset.offset` (`sys_getloadavg()` на PHP 8.4 возвращает `array{float,float,float}|false`)
удалена — проверка стала `=== false`.

### Первый прогон CI 1.4.0: красная lowest-ячейка (run 36996017587)

`ContainerCompileTest::testUnreachableRedisNeverTurnsRequestsInto500` упал с `ErrorException: Caster::castObject():
Implicitly marking parameter $debugClass as nullable is deprecated` (`symfony/var-dumper` 6.3.0, explicit
nullable — с 6.4.3). Класс грузится лениво внутри `guard()`, а `guard()` передаёт не-warning типы
предыдущему обработчику — замыканию теста, которое бросало на **любой** тип. Обработчик Symfony так не
делает, так что ошибка была в тесте: замыкание теперь возвращает `false` для типов вне своей маски.
Локально не воспроизводилось из-за OPcache CLI (`opcache.enable_cli=On`): класс уже скомпилирован, и
deprecation времени компиляции не повторяется. Воспроизведение: `php -d opcache.enable_cli=0
vendor/bin/phpunit --filter testUnreachableRedisNeverTurnsRequestsInto500` в lowest-копии.

## 1.4.1 (2026-10-02 UTC) — фикс-проход по ревью 1.4.0

Разбор SQL вынесен из `QueryMeter` в `QueryLabeller` (@internal, не `readonly`: LRU-кэш на 256
записей, ключ — `xxh128` от первых 16 KiB, тех же, что разбираются; метки зависят только от них).
`QueryMeter` создаёт его в конструкторе — кэш на соединение; сигнатура `QueryMeter` не менялась.
`label()` помечен `@phpstan-impure`: иначе PHPStan считает повторный вызов с тем же SQL тем же
значением (`staticMethod.alreadyNarrowedType` в тесте LRU).

- **UNION из DBAL QueryBuilder**: `AbstractPlatform::getUnionSelectPartSQL()` оборачивает каждую часть
  в скобки → верхний уровень `(#0) UNION (#1)`, `STATEMENT` ничего не находил → `unknown` (в 1.3.3
  была первая таблица). Теперь верхний уровень, начинающийся с `(#0)`, разбирается рекурсивно. На SQLite
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

