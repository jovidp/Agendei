# Imagem de producao do Agendei: PHP 8.2 + Apache, com os drivers dos dois
# bancos (MySQL e PostgreSQL/Supabase). Serve para Render, Koyeb, Fly, etc.
FROM php:8.2-apache

# pdo_pgsql precisa da libpq; pdo_mysql ja vem com o necessario.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql mbstring opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Fora de localhost o sistema ja entra em producao sozinho; a variavel deixa explicito.
ENV AGENDEI_AMBIENTE=producao

# Render, Koyeb e Fly entregam a requisicao por um proxy: sem esta variavel o
# sistema so enxerga o IP interno da plataforma e o controle de forca bruta
# trataria todos os visitantes como uma origem so. Em servidor sem proxy na
# frente, deixe a variavel desligada para nao confiar num cabecalho forjavel.
ENV AGENDEI_PROXY_CONFIAVEL=1

# Reutiliza conexoes PostgreSQL no Apache. O teto de processos limita tambem
# as conexoes persistentes ao pooler e o consumo de memoria no plano de 512 MB.
ENV AGENDEI_DB_PERSISTENTE=1
RUN printf '<IfModule mpm_prefork_module>\nStartServers 2\nMinSpareServers 2\nMaxSpareServers 4\nServerLimit 8\nMaxRequestWorkers 8\nMaxConnectionsPerChild 500\n</IfModule>\n' > /etc/apache2/conf-enabled/agendei-workers.conf

# OPcache: sem ele cada requisicao recompila auth.php, funcoes.php e os models.
# O codigo da imagem nao muda depois do build, entao nao ha por que conferir a
# data dos arquivos a cada acesso (validate_timestamps=0).
RUN printf 'opcache.enable=1\nopcache.memory_consumption=64\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=4000\nopcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/agendei-opcache.ini

# As pastas sensiveis se protegem por .htaccess (Require all denied). O Apache so
# respeita esses arquivos com AllowOverride liberado. Um bloco proprio do docroot
# evita mexer no <Directory /> raiz e vence por ser mais especifico.
RUN printf '\n<Directory /var/www/html/>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

# Copia o codigo. O .dockerignore mantem segredos e lixo de fora da imagem.
COPY . /var/www/html/

# O Apache passa a escutar na porta que a hospedagem definir (Render usa $PORT).
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
