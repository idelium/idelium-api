![Idelium](https://idelium.io/assets/images/idelium.png)

# Idelium Automation Server

Idelium AS is the tool that allows you to configure your tests. You can define your project, define your steps, compose your testcase.

Once you have configured what you want to test, you are ready to run your test using [idelium-cli](https://github.com/idelium/idelium-cli).

For more info: https://idelium.io

## Development and verification

The supported runtime range is PHP 8.2 through PHP 8.4. Dependency resolution
uses Composer 2.10.2 with a PHP 8.2 platform, and `composer.lock` is committed so
local and CI installations use the same package versions.

Run the same quality gates as CI from the repository root:

```bash
composer install --no-interaction --prefer-dist
composer validate --strict --no-check-publish
composer audit --locked --no-interaction
find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l
composer format:check
composer analyse
touch database/ci.sqlite
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=database/ci.sqlite php artisan migrate:fresh --force
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=database/ci.sqlite php artisan migrate:rollback --step=1 --force
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=database/ci.sqlite php artisan migrate --force
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: composer test
# With PCOV or Xdebug coverage enabled:
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpunit --coverage-clover=coverage.xml
composer coverage:check
```

CI runs the main quality gates on PHP 8.2, 8.3, and 8.4. A dedicated PHP 8.3
job enforces at least 60% statement coverage. Update dependencies with the same
Composer version, review the resulting lockfile diff, and rerun all gates.

## idelium-docker

idelium-docker is a docker project to start Idelium AS locally, as a pre-requisite you must have docker on your machine (https://www.docker.com/)

It is made up of three containers:

1) idelium-fe (front end)

2) idelium-be (web api)

3) idelium-db (db server)

## Prerequisite

Install Docker from [https://www.docker.com/](https://www.docker.com/)

## Download idelium-docker

```
git clone https://github.com/idelium/idelium-docker.git
```

To launch the server  and configure it correctly is very simple, just run these two commands:

```
cd idelium-docker
./start-idelium.sh
```

## Login

Open your browser and launch:

https://localhost

### Credentials

To log in to the system, enter the following credentials:

username: demo@idelium.io

password: demo

## Quick Start

[https://github.com/idelium/idelium-docker/wiki/Quick-Start-Selenium](https://github.com/idelium/idelium-docker/wiki/Quick-Start-Selenium)

or

[https://github.com/idelium/idelium-docker/wiki/Quick-Start-Test-API-Using-Postman](https://github.com/idelium/idelium-docker/wiki/Quick-Start-Test-API-Using-Postman)
