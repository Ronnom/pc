<?php
require_once 'includes/init.php';
requireLogin();
requirePermission('reports.view');

$db = getDB();

function reportTableExists($db, $table) {
    $row = $db->fetchOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
    return (int)($row['cnt'] ?? 0) > 0;
}

function reportColumnExists($db, $table, $column) {
    $row = $db->fetchOne("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$table, $column]);
    return (int)($row['cnt'] ?? 0) > 0;
}

function reportFirstColumn($db, $table, array $columns) {
    foreach ($columns as $column) {
        if (reportColumnExists($db, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function reportSlug($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim((string)$value, '-') ?: 'report';
}

$today = date('Y-m-d');
$default_start = date('Y-m-d', strtotime('-29 days'));
$start_date = $_GET['start_date'] ?? $default_start;
$end_date = $_GET['end_date'] ?? $today;
$category_filter = trim((string)($_GET['category'] ?? ''));
$location_filter = trim((string)($_GET['location'] ?? ''));
$cashier_filter = trim((string)($_GET['cashier'] ?? ''));
$technician_filter = trim((string)($_GET['technician'] ?? ''));
$margin_sort = ($_GET['margin_sort'] ?? 'profit') === 'margin' ? 'margin' : 'profit';

$isValidDate = static function ($value) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$value);
    return $dt && $dt->format('Y-m-d') === $value;
};
if (!$isValidDate($start_date)) $start_date = $default_start;
if (!$isValidDate($end_date)) $end_date = $today;
if ($start_date > $end_date) {
    $start_date = $default_start;
    $end_date = $today;
}
$end_date_exclusive = date('Y-m-d', strtotime($end_date . ' +1 day'));

$categories_exists = reportTableExists($db, 'categories');
$locations_exists = reportTableExists($db, 'stock_locations');
$product_locations_exists = reportTableExists($db, 'product_locations');
$stock_movements_exists = reportTableExists($db, 'stock_movements');
$serials_exists = reportTableExists($db, 'product_serial_numbers');

$products_cost_col = reportFirstColumn($db, 'products', ['cost_price', 'cost', 'unit_cost']);
$products_price_col = reportFirstColumn($db, 'products', ['selling_price', 'sell_price', 'price']);
$products_stock_col = reportFirstColumn($db, 'products', ['stock_quantity']);
$products_location_col = reportFirstColumn($db, 'products', ['location']);
$products_active_col = reportFirstColumn($db, 'products', ['is_active']);
$products_deleted_col = reportFirstColumn($db, 'products', ['deleted_at']);
$ti_serial_cost_col = reportFirstColumn($db, 'transaction_items', ['serial_cost_price']);
$ti_serial_id_col = reportFirstColumn($db, 'transaction_items', ['product_serial_number_id']);
$psn_location_col = reportFirstColumn($db, 'product_serial_numbers', ['location_id']);
$sm_type_col = reportFirstColumn($db, 'stock_movements', ['movement_type', 'type']);
$sm_date_col = reportFirstColumn($db, 'stock_movements', ['created_at', 'created_date']);
$sm_location_col = reportFirstColumn($db, 'stock_movements', ['location_id']);
$repairs_request_col = reportFirstColumn($db, 'repairs', ['request_date', 'created_at']);
$repair_tech_col = reportFirstColumn($db, 'repairs', ['assigned_technician_id', 'technician_id', 'created_by', 'updated_by']);

$cost_expr = $products_cost_col ? "COALESCE(p.`{$products_cost_col}`, 0)" : '0';
$price_expr = $products_price_col ? "COALESCE(p.`{$products_price_col}`, 0)" : '0';
$stock_expr = $products_stock_col ? "COALESCE(p.`{$products_stock_col}`, 0)" : '0';
$category_expr = ($categories_exists && reportColumnExists($db, 'products', 'category_id')) ? "COALESCE(c.name, 'Uncategorized')" : "'Uncategorized'";
$category_join = ($categories_exists && reportColumnExists($db, 'products', 'category_id')) ? "LEFT JOIN categories c ON c.id = p.category_id" : '';
$active_condition = $products_active_col ? " AND COALESCE(p.`{$products_active_col}`, 1) = 1" : '';
$deleted_condition = $products_deleted_col ? " AND p.`{$products_deleted_col}` IS NULL" : '';

$categories = ($categories_exists && reportColumnExists($db, 'products', 'category_id')) ? $db->fetchAll("SELECT id, name FROM categories ORDER BY name") : [];
$locations = $locations_exists ? $db->fetchAll("SELECT id, name FROM stock_locations WHERE COALESCE(is_active, 1) = 1 ORDER BY name") : [];
$cashiers = $db->fetchAll("SELECT DISTINCT t.user_id AS id, COALESCE(u.full_name, u.username, CONCAT('User #', t.user_id)) AS name FROM transactions t LEFT JOIN users u ON u.id = t.user_id WHERE t.user_id IS NOT NULL ORDER BY name");
$technicians = $repair_tech_col ? $db->fetchAll("SELECT DISTINCT r.`{$repair_tech_col}` AS id, COALESCE(u.full_name, u.username, CONCAT('User #', r.`{$repair_tech_col}`)) AS name FROM repairs r LEFT JOIN users u ON u.id = r.`{$repair_tech_col}` WHERE r.`{$repair_tech_col}` IS NOT NULL ORDER BY name") : [];

$selected_location_name = '';
foreach ($locations as $loc) {
    if ((string)$loc['id'] === $location_filter) $selected_location_name = (string)$loc['name'];
}

$category_condition = '';
$category_params = [];
if ($category_filter === 'repairs') {
    $category_condition = " AND (p.sku IN ('REPAIR-SERVICE','SERVICE-FEE') OR p.name IN ('Repair Service','Service Fee'))";
} elseif (ctype_digit($category_filter) && (int)$category_filter > 0 && reportColumnExists($db, 'products', 'category_id')) {
    $category_condition = " AND p.category_id = ?";
    $category_params[] = (int)$category_filter;
}

$location_condition = '';
$location_params = [];
if (ctype_digit($location_filter) && (int)$location_filter > 0) {
    if ($product_locations_exists) {
        $location_condition = " AND EXISTS (SELECT 1 FROM product_locations plf WHERE plf.product_id = p.id AND plf.location_id = ?)";
        $location_params[] = (int)$location_filter;
    } elseif ($products_location_col && $selected_location_name !== '') {
        $location_condition = " AND LOWER(TRIM(COALESCE(p.`{$products_location_col}`, ''))) = LOWER(TRIM(?))";
        $location_params[] = $selected_location_name;
    }
}

$cashier_condition = '';
$cashier_params = [];
if (ctype_digit($cashier_filter) && (int)$cashier_filter > 0) {
    $cashier_condition = " AND t.user_id = ?";
    $cashier_params[] = (int)$cashier_filter;
}

$tech_condition = '';
$tech_params = [];
if ($repair_tech_col && ctype_digit($technician_filter) && (int)$technician_filter > 0) {
    $tech_condition = " AND r.`{$repair_tech_col}` = ?";
    $tech_params[] = (int)$technician_filter;
}

$scope_condition = $category_condition . $location_condition;
$scope_params = array_merge($category_params, $location_params);

$kpi_sales = $db->fetchOne("SELECT COALESCE(SUM(ti.quantity * ti.unit_price), 0) AS total FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
$kpi_profit = $db->fetchOne("SELECT COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(" . ($ti_serial_cost_col ? "ti.`{$ti_serial_cost_col}`" : 'NULL') . ", {$cost_expr}))), 0) AS total FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
$kpi_repairs = $db->fetchOne("SELECT COUNT(*) AS total FROM repairs WHERE status IN ('received','diagnosing','repairing','ready')");
$service_performance = $db->fetchAll("SELECT DATE(r.updated_at) AS day, ROUND(AVG(DATEDIFF(r.updated_at, r.`{$repairs_request_col}`)), 1) AS avg_days FROM repairs r WHERE r.status = 'completed' AND r.updated_at >= ? AND r.updated_at < ?{$tech_condition} GROUP BY DATE(r.updated_at) ORDER BY day ASC", array_merge([$start_date, $end_date_exclusive], $tech_params));

$inventory_rows = [];
if ($product_locations_exists && $locations_exists) {
    $inventory_rows = $db->fetchAll("SELECT {$category_expr} AS category_name, COALESCE(l.name, 'Unassigned') AS location_name, COALESCE(pl.quantity, 0) AS qty, {$cost_expr} AS unit_cost, {$price_expr} AS unit_price FROM products p {$category_join} JOIN product_locations pl ON pl.product_id = p.id LEFT JOIN stock_locations l ON l.id = pl.location_id WHERE 1=1{$active_condition}{$deleted_condition}{$category_condition}" . (ctype_digit($location_filter) && (int)$location_filter > 0 ? " AND pl.location_id = ?" : ''), array_merge($category_params, ctype_digit($location_filter) && (int)$location_filter > 0 ? [(int)$location_filter] : []));
} else {
    $inventory_rows = $db->fetchAll("SELECT {$category_expr} AS category_name, COALESCE(NULLIF(TRIM(" . ($products_location_col ? "p.`{$products_location_col}`" : "''") . "), ''), 'All Locations') AS location_name, {$stock_expr} AS qty, {$cost_expr} AS unit_cost, {$price_expr} AS unit_price FROM products p {$category_join} WHERE 1=1{$active_condition}{$deleted_condition}{$category_condition}{$location_condition}", array_merge($category_params, $location_params));
}

$inventory_groups = [];
foreach ($inventory_rows as $row) {
    $key = ($row['category_name'] ?? 'Uncategorized') . '|' . ($row['location_name'] ?? 'All Locations');
    if (!isset($inventory_groups[$key])) {
        $inventory_groups[$key] = ['category_name' => $row['category_name'] ?? 'Uncategorized', 'location_name' => $row['location_name'] ?? 'All Locations', 'on_hand' => 0, 'cost_value' => 0, 'retail_value' => 0, 'cogs' => 0];
    }
    $qty = (float)($row['qty'] ?? 0);
    $inventory_groups[$key]['on_hand'] += $qty;
    $inventory_groups[$key]['cost_value'] += $qty * (float)($row['unit_cost'] ?? 0);
    $inventory_groups[$key]['retail_value'] += $qty * (float)($row['unit_price'] ?? 0);
}

$cogs_rows = $db->fetchAll("SELECT CASE WHEN p.sku IN ('REPAIR-SERVICE','SERVICE-FEE') OR p.name IN ('Repair Service','Service Fee') THEN 'Repairs' ELSE {$category_expr} END AS category_name, " . (($ti_serial_id_col && $serials_exists && $psn_location_col && $locations_exists) ? "COALESCE(l.name, 'Unassigned')" : "'" . addslashes($selected_location_name !== '' ? $selected_location_name : 'All Locations') . "'") . " AS location_name, COALESCE(SUM(ti.quantity * COALESCE(" . ($ti_serial_cost_col ? "ti.`{$ti_serial_cost_col}`" : 'NULL') . ", {$cost_expr})), 0) AS cogs FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id {$category_join} " . (($ti_serial_id_col && $serials_exists && $psn_location_col && $locations_exists) ? "LEFT JOIN product_serial_numbers psn ON psn.id = ti.`{$ti_serial_id_col}` LEFT JOIN stock_locations l ON l.id = psn.`{$psn_location_col}`" : "") . " WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition}" . (($ti_serial_id_col && $serials_exists && $psn_location_col && $locations_exists && ctype_digit($location_filter) && (int)$location_filter > 0) ? " AND psn.`{$psn_location_col}` = ?" : '') . " GROUP BY category_name, location_name", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params, (($ti_serial_id_col && $serials_exists && $psn_location_col && $locations_exists && ctype_digit($location_filter) && (int)$location_filter > 0) ? [(int)$location_filter] : [])));
foreach ($cogs_rows as $row) {
    $key = ($row['category_name'] ?? 'Uncategorized') . '|' . ($row['location_name'] ?? 'All Locations');
    if (!isset($inventory_groups[$key])) {
        $inventory_groups[$key] = ['category_name' => $row['category_name'] ?? 'Uncategorized', 'location_name' => $row['location_name'] ?? 'All Locations', 'on_hand' => 0, 'cost_value' => 0, 'retail_value' => 0, 'cogs' => 0];
    }
    $inventory_groups[$key]['cogs'] += (float)($row['cogs'] ?? 0);
}
$inventory_groups = array_values($inventory_groups);
usort($inventory_groups, static fn($a, $b) => ($b['cost_value'] <=> $a['cost_value']));
$inventory_totals = ['on_hand' => 0, 'cost_value' => 0, 'retail_value' => 0, 'cogs' => 0];
foreach ($inventory_groups as $row) {
    $inventory_totals['on_hand'] += $row['on_hand'];
    $inventory_totals['cost_value'] += $row['cost_value'];
    $inventory_totals['retail_value'] += $row['retail_value'];
    $inventory_totals['cogs'] += $row['cogs'];
}

$turnover_rows = [];
if ($stock_movements_exists && $sm_type_col && $sm_date_col) {
    $turnover_rows = $db->fetchAll("SELECT p.name, {$category_expr} AS category_name, {$stock_expr} AS current_stock, COALESCE(s.sold_units, 0) AS sold_units, COALESCE(m.received_units, 0) AS received_units FROM products p {$category_join} LEFT JOIN (SELECT ti.product_id, COALESCE(SUM(ti.quantity), 0) AS sold_units FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition} GROUP BY ti.product_id) s ON s.product_id = p.id LEFT JOIN (SELECT sm.product_id, COALESCE(SUM(CASE WHEN sm.`{$sm_type_col}` = 'in' THEN ABS(sm.quantity) ELSE 0 END), 0) AS received_units FROM stock_movements sm JOIN products p ON p.id = sm.product_id WHERE sm.`{$sm_date_col}` >= ? AND sm.`{$sm_date_col}` < ?{$category_condition}{$location_condition}" . (($sm_location_col && ctype_digit($location_filter) && (int)$location_filter > 0) ? " AND sm.`{$sm_location_col}` = ?" : '') . " GROUP BY sm.product_id) m ON m.product_id = p.id WHERE 1=1{$active_condition}{$deleted_condition}{$category_condition}{$location_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params, [$start_date, $end_date_exclusive], $category_params, $location_params, (($sm_location_col && ctype_digit($location_filter) && (int)$location_filter > 0) ? [(int)$location_filter] : []), $category_params, $location_params));
} else {
    $turnover_rows = $db->fetchAll("SELECT p.name, {$category_expr} AS category_name, {$stock_expr} AS current_stock, COALESCE(s.sold_units, 0) AS sold_units, 0 AS received_units FROM products p {$category_join} LEFT JOIN (SELECT ti.product_id, COALESCE(SUM(ti.quantity), 0) AS sold_units FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition} GROUP BY ti.product_id) s ON s.product_id = p.id WHERE 1=1{$active_condition}{$deleted_condition}{$category_condition}{$location_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params, $category_params, $location_params));
}

$turnover_metrics = [];
$turnover_chart = [];
$slow_movers = 0;
foreach ($turnover_rows as $row) {
    $current = (float)($row['current_stock'] ?? 0);
    $sold = (float)($row['sold_units'] ?? 0);
    $received = (float)($row['received_units'] ?? 0);
    $opening = max($current - $received + $sold, 0);
    $available = $received > 0 ? ($opening + $received) : ($current + $sold);
    $avg_inventory = ($opening + $current) / 2;
    $turnover = $avg_inventory > 0 ? $sold / $avg_inventory : 0;
    $sell_through = $available > 0 ? ($sold / $available) * 100 : 0;
    if ($sold > 0 && $sell_through < 20) $slow_movers++;
    $turnover_metrics[] = ['name' => $row['name'], 'category_name' => $row['category_name'], 'opening' => $opening, 'received' => $received, 'available' => $available, 'sold' => $sold, 'current' => $current, 'turnover' => $turnover, 'sell_through' => $sell_through];
    if (!isset($turnover_chart[$row['category_name']])) $turnover_chart[$row['category_name']] = ['category_name' => $row['category_name'], 'sold' => 0, 'available' => 0, 'avg_inventory' => 0];
    $turnover_chart[$row['category_name']]['sold'] += $sold;
    $turnover_chart[$row['category_name']]['available'] += $available;
    $turnover_chart[$row['category_name']]['avg_inventory'] += $avg_inventory;
}
usort($turnover_metrics, static fn($a, $b) => ($b['turnover'] <=> $a['turnover']) ?: ($b['sell_through'] <=> $a['sell_through']));
$turnover_chart = array_values($turnover_chart);
foreach ($turnover_chart as &$row) {
    $row['turnover'] = $row['avg_inventory'] > 0 ? $row['sold'] / $row['avg_inventory'] : 0;
    $row['sell_through'] = $row['available'] > 0 ? ($row['sold'] / $row['available']) * 100 : 0;
}
unset($row);
usort($turnover_chart, static fn($a, $b) => ($b['sell_through'] <=> $a['sell_through']));

$margin_rows = $db->fetchAll("SELECT p.name, {$category_expr} AS category_name, {$cost_expr} AS cost_price, {$price_expr} AS sale_price, COALESCE(SUM(ti.quantity), 0) AS units_sold, COALESCE(SUM(ti.quantity * ti.unit_price), 0) AS revenue, COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(" . ($ti_serial_cost_col ? "ti.`{$ti_serial_cost_col}`" : 'NULL') . ", {$cost_expr}))), 0) AS total_profit FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id {$category_join} WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition} GROUP BY p.id, p.name, category_name, cost_price, sale_price ORDER BY " . ($margin_sort === 'margin' ? "CASE WHEN {$price_expr} > 0 THEN (({$price_expr} - {$cost_expr}) / {$price_expr}) ELSE 0 END DESC, total_profit DESC" : "total_profit DESC") . " LIMIT 25", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
$avg_margin = 0;
$top_margin_name = 'N/A';
$top_profit_name = 'N/A';
$top_margin = -INF;
$top_profit = -INF;
foreach ($margin_rows as &$row) {
    $sale = (float)($row['sale_price'] ?? 0);
    $cost = (float)($row['cost_price'] ?? 0);
    $row['margin_pct'] = $sale > 0 ? (($sale - $cost) / $sale) * 100 : 0;
    $avg_margin += $row['margin_pct'];
    if ($row['margin_pct'] > $top_margin) {
        $top_margin = $row['margin_pct'];
        $top_margin_name = (string)$row['name'];
    }
    if ((float)$row['total_profit'] > $top_profit) {
        $top_profit = (float)$row['total_profit'];
        $top_profit_name = (string)$row['name'];
    }
}
unset($row);
$avg_margin = count($margin_rows) ? $avg_margin / count($margin_rows) : 0;

$heat_rows = $db->fetchAll("SELECT WEEKDAY(t.transaction_date) AS weekday_idx, DAYNAME(t.transaction_date) AS weekday_name, HOUR(t.transaction_date) AS hour_bucket, COUNT(*) AS tx_count, AVG(t.total_amount) AS avg_ticket, SUM(t.total_amount) AS total_sales FROM transactions t WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition} AND EXISTS (SELECT 1 FROM transaction_items ti JOIN products p ON p.id = ti.product_id WHERE ti.transaction_id = t.id{$scope_condition}) GROUP BY WEEKDAY(t.transaction_date), DAYNAME(t.transaction_date), HOUR(t.transaction_date) ORDER BY weekday_idx ASC, hour_bucket ASC", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
$heat_units_rows = $db->fetchAll("SELECT WEEKDAY(t.transaction_date) AS weekday_idx, HOUR(t.transaction_date) AS hour_bucket, COALESCE(SUM(ti.quantity), 0) AS units_sold FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition} GROUP BY WEEKDAY(t.transaction_date), HOUR(t.transaction_date)", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));

$weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$heatmap = [];
for ($d = 0; $d < 7; $d++) {
    for ($h = 0; $h < 24; $h++) $heatmap[$d][$h] = ['day' => $weekdays[$d], 'hour' => $h, 'tx' => 0, 'avg_ticket' => 0, 'units' => 0, 'sales' => 0];
}
$peak_value = 0;
$peak_label = 'N/A';
$total_tx = 0;
$ticket_sum = 0;
$ticket_count = 0;
foreach ($heat_rows as $row) {
    $d = (int)($row['weekday_idx'] ?? 0);
    $h = (int)($row['hour_bucket'] ?? 0);
    $heatmap[$d][$h]['tx'] = (int)($row['tx_count'] ?? 0);
    $heatmap[$d][$h]['avg_ticket'] = (float)($row['avg_ticket'] ?? 0);
    $heatmap[$d][$h]['sales'] = (float)($row['total_sales'] ?? 0);
    $total_tx += (int)($row['tx_count'] ?? 0);
    if ((int)($row['tx_count'] ?? 0) > 0) {
        $ticket_sum += (float)($row['avg_ticket'] ?? 0);
        $ticket_count++;
    }
    if ((int)($row['tx_count'] ?? 0) > $peak_value) {
        $peak_value = (int)$row['tx_count'];
        $peak_label = ($row['weekday_name'] ?? 'Unknown') . ' ' . str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
    }
}
foreach ($heat_units_rows as $row) {
    $heatmap[(int)$row['weekday_idx']][(int)$row['hour_bucket']]['units'] = (int)($row['units_sold'] ?? 0);
}
$avg_ticket = $ticket_count ? $ticket_sum / $ticket_count : 0;
$heat_table = [];
foreach ($heatmap as $hours) {
    foreach ($hours as $slot) {
        if ($slot['tx'] <= 0 && $slot['units'] <= 0) continue;
        $heat_table[] = $slot;
    }
}
usort($heat_table, static fn($a, $b) => ($b['tx'] <=> $a['tx']) ?: ($b['units'] <=> $a['units']));

$pageTitle = 'Reports & Analytics';
include 'templates/header.php';
?>
<style>
.report-heat td,.report-heat th{padding:.5rem;border:1px solid #dee2e6;font-size:.8rem}
.heat-box{min-width:100px;border-radius:.5rem;padding:.5rem}
.heat-box strong{display:block;font-size:1rem}
</style>
<div class="container-fluid py-3">
    <div class="mb-3">
        <h1 class="h3 mb-1">Reports & Analytics</h1>
        <div class="text-muted">Default date range: last 30 days. Current scope: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-2 col-md-4"><label class="form-label">Start</label><input type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">End</label><input type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">Category</label><select class="form-select" name="category"><option value="">All Categories</option><option value="repairs" <?php echo $category_filter === 'repairs' ? 'selected' : ''; ?>>Repairs</option><?php foreach ($categories as $category): ?><option value="<?php echo (int)$category['id']; ?>" <?php echo (string)$category_filter === (string)$category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">Location</label><select class="form-select" name="location"><option value="">All Locations</option><?php foreach ($locations as $loc): ?><option value="<?php echo (int)$loc['id']; ?>" <?php echo (string)$location_filter === (string)$loc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($loc['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">Cashier</label><select class="form-select" name="cashier"><option value="">All Cashiers</option><?php foreach ($cashiers as $cashier): ?><option value="<?php echo (int)$cashier['id']; ?>" <?php echo (string)$cashier_filter === (string)$cashier['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cashier['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">Technician</label><select class="form-select" name="technician" <?php echo empty($technicians) ? 'disabled' : ''; ?>><option value="">All Technicians</option><?php foreach ($technicians as $tech): ?><option value="<?php echo (int)$tech['id']; ?>" <?php echo (string)$technician_filter === (string)$tech['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tech['name']); ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2 col-md-4"><label class="form-label">Margin Sort</label><select class="form-select" name="margin_sort"><option value="profit" <?php echo $margin_sort === 'profit' ? 'selected' : ''; ?>>Highest Profit</option><option value="margin" <?php echo $margin_sort === 'margin' ? 'selected' : ''; ?>>Highest Margin %</option></select></div>
                <div class="col-12 d-flex gap-2 flex-wrap"><button class="btn btn-primary" type="submit">Apply Filters</button><a class="btn btn-outline-secondary" href="reports.php">Reset</a><?php if (empty($technicians)): ?><span class="text-muted small align-self-center">Technician filtering becomes available once repairs are assigned to a technician user field.</span><?php endif; ?></div>
            </form>
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-lg-3 col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Total Sales</div><div class="h3 mb-0"><?php echo formatCurrency($kpi_sales['total'] ?? 0); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Total Profit</div><div class="h3 mb-0 text-success"><?php echo formatCurrency($kpi_profit['total'] ?? 0); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Active Repairs</div><div class="h3 mb-0"><?php echo (int)($kpi_repairs['total'] ?? 0); ?></div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Inventory Cost Value</div><div class="h3 mb-0"><?php echo formatCurrency($inventory_totals['cost_value']); ?></div></div></div></div>
    </div>
    <div class="row g-3">
        <div class="col-12"><div class="card" id="inventory-valuation-section"><div class="card-header d-flex justify-content-between flex-wrap gap-2"><div><div class="fw-bold">Inventory Valuation Report</div><div class="text-muted small">Key metrics: on-hand units, cost value, retail value, and COGS. Default date range: last 30 days. Useful for financial accounting and tracking capital tied up in stock.</div></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-export-table="inventoryValuationTable" data-filename="inventory-valuation-<?php echo reportSlug($start_date . '-' . $end_date); ?>">CSV</button><button class="btn btn-primary btn-sm" type="button" data-export-section="inventory-valuation-section" data-filename="inventory-valuation-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button></div></div><div class="card-body"><div class="row g-2 mb-3"><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">On Hand Units</div><strong><?php echo number_format($inventory_totals['on_hand']); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Cost Value</div><strong><?php echo formatCurrency($inventory_totals['cost_value']); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Retail Value</div><strong><?php echo formatCurrency($inventory_totals['retail_value']); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">COGS</div><strong><?php echo formatCurrency($inventory_totals['cogs']); ?></strong></div></div></div><div style="height:280px" class="mb-3"><canvas id="inventoryValuationChart"></canvas></div><div class="table-responsive"><table class="table table-sm align-middle" id="inventoryValuationTable"><thead><tr><th>Category</th><th>Location</th><th class="text-end">On Hand</th><th class="text-end">Cost Value</th><th class="text-end">Retail Value</th><th class="text-end">COGS</th></tr></thead><tbody><?php foreach ($inventory_groups as $row): ?><tr><td><?php echo htmlspecialchars($row['category_name']); ?></td><td><?php echo htmlspecialchars($row['location_name']); ?></td><td class="text-end"><?php echo number_format($row['on_hand']); ?></td><td class="text-end"><?php echo formatCurrency($row['cost_value']); ?></td><td class="text-end"><?php echo formatCurrency($row['retail_value']); ?></td><td class="text-end"><?php echo formatCurrency($row['cogs']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
        <div class="col-12"><div class="card" id="turnover-section"><div class="card-header d-flex justify-content-between flex-wrap gap-2"><div><div class="fw-bold">Inventory Turnover & Sell-Through Rate</div><div class="text-muted small">Key metrics: opening, received, available, sold, turnover, and sell-through. Default date range: last 30 days. Useful for spotting best-sellers and slow-moving stock.</div></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-export-table="turnoverTable" data-filename="turnover-<?php echo reportSlug($start_date . '-' . $end_date); ?>">CSV</button><button class="btn btn-primary btn-sm" type="button" data-export-section="turnover-section" data-filename="turnover-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button></div></div><div class="card-body"><div class="row g-2 mb-3"><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Tracked Products</div><strong><?php echo number_format(count($turnover_metrics)); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Slow Movers</div><strong><?php echo number_format($slow_movers); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Best Category</div><strong><?php echo htmlspecialchars($turnover_chart[0]['category_name'] ?? 'N/A'); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Best Product</div><strong><?php echo htmlspecialchars($turnover_metrics[0]['name'] ?? 'N/A'); ?></strong></div></div></div><div style="height:280px" class="mb-3"><canvas id="turnoverChart"></canvas></div><div class="table-responsive"><table class="table table-sm align-middle" id="turnoverTable"><thead><tr><th>Product</th><th>Category</th><th class="text-end">Opening</th><th class="text-end">Received</th><th class="text-end">Available</th><th class="text-end">Sold</th><th class="text-end">Current</th><th class="text-end">Turnover</th><th class="text-end">Sell-Through</th></tr></thead><tbody><?php foreach ($turnover_metrics as $row): ?><tr><td><?php echo htmlspecialchars($row['name']); ?></td><td><?php echo htmlspecialchars($row['category_name']); ?></td><td class="text-end"><?php echo number_format($row['opening']); ?></td><td class="text-end"><?php echo number_format($row['received']); ?></td><td class="text-end"><?php echo number_format($row['available']); ?></td><td class="text-end"><?php echo number_format($row['sold']); ?></td><td class="text-end"><?php echo number_format($row['current']); ?></td><td class="text-end"><?php echo number_format($row['turnover'], 2); ?>x</td><td class="text-end"><?php echo number_format($row['sell_through'], 1); ?>%</td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
        <div class="col-12"><div class="card" id="margin-section"><div class="card-header d-flex justify-content-between flex-wrap gap-2"><div><div class="fw-bold">Margin Analysis by Product</div><div class="text-muted small">Key metrics: cost, sale price, margin %, units sold, revenue, and total profit. Default date range: last 30 days. Useful for separating high margin items from pure volume sellers.</div></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-export-table="marginTable" data-filename="margin-analysis-<?php echo reportSlug($start_date . '-' . $end_date); ?>">CSV</button><button class="btn btn-primary btn-sm" type="button" data-export-section="margin-section" data-filename="margin-analysis-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button></div></div><div class="card-body"><div class="row g-2 mb-3"><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Average Margin</div><strong><?php echo number_format($avg_margin, 1); ?>%</strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Top Margin Product</div><strong><?php echo htmlspecialchars($top_margin_name); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Top Profit Product</div><strong><?php echo htmlspecialchars($top_profit_name); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Sort Mode</div><strong><?php echo $margin_sort === 'margin' ? 'Highest Margin %' : 'Highest Profit'; ?></strong></div></div></div><div style="height:280px" class="mb-3"><canvas id="marginChart"></canvas></div><div class="table-responsive"><table class="table table-sm align-middle" id="marginTable"><thead><tr><th>Product</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Sale Price</th><th class="text-end">Margin %</th><th class="text-end">Units Sold</th><th class="text-end">Revenue</th><th class="text-end">Total Profit</th></tr></thead><tbody><?php foreach ($margin_rows as $row): ?><tr><td><?php echo htmlspecialchars($row['name']); ?></td><td><?php echo htmlspecialchars($row['category_name']); ?></td><td class="text-end"><?php echo formatCurrency($row['cost_price']); ?></td><td class="text-end"><?php echo formatCurrency($row['sale_price']); ?></td><td class="text-end"><?php echo number_format($row['margin_pct'], 1); ?>%</td><td class="text-end"><?php echo number_format($row['units_sold']); ?></td><td class="text-end"><?php echo formatCurrency($row['revenue']); ?></td><td class="text-end"><?php echo formatCurrency($row['total_profit']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
        <div class="col-12"><div class="card" id="heatmap-section"><div class="card-header d-flex justify-content-between flex-wrap gap-2"><div><div class="fw-bold">Sales by Hour / Day of Week Heatmap</div><div class="text-muted small">Key metrics: transaction counts, average ticket value, units sold, and total sales by weekday-hour slot. Default date range: last 30 days. Useful for staffing and promotion timing.</div></div><div class="d-flex gap-2"><button class="btn btn-outline-secondary btn-sm" type="button" data-export-table="heatSummaryTable" data-filename="sales-heatmap-<?php echo reportSlug($start_date . '-' . $end_date); ?>">CSV</button><button class="btn btn-primary btn-sm" type="button" data-export-section="heatmap-section" data-filename="sales-heatmap-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button></div></div><div class="card-body"><div class="row g-2 mb-3"><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Peak Slot</div><strong><?php echo htmlspecialchars($peak_label); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Peak Transactions</div><strong><?php echo number_format($peak_value); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Total Transactions</div><strong><?php echo number_format($total_tx); ?></strong></div></div><div class="col-md-3"><div class="border rounded p-2 small"><div class="text-muted">Average Ticket</div><strong><?php echo formatCurrency($avg_ticket); ?></strong></div></div></div><div class="table-responsive mb-3"><table class="table report-heat"><thead><tr><th>Hour</th><?php foreach ($weekdays as $day): ?><th><?php echo htmlspecialchars(substr($day, 0, 3)); ?></th><?php endforeach; ?></tr></thead><tbody><?php for ($h = 0; $h < 24; $h++): ?><tr><th><?php echo htmlspecialchars(str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00'); ?></th><?php foreach ($weekdays as $d => $day): $slot = $heatmap[$d][$h]; $opacity = $peak_value > 0 ? max(0.08, min(0.92, $slot['tx'] / $peak_value)) : 0.08; ?><td><div class="heat-box" style="background:rgba(37,99,235,<?php echo $opacity; ?>)"><strong><?php echo number_format($slot['tx']); ?></strong><div><?php echo formatCurrency($slot['avg_ticket']); ?></div><div><?php echo number_format($slot['units']); ?> units</div></div></td><?php endforeach; ?></tr><?php endfor; ?></tbody></table></div><div class="table-responsive"><table class="table table-sm align-middle" id="heatSummaryTable"><thead><tr><th>Day</th><th>Hour</th><th class="text-end">Transactions</th><th class="text-end">Average Ticket</th><th class="text-end">Units Sold</th><th class="text-end">Total Sales</th></tr></thead><tbody><?php foreach ($heat_table as $row): ?><tr><td><?php echo htmlspecialchars($row['day']); ?></td><td><?php echo htmlspecialchars(str_pad((string)$row['hour'], 2, '0', STR_PAD_LEFT) . ':00'); ?></td><td class="text-end"><?php echo number_format($row['tx']); ?></td><td class="text-end"><?php echo formatCurrency($row['avg_ticket']); ?></td><td class="text-end"><?php echo number_format($row['units']); ?></td><td class="text-end"><?php echo formatCurrency($row['sales']); ?></td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
        <div class="col-12 col-xl-6"><div class="card"><div class="card-header"><span class="fw-bold">Service Performance</span></div><div class="card-body"><div style="height:260px"><canvas id="serviceChart"></canvas></div></div></div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
Chart.defaults.font.family = "Inter, system-ui, sans-serif";
const gridColor = "rgba(148,163,184,.25)";
const moneyTick = (v) => '$' + Number(v || 0).toLocaleString();
new Chart(document.getElementById('inventoryValuationChart'), { type: 'bar', data: { labels: <?php echo json_encode(array_map(static fn($r) => $r['category_name'] . ' / ' . $r['location_name'], array_slice($inventory_groups, 0, 12))); ?>, datasets: [{ label: 'Cost Value', data: <?php echo json_encode(array_map(static fn($r) => (float)$r['cost_value'], array_slice($inventory_groups, 0, 12))); ?>, backgroundColor: '#2563eb', borderRadius: 8 }, { label: 'Retail Value', data: <?php echo json_encode(array_map(static fn($r) => (float)$r['retail_value'], array_slice($inventory_groups, 0, 12))); ?>, backgroundColor: '#22c55e', borderRadius: 8 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { grid: { color: gridColor }, ticks: { callback: moneyTick } } } } });
new Chart(document.getElementById('turnoverChart'), { data: { labels: <?php echo json_encode(array_map(static fn($r) => $r['category_name'], array_slice($turnover_chart, 0, 12))); ?>, datasets: [{ type: 'bar', label: 'Sell-Through %', data: <?php echo json_encode(array_map(static fn($r) => round((float)$r['sell_through'], 2), array_slice($turnover_chart, 0, 12))); ?>, backgroundColor: '#0ea5e9', borderRadius: 8, yAxisID: 'y' }, { type: 'line', label: 'Turnover', data: <?php echo json_encode(array_map(static fn($r) => round((float)$r['turnover'], 2), array_slice($turnover_chart, 0, 12))); ?>, borderColor: '#f59e0b', backgroundColor: '#f59e0b', yAxisID: 'y1', tension: .3 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: gridColor }, ticks: { callback: (v) => v + '%' } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: (v) => Number(v).toFixed(1) + 'x' } } } } });
new Chart(document.getElementById('marginChart'), { type: 'bar', data: { labels: <?php echo json_encode(array_map(static fn($r) => $r['name'], array_slice($margin_rows, 0, 12))); ?>, datasets: [{ label: 'Total Profit', data: <?php echo json_encode(array_map(static fn($r) => (float)$r['total_profit'], array_slice($margin_rows, 0, 12))); ?>, backgroundColor: '#059669', borderRadius: 8 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, scales: { x: { grid: { color: gridColor }, ticks: { callback: moneyTick } }, y: { grid: { display: false } } } } });
new Chart(document.getElementById('serviceChart'), { type: 'line', data: { labels: <?php echo json_encode(array_map(static fn($r) => $r['day'], $service_performance)); ?>, datasets: [{ label: 'Avg Turnaround Days', data: <?php echo json_encode(array_map(static fn($r) => (float)$r['avg_days'], $service_performance)); ?>, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .35 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: gridColor } } } } });
function exportTableToCSV(id, filename) { const table = document.getElementById(id); if (!table) return; const csv = [...table.querySelectorAll('tr')].map((tr) => [...tr.querySelectorAll('th,td')].map((cell) => `"${(cell.innerText || '').replace(/\s+/g, ' ').trim().replace(/"/g, '""')}"`).join(',')).join('\n'); const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' }); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = (filename || 'report') + '.csv'; link.click(); URL.revokeObjectURL(link.href); }
async function exportSectionToPDF(id, filename) { const el = document.getElementById(id); if (!el || !window.html2canvas || !window.jspdf?.jsPDF) return; const canvas = await window.html2canvas(el, { scale: 2, backgroundColor: '#ffffff', useCORS: true }); const { jsPDF } = window.jspdf; const pdf = new jsPDF('p', 'mm', 'a4'); const pageW = pdf.internal.pageSize.getWidth() - 10; const pageH = pdf.internal.pageSize.getHeight() - 10; const imgH = (canvas.height * pageW) / canvas.width; const img = canvas.toDataURL('image/png'); let left = imgH; let pos = 5; pdf.addImage(img, 'PNG', 5, pos, pageW, imgH); left -= pageH; while (left > 0) { pos = left - imgH + 5; pdf.addPage(); pdf.addImage(img, 'PNG', 5, pos, pageW, imgH); left -= pageH; } pdf.save((filename || 'report') + '.pdf'); }
document.querySelectorAll('[data-export-table]').forEach((btn) => btn.addEventListener('click', () => exportTableToCSV(btn.dataset.exportTable, btn.dataset.filename)));
document.querySelectorAll('[data-export-section]').forEach((btn) => btn.addEventListener('click', () => exportSectionToPDF(btn.dataset.exportSection, btn.dataset.filename)));
</script>
<?php include 'templates/footer.php'; ?>
