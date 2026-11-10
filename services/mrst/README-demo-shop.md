# Aozora Goods: local Sandbox demonstration

This fictional store connects the unchanged Morashiter Chrome extension, a source-guided AI purchase memo, and buyer-approved PayPal Sandbox checkout. It sells one fictional canvas tote for USD 24.00. Nothing is shipped. The AI cannot place or approve orders.

Status: implementation draft. Local HTTP-fake tests and browser tests are separate from real provider verification. A real AI call, the real PayPal v6 SDK on `.localhost`, and Sandbox buyer approval/capture still require verification before submission. Do not use this branch as evidence of a completed real payment.

## Start a separate local demo

Requirements: Docker Compose, Git, and Chrome. Run these commands from the repository root, using a separate checkout of the implementation branch. This configuration has its own database and binds only to loopback; it starts no crawler or scheduler.

1. Copy `.env.demo-shop.example` to `.env.demo-shop`.
2. Set `DEMO_APP_KEY` to `base64:` followed by 32 random bytes encoded as Base64. Set a separate random `DEMO_DB_PASSWORD`. In Git Bash, generate these with `openssl rand -base64 32` and `openssl rand -hex 24`. Do not commit the resulting file.
3. Initially leave AI disabled and PayPal credentials blank to inspect the shop and notice pages. For the real demo, supply a Sandbox app client ID/secret and its Sandbox business merchant ID. Use a separate Sandbox personal buyer account. Use a demo-only AI key/model and an HTTPS OpenAI-compatible base URL supporting `/chat/completions`, `response_format: json_object`, `temperature`, and `max_tokens`.

```bash
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml build app
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml up -d --wait db
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml run --rm app composer install --no-interaction --prefer-dist
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml run --rm app php artisan migrate --force
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml up -d app
```

Open `http://aozora-zakka.localhost:8765/demo-shop` in Chrome. The route intentionally rejects other hosts. Load the existing extension 0.2.0 from its existing checkout without editing it. The extension's fixed records for `aozora-zakka.localhost` match these fictional notices. A fresh checkout of this draft's base may not contain the separately developed extension yet; use the already tested extension checkout.

After changing configuration, recreate the demo app with the same `up -d app` command. Do not point it at the production database or production credentials. Stop with:

```bash
docker compose --env-file .env.demo-shop -f docker-compose.demo-shop.yml down
```

This retains the demo database volume. Never delete its saved orders while a payment outcome is unknown. Static CSS and JavaScript are served directly; this demo has no Vite build step and adds no package dependency.

## Walkthrough for a reviewer

1. Open the fictional shop. Inspect the extension badge and its two fictional notices. Open both source pages.
2. Click **Create AI purchase memo**. Check the reported facts, linked dated sources, unknowns, and general precautions. The server calls the configured AI provider only after this action.
3. Choose **Save for later**. This records a deferral message on the page and sends no payment request; it does not persist a wishlist.
4. Choose PayPal. The server creates an order with a fixed price and a saved operation key. The PayPal SDK asks the buyer to review and approve. Only the approval callback requests server-side capture.
5. A completed receipt must show `USD 24.00`, a local order ID, and a PayPal capture ID matching the Sandbox dashboard. `PENDING` and unknown outcomes must not show success.
6. Try cancellation. After an interrupted payment, use **Check payment status** on the same page. Do not clear browser storage/cookies or start another order to work around an unknown result.

Orders belong to the current server session. Browser sessionStorage saves the original create key and local order across reloads. Losing the session can make an order inaccessible; manual Sandbox inspection is then required. There is no webhook, refund, fulfilment, or public-host deployment in this draft. A failed or completed order stays closed in this demo tab. Start a separate, deliberate demonstration session only after verifying the prior payment's final outcome.

## AI output contract

The first implementation uses constrained selection: the model selects/orders exact approved English fact sentences from the two fictional notices and exact unknown/precaution sentences. The server verifies every sentence and source ID. It does not accept free-form advice, safety guarantees, HTML, URLs, or payment instructions. This is a deliberate narrowing of the initial free-summary plan, pending a real-provider quality check.

The runtime catalog is `resources/data/demo-shop/catalog.json`; fixed test data is in `tests/fixtures/demo-shop/scenario.json`. Keep the fictional records consistent. Successful memos are cached for 24 hours by input, prompt version, and model. The default daily budget is 50 provider calls (JST), including failed attempts, with 3 requests/minute/session. Use a persistent lock-capable cache (`file` for one local app; a shared store for any future multi-instance deployment). Never use the `array` cache outside tests for this feature. Secrets and raw provider responses are not returned to the browser.

## Verification

From the repository root:

```bash
# Existing disposable PHP 8.5/PostgreSQL fixture environment; never the demo or production DB.
bash bin/mrst-ci-test.bash
node --test services/mrst/tests/Browser/demo-checkout.test.mjs
```

For a targeted run inside that isolated test environment:

```bash
php vendor/bin/phpunit --filter='DemoShopPageTest|PurchaseMemoTest|DemoOrderTest'
```

The optional browser test needs an existing Playwright installation. Set `PLAYWRIGHT_MODULE` to its absolute `index.mjs` path, and optionally `PLAYWRIGHT_CHANNEL=chrome` to use an installed Chrome. Start the local demo with a nonempty placeholder public client ID, then run from `services/mrst`:

```bash
node tests/Browser/demo-shop.e2e.mjs
```

The browser test intercepts the SDK, AI, and order APIs and blocks all other external requests. It checks desktop/mobile rendering, memo/source display, deferral, completion, reload, AI outage, pending/reconciliation, and cancellation. Screenshots go to ignored `storage/app/demo-browser-check`. These tests do **not** prove real-provider interoperability. Do not use the test's synthetic PayPal button or responses in a submission recording.

Remaining before submission: real AI/Sandbox credentials and live integration walkthrough; actual simultaneous-worker and post-provider DB-failure fault injection; extension + new shop walkthrough in ordinary Chrome; reviewer access, English video, repository visibility/license and submission assets. Merge and publication remain human decisions.

References: [PayPal SDK v6 setup](https://developer.paypal.com/sdk/js/set-up), [SDK callbacks](https://developer.paypal.com/sdk/js/reference), [Orders API](https://developer.paypal.com/api/orders/v2), and [implementation plan](../../docs/plans/aozora-ai-paypal-demo-plan.html).
