#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Human deployment: run only after configuring the review access restriction.
if [[ "${1:-}" != "--upload" ]]; then
  echo 'Build: npm run build:static'
  echo 'After enabling review access restrictions: bash scripts/upload-sakura.sh --upload'
  exit 0
fi

test -f dist-static/index.html
test -d dist-static/assets
# Only the static asset directory and HTML are allowed in this upload.
AWS_ARGS=(--profile morashiter-deploy --region jp-north-1 --endpoint-url https://s3.isk01.sakurastorage.jp)
aws "${AWS_ARGS[@]}" s3 sync dist-static/assets/ s3://morashiter-site-prod/assets/ \
  --cache-control 'public,max-age=31536000,immutable' --acl private --no-progress
aws "${AWS_ARGS[@]}" s3 cp dist-static/index.html s3://morashiter-site-prod/index.html \
  --content-type 'text/html; charset=utf-8' --cache-control 'public,max-age=300' \
  --acl private --no-progress
echo 'Upload complete. Purge the site cache in the Web Accelerator control panel, then verify HTTPS.'
