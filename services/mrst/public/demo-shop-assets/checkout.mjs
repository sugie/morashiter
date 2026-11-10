// The saved key survives network errors and reloads. Never reset it after an uncertain payment.
export class Checkout {
  constructor(request, storage, uuid = () => crypto.randomUUID()) {
    this.request = request;
    this.storage = storage;
    this.uuid = uuid;
    this.busy = false;
    this.state = JSON.parse(storage.getItem('aozora-checkout-v1') || '{}');
    if (!this.state || typeof this.state !== 'object' || Array.isArray(this.state)) throw Error('Invalid saved order');
  }

  save() { this.storage.setItem('aozora-checkout-v1', JSON.stringify(this.state)); }

  async create() {
    if (this.busy || ['capturing', 'capture_unknown', 'pending', 'completed', 'failed'].includes(this.state.order?.status)) {
      throw Error('Check the existing order before proceeding.');
    }
    if (this.state.order?.paypal_order_id) return {orderId: this.state.order.paypal_order_id};
    this.state.key ||= this.uuid();
    this.save();
    this.busy = true;
    try {
      this.state.order = await this.request('/orders', {product_id: 'TOTE-001', quantity: 1, client_request_id: this.state.key});
      this.save();
      if (!this.state.order.paypal_order_id) throw Error('The order result is not yet known. Retry the same request.');
      return {orderId: this.state.order.paypal_order_id};
    } finally { this.busy = false; }
  }

  async approve(paypalOrderId) {
    if (this.busy || !this.state.order?.id || this.state.order.paypal_order_id !== paypalOrderId) throw Error('Order mismatch');
    if (this.state.order.status === 'completed') return this.state.order;
    this.busy = true;
    this.state.order.status = 'capture_unknown';
    try {
      this.save();
      this.state.order = await this.request(`/orders/${encodeURIComponent(this.state.order.id)}/capture`, {});
      this.save();
      return this.state.order;
    } finally { this.busy = false; }
  }

  async reconcile() {
    if (this.busy || !this.state.order?.id) throw Error('No saved order to check');
    this.busy = true;
    try {
      this.state.order = await this.request(`/orders/${encodeURIComponent(this.state.order.id)}/reconcile`, {});
      this.save();
      return this.state.order;
    } finally { this.busy = false; }
  }
}
