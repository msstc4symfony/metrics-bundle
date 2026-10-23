# Конвенции

- Раскладка и правила семейства — спеки этапа B (`docs/superpowers/specs/2026-10-17-stage-b-conventions-design.md`) и
  metrics 2.0 (`2026-10-19-metrics-2-hex-design.md`) в корне msstc4symfony: канонические слои, порты записи/хранилища,
  `@internal` вне Public API, `#[\Override]` везде (PHPStan `method.missingOverride`).

- PHP 8.2 (с 1.4.0; синтаксис 8.2, функции 8.4 — из `symfony/polyfill-php84`), `declare(strict_types=1)`, `#[Override]` на переопределениях.
- Проверка опциональной библиотеки — по типу символа: `interface_exists()` для
  интерфейсов, `class_exists()` для классов. `class_exists()` на интерфейсе
  всегда `false` — так `AddHttpClientMonitorPass` годами не срабатывал.
- Сервисы из контейнера сужаются `instanceof`, не `@var`-приведением
  (`MetricsBundle::boot()`).
- Имена тегов DI — константы на интерфейсе (`AssemblerInterface::TAG`), атрибут
  `#[AutoconfigureTag(self::TAG)]` и компилер-пасс ссылаются на одно значение.
- Декоратор HTTP-клиента — по образцу `TraceableHttpClient`: `DecoratorTrait`
  (`withOptions()`/`reset()`) + своя прозрачная обёртка `MonitoredResponse`, а
  `stream()` разворачивает обёртки. **Не `AsyncResponse`**: его инициализатор
  превращает `TimeoutException` в `TransportException` для всего трафика.
  Статус читать в `request()` нельзя (сериализует запросы). Host/path — из
  `getInfo('url')` внутреннего ответа: там уже учтён `base_uri` из `withOptions()`.
- Свой канал Monolog у бандла — `metrics_bundle` (имя канала, не параметр); он исключён из `HandlerDecorator`.
- В компилер-пассах — `new TaggedIteratorArgument(...)`, а не функция
  `tagged_iterator()`: она определена в файле конфигуратора и вне загрузки
  PHP-конфигов не существует.
- Метрики бандла — кейсы `BuiltInMetric`; приложение добавляет свой enum через
  `msstc4symfony_metrics.metric_enums` (конфиг), мосты — дописывая параметр с тем же именем в
  компилер-пассе. Имя метрики объявляется один раз — дубликат валит `MetricRepositoryFactory`.
  Значения лейблов нормализуются (кардинальность). Контракт каталога (тип, лейблы, бакеты)
  закреплён `MetricCatalogTest`.
- Параметры контейнера — только `msstc4symfony_metrics.*`, выставляются в
  `MetricsBundle::loadExtension()` из дерева конфигурации; в `#[Autowire(param: …)]` имя пишется
  строкой (deptrac: Infrastructure не зависит от корня бандла). Аргументы конструкторов без
  BC-дефолтов: логгеры, опции и зависимости — обязательные; дефолты остаются только у настоящих
  настроек по умолчанию (период `InfoEventListener`, игнорируемые маршруты, уровни `HandlerDecorator`).
- PHPStan level 10 без baseline-роста и без `@phpstan-ignore`: `mixed` из внешних массивов
  (`opcache_get_status()` и т. п.) сужается `is_array()`/`is_int()`/`is_float()` в маленьком хелпере
  (`InfoEventListener::number()`), а не кастами.
- Комментарии — только «почему», на английском.
- Встроенные функции из набора `@compiler_optimized` (`\sprintf`, `\is_array`, `\in_array`, `\count`…) — с ведущим `\`
  в новом коде (так в большинстве `src/`; CS-Fixer этого не требует, правило применялось разово к файлам 2.2).
- Декоратор пула/клиента реализует все необязательные интерфейсы декорируемого (как `TraceableAdapter`) и
  проксирует их по `instanceof`; общий код вариантов — trait (`CountsCacheLookups`), классы остаются `final readonly`.
- Счётчики горячих путей (кеш) агрегируются в памяти и пишутся через `MetricBatchRecorderInterface::incrementBy()`
  один раз на серию в конце единицы работы; новый метод порта — новый интерфейс, не метод существующего (Roave BC).
- Опциональный listener/коллектор, нужный только при опции конфига, исключается из скана и регистрируется пассом
  с явными тегами (`kernel.event_listener`, `kernel.reset`) — пасс идёт после автоконфигурации, атрибуты к его
  определениям не применяются (`RegisterMessengerQueueDepthPass`, `DecorateCachePoolsPass`).
- Декоратор пула не оборачивает callback приложения, если у пула есть собственный callback wrapper: считать там
  (`CountingCallbackWrapper`), иначе ломается развёртывание callback'а early expiration.
- Метрики разных клиентов одного рода (http-client, Guzzle) пишут одни серии; общие метки — в одном `@internal`
  классе (`OutgoingRequestLabels`).
