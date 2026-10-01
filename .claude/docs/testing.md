# Тестирование

## Два suite в одном `phpunit.xml.dist`

- `unit` (`tests/unit/`) — без опциональных библиотек.
- `integration` (`tests/integration/`) — каждый тест в `setUp()` вызывает
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

`tests/integration/ContainerCompileTest` + `Kernel/TestKernel` (Framework + Monolog +
Metrics) ловят то, чего не видят тесты пассов на голом `ContainerBuilder`: циклы,
проводку декораторов, маршрут. HTTP проверяется через подмену самого
`http_client.transport` на `MockHttpClient` (в `TestKernel::build()`):
`framework.http_client.mock_response_factory` встаёт **снаружи** транспорта и
спрятал бы монитор. Каталог кеша — на процесс (`getmypid()`): infection гоняет
PHPUnit параллельно, а `setUp()` чистит каталог.

## Логгер в тестах

Проверять «какие сообщения записаны» удобнее анонимным `AbstractLogger`,
собирающим сообщения, чем `expects(never())->method('error')`: последний ловит
любой вызов, включая законные ошибки окружения.
