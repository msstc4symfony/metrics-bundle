# Инструментарий

## Два манифеста

- `composer.json` — публикуемый; только обязательные зависимости + базовый dev.
  Опциональные библиотеки — в `suggest`.
- `composer-ci.json` — тот же runtime + опциональные библиотеки
  (`symfony/http-client`, `doctrine/dbal`, `mongodb/mongodb` ^2,
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

- Анализируется код на полном профиле; `excludePaths` — только `MetricProcessor`
  (до A5).
- `phpstan-ci.neon` = шаблон стандарта (`reportUnmatchedIgnoredErrors: false`).
- `phpstan-ci.neon` глушит только «unmatched ignored» (у CI-профиля другие записи
  baseline не срабатывают). Строгая проверка неиспользованных записей — локальный
  `make check` с `phpstan.dist.neon` на полном профиле. Уровень 9 — решение стандарта
  этапа A; переход на 10 — этап B.
- Baseline — 9 старых записей. Регенерация — только на полном профиле, иначе в
  него попадут `class.notFound` для опциональных библиотек.

## deptrac, infection

- `deptrac.yaml` — см. `architecture.md`.
- `infection.json5` гоняет оба suite (интеграционные покрывают адаптеры).
