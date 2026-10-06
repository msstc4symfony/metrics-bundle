# Архитектура

## Слои (`src/`)

| Слой | Что внутри | Может зависеть от |
|---|---|---|
| `Infrastructure` | коллекторы, `MetricRepository`, `Storage\Factory`, сущности/enum, адаптеры DBAL/ODM/Elastica/HttpClient/Monolog | ничего внутри бандла |
| `Presentation` | `GetMetricsController` (`GET /_/metrics`), команды `metrics:list`, `metrics:clear` | `Infrastructure` |
| `Framework` | слушатели kernel/console/Messenger | `Infrastructure`, `Presentation` |
| `DependencyInjection` | компилер-пассы | `Infrastructure` |
| `MetricsBundle` (корень) | дерево конфигурации, параметры, регистрация пассов, `boot()` | `DependencyInjection`, `Infrastructure` |

Правила зафиксированы в `deptrac.yaml` как статус-кво этапа A (спек 6.2): новые
направления зависимостей запрещены, перекраска слоёв — этап B.
`Framework → Presentation` существует ради одного случая: `RequestEventListener`
не меряет запросы к самому эндпоинту метрик (`GetMetricsController::ROUTE_NAME`).

## Точки проводки

1. `MetricsBundle` (`AbstractBundle`, алиас `msstc4symfony_metrics`):
   - `configure()` — дерево `storage.{dsn, reconnect_backoff_seconds}`, `application_name`,
     `component_name`, `errors.short_exception_class_name`, `http_client.sanitize_path`,
     `metric_enums` (каждый класс обязан реализовать `MetricLabelEnumInterface`). Значения по
     умолчанию dsn/application/component — env-плейсхолдеры с параметрами
     `msstc4symfony_metrics.default_dsn` / `msstc4symfony_metrics.unknown`;
   - `loadExtension()` — пишет параметры `msstc4symfony_metrics.<путь опции>`
     (`storage.dsn`, `storage.reconnect_backoff_seconds`, `application_name`, `component_name`,
     `errors.short_exception_class_name`, `http_client.sanitize_path`, `metric_enums` =
     `[MetricLabelEnum::class, ...metric_enums]`) и импортирует `Resources/config/services.php`;
   - `getPath()` — `src/` (ради `@MetricsBundle/Presentation/Controller/`).
   Контракт для мостов: `msstc4symfony_metrics.metric_enums` (компилер-пассы дописывают в конец),
   `msstc4symfony_metrics.application_name`, `msstc4symfony_metrics.component_name`.
2. `services.php` — resource-скан `src/` без всего, что требует опциональной библиотеки
   (`Infrastructure/{Doctrine,Elastica,Monolog}/`, `Infrastructure/HttpClient/*.php`,
   `MessengerEventListener`), а также DI, `Resources` и корня; `HttpClient/URLAssembler/` сканируется:
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
   - `ElasticaClientDefinitions` (`@internal`): `find()` — общий отбор для двух пассов ниже: не
     абстрактные и не декораторы (`DefinitionFilter::isDecoratable`), класс (по цепочке родителей
     `ChildDefinition` — `lineage()`, после разрешения параметров, через `getReflectionClass`) —
     `Elastica\Client` или подкласс (FOSElasticaBundle);
   - `SaveElasticaClientsListPass` (Elastica 7) — пишет id клиентов в параметр
     `msstc4symfony_metrics.elastica.clients` (`SaveElasticaClientsListPass::PARAMETER`) и делает
     их public. На Elastica 8 (нет `AbstractTransport`) — пустой список;
   - `DecorateElasticaClientsPass` (Elastica 8, гард `class_exists(Elastic\Transport\Transport)`) —
     в литеральный массив конфига клиента (`index_0` / `$config` / `0`, свой или от родителя) пишет
     `transport_config.http_client` = inline `TimingHttpClient(<inner>, @ElasticaCollector[, %msstc4symfony_metrics.elastica.sanitize_path%])`, где
     `<inner>` = фабрика `ConfiguredHttpClientFactory::create(<http_client|null>, http_client_config,
     http_client_options)`; эти два ключа из `transport_config` удаляются. Не литеральный
     конфиг/`transport_config` — пропуск с `$container->log()`.
4. `MetricsBundle::boot()` — то, что нельзя сделать в контейнере:
   подписка `TimingSubscriber` на драйвер MongoDB и подмена транспорта у
   соединений Elastica 7 на `TimingTransport` с флагом `elastica.sanitize_path` (на 8 — ничего,
   там всё сделал пасс). Метку `path` на обеих версиях нормализует `ElasticaPathSanitizer`.
5. `MetricRepositoryFactory` собирает каталог из `msstc4symfony_metrics.metric_enums` (повторный
   класс читается один раз); одинаковое имя метрики в двух enum — `LogicException`.

## Опциональные библиотеки

Все интеграции, кроме Redis-хранилища, опциональны: http-client, DBAL,
MongoDB, Elastica, Messenger, APCu. Точка проводки молча выключается без своей библиотеки:
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

- `AbstractCollector` — базовый класс собственных коллекторов приложения (публичный API:
  конструктор и protected `incCounter` / `setGauge` / `observeHistogram` / `observeSummary`).
