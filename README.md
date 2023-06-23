![Idelium](https://idelium.io/assets/images/idelium.png)

# Idelium Automation Server

Idelium AS is the tool that allows you to configure your tests. You can define your project, define your steps, compose your testcase.

Once you have configured what you want to test, you are ready to run your test using idelium-cli.

For more info: [https://idelium.io](https://idelium.io)

## idelium-api

Idelium-api is the server component of Idelium. To install it in your environment, you need to have the following prerequisites:

1. Apache 2.4 ([https://httpd.apache.org/](https://httpd.apache.org/))
2. PHP 8.1 ([https://www.php.net/](https://www.php.net/))
3. composer 2.x ([https://getcomposer.org/](https://getcomposer.org/))
4. Database: 
	*  	MySQL
	*   PostgeAQL,
	*   SQLite
	*   SQLServer 

## Installation of Idelium Api

launch this commands:

    composer install
    php artisan migrate
    php artisan db:seed
    rm idelium.ok

## Configuration

Idelium Api is develoed in php with Laravel 8.1 for the FE u can use Apache o Nginx:

[https://laravel.com/docs/5.0/configuration#pretty-urls](https://laravel.com/docs/5.0/configuration#pretty-urls)

The database configuration is described in .env file, and this moment is configured to work into docker ([https://github.com/idelium/idelium-docker](https://github.com/idelium/idelium-docker))


For any help write to: [https://idelium.io/#contact](https://idelium.io/#contact)