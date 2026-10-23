# Архитектура

## Слои (`src/`, с 2.0 — канон bundle-standard v3, спек B2-metrics)

| Слой | Что внутри | Может зависеть от |
|---|---|---|
| `Application/Metric` | определения и модель (`MetricDefinitionInterface`, `BuiltInMetric`, `Metric`, `Label`, `MetricType`, `LabelType`, `MessengerMessageStatus` — его значения перечисляет `BuiltInMetric`, `MetricRepository`+фабрика) — публичный API | — |
| `Application/Port` | `MetricRecorderInterface`, `MetricBatchRecorderInterface` (2.2: `incrementBy()` — новый интерфейс, а не метод в старом: Roave BC), `MetricStorageInterface`, `MetricExport`, `MetricStorageException` — публичный API | — |
| `Application/Collector` | 10 коллекторов (`final`, получают `MetricRecorderInterface`; `CacheCollector` — единственный со состоянием: счёт hit/miss в памяти, `flush()` пишет через `MetricBatchRecorderInterface`), enum `DoctrineQueryType`; публичны `DoctrineConnectionCollector` и `DoctrineQueryType` (ручной DBAL без DoctrineBundle, вместе с `NamedConnectionMiddleware`) | — |
| `Infrastructure/Prometheus` | `PrometheusMetricRecorder` (префикс лейблов, выбор типа, лог сбоя в `metrics_bundle`), `PrometheusMetricStorage`, `Storage/` (фабрика адаптера, `ReconnectingRedisAdapter`) | `Application` |
| `Infrastructure/{Doctrine,Elastica,HttpClient,Guzzle,Cache}` | адаптеры, вызывающие коллекторы; `OutgoingHttp/OutgoingRequestLabels` — общие метки host/path для http-client и Guzzle (ассемблеры + `PathSanitizer`, с 2.2 там же); `Cache/` — декораторы, `CountingCallbackWrapper` + `ComputationCounter`; `Messenger/MessengerQueueDepthReader` | `Application` |
| `Framework/Monolog` | `HandlerDecorator` | `Application` |
| `Presentation` | listener'ы kernel/console/Messenger (`CacheMetricsFlushListener`, `MessengerQueueDepthListener` — 2.2), `GetMetricsController`, команды — только через порты | `Application` |
| `DependencyInjection` | pass'ы (`CompilerPass/`), `DefinitionFilter`, `ElasticaClientDefinitions`, `RuntimeIntegrations` (подписчик MongoDB и транспорты Elastica 7 при `boot()`) | `Application`, `Infrastructure`, `Presentation` (2.2, канонное правило: `RegisterMessengerQueueDepthPass`), `Framework` |
| `MetricsBundle` | конфиг-дерево, параметры, pass'ы, `boot()` → `RuntimeIntegrations::register()` | `Application`, `DependencyInjection` |

Prometheus импортируют только классы `Infrastructure/` (и регистрация в `services.php`). Лог-канал —
`Application\LogChannel::NAME`: его читают controller и `AddMonologDecoratorCompilerPass`, поэтому константа не в
Infrastructure. `AbstractCollector` удалён в 2.0: свой коллектор — сервис с `MetricRecorderInterface`.

## Точки проводки

1. `MetricsBundle` (`AbstractBundle`, алиас `msstc4symfony_metrics`):
   - `configure()` — дерево `storage.{dsn, reconnect_backoff_seconds}`, `application_name`,
     `component_name`, `errors.short_exception_class_name`, `http_client.sanitize_path`,
     `cache.pools` (2.2; `metrics.cache` и `cache.messenger.restart_workers_signal` отвергаются),
     `messenger.queue_depth.{enabled, transports, period}` (2.2, `canBeEnabled()`: любой ключ секции включает её,
     `period` — ISO 8601 больше нуля), `metric_enums` (каждый класс обязан реализовать `MetricDefinitionInterface`). Значения по
     умолчанию dsn/application/component — env-плейсхолдеры с параметрами
     `msstc4symfony_metrics.default_dsn` / `msstc4symfony_metrics.unknown`;
   - `loadExtension()` — пишет параметры `msstc4symfony_metrics.<путь опции>`
     (`storage.dsn`, `storage.reconnect_backoff_seconds`, `application_name`, `component_name`,
     `errors.short_exception_class_name`, `http_client.sanitize_path`, `metric_enums` =
     `[BuiltInMetric::class, ...metric_enums]`, `cache.pools`, `messenger.queue_depth.{enabled,transports,period}` —
     списки без дублей) и импортирует `Resources/config/services.php`;
   - `getPath()` — `src/` (ради `@MetricsBundle/Presentation/Controller/`).
   Контракт для мостов: `msstc4symfony_metrics.metric_enums` (компилер-пассы дописывают в конец),
   `msstc4symfony_metrics.application_name`, `msstc4symfony_metrics.component_name`.
2. `services.php` — resource-скан `src/` без всего, что требует опциональной библиотеки
   (`Infrastructure/{Doctrine,Elastica,Monolog,Guzzle}/`, `Infrastructure/HttpClient/*.php`,
   `MessengerEventListener`, `MessengerQueueDepthListener`), декораторы `Infrastructure/Cache/` (их ставит только пасс), `Infrastructure/Messenger/`, статический `OutgoingHttp/PathSanitizer` (не сервис; `ServiceScanTest`),
   а также DI, `Resources` и корня; `MetricsGuzzleMiddleware` регистрируется с гардом
   `interface_exists(GuzzleHttp\ClientInterface)` — до оптимизации, чтобы автовайринг дал ему зависимости; `HttpClient/URLAssembler/` сканируется:
   `#[AutoconfigureTag]` на `AssemblerInterface` тегирует ассемблеры приложения, только пока интерфейс в
   скане. bind `$applicationName`/`$componentName`. С гардом регистрируются
   `MessengerEventListener` (`class_exists(WorkerMessageReceivedEvent)`) и `TimingSubscriber`
   (`interface_exists(CommandSubscriber)`); DBAL/http-client/Elastica — компилер-пассами.
   Опции попадают в сервисы через
   `#[Autowire(param: 'msstc4symfony_metrics.…')]` (`ErrorCollector`, `HttpClientDecorator`,
   `Storage\Factory`, `MetricRepositoryFactory`).
3. Компилер-пассы (`MetricsBundle::build()`):
   - `AddMonologDecoratorCompilerPass` — декорирует `monolog.logger.*`;
   - `AddDoctrineDBALMonitorPass` (priority 1, раньше `MiddlewaresPass` DoctrineBundle) — при
     параметре `doctrine.connections` регистрирует на каждое соединение
     `msstc4symfony_metrics.doctrine.middleware.<name>` = `NamedConnectionMiddleware` с
     `$connectionName` и тегом `doctrine.middleware` (`connection: <name>`). Без DoctrineBundle —
     ничего (ручная проводка `NamedConnectionMiddleware`);
   - `AddHttpClientMonitorPass` (priority -256) — декорирует **только**
     `http_client.transport` (приоритет декорации -20 — снаружи мока
     `mock_response_factory`, -10): туда сходятся все клиенты фреймворка (default и scoped)
     уже с абсолютным URL, поэтому каждый реальный запрос считается один раз и с
     правильным host. Повторы `retry_failed` — отдельные запросы. Клиенты, созданные
     приложением в обход FrameworkBundle, не мониторятся. URL-ассемблеры — по тегу
     `AssemblerInterface::TAG` (`metrics.http_client.url_assembler`);
   - `AddGuzzleMonitorPass` (`TYPE_BEFORE_REMOVING`, после разрешения именованных аргументов) — у каждого
     сервиса `GuzzleHttp\ClientInterface`: литеральный массив конфига `GuzzleHttp\Client` → `handler` = фабрика
     `MetricsGuzzleMiddleware::decorateHandler(<handler|null>)` (не-`HandlerStack` handler — пропуск с
     `container->log`); фабрика/свой конструктор/конфиг-параметр/ключи `max_host_connections`,
     `max_total_connections`, `transport_sharing`, `multiplex` без `handler` → конфигуратор `addHandler()` (свой
     конфигуратор сохраняется через `ChainedClientConfigurator`); без `getConfig()` (Guzzle 8) → только лог.
     Middleware ставится `before('allow_redirects')` (нет его — `unshift`), предварительно `remove()` своего имени;
   - `DecorateCachePoolsPass` (BEFORE_OPTIMIZATION, priority 16 — после `CachePoolPass` 32, который выводит namespace
     пула из id) — каждый пул из `cache.pools` (алиас раскрывается, метка `pool` — имя как в конфиге) декорируется
     `CountingCacheAdapter` или `CountingTagAwareCacheAdapter` (класс по цепочке `ChildDefinition`; достаточно
     `AdapterInterface` — `cache.system` объявлен так фабрикой). Тегу `cache.pool` пасс дописывает `name` = id (иначе
     после переезда тега на декоратор `cache:pool:prune` и профайлер показали бы id декоратора), фабрику
     `setCallbackWrapper` early-expiration переводит на `<декоратор>.inner` (как `CacheCollectorPass`);
   - `RegisterMessengerQueueDepthPass` (BEFORE_OPTIMIZATION, priority -10 — после `_instanceof`; теги listener'а
     явные) — при `queue_depth.enabled`: имена транспортов как у `messenger:stats` (последний `alias` тега
     `messenger.receiver`, иначе id), `MessengerQueueDepthReader` (адаптер порта `Application\Port\QueueDepthReaderInterface`,
     @internal) с локатором выбранных receivers; неизвестное имя или перечисленный receiver известного класса без
     `MessageCountAwareInterface` — `InvalidArgumentException`, нет `messenger.receiver_locator` — `LogicException`,
     ни одного транспорта — `container->log()` и ничего;
   - `KeepCachePoolsReversiblePass` (BEFORE_REMOVING) — возвращает `container.reversible` внутренним пулам
     (`<декоратор>.inner`) из параметра, который пишет `DecorateCachePoolsPass` и который пасс удаляет;
   - `ElasticaClientDefinitions` (`@internal`): `find()` — общий отбор для двух пассов ниже: не
     абстрактные и не декораторы (`DefinitionFilter::isDecoratable`), класс (по цепочке родителей
     `ChildDefinition` — `lineage()`, после разрешения параметров, через `getReflectionClass`) —
     `Elastica\Client` или подкласс (FOSElasticaBundle);
   - `SaveElasticaClientsListPass` (Elastica 7) — пишет id клиентов в параметр
     `msstc4symfony_metrics.elastica.clients` (`SaveElasticaClientsListPass::PARAMETER`) и делает
     их public. На Elastica 8 (нет `AbstractTransport`) — пустой список;
   - `DecorateElasticaClientsPass` (Elastica 8 и 9, гард `class_exists(Elastic\Transport\Transport)`) —
     в литеральный массив конфига клиента (`index_0` / `$config` / `0`, свой или от родителя) пишет
     `transport_config.http_client` = inline `TimingHttpClient(<inner>, @ElasticaCollector[, %msstc4symfony_metrics.elastica.sanitize_path%])`, где
     `<inner>` = фабрика `ConfiguredHttpClientFactory::create(<http_client|null>, http_client_config,
     http_client_options)`; эти два ключа из `transport_config` удаляются. Не литеральный
     конфиг/`transport_config` — пропуск с `$container->log()`.
4. `MetricsBundle::boot()` — то, что нельзя сделать в контейнере:
   подписка `TimingSubscriber` на драйвер MongoDB и подмена транспорта у
   соединений Elastica 7 на `TimingTransport` с флагом `elastica.sanitize_path` (на 8/9 — ничего,
   там всё сделал пасс). Метку `path` на обеих версиях нормализует `ElasticaPathSanitizer`.
5. `MetricRepositoryFactory` собирает каталог из `msstc4symfony_metrics.metric_enums` (повторный
   класс читается один раз); одинаковое имя метрики в двух enum — `LogicException`.

## Опциональные библиотеки

Все интеграции, кроме Redis-хранилища, опциональны: http-client, Guzzle, DBAL,
MongoDB, Elastica, Messenger, APCu. Метрики кешей и глубины очередей — opt-in в конфиге. Точка проводки молча выключается без своей библиотеки:
либо явным гардом (`interface_exists` для интерфейсов, `class_exists` для
классов — http-client, MongoDB, Elastica в `boot()`), либо потому, что без
библиотеки в контейнере нет подходящих определений (DBAL, список клиентов Elastica). Job `minimal` в CI доказывает, что гарды держат.

## Хранилище

`Storage\Factory::create()` выбирает адаптер Prometheus по схеме DSN:
`redis`, `redisng`, `apc`, `apcng`, `inmemory`. Любая ошибка — откат на
`InMemory` с записью в лог канала `metrics_bundle` (только схема DSN, без учётных данных).
Схемы без хоста (`apc://`, `apcng://`, `inmemory://`) разбираются отдельно:
`parse_url()` их отвергает.

`redis`/`redisng` отдаются обёрнутыми в `ReconnectingRedisAdapter` (@internal): при
`RedisException`/`StorageException`/`RedisClientException` адаптер выбрасывается, следующая операция
строит новый (`new Redis($options)`) — новое соединение, AUTH, SELECT, read timeout. Сама упавшая
операция не повторяется. Запись никогда не бросает (лог в `metrics_bundle`), чтение бросает
`StorageException`, а PHP warnings phpredis не доходят до обработчика приложения; `/_/metrics` при
недоступном хранилище отвечает 503. После сетевой ошибки действует circuit breaker:
`msstc4symfony_metrics.storage.reconnect_backoff_seconds` (5 с) без попыток переподключения. Подробности — `known-issues.md`, разделы о переподключении Redis, регрессии с 500 и circuit breaker.

## Не-final классы

