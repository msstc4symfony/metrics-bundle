# Changelog

All notable changes to this bundle are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

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
- Optional: Doctrine DBAL / DoctrineBundle, `mongodb/mongodb`, `ruflin/elastica` 7, Symfony
  HttpClient, Messenger, APCu.

[1.0.0]: https://github.com/msstc4symfony/metrics-bundle/releases/tag/v1.0.0
