#!/usr/bin/env bash
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."
exec docker compose --env-file .env.mrst -f docker-compose.mrst.yml --profile crawler up -d app
