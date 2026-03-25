<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('sales.create');
$pageTitle = 'Held Transactions';
include 'templates/header.php';
?>
<input type="hidden" id="pos-csrf" value="<?php echo escape(generateCSRFToken()); ?>">
<div class="row">
    <div class="col-lg-8 mx-auto">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5 class="mb-0">Hold / Parked Transactions</h5>
                <button id="refresh-holds" class="btn btn-outline-primary btn-sm">Refresh</button>
            </div>
            <div class="card-body">
                <div id="holds-empty" class="text-muted">Loading...</div>
                <div id="holds-list"></div>
            </div>
            <div class="card-footer">
                <a href="<?php echo getBaseUrl(); ?>/pos.php" class="btn btn-outline-secondary btn-sm">Back to POS</a>
            </div>
        </div>
    </div>
</div>
<script>
const endpoint = "<?php echo escape(getBaseUrl()); ?>/pos.php?ajax=1";
const csrf = document.getElementById('pos-csrf').value;

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

async function loadHolds() {
    const data = await api('list_holds');
    const list = document.getElementById('holds-list');
    const empty = document.getElementById('holds-empty');
    list.innerHTML = '';
    const holds = data.holds || [];
    if (!holds.length) {
        empty.style.display = 'block';
        empty.textContent = 'No held transactions.';
        return;
    }
    empty.style.display = 'none';
    holds.forEach(hold => {
        const row = document.createElement('div');
        row.className = 'd-flex justify-content-between align-items-center border rounded p-2 mb-2';
        row.innerHTML = `
            <div>
                <div class="fw-semibold">${hold.name}</div>
                <small class="text-muted">${hold.item_count} items | ${hold.age_minutes} min old</small>
            </div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-success load">Load</button>
                <button class="btn btn-outline-danger delete">Delete</button>
            </div>
        `;
        row.querySelector('.load').addEventListener('click', async () => {
            try {
                await api('load_hold', 'POST', { hold_id: hold.id });
                alert('Hold loaded. Redirecting to POS.');
                window.location.href = "<?php echo getBaseUrl(); ?>/pos.php";
            } catch (e) {
                alert(e.message);
            }
        });
        row.querySelector('.delete').addEventListener('click', async () => {
            try {
                await api('delete_hold', 'POST', { hold_id: hold.id });
                await loadHolds();
            } catch (e) {
                alert(e.message);
            }
        });
        list.appendChild(row);
    });
}

document.getElementById('refresh-holds').addEventListener('click', () => loadHolds().catch((e) => alert(e.message)));
loadHolds().catch((e) => alert(e.message));
</script>
<?php include 'templates/footer.php'; ?>
