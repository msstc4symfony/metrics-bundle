# Конвенции

- PHP 8.4, `declare(strict_types=1)`, `#[Override]` на переопределениях.
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
- Свой канал Monolog у бандла — `metrics_bundle`; он исключён из `HandlerDecorator`.
- В компилер-пассах — `new TaggedIteratorArgument(...)`, а не функция
  `tagged_iterator()`: она определена в файле конфигуратора и вне загрузки
  PHP-конфигов не существует.
- Новые метрики — новый enum бандла (как `MessengerMetricLabelEnum`, дописывается пассом) или enum
  хоста в `metrics_bundle.metric_enums`; кейсы в `MetricLabelEnum` в мажоре 1 не добавляем (Roave
  считает это BC-break). Значения лейблов нормализуются (кардинальность). Контракт каталога
  (тип, лейблы, бакеты) закреплён `MetricCatalogTest`.
- PHPStan level 10 без baseline-роста и без `@phpstan-ignore`: `mixed` из внешних массивов
  (`opcache_get_status()` и т. п.) сужается `is_array()`/`is_int()`/`is_float()` в маленьком хелпере
  (`InfoEventListener::number()`), а не кастами.
- Комментарии — только «почему», на английском.
