<?php
/**
 * Warranty Intake (Simplified)
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('sales.view');

$db = getDB();
$pageTitle = 'Warranty Intake';

$warranty_table = getWarrantyTableName();
if ($warranty_table === null) {
    setFlashMessage('error', 'Warranty table is missing. Run database schema updates first.');
    include 'templates/header.php';
    echo '<div class="alert alert-danger">Warranty module is unavailable because required tables are missing.</div>';
    include 'templates/footer.php';
    exit;
}

$serial = trim((string)($_GET['serial'] ?? ''));
$claimRecorded = (string)($_GET['claim'] ?? '') === 'recorded';
$warranty = null;
if ($serial !== '') {
    $warranty = $db->fetchOne(
        "SELECT w.*, p.name AS product_name, p.sku, t.transaction_number, t.transaction_date,
                CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
         FROM {$warranty_table} w
         LEFT JOIN products p ON p.id = w.product_id
         LEFT JOIN transaction_items ti ON ti.id = w.transaction_item_id
         LEFT JOIN transactions t ON t.id = ti.transaction_id
         LEFT JOIN customers c ON c.id = w.customer_id
         WHERE w.serial_number = ?
         ORDER BY w.id DESC
         LIMIT 1",
        [$serial]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if (! _logTableExists('warranty_claims')) {
            throw new Exception('Warranty claims table is missing.');
        }

        $warrantyId = (int)($_POST['warranty_id'] ?? 0);
        $serialInput = trim((string)($_POST['serial_number'] ?? ''));
        $reason = trim((string)($_POST['reason_for_failure'] ?? ''));

        $voidLiquid = !empty($_POST['void_liquid']) ? 0 : 1;
        $voidImpact = !empty($_POST['void_impact']) ? 0 : 1;
        $voidSeals = !empty($_POST['void_seals']) ? 0 : 1;

        if ($warrantyId <= 0 || $serialInput === '') {
            throw new Exception('Warranty record not found. Search by serial number first.');
        }
        if ($voidLiquid || $voidImpact || $voidSeals) {
            throw new Exception('Void checklist must be all checked to proceed.');
        }

        $w = $db->fetchOne("SELECT * FROM {$warranty_table} WHERE id = ? LIMIT 1", [$warrantyId]);
        if (!$w) {
            throw new Exception('Warranty record not found.');
        }

        $existing = $db->fetchOne(
            "SELECT wc.id
             FROM warranty_claims wc
             INNER JOIN {$warranty_table} w2 ON w2.id = wc.warranty_id
             WHERE w2.serial_number = ?
               AND wc.status NOT IN ('completed','rejected')
             ORDER BY wc.id DESC
             LIMIT 1",
            [$w['serial_number']]
        );

        $inWarranty = 0;
        if (!empty($w['warranty_end'])) {
            $inWarranty = (strtotime($w['warranty_end']) >= strtotime(date('Y-m-d'))) ? 1 : 0;
        }

        if ($action === 'record_claim') {
            if (!empty($existing['id'])) {
                throw new Exception('An active warranty claim already exists for this serial number.');
            }
            if ($reason === '') {
                throw new Exception('Reason for failure is required.');
            }
            $db->insert('warranty_claims', [
                'warranty_id' => (int)$w['id'],
                'claim_date' => date('Y-m-d'),
                'status' => 'pending',
                'notes' => $reason !== '' ? $reason : null,
                'claim_reason' => $reason !== '' ? $reason : null,
                'in_warranty' => $inWarranty,
                'void_liquid_damage' => 0,
                'void_physical_damage' => 0,
                'void_tampered_seal' => 0,
                'void_serial_label' => 0,
                'zero_cost' => 1,
                'internal_labor_cost' => 0,
                'internal_parts_cost' => 0,
                'internal_total_cost' => 0,
                'resolution_action' => 'recorded',
                'created_by' => getCurrentUserId()
            ]);
            $db->query("UPDATE {$warranty_table} SET status = 'claimed' WHERE id = ?", [(int)$w['id']]);
            setFlashMessage('success', 'Claim recorded. Scan new serial to finalize exchange.');
            redirect(getBaseUrl() . '/warranty_intake.php?serial=' . urlencode($serialInput) . '&claim=recorded');
        }

        if ($action === 'send_repair') {
            if (empty($existing['id'])) {
                $db->insert('warranty_claims', [
                    'warranty_id' => (int)$w['id'],
                    'claim_date' => date('Y-m-d'),
                    'status' => 'pending',
                    'notes' => $reason !== '' ? $reason : null,
                    'claim_reason' => $reason !== '' ? $reason : null,
                    'in_warranty' => $inWarranty,
                    'void_liquid_damage' => 0,
                    'void_physical_damage' => 0,
                    'void_tampered_seal' => 0,
                    'void_serial_label' => 0,
                    'zero_cost' => 1,
                    'internal_labor_cost' => 0,
                    'internal_parts_cost' => 0,
                    'internal_total_cost' => 0,
                    'resolution_action' => 'repair_ticket',
                    'created_by' => getCurrentUserId()
                ]);
                $db->query("UPDATE {$warranty_table} SET status = 'claimed' WHERE id = ?", [(int)$w['id']]);
            } else {
                $db->query("UPDATE warranty_claims SET resolution_action = 'repair_ticket' WHERE id = ?", [(int)$existing['id']]);
            }
                $db->insert('repairs', [
                    'customer_id' => $w['customer_id'] ?? null,
                    'product_id' => $w['product_id'] ?? null,
                    'serial_number' => $w['serial_number'],
                    'model' => null,
                    'customer_reported_issue' => $reason !== '' ? $reason : 'Warranty repair intake',
                    'initial_quote' => 0,
                    'status' => 'received',
                    'notes' => 'Warranty intake created from serial ' . $w['serial_number']
                ]);
                $db->query("UPDATE warranty_claims SET status = 'completed' WHERE id = ?", [(int)$existing['id']]);
        }

        if ($action === 'finalize_exchange') {
            $newSerial = trim((string)($_POST['new_serial'] ?? ''));
            if ($newSerial === '') {
                throw new Exception('New serial number is required to finalize exchange.');
            }
            if (empty($existing['id'])) {
                throw new Exception('No recorded claim found for this serial. Record claim first.');
            }
            $db->query(
                "UPDATE warranty_claims
                 SET notes = CONCAT(COALESCE(notes,''), '\nExchange Serial: ', ?),
                     resolution_action = 'exchange_unit'
                 WHERE id = ?",
                [$newSerial, (int)$existing['id']]
            );
            $db->query("UPDATE warranty_claims SET status = 'completed' WHERE id = ?", [(int)$existing['id']]);
        }

        setFlashMessage('success', 'Warranty claim recorded.');
        redirect(getBaseUrl() . '/warranty_intake.php?serial=' . urlencode($serialInput));
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        redirect(getBaseUrl() . '/warranty_intake.php?serial=' . urlencode($serialInput));
    }
}

include 'templates/header.php';
?>

<div class="container-fluid">
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white">
            <strong>Warranty Intake</strong>
        </div>
        <div class="card-body">
            <form method="GET" class="mb-3">
                <label class="form-label">Search by Serial Number</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span>
                    <input type="text" class="form-control" name="serial" value="<?php echo escape($serial); ?>" placeholder="Scan or enter serial number" required>
                    <button class="btn btn-outline-secondary" type="submit">Search</button>
                </div>
            </form>

            <?php if ($warranty): ?>
                <?php
                $isActive = !empty($warranty['warranty_end']) && strtotime($warranty['warranty_end']) >= strtotime(date('Y-m-d'));
                ?>
                <div class="card border-0 bg-light mb-3">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?php echo escape($warranty['customer_name'] ?? 'Guest'); ?></div>
                            <div class="small text-muted">Invoice: <?php echo escape($warranty['transaction_number'] ?? '-'); ?></div>
                            <div class="small text-muted">Purchase: <?php echo escape($warranty['warranty_start'] ?? '-'); ?></div>
                            <div class="small text-muted">Expires: <?php echo escape($warranty['warranty_end'] ?? '-'); ?></div>
                            <div class="small text-muted">Product: <?php echo escape(($warranty['product_name'] ?? '') . ' (' . ($warranty['sku'] ?? '-') . ')'); ?></div>
                            <div class="small text-muted">Serial: <?php echo escape($warranty['serial_number'] ?? ''); ?></div>
                        </div>
                        <span class="badge <?php echo $isActive ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                            <?php echo $isActive ? 'ACTIVE' : 'EXPIRED'; ?>
                        </span>
                    </div>
                </div>

                <form method="POST" id="intakeForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="warranty_id" value="<?php echo (int)$warranty['id']; ?>">
                    <input type="hidden" name="serial_number" value="<?php echo escape($warranty['serial_number'] ?? ''); ?>">
                    <input type="hidden" name="reason_for_failure" id="reason_for_failure" value="">
                    <input type="hidden" name="new_serial" id="new_serial" value="">
                    <input type="hidden" name="action" id="actionField" value="">

                    <div class="mb-3">
                        <label class="form-label">Void Checklist</label>
                        <div class="d-flex flex-wrap gap-3">
                            <label class="form-check">
                                <input class="form-check-input void-check" type="checkbox" name="void_liquid" value="1">
                                No Liquid Damage
                            </label>
                            <label class="form-check">
                                <input class="form-check-input void-check" type="checkbox" name="void_impact" value="1">
                                No Physical Impact
                            </label>
                            <label class="form-check">
                                <input class="form-check-input void-check" type="checkbox" name="void_seals" value="1">
                                Warranty Seals Intact
                            </label>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary" id="recordClaimBtn" disabled>Record Claim</button>
                        <button type="button" class="btn btn-primary" id="exchangeBtn" disabled data-bs-toggle="modal" data-bs-target="#exchangeModal">Exchange for New Unit</button>
                        <button type="button" class="btn btn-outline-secondary" id="repairBtn" disabled>Send for Repair</button>
                    </div>
                </form>
            <?php elseif ($serial !== ''): ?>
                <div class="alert alert-warning">No warranty record found for this serial number.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Exchange Modal -->
<div class="modal fade" id="exchangeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Exchange for New Unit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2 text-muted small">Enter the new serial number for the replacement unit.</div>
                <label class="form-label">New Serial Number</label>
                <input type="text" class="form-control" id="exchangeSerialInput" placeholder="Scan or type new serial">
                <label class="form-label mt-3">Reason for Failure</label>
                <input type="text" class="form-control" id="exchangeReasonInput" placeholder="Dead on Arrival, Failed Motherboard, etc.">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmExchangeBtn">Finalize Exchange</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const checks = document.querySelectorAll('.void-check');
    const exchangeBtn = document.getElementById('exchangeBtn');
    const recordClaimBtn = document.getElementById('recordClaimBtn');
    const repairBtn = document.getElementById('repairBtn');
    const actionField = document.getElementById('actionField');
    const reasonField = document.getElementById('reason_for_failure');
    const newSerialField = document.getElementById('new_serial');
    const form = document.getElementById('intakeForm');
    const exchangeSerialInput = document.getElementById('exchangeSerialInput');
    const exchangeReasonInput = document.getElementById('exchangeReasonInput');
    const confirmExchangeBtn = document.getElementById('confirmExchangeBtn');

    function updateButtons() {
        let allChecked = true;
        checks.forEach(c => { if (!c.checked) allChecked = false; });
        if (recordClaimBtn) recordClaimBtn.disabled = !allChecked;
        if (exchangeBtn) exchangeBtn.disabled = !allChecked || !<?php echo $claimRecorded ? 'true' : 'false'; ?>;
        if (repairBtn) repairBtn.disabled = !allChecked;
    }

    if (checks.length) {
        checks.forEach(c => c.addEventListener('change', updateButtons));
        updateButtons();
    }

    if (recordClaimBtn) {
        recordClaimBtn.addEventListener('click', () => {
            const reason = window.prompt('Reason for Failure (e.g., Dead on Arrival, Failed Motherboard):', '');
            if (!reason) return;
            if (reasonField) reasonField.value = reason;
            if (actionField) actionField.value = 'record_claim';
            if (form) form.submit();
        });
    }

    if (confirmExchangeBtn) {
        confirmExchangeBtn.addEventListener('click', () => {
            const newSerial = (exchangeSerialInput?.value || '').trim();
            const reason = (exchangeReasonInput?.value || '').trim();
            if (!newSerial) {
                alert('New serial number is required.');
                return;
            }
            if (newSerialField) newSerialField.value = newSerial;
            if (reasonField && reason) reasonField.value = reason;
            if (actionField) actionField.value = 'finalize_exchange';
            if (form) form.submit();
        });
    }

    if (repairBtn) {
        repairBtn.addEventListener('click', () => {
            const reason = window.prompt('Reason for Failure (e.g., Dead on Arrival, Failed Motherboard):', '');
            if (reasonField) reasonField.value = reason || '';
            if (actionField) actionField.value = 'send_repair';
            if (form) form.submit();
        });
    }

    if (exchangeSerialInput) {
        exchangeSerialInput.disabled = !<?php echo $claimRecorded ? 'true' : 'false'; ?>;
    }
    if (confirmExchangeBtn) {
        confirmExchangeBtn.disabled = !<?php echo $claimRecorded ? 'true' : 'false'; ?>;
    }
})();
</script>

<?php include 'templates/footer.php'; ?>
