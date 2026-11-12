# Dedicated hosted judge demo

The public endpoint is `https://demo.morashiter.com/demo-shop`. Deploy it as its own Compose project, database, storage volume, app key, and session cookie. Do not enable crawlers or connect it to the production database.

1. Build the existing `docker_settings/mrst/php/Dockerfile.prod` target `web-prod` from a reviewed checkout. Push to your private registry and record its immutable digest. The Docker ignore file excludes `.env`, dependencies, tests, runtime logs, and private storage.
2. Copy `docker-compose.demo-shop.prod.yml` and its relative `docker_settings/mrst/frankenphp/Caddyfile.demo` into the deployment directory. Set `DEMO_WEB_IMAGE` to the immutable digest. Set the bind address and port to an interface reachable only by your load balancer. The default bind address is loopback.
3. Prepare root-readable-only `/etc/mrst-demo/app.env` from `docker_settings/mrst/demo.env.example`. Supply a fresh app key and dedicated DB password, Sandbox app credentials, and AI credentials. Specify only actual trusted proxy networks. Leave the admin allowlist empty. Do not print or commit this file.
4. Prepare root-readable-only `/etc/mrst-demo/db.env` with `POSTGRES_DB=aozora_demo`, `POSTGRES_USER=aozora_demo`, and the same dedicated `POSTGRES_PASSWORD`. The DB publishes no host port.
5. Start the DB, run `php artisan migrate --force --no-interaction` using the app image, and start the app. No production migration is involved. Persist the demo DB and `/app/storage`, including sessions, cache, and payment recovery state.
6. Add a dedicated load-balancer server group and an exact host rule for the demo. Keep existing host routes unchanged. Add DNS and an automatically renewed valid HTTPS certificate. Allow at least 30 seconds for origin responses: the AI timeout is 20 seconds and PayPal token plus order requests may take up to 20 seconds.
7. Verify HTTPS, the real AI memo, PayPal approval/capture/reconciliation, and extension 0.2.1. Verify `/admin/login`, unapproved hosts, and direct public access to the origin port are denied. Confirm existing services remain healthy.

The Caddy configuration serves only the demo routes, demo assets, and health endpoint; it redirects the dedicated host's `/` to `/demo-shop`. PHP runs without root, with a read-only root filesystem, bounded resources, and rotated container logs. Use secure cookies and HTTPS forwarding from the trusted load balancer.

Keep judge access available through the end of judging. Preserve the previous immutable image digest for rollback. Stop or replace only this Compose project; never delete its DB volume to resolve an uncertain payment. Runtime credentials stay outside the public repository and container image.
