# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository purpose

Symfony bundle that auto-collects application runtime metrics (HTTP, console, exceptions, Doctrine DBAL, MongoDB, Elastica, `symfony/http-client`) and exposes them in Prometheus format at `GET /_/metrics`. Metrics live in Redis (via `promphp/prometheus_client_php`) and Prometheus scrapes the endpoint.

## Common commands

Develop against the CI profile — PHPStan, deptrac and the integration suite need it:

- `make install-ci` — `COMPOSER=composer-ci.json composer install` (optional libraries + CI-only tools).
- `make check` — `php -l`, PHPStan (level 9, `--memory-limit=512M`), PHP-CS-Fixer check, `composer validate --strict`, `composer audit`, Rector dry-run, deptrac. Run it with `COMPOSER=composer-ci.json` so `composer validate` checks the manifest that is actually installed.
- `make fix` — PHP-CS-Fixer fix + Rector apply.
- `make test` — both suites; `make test-unit` / `make test-integration` run one.
- `make infection` — mutation testing (not part of `make check`).
- `make regenerate-baseline` — regenerates `phpstan-baseline.neon`.
- Single test: `vendor/bin/phpunit tests/unit/Path/To/SomeTest.php` or `--filter testMethodName`.

PHPUnit is strict: `failOnWarning`, `failOnRisky`, `failOnPhpunitDeprecation`, `beStrictAboutOutputDuringTests`. A mock without expectations raises a PHPUnit notice — use `createStub()`.

## Static analysis & style

- **PHPStan**: level 9, `phpVersion: 80400`, analyses `src/`, `tests/unit/`, `tests/integration/` against the CI profile. The only `excludePaths` entry is `Framework/Profiling/.../MetricProcessor.php` — it imports profiling-bundle classes that do not exist yet (fixed in phase A5). The baseline holds 9 pre-existing entries; new code must not add to it.
- **PHP-CS-Fixer**: config is byte-identical to `bundle-standard/templates/.php-cs-fixer.dist.php` (the verifier enforces it).
- **Rector**: PHP 8.4 set + Doctrine/Symfony/PHPUnit/MongoDB attribute sets. Skipped rules are listed in `rector.php` — don't reintroduce them.
- **deptrac**: `deptrac.yaml` records the phase-A status quo of the layers (see `.claude/docs/architecture.md`).
- No Psalm — the standard uses PHPStan only.

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
  - `AddHttpClientMonitorPass` (priority `-256`, runs late) — decorates only `http_client.transport` (all framework clients end there with absolute URLs, so each request is counted once). `HttpClientDecorator` wraps responses in a transparent `MonitoredResponse` (TraceableHttpClient-style) and records metrics when the caller reads the status/body or streams; never read the status in `request()` and do not switch to `AsyncResponse` (it rewrites TimeoutException). URL assemblers are collected by the `AssemblerInterface::TAG` tag.
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

One `phpunit.xml.dist`, two suites:

- **`tests/unit/`** — namespace `Msstc4Symfony\MetricsBundle\Test\Unit\`; runs without optional libraries.
- **`tests/integration/`** — namespace `Msstc4Symfony\MetricsBundle\Test\Integration\`; each test `markTestSkipped()`s in `setUp()` when its optional dependency is missing.

`composer-ci.json` adds `symfony/http-client`, `doctrine/dbal`, `mongodb/mongodb` ^2, `ruflin/elastica` ^7 (8 is unsupported), deptrac, infection and the Roave BC check. It pins `config.platform.ext-mongodb` to the CI runner's extension so `composer-ci.lock` resolves there. APCu tests need `apc.enable_cli=1`.

## CI

`.github/workflows/checks.yml` = the shared `bundle-standard` reusable workflow (pinned tag) with extensions `redis, apcu, mongodb` and `ini-values: apc.enable_cli=1`, plus a local `minimal` job that installs `composer.json` only and runs PHPUnit — it proves the `class_exists`/`interface_exists` guards hold without optional libraries. Codecov is disabled (`run-codecov` defaults to `false`). Details: `.claude/docs/ci.md`.

## Deep references

- `.claude/docs/architecture.md` — layers, wiring points, deptrac rules.
- `.claude/docs/conventions.md` — naming, guards for optional libraries, typing rules.
- `.claude/docs/testing.md` — suites, skip guards, stubs vs mocks.
- `.claude/docs/tooling.md` — two manifests, `make check`, baseline policy.
- `.claude/docs/ci.md` — reusable workflow inputs, the `minimal` job.
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
- `/acc:audit-test` + `/acc:generate-test` — для исключённых из coverage путей (`Framework/*`, `Infrastructure/{Doctrine,Elastica,HttpClient,Monolog}`), которые сейчас вообще не покрыты юнит-тестами.
- `/acc:audit-patterns` — при изменении декораторов/middlewares/transport'ов (Decorator/Middleware/Strategy здесь — основная механика).
- `/acc:audit-performance` — для `Storage\Factory` и коллекторов (Redis IO, циклы по labels).
- `/acc:audit-security` — после правок DSN-парсинга, env-обработки или контроллера `/_/metrics`.
- `/acc:audit-documentation` — README + `doc/`.

**Не подходит и не нужно:**
- Все `/acc:*docker*`, `/acc:generate-ddd`, CQRS/Saga/EventSourcing скилы. Это инфраструктурная библиотека, не доменное приложение.
