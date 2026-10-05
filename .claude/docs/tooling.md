# Инструментарий

## Два манифеста

- `composer.json` — публикуемый; только обязательные зависимости + базовый dev.
  Опциональные библиотеки — в `suggest`.
- `composer-ci.json` — тот же runtime + опциональные библиотеки
  (`symfony/http-client`, `symfony/messenger`, `doctrine/dbal`, `doctrine/doctrine-bundle`, `mongodb/mongodb` ^2,
  `ruflin/elastica` `^7.3|^8.0`, лок на 7 — см. known-issues, `php-http/discovery`, `psr/http-client`,
  `nyholm/psr7`) + deptrac, infection, Roave BC check. Лок —
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
  шаблон `bundle-standard`).
- `phpstan-ci.neon` = шаблон стандарта (`reportUnmatchedIgnoredErrors: false`).
- `phpstan-ci.neon` глушит только «unmatched ignored» (у CI-профиля другие записи
  baseline не срабатывают). Строгая проверка неиспользованных записей — локальный
  `make check` с `phpstan.dist.neon` на полном профиле. Уровень 10 (`bundle-standard`
  разрешает 9/10/max).
- Baseline — 8 старых записей (запись `isset.offset` у `InfoEventListener` удалена). Регенерация — только на полном профиле, иначе в
  него попадут `class.notFound` для опциональных библиотек.

## deptrac, infection

- `deptrac.yaml` — см. `architecture.md`.
- `infection.json5` (шаблон стандарта) гоняет **только** `--testsuite=unit`: интеграционные тесты
  адаптеров MSI не поднимают. Infection считает лишь мутантов в покрытом коде, поэтому первый
  слабый тест для непокрытого класса MSI **снижает** (тест `InfoEventListener` однажды уронил его
  с 59 до 56, пока не добавили контракт каталога). Порог в CI — 69 (замер 73,12 %, 2026-10-02 UTC).

## Два манифеста и нижние границы

`--prefer-lowest` (CI-ячейка PHP 8.4 + Symfony 7.4) ставит `composer-ci.json`, а шаг «Pin Symfony»
переписывает **require** перечисленных `symfony/*` на `7.4.*` — поднять минимальный патч через
`require` нельзя (верификатор требует там ровно `^7.4|^8.0`), поэтому при нужде минимальный патч
задаётся через `conflict` (его пин не трогает). Остался один `conflict`,
только в `composer-ci.json` (гигиена тестов, пользователям не нужен): `symfony/error-handler
<7.4.17 || >=8.0,<8.1.5` — одинаковый во всех бандлах семейства (8.1.5 — та же правка в ветке 8.x).
Ниже ядро (FrameworkBundle 7.4.0) оставляет свой exception handler после загрузки, PHPUnit помечает
каждый kernel-тест risky («did not remove its own exception handlers»), `failOnRisky` валит прогон.
`error-handler` в манифестах не перечислен, поэтому пин его не трогает и lowest брал 7.3.0. Бисекция
2026-10-04 UTC (framework-bundle 7.4.0, http-kernel 7.4.12): error-handler 7.3.0/7.4.0/7.4.4/7.4.8/
7.4.14/7.4.15 → 18 risky, 7.4.17/7.4.20 → 0. Не удалять при чистке «лишних» `conflict`.

## Elastica в CI

- Основные ячейки PHPUnit (`composer update`) ставят Elastica 8, lowest — 7.3.0, статанализ и
  Infection — лок (7.3.2).
- Job «Elasticsearch integration» (`bundle-standard` `v1.1.0`, вход `elasticsearch` в
  `checks.yml`): Elastica `^7.3` + ES `7.17.29` и `^8.0` + ES `8.19.22` (теги — те же, что в
  healthcheck-bundle), гоняет `vendor/bin/phpunit --group elasticsearch`.
- Локально: `docker run -d --rm --name es -p 9200:9200 -e discovery.type=single-node
  -e xpack.security.enabled=false -e ES_JAVA_OPTS='-Xms512m -Xmx512m'
  docker.elastic.co/elasticsearch/elasticsearch:<тег>`, затем
  `ELASTICSEARCH_URL=http://localhost:9200 vendor/bin/phpunit --group elasticsearch`. Без
  `ELASTICSEARCH_URL` тест группы пропускается.
