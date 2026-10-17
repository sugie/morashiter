#!/usr/bin/env bash
set -euo pipefail
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
cat > "$tmp/docker" <<'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*"
EOF
chmod +x "$tmp/docker"
export PATH="$tmp:$PATH"
for pair in 'mrsta app' 'mrstn msnc' 'mrstd db' 'mrsts scheduler'; do
    read -r command service <<< "$pair"
    actual=$(bash "$root/bin/$command")
    [[ "$actual" == "container exec -it mrst-dev-$service-1 bash" ]]
done
cd "$tmp"
for pair in 'launch_docker_1.bash app' 'launch_docker_2_scheduler.bash msnc scheduler'; do
    read -r script services <<< "$pair"
    actual=$(bash "$root/bin/$script")
    [[ "$actual" == "compose --env-file .env.mrst -f docker-compose.mrst.yml --profile crawler up -d $services" ]]
done
echo 'PASS: shell entry commands and launch commands (Docker mocked)'
