#!/bin/sh
# Ajusta o Apache para a porta que a plataforma injeta (Render/Koyeb usam $PORT).
set -e
# O sinalizador so existe depois de a preparacao terminar com sucesso. Assim
# o caminho HTTP nao repete CREATE TABLE/INDEX a cada pagina ou tentativa.
unset AGENDEI_ESTRUTURA_PRONTA
if [ -n "${AGENDEI_DB_HOST:-}" ]; then
    php /var/www/html/scripts/preparar_runtime.php
    export AGENDEI_ESTRUTURA_PRONTA=1
fi
PORTA="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORTA}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${PORTA}>#" /etc/apache2/sites-available/000-default.conf
exec apache2-foreground
