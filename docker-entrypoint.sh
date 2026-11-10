#!/bin/sh
set -e

cd /var/www/html

# Garante que o arquivo .env exista no container
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Ajusta a porta do Apache caso a variável PORT seja injetada pela plataforma (Render, Railway, Cloud Run, etc.)
LISTEN_PORT="${PORT:-8000}"
sed -i "s/Listen 80/Listen ${LISTEN_PORT}/g" /etc/apache2/ports.conf
sed -i "s/:80>/:${LISTEN_PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Gera APP_KEY caso não tenha sido definida via variável de ambiente
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --no-interaction || true
fi

# Garante permissões de escrita nas pastas de cache e storage
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Executa migrations e seed inicial se RUN_MIGRATIONS=true (padrão: true)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "==> Executando migrations no banco de dados (${DB_CONNECTION:-pgsql})..."
    php artisan migrate --force --no-interaction || echo "Aviso: Banco ainda indisponível ou migrations já aplicadas."
    if [ "${RUN_SEED:-true}" = "true" ]; then
        php artisan db:seed --force --no-interaction || true
    fi
fi

# Otimiza caches do Laravel para produção
php artisan config:clear
php artisan route:clear

echo "==> Iniciando servidor de produção Laravel na porta ${LISTEN_PORT}..."
exec apache2-foreground
