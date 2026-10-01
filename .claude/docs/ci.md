# CI

`.github/workflows/checks.yml`:

```yaml
jobs:
  standard:
    uses: msstc4symfony/bundle-standard/.github/workflows/php-bundle.yml@v1.6.0
    with:
      slug: msstc4symfony/metrics-bundle
      extensions: 'mbstring, xml, ctype, iconv, intl, redis, apcu, mongodb'
      ini-values: 'apc.enable_cli=1'
  minimal:   # composer.json only + phpunit
```

- `standard` — общий гейт: standard-check, PHPStan (`phpstan-ci.neon`), CS-Fixer,
  Rector, deptrac, composer validate/audit, PHPUnit на матрице
  PHP {8.4, 8.5} × Symfony {6.4, 7.4, 8.x}, BC check, infection (push в `main`).
  Каждая ячейка проверяет, что реально поставился нужный мажор Symfony.
- `minimal` — единственная проверка работы без опциональных библиотек.
  Расширения: без `apcu`/`mongodb` намеренно.
- Тег стандарта закреплён точным `vX.Y.Z`; обновление — явная правка строки.
- Codecov выключен (`run-codecov: false` по умолчанию) — нет `CODECOV_TOKEN`.
- Логику гейта менять в `bundle-standard`, не копированием сюда.
