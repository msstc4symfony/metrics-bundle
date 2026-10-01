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
- Новые метрики — case в `MetricLabelEnum` или enum хоста в
  `metrics_bundle.metric_enums`; значения лейблов нормализуются (кардинальность).
- Комментарии — только «почему», на английском.
