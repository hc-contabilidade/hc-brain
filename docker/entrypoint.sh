#!/bin/bash
set -e

# Prepara diretórios essenciais
mkdir -p database storage/logs storage/framework/sessions storage/framework/views storage/framework/cache/data bootstrap/cache
touch database/database.sqlite

# Limpa caches antigas do bootstrap que possam conter a URL antiga
rm -f bootstrap/cache/*.php

# Ajusta permissões dos diretórios
chown -R www-data:www-data /app/public database storage bootstrap/cache
chmod -R 777 /app/public storage bootstrap/cache database

# Os Secret Files do Render só o root lê, mas o Apache e o agendador rodam
# como www-data: o certificado do MySQL vai para um lugar que todos leem.
if [ -n "$MYSQL_ATTR_SSL_CA" ] && [ -f "$MYSQL_ATTR_SSL_CA" ]; then
    install -m 644 "$MYSQL_ATTR_SSL_CA" /app/storage/mysql-ca.pem
    export MYSQL_ATTR_SSL_CA=/app/storage/mysql-ca.pem
fi

# Configura a porta no Apache
sed -i "s/Listen 80/Listen ${PORT:-10000}/" /etc/apache2/ports.conf

# Atualiza o schema sem apagar nada
php artisan migrate --force

# Semeia só se a base estiver vazia e garante o acesso inicial do ambiente.
php artisan hc:preparar

# A planilha é pesada: importa em segundo plano para o Apache abrir a porta
# dentro do prazo do Render. Uma falha aqui não derruba o contêiner.
runuser -u www-data -- php artisan db:seed --class=ImportEmpresasSeeder --force &

# Colaboradores: se a planilha estiver em storage/app (via deploy), importa em background.
if [ -f /app/storage/app/colaboradores.xlsx ]; then
    runuser -u www-data -- php artisan hc:importar-colaboradores &
fi

# O agendador roda ao lado do Apache
runuser -u www-data -- php artisan schedule:work &

# Inicia o servidor Apache
exec apache2-foreground