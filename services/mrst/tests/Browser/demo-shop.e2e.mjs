// Optional browser check. Supply PLAYWRIGHT_MODULE pointing at an existing Playwright installation.
// Start the local Laravel demo with a placeholder client ID. No real AI or PayPal request is sent.
import assert from 'node:assert/strict';
import { readFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
const base = process.env.DEMO_TEST_URL || 'http://aozora-zakka.localhost:8765/demo-shop';
assert.equal(new URL(base).hostname, 'aozora-zakka.localhost');
const fixture = JSON.parse(await readFile(new URL('../fixtures/demo-shop/scenario.json', import.meta.url)));
const shots = process.env.DEMO_SCREENSHOTS || 'storage/app/demo-browser-check';
await mkdir(shots, {recursive:true});
const browser = await chromium.launch({channel:process.env.PLAYWRIGHT_CHANNEL || 'chromium',headless:true});
const context = await browser.newContext({viewport:{width:1440,height:1080}});
const page = await context.newPage();
page.setDefaultTimeout(15000);
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const calls = [];
let memoFails = false;
let capturePending = false;
const mockOrder = {id:'44444444-4444-4444-8444-444444444444', paypal_order_id:'DEMO-ORDER-001', amount:'24.00', currency:'USD'};
await context.route('**/*', async route => {
  const request = route.request();
  const url = new URL(request.url());
  if (url.href === 'https://www.sandbox.paypal.com/web-sdk/v6/core') {
    return route.fulfill({contentType:'application/javascript', body:`
      customElements.define('paypal-button', class extends HTMLElement { connectedCallback() {
        this.style.cssText='display:block;background:#ffc439;border-radius:4px;padding:12px 20px;text-align:center;font:600 14px system-ui;cursor:pointer';
        this.textContent='PayPal · Sandbox test'; this.tabIndex=0; this.setAttribute('role','button');
      }});
      window.paypal = {createInstance: async () => ({
        findEligibleMethods: async () => ({isEligible: () => true}),
        createPayPalOneTimePaymentSession: callbacks => ({
          hasReturned: () => false,
          start: async (_, promise) => { const result = await promise;
            if (window.demoCancel) callbacks.onCancel({}); else await callbacks.onApprove(result);
          }
        })
      })};`});
  }
  if (url.origin !== new URL(base).origin) return route.abort();
  if (request.method() !== 'POST') return route.continue();
  calls.push({path:url.pathname, body:request.postDataJSON()});
  if (url.pathname.endsWith('/memo')) return route.fulfill({status:memoFails?503:200, json:memoFails?{error:'memo_unavailable'}:{...fixture.memo_valid, sources:fixture.notices.map(n=>({id:n.id,path:n.path,date:n.publishedOn}))}});
  if (url.pathname.endsWith('/orders')) return route.fulfill({status:201,json:{...mockOrder,status:'created'}});
  if (url.pathname.endsWith('/capture')) return route.fulfill({status:capturePending?202:200,json:{...mockOrder,status:capturePending?'pending':'completed',paypal_capture_id:'DEMO-CAPTURE-001'}});
  if (url.pathname.endsWith('/reconcile')) return route.fulfill({json:{...mockOrder,status:'completed',paypal_capture_id:'DEMO-CAPTURE-001'}});
  return route.continue();
});

try {
  await page.goto(base,{waitUntil:'networkidle',timeout:120000});
  await page.locator('#paypal-button').waitFor({state:'visible'});
  const withoutCsrf = await context.request.post(`${base}/orders`, {data:fixture.requests.create,headers:{Accept:'application/json'}});
  assert.equal(withoutCsrf.status(),419,'real Laravel route rejects missing CSRF token');
  assert.equal(calls.length,0,'page load must not generate a memo or order');
  await page.screenshot({path:path.join(shots,'store-desktop.png'),fullPage:true});
  await page.locator('#defer').click();
  assert.equal(calls.length,0,'deferral must not call an API');
  await page.locator('#generate-memo').click();
  await page.locator('#memo-content').waitFor({state:'visible'});
  assert.match(await page.locator('#memo-content').innerText(),/What remains unknown/);
  assert.equal(await page.locator('#memo-content a').count(),2);
  assert.equal(calls.filter(c=>c.path.endsWith('/orders')).length,0,'AI must not order');
  await page.screenshot({path:path.join(shots,'memo-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'mobile overflow');
  await page.screenshot({path:path.join(shots,'memo-mobile.png'),fullPage:true});
  await page.locator('#paypal-button').click();
  await page.locator('#receipt').waitFor({state:'visible'});
  assert.match(await page.locator('#receipt').innerText(),/USD 24.00/);
  assert.equal(calls.filter(c=>c.path.endsWith('/capture')).length,1);
  await page.reload({waitUntil:'networkidle'});
  await page.locator('#receipt').waitFor({state:'visible'});
  assert.equal(calls.filter(c=>c.path.endsWith('/capture')).length,1,'reload must not capture again');
  await page.evaluate(()=>sessionStorage.clear());
  memoFails=true; capturePending=true;
  await page.reload({waitUntil:'networkidle'});
  await page.locator('#generate-memo').click();
  await page.waitForFunction(()=>document.getElementById('memo-status').textContent.includes('unavailable'));
  await page.locator('#paypal-button').click();
  await page.waitForFunction(()=>document.getElementById('payment-status').textContent.includes('pending'));
  assert.equal(await page.locator('#receipt').isVisible(),false,'pending is not success');
  await page.locator('#reconcile').click();
  await page.locator('#receipt').waitFor({state:'visible'});
  await page.screenshot({path:path.join(shots,'receipt-mobile.png'),fullPage:true});
  await page.evaluate(()=>sessionStorage.clear());
  await page.reload({waitUntil:'networkidle'});
  await page.evaluate(()=>{window.demoCancel=true;});
  const captureCount=calls.filter(c=>c.path.endsWith('/capture')).length;
  await page.locator('#paypal-button').click();
  await page.waitForFunction(()=>document.getElementById('payment-status').textContent.includes('closed'));
  assert.equal(calls.filter(c=>c.path.endsWith('/capture')).length,captureCount,'cancel must not capture');
  assert.deepEqual(errors,[]);
  console.log('PASS: real CSRF guard; desktop/mobile, AI, deferral, success, reload, outage, pending reconciliation, cancellation with mock provider APIs.');
} finally { await browser.close(); }
