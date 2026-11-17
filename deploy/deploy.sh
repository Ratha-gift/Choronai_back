#!/usr/bin/env bash
#
# deploy.sh — update Choronai to the latest code (RUN THIS ON THE SERVER).
#
# It pulls both repos, rebuilds the Docker images and runs migrations.
# Safe to run as often as you like; it only restarts what changed.
#
#   Usage (on the server):
#     cd ~/Choronai-backend/deploy && ./deploy.sh
#   or from anywhere:
#     ~/Choronai-backend/deploy/deploy.sh
#
set -euo pipefail

# --- where the two repos live on the server (change if yours differ) ---------
FRONT_DIR="${FRONT_DIR:-$HOME/Choronai-front}"
BACK_DIR="${BACK_DIR:-$HOME/Choronai-backend}"

# docker compose shortcut (same as the README `dc` alias)
dc() {
  docker compose \
    -f "$BACK_DIR/deploy/compose.prod.yaml" \
    --env-file "$BACK_DIR/deploy/.env.production" "$@"
}

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# --- 0. sanity checks --------------------------------------------------------
[ -d "$FRONT_DIR/.git" ] || { echo "Frontend repo not found at $FRONT_DIR"; exit 1; }
[ -d "$BACK_DIR/.git" ]  || { echo "Backend repo not found at $BACK_DIR"; exit 1; }
[ -f "$BACK_DIR/deploy/.env.production" ] || {
  echo "Missing $BACK_DIR/deploy/.env.production — copy .env.production.example and fill it in first."
  exit 1
}

# --- 1. get the latest code --------------------------------------------------
say "Updating frontend ($FRONT_DIR)"
git -C "$FRONT_DIR" pull --ff-only

say "Updating backend ($BACK_DIR)"
git -C "$BACK_DIR" pull --ff-only

# --- 2. rebuild + restart the containers -------------------------------------
say "Building and restarting containers (this can take a few minutes)"
dc up -d --build

# --- 3. run database migrations ----------------------------------------------
say "Running database migrations"
dc exec -T api php artisan migrate --force

# --- 4. show the result ------------------------------------------------------
say "Done. Current containers:"
dc ps

echo
echo "If the site misbehaves, check logs:  ~/Choronai-backend/deploy/deploy.sh is done,"
echo "  cd ~/Choronai-backend && docker compose -f deploy/compose.prod.yaml --env-file deploy/.env.production logs -f --tail 100 api front web"
