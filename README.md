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
- Symfony Messenger messages sent to transports and consumed by workers (count by outcome, handling duration)
- `ruflin/elastica` 7.x requests (Elastica 8 removed the transport API the bundle hooks into; its requests are not measured)
- system info (CPU load, memory, OPcache, FPM, filesystem)

The exact list of metrics with labels and histogram buckets is shown by `bin/console metrics:list`.

## Profiling spans

Span durations from `msstc4symfony/profiling-bundle` are exported by
[`msstc4symfony/metrics-bridge-profiling`](https://github.com/msstc4symfony/metrics-bridge-profiling)
(`profiling_span_duration_histogram_seconds`).

## Compatibility

| Bundle | PHP   | Symfony           |
| ------ | ----- | ----------------- |
| 1.x    | 8.4+  | 7.4, 8.x          |

Requires `ext-redis` and a Redis-compatible storage (or APCu / in-memory for tests), and
`promphp/prometheus_client_php` 2.13+ (`redisng://` needs 2.7, a DSN user name for Redis ACL is sent
to `AUTH` from 2.13). The lowest declared versions are tested in CI (`--prefer-lowest`).

## Installation

The package is not on Packagist yet, so register its GitHub repository first:

```sh
composer config repositories.msstc4symfony-metrics vcs https://github.com/msstc4symfony/metrics-bundle
composer require msstc4symfony/metrics-bundle
```

Symfony Flex will auto-register the bundle. Otherwise add it to `config/bundles.php`:

```php
return [
    // ...
    Msstc4Symfony\MetricsBundle\MetricsBundle::class => ['all' => true],
];
```

Add the env vars (see [doc/.env.dist](doc/.env.dist)); they feed the defaults of the
[configuration](#configuration):

```
METRICS_STORAGE_DSN=redis://redis:6379?database=4
APPLICATION_NAME=my-app
COMPONENT_NAME=http
```

### Endpoint route

On Symfony 7.4+ applications whose `config/routes.yaml` imports `routing.controllers` (the
default recipe since 7.4) the `GET /_/metrics` route is loaded automatically: the bundle's
controller is an autoconfigured service with a `#[Route]` attribute, the same mechanism that
loads `msstc4symfony/healthcheck-bundle`'s probes. Nothing to add.

When `config/routes.yaml` imports only `../src/Controller/`, import it manually:

```yaml
# config/routes/metrics.yaml
metrics:
    resource: '@MetricsBundle/Presentation/Controller/'
    type: attribute
```

Keeping this import next to `routing.controllers` is harmless: the route is registered once
(same name `metrics-get`).

**The endpoint is unauthenticated by default and leaks operational data (route names, table names, outbound hosts, exception classes).** Restrict it in your host app's firewall:

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

If the configured adapter cannot be constructed (malformed DSN, unknown scheme, missing extension), the bundle logs a warning/error via PSR-3 (channel `metrics_bundle`) and falls back to `InMemory`. Watch your logs in production.

The Redis connection is opened lazily on the first metric write or read, so an unreachable Redis does not trigger the fallback: each failed write is logged (`Cannot save metric ...`) and never breaks the request, command or message. The `redis://` and `redisng://` adapters reconnect after a lost connection (Redis restart, network blip): the operation that hits the failure is dropped, the next one opens a fresh connection (database, credentials and read timeout are applied again). Long-running workers (RoadRunner, `messenger:consume`) recover without a restart. A failed write never throws (the sample is dropped and logged on the `metrics_bundle` channel), and PHP warnings raised by phpredis while (re)connecting — e.g. `getaddrinfo ... failed` while the Redis container is stopped — never reach the application error handler, so `framework.php_errors.throw` cannot turn them into 500s.

Redis query parameters: `database` (or the DSN path, `redis://host:6379/4`), `read_timeout` (default `1`), `timeout` (default `0.1`), `persistent_connections`, `ssl_verify_peer` (default `true`).

```
redis://user:pass@redis:6379/4?read_timeout=2&persistent_connections=1
```

> **Operational notes.** `ssl_verify_peer` is accepted in the DSN but currently has no effect: `promphp/prometheus_client_php` connects without passing TLS context options to phpredis. If `read_timeout=1` is too aggressive for the network, metric writes fail (logged as `Cannot save metric ...`) — increase it via the query parameter. While Redis is unreachable the adapter retries the connection at most once per `msstc4symfony_metrics.storage.reconnect_backoff_seconds` (default 5 s); in between, writes are dropped and reads fail without touching the network. Each retry costs up to `timeout` (default `0.1` s), a DNS lookup, or — for a server that accepts connections but never answers — `read_timeout` (default `1` s; the bundle caps `default_socket_timeout` to it while the handshake runs). The option accepts env placeholders, e.g. `'%env(float:METRICS_RECONNECT_BACKOFF)%'`.

## Configuration

Optional, all keys have defaults:

```yaml
# config/packages/msstc4symfony_metrics.yaml
msstc4symfony_metrics:
    storage:
        # Storage DSN, see "Storage DSN" above.
        dsn: '%env(default:msstc4symfony_metrics.default_dsn:METRICS_STORAGE_DSN)%' # redis://127.0.0.1:6379
        # After a Redis connection failure, metric writes are dropped and /_/metrics answers 503
        # without any network call for this many seconds; then one reconnect is tried
        # (success closes the breaker, failure opens it again). Per process. 0 = reconnect on
        # every operation (not recommended: each attempt can cost a DNS lookup or a timeout).
        reconnect_backoff_seconds: 5.0
    # Values of the "application" and "component" labels of every metric.
    application_name: '%env(default:msstc4symfony_metrics.unknown:APPLICATION_NAME)%' # unknown
    component_name: '%env(default:msstc4symfony_metrics.unknown:COMPONENT_NAME)%' # unknown
    errors:
        # Label the exception metric with the short class name instead of the FQCN (less info leakage).
        short_exception_class_name: false
    http_client:
        # Replace id/uuid/hash segments of outbound paths (/users/42 -> /users/:id) when no URL assembler matched.
        sanitize_path: true
    # Extra metric catalogs (see "Adding a custom metric"), appended after the bundle's MetricLabelEnum.
    metric_enums: []
```

### Doctrine DBAL

With DoctrineBundle every configured connection gets a metrics middleware, and the `connection` label of
the `doctrine_query_*` metrics is the DoctrineBundle connection name (`default`, `replica`, ...). Without
DoctrineBundle nothing is registered: add
`Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\NamedConnectionMiddleware` to the
connection's middlewares yourself, passing the `DoctrineConnectionCollector` service and the name to use as
the label. With DoctrineBundle do not add it by hand as well: every query would be counted twice.

The `table` label of the `doctrine_query_*` metrics is the first table of the statement's own `FROM`
(`SELECT`/`DELETE`), `INSERT INTO` or `UPDATE`, without schema/catalog prefixes or identifier quotes. A
`FROM` inside parentheses (function arguments such as `EXTRACT(EPOCH FROM ...)`, subqueries in the select
list or in conditions), comments and string literals is ignored, and a derived table
(`FROM (SELECT ... FROM orders) t`) is labelled with its inner table. The same holds for a
common table expression (`WITH x AS (SELECT ... FROM orders) SELECT ... FROM x` gives `orders`; a recursive
CTE that only reads itself gives its own name), a parenthesised `UNION` as built by DBAL's `QueryBuilder`
on PostgreSQL and MySQL is labelled with the table of its first part, and `ONLY`/`LATERAL` are skipped.
Statements without a recognisable table, or whose `FROM` lies beyond the first 16 KiB, are labelled
`unknown`. Known limitations: a table function in `FROM` (`generate_series(...)`, `unnest(...)`) is
labelled with the function name, and PostgreSQL `E'...'` escapes, dollar-quoted strings and MySQL `#`
comments are not recognised as literals or comments.

## Redis restarts in long-running workers

The bundle's own Redis storage reconnects by itself (circuit breaker, see
`msstc4symfony_metrics.storage.reconnect_backoff_seconds`). Your application's Redis clients may not: once a phpredis
command hits a dropped connection, that `\Redis` instance answers every later command with
`Redis server ... went away` until it is reconnected, and Symfony's Redis cache and lock connections
are built once per container. Under RoadRunner (`kernel_reboot.strategy: on_exception`) they are only
rebuilt when an unexpected exception reboots the kernel. A failing `/_/metrics` scrape answers 503
and does not cause such a reboot. If your readiness checks
stay red after Redis is back, rebuild the clients explicitly, for example by dispatching
`Baldinof\RoadRunnerBundle\Event\ForceKernelRebootEvent` when a Redis-backed check fails, or by
resetting the connection services between requests (`kernel.reset`).

## Messenger metrics

With `symfony/messenger` installed the bundle listens to Messenger events (no configuration):

| Metric | Type | Labels | Recorded on |
| ------ | ---- | ------ | ----------- |
| `symfony_messenger_message_sent` | counter | `transport`, `message` | `SendMessageToTransportsEvent`, once per transport the message is routed to. Retries and failure-transport re-sends are not counted. |
| `symfony_messenger_message_handled` | counter | `transport`, `message`, `status` | A worker finished a message: `handled`, `retried` (failed and will be retried) or `failed` (failed for good). |
| `symfony_messenger_message_handling_duration_histogram_seconds` | histogram | `transport`, `message`, `status` | Same moment; time from `WorkerMessageReceivedEvent` to the outcome (for batch handlers: to the batch acknowledgement). Buckets: 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 180. |

All metrics also carry the `application` and `component` labels. `transport` is the transport
(receiver) name, `message` the short class name of the message (`App\Message\SendEmail` →
`SendEmail`; anonymous classes collapse to `class@anonymous`), so same-named classes from
different namespaces share a series. Messages handled synchronously (no transport) do not go
through a worker and are not counted by `messenger_message_handled`; messages routed to a
`sync://` transport are counted as sent only. `messenger_message_sent` is recorded right before
the transports send, so a send that throws is still counted.

The metrics are declared by `MetricLabelEnum`, so `metrics:list` shows them whether or not
Messenger is installed; without Messenger they are never recorded and `/_/metrics` does not show them.

Cardinality: the histogram writes 16 series (14 buckets, `_sum`, `_count`) per
transport × message × status combination — with many message classes, watch the storage size.

## Endpoints and commands

| Method             | What                                                                |
| ------------------ | ------------------------------------------------------------------- |
| `GET /_/metrics`   | Returns all collected metrics in Prometheus text format. Responds with `Cache-Control: no-store, max-age=0` so every scrape sees fresh values. While the storage is unreachable it answers `503` (logged on the `metrics_bundle` channel), so Prometheus marks the target `up == 0` instead of seeing an empty scrape. |
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

2. Register the enum in the bundle configuration:

    ```yaml
    # config/packages/msstc4symfony_metrics.yaml
    msstc4symfony_metrics:
        metric_enums:
            - App\Metrics\AppMetrics
    ```

3. Write a service that extends `Msstc4Symfony\MetricsBundle\Infrastructure\Collector\AbstractCollector` and exposes the methods you'll call from your code. The base collector takes care of registering the metric, applying the `application`/`component` labels, and catching storage errors. Its `$applicationName` / `$componentName` constructor arguments are not autowired in your application — the bundle binds them only for its own services — so bind them to the bundle's parameters:

    ```yaml
    # config/services.yaml
    services:
        _defaults:
            bind:
                $applicationName: '%msstc4symfony_metrics.application_name%'
                $componentName: '%msstc4symfony_metrics.component_name%'
    ```

These extension points are public API and follow semantic versioning within a major:
`MetricLabelEnumInterface`, `Label`, `MetricLabelTypeEnum`, `MetricTypeEnum`,
`AbstractCollector` (constructor and protected `incCounter` / `setGauge` / `observeHistogram`)
and the `msstc4symfony_metrics.metric_enums`, `msstc4symfony_metrics.application_name` and
`msstc4symfony_metrics.component_name` container parameters. Packages such as
`msstc4symfony/metrics-bridge-profiling` build on them and append their enum to `msstc4symfony_metrics.metric_enums` in a
compiler pass. Every metric name must be declared once: if two enums declare the same name, building
the metric repository throws a `LogicException` naming both cases.

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

Run a single test: `vendor/bin/phpunit tests/Unit/Path/To/SomeTest.php`.

## License

MIT — see [LICENSE](LICENSE).
