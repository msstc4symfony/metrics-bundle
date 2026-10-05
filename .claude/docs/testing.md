# Тестирование

## Два suite в одном `phpunit.xml.dist`

- `unit` (`tests/Unit/`) — без опциональных библиотек.
- `integration` (`tests/Integration/`) — каждый тест в `setUp()` вызывает
  `markTestSkipped()`, если его библиотеки нет. Поэтому `make test` безопасен на
  любой установке, но **пропуск ≠ проход**: смотри счётчик Skipped.

Ожидаемые пропуски на полном профиле: только 2 теста APCu, если нет `ext-apcu`
или `apc.enable_cli=0`. Любой другой пропуск в CI — сигнал, что профиль не
поставил то, что должен.

## Моки и стабы

PHPUnit 12+ пишет notice на мок без ожиданий. Нет `expects()` — `createStub()`.
Методы, возвращающие `static` (`HttpClientInterface::withOptions()`), стабить
через `willReturnSelf()`: стаб другого класса нарушит тип возврата.
`ResponseInterface::getInfo()` в коде вызывается с ключом
(`getInfo('start_time')`) — стаб через `willReturnMap`.

## Тест на настоящем ядре

Конфигурация бандла — `tests/Integration/DependencyInjection/MetricsBundleConfigTest` на ядре
`Kernel/BundleConfigKernel` (Framework + Monolog + Metrics, без Messenger/Doctrine; дерево
`msstc4symfony_metrics` передаётся в конструктор, `null` — приложение без файла конфига; кеш — по
хешу конфига). Приватные сервисы достаются через `test.service_container`.

`tests/Integration/ContainerCompileTest` + `Kernel/TestKernel` (Framework + Monolog +
Metrics) ловят то, чего не видят тесты пассов на голом `ContainerBuilder`: циклы,
проводку декораторов, маршрут. HTTP проверяется штатным `framework.http_client.mock_response_factory`: монитор
декорирует транспорт с приоритетом -20, то есть снаружи мока (-10). Таймаут —
только настоящим сокетом (`stream_socket_server` без ответа): `MockResponse`
отдаёт статус сразу и бросает таймаут из деструктора чанка, причём уже в
следующем тесте. Каталог кеша — на процесс (`getmypid()`): infection гоняет
PHPUnit параллельно, а `setUp()` чистит каталог.

## Логгер в тестах

Проверять «какие сообщения записаны» удобнее анонимным `AbstractLogger`,
собирающим сообщения, чем `expects(never())->method('error')`: последний ловит
любой вызов, включая законные ошибки окружения.

## Обрыв Redis без Redis

`tests/Support/Redis/resp-server.php` — RESP-сервер на PHP (`+OK` на всё, команды в лог-файл,
сам завершается через 60 с). Тест стартует его через `proc_open` на свободном порту, убивает
(`proc_terminate(…, 9)`) и поднимает на том же порту — так настоящий phpredis проходит путь
`Connection lost` → `went away`. Для юнит-уровня — `FailingOnceDownRedis` (наследник `\Redis`,
`fromExistingConnection()`), без сети.
Номер БД из пути DSN (`redis://host/3`) проверяет только этот интеграционный тест (`SELECT 3`):
`FactoryTest` видит лишь тип адаптера. `freePort()` освобождает порт до старта сервера — теоретическая
гонка; если тест начнёт мигать, сервер должен слушать порт 0 и сообщать фактический порт.

## Чтение реестра в тестах

Только `tests/Support/RegistrySamples`: `labels($registry, $metric, $suffix)`, `samples(...)` (лейблы +
значение строкой), `exists($registry, $metric)`. Суффикс `_count`/`_sum`/`_bucket` выбирает сэмплы
гистограммы. Собственные `labelValuesFor()`/`familyExists()` убраны: они возвращали
`array<array>` с `mixed`-значениями лейблов и не проходили PHPStan level 10.

`tests/Unit/Infrastructure/Enum/MetricCatalogTest` — снимок контракта каталога (тип, лейблы с
перечислениями, бакеты) для `MetricLabelEnum`; `testEveryCaseIsPinned`
ловит новый кейс без строки в провайдере.

## prefer-lowest локально

`/tmp` (tmpfs) в песочнице кончается по инодам — копию держать в `var/work/` бандла (в `.gitignore`),
`TMPDIR` и `COMPOSER_CACHE_DIR` туда же. Шаги как в CI: `composer remove --dev --no-update` для
`roave/backward-compatibility-check` и `deptrac/deptrac`, `composer require --no-update
<symfony/*>:7.4.*` для `framework-bundle`, `console`, `http-kernel`, `dependency-injection`,
`config` и всех `symfony/*` из манифеста (кроме contracts/monolog-bundle/polyfill), затем
`COMPOSER=composer-ci.json composer update --prefer-lowest --prefer-stable`. Перед `make check` копию
удалить: `php -l` обходит всё, кроме `vendor/`.

## Группа `elasticsearch` (живой кластер)

`tests/Integration/Infrastructure/Elastica/ElasticsearchMetricsTest` (`#[Group('elasticsearch')]`)
поднимает `ElasticsearchKernel` (FrameworkBundle + MonologBundle + MetricsBundle, `inmemory://`,
`application`/`component` = `app`/`cmp`) с клиентом-подклассом `tests/Support/SubclassedElasticaClient`
и проверяет серии после `getCluster()->getHealth()`. Без `ELASTICSEARCH_URL` — skip. Тесты пассов
Elastica разделены по версии: `SaveElasticaClientsListPassTest` пропускается без
`AbstractTransport` (7), `DecorateElasticaClientsPassTest` — без `Elastic\Transport\Transport` (8);
локально 8 гонять в копии с `composer update` (лок на 7).
