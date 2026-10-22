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

# O Apache não lê variável de ambiente no VirtualHost: precisa do número
# literal. Sem isso o contêiner sobe, mas nenhuma porta abre no Render.
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/\${PORT}/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Atualiza o schema sem apagar nada
php artisan migrate --force

# Preparar e as planilhas podem demorar (base vazia / Excel grande). Em
# primeiro plano o Render corta o deploy por timeout de porta. Falha aqui
# não derruba o Apache.
(
    php artisan hc:preparar || true
    runuser -u www-data -- php artisan db:seed --class=ImportEmpresasSeeder --force || true
    if [ -f /app/storage/app/colaboradores.xlsx ]; then
        runuser -u www-data -- php artisan hc:importar-colaboradores || true
    fi
) &

# O agendador roda ao lado do Apache
runuser -u www-data -- php artisan schedule:work &

# Inicia o servidor Apache
exec apache2-foreground
