# Changelog

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
