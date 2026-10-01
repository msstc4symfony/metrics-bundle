# Metrics Symfony bundle

![Build Status](https://github.com/msstc4symfony/metrics-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![codecov](https://codecov.io/github/msstc4symfony/metrics-bundle/graph/badge.svg?token=EoGwEpONxh)](https://codecov.io/github/msstc4symfony/metrics-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Automatic collection of application runtime metrics and Prometheus-format export over HTTP. Drops into a Symfony app, observes the request, console, exception, log, DB and outbound HTTP layers without code changes, stores aggregated values in Redis (or any Prometheus storage adapter), and exposes them at `GET /_/metrics` for Prometheus to scrape.

Out of the box the following are measured:

- incoming HTTP requests (count, duration, response status)
- console command executions (count, duration)
- uncaught exceptions and Monolog error/warning records
- outgoing requests via `symfony/http-client`
- Doctrine DBAL queries (count, duration, type, table)
- MongoDB driver commands
- `ruflin/elastica` 7.x requests (Elastica 8 removed the transport API the bundle hooks into; its requests are not measured)
- system info (CPU load, memory, OPcache, FPM, filesystem)

The exact list of metrics with labels and histogram buckets is shown by `bin/console metrics:list`.

## Profiling spans

Span durations from `msstc4symfony/profiling-bundle` are exported by
[`msstc4symfony/metrics-bridge-profiling`](https://github.com/msstc4symfony/metrics-bridge-profiling)
(`profiling_span_duration_histogram_seconds`). The built-in `MetricProcessor` is deprecated
and is replaced by the bridge once it is installed.

## Compatibility

| Bundle | PHP   | Symfony           |
| ------ | ----- | ----------------- |
| 1.x    | 8.4+  | 6.4, 7.x, 8.x     |

Requires `ext-redis` and a Redis-compatible storage (or APCu / in-memory for tests).

## Installation

The package lives in a private GitHub repository, so register it as a VCS
repository first. `no-api` makes Composer clone over SSH instead of calling the
GitHub API, which would need a token for a private repository:

```sh
composer config repositories.msstc4symfony-metrics '{"type": "vcs", "url": "git@github.com:msstc4symfony/metrics-bundle.git", "no-api": true}'
composer require msstc4symfony/metrics-bundle
```

Without a GitHub token Composer cannot download dist archives of a private
repository; either add one (`composer config github-oauth.github.com <token>`)
or install from source (`composer require --prefer-source ...`).

Symfony Flex will auto-register the bundle. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    Msstc4Symfony\MetricsBundle\MetricsBundle::class => ['all' => true],
];
```

Add the env vars (see [doc/.env.dist](doc/.env.dist)):

```
METRICS_STORAGE_DSN=redis://redis:6379?database=4
APPLICATION_NAME=my-app
COMPONENT_NAME=http
```

Import the endpoint route — bundles cannot add routes on their own:

```yaml
# config/routes/metrics.yaml
metrics:
    resource: '@MetricsBundle/Presentation/Controller/'
    type: attribute
```

This adds `GET /_/metrics`. **The endpoint is unauthenticated by default and leaks operational data (route names, table names, outbound hosts, exception classes).** Restrict it in your host app's firewall:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/_/metrics, ips: [10.0.0.0/8, 127.0.0.1] }
```

or behind a reverse proxy / private network.

## Storage DSN

`METRICS_STORAGE_DSN` picks the Prometheus storage adapter:

| Scheme       | Adapter                       | Use case                                       |
| ------------ | ----------------------------- | ---------------------------------------------- |
| `redis://`   | `Prometheus\Storage\Redis`    | Default. Multi-process aggregation.            |
| `redisng://` | `Prometheus\Storage\RedisNg`  | Pipelined Redis adapter (recommended on high-traffic scrapes). |
| `apc://`     | `Prometheus\Storage\APC`      | Single-host APCu storage.                       |
| `apcng://`   | `Prometheus\Storage\APCng`    | APCu with sharded counters.                     |
| `inmemory://`| `Prometheus\Storage\InMemory` | Per-process, lost on shutdown (tests/dev).      |

If the configured adapter cannot be constructed (unreachable Redis, malformed DSN, unknown scheme), the bundle logs a warning/error via PSR-3 and silently falls back to `InMemory`. Watch your logs in production.

Redis query parameters: `database`, `read_timeout` (default `1`), `timeout` (default `0.1`), `persistent_connections`, `ssl_verify_peer` (default `true`).

```
redis://user:pass@redis:6379/4?read_timeout=2&persistent_connections=1
```

> **Operational note.** `ssl_verify_peer` defaults to `true`: TLS Redis with a self-signed CA chain will fail to connect and the bundle will silently fall back to `InMemory` (each PHP worker keeps its own metrics, none are exposed to Prometheus). Pass `?ssl_verify_peer=0` in the DSN when intentionally using a private CA. The same silent fallback applies if `read_timeout=1` is too aggressive for the network — increase via the query parameter.

## Endpoints and commands

| Method             | What                                                                |
| ------------------ | ------------------------------------------------------------------- |
| `GET /_/metrics`   | Returns all collected metrics in Prometheus text format. Responds with `Cache-Control: no-store, max-age=0` so every scrape sees fresh values. |
| `bin/console metrics:list`  | Tabular list of every registered metric (name, type, labels, histogram buckets). |
| `bin/console metrics:clear` | Wipes the storage adapter.                                   |

## Adding a custom metric

1. Implement a string-backed enum on `Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface` describing your metrics:

    ```php
    use Msstc4Symfony\MetricsBundle\Infrastructure\Entity\Label;
    use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnumInterface;
    use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelTypeEnum;
    use Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricTypeEnum;

    enum AppMetrics: string implements MetricLabelEnumInterface
    {
        case ORDER_CREATED = 'order_created_total';

        public function getType(): MetricTypeEnum
        {
            return MetricTypeEnum::COUNTER;
        }

        public function getDescription(): string
        {
            return 'Successfully created orders';
        }

        public function getLabels(): array
        {
            return [new Label('channel', MetricLabelTypeEnum::STRING)];
        }

        public function getBatches(): array
        {
            return [];
        }
    }
    ```

2. Register the enum in `config/services.yaml` by appending its FQCN to `metrics_bundle.metric_enums`:

    ```yaml
    parameters:
        metrics_bundle.metric_enums:
            - Msstc4Symfony\MetricsBundle\Infrastructure\Enum\MetricLabelEnum
            - App\Metrics\AppMetrics
    ```

3. Write a service that extends `Msstc4Symfony\MetricsBundle\Infrastructure\Collector\AbstractCollector` and exposes the methods you'll call from your code. The base collector takes care of registering the metric, applying the `application`/`component` labels, and catching storage errors.

These extension points are public API and follow semantic versioning within a major:
`MetricLabelEnumInterface`, `Label`, `MetricLabelTypeEnum`, `MetricTypeEnum`,
`AbstractCollector` (constructor and protected `incCounter` / `setGauge` / `observeHistogram`)
and the `metrics_bundle.metric_enums` parameter. Packages such as
`msstc4symfony/metrics-bridge-profiling` build on them and append their enum in a compiler
pass. If two enums declare the same metric name, the first declaration is listed.

## Bundle parameters

| Parameter                                       | Default                             | Description                                              |
| ----------------------------------------------- | ----------------------------------- | -------------------------------------------------------- |
| `metrics_bundle.dsn`                            | `redis://127.0.0.1:6379`            | Storage DSN; resolved from `METRICS_STORAGE_DSN`.        |
| `metrics_bundle.applicationName`                | `unknown`                           | Value of the `application` label; resolved from `APPLICATION_NAME`. |
| `metrics_bundle.componentName`                  | `unknown`                           | Value of the `component` label; resolved from `COMPONENT_NAME`. |
| `metrics_bundle.metric_enums`                   | `[MetricLabelEnum::class]`          | List of FQCNs of `MetricLabelEnumInterface` enums to register. |
| `metrics_bundle.exceptionLabelShortClassName`   | `false`                             | If `true`, the `exception_total` metric labels by short class name instead of FQCN — reduces info leakage. |
| `metrics_bundle.httpClientSanitizePath`         | `true`                              | Replaces id/uuid/hash segments in outbound HTTP paths (`/users/42` → `/users/:id`) when no `URLAssembler` matched. |

## How it works

Collectors measure at well-known integration points (Symfony kernel events, console events, Monolog handler decorator, Doctrine DBAL middleware, MongoDB driver subscriber, Elastica transport adapter, `symfony/http-client` decorator). They write to a Prometheus `RegistryInterface` backed by the configured storage adapter (Redis by default). Prometheus periodically scrapes the `/_/metrics` controller, which renders the registry as text/plain.

Metric state lives in Redis indefinitely (or until `metrics:clear` is run / the app is redeployed and the keys are flushed). Each scrape returns the full current state — Prometheus overwrites its own series on every scrape, so a stale Redis simply produces stale values, not duplicates.

## Local development

```sh
make install-ci  # composer-ci.json: optional libraries + CI-only tools
make check       # lint, PHPStan, CS-Fixer, composer validate/audit, Rector, deptrac
make fix         # CS-Fixer + Rector apply
make test        # unit + integration suites (integration skips without optional libraries)
```

APCu storage tests need `ext-apcu` with `apc.enable_cli=1`; without it they are skipped.

Run a single test: `vendor/bin/phpunit tests/unit/Path/To/SomeTest.php`.

## License

MIT — see [LICENSE](LICENSE).
