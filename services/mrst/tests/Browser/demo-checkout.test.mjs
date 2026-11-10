import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Checkout } from '../../public/demo-shop-assets/checkout.mjs';

function memory(initial = null) {
  let value = initial;
  return { getItem: () => value, setItem: (_key, next) => { value = next; } };
}

test('a lost create response reuses its saved idempotency key', async () => {
  const calls = [];
  const storage = memory();
  const request = async (path, body) => {
    calls.push({path, body});
    if (calls.length === 1) throw Error('connection lost');
    return {id: 'local-order', paypal_order_id: 'paypal-order', status: 'created'};
  };
  const first = new Checkout(request, storage, () => 'stable-key');
  await assert.rejects(first.create());
  const reloaded = new Checkout(request, storage, () => 'wrong-new-key');
  assert.deepEqual(await reloaded.create(), {orderId: 'paypal-order'});
  assert.equal(calls[0].body.client_request_id, calls[1].body.client_request_id);
});

test('unknown payment never creates another order and reconciles the original', async () => {
  const calls = [];
  const request = async (path) => {
    calls.push(path);
    if (path.endsWith('/capture')) return {id:'local-order', paypal_order_id:'paypal-order', status:'capture_unknown'};
    if (path.endsWith('/reconcile')) return {id:'local-order', paypal_order_id:'paypal-order', status:'completed'};
    return {id:'local-order', paypal_order_id:'paypal-order', status:'created'};
  };
  const checkout = new Checkout(request, memory(), () => 'key');
  await checkout.create();
  await checkout.approve('paypal-order');
  await assert.rejects(checkout.create());
  await checkout.reconcile();
  await assert.rejects(checkout.create());
  assert.equal(calls.filter(path => path === '/orders').length, 1);
});

test('SDK approval for another order cannot capture', async () => {
  let calls = 0;
  const checkout = new Checkout(async () => { calls++; return {id:'local-order', paypal_order_id:'paypal-order', status:'created'}; }, memory(), () => 'key');
  await checkout.create();
  await assert.rejects(checkout.approve('other-order'));
  assert.equal(calls, 1);
});

test('unavailable persistent storage blocks checkout before any API call', async () => {
  let calls = 0;
  const storage = {getItem: () => null, setItem: () => { throw Error('blocked'); }};
  const checkout = new Checkout(async () => { calls++; }, storage, () => 'key');
  await assert.rejects(checkout.create());
  assert.equal(calls, 0);
});

test('defer has no API action and missing local order cannot be captured', async () => {
  let calls = 0;
  const checkout = new Checkout(async () => { calls++; }, memory(), () => 'key');
  await assert.rejects(checkout.approve('unowned-order'));
  assert.equal(calls, 0);
});
