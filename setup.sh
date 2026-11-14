#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "=========================================================="
echo "    Brixel ERP — one-command setup"
echo "=========================================================="

# 1. Environment file
if [ ! -f .env ]; then
    echo "→ Creating .env from .env.example"
    cp .env.example .env
fi

# 2. Self-signed certificate for the local HTTPS listener
if [ ! -f docker/ssl/default.crt ]; then
    echo "→ Generating self-signed SSL certificate"
    mkdir -p docker/ssl
    SUBJECT="/C=PK/ST=Sindh/L=Karachi/O=Brixel Technologies/CN=localhost"
    if command -v openssl >/dev/null 2>&1; then
        openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
            -keyout docker/ssl/default.key -out docker/ssl/default.crt -subj "$SUBJECT" 2>/dev/null
    else
        # No openssl on the host: generate the certificate in a throwaway container.
        docker run --rm -v "$PWD/docker/ssl:/ssl" alpine:3 sh -c \
            "apk add --no-cache openssl >/dev/null && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 -keyout /ssl/default.key -out /ssl/default.crt -subj '$SUBJECT' 2>/dev/null"
    fi
fi

# 3. Containers
echo "→ Building and starting containers"
docker compose up -d --build

echo "→ Waiting for PostgreSQL"
for i in $(seq 1 30); do
    if docker compose exec -T postgres pg_isready -U erp_app -d erp_app >/dev/null 2>&1; then
        echo "  ✓ database is ready"
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "  ✗ database did not become ready in time" >&2
        exit 1
    fi
    sleep 2
done

# 4. Backend dependencies
echo "→ Installing Composer dependencies"
docker compose exec -T app composer install --prefer-dist --optimize-autoloader --no-interaction

# 5. Application key (only when missing, so re-running never invalidates sessions/tokens)
if ! grep -q '^APP_KEY=base64:' .env; then
    echo "→ Generating application key"
    docker compose exec -T app php artisan key:generate --force
fi

# 6. Frontend
echo "→ Installing Node dependencies"
docker compose exec -T node npm install --no-audit --no-fund
echo "→ Building frontend (React + DevExtreme)"
docker compose exec -T node npm run build

# 7. Database
echo "→ Running migrations"
docker compose exec -T app php artisan migrate --force
echo "→ Seeding reference and demo data"
docker compose exec -T app php artisan db:seed --force

docker compose exec -T app php artisan storage:link --force >/dev/null 2>&1 || true
docker compose exec -T app php artisan optimize:clear >/dev/null 2>&1 || true

# 8. Workers started before vendor/ existed; restart them now that the app can boot
docker compose restart queue scheduler reverb nginx >/dev/null

echo ""
echo "=========================================================="
echo "  ✓ Brixel ERP is ready"
echo "=========================================================="
echo "  Web application : http://localhost:8090"
echo "  API base URL    : http://localhost:8090/api/v1"
echo "  PostgreSQL      : localhost:35432  (erp_app / erp_app_password)"
echo "  Redis           : localhost:36379"
echo ""
echo "  Login           : admin@brixelltd.com"
echo "  Password        : BrixelAdmin2026!   (change it after first login)"
echo "=========================================================="
