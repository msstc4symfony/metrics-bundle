# Известные проблемы и находки

## HTTP-мониторинг не работал до `v1.1.0`, а включённый как был — вредил

`AddHttpClientMonitorPass` проверял `class_exists(HttpClientInterface::class)` —
для интерфейса всегда `false`; под этим сидел вызов незагруженной `tagged_iterator()`.
Когда пасс заработал, ревью (2026-09-30) нашло, что старый дизайн:
- декорировал и клиенты, и `http_client.transport` — каждый запрос считался дважды;
- у scoped-клиентов Symfony 7.4+/8 видел относительный URL — пустой host;
- читал статус в `request()` — терял конкурентность и проверку статуса в деструкторе;
- не имел `reset()` — тег `kernel.reset` переезжал на декоратор и терялся.
Первая переделка на `AsyncResponse` (2026-10-01) сломала контракт: idle-timeout
приходил как `TransportException` вместо `TimeoutException`, а отменённые запросы
писали длительность. Сейчас: только транспорт, прозрачная `MonitoredResponse`,
метрики при чтении ответа или в `stream()`; длительность — только у полностью
полученных ответов. Статус пишется и когда чтение бросает 4xx/5xx
(`HttpExceptionInterface`), и в деструкторе непрочитанного ответа; ошибки транспорта
статуса не дают. `toStream()` отдаёт буферизованный и перематываемый поток внутреннего
ответа (Psr18Client/HttplugClient делают `seek`), а длительность фиксирует read-фильтр
`CompletionStreamFilter` на EOF; закрытие недочитанного потока тоже его вызывает, так
что длительность у такого ответа будет частичной. Деструктор не пробрасывает ошибки
хранилища метрик. Явный вызов внутреннего `__destruct()` (как в `TraceableResponse`)
у Native/Amp дважды уменьшает счётчик активных ответов — только чуть раньше чистится
DNS-кеш, корректность не страдает. Тег ассемблеров
исправлен с `metrics.htp_client...` на `metrics.http_client.url_assembler`.

## `apc://`, `apcng://`, `inmemory://` уходили в InMemory

`parse_url('apc://')` === `false` → «malformed DSN» → InMemory, то есть метрики
APCu не шарились между воркерами. Исправлено разбором схем без хоста (в т.ч. `apc:///`,
`apcng://?prefix=x`). `redis://` без хоста по-прежнему ошибка — осознанно.

## Таблица `unknown` у запросов без `WHERE`

Регулярка `Statement::assembleTableName` требовала пробел после имени таблицы:
`SELECT * FROM users` давал `unknown`. Теперь граница слова.

## Бандл не запускался в приложении с Monolog

Цикл: `Storage\Factory` → `LoggerInterface` (задекорирован `HandlerDecorator`) →
`ErrorCollector` → `RegistryInterface` → `Adapter` → `Factory`. Контейнер не
собирался вообще; тесты компилер-пассов на голом `ContainerBuilder` этого не видели.
Исправлено: `Factory` пишет в свой канал `metrics_bundle` (`#[WithMonologChannel]`), канал
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
принимает `mixed $params = null` и форвардит `func_get_args()`. CI-лок держит DBAL 4;
DBAL 3 (3.10 + Symfony 7.4) проверен вручную 2026-10-01 — весь набор зелёный. На
Symfony 8 DBAL 3 не ставится (конфликт с `symfony/http-foundation`).

## Elastica: клиенты создаются в `boot()`

`wireElasticaTransports()` достаёт каждый клиент и соединение при загрузке ядра —
один раз на воркер. Ленивая обёртка — этап B вместе с поддержкой Elastica 8.

## BC check красный до релиза `v1.1.0`

Последний тег `v1.0.0` — под старым namespace; Roave видит «удалённые» классы.
Job `continue-on-error`. После тега `v1.1.0` сравнение пойдёт с ним.

## Коммит `0df4722` содержит больше, чем в сообщении

Первый коммит A2 захватил заранее проиндексированные переименования
(`composer-integration.json` → `composer-ci.json`, `phpunit-integration.xml.dist`
→ `phpunit.xml.dist`, удаление Psalm). Переписать историю `main` не удалось;
сообщение следующего коммита описывает их корректно.

## Профилирование вынесено в мост (1.2.0, 2026-10-01 UTC)

`MetricProcessor` / `ProfilingCollector` / case `PROFILING_SPAN_DURATION_HISTOGRAM_SECONDS` —
deprecated, живут до 2.0 ради BC. Мост `msstc4symfony/metrics-bridge-profiling` объявляет ту же
метрику своим enum (через `metrics_bundle.metric_enums`) и в compiler pass удаляет definition
`MetricProcessor` — иначе span писались бы дважды. Поэтому `MetricRepositoryFactory` схлопывает
одинаковые имена метрик (первое объявление побеждает). `MetricProcessor` по-прежнему в
`excludePaths` PHPStan: профилирования в окружении metrics нет.
