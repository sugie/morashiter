import { Checkout } from './checkout.mjs';

const element = id => document.getElementById(id);
const status = (id, message) => { element(id).textContent = message; };
async function request(path, body) {
  const response = await fetch(`/demo-shop${path}`, {
    method: 'POST', credentials: 'same-origin',
    headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
    body: JSON.stringify(body), signal: AbortSignal.timeout(45000),
  });
  if (!response.ok) throw Error(response.status === 419 ? 'Session expired. Reload this page before continuing.' : 'The request could not be completed. Check the existing order before retrying.');
  return response.json();
}

function listSection(parent, title, items) {
  const heading = document.createElement('h4');
  heading.textContent = title;
  const list = document.createElement('ul');
  for (const text of items) {
    const item = document.createElement('li'); item.textContent = text; list.append(item);
  }
  parent.append(heading, list);
  return list;
}

element('generate-memo').addEventListener('click', async () => {
  element('generate-memo').disabled = true;
  element('memo-content').hidden = true;
  status('memo-status', 'Organizing the fictional notices…');
  try {
    const memo = await request('/memo', {product_id:'TOTE-001', language:'en'});
    const content = element('memo-content'); content.replaceChildren();
    const facts = listSection(content, 'Reported facts', memo.facts.map(f => f.text));
    memo.facts.forEach((fact, index) => {
      for (const id of fact.source_ids) {
        const source = memo.sources.find(source => source.id === id);
        if (!source || !/^\/demo-shop\/notices\/DEMO-NOTICE-\d{3}$/.test(source.path)) continue;
        const link = document.createElement('a'); link.href = source.path;
        link.textContent = ` Source · ${source.date} ↗`; link.target = '_blank'; link.rel = 'noopener';
        facts.children[index].append(link);
      }
    });
    listSection(content, 'What remains unknown', memo.unknowns);
    listSection(content, 'General precautions', memo.general_tips);
    content.hidden = false;
    status('memo-status', 'AI-organized memo · Verified source wording · Fictional data');
  } catch {
    status('memo-status', 'AI memo unavailable. You can still read the sources, save for later, or choose Sandbox checkout.');
  } finally { element('generate-memo').disabled = false; }
});

element('defer').addEventListener('click', () => {
  status('payment-status', 'Decision deferred. No payment request was sent by this action.');
});

let checkout;
const button = element('paypal-button');
function showOrder(order) {
  element('reconcile').hidden = !order?.id || ['completed', 'failed'].includes(order.status);
  const blocked = ['capturing', 'capture_unknown', 'pending', 'completed', 'failed'].includes(order?.status);
  button.hidden = blocked || !window.paypal;
  const messages = {
    completed: 'Sandbox payment completed. No real money moved.',
    pending: 'Payment is pending. Check this order; do not start another payment.',
    capture_unknown: 'Payment result is not yet known. Check this order before taking any further action.',
    create_unknown: 'Order creation is uncertain. The next checkout attempt reuses the same request.',
    created: 'Your Sandbox order is ready. Continue on PayPal to review and approve.',
    failed: 'Payment was not completed. This demo order is closed.',
  };
  if (order) status('payment-status', messages[order.status] || 'Checking the existing order…');
  if (order?.status === 'completed') {
    const receipt = element('receipt'); receipt.replaceChildren();
    listSection(receipt, 'Sandbox receipt', [`${order.currency} ${order.amount}`, `Order: ${order.id}`, `Capture: ${order.paypal_capture_id}`]);
    receipt.hidden = false;
  }
}

element('reconcile').addEventListener('click', async () => {
  element('reconcile').disabled = true;
  try { showOrder(await checkout.reconcile()); }
  catch { status('payment-status', 'Status could not be confirmed. Keep this page and check this same order again later.'); }
  finally { element('reconcile').disabled = false; }
});

async function initializePayPal() {
  checkout = new Checkout(request, sessionStorage);
  // Verify that recovery storage works before permitting any payment request.
  checkout.save();
  showOrder(checkout.state.order);
  const clientId = element('paypal-container').dataset.clientId;
  if (!clientId) {
    status('paypal-loading', 'Sandbox checkout is not configured yet.');
    return;
  }
  await new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://www.sandbox.paypal.com/web-sdk/v6/core';
    script.onload = resolve; script.onerror = reject;
    document.head.append(script);
  });
  const sdk = await window.paypal.createInstance({clientId, components:['paypal-payments']});
  const methods = await sdk.findEligibleMethods({currencyCode:'USD', amount:'24.00'});
  if (!methods.isEligible('paypal')) throw Error('PayPal unavailable');
  const paymentSession = sdk.createPayPalOneTimePaymentSession({
    onApprove: async data => {
      try { showOrder(await checkout.approve(data.orderId)); }
      catch { showOrder(checkout.state.order); status('payment-status', 'The payment result needs checking. Use Check payment status.'); }
    },
    onCancel: () => { showOrder(checkout.state.order); status('payment-status', 'PayPal checkout was closed. This action did not capture a payment.'); },
    onError: () => { showOrder(checkout.state.order); status('payment-status', 'PayPal could not finish. Check the saved order before retrying.'); },
  });
  button.addEventListener('click', async () => {
    if (checkout.busy) return;
    button.hidden = true;
    try { await paymentSession.start({presentationMode:'auto'}, checkout.create()); }
    catch { showOrder(checkout.state.order); status('payment-status', 'Checkout could not finish. Your original request is preserved for recovery.'); }
  });
  status('paypal-loading', '');
  showOrder(checkout.state.order);
  if (paymentSession.hasReturned()) await paymentSession.resume();
}

initializePayPal().catch(() => {
  button.hidden = true;
  status('paypal-loading', 'Sandbox checkout unavailable. Read the sources or save for later.');
});
