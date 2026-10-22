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

# Porta literal: o Apache não expande variável de ambiente no VirtualHost.
# Sem a porta aberta cedo, o Render corta o deploy (port scan timeout).
PORT="${PORT:-10000}"
printf 'Listen %s\n' "${PORT}" > /etc/apache2/ports.conf
cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@localhost
    ServerName localhost
    DocumentRoot /app/public

    <Directory /app/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF
a2ensite 000-default >/dev/null 2>&1 || true
apache2ctl configtest

# Abre a porta imediatamente — migrate/preparar podem demorar minutos.
apache2-foreground &
APACHE_PID=$!

(
    php artisan migrate --force || true
    php artisan hc:preparar || true
    runuser -u www-data -- php artisan db:seed --class=ImportEmpresasSeeder --force || true
    if [ -f /app/storage/app/colaboradores.xlsx ]; then
        runuser -u www-data -- php artisan hc:importar-colaboradores || true
    fi
) &

runuser -u www-data -- php artisan schedule:work &

wait "${APACHE_PID}"
