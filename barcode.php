<?php
/**
 * Barcode and QR Label Printing
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('products.view');

if (file_exists(APP_ROOT . '/vendor/autoload.php')) {
    require_once APP_ROOT . '/vendor/autoload.php';
}

$db = getDB();
$pageTitle = 'Barcode & Label Printing';

function renderCode128Html($code) {
    $safeCode = escape($code);
    if (class_exists('\Picqer\Barcode\BarcodeGeneratorSVG')) {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
        return $generator->getBarcode($code, $generator::TYPE_CODE_128, 2, 45);
    }
    return '<div class="border rounded py-3 text-center fw-bold">' . $safeCode . '</div>';
}

function renderQrDataUri($url) {
    if (class_exists('\Endroid\QrCode\Builder\Builder')) {
        $result = \Endroid\QrCode\Builder\Builder::create()
            ->data($url)
            ->size(130)
            ->margin(8)
            ->build();
        return $result->getDataUri();
    }

    if (class_exists('\Endroid\QrCode\QrCode') && class_exists('\Endroid\QrCode\Writer\PngWriter')) {
        $qrCode = new \Endroid\QrCode\QrCode($url);
        if (method_exists($qrCode, 'setSize')) {
            $qrCode->setSize(130);
        }
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);
        return 'data:image/png;base64,' . base64_encode($result->getString());
    }

    return null;
}

$products = $db->fetchAll(
    "SELECT id, sku, name, barcode, selling_price
     FROM products
     WHERE is_active = 1
     ORDER BY name
     LIMIT 1000"
);

$productMap = [];
foreach ($products as $product) {
    $productMap[(int)$product['id']] = $product;
}

$labels = [];
$renderQr = false;
$libraryNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    requirePermission('products.edit');

    try {
        $mode = $_POST['mode'] ?? 'single';
        $renderQr = isset($_POST['render_qr']);

        $selected = [];
        if ($mode === 'single') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $copies = max(1, min(100, (int)($_POST['copies'] ?? 1)));
            if ($productId <= 0 || !isset($productMap[$productId])) {
                throw new Exception('Please select a valid product.');
            }
            $selected[] = ['product' => $productMap[$productId], 'copies' => $copies];
        } else {
            $selectedIds = $_POST['product_ids'] ?? [];
            $copiesMap = $_POST['copies_map'] ?? [];
            if (empty($selectedIds)) {
                throw new Exception('Select at least one product for batch printing.');
            }
            foreach ($selectedIds as $idValue) {
                $productId = (int)$idValue;
                if (!isset($productMap[$productId])) {
                    continue;
                }
                $copies = max(1, min(100, (int)($copiesMap[$productId] ?? 1)));
                $selected[] = ['product' => $productMap[$productId], 'copies' => $copies];
            }
        }

        if ($renderQr && !class_exists('\Endroid\QrCode\Builder\Builder') && !class_exists('\Endroid\QrCode\QrCode')) {
            $libraryNotice = 'QR library not found. Install endroid/qr-code to enable QR labels.';
            $renderQr = false;
        }
        if (!class_exists('\Picqer\Barcode\BarcodeGeneratorSVG')) {
            $libraryNotice = ($libraryNotice ? $libraryNotice . ' ' : '') . 'Barcode library not found. Install picqer/php-barcode-generator for true Code128 output.';
        }

        foreach ($selected as $item) {
            $product = $item['product'];
            $barcodeValue = trim((string)($product['barcode'] ?: $product['sku']));
            $barcodeHtml = renderCode128Html($barcodeValue);
            $qrDataUri = null;

            if ($renderQr) {
                $productUrl = getBaseUrl() . '/product_view.php?id=' . (int)$product['id'];
                $qrDataUri = renderQrDataUri($productUrl);
            }

            $labels[] = [
                'product' => $product,
                'barcode_value' => $barcodeValue,
                'barcode_html' => $barcodeHtml,
                'qr_data_uri' => $qrDataUri,
                'copies' => $item['copies']
            ];
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        redirect(getBaseUrl() . '/barcode.php');
    }
}

include 'templates/header.php';
?>

<style>
.label-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.print-label {
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 10px;
    background: #fff;
}
.barcode-wrap svg {
    width: 100%;
    height: 52px;
}
@media print {
    .no-print, nav, footer, .alert {
        display: none !important;
    }
    .print-label {
        page-break-inside: avoid;
        break-inside: avoid;
        border-color: #aaa;
    }
    .label-grid {
        gap: 6px;
    }
}
</style>

<div class="row mb-4 no-print">
    <div class="col-12">
        <h1 class="h3 mb-0">Barcode & QR Label Printing</h1>
    </div>
</div>

<?php if ($libraryNotice): ?>
<div class="alert alert-warning no-print"><?php echo escape($libraryNotice); ?></div>
<?php endif; ?>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Mode</label>
                    <select class="form-select" id="mode" name="mode">
                        <option value="single">Single Product</option>
                        <option value="batch" <?php echo ($_POST['mode'] ?? '') === 'batch' ? 'selected' : ''; ?>>Batch</option>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="render_qr" id="render_qr" <?php echo $renderQr ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="render_qr">Include QR code with product URL</label>
                    </div>
                </div>
            </div>

            <div id="singleModePanel">
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Product</label>
                        <select class="form-select" name="product_id">
                            <option value="">Select product</option>
                            <?php foreach ($products as $product): ?>
                            <option value="<?php echo (int)$product['id']; ?>" <?php echo (int)($_POST['product_id'] ?? 0) === (int)$product['id'] ? 'selected' : ''; ?>>
                                <?php echo escape($product['name'] . ' [' . $product['sku'] . ']'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Copies</label>
                        <input type="number" class="form-control" name="copies" min="1" max="100" value="<?php echo (int)($_POST['copies'] ?? 1); ?>">
                    </div>
                </div>
            </div>

            <div id="batchModePanel" class="d-none">
                <div class="table-responsive" style="max-height: 360px;">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Barcode</th>
                                <th>Copies</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $selectedIds = $_POST['product_ids'] ?? []; ?>
                            <?php foreach ($products as $product): ?>
                            <?php $pid = (int)$product['id']; ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input" name="product_ids[]" value="<?php echo $pid; ?>" <?php echo in_array((string)$pid, array_map('strval', $selectedIds), true) ? 'checked' : ''; ?>>
                                </td>
                                <td><?php echo escape($product['name']); ?></td>
                                <td><?php echo escape($product['sku']); ?></td>
                                <td><?php echo escape($product['barcode'] ?: $product['sku']); ?></td>
                                <td>
                                    <input type="number" class="form-control form-control-sm" name="copies_map[<?php echo $pid; ?>]" min="1" max="100" value="<?php echo (int)($_POST['copies_map'][$pid] ?? 1); ?>" style="width: 90px;">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <button type="submit" class="btn btn-primary mt-3">
                <i class="bi bi-upc-scan"></i> Generate Labels
            </button>
            <button type="button" class="btn btn-outline-secondary mt-3" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </form>
    </div>
</div>

<?php if (!empty($labels)): ?>
<div class="label-grid">
    <?php foreach ($labels as $label): ?>
        <?php for ($i = 0; $i < $label['copies']; $i++): ?>
        <div class="print-label">
            <div class="small text-muted"><?php echo escape($label['product']['sku']); ?></div>
            <div class="fw-bold mb-1"><?php echo escape($label['product']['name']); ?></div>
            <div class="barcode-wrap mb-1"><?php echo $label['barcode_html']; ?></div>
            <div class="small text-center mb-2"><?php echo escape($label['barcode_value']); ?></div>
            <div class="d-flex justify-content-between align-items-end">
                <div class="small">
                    Price: <?php echo formatCurrency($label['product']['selling_price']); ?>
                </div>
                <?php if (!empty($label['qr_data_uri'])): ?>
                <img src="<?php echo escape($label['qr_data_uri']); ?>" alt="QR Code" width="70" height="70">
                <?php endif; ?>
            </div>
        </div>
        <?php endfor; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function toggleModePanels() {
    const mode = document.getElementById('mode').value;
    document.getElementById('singleModePanel').classList.toggle('d-none', mode !== 'single');
    document.getElementById('batchModePanel').classList.toggle('d-none', mode !== 'batch');
}

document.getElementById('mode').addEventListener('change', toggleModePanels);
toggleModePanels();
</script>

<?php include 'templates/footer.php'; ?>
