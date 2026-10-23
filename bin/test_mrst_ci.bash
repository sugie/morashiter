#!/usr/bin/env bash
# Tests bin/mrst-ci-test.bash with docker replaced by a recording fake.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
log="$tmp/calls.log"
seen="$tmp/seen.log"

# FAKE_FAIL_ON: fail any call whose arguments contain this text.
# FAKE_PHPUNIT_STATUS: exit status of the phpunit run (writes a fixed JUnit report either way).
cat > "$tmp/docker" <<EOF
#!/usr/bin/env bash
{ printf '%s' "\$*" | tr '\n' ' '; echo; } >> "$log"
if [[ -n "\${FAKE_FAIL_ON:-}" && "\$*" == *"\$FAKE_FAIL_ON"* ]]; then exit 1; fi
if [[ "\$*" == *"vendor/bin/phpunit"* ]]; then
  {
    printf 'source=%s\n' "\$MRST_CI_SOURCE"
    [[ -f "\$MRST_CI_SOURCE/mrst/phpunit.xml" ]] && echo 'has phpunit.xml'
    [[ -f "\$MRST_CI_SOURCE/mrst/tests/TestCase.php" ]] && echo 'has TestCase.php'
    [[ -e "\$MRST_CI_SOURCE/mrst/vendor" ]] && echo 'has vendor'
    [[ -e "\$MRST_CI_SOURCE/mrst/.env" ]] && echo 'has .env'
    [[ -f "\$MRST_CI_SOURCE/msnc-python/src/extractor.py" ]] && echo 'has extractor'
    [[ -n "\${MRST_CI_DB_PASSWORD:-}" ]] && printf 'password=%s\n' "\$MRST_CI_DB_PASSWORD"
    [[ "\${MRST_CI_APP_KEY:-}" == base64:* ]] && echo 'has app key'
  } > "$seen"
  mkdir -p "\$MRST_CI_SOURCE/mrst/storage/logs"
  printf '%s\n' '<?xml version="1.0" encoding="UTF-8"?>' '<testsuites>' \
    '  <testsuite name="mrst" tests="12" assertions="34" errors="0" failures="1" skipped="2" time="1.5">' \
    '  </testsuite>' '</testsuites>' > "\$MRST_CI_SOURCE/mrst/storage/logs/junit.xml"
  exit "\${FAKE_PHPUNIT_STATUS:-0}"
fi
EOF
chmod +x "$tmp/docker"
export PATH="$tmp:$PATH"

fail() { echo "FAIL: $*" >&2; exit 1; }
run() { : > "$log"; rm -f "$seen"; out=$(bash "$root/bin/mrst-ci-test.bash" 2>&1) && status=0 || status=$?; }
line_of() { grep -n -- "$1" "$log" | head -1 | cut -d: -f1; }

# 1. A passing run builds, waits for db and fixture-news, checks both, installs, tests, then removes only its own project.
MRST_CI_PROJECT=mrst-ci-t1 run
[[ $status == 0 ]] || fail "passing run exited $status: $out"
grep -v -- '-p mrst-ci-t1 ' "$log" && fail "a docker call did not target the run's own project"
up=$(line_of 'up -d --wait'); check=$(line_of 'feed.xml'); install=$(line_of 'composer install'); test=$(line_of 'vendor/bin/phpunit'); down=$(line_of 'down -v')
[[ -n "$up" && -n "$check" && -n "$install" && -n "$test" && -n "$down" ]] || fail "missing a step: $(cat "$log")"
grep -q -- 'up -d --wait --wait-timeout [0-9]* db fixture-news' "$log" || fail "did not wait for db and fixture-news"
(( up < check && check < install && install < test && test < down )) || fail "steps out of order: $(cat "$log")"
grep -q -- 'down -v --remove-orphans --rmi local' "$log" || fail "cleanup leaves the images this run built"
grep -q -- 'vendor/bin/phpunit --fail-on-skipped' "$log" || fail "skipped tests would not fail the run"
grep -q -- 'composer install --no-interaction' "$log" || fail "composer install is not non-interactive"
grep -q 'has phpunit.xml' "$seen" && grep -q 'has TestCase.php' "$seen" && grep -q 'has extractor' "$seen" || fail "source copy is incomplete: $(grep -v "^password=" "$seen")"
grep -q 'has vendor\|has .env' "$seen" && fail "source copy carried local vendor or .env: $(grep -v "^password=" "$seen")"
grep -q 'has app key' "$seen" || fail "APP_KEY was not generated"
password=$(sed -n 's/^password=//p' "$seen")
(( ${#password} >= 32 )) || fail "generated DB password is missing or short"
grep -qF -- "$password" "$log" && fail "the DB password appeared in docker arguments"
[[ "$out" == *"$password"* ]] && fail "the DB password was printed"
source_dir=$(sed -n 's/^source=//p' "$seen")
[[ -e "$source_dir" ]] && fail "temporary source copy was left behind: $source_dir"
[[ "$out" == *'tests=12 assertions=34 failures=1 errors=0 skipped=2'* ]] || fail "JUnit summary missing: $out"

# 2. Each run gets a fresh password and a project name of its own.
first_password=$password
run
[[ $status == 0 ]] || fail "default-project run exited $status: $out"
grep -q -- '-p mrst-ci-[0-9a-z-]* ' "$log" || fail "default project name is not mrst-ci-*"
[[ "$(sed -n 's/^password=//p' "$seen")" != "$first_password" ]] || fail "DB password was reused"

# 3. db or fixture-news not becoming ready stops before installing or testing, and still cleans up.
FAKE_FAIL_ON='up -d --wait' MRST_CI_PROJECT=mrst-ci-t3 run
[[ $status != 0 ]] || fail "unready services were accepted"
grep -q 'composer install\|vendor/bin/phpunit' "$log" && fail "continued after services were not ready"
grep -q 'down -v' "$log" || fail "did not clean up after a failed start"

# 4. A failed fixture-news/database check stops before testing, so the live tests cannot just be skipped.
FAKE_FAIL_ON='feed.xml' MRST_CI_PROJECT=mrst-ci-t4 run
[[ $status != 0 ]] || fail "failed readiness check was accepted"
grep -q 'vendor/bin/phpunit' "$log" && fail "ran PHPUnit after the readiness check failed"
[[ "$out" == *'not ready'* ]] || fail "readiness failure was not explained: $out"
grep -q 'down -v' "$log" || fail "did not clean up after a failed check"

# 5. A dependency install failure stops the run.
FAKE_FAIL_ON='composer install' MRST_CI_PROJECT=mrst-ci-t5 run
[[ $status != 0 ]] || fail "failed composer install was accepted"
grep -q 'vendor/bin/phpunit' "$log" && fail "ran PHPUnit without dependencies"

# 6. PHPUnit's failure status reaches the caller, with the summary and cleanup still done.
FAKE_PHPUNIT_STATUS=1 MRST_CI_PROJECT=mrst-ci-t6 run
[[ $status == 1 ]] || fail "PHPUnit failure exited $status instead of 1"
[[ "$out" == *'tests=12'* ]] || fail "no summary after a failure: $out"
grep -q 'down -v' "$log" || fail "did not clean up after a test failure"

# 7. Project names that could reach other Compose projects (mrst-dev, mrst-prod) are refused before any docker call.
for name in mrst-dev mrst-prod mrst-ci 'mrst-ci-x;rm' MRST-CI-1; do
  MRST_CI_PROJECT=$name run
  [[ $status != 0 ]] || fail "project name '$name' was accepted"
  [[ -s "$log" ]] && fail "docker was called for project name '$name'"
done

echo 'PASS: mrst-ci-test (docker mocked)'
