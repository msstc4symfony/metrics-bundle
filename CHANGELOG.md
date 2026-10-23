# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [2.2.1] - 2026-10-23

### Fixed

- No `symfony/dependency-injection` 8.1 deprecation "Relying solely on the name of parameter \"$metricsCache\"" when the
  application defines the `metrics.cache` pool: the system info and queue depth listeners get the throttle pool
  explicitly (`metrics.cache`, or the `CacheInterface` alias — `cache.app` — without it, as before). A `$metricsCache`
  argument the application set on the info listener is kept.

## [2.2.0] - 2026-10-23

### Added

- Guzzle metrics: with `guzzlehttp/guzzle` 7.8+ or 8 installed, every `GuzzleHttp\ClientInterface` service writes the
  `http_connection_*` series of `symfony/http-client` (same labels, URL assemblers and `http_client.sanitize_path`),
  one record per call with the final status after redirects. Clients are reached through their `handler` config or a
  service configurator; unreachable ones are named in the container compiler log (README "Guzzle metrics"). With the
  `stream` request option the duration ends with the response headers, not the body. URL assemblers get the URL
  without credentials.
- Opt-in cache pool metrics: `msstc4symfony_metrics.cache.pools` lists the pools whose lookups are counted in the new
  `cache_request` counter (`pool`, `result` = `hit`|`miss`), aggregated in memory and written once per series at the
  end of each request, command or message, every 1000 lookups and when the container is destroyed (README "Cache pool
  metrics"). Callbacks reach the pool unchanged, so `early_expiration_message_bus` keeps recomputing asynchronously.
  `metrics.cache` and `cache.messenger.restart_workers_signal` are rejected, also through an alias, and so is the pool
  they alias.
- Opt-in Messenger queue depth: `msstc4symfony_metrics.messenger.queue_depth` (`enabled`, `transports`, `period`
  = `PT1M`) samples the new `messenger_queue_depth` and `messenger_queue_depth_sampled_at` gauges (`transport`) of the
  transports that can count their messages, at most once per period (each process skips the shared throttle until the
  period ends; after a failure of that pool, for a full period), on `kernel.terminate` and in workers (README
  "Messenger queue depth"). Receivers tagged through
  `_instanceof` are seen; a listed receiver that cannot count fails the compilation when its class is known.
- `Application\Port\MetricBatchRecorderInterface::incrementBy()`, implemented by the Prometheus recorder, for counts
  aggregated before they are written.

### Changed

- `symfony/cache` `^7.4|^8.0` and `symfony/cache-contracts` `^3.6` are direct requirements: the cache decorators
  implement their 7.4 interfaces (`NamespacedPoolInterface` of `symfony/cache-contracts` 3.6), and FrameworkBundle 7.4 still accepts `symfony/cache` 6.4.
- `BuiltInMetric` has the cases `CACHE_REQUEST`, `MESSENGER_QUEUE_DEPTH` and `MESSENGER_QUEUE_DEPTH_SAMPLED_AT`; minor
  releases add cases, so a `match` over them needs a `default` arm (README "Public API"). The Roave BC check ignores
  added `BuiltInMetric` cases (`.roave-backward-compatibility-check.xml`).
- The internal `HttpClientDecorator` takes the host/path labels from the new internal `OutgoingRequestLabels`, shared
  with the Guzzle middleware; the labels are unchanged. The internal `PathSanitizer` moved from
  `Infrastructure\HttpClient` to `Infrastructure\OutgoingHttp`.

### Fixed

- The comment of the internal `CompletionStreamFilter` claimed it also fires on `fclose()`: it fires on EOF only, so
  closing a partially read `toStream()` records no duration (now pinned by a test).

## [2.1.0] - 2026-10-22

### Changed

- The Doctrine metrics take the `type` and `table` labels from the new `msstc4symfony/sql-labeller` package, the same
  parser profiling-bundle names its SQL spans with; the labels are unchanged. A new runtime dependency: register its
  repository before updating, `composer config repositories.msstc4symfony-sql-labeller vcs
  https://github.com/msstc4symfony/sql-labeller` (README "Installation").

### Removed

- The internal `Infrastructure\Doctrine\DBAL\Metrics\QueryLabeller`, `QueryLabelling` and `QueryLabels` (now in
  sql-labeller).

## [2.0.0] - 2026-10-20

No changes since 2.0.0-rc.1.

## [2.0.0-rc.1] - 2026-10-19

### Added

- Ports `Application\Port\MetricRecorderInterface` (`increment`/`set`/`observe`; storage failures are logged on the
  `metrics_bundle` channel, never thrown; a metric of another type throws `InvalidArgumentException`) and
  `Application\Port\MetricStorageInterface` (`export(): MetricExport`,
  `clear()`, both throw `MetricStorageException`), with Prometheus adapters in `Infrastructure\Prometheus\`.
- `Application\LogChannel::NAME` (`metrics_bundle`).
- README "Public API": the classes, configuration keys, parameters, route and commands applications may rely on.

### Changed

- Classes moved and renamed (namespace `Msstc4Symfony\MetricsBundle\`):
  - `Infrastructure\Enum\MetricLabelEnumInterface` → `Application\Metric\MetricDefinitionInterface`;
  - `Infrastructure\Enum\MetricLabelEnum` → `Application\Metric\BuiltInMetric`;
  - `Infrastructure\Enum\MetricTypeEnum` → `Application\Metric\MetricType`;
  - `Infrastructure\Enum\MetricLabelTypeEnum` → `Application\Metric\LabelType`;
  - `Infrastructure\Entity\{Metric,Label}` → `Application\Metric\{Metric,Label}`;
  - `Infrastructure\Repository\{MetricRepositoryInterface,MetricRepository}`,
    `Infrastructure\Factory\MetricRepositoryFactory` →
    `Application\Metric\…`;
  - `Infrastructure\Enum\DoctrineQueryTypeEnum` → `Application\Collector\DoctrineQueryType`;
  - `Infrastructure\Enum\MessengerMessageStatusEnum` → `Application\Metric\MessengerMessageStatus`;
  - `Infrastructure\Collector\<X>Collector` → `Application\Collector\<X>Collector` (they take a
    `MetricRecorderInterface`);
  - `Infrastructure\Storage\*` → `Infrastructure\Prometheus\Storage\*`; `Factory::LOG_CHANNEL` →
    `Application\LogChannel::NAME`;
  - `Infrastructure\Monolog\Handler\HandlerDecorator` → `Framework\Monolog\HandlerDecorator`;
  - `Framework\EventListener\*` → `Presentation\EventListener\*`;
  - `DependencyInjection\Compiler\*Pass` → `DependencyInjection\CompilerPass\*Pass`;
    `DependencyInjection\Compiler\{DefinitionFilter,ElasticaClientDefinitions}` → `DependencyInjection\…`.
- Removed `AbstractCollector`: a custom collector is a service with `MetricRecorderInterface` and an enum implementing
  `MetricDefinitionInterface` (README "Adding a custom metric").
- `ErrorCollector::incError()` takes the level name (`string`) instead of `Monolog\Level`.
- `GetMetricsController` and `metrics:clear` work through `MetricStorageInterface`; the MongoDB/Elastica 7 runtime
  wiring of `MetricsBundle::boot()` moved to the internal `DependencyInjection\RuntimeIntegrations`.
- A failed metric write is logged on the `metrics_bundle` channel instead of the application's default logger (`app`):
  it no longer appears in the application log and no longer increments `symfony_error{level="ERROR"}` — move alerts
  that matched it to the `metrics_bundle` channel. `ErrorCollector` now logs its own write failures too (1.x dropped
  them silently).
- `metrics:clear` fails on any storage error and `/_/metrics` answers 503 on any export error: the storage adapter wraps
  every `Throwable` in `MetricStorageException` (1.x: `metrics:clear` caught only `StorageException`).
- `HandlerDecorator` does not count a record logged through the same logger while it is counting one (second guard
  against recursion).
- `msstc4symfony_metrics.metric_enums` rejects enums that are not string-backed (1.x skipped them silently).
- `MetricDefinitionInterface::getLabels()` / `getBatches()` and `MetricRepositoryInterface::findAll()` are typed
  `list<Label>` / `list<float>` / `list<Metric>`.
- Classes outside the README "Public API" are `@internal`.
- PHPStan enforces `#[\Override]` (`checkMissingOverrideMethodAttribute`); adopts bundle-standard v3 (canonical
  layers, anchored deptrac `BundleRoot`).
- Metric names, labels, the `/_/metrics` route, the commands and the configuration keys are unchanged.

## [1.4.0] - 2026-10-14

### Added

- `symfony/polyfill-php84` dependency (`array_any` on PHP 8.2/8.3).

### Changed

- Requires PHP 8.2 instead of 8.4 (Symfony 8 still needs PHP 8.4).
- Class constants no longer declare a native type (PHP 8.2 has none); values are unchanged.

## [1.3.0] - 2026-10-07

### Added

- Elastica 9 (`ruflin/elastica` 9.0, Elasticsearch 9) request metrics, measured the same way as on
  Elastica 8 (`DecorateElasticaClientsPass` + `TimingHttpClient`); metric names, labels and buckets
  do not change.

### Changed

- Optional `ruflin/elastica` support is now `^7.3|^8.0|^9.0`.

### Fixed

- No discoverable PSR-18 client (no `symfony/http-client`, Guzzle, …) and no
  `transport_config.http_client`: on Elastica 9 the bundle threw
  `Http\Discovery\Exception\NotFoundException` while building the client, although elastic-transport 9
  falls back to its own `Elastic\Transport\Client\Curl`. `ConfiguredHttpClientFactory` now makes the
  same fallback, so the Curl client is used and measured. On Elastica 8 (elastic-transport 8 has no
  fallback) the discovery error is rethrown, exactly as Elastica throws it without the bundle.

## [1.2.0] - 2026-10-06

### Changed

- The `path` label of the `elastica_request_*` metrics is normalised on Elastica 7 and 8: the id of a
  document endpoint (`<index>/{_doc,_create,_update,_source,_explain,_termvectors}/<id>`) becomes
  `:id`, then UUID / digit / long-hex segments become `:uuid` / `:id` / `:hash`. Label values for
  document paths change (`products/_doc/sku-1` → `products/_doc/:id`): dashboards and alerts that
  match raw ids need updating.

### Added

- `msstc4symfony_metrics.elastica.sanitize_path` (bool, default `true`); `false` keeps the raw
  request path in the label, as in 1.1.0.

### Fixed

- Elastica 7: a response without a query time (`NullTransport`, custom transports) no longer makes
  the application request fail with a `TypeError`; the duration is measured around the call instead.

## [1.1.0] - 2026-10-05

### Added

- Elastica 8 request metrics: `DecorateElasticaClientsPass` wraps each client's PSR-18 HTTP client
  (`transport_config.http_client`) in `TimingHttpClient`; metric names, labels and buckets are the
  same as on Elastica 7. `http_client_config` / `http_client_options` are applied to the HTTP client
  before it is wrapped. Clients whose configuration is not a literal array, or whose `transport_config`
  is not a literal array, are not measured (the container compiler log says why).
- Subclasses of `Elastica\Client` and FOSElasticaBundle clients (child definitions of its abstract
  client prototype) are measured on Elastica 7 and 8.

### Changed

- Optional `ruflin/elastica` support is now `^7.3|^8.0` (was 7 only).

## [1.0.0] - 2026-10-04

First release of `msstc4symfony/metrics-bundle` (namespace `Msstc4Symfony\MetricsBundle`).

### Added

- Automatic runtime metrics with `application` / `component` labels: incoming HTTP requests,
  console commands, uncaught exceptions, Monolog records by level, outgoing `symfony/http-client`
  requests (decorated `http_client.transport`), Doctrine DBAL queries (per DoctrineBundle
  connection, `type` and `table` labels from a top-level SQL parse), MongoDB driver commands,
  Messenger sent / handled messages and handling duration, Elastica 7 requests, system info
  (CPU load, memory, OPcache, FPM, filesystem).
- Prometheus export at `GET /_/metrics` (loaded through `routing.controllers` or an explicit
  import; `503` while the storage is unreachable) and the `metrics:list` / `metrics:clear` commands.
- Storage selected by DSN: `redis://`, `redisng://`, `apc://`, `apcng://`, `inmemory://`; a
  construction failure falls back to `InMemory`. Redis storage reconnects after a lost
  connection with a circuit breaker, never throws on writes and keeps phpredis warnings away from
  the application error handler.
- Custom metrics: string-backed enums implementing `MetricLabelEnumInterface`, listed under
  `metric_enums`; duplicate metric names are rejected.
- Configuration under the `msstc4symfony_metrics` root: `storage.dsn`,
  `storage.reconnect_backoff_seconds`, `application_name`, `component_name`,
  `errors.short_exception_class_name`, `http_client.sanitize_path`, `metric_enums`.

### Requirements

- PHP >= 8.4 with `ext-redis`, Symfony ^7.4|^8.0, Monolog ^3.5, `symfony/monolog-bundle` ^3.11|^4.0,
  `promphp/prometheus_client_php` ^2.13.
- Optional: Doctrine DBAL / DoctrineBundle, `mongodb/mongodb`, `ruflin/elastica` ^7.3|^8.0, Symfony
  HttpClient, Messenger, APCu.

[2.2.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v2.0.0-rc.1...v2.0.0
[2.0.0-rc.1]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.4.0...v2.0.0-rc.1
[1.4.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/msstc4symfony/metrics-bundle/releases/tag/v1.0.0
