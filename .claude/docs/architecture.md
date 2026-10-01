# Архитектура

## Слои (`src/`)

| Слой | Что внутри | Может зависеть от |
|---|---|---|
| `Infrastructure` | коллекторы, `MetricRepository`, `Storage\Factory`, сущности/enum, адаптеры DBAL/ODM/Elastica/HttpClient/Monolog | ничего внутри бандла |
| `Presentation` | `GetMetricsController` (`GET /_/metrics`), команды `metrics:list`, `metrics:clear` | `Infrastructure` |
| `Framework` | слушатели kernel/console, `Profiling\...\MetricProcessor` | `Infrastructure`, `Presentation` |
| `DependencyInjection` | `MetricsExtension`, компилер-пассы | `Infrastructure` |
| `MetricsBundle` (корень) | регистрация пассов, `boot()` | `DependencyInjection`, `Infrastructure` |

Правила зафиксированы в `deptrac.yaml` как статус-кво этапа A (спек 6.2): новые
направления зависимостей запрещены, перекраска слоёв — этап B.
`Framework → Presentation` существует ради одного случая: `RequestEventListener`
не меряет запросы к самому эндпоинту метрик (`GetMetricsController::ROUTE_NAME`).

## Точки проводки

1. `services.yaml` — грузится `YamlFileLoader` в `MetricsExtension`, поэтому
   `symfony/yaml` в `require` обязателен.
2. Компилер-пассы (`MetricsBundle::build()`):
   - `AddMonologDecoratorCompilerPass` — декорирует `monolog.logger.*`;
   - `AddDoctrineDBALMonitorPass` — DBAL middleware;
   - `AddHttpClientMonitorPass` (priority -256) — декорирует **только**
     `http_client.transport` (приоритет декорации -20 — снаружи мока
     `mock_response_factory`, -10): туда сходятся все клиенты фреймворка (default и scoped)
     уже с абсолютным URL, поэтому каждый реальный запрос считается один раз и с
     правильным host. Повторы `retry_failed` — отдельные запросы. Клиенты, созданные
     приложением в обход FrameworkBundle, не мониторятся. URL-ассемблеры — по тегу
     `AssemblerInterface::TAG` (`metrics.http_client.url_assembler`);
   - `SaveElasticaClientsListPass` — пишет id клиентов Elastica в параметр
     `metrics.elastica.clients`.
3. `MetricsBundle::boot()` — то, что нельзя сделать в контейнере:
   подписка `TimingSubscriber` на драйвер MongoDB и подмена транспорта у
   соединений Elastica 7 на `TimingTransport`.

## Опциональные библиотеки

Все интеграции, кроме Redis-хранилища, опциональны: http-client, DBAL,
MongoDB, Elastica, APCu. Точка проводки молча выключается без своей библиотеки:
либо явным гардом (`interface_exists` для интерфейсов, `class_exists` для
классов — http-client, MongoDB, Elastica в `boot()`), либо потому, что без
библиотеки в контейнере нет подходящих определений (DBAL, список клиентов Elastica). Job `minimal` в CI доказывает, что гарды держат.

## Хранилище

`Storage\Factory::create()` выбирает адаптер Prometheus по схеме DSN:
`redis`, `redisng`, `apc`, `apcng`, `inmemory`. Любая ошибка — откат на
`InMemory` с записью в лог канала `metrics_bundle` (только схема DSN, без учётных данных).
Схемы без хоста (`apc://`, `apcng://`, `inmemory://`) разбираются отдельно:
`parse_url()` их отвергает.
