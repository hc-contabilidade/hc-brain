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

# Migrate curto em primeiro plano: a Sofia e o restante precisam das tabelas.
# Se o banco travar, o timeout evita derrubar o prazo da porta do Render.
timeout 45 php artisan migrate --force || true

# Abre a porta; preparar/import podem demorar e ficam em background.
apache2-foreground &
APACHE_PID=$!

(
    php artisan hc:preparar || true
    # --preserve-environment: sem isso o runuser zera DB_*, APP_KEY etc. e o
    # comando falha em silêncio no Render.
    runuser -u www-data --preserve-environment -- php artisan db:seed --class=ImportEmpresasSeeder --force || true
    if [ -f /app/storage/app/colaboradores.xlsx ]; then
        runuser -u www-data --preserve-environment -- php artisan hc:importar-colaboradores || true
    fi
) &

# O agendador precisa ficar vivo: se cair (OOM, exceção), sobe de novo.
# Log em storage/logs/scheduler.log para ver no shell do Render.
mkdir -p /app/storage/logs
touch /app/storage/logs/scheduler.log
chown www-data:www-data /app/storage/logs/scheduler.log
(
    while true; do
        echo "$(date -Is) iniciando schedule:work" >> /app/storage/logs/scheduler.log
        runuser -u www-data --preserve-environment -- php artisan schedule:work >> /app/storage/logs/scheduler.log 2>&1
        code=$?
        echo "$(date -Is) schedule:work saiu com código ${code}; reiniciando em 5s" >> /app/storage/logs/scheduler.log
        sleep 5
    done
) &

wait "${APACHE_PID}"
