#!/usr/bin/env bash
# Upload the verified static site to the private Sakura object storage bucket behind Web Accelerator.
# Default is a dry run. Pass --upload to send files; add --indexable only for the general public release.
set -euo pipefail
cd "$(dirname "$0")/.."

upload=false
verify_args=()
for arg in "$@"; do
  case "$arg" in
    --upload) upload=true ;;
    --indexable) verify_args+=(--indexable) ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

site_dir="${MORASHITER_SITE_DIR:-dist-static}"
node scripts/verify-static.mjs "$site_dir" "${verify_args[@]}"
release_id=$(node -e 'process.stdout.write(JSON.parse(require("fs").readFileSync(process.argv[1], "utf8")).releaseId)' "$site_dir/release.json")

assets=()
while IFS= read -r file; do assets+=("$file"); done < <(cd "$site_dir" && find assets -type f | sort)
# HTML goes last so visitors never receive a page whose assets are not uploaded yet.
others=(favicon.svg robots.txt release.json index.html)

if [[ "$upload" != true ]]; then
  echo "Dry run for release $release_id. Upload order:"
  printf '  %s\n' "${assets[@]}" "${others[@]}"
  echo 'Run again with --upload after setting MORASHITER_BUCKET, MORASHITER_S3_ENDPOINT, MORASHITER_AWS_PROFILE and MORASHITER_S3_REGION.'
  exit 0
fi

for name in MORASHITER_BUCKET MORASHITER_S3_ENDPOINT MORASHITER_AWS_PROFILE MORASHITER_S3_REGION; do
  if [[ -z "${!name:-}" ]]; then
    echo "$name is not set; nothing was uploaded." >&2
    exit 1
  fi
done

aws_args=(--profile "$MORASHITER_AWS_PROFILE" --region "$MORASHITER_S3_REGION" --endpoint-url "$MORASHITER_S3_ENDPOINT")

put() {
  local file=$1 content_type=$2 cache_control=$3
  aws "${aws_args[@]}" s3 cp "$site_dir/$file" "s3://$MORASHITER_BUCKET/$file" \
    --content-type "$content_type" --cache-control "$cache_control" --acl private --no-progress
}

for file in "${assets[@]}"; do
  case "$file" in
    *.js) put "$file" 'text/javascript; charset=utf-8' 'public, max-age=31536000, immutable' ;;
    *.css) put "$file" 'text/css; charset=utf-8' 'public, max-age=31536000, immutable' ;;
  esac
done
put favicon.svg 'image/svg+xml' 'public, max-age=86400'
put robots.txt 'text/plain; charset=utf-8' 'public, max-age=300'
put release.json 'application/json; charset=utf-8' 'no-store'
put index.html 'text/html; charset=utf-8' 'public, max-age=300'

echo "Uploaded release $release_id. Purge the Web Accelerator cache, then confirm https://www.morashiter.com/release.json shows this release."
