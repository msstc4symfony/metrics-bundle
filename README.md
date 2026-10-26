# Metrics Symfony bundle

![Build Status](https://github.com/msstc4symfony/metrics-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Automatic collection of application runtime metrics and Prometheus-format export over HTTP. Drops into a Symfony app, observes the request, console, exception, log, DB and outbound HTTP layers without code changes, stores aggregated values in Redis (or any Prometheus storage adapter), and exposes them at `GET /_/metrics` for Prometheus to scrape.

Out of the box the following are measured:

- incoming HTTP requests (count, duration, response status)
- console command executions (count, duration)
- uncaught exceptions and Monolog error/warning records
- outgoing requests via `symfony/http-client` and Guzzle 7/8 client services — see [Guzzle metrics](#guzzle-metrics)
- Doctrine DBAL queries (count, duration, type, table)
- MongoDB driver commands
- Symfony Messenger messages sent to transports and consumed by workers (count by outcome, handling duration)
- `ruflin/elastica` 7.x, 8.x and 9.x requests, for `Elastica\Client` and its subclasses (FOSElasticaBundle clients included) — see [Elastica metrics](#elastica-metrics)
- system info (CPU load, memory, OPcache, FPM, filesystem)
- opt-in: cache pool hits and misses — see [Cache pool metrics](#cache-pool-metrics)
- opt-in: Messenger queue depth — see [Messenger queue depth](#messenger-queue-depth)

The exact list of metrics with labels and histogram buckets is shown by `bin/console metrics:list`.

## Profiling spans

Span durations from `msstc4symfony/profiling-bundle` are exported by
[`msstc4symfony/metrics-bridge-profiling`](https://github.com/msstc4symfony/metrics-bridge-profiling)
(`profiling_span_duration_histogram_seconds`).

## Compatibility

| Bundle  | PHP   | Symfony           |
| ------- | ----- | ----------------- |
| 2.x     | 8.2+  | 7.4, 8.x          |
| 1.4     | 8.2+  | 7.4, 8.x          |
| 1.0–1.3 | 8.4+  | 7.4, 8.x          |

Symfony 8.x needs PHP 8.4; on PHP 8.2 and 8.3 Composer installs Symfony 7.4.

Requires `ext-redis` and a Redis-compatible storage (or APCu / in-memory for tests), and
`promphp/prometheus_client_php` 2.13+ (`redisng://` needs 2.7, a DSN user name for Redis ACL is sent
to `AUTH` from 2.13). The lowest declared versions are tested in CI (`--prefer-lowest`).

## Installation

The packages are not on Packagist yet, so register the GitHub repositories of the bundle and of the SQL labeller it
uses first:

```sh
composer config repositories.msstc4symfony-sql-labeller vcs https://github.com/msstc4symfony/sql-labeller
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
    elastica:
        # Replace document ids (products/_doc/sku-1 -> products/_doc/:id) and id/uuid/hash segments
        # in the "path" label of the elastica_request_* metrics. false keeps the raw request path.
        sanitize_path: true
    cache:
        # Cache pool service ids whose lookups are counted in cache_request (see "Cache pool metrics").
        pools: []
    messenger:
        # Samples messenger_queue_depth (see "Messenger queue depth"). Configuring the section enables it.
        queue_depth:
            enabled: false
            # Transport names; empty samples every transport.
            transports: []
            # ISO 8601 interval: at most one sample per period across the processes sharing metrics.cache.
            period: PT1M
    # Extra metric catalogs (see "Adding a custom metric"), appended after the bundle's BuiltInMetric.
    metric_enums: []
```

### Doctrine DBAL

With DoctrineBundle every configured connection gets a metrics middleware, and the `connection` label of
the `doctrine_query_*` metrics is the DoctrineBundle connection name (`default`, `replica`, ...). Without
DoctrineBundle nothing is registered: add
`Msstc4Symfony\MetricsBundle\Infrastructure\Doctrine\DBAL\Metrics\NamedConnectionMiddleware` to the
connection's middlewares yourself, passing the `DoctrineConnectionCollector` service and the name to use as
the label. With DoctrineBundle do not add it by hand as well: every query would be counted twice.

The `type` and `table` labels of the `doctrine_query_*` metrics come from
[msstc4symfony/sql-labeller](https://github.com/msstc4symfony/sql-labeller), whose README has the full rules and
limits: `type` by the statement's first keyword (`other` for anything else, including a type the labeller adds later),
`table` the first table of the statement's own `FROM`, `INSERT INTO` or `UPDATE`, without schema prefixes or quotes,
following derived tables, common table expressions and a parenthesised `UNION`. A statement without a recognisable
table is labelled `unknown`.

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

The metrics are declared by `BuiltInMetric`, so `metrics:list` shows them whether or not
Messenger is installed; without Messenger they are never recorded and `/_/metrics` does not show them.

Cardinality: the histogram writes 16 series (14 buckets, `_sum`, `_count`) per
transport × message × status combination — with many message classes, watch the storage size.

### Messenger queue depth

Opt-in (`msstc4symfony_metrics.messenger.queue_depth`, see [Configuration](#configuration)):

```yaml
msstc4symfony_metrics:
    messenger:
        queue_depth:
            transports: [async, failed]  # optional; configuring the section enables it
            period: PT1M
```

| Metric | Type | Labels | Recorded on |
| ------ | ---- | ------ | ----------- |
| `symfony_messenger_queue_depth` | gauge | `transport` | Messages waiting in the transport (`MessageCountAwareInterface::getMessageCount()`, approximate on some brokers). |
| `symfony_messenger_queue_depth_sampled_at` | gauge | `transport` | Unix time (seconds) of the last successful sample of that transport: alert on `time() - … > N` to catch a stale depth. |

The transports are sampled at most once per `period` across every process sharing the `metrics.cache` pool (the
throttle of the system info gauges), on `kernel.terminate` and on `WorkerRunningEvent` (so worker pods sample too).
The shared entry holds the end of the current period: a process asks the pool again only after that time, not on
every request or worker loop.

- Transport names are those of `messenger:stats` (the failure transport included; receivers tagged through
  `_instanceof` too). An unknown name in `transports` fails the container compilation.
- Transports that cannot count their messages (`in-memory://`, `sync://`) are skipped. Listing one whose class is
  known at compile time (a receiver service of your own) fails the compilation; a framework transport (built by a
  factory) that turns out not to count is logged once per process on the `metrics_bundle` channel. Without any
  countable transport nothing is registered and the container compiler log says so.
- A transport that fails to count (broker down) is logged on the `metrics_bundle` channel and skipped: its depth and
  `sampled_at` keep their last values. Each sample calls the broker once per transport.
- If the `metrics.cache` pool itself fails (Redis down), the process logs one warning and does not try again for a
  full `period`.

## Guzzle metrics

With `guzzlehttp/guzzle` 7.8+ or 8 installed, every container service implementing `GuzzleHttp\ClientInterface` writes
the same series as `symfony/http-client` (no configuration): `http_connection_request`, `http_connection_response` and
`http_connection_duration_histogram_seconds`, with the same `method`, `host` and `path` labels — URL assemblers
(`metrics.http_client.url_assembler`) and `msstc4symfony_metrics.http_client.sanitize_path` apply to both. Dashboards
see one outbound HTTP family whatever the client.

- The middleware sits in the client's `HandlerStack` right outside Guzzle's `allow_redirects` (inside `http_errors`):
  one call is one record with the final status after redirects, labelled with the requested URL, as the curl transport
  of `symfony/http-client` does. `4xx`/`5xx` are recorded with their status before `http_errors` turns them into
  exceptions.
- The request counter is written when the request starts; the response counter and the duration (from that moment to
  the settlement of the promise, so asynchronous requests and pools are measured too) when it completes. A rejection
  carrying a response (`RequestException`/`ResponseException` with `getResponse()`) is recorded with that status; a
  connection failure only counts the request. With the `stream` option the duration ends with the response headers.
  URL assemblers get the request URL without its user info (credentials).
- Retries: a retry middleware the application `push()`es runs inside the metrics middleware, so retries are one record;
  one it `unshift()`s runs outside and every attempt is recorded.
- Reached clients: a `GuzzleHttp\Client` service with a literal config array gets the middleware in its `handler`
  (Guzzle's default stack when none is set; a `handler` whose class is known and is not a `HandlerStack` is left alone
  and reported in the container compiler log; one whose class is unknown at compile time goes the configurator way). Clients built by a factory, by their own constructor, with a parameter as config, or with
  `max_host_connections`/`max_total_connections`/`transport_sharing`/`multiplex` (Guzzle 8 builds its default handler
  from those) get it through a service configurator via `getConfig('handler')`, chained after their own configurator.
  A Guzzle 8 client without `getConfig()` cannot be reached: the compiler log names it — configure it as a
  `GuzzleHttp\Client` with a literal config array or a `HandlerStack` handler. Clients created with `new`
  outside the container are not measured.
- Elastica 8/9 configured with a Guzzle-based PSR-18 client, or anything else sending through a measured Guzzle
  service, is counted in both families — as with `psr18.http_client` today.

## Cache pool metrics

Opt-in: list the pools under `msstc4symfony_metrics.cache.pools` (service ids, such as `cache.app` or the names under
`framework.cache.pools`):

```yaml
msstc4symfony_metrics:
    cache:
        pools: [cache.app, app.cache.products]
```

| Metric | Type | Labels | Recorded on |
| ------ | ---- | ------ | ----------- |
| `symfony_cache_request` | counter | `pool`, `result` (`hit`, `miss`) | Every item lookup of a listed pool: `getItem()`, each item of `getItems()`, and `get()` with a callback (`miss` when the pool computes the value now — also for a synchronous probabilistic early recomputation — `hit` otherwise, including a stale value served while `early_expiration_message_bus` recomputes it asynchronously). A failing backend counts nothing; a failing computation counts a miss. `hasItem()`, writes and deletes are not counted. |

Lookups are counted in memory and written with one increment per pool and result at the end of the unit of work
(`kernel.terminate`, `console.terminate`, a handled or failed Messenger message, `kernel.reset`), so a hot pool costs
one storage write per series and request instead of one per lookup. Long commands and workers also write every 1000
lookups, and the pending counts are written when the container is destroyed.

- `get()` callbacks reach the pool unchanged: pools that compute through a callback wrapper (filesystem, Redis,
  PDO, APCu, chain and tag-aware adapters) count the computation in a wrapper the bundle adds to that pool, inside
  Symfony's early-expiration dispatcher, so `early_expiration_message_bus` keeps sending recomputations to the bus.
  Other pools (`cache.adapter.array`, proxies) count by wrapping the callback, as they have no early expiration.
- Hit or miss of a `get()` is whether the pool's computation count moved while the call ran; the count is per pool.
  Two `get()` calls of the same pool interleaved in Fibers can therefore turn a hit into a miss.
- Like Symfony's `TraceableAdapter`, the decorator exposes `get()` and `withSubNamespace()` for every pool and throws
  `BadMethodCallException` when the decorated pool does not implement `CacheInterface` or `NamespacedPoolInterface`.

- The decorator keeps the pool's interfaces (`CacheInterface`, `NamespacedPoolInterface`, `PruneableInterface`,
  `ResettableInterface`, and the tag-aware ones for tag-aware pools); `cache:pool:clear`/`prune` and the profiler keep
  the pool name. A sub-namespace (`withSubNamespace()`) is counted under the same pool.
- `tags: true` pools: the public `TagAwareAdapter` service is decorated, so the tag version lookups it makes in its
  inner pool are not counted. Symfony autowires `CacheInterface $pool` of such a pool to that inner, untagged pool:
  those lookups are not counted — inject `TagAwareCacheInterface $pool`. For other pools, `TagAwareCacheInterface $pool`
  (Symfony's `.<pool>.taggable` adapter) is counted, without its tag version lookups; an item invalidated by a tag is
  still counted as a hit there.
- A listed id that is not a service, or not a `Symfony\Component\Cache\Adapter\AdapterInterface`, fails the container
  compilation. `metrics.cache` (the bundle's own throttle pool) and `cache.messenger.restart_workers_signal` (read on
  every worker loop) are rejected, also through an alias, and so is the pool they are an alias of (with
  `metrics.cache` aliased to `cache.app`, `cache.app` cannot be listed).
- Without a `metrics.cache` pool (see [doc/cache.yaml](doc/cache.yaml)) the bundle's throttle falls back to `cache.app`:
  listing `cache.app` then also counts those throttle lookups (one per request or worker loop).

## Elastica metrics

Every container service whose class is `Elastica\Client` or a subclass (FOSElasticaBundle's
`FOS\ElasticaBundle\Elastica\Client` included) is measured, with no configuration:
`elastica_request_success`, `elastica_request_failed` and
`elastica_request_duration_histogram_seconds`, labelled with the HTTP `method` and the request
`path` without the leading `/` (`GET`, `index/_search`). Metric names, labels and buckets are the
same on Elastica 7, 8 and 9, so dashboards do not change when you upgrade.

The `path` label is normalised so that its cardinality stays bounded
(`msstc4symfony_metrics.elastica.sanitize_path`, default `true`, same on 7, 8 and 9):

- In a document endpoint `<index>/<endpoint>/<id>` with `<endpoint>` one of `_doc`, `_create`,
  `_update`, `_source`, `_explain`, `_termvectors`, the id segment becomes `:id`, whatever it
  looks like (`products/_doc/sku%2F1` → `products/_doc/:id`).
- Then the `http_client.sanitize_path` rules apply to every segment: a UUID becomes `:uuid`, digits
  become `:id`, 24+ hex characters become `:hash` (`_tasks/1234` → `_tasks/:id`).
- Index names and endpoints such as `_search`, `_bulk`, `_cluster/health` are kept. An index whose
  whole name is digits, a UUID or long hex would be replaced too.

With `sanitize_path: false` the label is the raw request path, as before 1.2.0.

| | Elastica 7 | Elastica 8 and 9 |
| --- | --- | --- |
| Measurement point | the connection transport, wrapped when the kernel boots | the PSR-18 HTTP client, wrapped through `transport_config.http_client` at container compile time |
| Failed request | the transport throws: connection error, a body with a top-level `error` key, or a partial shard failure (`_shards.failed` > 0) | the HTTP client throws, or the status is ≥ 400 **and** the body is not a JSON object or has a top-level `error` key; a `404` with `"found": false` and an empty `HEAD 404` count as successful, as on 7 |
| Duration | the HTTP call as Elastica's HTTP transport times it | the HTTP call, timed by the decorator |

FOSElasticaBundle clients (child definitions of `fos_elastica.client_prototype`) are found through
their parent definition on both versions.

Differences and limits on Elastica 8 and 9 ("8" below means both):

- The client's configuration (first constructor argument or the named `$config` argument, on the
  service or inherited from its parent definition) must be a literal array. A client configured with a
  DSN string, a parameter or a reference is not measured; the container compiler log
  (`var/cache/<env>/*Compiler.log`, debug mode) says `Elastica client "<id>" is not measured: …`.
- `transport_config.http_client_config` / `http_client_options` keep working: the bundle applies them
  to the HTTP client exactly as Elastica does (same adapters, same error for a client without one)
  and then wraps the result.
- Without `transport_config.http_client` the HTTP client is discovered (`php-http/discovery`), as
  elastic-transport does. If no PSR-18 client is installed, Elastica 9 falls back to elastic-transport's
  own `Elastic\Transport\Client\Curl` and the bundle does the same, so that client is measured too; on
  Elastica 8 there is no fallback and the discovery error is thrown, with or without the bundle.
- A `transport_config.http_client` you configure yourself is kept and wrapped. If it is Symfony's
  `psr18.http_client`, the same requests are also counted in the `http_client_*` metrics (a separate
  metric family).
- Every transport retry is a separate HTTP request and is recorded separately (a request retried
  once after a connection error records one failure and one success).
- A host with a base path (`http://es:9200/prefix`) puts the prefix into the `path` label
  (`prefix/index/_search`); Elastica 7 does not.
- A ≥ 400 response with a non-JSON body (proxy HTML, plain text) is a failure on 8; Elastica 7 wraps
  such a body as `{"message": …}` and counts it as a success. This difference is deliberate.
- A partial shard failure (`200` with `_shards.failed` > 0) is a failure on 7
  (`PartialShardFailureException`) and a success on 8.
- The wrapper hides the inner HTTP client's class from Elastica and elastic-transport: with `cloud_id`
  the gzip `Accept-Encoding` decision falls back to async-client discovery, and the
  `x-elastic-client-meta` library hint is lost. The async (`HttpAsyncClient`) capability of the inner
  client is not exposed.

## Endpoints and commands

| Method             | What                                                                |
| ------------------ | ------------------------------------------------------------------- |
| `GET /_/metrics`   | Returns all collected metrics in Prometheus text format. Responds with `Cache-Control: no-store, max-age=0` so every scrape sees fresh values. While the storage is unreachable it answers `503` (logged on the `metrics_bundle` channel), so Prometheus marks the target `up == 0` instead of seeing an empty scrape. |
| `bin/console metrics:list`  | Tabular list of every registered metric (name, type, labels, histogram buckets). |
| `bin/console metrics:clear` | Wipes the storage adapter.                                   |

## Adding a custom metric

1. Implement a string-backed enum on `Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface` describing your metrics:

    ```php
    use Msstc4Symfony\MetricsBundle\Application\Metric\Label;
    use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
    use Msstc4Symfony\MetricsBundle\Application\Metric\LabelType;
    use Msstc4Symfony\MetricsBundle\Application\Metric\MetricType;
    use Override;

    enum AppMetrics: string implements MetricDefinitionInterface
    {
        case ORDER_CREATED = 'order_created_total';

        #[Override]
        public function getType(): MetricType
        {
            return MetricType::COUNTER;
        }

        #[Override]
        public function getDescription(): string
        {
            return 'Successfully created orders';
        }

        #[Override]
        public function getLabels(): array
        {
            return [new Label('channel', LabelType::STRING)];
        }

        #[Override]
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

3. Record values through the bundle's recorder port: inject
   `Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface` and pass the enum case with the values of
   its own labels, in order — the bundle adds the `application`/`component` labels and logs storage failures on the
   `metrics_bundle` channel instead of throwing:

    ```php
    final readonly class CheckoutMetrics
    {
        public function __construct(private MetricRecorderInterface $recorder)
        {
        }

        public function orderCreated(string $channel): void
        {
            $this->recorder->increment(AppMetrics::ORDER_CREATED, [$channel]);
        }
    }
    ```

   `increment()` takes counters, `set()` gauges and `observe()` histograms or summaries; any other type throws
   `InvalidArgumentException`.

## Public API

Applications and bridges use or implement only these; every other class is `@internal` and may change in a minor
release.

| Namespace `Msstc4Symfony\MetricsBundle\` | Classes |
|---|---|
| `Application\Metric` | `MetricDefinitionInterface`, `BuiltInMetric`, `MetricType`, `LabelType`, `Metric`, `Label`, `MessengerMessageStatus`, `MetricRepositoryInterface`, `MetricRepository` |
| `Application\Collector` | `DoctrineConnectionCollector`, `DoctrineQueryType` (wiring DBAL metrics without DoctrineBundle) |
| `Infrastructure\Doctrine\DBAL\Metrics` | `NamedConnectionMiddleware` |
| `Application\Port` | `MetricRecorderInterface`, `MetricBatchRecorderInterface` (`incrementBy()` for counts aggregated in memory; an application replacing the recorder aliases both), `MetricStorageInterface`, `MetricExport`, `MetricStorageException` |
| `Application` | `LogChannel` (`metrics_bundle`) |
| `Infrastructure\HttpClient\URLAssembler` | `AssemblerInterface` |
| (root) | `MetricsBundle` |

Minor releases add cases to `BuiltInMetric` (new metrics): a `match` over its cases needs a `default` arm.

The configuration keys under `msstc4symfony_metrics` (including `metric_enums`), the container parameters
`msstc4symfony_metrics.metric_enums`, `.application_name`, `.component_name`, the route `/_/metrics` and the commands
`metrics:list` / `metrics:clear` are public too. Packages such as `msstc4symfony/metrics-bridge-profiling` append their
enum to `msstc4symfony_metrics.metric_enums` in a
compiler pass. Every metric name must be declared once: if two enums declare the same name, building
the metric repository throws a `LogicException` naming both cases.

## How it works

Collectors measure at well-known integration points (Symfony kernel events, console events, Monolog handler decorator, Doctrine DBAL middleware, MongoDB driver subscriber, Elastica transport adapter on 7 / PSR-18 client decorator on 8 and 9, `symfony/http-client` decorator, Guzzle handler-stack middleware, cache pool decorators, Messenger receivers). They record through `MetricRecorderInterface`, whose Prometheus adapter writes to a `RegistryInterface` backed by the configured storage adapter (Redis by default). Prometheus periodically scrapes the `/_/metrics` controller, which exports the storage through `MetricStorageInterface` as text/plain.

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
