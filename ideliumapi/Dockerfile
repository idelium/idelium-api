FROM php:8.3-apache

RUN apt-get update

RUN apt-get install -y libzip-dev zip unzip

RUN docker-php-ext-install pdo pdo_mysql mysqli zip

RUN curl --insecure https://getcomposer.org/composer.phar -o /usr/bin/composer && chmod +x /usr/bin/composer

RUN curl -sS https://get.symfony.com/cli/installer | bash
RUN mv /root/.symfony5/bin/symfony /usr/bin/symfony
RUN ln -s /etc/apache2/mods-available/rewrite.load /etc/apache2/mods-enabled/rewrite.load
 
COPY conf/idelium-httpd.conf /etc/apache2/sites-enabled/000-default.conf

RUN apt-get install -y supervisor
RUN mkdir -p "/etc/supervisor/logs"

COPY conf/supervisord.conf /etc/supervisor/supervisord.conf
COPY script/configure.sh /tmp/configure.sh

CMD ["/usr/bin/supervisord", "-n", "-c",  "/etc/supervisor/supervisord.conf"]

RUN mkdir -p "/etc/apache2/certs"
RUN apt-get update && \
    apt-get install -y openssl && \
    openssl genrsa -des3 -passout pass:x -out /etc/apache2/certs/server.pass.key 2048 && \
    openssl rsa -passin pass:x -in /etc/apache2/certs/server.pass.key -out /etc/apache2/certs/server.key && \
    rm /etc/apache2/certs/server.pass.key && \
    openssl req -new -key /etc/apache2/certs/server.key -out /etc/apache2/certs/server.csr \
    -subj "/C=UK/ST=Warwickshire/L=Leamington/O=OrgName/OU=IT Department/CN=example.com" && \
    openssl x509 -req -days 365 -in /etc/apache2/certs/server.csr -signkey /etc/apache2/certs/server.key -out /etc/apache2/certs/server.crt
RUN git clone https://github.com/idelium/idelium-api.git /var/idelium-api
RUN chown -R www-data:www-data /var/idelium-api
WORKDIR /var/idelium-api