# Конвенции

- PHP 8.4, `declare(strict_types=1)`, `#[Override]` на переопределениях.
- Проверка опциональной библиотеки — по типу символа: `interface_exists()` для
  интерфейсов, `class_exists()` для классов. `class_exists()` на интерфейсе
  всегда `false` — так `AddHttpClientMonitorPass` годами не срабатывал.
- Сервисы из контейнера сужаются `instanceof`, не `@var`-приведением
  (`MetricsBundle::boot()`).
- Имена тегов DI — константы на интерфейсе (`AssemblerInterface::TAG`), атрибут
  `#[AutoconfigureTag(self::TAG)]` и компилер-пасс ссылаются на одно значение.
- Декораторы HTTP-клиента — через `AsyncDecoratorTrait` + `AsyncResponse` с passthru:
  метрики пишутся по чанкам (`isFirst` — статус, `isLast` — `total_time`). Читать
  статус в `request()` нельзя: это сериализует конкурентные запросы и отключает
  проверку статуса в деструкторе непрочитанного ответа. `DecoratorTrait` даёт
  `withOptions()`/`reset()` — без `reset()` в воркерах не сбрасывается curl multi.
- Свой канал Monolog у бандла — `metrics_bundle`; он исключён из `HandlerDecorator`.
- В компилер-пассах — `new TaggedIteratorArgument(...)`, а не функция
  `tagged_iterator()`: она определена в файле конфигуратора и вне загрузки
  PHP-конфигов не существует.
- Новые метрики — case в `MetricLabelEnum` или enum хоста в
  `metrics_bundle.metric_enums`; значения лейблов нормализуются (кардинальность).
- Комментарии — только «почему», на английском.
