#!/usr/bin/env bash
# Tests bin/mrst-release-images.bash with git and docker replaced by recording fakes.
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
log="$tmp/calls.log"

cat > "$tmp/git" <<'EOF'
#!/usr/bin/env bash
case "$*" in
  "status --porcelain"*) [[ "${FAKE_DIRTY:-}" == 1 ]] && echo ' M services/mrst/app/Foo.php' ; exit 0 ;;
  "rev-parse --short=12 HEAD") echo 'abc123def456' ;;
  *) echo "unexpected git $*" >&2; exit 1 ;;
esac
EOF
cat > "$tmp/docker" <<EOF
#!/usr/bin/env bash
printf '%s\n' "\$*" >> "$log"
if [[ "\$1 \$2" == "image inspect" ]]; then
  ref="\${@: -1}"
  echo "\${ref%:*}@sha256:\$(printf '%s' "\$ref" | sha256sum | cut -c1-64)"
fi
EOF
chmod +x "$tmp/git" "$tmp/docker"
export PATH="$tmp:$PATH"

fail() { echo "FAIL: $*" >&2; exit 1; }
run() { : > "$log"; out=$(bash "$root/bin/mrst-release-images.bash" "$@" 2>&1) && status=0 || status=$?; }

# 1. Default run builds both targets for linux/amd64 with commit tags and does not push.
run
[[ $status == 0 ]] || fail "default run exited $status: $out"
grep -q -- 'build --platform linux/amd64 -f docker_settings/mrst/php/Dockerfile.prod --target web-prod -t ghcr.io/sugie/mrst-web:abc123def456 .' "$log" || fail "web build missing: $(cat "$log")"
grep -q -- 'build --platform linux/amd64 -f docker_settings/mrst/php/Dockerfile.prod --target worker-prod -t ghcr.io/sugie/mrst-worker:abc123def456 .' "$log" || fail "worker build missing"
grep -q '^push' "$log" && fail "default run pushed"
grep -q 'latest' "$log" && fail "used a latest tag"
[[ "$out" == *'not pushed'* ]] || fail "default run did not say it skipped the push: $out"

# 2. A dirty working tree is refused before any docker call.
FAKE_DIRTY=1 run --push
[[ $status != 0 ]] || fail "dirty tree was accepted"
[[ -s "$log" ]] && fail "docker was called for a dirty tree"
[[ "$out" == *'uncommitted'* ]] || fail "dirty tree message missing: $out"

# 3. --push pushes both images and prints digest-pinned references for /etc/mrst/compose.env.
run --push
[[ $status == 0 ]] || fail "push run exited $status: $out"
grep -qx 'push ghcr.io/sugie/mrst-web:abc123def456' "$log" || fail "web push missing"
grep -qx 'push ghcr.io/sugie/mrst-worker:abc123def456' "$log" || fail "worker push missing"
[[ "$out" =~ MRST_WEB_IMAGE=ghcr\.io/sugie/mrst-web@sha256:[0-9a-f]{64} ]] || fail "web digest line missing: $out"
[[ "$out" =~ MRST_WORKER_IMAGE=ghcr\.io/sugie/mrst-worker@sha256:[0-9a-f]{64} ]] || fail "worker digest line missing: $out"
last_build=$(grep -n '^build' "$log" | tail -1 | cut -d: -f1)
first_push=$(grep -n '^push' "$log" | head -1 | cut -d: -f1)
(( last_build < first_push )) || fail "pushed before both builds finished"

# 4. The registry namespace can be overridden.
MRST_REGISTRY=ghcr.io/example-org run
grep -q 'ghcr.io/example-org/mrst-web:abc123def456' "$log" || fail "registry override ignored"

# 5. Unknown options are rejected without building.
run --pushh
[[ $status != 0 ]] || fail "unknown option accepted"
[[ -s "$log" ]] && fail "docker was called for an unknown option"

echo 'PASS: mrst-release-images (git and docker mocked)'
