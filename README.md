# Metrics symfony bundle

![Build Status](https://github.com/max-shamaev-php/metrics-bundle/actions/workflows/checks.yml/badge.svg?branch=main)
[![codecov](https://codecov.io/github/max-shamaev-php/metrics-bundle/graph/badge.svg?token=EoGwEpONxh)](https://codecov.io/github/max-shamaev-php/metrics-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

This bundle is responsible for the automatic collection of application metrics, their storage, and exposing them to a Prometheus-compatible client via HTTP requests to the application.

The following are measured:
* incoming HTTP requests
* console command executions
* uncaught exceptions and errors
* requests to external services via symfony/http-client
* Doctrine DBAL queries
* MongoDB queries
* ruflin/elastica queries (only 1.*-7.* versions)

The exact list of metrics can be viewed by running the console command: `bin/console metrics:list`

The URL for the Prometheus client is: `GET /_/metrics`

## Installation

After `composer require max-shamaev-php/metrics-bundle` need add some env variables to local `.env`. See [doc/.env.dist](doc/.env.dist) for example.

## Add custom metric

1. Define a custom collector (like [ConsoleCollector.php](vendor-local/max-shamaev-php/metrics-bundle/src/Infrastructure/Collector/ConsoleCollector.php)) that exposes the required methods.
2. Register the collector as a dependency within the relevant services.
3. Leverage the collector’s methods to perform the necessary measurements.

## Usage

### Get collected metrics

`GET /_/metrics`

Get all collected metrics in Prometheus format.

### Clear metrics storage 

`./bin/console metrics:clear`

### Get metrics list 

`./bin/console metrics:list`

## Local development

Check code:
```shell
make check
```

Fix code:
```shell
make fix
```

## Technical details

Метрики через классы-коллекторы замеряются в определенных точках кода, используя систему событий Symfony, middlewares и декорирования.  
В классах-коллекторах метрики собираются и в конце runtime сохраняются в redis.  
Prometheus периодически через HTTP ходит в контроллер библиотеки и забирает метрики из redis. При этом метрики сами в redis'е хранятся вечно ну или до передеплоя приложения, так как prometheus метрики забирает заново целиком, он полностью перезаписывает новыми метриками старые метрики в своем хранилище.  