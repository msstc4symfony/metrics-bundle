# CI

`.github/workflows/checks.yml`:

```yaml
jobs:
  standard:
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.8.0
    with:
      slug: msstc4symfony/metrics-bundle
      extensions: 'mbstring, xml, ctype, iconv, intl, redis, apcu, mongodb, pdo_sqlite'
      ini-values: 'apc.enable_cli=1'
      infection-min-msi: 69
      infection-min-covered-msi: 69
```

- `standard` — общий гейт: standard-check, PHPStan (`phpstan-ci.neon`, level 10), CS-Fixer,
  Rector, deptrac, composer validate/audit, PHPUnit на матрице
  PHP {8.4, 8.5} × Symfony {6.4, 7.4, 8.x}, BC check, Infection (push в `main`).
  Каждая ячейка проверяет, что реально поставился нужный мажор Symfony.
- С `bundle-standard` v1.8.0 (metrics 1.4.0):
  - **Roave BC check блокирующий**, база — последний стабильный тег. Мажор 1: только аддитивные
    изменения и багфиксы (добавленный кейс enum — тоже BC-break).
  - **Infection блокирующий**: `--min-msi`/`--min-covered-msi` = 69 (замер 73,12 % на 2026-10-02 UTC,
    минус ~4). Только на push в `main` — PR, уронивший MSI, покраснит `main` после мержа; перед
    мержем изменений в `src/` гонять `make infection` локально.
  - **Ячейка `--prefer-lowest`** (PHP 8.4, Symfony 6.4): `composer-ci.json`, `composer update
    --prefer-lowest --prefer-stable` после пина `symfony/*` на `6.4.*`. Почему нужны `conflict`
    вместо патчей в `require` — `tooling.md`. Воспроизведение локально — `testing.md`.
    **Сознательный пробел покрытия**: CI-only `conflict` в `composer-ci.json` (`symfony/error-handler
    <6.4.44`, `http-kernel <6.4.13`) строже, чем в `composer.json` (`error-handler <6.4.10`). Поэтому
    lowest-ячейка ставит error-handler 6.4.44, и диапазон 6.4.10–6.4.43, который пользователям
    разрешён, CI не проверяет. Причина CI-only конфликтов — risky-тесты (`tooling.md`).
- `PHPUnit without optional libraries` — job общего workflow: ставит только `composer.json`.
  Расширения — те же `extensions`, проверяется отсутствие *библиотек*, а не расширений.
- Тег стандарта закреплён точным `vX.Y.Z`; обновление — явная правка строки.
- Codecov выключен (`run-codecov: false` по умолчанию) — нет `CODECOV_TOKEN`.
- Логику гейта менять в `bundle-standard`, не копированием сюда.
