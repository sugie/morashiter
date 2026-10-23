#!/usr/bin/env bash
# Run the mrst PHPUnit suite in a throwaway Compose project: PHP 8.5, PostgreSQL 17 as "db"
# (fresh mrst_test on tmpfs) and fixture-news, the same way in GitHub Actions and locally.
# Tests a copy of services/mrst and workers/msnc-python (tracked + untracked, minus ignored files
# such as vendor/ and .env). The app runs as the worker-dev image so queued-sync FetchArticle jobs
# can start the Python extractor, as the msnc worker does in production.
# Fails if a service is not ready, dependencies do not install, or any test fails or is skipped.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

project="${MRST_CI_PROJECT:-mrst-ci-$$}"
if [[ ! "$project" =~ ^mrst-ci-[a-z0-9][a-z0-9-]*$ ]]; then
  echo "Refusing project name '$project': it must start with mrst-ci- so mrst-dev and mrst-prod are never touched." >&2
  exit 2
fi
compose=(docker compose -p "$project" -f docker-compose.mrst.ci.yml)
if [[ -n "${MRST_CI_COMPOSE_OVERRIDE:-}" ]]; then
  compose+=(-f "$MRST_CI_COMPOSE_OVERRIDE")
fi

work=$(mktemp -d)
cleanup() {
  local status=$?
  "${compose[@]}" down -v --remove-orphans --rmi local >/dev/null 2>&1 || true
  rm -rf -- "$work"
  exit "$status"
}
trap cleanup EXIT

# Per-run test-only values, passed to Compose through the environment and never printed.
MRST_CI_DB_PASSWORD=$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')
MRST_CI_APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
export MRST_CI_DB_PASSWORD MRST_CI_APP_KEY

copy() {
  mkdir -p "$work/$2"
  git ls-files -z --cached --others --exclude-standard -- "$1" \
    | tar --null --ignore-failed-read -T - -cf - | tar -xf - -C "$work/$2" --strip-components=2
}
copy services/mrst mrst
copy workers/msnc-python msnc-python
MRST_CI_SOURCE=$(cygpath -m "$work" 2>/dev/null || printf '%s' "$work")
MRST_CI_UID=$(id -u)
MRST_CI_GID=$(id -g)
export MRST_CI_SOURCE MRST_CI_UID MRST_CI_GID

"${compose[@]}" build app fixture-news
"${compose[@]}" up -d --wait --wait-timeout 180 db fixture-news

# The live HTTP tests skip when fixture-news is missing; check it (and the database) explicitly first.
if ! "${compose[@]}" run --rm --no-deps app php -r '
  $feed = @file_get_contents("http://fixture-news/feed.xml");
  if ($feed === false || ! str_contains($feed, "<feed")) { fwrite(STDERR, "fixture-news feed is not served\n"); exit(1); }
  new PDO("pgsql:host=db;port=5432;dbname=mrst_test", "mrst_app", getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  echo "db and fixture-news are ready\n";
'; then
  echo 'db or fixture-news is not ready; not running PHPUnit.' >&2
  exit 1
fi

"${compose[@]}" run --rm --no-deps app composer install --no-interaction --no-progress --prefer-dist

"${compose[@]}" run --rm --no-deps app php vendor/bin/phpunit --fail-on-skipped --log-junit storage/logs/junit.xml \
  && phpunit_status=0 || phpunit_status=$?

if [[ -f "$work/mrst/storage/logs/junit.xml" ]]; then
  suite=$(grep -m1 '<testsuite ' "$work/mrst/storage/logs/junit.xml")
  attr() { sed -n "s/.* $1=\"\\([0-9]*\\)\".*/\\1/p" <<< "$suite"; }
  summary="tests=$(attr tests) assertions=$(attr assertions) failures=$(attr failures) errors=$(attr errors) skipped=$(attr skipped)"
  echo "PHPUnit: $summary (exit $phpunit_status)"
  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    echo "mrst PHPUnit: $summary (exit $phpunit_status)" >> "$GITHUB_STEP_SUMMARY"
  fi
fi
exit "$phpunit_status"
