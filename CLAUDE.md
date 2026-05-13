# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository purpose

Symfony bundle that auto-collects application runtime metrics (HTTP, console, exceptions, Doctrine DBAL, MongoDB, Elastica, `symfony/http-client`) and exposes them in Prometheus format at `GET /_/metrics`. Metrics live in Redis (via `promphp/prometheus_client_php`) and Prometheus scrapes the endpoint.

## Common commands

- `make check` — runs `php -l` on every PHP file, PHPStan (`--memory-limit=512M`), PHP-CS-Fixer in check mode, `composer audit`, and Rector dry-run. This is what CI runs.
- `make fix` — PHP-CS-Fixer fix + Rector apply.
- `make test` — PHPUnit.
- `make test-with-coverage` — PHPUnit with HTML coverage in `./coverage` (sets `XDEBUG_MODE=coverage`).
- `make regenerate-baseline` — regenerates `phpstan-baseline.neon`.
- Run a single test: `vendor/bin/phpunit tests/unit/Path/To/SomeTest.php` or `vendor/bin/phpunit --filter testMethodName`.

PHPUnit is strict: `failOnWarning`, `failOnRisky`, `failOnPhpunitDeprecation`, `beStrictAboutOutputDuringTests` are all on — any new test that emits output, warnings, or deprecations will fail the suite.

## Static analysis & style

- **PHPStan**: level 9, `phpVersion: 80300`, baseline in `phpstan-baseline.neon`. Several integration files are excluded from analysis entirely (third-party-shaped wrappers — Doctrine DBAL `Connection`/`Driver`/`Middleware`/`Statement`, Elastica `TimingTransport`, `MetricLabelEnum*`, `HttpClientDecorator`). Prefer fixing new errors over adding to the baseline.
- **Psalm**: `errorLevel="5"` with `psalm-baseline.xml`. Note Psalm is configured but **not run by `make check`** — CI only runs the Makefile.
- **PHP-CS-Fixer**: `@Symfony` preset plus project tweaks (see `.php-cs-fixer.dist.php` — `phpdoc_align: left`, `concat_space: one`, post-increment, no Yoda, `global_namespace_import` on). Risky rules allowed.
- **Rector**: PHP 8.1 set + Doctrine/Symfony/PHPUnit/MongoDB attribute sets + most prepared sets. Several rules are explicitly skipped (notably `ClassPropertyAssignToConstructorPromotionRector`, `PostIncDecToPreIncDecRector`, `ActionSuffixRemoverRector`) — don't reintroduce them.
- `composer.json` requires PHP `>=8.1` for runtime compatibility; do not raise this without an explicit version bump.

## Architecture

The bundle is a **classic Symfony bundle** organised in a loose layered shape under `src/`:

- **`Presentation/`** — `GetMetricsController` (the `GET /_/metrics` endpoint) and console commands `metrics:list` / `metrics:clear`.
- **`Framework/EventListener/`** — Symfony kernel/console event listeners (`#[AsEventListener]`) that drive measurement timing for HTTP requests, console commands, exceptions, and one-shot info gauges.
- **`Framework/Profiling/`** — integration with profiling spans → metrics.
- **`Infrastructure/Collector/`** — the heart of the bundle. Each collector wraps the Prometheus `RegistryInterface` and exposes domain-specific `inc*`/`set*` methods called from listeners or decorators. All inherit `AbstractCollector`, which automatically prepends two labels (`application`, `component`) to every sample.
- **`Infrastructure/Doctrine/`**, **`Elastica/`**, **`HttpClient/`**, **`Monolog/`** — integration adapters (middlewares, transports, decorators) that hook into third-party systems and call into the matching collector.
- **`Infrastructure/Storage/Factory`** — picks a Prometheus storage adapter from a DSN scheme: `redis`, `redisng`, `apc`, `apcng`, `inmemory`. Any failure falls back to `InMemory` silently — be aware when debugging "missing metrics".
- **`Infrastructure/Enum/MetricLabelEnum`** — the **central catalog** of all metric names. Each enum case has a type (counter/gauge/histogram/summary), description, label set, and histogram buckets. Implements `MetricLabelEnumInterface` (extends `BackedEnum`). `MetricRepositoryFactory` reads the container parameter `metrics_bundle.metric_enums` (list of class-strings) and merges `::cases()` from each — this is the extension point for downstream apps to register their own metrics.
- **`DependencyInjection/Compiler/`** — four compiler passes wire integrations into the host application:
  - `AddMonologDecoratorCompilerPass` — decorates every `monolog.logger.*` service (except `profiling`, `removal_request`, `deprecation` channels) with `HandlerDecorator` so log levels are counted.
  - `AddDoctrineDBALMonitorPass` — finds `doctrine.dbal.*_connection` services and registers the metrics `Middleware` (autowired).
  - `AddHttpClientMonitorPass` (priority `-256`, runs late) — wraps `symfony/http-client` services.
  - `SaveElasticaClientsListPass` — collects Elastica client service IDs into the `metrics.elastica.clients` parameter; `MetricsBundle::boot()` later wraps each client's connection transport.
- `MetricsBundle::boot()` also registers the MongoDB driver `TimingSubscriber` via `MongoDB\Driver\Monitoring\addSubscriber`. MongoDB and Elastica wiring is **optional** — guarded by `class_exists` and `NULL_ON_INVALID_REFERENCE`, so the bundle works without those libraries installed.

### Adding a new metric

1. Either extend `MetricLabelEnum` (in-bundle) or create a new `string`-backed enum implementing `MetricLabelEnumInterface` in the host app and append its FQCN to the `metrics_bundle.metric_enums` parameter.
2. Add a collector method (or extend an existing collector) that calls one of `AbstractCollector`'s helpers (`incCounter`, etc.) — the application/component labels are added for you, so `getLabels()` on the enum should only return the *additional* labels.
3. Trigger the collector from an event listener, middleware, or decorator depending on where the measurement point lives.

## Configuration

Required env vars (see `doc/.env.dist`):

- `METRICS_STORAGE_DSN` — e.g. `redis://redis:6379?database=4` (defaults to `redis://127.0.0.1:6379`).
- `APPLICATION_NAME`, `COMPONENT_NAME` — populate the `application`/`component` labels (default `unknown`).

Service config lives in `src/Resources/config/services.yaml`. The Yaml file is loaded by `MetricsExtension`; there is no Configuration tree / `prependExtension` — settings are passed via container parameters.

## Tests

Two test suites:

- **`tests/unit/`** — testsuite `unit`. Run by `make test`. PSR-4 namespace `MaxShamaev\MetricsBundle\Test\Unit\`. Coverage source (in `phpunit.xml.dist`) excludes `MetricsBundle.php` and `Infrastructure/HttpClient/HttpClientDecorator.php` (depends on `symfony/http-client`).
- **`tests/integration/`** — testsuite `integration`. Run by `make test-integration` (uses separate `phpunit-integration.xml.dist`). PSR-4 namespace `MaxShamaev\MetricsBundle\Test\Integration\`. Tests use `markTestSkipped()` in `setUp()` if their optional dependency is missing.

Optional dependencies for integration tests live in **`composer-integration.json`** (extends `composer.json` with `symfony/http-client`, `doctrine/dbal`, `mongodb/mongodb`, `ruflin/elastica`). Install via `make install-integration` (this replaces `vendor/` with the integration profile — to switch back run `composer install`).

CI has two jobs (`.github/workflows/checks.yml`):
- **`unit`** — `composer install` + `make check` + unit suite. Verifies the bundle works WITHOUT optional libs (proves `class_exists` guards still hold).
- **`integration`** — `COMPOSER=composer-integration.json composer install` + integration suite + coverage. Runs with full optional-dep stack and `ext-apcu`, `ext-mongodb` PHP extensions.

PHPStan only analyses `src/` + `tests/unit/` (integration tests reference classes that need the optional libs and would explode static analysis under the minimal install).

## CI

GitHub Actions (`.github/workflows/checks.yml`) runs on PHP 8.1, executes `make check` then PHPUnit with coverage, uploads to Codecov. Both Codecov uploads have `fail_ci_if_error: true`, so a missing/expired `CODECOV_TOKEN` will fail CI.

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
- `/acc:audit-test` + `/acc:generate-test` — для исключённых из coverage путей (`Framework/*`, `Infrastructure/{Doctrine,Elastica,HttpClient,Monolog}`), которые сейчас вообще не покрыты юнит-тестами.
- `/acc:audit-patterns` — при изменении декораторов/middlewares/transport'ов (Decorator/Middleware/Strategy здесь — основная механика).
- `/acc:audit-performance` — для `Storage\Factory` и коллекторов (Redis IO, циклы по labels).
- `/acc:audit-security` — после правок DSN-парсинга, env-обработки или контроллера `/_/metrics`.
- `/acc:audit-documentation` — README + `doc/`.

**Не подходит и не нужно:**
- Все `/acc:*docker*`, `/acc:generate-ddd`, CQRS/Saga/EventSourcing скилы. Это инфраструктурная библиотека, не доменное приложение.
