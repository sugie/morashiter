# Morashiter Mac 開発環境

この環境は既存の `compose.yaml` と独立した `mrst-dev` プロジェクトです。Laravel 13 の API と管理 Web は `services/mrst` の単一アプリで開発します。Web は FrankenPHP / PHP 8.5、DB は PostgreSQL 17、通常接続は PgBouncer の transaction pooling を通します。msnc ワーカーにだけ Python と Beautiful Soup を入れています。

## 初回

リポジトリ直下で `.env.mrst.example` を `.env.mrst` にコピーし、Mac の `id -u` / `id -g`、`php artisan key:generate --show` で作ったアプリキー、ランダムな DB パスワードを設定します。`.env.mrst` と `services/mrst/.env` は Git に含めません。現在の Mac には両ファイルを作成済みです。

```sh
docker compose --env-file .env.mrst -f docker-compose.mrst.yml build app msnc scheduler
docker compose --env-file .env.mrst -f docker-compose.mrst.yml up -d db pgbouncer app
docker compose --env-file .env.mrst -f docker-compose.mrst.yml exec -e DB_HOST=db -e DB_PORT=5432 app php artisan migrate --force
```

Web は `http://localhost:6554`、PostgreSQL のホスト用接続は `127.0.0.1:6552` です。ホスト公開はいずれも loopback のみです。アプリが使う DB は `pgbouncer:6432` で、マイグレーションは上記のように直接 `db:5432` を使用します。固定コンテナ IP は `172.30.150.0/24` 内の `.2` (DB)、`.4` (Web)、`.6` (PgBouncer)、`.7` (msnc)、`.8` (scheduler)、`.9` (Node) です。

## オプションのプロセス

```sh
docker compose --env-file .env.mrst -f docker-compose.mrst.yml --profile crawler up -d msnc scheduler
docker compose --env-file .env.mrst -f docker-compose.mrst.yml --profile assets run --rm --no-deps node npm install
docker compose --env-file .env.mrst -f docker-compose.mrst.yml --profile assets up -d node
```

`msnc` は今は標準の database queue の `msnc` キューを待機するだけです。クローラーの業務ロジックはまだありません。Python はワーカー内の `/opt/msnc-venv/bin/python` で呼び出します。Vite のホストポートは 6557 です。

## 確認と停止

```sh
docker compose --env-file .env.mrst -f docker-compose.mrst.yml ps
docker compose --env-file .env.mrst -f docker-compose.mrst.yml exec app php artisan about
docker compose --env-file .env.mrst -f docker-compose.mrst.yml --profile crawler run --rm --no-deps msnc python -c 'import bs4, lxml; print(bs4.__version__)'
docker compose --env-file .env.mrst -f docker-compose.mrst.yml down
```

DB データは `mrst_pgdata` ボリュームに残ります。通常停止で `down -v` は使いません。本番 API1 の Docker、LB、DNS、DB はこの Compose から変更しません。
