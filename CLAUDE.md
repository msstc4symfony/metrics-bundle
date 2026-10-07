# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository purpose

Symfony bundle that auto-collects application runtime metrics (HTTP, console, exceptions, Doctrine DBAL, MongoDB, Elastica, `symfony/http-client`) and exposes them in Prometheus format at `GET /_/metrics`. Metrics live in Redis (via `promphp/prometheus_client_php`) and Prometheus scrapes the endpoint.

## Common commands

Develop against the CI profile — PHPStan, deptrac and the integration suite need it:

- `make install-ci` — `COMPOSER=composer-ci.json composer install` (optional libraries + CI-only tools).
- `make check` — `php -l`, PHPStan (level 10, `--memory-limit=512M`), PHP-CS-Fixer check, `composer validate --strict`, `composer audit`, Rector dry-run, deptrac. Run it with `COMPOSER=composer-ci.json` so `composer validate` checks the manifest that is actually installed.
- `make fix` — PHP-CS-Fixer fix + Rector apply.
- `make test` — both suites; `make test-unit` / `make test-integration` run one.
- `make infection` — mutation testing (not part of `make check`).
- `make regenerate-baseline` — regenerates `phpstan-baseline.neon`.
- Single test: `vendor/bin/phpunit tests/Unit/Path/To/SomeTest.php` or `--filter testMethodName`.

PHPUnit is strict: `failOnWarning`, `failOnRisky`, `failOnPhpunitDeprecation`, `beStrictAboutOutputDuringTests`. A mock without expectations raises a PHPUnit notice — use `createStub()`.

## Static analysis & style

- **PHPStan**: level 10, `phpVersion: 80400`, analyses `src/` and `tests/` against the CI profile. No `excludePaths`. The baseline holds 8 pre-existing entries; new code must not add to it (no `@phpstan-ignore` either).
- **PHP-CS-Fixer**: config is byte-identical to `bundle-standard/templates/.php-cs-fixer.dist.php` (the verifier enforces it).
- **Rector**: PHP 8.4 set + Doctrine/Symfony/PHPUnit/MongoDB attribute sets. Skipped rules are listed in `rector.php` — don't reintroduce them.
- **deptrac**: `deptrac.yaml` records the phase-A status quo of the layers (see `.claude/docs/architecture.md`).
- No Psalm — the standard uses PHPStan only.

## Architecture

The bundle is a **classic Symfony bundle** organised in a loose layered shape under `src/`:

- **`Presentation/`** — `GetMetricsController` (the `GET /_/metrics` endpoint) and console commands `metrics:list` / `metrics:clear`.
- **`Framework/EventListener/`** — Symfony kernel/console event listeners (`#[AsEventListener]`) that drive measurement timing for HTTP requests, console commands, exceptions, and one-shot info gauges. `MessengerEventListener` (Messenger send/worker events) is excluded from the resource scan and registered in `services.php` only when Messenger is installed.
- **`Infrastructure/Collector/`** — the heart of the bundle. Each collector wraps the Prometheus `RegistryInterface` and exposes domain-specific `inc*`/`set*` methods called from listeners or decorators. All inherit `AbstractCollector`, which automatically prepends two labels (`application`, `component`) to every sample.
- **`Infrastructure/Doctrine/`**, **`Elastica/`**, **`HttpClient/`**, **`Monolog/`** — integration adapters (middlewares, transports, decorators) that hook into third-party systems and call into the matching collector.
- **`Infrastructure/Storage/Factory`** — picks a Prometheus storage adapter from a DSN scheme: `redis`, `redisng`, `apc`, `apcng`, `inmemory`. A construction failure (malformed DSN, unknown scheme, missing class/extension) falls back to `InMemory` and is logged on the `metrics_bundle` channel — check that channel when debugging "missing metrics". An unreachable Redis does not fall back: `ReconnectingRedisAdapter` drops writes (rate-limited log) and reconnects with a backoff.
- **`Infrastructure/Enum/MetricLabelEnum`** — the **central catalog** of all metric names. Each enum case has a type (counter/gauge/histogram/summary), description, label set, and histogram buckets. Implements `MetricLabelEnumInterface` (extends `BackedEnum`). `MetricRepositoryFactory` reads the container parameter `msstc4symfony_metrics.metric_enums` (`MetricLabelEnum` plus the `metric_enums` config option; compiler passes of bridges append to it) and merges `::cases()` from each; a metric name declared twice throws `LogicException`.
- **`DependencyInjection/Compiler/`** — compiler passes wire integrations into the host application:
  - `AddMonologDecoratorCompilerPass` — decorates every `monolog.logger.*` service (except `profiling`, `removal_request`, `deprecation` channels) with `HandlerDecorator` so log levels are counted.
  - `AddDoctrineDBALMonitorPass` — with DoctrineBundle registers one `NamedConnectionMiddleware` per connection (`$connectionName` argument, `doctrine.middleware` tag with the `connection` attribute), so the `connection` label is the connection name. Without DoctrineBundle nothing is registered; applications wire `NamedConnectionMiddleware` by hand.
  - `AddHttpClientMonitorPass` (priority `-256`, runs late) — decorates only `http_client.transport` (all framework clients end there with absolute URLs, so each request is counted once). `HttpClientDecorator` wraps responses in a transparent `MonitoredResponse` (TraceableHttpClient-style) and records metrics when the caller reads the status/body or streams; never read the status in `request()` and do not switch to `AsyncResponse` (it rewrites TimeoutException). URL assemblers are collected by the `AssemblerInterface::TAG` tag.
  - `SaveElasticaClientsListPass` (Elastica 7) — collects the IDs of `Elastica\Client` services (subclasses included, via `ElasticaClientDefinitions`) into the `msstc4symfony_metrics.elastica.clients` parameter; `MetricsBundle::boot()` later wraps each client's connection transport in `TimingTransport`.
  - `DecorateElasticaClientsPass` (Elastica 8 and 9) — writes `transport_config.http_client` = `TimingHttpClient(ConfiguredHttpClientFactory::create(<http_client>, <http_client_config>, <http_client_options>), ElasticaCollector, %msstc4symfony_metrics.elastica.sanitize_path%)` into each client's literal config array (also FOSElasticaBundle `ChildDefinition`s, found through the parent chain); non-literal configs are skipped with a compiler-log line. Without a configured/discoverable PSR-18 client the factory falls back to `Elastic\Transport\Client\Curl` (elastic-transport 9) or rethrows the discovery error (8), mirroring `TransportBuilder::getClient()`.
- `MetricsBundle::boot()` also registers the MongoDB driver `TimingSubscriber` via `MongoDB\Driver\Monitoring\addSubscriber`. MongoDB and Elastica wiring is **optional** — guarded by `class_exists` and `NULL_ON_INVALID_REFERENCE`, so the bundle works without those libraries installed.

### Adding a new metric

1. Bundle metrics are cases of `MetricLabelEnum` (pin each in `MetricCatalogTest`). A host app creates a `string`-backed enum implementing `MetricLabelEnumInterface` and lists it under `msstc4symfony_metrics.metric_enums` in the bundle config. Metric names must be unique across all enums.
2. Add a collector method (or extend an existing collector) that calls one of `AbstractCollector`'s helpers (`incCounter`, etc.) — the application/component labels are added for you, so `getLabels()` on the enum should only return the *additional* labels.
3. Trigger the collector from an event listener, middleware, or decorator depending on where the measurement point lives.

## Configuration

`MetricsBundle` is an `AbstractBundle` with the config root `msstc4symfony_metrics`: `storage.dsn`, `storage.reconnect_backoff_seconds`, `application_name`, `component_name`, `errors.short_exception_class_name`, `http_client.sanitize_path`, `elastica.sanitize_path` (both measurement points normalise the `path` label through `ElasticaPathSanitizer`), `metric_enums`. `loadExtension()` turns every option into a `msstc4symfony_metrics.*` container parameter and imports `src/Resources/config/services.php`. Env vars (see `doc/.env.dist`) feed the defaults:

- `METRICS_STORAGE_DSN` — e.g. `redis://redis:6379?database=4` (defaults to `redis://127.0.0.1:6379`).
- `APPLICATION_NAME`, `COMPONENT_NAME` — populate the `application`/`component` labels (default `unknown`).

## Tests

One `phpunit.xml.dist`, two suites:

- **`tests/Unit/`** — namespace `Msstc4Symfony\MetricsBundle\Test\Unit\`; runs without optional libraries.
- **`tests/Integration/`** — namespace `Msstc4Symfony\MetricsBundle\Test\Integration\`; each test `markTestSkipped()`s in `setUp()` when its optional dependency is missing.
- Read samples from a registry with `tests/Support/RegistrySamples` (`labels()`, `samples()`, `exists()`); don't scan `getMetricFamilySamples()` by hand. `tests/Unit/Infrastructure/Enum/MetricCatalogTest` pins every metric's type, labels and buckets — update it only for an intentional contract change.

`composer-ci.json` adds `symfony/http-client`, `symfony/messenger`, `doctrine/dbal`, `mongodb/mongodb` ^2, `ruflin/elastica` `^7.3|^8.0|^9.0` (the lock stays on 7 — PHPStan cannot load the Elastica 7 `TimingTransport` against 8/9; see known-issues), deptrac, infection and the Roave BC check. It pins `config.platform.ext-mongodb` to the CI runner's extension so `composer-ci.lock` resolves there. APCu tests need `apc.enable_cli=1`.

## CI

`.github/workflows/checks.yml` = the shared `bundle-standard` reusable workflow (pinned tag) with extensions `redis, apcu, mongodb` and `ini-values: apc.enable_cli=1`. The workflow's `PHPUnit without optional libraries` job installs `composer.json` only and proves the `class_exists`/`interface_exists` guards hold without optional libraries. Infection (`infection-min-msi`/`infection-min-covered-msi` = 69, measured 73) is blocking; the Roave BC check is blocking (the standard's default); a `--prefer-lowest` PHPUnit cell (PHP 8.4, Symfony 7.4) tests the declared lower bounds. Codecov is disabled (`run-codecov` defaults to `false`). Details: `.claude/docs/ci.md`.

## Deep references

- `.claude/docs/architecture.md` — layers, wiring points, deptrac rules.
- `.claude/docs/conventions.md` — naming, guards for optional libraries, typing rules.
- `.claude/docs/testing.md` — suites, skip guards, stubs vs mocks.
- `.claude/docs/tooling.md` — two manifests, `make check`, baseline policy.
- `.claude/docs/ci.md` — reusable workflow inputs, the job without optional libraries.
- `.claude/docs/known-issues.md` — gotchas; **read before chasing a "weird" failure.**

## Working with `acc` plugin commands

The `acc@awesome-claude-code` plugin is installed at user scope, so all `/acc:*` skills are available in this repo. Use them when they fit — don't reinvent the analysis manually.

**Daily flow:**
- `/acc:explain <path|route|command>` — understand a collector, compiler pass, or the `GET /_/metrics` route before touching it.
- `/acc:bug-fix` — точечные баги; даёт минимальный фикс с regression-тестом.
- `/acc:code-review` — ревью diff'а ветки против `main` перед PR.
- `/acc:commit` — conventional-commit сообщение + пуш.

**Project-specific agent:**
- `metrics-bundle-reviewer` (в `.claude/agents/`) — знает архитектурные инварианты бандла (см. секцию выше: коллекторы → `AbstractCollector`, реестр через `MetricLabelEnum`, compiler passes, exclude-листы PHPStan). Вызывай через Task tool с `subagent_type: metrics-bundle-reviewer` для глубокого ревью изменений, затрагивающих коллекторы/интеграции.

**Когда какой аудит:**
- `/acc:audit-psr` — после добавления новых классов/неймспейсов (бандл должен оставаться PSR-12 + PSR-4).
- `/acc:audit-test` + `/acc:generate-test` — при слабом покрытии; адаптеры (`Infrastructure/{Doctrine,Elastica,HttpClient,Monolog}`) и слушатели покрыты интеграционными тестами, но Infection считает только unit-suite — самые «живучие» мутанты в `ReconnectingRedisAdapter`, `Storage\Factory`, `InfoEventListener`.
- `/acc:audit-patterns` — при изменении декораторов/middlewares/transport'ов (Decorator/Middleware/Strategy здесь — основная механика).
- `/acc:audit-performance` — для `Storage\Factory` и коллекторов (Redis IO, циклы по labels).
- `/acc:audit-security` — после правок DSN-парсинга, env-обработки или контроллера `/_/metrics`.
- `/acc:audit-documentation` — README + `doc/`.

**Не подходит и не нужно:**
- Все `/acc:*docker*`, `/acc:generate-ddd`, CQRS/Saga/EventSourcing скилы. Это инфраструктурная библиотека, не доменное приложение.
