#!/usr/bin/sh
if [ ! -f /var/idelium.ok ]; then
    echo "I'm here"
    cd /tmp/idelium-web
    npm install
    npm run build
    mv dist/* /usr/local/apache2/htdocs/
    cd /tmp
    rm -fr /tmp/idelium-web
    touch /var/idelium.ok
fi
exit 0