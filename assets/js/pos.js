document.addEventListener("DOMContentLoaded", () => {
    const cfg = window.POS_CONFIG || {};
    const endpoint = cfg.endpoint || "/pos.php?ajax=1";
    const baseUrl = cfg.base_url || "";
    const csrf = cfg.csrf || "";
    let currentMode = cfg.mode || "pos";

    const el = (id) => document.getElementById(id);
    const fmt = (n) => Number(n || 0).toFixed(2);
    const toast = (msg) => alert(msg);

    let productPage = 1;
    let productTotalPages = 1;
    let selectedReturnTxId = null;
    let currentQuoteId = null;

    const receiptModal = el("receiptModal") ? new bootstrap.Modal(el("receiptModal")) : null;
    const quotesModal = el("quotesModal") ? new bootstrap.Modal(el("quotesModal")) : null;

    async function api(action, method = "GET", payload = null) {
        let url = `${endpoint}&action=${encodeURIComponent(action)}`;
        const opts = { method };
        if (method === "GET" && payload) {
            const q = new URLSearchParams(payload);
            url += `&${q.toString()}`;
        } else if (payload) {
            const body = new URLSearchParams();
            body.set("csrf_token", csrf);
            Object.keys(payload).forEach((k) => {
                const v = payload[k];
                body.set(k, typeof v === "object" ? JSON.stringify(v) : String(v));
            });
            opts.body = body;
            opts.headers = { "Content-Type": "application/x-www-form-urlencoded" };
        }
        const res = await fetch(url, opts);
        const data = await res.json();
        if (!res.ok || data.success === false) {
            throw new Error(data.message || "Request failed.");
        }
        return data;
    }

    function isQuoteMode() {
        return currentMode === "quote";
    }

    function getSelectedCustomerId() {
        const cid = Number(el("selected-customer")?.dataset?.customerId || 0);
        return cid > 0 ? cid : null;
    }

    function applyModeUI() {
        const quote = isQuoteMode();
        el("mode-pos")?.classList.toggle("btn-primary", !quote);
        el("mode-pos")?.classList.toggle("btn-outline-primary", quote);
        el("mode-quote")?.classList.toggle("btn-primary", quote);
        el("mode-quote")?.classList.toggle("btn-outline-primary", !quote);
        if (el("mode-badge")) {
            el("mode-badge").textContent = quote ? "QUOTE MODE" : "POS MODE";
            el("mode-badge").className = quote ? "badge bg-info text-dark" : "badge bg-primary";
        }
        el("quote-header")?.classList.toggle("d-none", !quote);
        el("quote-notes-wrap")?.classList.toggle("d-none", !quote);
        el("pos-actions")?.classList.toggle("d-none", quote);
        el("quote-actions")?.classList.toggle("d-none", !quote);
        if (el("payment-amount")) el("payment-amount").disabled = false;
        const panel = el("cart-panel");
        if (panel) {
            panel.classList.toggle("border-info", quote);
            panel.classList.toggle("bg-info-subtle", quote);
        }
        const customerMissing = quote && !getSelectedCustomerId();
        el("quote-customer-warning")?.classList.toggle("d-none", !customerMissing);
        if (!quote) {
            currentQuoteId = null;
            if (el("quote-number")) el("quote-number").textContent = "Q-NEW";
            if (el("quote-status")) el("quote-status").textContent = "DRAFT";
        }
    }

    async function switchMode(nextMode) {
        if (nextMode === currentMode) return;
        const cartHasItems = (document.querySelectorAll("#cart-items .border.rounded").length > 0);
        let clearCart = false;
        if (cartHasItems) {
            const ok = confirm("Switch mode and clear current cart?");
            if (!ok) return;
            clearCart = true;
        }
        await api("switch_mode", "POST", { mode: nextMode, clear_cart: clearCart ? "1" : "" });
        currentMode = nextMode;
        applyModeUI();
        await refreshCart();
    }

    async function listQuotes() {
        const data = await api("list_quotes", "GET");
        const box = el("quotes-body");
        box.innerHTML = "";
        const quotes = data.quotes || [];
        if (!quotes.length) {
            box.innerHTML = '<div class="text-muted small">No saved quotes yet.</div>';
            return;
        }
        quotes.forEach((q) => {
            const row = document.createElement("div");
            row.className = "d-flex justify-content-between align-items-center border rounded p-2 mb-2";
            row.innerHTML = `
                <div>
                    <div class="fw-semibold small">${q.quote_number}</div>
                    <div class="small text-muted">${(q.first_name || "Guest")} ${(q.last_name || "")} | ${fmt(q.total_amount)} | ${q.status}</div>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary load">Load</button>
                    <button class="btn btn-success convert">Convert</button>
                </div>
            `;
            row.querySelector(".load").addEventListener("click", async () => {
                try {
                    await api("load_quote", "POST", { quote_id: q.id });
                    currentMode = "quote";
                    applyModeUI();
                    currentQuoteId = Number(q.id || 0) || null;
                    el("quote-number").textContent = q.quote_number;
                    el("quote-status").textContent = (q.status || "draft").toUpperCase();
                    el("quote-valid-until").value = (q.valid_until || "").slice(0, 10);
                    el("selected-customer").textContent = `Customer: ${(q.first_name || "Guest")} ${(q.last_name || "")}`.trim();
                    el("selected-customer").dataset.customerId = String(q.customer_id || "");
                    quotesModal?.hide();
                    await refreshCart();
                } catch (e) { toast(e.message); }
            });
            row.querySelector(".convert").addEventListener("click", async () => {
                try {
                    if (!confirm(`Convert ${q.quote_number} to sale?`)) return;
                    await api("convert_quote_to_sale", "POST", { quote_id: q.id });
                    currentMode = "pos";
                    applyModeUI();
                    quotesModal?.hide();
                    await refreshCart();
                    toast(`Quote ${q.quote_number} converted. Ready for payment.`);
                } catch (e) { toast(e.message); }
            });
            box.appendChild(row);
        });
    }

    async function saveQuote(status = "draft") {
        if (!isQuoteMode()) throw new Error("Switch to Quote Mode first.");
        const customerId = getSelectedCustomerId();
        if (!customerId) throw new Error("Customer is required for quotes.");
        const data = await api("save_quote", "POST", {
            quote_id: currentQuoteId || "",
            customer_id: customerId,
            valid_until: el("quote-valid-until")?.value || "",
            status,
            notes: el("quote-notes")?.value || ""
        });
        currentQuoteId = Number(data.quote?.id || 0) || currentQuoteId;
        el("quote-number").textContent = data.quote?.quote_number || "Q-NEW";
        el("quote-status").textContent = (data.quote?.status || status).toUpperCase();
        toast(`Quote ${data.quote?.quote_number || ""} saved.`);
        return data.quote;
    }

    function collectPayments() {
        const total = Number(el("total")?.textContent || 0);
        const enteredAmount = Number(el("payment-amount")?.value || 0);
        const amount = enteredAmount > 0 ? enteredAmount : total;
        if (amount <= 0) return [];
        return [{ method: "cash", amount, reference: null }];
    }

    function renderCart(cart) {
        const list = el("cart-items");
        if (!list) return;
        list.innerHTML = "";
        const items = cart?.items || [];
        if (!items.length) {
            list.innerHTML = '<div class="text-muted small">Cart is empty.</div>';
        } else {
            items.forEach((item) => {
                const row = document.createElement("div");
                row.className = "d-flex align-items-center justify-content-between p-2 mb-2 pos-cart-item";
                row.innerHTML = `
                    <div class="flex-grow-1 pe-2">
                        <div class="small fw-semibold">${item.name}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm qty-minus">-</button>
                        <div class="small fw-semibold text-end">Qty: ${item.quantity}</div>
                        <button type="button" class="btn btn-outline-secondary btn-sm qty-plus">+</button>
                    </div>
                `;
                row.querySelector(".qty-minus")?.addEventListener("click", async (ev) => {
                    ev.stopPropagation();
                    try {
                        const nextQty = Number(item.quantity || 0) - 1;
                        if (nextQty <= 0) {
                            await api("remove_cart_item", "POST", { product_id: item.product_id });
                        } else {
                            await api("update_cart", "POST", { product_id: item.product_id, quantity: nextQty });
                        }
                        await refreshCart();
                    } catch (e) { toast(e.message); }
                });
                row.querySelector(".qty-plus")?.addEventListener("click", async (ev) => {
                    ev.stopPropagation();
                    try {
                        await api("update_cart", "POST", { product_id: item.product_id, quantity: Number(item.quantity || 0) + 1 });
                        await refreshCart();
                    } catch (e) { toast(e.message); }
                });
                row.addEventListener("click", async () => {
                    if (!confirm(`Remove "${item.name}" from checkout list?`)) return;
                    try {
                        await api("remove_cart_item", "POST", { product_id: item.product_id });
                        await refreshCart();
                    } catch (e) { toast(e.message); }
                });
                row.title = "Click row to remove item";
                list.appendChild(row);
            });
        }
        if (el("subtotal")) el("subtotal").textContent = fmt(cart?.totals?.subtotal);
        if (el("tax")) el("tax").textContent = fmt(cart?.totals?.tax);
        if (el("discount")) el("discount").textContent = fmt(cart?.totals?.discount);
        if (el("total")) el("total").textContent = fmt(cart?.totals?.total);
    }

    function renderProducts(data) {
        const grid = el("product-grid");
        if (!grid) return;
        grid.innerHTML = "";
        const products = data.products || [];
        if (!products.length) {
            grid.innerHTML = '<div class="text-muted">No products found.</div>';
            return;
        }
        products.forEach((p) => {
            const col = document.createElement("div");
            col.className = "col-md-4";
            const out = Number(p.stock_quantity || 0) <= 0;
            col.innerHTML = `
                <div class="card h-100">
                    <div class="card-img-wrap"><img src="${p.image || ""}" class="card-img-top" style="height:100%;object-fit:cover" onerror="this.style.display='none'"></div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="small fw-semibold pe-2">${p.name}</div>
                            <span class="stock-badge ${out ? "out" : "in"}">${out ? "Out of Stock" : "In Stock"}</span>
                        </div>
                        <div class="small text-muted">${p.sku || ""}</div>
                        <div class="small fw-semibold mt-1">${fmt(p.selling_price)}</div>
                    </div>
                    <div class="card-footer">
                        <div class="input-group input-group-sm">
                            <input type="number" min="1" value="1" class="form-control qty">
                            <button class="btn btn-primary add" ${out ? "disabled" : ""}>Add</button>
                        </div>
                    </div>
                </div>
                `;
            col.querySelector(".add").addEventListener("click", async () => {
                try {
                    const qty = col.querySelector(".qty").value || "1";
                    await api("add_to_cart", "POST", { product_id: p.id, quantity: qty });
                    await refreshCart();
                } catch (e) { toast(e.message); }
            });
            grid.appendChild(col);
        });
        productPage = data.page || 1;
        productTotalPages = data.total_pages || 1;
        el("page-indicator").textContent = `Page ${productPage} / ${productTotalPages}`;
    }

    async function refreshProducts() {
        const search = el("product-search")?.value || "";
        const category = el("category-filter")?.value || "";
        const data = await api("search_products", "GET", { search, category_id: category, page: productPage });
        renderProducts(data);
    }

    async function refreshCart() {
        const data = await api("get_cart", "GET");
        renderCart(data.cart);
    }

    async function searchCustomers(q) {
        const results = el("customer-results");
        if (!q.trim()) { results.classList.add("d-none"); results.innerHTML = ""; return; }
        const data = await api("search_customers", "GET", { search: q });
        results.innerHTML = "";
        if (!data.customers.length) { results.classList.add("d-none"); return; }
        data.customers.forEach((c) => {
            const a = document.createElement("button");
            a.type = "button";
            a.className = "list-group-item list-group-item-action";
            a.textContent = `${c.first_name} ${c.last_name} (${c.phone || "n/a"})`;
            a.addEventListener("click", async () => {
                try {
                    await api("set_customer", "POST", { customer_id: c.id });
                    el("selected-customer").textContent = `Customer: ${c.first_name} ${c.last_name}`;
                    el("selected-customer").dataset.customerId = String(c.id);
                    applyModeUI();
                    results.classList.add("d-none");
                } catch (e) { toast(e.message); }
            });
            results.appendChild(a);
        });
        results.classList.remove("d-none");
    }

    async function searchTransactions(q) {
        const data = await api("search_transactions", "GET", { search: q });
        const box = el("return-results");
        box.innerHTML = "";
        (data.transactions || []).forEach((tx) => {
            const row = document.createElement("button");
            row.type = "button";
            row.className = "list-group-item list-group-item-action";
            row.textContent = `${tx.transaction_number} | ${tx.first_name || "Guest"} ${tx.last_name || ""} | ${fmt(tx.total_amount)}`;
            row.addEventListener("click", async () => {
                selectedReturnTxId = tx.id;
                const itemsData = await api("get_transaction_items", "GET", { transaction_id: tx.id });
                renderReturnItems(itemsData.items || []);
            });
            box.appendChild(row);
        });
    }

    function renderReturnItems(items) {
        const box = el("return-items");
        box.innerHTML = "";
        if (!items.length) {
            box.innerHTML = '<div class="text-muted small">Select transaction to return.</div>';
            return;
        }
        items.forEach((item) => {
            const row = document.createElement("div");
            row.className = "border rounded p-2 mb-2 small return-line";
            row.dataset.productId = item.product_id;
            row.dataset.transactionItemId = item.transaction_item_id;
            row.dataset.productSerialNumberId = item.product_serial_number_id || "";
            row.innerHTML = `
                <div class="fw-semibold">${item.product_name}</div>
                <div class="text-muted">Purchased: ${item.quantity} | Returnable: ${item.returnable_qty}${item.serial_number ? ` | Serial: ${item.serial_number}` : ""}</div>
                <div class="row g-1 mt-1">
                    <div class="col-4"><input type="number" min="0" max="${item.returnable_qty}" value="0" class="form-control form-control-sm return-qty"></div>
                    <div class="col-4">
                        <select class="form-select form-select-sm return-reason">
                            <option value="defective">Defective</option>
                            <option value="wrong_item">Wrong Item</option>
                            <option value="change_mind">Change Mind</option>
                        </select>
                    </div>
                    <div class="col-4">
                        <select class="form-select form-select-sm refund-method">
                            <option value="cash">Cash</option>
                            <option value="card">Card</option>
                            <option value="store_credit">Store Credit</option>
                        </select>
                    </div>
                </div>
            `;
            box.appendChild(row);
        });
    }

    function gatherReturnItems(forceStoreCredit = false) {
        const out = [];
        document.querySelectorAll(".return-line").forEach((line) => {
            const qty = Number(line.querySelector(".return-qty").value || 0);
            if (qty > 0) {
                out.push({
                    transaction_item_id: Number(line.dataset.transactionItemId || 0),
                    product_id: Number(line.dataset.productId),
                    product_serial_number_id: Number(line.dataset.productSerialNumberId || 0) || null,
                    quantity: qty,
                    reason: line.querySelector(".return-reason").value,
                    refund_method: forceStoreCredit ? "store_credit" : line.querySelector(".refund-method").value,
                    refund_mode: "original",
                    restocking_fee_percent: 0
                });
            }
        });
        return out;
    }

    el("product-search")?.addEventListener("keyup", async (e) => {
        if (e.key === "Enter") {
            try {
                const code = e.target.value.trim();
                if (code) {
                    await api("scan_code", "GET", { code });
                    e.target.value = "";
                    await refreshCart();
                    return;
                }
            } catch (_) {}
        }
        productPage = 1;
        try { await refreshProducts(); } catch (err) { toast(err.message); }
    });
    el("category-filter")?.addEventListener("change", async () => { productPage = 1; try { await refreshProducts(); } catch (e) { toast(e.message); } });
    el("scan-barcode")?.addEventListener("click", () => {
        el("product-search")?.focus();
    });
    el("refresh-products")?.addEventListener("click", async () => { try { await refreshProducts(); } catch (e) { toast(e.message); } });
    el("prev-page")?.addEventListener("click", async () => { if (productPage > 1) { productPage--; await refreshProducts(); } });
    el("next-page")?.addEventListener("click", async () => { if (productPage < productTotalPages) { productPage++; await refreshProducts(); } });

    el("clear-cart")?.addEventListener("click", async () => { try { await api("clear_cart", "POST"); await refreshCart(); } catch (e) { toast(e.message); } });

    let customerTimer = null;
    el("customer-search")?.addEventListener("keyup", (e) => {
        clearTimeout(customerTimer);
        customerTimer = setTimeout(() => searchCustomers(e.target.value).catch((err) => toast(err.message)), 250);
    });
    el("quick-customer-btn")?.addEventListener("click", async () => {
        const first = prompt("Customer first name:");
        if (!first) return;
        const last = prompt("Customer last name:");
        if (!last) return;
        const phone = prompt("Phone (optional):") || "";
        const email = prompt("Email (optional):") || "";
        try {
            const data = await api("quick_add_customer", "POST", { first_name: first, last_name: last, phone, email });
            el("selected-customer").textContent = `Customer: ${data.customer.first_name} ${data.customer.last_name}`;
            el("selected-customer").dataset.customerId = String(data.customer.id);
            applyModeUI();
        } catch (e) { toast(e.message); }
    });

    el("checkout-btn")?.addEventListener("click", async () => {
        try {
            const payments = collectPayments();
            const notes = prompt("Notes (optional):") || "";
            const emailReceipt = confirm("Email receipt to selected customer?");
            const data = await api("checkout", "POST", { payments, notes, email_receipt: emailReceipt ? "1" : "" });
            el("receipt-body").innerHTML = data.receipt_html || "";
            receiptModal?.show();
            await refreshCart();
            if (el("payment-amount")) el("payment-amount").value = "";
            toast(`Transaction saved. Change: ${fmt(data.change)}`);
            currentQuoteId = null;
            if (el("quote-number")) el("quote-number").textContent = "Q-NEW";
            if (el("quote-status")) el("quote-status").textContent = "DRAFT";
        } catch (e) { toast(e.message); }
    });
    el("print-receipt")?.addEventListener("click", () => {
        const body = el("receipt-body");
        if (!body) {
            window.print();
            return;
        }
        const w = window.open("", "_blank", "width=480,height=640");
        if (!w) {
            window.print();
            return;
        }
        w.document.write(`
            <html>
            <head>
                <title>Receipt</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 16px; color: #0f172a; }
                    hr { border: 0; border-top: 1px solid #e5e7eb; margin: 8px 0; }
                </style>
            </head>
            <body>
                ${body.innerHTML}
            </body>
            </html>
        `);
        w.document.close();
        w.focus();
        w.print();
        w.close();
    });
    el("mode-pos")?.addEventListener("click", (ev) => {
        ev.preventDefault();
        switchMode("pos").catch((e) => toast(e.message));
    });
    el("mode-quote")?.addEventListener("click", (ev) => {
        ev.preventDefault();
        switchMode("quote").catch((e) => toast(e.message));
    });
    el("show-quotes")?.addEventListener("click", async () => { try { await listQuotes(); quotesModal?.show(); } catch (e) { toast(e.message); } });
    el("save-quote-btn")?.addEventListener("click", () => saveQuote("draft").catch((e) => toast(e.message)));
    el("save-quote-draft-btn")?.addEventListener("click", () => saveQuote("draft").catch((e) => toast(e.message)));
    el("email-quote-btn")?.addEventListener("click", async () => {
        try {
            const q = await saveQuote("sent");
            await api("email_quote", "POST", { quote_id: q.id });
            toast("Quote emailed successfully.");
        } catch (e) { toast(e.message); }
    });
    el("print-quote-btn")?.addEventListener("click", async () => {
        try {
            const q = await saveQuote("draft");
            const route = `${baseUrl}/quote.php?id=${encodeURIComponent(String(q.id || ""))}`;
            const w = window.open(route, "_blank", "width=980,height=760");
            if (!w) throw new Error("Popup blocked. Allow popups to print quote.");
        } catch (e) { toast(e.message); }
    });
    el("convert-quote-btn")?.addEventListener("click", async () => {
        try {
            const q = await saveQuote("draft");
            if (!confirm(`Convert ${q.quote_number} to sale?`)) return;
            await api("convert_quote_to_sale", "POST", { quote_id: q.id });
            currentMode = "pos";
            applyModeUI();
            await refreshCart();
            toast(`Quote ${q.quote_number} converted. Ready for payment.`);
            currentQuoteId = null;
        } catch (e) { toast(e.message); }
    });

    let returnTimer = null;
    el("return-search")?.addEventListener("keyup", (e) => {
        clearTimeout(returnTimer);
        returnTimer = setTimeout(() => searchTransactions(e.target.value).catch((err) => toast(err.message)), 250);
    });
    el("process-return")?.addEventListener("click", async () => {
        try {
            if (!selectedReturnTxId) throw new Error("Select a transaction first.");
            const items = gatherReturnItems(false);
            if (!items.length) throw new Error("No return quantities selected.");
            const data = await api("process_return", "POST", { transaction_id: selectedReturnTxId, items });
            toast(`Return processed. Refund total: ${fmt(data.refund_total)}`);
            selectedReturnTxId = null;
            el("return-items").innerHTML = "";
            el("return-results").innerHTML = "";
        } catch (e) { toast(e.message); }
    });
    el("process-exchange")?.addEventListener("click", async () => {
        try {
            if (!selectedReturnTxId) throw new Error("Select a transaction first.");
            const returnItems = gatherReturnItems(true);
            if (!returnItems.length) throw new Error("Select return quantities for exchange.");
            const pid = Number(prompt("Exchange product ID to add:") || 0);
            const qty = Number(prompt("Exchange quantity:") || 1);
            if (!pid || qty <= 0) throw new Error("Invalid exchange item.");
            const payments = collectPayments();
            const data = await api("process_exchange", "POST", {
                transaction_id: selectedReturnTxId,
                return_items: returnItems,
                new_items: [{ product_id: pid, quantity: qty }],
                payments
            });
            toast(`Exchange done. New transaction #${data.exchange_transaction_id}`);
            selectedReturnTxId = null;
            el("return-items").innerHTML = "";
            el("return-results").innerHTML = "";
        } catch (e) { toast(e.message); }
    });

    refreshProducts().catch((e) => toast(e.message));
    refreshCart().catch((e) => toast(e.message));
    if (el("quote-valid-until")) {
        const d = new Date();
        d.setDate(d.getDate() + 7);
        el("quote-valid-until").value = d.toISOString().slice(0, 10);
    }
    applyModeUI();
});
