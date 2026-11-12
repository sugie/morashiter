# Morashiter

## Try the PayPal AI Hackathon demo

- **Hosted store:** [Aozora Goods](https://demo.morashiter.com/demo-shop) — fictional product, USD 24.00, PayPal Sandbox only.
- **Start here:** [Judge walkthrough](docs/judge-guide.md). No app account, Docker, API key, or server setup is needed for the hosted demo. The dedicated Sandbox buyer login is in Devpost's private testing-credentials field.
- **Chrome extension:** load [`extensions/chrome`](extensions/chrome) as an unpacked extension (version 0.2.1); no build is required. It shows two clearly fictional notices on the hosted demo.
- **Local reproduction:** [English](services/mrst/README-demo-shop.md) / [日本語](services/mrst/README-demo-shop.ja.md).

The AI organizes source facts and cannot approve a purchase. The buyer makes the final decision on PayPal. Nothing is shipped and no real money moves.

A public catalog of corporate incident disclosures (data leaks, unauthorized
access, and similar events), rebuilt from each company's own announcement so
victims and their advisors can see the facts in a consistent shape.

The project pairs a Laravel 13 admin / API with two crawlers — a news feed
scanner (`msnc`, Laravel + Python/BeautifulSoup) that discovers candidate
announcements, and an official-source fetcher (`msic`) that only retrieves
pages from company domains an administrator has registered. An LLM (mock in
development; Sakura AI in production) summarizes each announcement into a
draft for human review. Approved drafts become incident revisions that a
publishing step (`msgn`) renders to a static site.

The Aozora Goods fictional shop demonstrates a source-guided AI purchase memo
and buyer-approved PayPal Sandbox checkout for the PayPal AI Hackathon 2026.
For setup, verified behavior, and remaining limitations, see the demo guide
in [English](services/mrst/README-demo-shop.md) or
[日本語](services/mrst/README-demo-shop.ja.md). No real money moves in the demo.

## Repository layout

```
services/mrst/           Laravel 13 app (admin UI, API, Artisan commands)
workers/msnc-python/     Python extractor (BeautifulSoup, stdin→stdout JSON)
prototype/               Pre-Laravel React/Vite prototype of the public site
docker-compose.mrst.yml  Local dev stack (app, msnc, scheduler, db, pgbouncer)
docs/                    Charter, scope, DDD notes, implementation plans
```

## Prerequisites

- Docker Desktop (or a compatible Docker + Compose v2 setup)
- A `.env.mrst` next to `docker-compose.mrst.yml` with `MRST_UID`,
  `MRST_GID`, `MRST_DB_PASSWORD`, and `MRST_APP_KEY`
  (generate once with `php artisan key:generate --show` after the stack is up).

## Local setup

```bash
# bring up app + db + pgbouncer
bash bin/launch_docker_1.bash

# first run only: copy the example env into the container
docker exec mrst-dev-app-1 cp .env.example .env

# run migrations
docker exec mrst-dev-app-1 php artisan migrate

# create an admin user
docker exec -it mrst-dev-app-1 php artisan admin:create you@example.com --name="You" --activate
```

Admin UI: <http://localhost:6554/admin>. The crawler services (`msnc`,
`scheduler`, `fixture-news`) are opt-in through Compose's `crawler` profile
— see `bin/launch_docker_2_scheduler.bash`.

## Tests

```bash
# PHP (Laravel) — requires the dev stack to be running for DB access
docker exec mrst-dev-app-1 php artisan test

# Python extractor
cd workers/msnc-python && python -m pytest

# Prototype (React/Vite) and static-site tooling
cd prototype && npm test

# Node test for the release-image shell script
bash bin/test_release_images.bash
```

## Project background

- [Project reference map / 関連リポジトリ台帳](docs/project-reference-map.md)
  records the six related local directories, verified Git remotes, access requirements,
  and recovery limits when moving to another PC or cloud environment.
- [Judge README and video production plan](docs/plans/hackathon-judge-readme-video-plan.html)
  describes the English submission materials: the purchase demo first, then the website
  and backend architecture. This is a production plan, not a completed submission.

For the Japanese-language charter, scope, DDD notes, and implementation plans,
see `docs/`:

- [`docs/charter.md`](docs/charter.md) — product charter
- [`docs/mvp-scope.md`](docs/mvp-scope.md) — MVP scope and data boundaries
- [`docs/ddd/`](docs/ddd/) — domain, bounded contexts, architecture rules
- [`docs/architecture/crawler-and-ai.md`](docs/architecture/crawler-and-ai.md)
  — crawler and AI wiring overview

## License

MIT — see [`LICENSE`](LICENSE).
