<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('sales.create');
$pageTitle = 'POS Cart';
include 'templates/header.php';
?>
<input type="hidden" id="pos-csrf" value="<?php echo escape(generateCSRFToken()); ?>">
<div class="row">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Shopping Cart</h5>
                <div class="btn-group btn-group-sm">
                    <button id="clear-cart" class="btn btn-outline-danger">Clear Cart</button>
                    <button id="save-hold" class="btn btn-outline-warning">Save Hold</button>
                    <a href="<?php echo getBaseUrl(); ?>/hold_transactions.php" class="btn btn-outline-info">Held List</a>
                </div>
            </div>
            <div class="card-body">
                <div id="cart-empty" class="text-muted">Loading cart...</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="cart-table" style="display:none;">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Line Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cart-body"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Totals</div>
            <div class="card-body small">
                <div class="d-flex justify-content-between"><span>Subtotal</span><span id="subtotal">0.00</span></div>
                <div class="d-flex justify-content-between"><span>Tax</span><span id="tax">0.00</span></div>
                <div class="d-flex justify-content-between"><span>Discount</span><span id="discount">0.00</span></div>
                <hr>
                <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span id="total">0.00</span></div>
            </div>
            <div class="card-footer d-grid gap-2">
                <a href="<?php echo getBaseUrl(); ?>/pos.php" class="btn btn-outline-primary">Back to POS</a>
                <a href="<?php echo getBaseUrl(); ?>/checkout.php" class="btn btn-primary">Proceed to Checkout</a>
            </div>
        </div>
    </div>
</div>
<script>
const endpoint = "<?php echo escape(getBaseUrl()); ?>/pos.php?ajax=1";
const csrf = document.getElementById('pos-csrf').value;
const fmt = (n) => Number(n || 0).toFixed(2);

async function api(action, method = 'GET', payload = null) {
    let url = `${endpoint}&action=${encodeURIComponent(action)}`;
    const opts = { method };
    if (method === 'GET' && payload) {
        url += '&' + new URLSearchParams(payload).toString();
    } else if (payload) {
        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        Object.keys(payload).forEach(k => body.set(k, String(payload[k])));
        opts.body = body;
        opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    }
    const res = await fetch(url, opts);
    const data = await res.json();
    if (!res.ok || data.success === false) throw new Error(data.message || 'Request failed');
    return data;
}

function renderCart(cart) {
    const body = document.getElementById('cart-body');
    const empty = document.getElementById('cart-empty');
    const table = document.getElementById('cart-table');
    body.innerHTML = '';
    const items = cart?.items || [];
    if (!items.length) {
        table.style.display = 'none';
        empty.style.display = 'block';
        empty.textContent = 'Cart is empty.';
    } else {
        table.style.display = 'table';
        empty.style.display = 'none';
        items.forEach(item => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="fw-semibold">${item.name}</div>
                    <small class="text-muted">${item.sku}</small>
                </td>
                <td style="width:170px;">
                    <div class="input-group input-group-sm">
                        <button class="btn btn-outline-secondary minus">-</button>
                        <input class="form-control qty" type="number" min="1" value="${item.quantity}">
                        <button class="btn btn-outline-secondary plus">+</button>
                    </div>
                </td>
                <td>${fmt(item.unit_price)}</td>
                <td>${fmt(item.total)}</td>
                <td><button class="btn btn-sm btn-outline-danger remove">Remove</button></td>
            `;
            tr.querySelector('.minus').addEventListener('click', async () => {
                try { await api('update_cart', 'POST', { product_id: item.product_id, quantity: item.quantity - 1 }); await loadCart(); } catch (e) { alert(e.message); }
            });
            tr.querySelector('.plus').addEventListener('click', async () => {
                try { await api('update_cart', 'POST', { product_id: item.product_id, quantity: item.quantity + 1 }); await loadCart(); } catch (e) { alert(e.message); }
            });
            tr.querySelector('.qty').addEventListener('change', async (ev) => {
                try { await api('update_cart', 'POST', { product_id: item.product_id, quantity: ev.target.value }); await loadCart(); } catch (e) { alert(e.message); }
            });
            tr.querySelector('.remove').addEventListener('click', async () => {
                try { await api('remove_cart_item', 'POST', { product_id: item.product_id }); await loadCart(); } catch (e) { alert(e.message); }
            });
            body.appendChild(tr);
        });
    }
    document.getElementById('subtotal').textContent = fmt(cart?.totals?.subtotal);
    document.getElementById('tax').textContent = fmt(cart?.totals?.tax);
    document.getElementById('discount').textContent = fmt(cart?.totals?.discount);
    document.getElementById('total').textContent = fmt(cart?.totals?.total);
}

async function loadCart() {
    const data = await api('get_cart', 'GET');
    renderCart(data.cart);
}

document.getElementById('clear-cart').addEventListener('click', async () => {
    if (!confirm('Clear all cart items?')) return;
    try { await api('clear_cart', 'POST'); await loadCart(); } catch (e) { alert(e.message); }
});
document.getElementById('save-hold').addEventListener('click', async () => {
    const name = prompt('Hold name');
    try { await api('save_hold', 'POST', { hold_name: name || '' }); await loadCart(); alert('Cart saved to hold.'); } catch (e) { alert(e.message); }
});

loadCart().catch((e) => alert(e.message));
</script>
<?php include 'templates/footer.php'; ?>
