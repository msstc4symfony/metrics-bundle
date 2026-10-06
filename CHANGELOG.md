# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

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

[1.2.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/msstc4symfony/metrics-bundle/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/msstc4symfony/metrics-bundle/releases/tag/v1.0.0
