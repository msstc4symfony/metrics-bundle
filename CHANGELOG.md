# Changelog

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
