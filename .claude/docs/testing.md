# Тестирование

## Два suite в одном `phpunit.xml.dist`

- `unit` (`tests/Unit/`) — без опциональных библиотек.
- `integration` (`tests/Integration/`) — каждый тест в `setUp()` вызывает
  `markTestSkipped()`, если его библиотеки нет. Поэтому `make test` безопасен на
  любой установке, но **пропуск ≠ проход**: смотри счётчик Skipped.

Ожидаемые пропуски на полном профиле: только 2 теста APCu, если нет `ext-apcu`
или `apc.enable_cli=0`. Любой другой пропуск в CI — сигнал, что профиль не
поставил то, что должен (так нашлось отсутствие `symfony/yaml`).

## Моки и стабы

PHPUnit 12+ пишет notice на мок без ожиданий. Нет `expects()` — `createStub()`.
Методы, возвращающие `static` (`HttpClientInterface::withOptions()`), стабить
через `willReturnSelf()`: стаб другого класса нарушит тип возврата.
`ResponseInterface::getInfo()` в коде вызывается с ключом
(`getInfo('start_time')`) — стаб через `willReturnMap`.

## Тест на настоящем ядре

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
гистограммы. Собственные `labelValuesFor()`/`familyExists()` убраны в 1.4.0: они возвращали
`array<array>` с `mixed`-значениями лейблов и не проходили PHPStan level 10.

`tests/Unit/Infrastructure/Enum/MetricCatalogTest` — снимок контракта каталога (тип, лейблы с
перечислениями, бакеты) для `MetricLabelEnum` и `MessengerMetricLabelEnum`; `testEveryCaseIsPinned`
ловит новый кейс без строки в провайдере.

## Ветка, недостижимая на CI-профиле

`LogicException` пасса `AddDoctrineDBALMonitorPass` («нет `ConnectionNameAwareInterface`») в CI не
воспроизвести: интерфейс всегда установлен. Тест запускается `#[RunInSeparateProcess]` и добавляет
в Composer class map `ConnectionNameAwareInterface => /dev/null` — class map проверяется раньше PSR-4,
пустой файл класс не объявляет, и `interface_exists()` в этом процессе даёт `false`.

## prefer-lowest локально

`/tmp` (tmpfs) в песочнице кончается по инодам — копию держать в `var/work/` бандла (в `.gitignore`),
`TMPDIR` и `COMPOSER_CACHE_DIR` туда же (кеш `~/.cache/composer/vcs` в песочнице не пишется, а
profiling-bundle ставится из vcs). Шаги как в CI: `composer remove --dev --no-update` для
`roave/backward-compatibility-check` и `deptrac/deptrac`, `composer require --no-update
<symfony/*>:6.4.*` для `framework-bundle`, `console`, `http-kernel`, `dependency-injection`,
`config` и всех `symfony/*` из манифеста (кроме contracts/monolog-bundle/polyfill), затем
`COMPOSER=composer-ci.json composer update --prefer-lowest --prefer-stable`. Перед `make check` копию
удалить: `php -l` обходит всё, кроме `vendor/`. На framework-bundle 6.4.0 PHP 8.4 печатает
«Implicitly marking parameter ... as nullable» при загрузке классов — это вывод в stderr, не
провал теста.
