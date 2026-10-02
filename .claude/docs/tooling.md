# Инструментарий

## Два манифеста

- `composer.json` — публикуемый; только обязательные зависимости + базовый dev.
  Опциональные библиотеки — в `suggest`.
- `composer-ci.json` — тот же runtime + опциональные библиотеки
  (`symfony/http-client`, `symfony/messenger`, `doctrine/dbal`, `doctrine/doctrine-bundle`, `mongodb/mongodb` ^2,
  `ruflin/elastica` ^7) + deptrac, infection, Roave BC check. Лок —
  `composer-ci.lock`, коммитится.
- `config.platform.ext-mongodb` в `composer-ci.json` = версия расширения на
  раннере CI. Без этого лок, собранный локально с `ext-mongodb` 1.x, не
  ставится в CI (2.x) — `mongodb/mongodb` 1.x требует `ext-mongodb ^1.21`.

Оба манифеста ставятся в один `vendor/`. Основной профиль разработки —
`make install-ci`.

## `make check`

`php -l` → PHPStan (`PHPSTAN_CONFIG`, по умолчанию `phpstan.dist.neon`) →
CS-Fixer check → `composer validate --strict` → `composer audit` → Rector
dry-run → deptrac. Запускать как `COMPOSER=composer-ci.json make check`, иначе
`composer validate` сверяет несуществующий `composer.lock` плоского манифеста.

## PHPStan

- Анализируется код на полном профиле, без `excludePaths` (`phpstan.dist.neon` — общий
  шаблон `bundle-standard`; profiling-bundle в `composer-ci.json`).
- `phpstan-ci.neon` = шаблон стандарта (`reportUnmatchedIgnoredErrors: false`).
- `phpstan-ci.neon` глушит только «unmatched ignored» (у CI-профиля другие записи
  baseline не срабатывают). Строгая проверка неиспользованных записей — локальный
  `make check` с `phpstan.dist.neon` на полном профиле. Уровень 10 (с 1.4.0, `bundle-standard`
  v1.8.0 разрешает 9/10/max).
- Baseline — 8 старых записей (запись `isset.offset` у `InfoEventListener` ушла в 1.4.0). Регенерация — только на полном профиле, иначе в
  него попадут `class.notFound` для опциональных библиотек.

## deptrac, infection

- `deptrac.yaml` — см. `architecture.md`.
- `infection.json5` (шаблон стандарта) гоняет **только** `--testsuite=unit`: интеграционные тесты
  адаптеров MSI не поднимают. Infection считает лишь мутантов в покрытом коде, поэтому первый
  слабый тест для непокрытого класса MSI **снижает** (в 1.4.0 тест `InfoEventListener` уронил его
  с 59 до 56, пока не добавили контракт каталога). Порог в CI — 69 (замер 73,12 %, 2026-10-02 UTC).

## Два манифеста и нижние границы

`--prefer-lowest` (CI-ячейка PHP 8.4 + Symfony 6.4) ставит `composer-ci.json`, а шаг «Pin Symfony»
переписывает **require** перечисленных `symfony/*` на `6.4.*` — поднять минимальный патч через
`require` нельзя (и верификатор требует там ровно `^6.4|^7.0|^8.0`). Поэтому минимальные патчи
задаются через `conflict` (его пин не трогает):
- `composer.json` и `composer-ci.json`: `symfony/error-handler <6.4.10` — до 6.4.10 `ErrorHandler`
  ссылается на `E_STRICT`, на PHP 8.4 это deprecation при каждой загрузке ядра;
- только `composer-ci.json` (гигиена тестов, пользователям не нужно): `symfony/error-handler <6.4.44`
  и `symfony/http-kernel <6.4.13` — старые версии оставляют свой exception handler после
  загрузки ядра, PHPUnit 11+ помечает тест risky («did not remove its own exception handlers»),
  а `failOnRisky` валит прогон. Найдено бисекцией 2026-10-02 UTC: error-handler 6.4.43 → 11 risky,
  6.4.44 → 1 (`testUnreachableRedisNeverTurnsRequestsInto500`), http-kernel 6.4.12 → 1, 6.4.13 → 0.
