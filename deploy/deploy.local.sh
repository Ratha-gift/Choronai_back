#!/usr/bin/env bash
#
# deploy.local.sh — deploy Choronai from a Mac/Linux computer.
#
# Like deploy.ps1 but for macOS/Linux: it SSHes into the server and runs
# deploy.sh there. Nothing is built locally.
#
#   Usage:
#     ./deploy.local.sh <server-ip> [user] [path/to/key.pem]
#   Examples:
#     ./deploy.local.sh 1.2.3.4
#     ./deploy.local.sh pos.example.com ubuntu ~/.ssh/choronai.pem
#
set -euo pipefail

SERVER="${1:-}"
USER_NAME="${2:-ubuntu}"
KEY="${3:-}"
BACK_DIR="${BACK_DIR:-~/Choronai-backend}"

if [ -z "$SERVER" ]; then
  echo "Usage: ./deploy.local.sh <server-ip> [user] [path/to/key.pem]"
  exit 1
fi

SSH_OPTS=()
[ -n "$KEY" ] && SSH_OPTS+=(-i "$KEY")

echo "==> Deploying to $USER_NAME@$SERVER ..."
ssh "${SSH_OPTS[@]}" "$USER_NAME@$SERVER" "bash $BACK_DIR/deploy/deploy.sh"
echo "==> Deploy finished."
