![Idelium](https://idelium.io/assets/images/idelium.png)

# Idelium Automation Server

Idelium AS is the tool that allows you to configure your tests. You can define your project, define your steps, compose your testcase.

Once you have configured what you want to test, you are ready to run your test using [idelium-cli](https://github.com/idelium/idelium-cli).

For more info: https://idelium.io
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