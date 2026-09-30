# Известные проблемы и находки

## HTTP-мониторинг не работал до `v1.1.0`

`AddHttpClientMonitorPass` проверял `class_exists(HttpClientInterface::class)` —
для интерфейса всегда `false`, пасс выходил сразу. Под этим сидел второй дефект:
вызов `tagged_iterator()`, функции, которой нет вне загрузки PHP-конфигов.
Тег ассемблеров был с опечаткой (`metrics.htp_client...`) — переименован в
`metrics.http_client.url_assembler` (`AssemblerInterface::TAG`); на старое имя
никто не мог опираться, фича не работала.

## `apc://`, `apcng://`, `inmemory://` уходили в InMemory

`parse_url('apc://')` === `false` → «malformed DSN» → InMemory, то есть метрики
APCu не шарились между воркерами. Исправлено разбором схем без хоста.

## Бандл не запускался в приложении с Monolog

Цикл: `Storage\Factory` → `LoggerInterface` (задекорирован `HandlerDecorator`) →
`ErrorCollector` → `RegistryInterface` → `Adapter` → `Factory`. Контейнер не
собирался вообще; тесты компилер-пассов на голом `ContainerBuilder` этого не видели.
Исправлено: `Factory` пишет в свой канал `metrics` (`#[WithMonologChannel]`), канал
исключён из декорирования. Ловит `tests/integration/ContainerCompileTest`
(настоящее ядро: Framework + Monolog + Metrics). `monolog/monolog ^3.5` объявлен явно —
атрибут и `Monolog\Level` есть только в 3.x.

## Маршрут `/_/metrics` не регистрируется сам

Бандл не может добавить маршрут; приложение импортирует
`@MetricsBundle/Presentation/Controller/` (`type: attribute`). README раньше
утверждал обратное.

## `symfony/yaml` не был объявлен

`MetricsExtension` грузит `services.yaml`, пакет приходил транзитивно (deptrac).
Приложение без него падало при сборке контейнера. Теперь в `require`;
`bundle-standard` v1.5.0 проверяет это правилом.

## `symfony/monolog-bundle` — `^3.11|^4.0`

3.x не ставится на Symfony 8.

## Elastica 8 не поддерживается

`TimingTransport` построен на транспортном API Elastica 7 (`Connection`,
`AbstractTransport`), которого в 8 нет. `boot()` выходит, если нет
`AbstractTransport`, — метрики Elastica 8 молча не собираются. Поддержка 8 —
этап B. В CI зафиксирована `^7.3`, чтобы транспорт реально тестировался.

## `Statement::execute()` и DBAL 3/4

DBAL 3 передаёт параметры в `execute($params)`, DBAL 4 параметр убрал. Метод
принимает `mixed $params = null` и форвардит `func_get_args()` — работает на обоих.

## BC check красный до релиза `v1.1.0`

Последний тег `v1.0.0` — под старым namespace; Roave видит «удалённые» классы.
Job `continue-on-error`. После тега `v1.1.0` сравнение пойдёт с ним.

## Коммит `0df4722` содержит больше, чем в сообщении

Первый коммит A2 захватил заранее проиндексированные переименования
(`composer-integration.json` → `composer-ci.json`, `phpunit-integration.xml.dist`
→ `phpunit.xml.dist`, удаление Psalm). Переписать историю `main` не удалось;
сообщение следующего коммита описывает их корректно.
