<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('sales.refund');
$pageTitle = 'Returns / Exchange';
include 'templates/header.php';
?>

<input type="hidden" id="pos-csrf" value="<?php echo escape(generateCSRFToken()); ?>">

<div class="container-fluid">
    <!-- TransactionSearch -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <h5 class="mb-3">Find Transaction</h5>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input
                    id="return-search"
                    class="form-control"
                    placeholder="Search by Invoice Number, Customer Name, or SKU"
                    aria-label="Search transaction"
                >
            </div>
            <div id="return-results" class="list-group mt-2" style="max-height:260px;overflow-y:auto;"></div>

            <div id="selected-transaction-card" class="card border-0 bg-light mt-3 d-none">
                <div class="card-body py-2">
                    <div class="row g-2 small">
                        <div class="col-md-3"><span class="text-muted">Invoice:</span> <strong id="summary-invoice">-</strong></div>
                        <div class="col-md-3"><span class="text-muted">Customer:</span> <strong id="summary-customer">-</strong></div>
                        <div class="col-md-3"><span class="text-muted">Total:</span> <strong id="summary-total">0.00</strong></div>
                        <div class="col-md-3"><span class="text-muted">Date:</span> <strong id="summary-date">-</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- TransactionDetails -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <!-- TransactionHeader -->
            <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center border rounded p-3 bg-white mb-3">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <div>
                        <span class="text-muted small">Invoice</span>
                        <div class="fw-semibold">
                            <span id="hdr-invoice">-</span>
                            <button id="copy-invoice-btn" class="btn btn-link btn-sm p-0 ms-1" type="button" title="Copy Invoice">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <span class="text-muted small">Date & Time</span>
                        <div id="hdr-datetime" class="fw-semibold">-</div>
                    </div>
                    <div>
                        <span class="text-muted small">Total Amount</span>
                        <div id="hdr-total" class="fw-semibold">0.00</div>
                    </div>
                    <div>
                        <span class="text-muted small">Customer</span>
                        <div id="hdr-customer" class="fw-semibold">Guest</div>
                    </div>
                </div>
                <span id="hdr-status" class="badge bg-secondary">No Transaction Selected</span>
            </div>

            <div id="details-state" class="alert alert-light border text-muted mb-3">
                Search and select a transaction to start processing returns.
            </div>

            <!-- ItemsTable -->
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0" id="return-items-table">
                    <thead class="table-light">
                        <tr>
                            <th class="col-select text-center">Select</th>
                            <th class="col-product">Product</th>
                            <th class="col-original text-end">Original Qty</th>
                            <th class="col-history">Return History</th>
                            <th class="col-return-qty">Return Qty</th>
                            <th class="col-serial">Serial Verification</th>
                            <th class="col-reason">Return Reason</th>
                            <th class="col-status">Status</th>
                            <th class="col-subtotal text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="return-items"></tbody>
                </table>
            </div>

            <!-- ReturnSummary -->
            <div class="card border-0 bg-light mt-3">
                <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
                    <div>
                        <div class="small text-muted">Refund Subtotal</div>
                        <div class="h5 mb-1" id="refund-subtotal">0.00</div>
                        <div class="fw-bold">Total Refund: <span id="refund-net-total">0.00</span></div>
                    </div>
                    <div class="d-flex gap-2">
                        <button id="process-return" class="btn btn-primary btn-lg" disabled>Process Return</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ConfirmationModal -->
<div class="modal fade" id="returnConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirm-modal-title">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="confirm-modal-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirm-modal-action-btn" class="btn btn-primary">Confirm Return</button>
            </div>
        </div>
    </div>
</div>

<style>
#return-items-table thead th { white-space: nowrap; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; }
#return-items-table tbody tr.return-line.table-active-row { background: #edf4ff; }
#return-items-table tbody tr.state-unavailable { background: #fff6e9; }
#return-items-table tbody tr.state-returned { opacity: .55; }
#return-items-table tbody tr.state-returned .item-name { text-decoration: line-through; }
#return-items-table .qty-input { text-align: center; }
.product-main { max-width: 320px; }
.product-name-text { display: inline-block; max-width: 290px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.product-thumb { width: 34px; height: 34px; border-radius: 8px; object-fit: cover; border: 1px solid #e9ecef; background: #f8f9fa; }
.history-wrap { min-width: 170px; }
.history-bar { height: 6px; background: #e9ecef; border-radius: 999px; overflow: hidden; }
.history-bar > span { display: block; height: 100%; background: #0d6efd; }
.serial-error { font-size: .75rem; color: #dc3545; }
.serial-verified-text { font-size: .8rem; }
.serial-change-link { font-size: .75rem; }
#return-items-table .col-select { width: 74px; position: sticky; left: 0; z-index: 2; background: inherit; }
#return-items-table .col-product { min-width: 280px; position: sticky; left: 74px; z-index: 1; background: inherit; }
#return-items-table .col-original { width: 110px; }
#return-items-table .col-history { min-width: 190px; }
#return-items-table .col-return-qty { width: 180px; }
#return-items-table .col-serial { min-width: 260px; }
#return-items-table .col-reason { width: 190px; }
#return-items-table .col-status { width: 160px; }
#return-items-table .col-subtotal { width: 140px; }
</style>

<script>
const endpoint = "<?php echo escape(getBaseUrl()); ?>/pos.php?ajax=1";
const csrf = document.getElementById('pos-csrf').value;
let selectedTxId = null;
let pendingAction = null;
let confirmModal = null;

async function api(action, method = 'GET', payload = null) {
    let url = `${endpoint}&action=${encodeURIComponent(action)}`;
    const opts = { method };
    if (method === 'GET' && payload) {
        url += '&' + new URLSearchParams(payload).toString();
    } else if (payload) {
        const body = new URLSearchParams();
        body.set('csrf_token', csrf);
        Object.keys(payload).forEach((k) => {
            body.set(k, typeof payload[k] === 'object' ? JSON.stringify(payload[k]) : String(payload[k]));
        });
        opts.body = body;
        opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
    }
    const res = await fetch(url, opts);
    const data = await res.json();
    if (!res.ok || data.success === false) throw new Error(data.message || 'Request failed');
    return data;
}

function money(n) {
    return Number(n || 0).toFixed(2);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function setDetailsState(type, text) {
    const box = document.getElementById('details-state');
    if (!box) return;
    box.className = 'alert mb-3';
    if (type === 'loading') box.classList.add('alert-info');
    else if (type === 'error') box.classList.add('alert-danger');
    else if (type === 'ready') box.classList.add('alert-success');
    else box.classList.add('alert-light', 'border', 'text-muted');
    box.textContent = text;
}

function setHeaderMeta(txMeta, eligible = false) {
    const badge = document.getElementById('hdr-status');
    if (!badge) return;
    document.getElementById('hdr-invoice').textContent = txMeta?.transaction_number || '-';
    document.getElementById('hdr-datetime').textContent = txMeta?.transaction_date || '-';
    document.getElementById('hdr-total').textContent = money(txMeta?.total_amount || 0);
    document.getElementById('hdr-customer').textContent = `${txMeta?.first_name || 'Guest'} ${txMeta?.last_name || ''}`.trim();
    badge.className = 'badge';
    if (!txMeta) {
        badge.classList.add('bg-secondary');
        badge.textContent = 'No Transaction Selected';
    } else if (eligible) {
        badge.classList.add('bg-success');
        badge.textContent = 'Return Eligible';
    } else {
        badge.classList.add('bg-warning', 'text-dark');
        badge.textContent = 'No Returnable Items';
    }
}

function updateActionButtons() {
    const selected = document.querySelectorAll('.return-line .return-select:checked').length > 0;
    document.getElementById('process-return').disabled = !selected;
}

function updateRefundSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('.return-line').forEach((line) => {
        if (!line.querySelector('.return-select')?.checked) return;
        const qty = Number(line.querySelector('.return-qty')?.value || 0);
        const unit = Number(line.dataset.unitRefund || 0);
        if (qty > 0) subtotal += qty * unit;
    });
    const net = Math.max(0, subtotal);
    document.getElementById('refund-subtotal').textContent = money(subtotal);
    document.getElementById('refund-net-total').textContent = money(net);
    updateActionButtons();
}

function updateLineSubtotal(line) {
    const checked = line.querySelector('.return-select')?.checked;
    const qty = Number(line.querySelector('.return-qty')?.value || 0);
    const unit = Number(line.dataset.unitRefund || 0);
    const subtotal = checked ? Math.max(0, qty * unit) : 0;
    const box = line.querySelector('.line-refund');
    if (box) box.textContent = money(subtotal);
}

function parseSerialInput(v) {
    return String(v || '').split(/[\n,\s]+/).map((x) => x.trim()).filter((x) => x.length > 0);
}

function setSerialStatus(line, status, text) {
    const badge = line.querySelector('.serial-verify-status');
    if (!badge) return;
    badge.className = 'badge serial-verify-status';
    if (status === 'verified') badge.classList.add('bg-success-subtle', 'text-success');
    else if (status === 'invalid') badge.classList.add('bg-danger-subtle', 'text-danger');
    else badge.classList.add('bg-warning-subtle', 'text-dark');
    badge.textContent = text;
    line.dataset.serialVerified = status === 'verified' ? '1' : (line.dataset.requiresSerial === '1' ? '0' : '1');
    const err = line.querySelector('.serial-error');
    if (err) err.textContent = status === 'invalid' ? 'Invalid serial number for this transaction item.' : '';
}

function refreshSelectAvailability(line) {
    const select = line.querySelector('.return-select');
    const max = Number(line.querySelector('.return-qty')?.getAttribute('max') || 0);
    const needs = line.dataset.requiresSerial === '1';
    const verified = line.dataset.serialVerified === '1';
    if (max <= 0) {
        select.disabled = true;
        select.checked = false;
    } else if (needs && !verified) {
        select.disabled = true;
        select.checked = false;
    } else {
        select.disabled = false;
    }
    if (select.disabled) {
        line.classList.remove('table-active-row');
        line.querySelectorAll('.return-control').forEach((x) => { x.disabled = true; });
    }
    updateLineSubtotal(line);
    updateActionButtons();
}

function verifySerialLine(line) {
    const input = line.querySelector('.serial-scan');
    if (!input) return;
    const pool = (line.dataset.serialPool || '').split('|').filter(Boolean);
    const normalizedPool = new Set(pool.map((s) => s.toLowerCase()));
    const scanned = parseSerialInput(input.value);
    const qty = Number(line.querySelector('.return-qty')?.value || 1);
    const unique = new Set(scanned.map((s) => s.toLowerCase()));
    const ok = scanned.length === qty
        && unique.size === scanned.length
        && scanned.every((s) => normalizedPool.has(s.toLowerCase()));
    if (ok) {
        setSerialStatus(line, 'verified', 'Verified');
        const verifiedText = line.querySelector('.serial-verified-text');
        if (verifiedText) verifiedText.textContent = `SN: ${scanned.join(', ')}`;
        line.querySelector('.serial-unverified')?.classList.add('d-none');
        line.querySelector('.serial-verified')?.classList.remove('d-none');
    }
    else setSerialStatus(line, 'invalid', 'Invalid / Pending');
    const statusCell = line.querySelector('.line-status');
    if (ok) statusCell.innerHTML = '<span class="badge bg-success">RETURNABLE</span>';
    else statusCell.innerHTML = '<span class="badge bg-warning text-dark">Pending Verification</span>';
    refreshSelectAvailability(line);
    updateRefundSubtotal();
}

function toggleLineState(line) {
    const checked = line.querySelector('.return-select').checked;
    line.classList.toggle('table-active-row', checked);
    line.querySelectorAll('.return-control').forEach((x) => { x.disabled = !checked; });
    if (!checked) line.querySelector('.return-qty').value = '';
    updateLineSubtotal(line);
    updateRefundSubtotal();
}

function renderItems(items, tx) {
    const box = document.getElementById('return-items');
    box.innerHTML = '';
    const eligible = (items || []).some((i) => Number(i.returnable_qty || 0) > 0);
    setHeaderMeta(tx, eligible);
    if (!items.length) {
        box.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No returnable items found.</td></tr>';
        setDetailsState('ready', 'Transaction loaded, but no returnable rows were found.');
        updateActionButtons();
        return;
    }

    setDetailsState('ready', eligible ? 'Transaction loaded. Select item(s) to continue.' : 'No item is currently returnable.');
    document.getElementById('selected-transaction-card').classList.remove('d-none');
    document.getElementById('summary-invoice').textContent = tx.transaction_number || '-';
    document.getElementById('summary-customer').textContent = `${tx.first_name || 'Guest'} ${tx.last_name || ''}`.trim();
    document.getElementById('summary-total').textContent = money(tx.total_amount || 0);
    document.getElementById('summary-date').textContent = tx.transaction_date || '-';

    items.forEach((item) => {
        const row = document.createElement('tr');
        row.className = 'return-line';
        row.dataset.productId = item.product_id;
        row.dataset.transactionItemId = item.transaction_item_id;
        row.dataset.productSerialNumberId = item.product_serial_number_id || '';
        row.dataset.unitRefund = item.unit_refund_amount || 0;
        row.dataset.requiresSerial = item.requires_serial_scan ? '1' : '0';
        row.dataset.serialVerified = item.requires_serial_scan ? '0' : '1';
        row.dataset.serialPool = (item.serial_numbers || []).join('|');

        const max = Number(item.returnable_qty || 0);
        const already = Number(item.already_returned_qty || 0);
        const requiresSerial = !!item.requires_serial_scan;
        const isReturned = already > 0 && max <= 0;
        const originalQty = Number(item.original_qty || 0);
        const progressPct = originalQty > 0 ? Math.min(100, Math.round((already / originalQty) * 100)) : 0;
        const eligibilityText = max <= 0 ? 'Return limit reached' : 'Eligible for return';
        const statusType = max <= 0 ? (isReturned ? 'returned' : 'not_eligible') : (requiresSerial ? 'pending_verify' : 'returnable');
        const statusBadge = max <= 0
            ? (isReturned ? '<span class="badge bg-secondary">ALREADY RETURNED</span>' : '<span class="badge bg-warning text-dark">OUT OF STOCK</span>')
            : (requiresSerial ? '<span class="badge bg-warning text-dark">PENDING VERIFY</span>' : '<span class="badge bg-success">RETURNABLE</span>');

        if (max <= 0) row.classList.add('state-unavailable');
        if (isReturned) row.classList.add('state-returned');

        const safeName = escapeHtml(item.item_name);
        const safeSku = escapeHtml(item.sku || 'N/A');
        row.innerHTML = `
            <td class="text-center"><input class="form-check-input return-select" type="checkbox" ${max <= 0 || requiresSerial ? 'disabled' : ''} aria-label="Select item for return"></td>
            <td>
                <div class="d-flex align-items-start gap-2 product-main">
                    <img class="product-thumb" src="${item.image || ''}" alt="" onerror="this.style.visibility='hidden'">
                    <div>
                        <div class="fw-semibold item-name product-name-text" title="${safeName}">${safeName}</div>
                        <div class="small text-muted">SKU: ${safeSku}</div>
                    </div>
                </div>
            </td>
            <td class="text-end">${originalQty}</td>
            <td>
                <div class="history-wrap">
                    <div class="small text-muted d-flex justify-content-between">
                        <span><i class="bi bi-check2-circle"></i> ${already} of ${originalQty} returned</span>
                        <span>${progressPct}%</span>
                    </div>
                    <div class="history-bar my-1"><span style="width:${progressPct}%"></span></div>
                    <div class="small ${max <= 0 ? 'text-danger' : 'text-success'}">
                        <i class="bi ${max <= 0 ? 'bi-x-circle' : 'bi-arrow-repeat'}"></i> ${eligibilityText}
                    </div>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary qty-minus return-control" disabled>-</button>
                    <input type="number" class="form-control qty-input return-qty return-control" min="1" max="${max}" value="1" disabled>
                    <button type="button" class="btn btn-outline-secondary qty-plus return-control" disabled>+</button>
                </div>
            </td>
            <td>
                ${requiresSerial ? `
                    <div class="serial-unverified">
                        <div class="small text-muted mb-1">${item.serial_number || (item.serial_numbers || []).join(', ') || 'No serial found'}</div>
                        <div class="input-group input-group-sm mb-1">
                            <input type="text" class="form-control serial-scan" list="serial-list-${item.transaction_item_id}" placeholder="Enter or scan serial">
                            <button type="button" class="btn btn-outline-primary verify-serial-btn" ${max <= 0 ? 'disabled' : ''}>Verify</button>
                            <button type="button" class="btn btn-outline-secondary" ${max <= 0 ? 'disabled' : ''} title="Scanner Placeholder" aria-label="Scanner"><i class="bi bi-upc-scan"></i></button>
                        </div>
                        <div class="serial-error" aria-live="polite"></div>
                        <span class="badge bg-warning-subtle text-dark serial-verify-status">Pending</span>
                    </div>
                    <div class="serial-verified d-none">
                        <div class="serial-verified-text text-success fw-semibold">SN: -</div>
                        <div><i class="bi bi-check-circle-fill text-success"></i> <span class="small text-success">Verified</span> <a href="#" class="serial-change-link">Change</a></div>
                    </div>
                    <datalist id="serial-list-${item.transaction_item_id}">
                        ${(item.serial_numbers || []).map((sn) => `<option value="${sn}"></option>`).join('')}
                    </datalist>
                ` : '<span class="badge bg-secondary-subtle text-secondary" title="No serial number required.">N/A</span>'}
            </td>
            <td>
                <select class="form-select form-select-sm return-reason return-control" disabled>
                    <option value="defective">Defective</option>
                    <option value="wrong_part">Wrong Part</option>
                    <option value="change_mind">Customer Change of Mind</option>
                    <option value="damaged_box">Damaged Box</option>
                </select>
            </td>
            <td class="line-status" data-status="${statusType}">${statusBadge}</td>
            <td class="text-end line-refund">0.00</td>
        `;
        box.appendChild(row);

        const select = row.querySelector('.return-select');
        const qty = row.querySelector('.return-qty');
        const minus = row.querySelector('.qty-minus');
        const plus = row.querySelector('.qty-plus');
        const serialInput = row.querySelector('.serial-scan');
        const verifyBtn = row.querySelector('.verify-serial-btn');
        const changeSerialLink = row.querySelector('.serial-change-link');
        const statusCell = row.querySelector('.line-status');

        select.addEventListener('change', () => toggleLineState(row));
        qty.addEventListener('input', () => {
            let v = Number(qty.value || 0);
            if (v > max) v = max;
            if (v < 1 && select.checked) v = 1;
            qty.value = v > 0 ? v : '';
            if (row.dataset.requiresSerial === '1') {
                setSerialStatus(row, 'pending', 'Pending');
                statusCell.innerHTML = '<span class="badge bg-warning text-dark">Pending Verification</span>';
                refreshSelectAvailability(row);
            }
            updateLineSubtotal(row);
            updateRefundSubtotal();
        });
        minus.addEventListener('click', () => {
            qty.value = Math.max(1, Number(qty.value || 1) - 1);
            qty.dispatchEvent(new Event('input'));
        });
        plus.addEventListener('click', () => {
            qty.value = Math.min(max, Number(qty.value || 1) + 1);
            qty.dispatchEvent(new Event('input'));
        });

        if (serialInput) {
            serialInput.addEventListener('input', () => setSerialStatus(row, 'pending', 'Pending'));
            verifyBtn?.addEventListener('click', () => verifySerialLine(row));
            changeSerialLink?.addEventListener('click', (ev) => {
                ev.preventDefault();
                row.dataset.serialVerified = '0';
                row.querySelector('.serial-verified')?.classList.add('d-none');
                row.querySelector('.serial-unverified')?.classList.remove('d-none');
                setSerialStatus(row, 'pending', 'Pending');
                statusCell.innerHTML = '<span class="badge bg-warning text-dark">PENDING VERIFY</span>';
                refreshSelectAvailability(row);
            });
            refreshSelectAvailability(row);
        }
    });

    updateRefundSubtotal();
}

function gather(forceStoreCredit = false) {
    const out = [];
    document.querySelectorAll('.return-line').forEach((line) => {
        if (!line.querySelector('.return-select')?.checked) return;
        const qty = Number(line.querySelector('.return-qty')?.value || 0);
        if (qty <= 0) return;
        const payload = {
            transaction_item_id: Number(line.dataset.transactionItemId || 0),
            product_id: Number(line.dataset.productId || 0),
            product_serial_number_id: Number(line.dataset.productSerialNumberId || 0) || null,
            quantity: qty,
            reason: line.querySelector('.return-reason').value,
            refund_method: forceStoreCredit ? 'store_credit' : 'cash',
            refund_mode: 'original'
        };
        const serialInput = line.querySelector('.serial-scan');
        if (serialInput) {
            if (line.dataset.serialVerified !== '1') throw new Error('Serial verification is required for selected serialized item.');
            const scanned = parseSerialInput(serialInput.value);
            if (scanned.length !== qty) throw new Error(`Please input exactly ${qty} serial(s) for selected item.`);
            payload.scanned_serials = scanned;
        }
        out.push(payload);
    });
    return out;
}

function resetUiAfterSubmit() {
    selectedTxId = null;
    setHeaderMeta(null, false);
    setDetailsState('empty', 'Search and select a transaction to start processing returns.');
    document.getElementById('selected-transaction-card').classList.add('d-none');
    document.getElementById('return-items').innerHTML = '';
    document.getElementById('refund-subtotal').textContent = '0.00';
    document.getElementById('refund-net-total').textContent = '0.00';
}

function openConfirmModal(items) {
    pendingAction = { type: 'return', items };
    document.getElementById('confirm-modal-title').textContent = 'Confirm Return';
    document.getElementById('confirm-modal-body').innerHTML =
        `<p class="mb-2">You are about to process <strong>${items.length}</strong> item line(s).</p>` +
        `<p class="mb-0">Total Refund: <strong>${document.getElementById('refund-net-total').textContent}</strong></p>`;
    const btn = document.getElementById('confirm-modal-action-btn');
    btn.className = 'btn btn-primary';
    btn.textContent = 'Confirm Return';
    if (window.bootstrap && !confirmModal) {
        confirmModal = new bootstrap.Modal(document.getElementById('returnConfirmModal'));
    }
    if (confirmModal) {
        confirmModal.show();
    } else {
        const ok = window.confirm(`Proceed with ${type} for ${items.length} line(s)?`);
        if (ok) {
            document.getElementById('confirm-modal-action-btn').click();
        }
    }
}

document.getElementById('confirm-modal-action-btn').addEventListener('click', async () => {
    try {
        if (!selectedTxId || !pendingAction) throw new Error('No pending action.');
        const data = await api('process_return', 'POST', { transaction_id: selectedTxId, items: pendingAction.items });
        alert('Return processed. Refund total: ' + money(data.refund_total || 0));
        if (confirmModal) confirmModal.hide();
        pendingAction = null;
        resetUiAfterSubmit();
    } catch (e) {
        alert(e.message);
    }
});

let timer;
document.getElementById('return-search').addEventListener('keyup', (e) => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        try {
            setDetailsState('loading', 'Searching transactions...');
            const data = await api('search_transactions', 'GET', { search: e.target.value });
            const list = document.getElementById('return-results');
            list.innerHTML = '';
            (data.transactions || []).forEach((tx) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'list-group-item list-group-item-action';
                b.innerHTML = `<div class="fw-semibold">${tx.transaction_number}</div>
                               <div class="small text-muted">${tx.first_name || 'Guest'} ${tx.last_name || ''} | ${money(tx.total_amount)}</div>`;
                b.addEventListener('click', async () => {
                    try {
                        selectedTxId = tx.id;
                        setDetailsState('loading', `Loading items for ${tx.transaction_number}...`);
                        const itemsData = await api('get_transaction_items', 'GET', { transaction_id: tx.id });
                        renderItems(itemsData.items || [], tx);
                    } catch (err) {
                        setDetailsState('error', `Failed to load items: ${err.message}`);
                        document.getElementById('return-items').innerHTML = '<tr><td colspan="8" class="text-danger py-3">Unable to load transaction items.</td></tr>';
                    }
                });
                list.appendChild(b);
            });
            if (!data.transactions || !data.transactions.length) {
                setDetailsState('empty', 'No transactions found. Refine your search.');
            }
        } catch (err) {
            setDetailsState('error', err.message);
        }
    }, 250);
});

document.getElementById('process-return').addEventListener('click', () => {
    try {
        if (!selectedTxId) throw new Error('Select a transaction first.');
        const items = gather(false);
        if (!items.length) throw new Error('Select at least one return item.');
        openConfirmModal(items);
    } catch (e) {
        alert(e.message);
    }
});

document.getElementById('copy-invoice-btn').addEventListener('click', async () => {
    const val = document.getElementById('hdr-invoice').textContent.trim();
    if (!val || val === '-') return;
    try { await navigator.clipboard.writeText(val); } catch (_) {}
});
</script>

<?php include 'templates/footer.php'; ?>
