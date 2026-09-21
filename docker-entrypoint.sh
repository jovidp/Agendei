#!/bin/sh
# Ajusta o Apache para a porta que a plataforma injeta (Render/Koyeb usam $PORT).
set -e
PORTA="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORTA}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${PORTA}>#" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
