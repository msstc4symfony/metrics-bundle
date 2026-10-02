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
