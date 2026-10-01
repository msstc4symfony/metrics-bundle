# CI

`.github/workflows/checks.yml`:

```yaml
jobs:
  standard:
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.7.1
    with:
      slug: msstc4symfony/metrics-bundle
      extensions: 'mbstring, xml, ctype, iconv, intl, redis, apcu, mongodb'
      ini-values: 'apc.enable_cli=1'
```

- `standard` — общий гейт: standard-check, PHPStan (`phpstan-ci.neon`), CS-Fixer,
  Rector, deptrac, composer validate/audit, PHPUnit на матрице
  PHP {8.4, 8.5} × Symfony {6.4, 7.4, 8.x}, BC check, infection (push в `main`).
  Каждая ячейка проверяет, что реально поставился нужный мажор Symfony.
- `PHPUnit without optional libraries` — job общего workflow (с `bundle-standard` 1.7, раньше
  был локальным `minimal`): ставит только `composer.json`. Расширения — те же `extensions`,
  проверяется отсутствие *библиотек*, а не расширений.
- Тег стандарта закреплён точным `vX.Y.Z`; обновление — явная правка строки.
- Codecov выключен (`run-codecov: false` по умолчанию) — нет `CODECOV_TOKEN`.
- Логику гейта менять в `bundle-standard`, не копированием сюда.
