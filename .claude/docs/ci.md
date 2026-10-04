# CI

`.github/workflows/checks.yml`:

```yaml
jobs:
  standard:
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.0.0
    with:
      slug: msstc4symfony/metrics-bundle
      extensions: 'mbstring, xml, ctype, iconv, intl, redis, apcu, mongodb, pdo_sqlite'
      ini-values: 'apc.enable_cli=1'
      infection-min-msi: 69
      infection-min-covered-msi: 69
```

- `standard` — общий гейт: standard-check, PHPStan (`phpstan-ci.neon`, level 10), CS-Fixer,
  Rector, deptrac, composer validate/audit, PHPUnit на матрице
  PHP {8.4, 8.5} × Symfony {7.4, 8.x}, BC check, Infection (push в `main`).
  Каждая ячейка проверяет, что реально поставился нужный мажор Symfony.
- Особенности гейта:
  - **Roave BC check** блокирующий (дефолт стандарта), база — последний стабильный тег.
  - **Infection блокирующий**: `--min-msi`/`--min-covered-msi` = 69 (замер 73,12 % на 2026-10-02 UTC,
    минус ~4). Только на push в `main` — PR, уронивший MSI, покраснит `main` после мержа; перед
    мержем изменений в `src/` гонять `make infection` локально.
  - **Ячейка `--prefer-lowest`** (PHP 8.4, Symfony 7.4): `composer-ci.json`, `composer update
    --prefer-lowest --prefer-stable` после пина `symfony/*` на `7.4.*`. Почему минимальные патчи
    задаются через `conflict`, а не `require`, и зачем CI-only `symfony/error-handler <7.4.17 || >=8.0,<8.1.5` —
    `tooling.md`. Воспроизведение локально — `testing.md`.
- `PHPUnit without optional libraries` — job общего workflow: ставит только `composer.json`.
  Расширения — те же `extensions`, проверяется отсутствие *библиотек*, а не расширений.
- Тег стандарта закреплён точным `vX.Y.Z`; обновление — явная правка строки.
- Codecov выключен (`run-codecov: false` по умолчанию) — нет `CODECOV_TOKEN`.
- Логику гейта менять в `bundle-standard`, не копированием сюда.
