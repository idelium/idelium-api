FROM httpd:2.4

COPY conf/apache-httpd.conf /usr/local/apache2/conf/httpd.conf
COPY conf/httpd-vhosts-idelium-ssl.conf /usr/local/apache2/conf/extra/httpd-vhosts-idelium-ssl.conf
COPY script/build.sh /tmp/build.sh
COPY html/index.html /usr/local/apache2/htdocs/index.html
RUN mkdir -p "/usr/local/apache2/certs"
RUN apt-get update && \
    apt-get install -y openssl && \
    openssl genrsa -des3 -passout pass:x -out certs/server.pass.key 2048 && \
    openssl rsa -passin pass:x -in certs/server.pass.key -out certs/server.key && \
    rm certs/server.pass.key && \
    openssl req -new -key certs/server.key -out certs/server.csr \
    -subj "/C=UK/ST=Warwickshire/L=Leamington/O=OrgName/OU=IT Department/CN=example.com" && \
    openssl x509 -req -days 365 -in certs/server.csr -signkey certs/server.key -out certs/server.crt
RUN apt-get install -y git curl
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
RUN apt-get install -y nodejs
RUN git clone https://github.com/idelium/idelium-web.git /tmp/idelium-web
RUN chmod 755 /tmp/build.sh
