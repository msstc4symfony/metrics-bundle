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

## BC check красный до релиза `v1.1.0`

Последний тег `v1.0.0` — под старым namespace; Roave видит «удалённые» классы.
Job `continue-on-error`. После тега `v1.1.0` сравнение пойдёт с ним.

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
BC-джоб (Roave, неблокирующий) на сравнении 1.2.0 с v1.1.0 красный: базовая ревизия ставится со
своим `composer-ci.json` без profiling-bundle, и BetterReflection не находит
`EndSpanProcessorInterface` (7 × `[BC] SKIPPED`, реальных изменений API нет — проверено локально
2026-10-01 UTC). С 1.2.0 profiling-bundle в require-dev (vcs-репозиторий в `composer-ci.json`),
`MetricProcessor` под PHPStan и тестом — сравнения с базой >= v1.2.0 зелёные.
`symfony/deprecation-contracts` (для `trigger_deprecation`) не объявлен напрямую: верификатор
стандарта требует для всех `symfony/*` `^6.4|^7.0|^8.0`, contracts версионируются `^2.5|^3`.
Гарантирован транзитивно через framework-bundle / console / event-dispatcher.

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
- Разбор таблицы: `SELECT\s+.+?\s+FROM` — ленивый, берётся первый (внешний) `FROM`. Жадный
  вариант с флагом `s` брал последний `FROM` (подзапрос), а на длинных `IN (...)` упирался в
  `pcre.backtrack_limit` → `preg_match` возвращал `false` → метка `unknown`. Разбирается только
  первые 16 KiB SQL (`QueryMeter::MAX_PARSED_SQL_LENGTH`); `FROM` дальше этого порога → `unknown`.
  Подзапрос в списке колонок (`SELECT (SELECT … FROM a) … FROM b`) по-прежнему даёт `a`.
- `TestKernel::hasDoctrine()` = DoctrineBundle **и** `ext-pdo_sqlite`; `pdo_sqlite` явно добавлен
  во вход `extensions` в `.github/workflows/checks.yml` (общий workflow не трогали).
- Хелпер выборки сэмплов из реестра — `tests/Support/RegistrySamples` (Unit и Integration).
  Старые тесты коллекторов пока сканируют реестр сами.

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

### Не покрыто тестом

`AddDoctrineDBALMonitorPass` бросает `LogicException`, если выбран `name`, а DoctrineBundle без
`ConnectionNameAwareInterface` — в CI-профиле интерфейс всегда есть.
