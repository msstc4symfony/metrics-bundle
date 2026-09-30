# Security Policy

## Supported Versions

The bundle follows semantic versioning. Security fixes are released for the latest
minor on each supported major.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

Runtime requirements are tracked in `composer.json`:

- PHP >= 8.4
- Symfony 6.4 LTS, 7.x, 8.x

If you are running an older PHP or Symfony version, upgrade before reporting.

## Reporting a Vulnerability

Please **do not** open public GitHub issues, pull requests, or discussions for
security problems.

Use one of the following private channels:

1. **GitHub Security Advisory** (preferred):
   <https://github.com/msstc4symfony/metrics-bundle/security/advisories/new>
2. **Email**: `maxim.shamaev@gmail.com`

Include, where possible:

- Affected bundle version and PHP/Symfony versions
- A reproducer (failing test, request sample, or minimal app config)
- Impact and exploitation scenario
- Suggested remediation, if any

You will receive an acknowledgement within **7 days**. Once the report is
triaged, you'll get a timeline for a fix. Coordinated disclosure is appreciated:
please give the maintainer a reasonable window (typically 30–90 days, depending
on severity) before publishing details.

## Scope

In scope:

- `GetMetricsController` (`GET /_/metrics`) and the console commands
- Collectors, `MetricRepository` and the storage `Factory` (DSN parsing)
- Integration adapters: Doctrine DBAL middleware, MongoDB `TimingSubscriber`,
  Elastica `TimingTransport`, `HttpClientDecorator`, Monolog `HandlerDecorator`
- The compiler passes that wire those adapters

Out of scope — the host application's responsibility:

- Network exposure of `/_/metrics` (see below)
- Security of the Redis / APCu storage the DSN points to
- Label values produced by the host's own metric enums

## Threat Model

### 1. `/_/metrics` is unauthenticated by design

Prometheus scrapes the endpoint without credentials, so the bundle registers it
without any access control. Metric labels reveal route names, outbound hosts and
paths, Doctrine table names and exception classes. Restrict the route at the
edge (internal network, ingress allow-list, or a Symfony `access_control` rule)
— never expose it on a public listener.

### 2. Outbound URL paths are sanitized, not redacted

`HttpClientDecorator` replaces numeric, UUID and long hex (24+) path segments before using the
path as a label (`metrics_bundle.httpClientSanitizePath`, on by default). Other
identifiers embedded in paths (e-mails, tokens in path segments) are kept.
Keep sanitization on and register a URL assembler (`AssemblerInterface`) for
APIs that put secrets in the path. Query strings are never used as labels.

### 3. Storage DSN credentials

`METRICS_STORAGE_DSN` may carry a Redis password. When adapter construction
fails, the bundle logs only the DSN scheme, never the full DSN.

### 4. Label cardinality

Every distinct label value creates a new time series in the shared storage.
Unbounded values (raw paths with IDs, user input) can exhaust Redis memory and
Prometheus. The built-in collectors normalise paths; custom metrics registered
through `metrics_bundle.metric_enums` must do the same.
