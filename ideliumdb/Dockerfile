FROM mariadb:10.6

ENV MYSQL_ROOT_PASSWORD ideliumcert
ENV MYSQL_USER ideliumadmin
ENV MYSQL_PASSWORD idelium
ENV MYSQL_DATABASE ideliumdb

COPY ./sql /docker-entrypoint-initdb.d/
