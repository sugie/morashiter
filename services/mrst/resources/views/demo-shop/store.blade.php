<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Aozora Goods · Sandbox store</title>
<link rel="stylesheet" href="/demo-shop-assets/store.css">
</head>
<body>
<div class="demo-banner">FICTIONAL STORE <span>·</span> SANDBOX DEMO <span>·</span> NO REAL CHARGES</div>
<header class="site-header"><a class="brand" href="{{ route('demo-shop.index') }}">☁ Aozora Goods</a><span class="tagline">Everyday essentials, considered.</span><span class="cart-label">Your bag <b>1</b></span></header>
<main>
<section class="product"><div class="product-art" role="img" aria-label="Natural canvas tote bag"><div class="bag-handle"></div><div class="bag-body"><span>AOZORA<br><small>EVERYDAY GOODS</small></span></div><div class="product-caption">A little room for the everyday.</div></div>
<p class="eyebrow">THE EVERYDAY COLLECTION</p><h1>{{ $product['name'] }}</h1><p class="muted">Natural cotton · One item · Fictional demo product</p>
<dl class="totals"><div><dt>Subtotal</dt><dd>$24.00</dd></div><div><dt>Demo shipping &amp; tax</dt><dd>$0.00</dd></div><div class="total"><dt>Total</dt><dd><small>USD</small> $24.00</dd></div></dl>
<p class="fine">This is a demonstration. Nothing will be shipped and no real money will move.</p></section>
<section class="review"><p class="eyebrow">A MOMENT BEFORE CHECKOUT</p><h2>Before you pay.</h2><p class="intro">Understand the notices. Decide for yourself.</p>
<div class="notice-strip"><span class="notice-count">2</span><div><strong>Fictional notices available</strong><p>Open the Morashiter extension to see the same demo records.</p></div></div>
<div class="memo-card"><div class="memo-heading"><h3>AI purchase memo</h3><span>Source guided</span></div><p class="muted">Organize the dated demo notices into reported facts, open questions, and general precautions.</p>
<button id="generate-memo" type="button" class="primary">Create AI purchase memo <span>↗</span></button>
<p id="memo-status" role="status" aria-live="polite"></p><div id="memo-content" hidden></div>
<div class="sources"><strong>Read the demo sources</strong>@foreach ($notices as $notice)<a href="{{ route('demo-shop.notice', $notice['id']) }}" target="_blank" rel="noopener">{{ $notice['publishedOn'] }} · Fictional notice ↗</a>@endforeach</div>
<p class="fine">This memo does not rate the store's safety or decide whether you should buy.</p></div>
<div class="decision"><h3>The purchase decision is yours.</h3><p class="muted">You can save this decision for later or review the purchase on PayPal.</p><div class="actions"><button id="defer" type="button" class="secondary">Save for later</button><div id="paypal-container" data-client-id="{{ $clientId }}"><paypal-button id="paypal-button" type="pay" class="paypal-gold" hidden></paypal-button><span id="paypal-loading">Preparing Sandbox checkout…</span></div></div>
<p id="payment-status" role="status" aria-live="polite"></p><button id="reconcile" class="secondary" type="button" hidden>Check payment status</button><div id="receipt" hidden></div><p class="fine">Sandbox checkout. Review and approve on PayPal.</p></div>
</section>
</main><footer>Aozora Goods is fictional. AI and PayPal operate separately; AI cannot authorize a payment.</footer>
<script type="module" src="/demo-shop-assets/store.js"></script>
</body></html>
