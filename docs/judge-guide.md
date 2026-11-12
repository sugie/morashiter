# Judge walkthrough

Open **https://demo.morashiter.com/demo-shop** in desktop Chrome. The hosted store needs no application login or API keys. Use the dedicated **PayPal Sandbox Personal buyer** credentials provided in Devpost's private testing field when PayPal asks you to sign in. Do not use a real PayPal account or payment card.

## Optional Chrome extension setup

1. Clone `https://github.com/sugie/morashiter.git`, or use GitHub **Code → Download ZIP** and extract it.
2. Open `chrome://extensions`, enable **Developer mode**, click **Load unpacked**, and select `extensions/chrome` inside the extracted repository. Select the folder containing `manifest.json`.
3. Pin Morashiter, open the hosted store, and click its badge. Version **0.2.1** shows **2** fictional notices for this exact demo hostname. Earlier versions only recognize the local demo.

The popup is currently in Japanese: **架空デモ** means fictional demo; **対策を公表** means measures announced; **調査中** means investigation ongoing. These are reported response states, not a safety score. The hosted shop, AI memo, and source pages are in English. The payment flow also works without installing the extension.

## End-to-end test

1. Read the **FICTIONAL STORE / SANDBOX DEMO / NO REAL CHARGES** banner. The only product is a fictional tote bag priced at **USD 24.00**, with zero shipping and tax.
2. Open the two **Fictional notice** source links. They correspond to the two extension records; no actual merchant is accused by this demo.
3. Click **Create AI purchase memo**. It calls the configured AI provider on the server and shows reported facts, dated source links, unknowns, and general precautions. The server validates the output. A cached valid memo may be reused for 24 hours.
4. Click **Save for later**. It displays a deferral message and sends no payment request. It is not a persistent wishlist.
5. Click **PayPal**. Allow its checkout popup if Chrome blocks it. Sign in with the supplied Sandbox buyer account, confirm **USD 24.00**, and approve the Sandbox purchase yourself.
6. Back at the store, confirm the receipt displays the amount, a **local order ID**, and a **PayPal capture ID**. No goods are shipped and no real funds are charged.
7. If the outcome is pending or unknown, use **Check payment status**. Keep the same tab/browser storage; do not start another purchase to recover an uncertain result. Refreshing the page should restore the local order and reconcile its status.

## Boundaries and troubleshooting

- AI is separate from PayPal: it has no payment tools or buyer credentials. Orders and captures are created by the Laravel server only after the corresponding user actions; the amount and merchant are checked server-side.
- This demonstrates Orders v2 create/approve/capture and recovery, not shipping, refunds, subscriptions, or a webhook-backed production payment service.
- AI has a 50-provider-call daily budget (JST) and a per-session limit of 3 requests/minute. Valid cached memos remain reusable. If temporarily unavailable, read the two original sources and retry later; payment is not automatically authorized.
- For an empty extension badge, confirm version 0.2.1, the exact hostname `demo.morashiter.com`, and that another manually selected demo scenario is not active. Reload the extension and page after an upgrade.
- Chrome is the verified browser. An earlier Edge Sandbox approval attempt did not complete; cross-browser interoperability is not claimed.
- All provider responses in automated tests are fakes. They are not evidence of real-provider interoperability. Manual hosted verification is recorded separately in the submission.

## Reproduce locally or review the implementation

The full [local setup guide](../services/mrst/README-demo-shop.md) uses an isolated Docker database. Local reproduction requires your own PayPal Sandbox business app client ID, secret, merchant ID, and compatible AI credentials. The judge buyer password is not a server API credential. No secrets are included in this public repository.

Key code: `services/mrst/app/Services/DemoShop`, `services/mrst/app/Http/Controllers/DemoShop`, `services/mrst/public/demo-shop-assets`, and `extensions/chrome`. The source is MIT licensed.

Run deterministic tests from the repository root:

```bash
bash bin/mrst-ci-test.bash
node --test "extensions/chrome/tests/*.test.js" services/mrst/tests/Browser/demo-checkout.test.mjs
```

The PHP runner creates and removes its own disposable test database. Never run those tests against the hosted demo or production database.
