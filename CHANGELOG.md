# Changelog

## 1.4.1

### Fixed

- `doctrine_query_*` `table` label: a parenthesised `UNION` as built by DBAL's `QueryBuilder` on
  PostgreSQL and MySQL (`(SELECT ...) UNION (SELECT ...)`) was labelled `unknown` since 1.4.0; it is
  labelled with the table of its first part again. A common table expression is labelled with the table
  its body reads (1.4.0 gave the CTE name). `FROM ONLY`, `UPDATE ONLY`, `DELETE FROM ONLY` and
  `FROM LATERAL` no longer give `ONLY`/`LATERAL`; `"a""b"` and catalog-qualified names (`db.public.users`)
  are read correctly. Label values of such queries change.
- `doctrine_query_duration_*` no longer includes the time spent deriving the labels from the SQL.

### Changed

- The labels of a statement are cached (up to 256 statements per connection), so repeated statements
  are not parsed again.

## 1.4.0

### Fixed

- The `table` label of the `doctrine_query_*` metrics took the first `FROM` anywhere in the statement,
  including function arguments (`EXTRACT(EPOCH FROM created_at)` gave `created_at`), subqueries in the
  select list or in conditions, SQL comments and string literals. DBAL's PostgreSQL schema introspection
  was labelled `pg_attrdef` or even `partitioned` (from the comment "inherit from partitioned tables").
  Only the statement's own top-level `FROM` is used now; a derived table is labelled with its inner
  table, and quoted identifiers (`"public"."users"`, `` `users` ``, `[users]`) are recognised instead
  of giving `unknown`. Label values of such queries change.
- OPcache gauges are skipped instead of failing when `opcache_get_status()` reports non-numeric values
  or an empty memory pool.

### Changed

- `promphp/prometheus_client_php` is required at `^2.13` (was `^2.6`). Below 2.7 `redisng://` fell
  back to `InMemory` (no `RedisNg` class); below 2.13 the user name of a `redis://user:pass@...` DSN
  was not sent to `AUTH`, so Redis ACL users could not authenticate.
- `symfony/deprecation-contracts` (`^2.5|^3`, used for `trigger_deprecation()`) is declared instead of
  being relied on transitively.
- Conflicts with `symfony/error-handler` < 6.4.10, whose error handler references `E_STRICT` and emits
  a deprecation on every boot under PHP 8.4.
- Development: PHPUnit `>=11.4` (the `phpunit.xml.dist` template uses `ignoreIndirectDeprecations`
  and `failOnPhpunitDeprecation`), PHPStan level 10, `bundle-standard` v1.8.0 (blocking BC check,
  Infection gate at 69 % MSI, a `--prefer-lowest` PHPUnit cell).

## 1.3.3

Upgrade from 1.3.0 straight to 1.3.3: 1.3.1 can turn a phpredis connect warning into an HTTP 500
and 1.3.2 slows every request down by seconds while Redis is unreachable. Skip both.

### Fixed

- While Redis was unreachable, 1.3.2 tried to reconnect on every metric operation. A request makes
  dozens of them and each attempt costs a DNS lookup or a connect/read timeout, so with the Redis
  container stopped a RoadRunner request took seconds (6.3 s instead of ~30 ms, 13.8 s with an
  idempotency key). The Redis adapter now has a circuit breaker: after a connection failure it
  makes no reconnect attempt for `metrics.storage.reconnect_backoff_seconds` (default 5 s, per
  process). Inside that window writes are dropped at once (still counted in the throttled log) and
  reads throw `StorageException` without a network call, so `/_/metrics` answers 503 immediately.
  After the window exactly one attempt is made: success closes the breaker, failure opens it again.

### Behaviour change to be aware of (since 1.3.2)

- Up to 1.3.1 a scrape of `/_/metrics` during a Redis outage ended in an uncaught exception. Under
  RoadRunner with `kernel_reboot.strategy: on_exception` that exception rebooted the kernel and,
  as a side effect, rebuilt the application's own Redis clients (`cache.app`, lock store). Since
  1.3.2 the endpoint answers 503 without an exception, so that accidental reboot no longer happens.
  phpredis keeps a client whose command hit the outage failed (`Redis server ... went away`) until
  it is reconnected, and Symfony's Redis cache/lock connections do not reconnect by themselves, so
  such applications now stay broken after Redis comes back. This is not caused by the bundle (the
  same happens with 1.3.0 when nothing scrapes `/_/metrics`); see README, "Redis restarts in
  long-running workers", for application-side fixes.

### Added

- Bundle option `metrics.storage.reconnect_backoff_seconds` (float, `>= 0`, default `5`; `0`
  restores the 1.3.2 behaviour of reconnecting on every operation). `Storage\Factory` takes it as
  an optional second constructor argument.
  Env placeholders (`%env(float:...)%`) are accepted.
- A Redis that accepts connections but never answers no longer holds a reconnect for
  `default_socket_timeout` (60 s by default): promphp sends `AUTH`/`SELECT` before it applies
  `read_timeout`, so the adapter lowers `default_socket_timeout` to the DSN `read_timeout` (rounded
  up to whole seconds, default 1 s; never raised, untouched for `read_timeout <= 0`) while a fresh
  connection handshakes, and restores it afterwards. A negative backoff coming from an env
  placeholder (which bypasses the configuration's `min(0)`) is logged and replaced by the default.

## 1.3.2

### Fixed

- Regression from 1.3.1: while Redis was unreachable, the reconnect attempt made phpredis raise a PHP
  warning (`Redis::connect(): php_network_getaddresses: getaddrinfo for redis failed`). With
  Symfony's `framework.php_errors.throw` the application error handler turned it into an
  `ErrorException`, and requests could end in a 500. `ReconnectingRedisAdapter` now handles PHP
  warnings and notices raised during storage calls itself (they are attached to the logged
  failure instead of reaching the application handler; deprecations are still passed on to it).
  A failed write never throws any more: the sample is dropped and logged on the `metrics_bundle`
  channel — the first failure of an outage at `error`, then one `warning` per minute with the
  number of dropped samples, and `info` once writes succeed again (the rate limit is per process:
  it pays off in long-running workers; under PHP-FPM each request logs its own first failure). A failed read (`collect()`,
  `wipeStorage()`) throws a `StorageException`.
- `GET /_/metrics` answered 500 while the storage was unreachable; it now answers
  `503 Service Unavailable` (`text/plain`, `no-store`) and logs the cause, so Prometheus records
  `up == 0` for the scrape.
- A collector whose logger throws while reporting a storage failure no longer lets that exception
  escape.
- `metrics:clear` prints the storage error and exits with `1` instead of dumping the exception.

### Changed

- For the `redis://` and `redisng://` storages, code that reads the registry directly
  (`getMetricFamilySamples()`) now gets `Prometheus\Exception\StorageException` (the original
  `RedisException` is its `previous`) instead of a raw `RedisException`. Collectors no longer log
  `Cannot save metric ...` for Redis outages; the adapter's rate-limited messages replace them.
- `GetMetricsController` takes an optional `LoggerInterface` (third constructor argument).

## 1.3.1

### Fixed

- `redis://` and `redisng://` storage never recovered after Redis restarted under a long-running
  process (RoadRunner, `messenger:consume`): phpredis marks a client that lost its connection as
  failed and answers every later command with `Redis server ... went away`, while the promphp
  adapter connects only once. The Redis adapters are now wrapped in the internal
  `ReconnectingRedisAdapter`: a connection failure drops the adapter, and the next metric write or
  read builds a fresh one, which connects, authenticates, selects the database and applies the
  read timeout again. The failing operation itself is not retried (a retried `EVAL` could count
  twice); collectors keep logging it as before, so metrics never break the request or message.
  While Redis is down every operation makes one connection attempt (`timeout`, default 0.1 s).
- The database number in the DSN path (`redis://redis:6379/4`, as documented in the README) was
  ignored: `parse_url()` returns `/4` and the numeric check failed, so metrics went to database 0.
  It is now applied (`?database=` still wins). Applications that relied on the path form write
  to the configured database from this release on.

### Changed

- `Storage\Factory::create()` returns `ReconnectingRedisAdapter` instead of
  `Prometheus\Storage\Redis` / `RedisNg` for the Redis schemes. The service is still typed
  `Prometheus\Storage\Adapter`; code that checked for the concrete promphp class must not.
- README: an unreachable Redis never triggered the `InMemory` fallback (the connection is lazy),
  and `ssl_verify_peer` is not passed to phpredis by promphp; the storage section now says so and
  describes the per-operation connection attempt while Redis is down.

## 1.3.0

### Added

- Symfony Messenger metrics, collected when `symfony/messenger` is installed:
  `messenger_message_sent` (`transport`, `message`), `messenger_message_handled` and
  `messenger_message_handling_duration_histogram_seconds` (`transport`, `message`,
  `status` = `handled` | `retried` | `failed`). `message` is the short class name. They are
  declared by the new `MessengerMetricLabelEnum` (appended to `metrics_bundle.metric_enums`);
  `MetricLabelEnum` gains no cases, so exhaustive `match` over it keeps compiling.
- Bundle configuration (`metrics:` root). `metrics.doctrine.connection_label: name` labels
  Doctrine DBAL query metrics with the DoctrineBundle connection name instead of
  `host:dbname`. The default `host_dbname` keeps the 1.x label values. With `name`, an
  application-defined DBAL `Middleware` service fails the compilation instead of being ignored.

### Changed

- README: on Symfony 7.4+ applications importing `routing.controllers` the `/_/metrics` route
  loads automatically (as before — it is now documented and tested); the manual
  `@MetricsBundle/Presentation/Controller/` import is only needed on Symfony 6.4–7.3 and can
  stay next to `routing.controllers` without registering the route twice.

## 1.2.2

### Fixed

- 1.2.1 registered the DBAL `Middleware` service only with DoctrineBundle. Without it the
  service is registered again (untagged, autowired) whenever doctrine/dbal is installed, so
  applications wiring it into hand-made connections keep working.
- The table label of a query with a subquery or several `FROM` clauses was taken from the last
  `FROM`; it is now the first (outer) table. Long statements (big `IN` lists) no longer hit the
  PCRE backtrack limit and fall back to `unknown`: only the first 16 KiB of SQL are parsed.

### Changed

- Internal: a prepared statement reuses its connection's `QueryMeter`
  (`Statement::__construct()` is `@internal`).

## 1.2.1

### Fixed

- Doctrine DBAL query metrics were never recorded with DoctrineBundle: the compiler pass
  looked for connection definitions with a `Doctrine\DBAL\Connection` class (DoctrineBundle
  defines them as class-less child definitions) and registered the middleware without the
  `doctrine.middleware` tag. The middleware is now tagged whenever DoctrineBundle configured
  connections, and the pass runs before DoctrineBundle's `MiddlewaresPass` whatever the bundle
  order.
- Queries without bound parameters (`Connection::query()` / `exec()`, which bypass
  `prepare()`) are now counted too.

## 1.2.0

### Deprecated

- The profiling integration moves to `msstc4symfony/metrics-bridge-profiling`:
  `MetricProcessor`, `ProfilingCollector` and
  `MetricLabelEnum::PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS` are deprecated and removed in
  2.0. Without the bridge they keep working; with it the bridge replaces them and records the
  same `profiling_span_duration_histogram_seconds` metric.

### Fixed

- Span durations were measured when the processor ran instead of when the span ended; they
  now use `SpanInterface::getDuration()` (profiling-bundle 1.0; older versions conflict).
  The processor is now analysed and tested against profiling-bundle in CI.
- A metric name declared by several `metrics_bundle.metric_enums` entries was listed once per
  declaration; the first declaration wins.

## 1.1.0

First release under `msstc4symfony/metrics-bundle` / `Msstc4Symfony\MetricsBundle`
(previously `MaxShamaev\MetricsBundle`).

### Fixed

- Outbound HTTP metrics were never recorded: the compiler pass checked
  `class_exists()` on an interface. Monitoring now wraps the shared
  `http_client.transport` only — each request is counted once, scoped clients get
  their real host, and metrics are recorded when the response is read or streamed,
  so requests stay concurrent, exceptions keep their class and `reset()` reaches
  the transport.
- The container failed to compile in applications with MonologBundle (circular
  reference through the storage logger). The storage factory now logs to its own
  `metrics_bundle` channel.
- `apc://`, `apcng://` and `inmemory://` DSNs silently fell back to per-process
  in-memory storage.
- Doctrine queries without a trailing clause (`SELECT * FROM users`) were labelled
  with table `unknown`; schema-qualified names (`public.users`) are labelled `users`.
- `symfony/yaml` and `monolog/monolog ^3.5` were used but not required.
- `symfony/monolog-bundle` 4 (Symfony 8) is now allowed.

### Changed

- The URL assembler tag is now `metrics.http_client.url_assembler`
  (`AssemblerInterface::TAG`); it was `metrics.htp_client.url_assembler`. Services
  implementing `AssemblerInterface` are tagged automatically; only manual tags
  need updating.
- The `/_/metrics` route must be imported by the application
  (`@MetricsBundle/Presentation/Controller/`, type `attribute`).
- Elastica metrics require Elastica 7; Elastica 8 is not supported yet.
- PHP >= 8.4.
