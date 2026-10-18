#!/usr/bin/env bash
# Build the production mrst web and worker images from the current commit.
# Default: build only. --push: also push to the private registry and print
# digest-pinned references for /etc/mrst/compose.env on api1.
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

push=false
for arg in "$@"; do
  case "$arg" in
    --push) push=true ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo 'Refusing to build: the working tree has uncommitted changes. Commit first so the tag matches the code.' >&2
  exit 1
fi

registry="${MRST_REGISTRY:-ghcr.io/sugie}"
tag=$(git rev-parse --short=12 HEAD)
web="$registry/mrst-web:$tag"
worker="$registry/mrst-worker:$tag"

build() {
  docker build --platform linux/amd64 -f docker_settings/mrst/php/Dockerfile.prod --target "$1" -t "$2" .
}
build web-prod "$web"
build worker-prod "$worker"

if [[ "$push" != true ]]; then
  echo "Built $web and $worker (not pushed). Run again with --push to publish."
  exit 0
fi

docker push "$web"
docker push "$worker"
echo "Pushed. Put these lines in /etc/mrst/compose.env on api1:"
echo "MRST_WEB_IMAGE=$(docker image inspect --format '{{index .RepoDigests 0}}' "$web")"
echo "MRST_WORKER_IMAGE=$(docker image inspect --format '{{index .RepoDigests 0}}' "$worker")"
