# Imagem de producao do Agendei: PHP 8.2 + Apache, com os drivers dos dois
# bancos (MySQL e PostgreSQL/Supabase). Serve para Render, Koyeb, Fly, etc.
FROM php:8.2-apache

# pdo_pgsql precisa da libpq; pdo_mysql ja vem com o necessario.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Fora de localhost o sistema ja entra em producao sozinho; a variavel deixa explicito.
ENV AGENDEI_AMBIENTE=producao

# Render, Koyeb e Fly entregam a requisicao por um proxy: sem esta variavel o
# sistema so enxerga o IP interno da plataforma e o controle de forca bruta
# trataria todos os visitantes como uma origem so. Em servidor sem proxy na
# frente, deixe a variavel desligada para nao confiar num cabecalho forjavel.
ENV AGENDEI_PROXY_CONFIAVEL=1

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
