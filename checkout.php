<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('sales.create');
$pageTitle = 'POS Checkout';
include 'templates/header.php';
?>
<input type="hidden" id="pos-csrf" value="<?php echo escape(generateCSRFToken()); ?>">
<div class="row">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">Checkout Summary</div>
            <div class="card-body">
                <div id="checkout-empty" class="text-muted">Loading cart...</div>
                <div class="table-responsive">
                    <table class="table table-sm" id="checkout-table" style="display:none;">
                        <thead>
                            <tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr>
                        </thead>
                        <tbody id="checkout-items"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Payment</div>
            <div class="card-body">
                <div id="payment-lines"></div>
                <button id="add-payment-line" class="btn btn-outline-primary btn-sm mb-3">Add Payment Line</button>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="email-receipt">
                    <label class="form-check-label" for="email-receipt">Email receipt (if customer has email)</label>
                </div>
                <input type="text" id="checkout-notes" class="form-control form-control-sm mb-3" placeholder="Transaction notes">
                <div class="small">
                    <div class="d-flex justify-content-between"><span>Subtotal</span><span id="subtotal">0.00</span></div>
                    <div class="d-flex justify-content-between"><span>Tax</span><span id="tax">0.00</span></div>
                    <div class="d-flex justify-content-between"><span>Discount</span><span id="discount">0.00</span></div>
                    <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span id="total">0.00</span></div>
                    <div class="d-flex justify-content-between"><span>Paid</span><span id="paid">0.00</span></div>
                    <div class="d-flex justify-content-between"><span>Change</span><span id="change">0.00</span></div>
                </div>
            </div>
            <div class="card-footer d-grid gap-2">
                <button id="finalize" class="btn btn-success">Finalize Transaction</button>
                <a href="<?php echo getBaseUrl(); ?>/cart.php" class="btn btn-outline-secondary">Back to Cart</a>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="receipt-body"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="reprint-link" href="#" class="btn btn-outline-primary">Open Receipt Page</a>
            </div>
        </div>
    </div>
</div>
<script>
const endpoint = "<?php echo escape(getBaseUrl()); ?>/pos.php?ajax=1";
const csrf = document.getElementById('pos-csrf').value;
const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
const fmt = (n) => Number(n || 0).toFixed(2);
let currentTotal = 0;

async function api(action, method = 'GET', payload = null) {
    let url = `${endpoint}&action=${encodeURIComponent(action)}`;
    const opts = { method };
    if (method === 'GET' && payload) {
        url += '&' + new URLSearchParams(payload).toString();
    } else if (payload) {
        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        Object.keys(payload).forEach(k => body.set(k, typeof payload[k] === 'object' ? JSON.stringify(payload[k]) : String(payload[k])));
        opts.body = body;
        opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    }
    const res = await fetch(url, opts);
    const data = await res.json();
    if (!res.ok || data.success === false) throw new Error(data.message || 'Request failed');
    return data;
}

function paymentLineRow() {
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 payment-line';
    row.innerHTML = `
        <div class="col-5">
            <select class="form-select form-select-sm payment-method">
                <option value="cash">Cash</option>
                <option value="card">Card</option>
                <option value="digital_wallet">Digital Wallet</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="check">Check</option>
            </select>
        </div>
        <div class="col-4"><input type="number" class="form-control form-control-sm payment-amount" min="0" step="0.01" placeholder="Amount"></div>
        <div class="col-3"><button type="button" class="btn btn-outline-danger btn-sm w-100 remove-payment">X</button></div>
        <div class="col-12"><input type="text" class="form-control form-control-sm payment-ref" placeholder="Reference"></div>
    `;
    row.querySelector('.remove-payment').addEventListener('click', () => { row.remove(); recalcPaid(); });
    row.querySelector('.payment-amount').addEventListener('input', recalcPaid);
    return row;
}

function collectPayments() {
    const out = [];
    document.querySelectorAll('.payment-line').forEach(line => {
        const amount = Number(line.querySelector('.payment-amount').value || 0);
        if (amount > 0) {
            out.push({
                method: line.querySelector('.payment-method').value,
                amount,
                reference: line.querySelector('.payment-ref').value.trim() || null
            });
        }
    });
    return out;
}

function recalcPaid() {
    const paid = collectPayments().reduce((s, p) => s + Number(p.amount || 0), 0);
    const change = Math.max(0, paid - currentTotal);
    document.getElementById('paid').textContent = fmt(paid);
    document.getElementById('change').textContent = fmt(change);
}

async function loadCheckout() {
    const data = await api('get_cart');
    const cart = data.cart || { items: [], totals: {} };
    const table = document.getElementById('checkout-table');
    const empty = document.getElementById('checkout-empty');
    const body = document.getElementById('checkout-items');
    body.innerHTML = '';

    if (!cart.items.length) {
        table.style.display = 'none';
        empty.style.display = 'block';
        empty.textContent = 'Cart is empty. Add products from POS.';
    } else {
        table.style.display = 'table';
        empty.style.display = 'none';
        cart.items.forEach(it => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${it.name}<br><small class="text-muted">${it.sku}</small></td><td>${it.quantity}</td><td>${fmt(it.unit_price)}</td><td>${fmt(it.total)}</td>`;
            body.appendChild(tr);
        });
    }

    document.getElementById('subtotal').textContent = fmt(cart.totals.subtotal);
    document.getElementById('tax').textContent = fmt(cart.totals.tax);
    document.getElementById('discount').textContent = fmt(cart.totals.discount);
    document.getElementById('total').textContent = fmt(cart.totals.total);
    currentTotal = Number(cart.totals.total || 0);
    recalcPaid();
}

document.getElementById('add-payment-line').addEventListener('click', () => {
    document.getElementById('payment-lines').appendChild(paymentLineRow());
});
document.getElementById('payment-lines').appendChild(paymentLineRow());

document.getElementById('finalize').addEventListener('click', async () => {
    try {
        const payments = collectPayments();
        if (!payments.length) throw new Error('Add at least one payment line.');
        const data = await api('checkout', 'POST', {
            payments,
            notes: document.getElementById('checkout-notes').value,
            email_receipt: document.getElementById('email-receipt').checked ? '1' : ''
        });
        document.getElementById('receipt-body').innerHTML = data.receipt_html || '<div class="text-muted">No receipt HTML.</div>';
        document.getElementById('reprint-link').href = "<?php echo getBaseUrl(); ?>/receipt.php?transaction_id=" + encodeURIComponent(data.transaction_id);
        receiptModal.show();
        await loadCheckout();
        alert('Transaction completed. Change: ' + fmt(data.change));
    } catch (e) {
        alert(e.message);
    }
});

loadCheckout().catch((e) => alert(e.message));
</script>
<?php include 'templates/footer.php'; ?>
