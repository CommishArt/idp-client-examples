#!/usr/bin/env bash
set -euo pipefail

COMPOSER="php.exe C:\\ProgramData\\ComposerSetup\\bin\\composer.phar"
CONSOLE="php.exe bin/console"

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

step() { echo -e "\n${YELLOW}==> $1${NC}"; }
ok()   { echo -e "${GREEN}  ✓ $1${NC}"; }

# ─── 1. Docker ───────────────────────────────────────────────────────────────
step "Starting Docker services (PostgreSQL + Redis)"
docker.exe --context default compose up -d

step "Waiting for PostgreSQL to be healthy"
until docker.exe --context default compose exec -T postgres pg_isready -U commish -d idp -q 2>/dev/null; do
    echo "  waiting..."
    sleep 2
done
ok "PostgreSQL is ready"

# ─── 2. App1 ─────────────────────────────────────────────────────────────────
step "Setting up App1 (Admin Panel)"
cd app1
$COMPOSER install --no-interaction
ok "composer install"
$CONSOLE doctrine:database:create --if-not-exists
$CONSOLE doctrine:migrations:migrate --no-interaction
ok "Database migrated"
cd ..

# ─── 3. App2 ─────────────────────────────────────────────────────────────────
step "Setting up App2 (Customer Portal)"
cd app2
$COMPOSER install --no-interaction
ok "composer install"
$CONSOLE doctrine:database:create --if-not-exists
$CONSOLE doctrine:migrations:migrate --no-interaction
ok "Database migrated"
cd ..

# ─── Done ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${GREEN}  ✓ Setup complete.${NC}"
echo ""
echo -e "  Make sure the IDP is running on port 8010 (see CommishArt/idp)."
echo ""
echo "  Then start the client apps:"
echo ""
echo "    symfony.exe serve --dir=app1 --port=8011 --no-tls"
echo "    symfony.exe serve --dir=app2 --port=8012 --no-tls"
echo ""
echo -e "${GREEN}  Test users (loaded by the IDP fixtures):${NC}"
echo "    admin@example.com    / password  → App1 (ROLE_ADMIN) + App2 (ROLE_USER)"
echo "    editor@example.com   / password  → App1 only (ROLE_EDITOR)"
echo "    customer@example.com / password  → App2 only (ROLE_USER)"
echo -e "${GREEN}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
