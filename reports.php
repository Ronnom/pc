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
$category_name_col = reportFirstColumn($db, 'categories', ['name', 'category_name', 'title']);
$location_name_col = reportFirstColumn($db, 'stock_locations', ['name', 'location_name', 'code', 'title']);
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
$category_expr = ($categories_exists && $category_name_col && reportColumnExists($db, 'products', 'category_id'))
    ? "COALESCE(c.`{$category_name_col}`, 'Uncategorized')"
    : "'Uncategorized'";
$category_join = ($categories_exists && $category_name_col && reportColumnExists($db, 'products', 'category_id'))
    ? "LEFT JOIN categories c ON c.id = p.category_id"
    : '';
$active_condition = $products_active_col ? " AND COALESCE(p.`{$products_active_col}`, 1) = 1" : '';
$deleted_condition = $products_deleted_col ? " AND p.`{$products_deleted_col}` IS NULL" : '';

$categories = ($categories_exists && $category_name_col && reportColumnExists($db, 'products', 'category_id'))
    ? $db->fetchAll("SELECT id, `{$category_name_col}` AS name FROM categories ORDER BY `{$category_name_col}`")
    : [];
$location_active_col = reportFirstColumn($db, 'stock_locations', ['is_active', 'status']);
$location_active_condition = '';
if ($location_active_col === 'is_active') {
    $location_active_condition = " WHERE COALESCE(`{$location_active_col}`, 1) = 1";
} elseif ($location_active_col === 'status') {
    $location_active_condition = " WHERE COALESCE(`{$location_active_col}`, 1) = 1";
}
$locations = ($locations_exists && $location_name_col)
    ? $db->fetchAll("SELECT id, `{$location_name_col}` AS name FROM stock_locations{$location_active_condition} ORDER BY `{$location_name_col}`")
    : [];
$location_label_expr = ($locations_exists && $location_name_col) ? "l.`{$location_name_col}`" : "NULL";
$selected_location_name = '';

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

$cashier_condition = '';
$cashier_params = [];
$tech_condition = '';
$tech_params = [];

$scope_condition = $category_condition . $location_condition;
$scope_params = array_merge($category_params, $location_params);

$kpi_sales = $db->fetchOne("SELECT COALESCE(SUM(ti.quantity * ti.unit_price), 0) AS total FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
$kpi_profit = $db->fetchOne("SELECT COALESCE(SUM(ti.quantity * (ti.unit_price - COALESCE(" . ($ti_serial_cost_col ? "ti.`{$ti_serial_cost_col}`" : 'NULL') . ", {$cost_expr}))), 0) AS total FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
$kpi_repairs = $db->fetchOne("SELECT COUNT(*) AS total FROM repairs WHERE status IN ('received','diagnosing','repairing','ready')");
$service_performance = $db->fetchAll("SELECT DATE(r.updated_at) AS day, ROUND(AVG(DATEDIFF(r.updated_at, r.`{$repairs_request_col}`)), 1) AS avg_days FROM repairs r WHERE r.status = 'completed' AND r.updated_at >= ? AND r.updated_at < ?{$tech_condition} GROUP BY DATE(r.updated_at) ORDER BY day ASC", array_merge([$start_date, $end_date_exclusive], $tech_params));

$inventory_rows = [];
if ($product_locations_exists && $locations_exists) {
    $inventory_rows = $db->fetchAll("SELECT {$category_expr} AS category_name, COALESCE({$location_label_expr}, 'Unassigned') AS location_name, COALESCE(pl.quantity, 0) AS qty, {$cost_expr} AS unit_cost, {$price_expr} AS unit_price FROM products p {$category_join} JOIN product_locations pl ON pl.product_id = p.id LEFT JOIN stock_locations l ON l.id = pl.location_id WHERE 1=1{$active_condition}{$deleted_condition}{$category_condition}", $category_params);
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

$cogs_rows = $db->fetchAll("SELECT CASE WHEN p.sku IN ('REPAIR-SERVICE','SERVICE-FEE') OR p.name IN ('Repair Service','Service Fee') THEN 'Repairs' ELSE {$category_expr} END AS category_name, " . (($ti_serial_id_col && $serials_exists && $psn_location_col && $locations_exists) ? "COALESCE({$location_label_expr}, 'Unassigned')" : "'All Locations'") . " AS location_name, COALESCE(SUM(ti.quantity * COALESCE(" . ($ti_serial_cost_col ? "ti.`{$ti_serial_cost_col}`" : 'NULL') . ", {$cost_expr})), 0) AS cogs FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id {$category_join} " . (($ti_serial_id_col && $serials_exists && $psn_location_col && $locations_exists) ? "LEFT JOIN product_serial_numbers psn ON psn.id = ti.`{$ti_serial_id_col}` LEFT JOIN stock_locations l ON l.id = psn.`{$psn_location_col}`" : "") . " WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition} GROUP BY category_name, location_name", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params));
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
    $turnover_rows = $db->fetchAll("SELECT p.name, {$category_expr} AS category_name, {$stock_expr} AS current_stock, COALESCE(s.sold_units, 0) AS sold_units, COALESCE(m.received_units, 0) AS received_units FROM products p {$category_join} LEFT JOIN (SELECT ti.product_id, COALESCE(SUM(ti.quantity), 0) AS sold_units FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id JOIN products p ON p.id = ti.product_id WHERE t.status = 'completed' AND t.transaction_date >= ? AND t.transaction_date < ?{$cashier_condition}{$scope_condition} GROUP BY ti.product_id) s ON s.product_id = p.id LEFT JOIN (SELECT sm.product_id, COALESCE(SUM(CASE WHEN sm.`{$sm_type_col}` = 'in' THEN ABS(sm.quantity) ELSE 0 END), 0) AS received_units FROM stock_movements sm JOIN products p ON p.id = sm.product_id WHERE sm.`{$sm_date_col}` >= ? AND sm.`{$sm_date_col}` < ?{$category_condition}{$location_condition} GROUP BY sm.product_id) m ON m.product_id = p.id WHERE 1=1{$active_condition}{$deleted_condition}{$category_condition}{$location_condition}", array_merge([$start_date, $end_date_exclusive], $cashier_params, $scope_params, [$start_date, $end_date_exclusive], $category_params, $location_params, $category_params, $location_params));
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
    for ($h = 0; $h < 24; $h++) {
        $heatmap[$d][$h] = ['day' => $weekdays[$d], 'hour' => $h, 'tx' => 0, 'avg_ticket' => 0, 'units' => 0, 'sales' => 0];
    }
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
        if ($slot['tx'] <= 0 && $slot['units'] <= 0) {
            continue;
        }
        $heat_table[] = $slot;
    }
}
usort($heat_table, static fn($a, $b) => ($b['tx'] <=> $a['tx']) ?: ($b['units'] <=> $a['units']));

$scopeLabel = date('Y-m-d', strtotime($start_date)) . ' to ' . date('Y-m-d', strtotime($end_date));
$selectedRange = 'custom';
if ($start_date === date('Y-m-d', strtotime('-6 days')) && $end_date === $today) {
    $selectedRange = 'last_7';
} elseif ($start_date === $default_start && $end_date === $today) {
    $selectedRange = 'last_30';
} elseif ($start_date === date('Y-m-d', strtotime('-89 days')) && $end_date === $today) {
    $selectedRange = 'last_90';
}
$inventoryTopLocations = array_slice($inventory_groups, 0, 8);
$turnoverBestCategory = $turnover_chart[0]['category_name'] ?? 'N/A';
$turnoverBestProduct = $turnover_metrics[0]['name'] ?? 'N/A';
$serviceTrend = count($service_performance) >= 2
    ? (($service_performance[count($service_performance) - 1]['avg_days'] ?? 0) - ($service_performance[0]['avg_days'] ?? 0))
    : 0;
$serviceAverage = 0;
if (!empty($service_performance)) {
    $serviceAverage = array_sum(array_map(static fn($row) => (float)($row['avg_days'] ?? 0), $service_performance)) / count($service_performance);
}

$pageTitle = 'Reports & Analytics';
include 'templates/header.php';
?>
<style>
.reports-dashboard {
    --reports-bg: #f3f4f6;
    --reports-card: #ffffff;
    --reports-border: #e5e7eb;
    --reports-text: #111827;
    --reports-muted: #6b7280;
    --reports-indigo: #4f46e5;
    --reports-indigo-soft: #eef2ff;
    --reports-green: #059669;
    --reports-amber: #d97706;
    --reports-sky: #0284c7;
    --reports-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    --reports-radius: 12px;
    color: var(--reports-text);
}

.reports-dashboard,
.reports-dashboard .card,
.reports-dashboard .btn,
.reports-dashboard .form-control,
.reports-dashboard .form-select,
.reports-dashboard .table {
    font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

.reports-dashboard.dashboard-shell {
    background: var(--reports-bg);
}

.reports-dashboard .dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.reports-dashboard .dashboard-title {
    font-size: 1.875rem;
    font-weight: 600;
    margin: 0 0 .25rem;
}

.reports-dashboard .dashboard-subtitle,
.reports-dashboard .scope-line,
.reports-dashboard .section-description,
.reports-dashboard .metric-label,
.reports-dashboard .chart-empty,
.reports-dashboard .helper-text {
    color: var(--reports-muted);
}

.reports-dashboard .scope-line {
    font-size: .95rem;
}

.reports-dashboard .range-card,
.reports-dashboard .filters-card,
.reports-dashboard .metric-card,
.reports-dashboard .report-card {
    background: var(--reports-card);
    border: 1px solid var(--reports-border);
    border-radius: var(--reports-radius);
    box-shadow: var(--reports-shadow);
}

.reports-dashboard .range-card {
    padding: 1rem 1.125rem;
    min-width: 260px;
}

.reports-dashboard .range-label,
.reports-dashboard .filter-label {
    display: block;
    margin-bottom: .45rem;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--reports-muted);
}

.reports-dashboard .filters-card {
    padding: 1.25rem;
    margin-bottom: 1.5rem;
}

.reports-dashboard .filter-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 1rem;
    align-items: end;
}

.reports-dashboard .filter-span {
    grid-column: span 1;
}

.reports-dashboard .filter-actions {
    display: flex;
    gap: .75rem;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    grid-column: span 2;
}

.reports-dashboard .form-control,
.reports-dashboard .form-select,
.reports-dashboard .btn {
    border-radius: 10px;
}

.reports-dashboard .form-control,
.reports-dashboard .form-select {
    border-color: var(--reports-border);
    min-height: 44px;
    box-shadow: none;
}

.reports-dashboard .form-control:focus,
.reports-dashboard .form-select:focus {
    border-color: rgba(79, 70, 229, .45);
    box-shadow: 0 0 0 .18rem rgba(79, 70, 229, .14);
}

.reports-dashboard .btn-primary {
    background: var(--reports-indigo);
    border-color: var(--reports-indigo);
}

.reports-dashboard .btn-primary:hover,
.reports-dashboard .btn-primary:focus {
    background: #4338ca;
    border-color: #4338ca;
}

.reports-dashboard .btn-outline-secondary {
    color: #374151;
    border-color: #d1d5db;
}

.reports-dashboard .kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.reports-dashboard .metric-card {
    padding: 1.125rem 1.25rem;
    transition: transform .18s ease, box-shadow .18s ease;
}

.reports-dashboard .metric-card:hover,
.reports-dashboard .report-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
}

.reports-dashboard .metric-value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1.15;
    margin-bottom: .35rem;
}

.reports-dashboard .metric-trend {
    font-size: .85rem;
    font-weight: 600;
}

.reports-dashboard .metric-trend.positive { color: var(--reports-green); }
.reports-dashboard .metric-trend.neutral { color: var(--reports-muted); }
.reports-dashboard .metric-trend.warning { color: var(--reports-amber); }

.reports-dashboard .reports-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
}

.reports-dashboard .report-card {
    padding: 1.25rem;
}

.reports-dashboard .report-card.full-width {
    grid-column: 1 / -1;
}

.reports-dashboard .report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1rem;
}

.reports-dashboard .report-title-row {
    display: flex;
    gap: .75rem;
    align-items: flex-start;
}

.reports-dashboard .report-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--reports-indigo-soft);
    color: var(--reports-indigo);
    flex-shrink: 0;
}

.reports-dashboard .report-title {
    margin: 0 0 .25rem;
    font-size: 1.05rem;
    font-weight: 600;
}

.reports-dashboard .export-actions {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
}

.reports-dashboard .export-actions:empty {
    display: none;
}

.reports-dashboard .chart-panel {
    position: relative;
    min-height: 300px;
    border: 1px solid #eef2f7;
    border-radius: 12px;
    padding: 1rem;
    background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
    margin-bottom: 1rem;
}

.reports-dashboard .chart-canvas {
    position: relative;
    height: 280px;
}

.reports-dashboard .chart-empty {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 1rem;
    font-size: .95rem;
}

.reports-dashboard .chart-panel.is-empty .chart-empty {
    display: flex;
}

.reports-dashboard .chart-panel.is-empty .chart-canvas {
    visibility: hidden;
}

@media (max-width: 1399.98px) {
    .reports-dashboard .filter-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .reports-dashboard .filter-actions {
        grid-column: span 4;
        justify-content: flex-start;
    }
}

@media (max-width: 991.98px) {
    .reports-dashboard .dashboard-header {
        flex-direction: column;
    }

    .reports-dashboard .range-card {
        width: 100%;
    }

    .reports-dashboard .kpi-grid,
    .reports-dashboard .reports-grid {
        grid-template-columns: 1fr;
    }

    .reports-dashboard .report-header {
        flex-direction: column;
    }
}

@media (max-width: 767.98px) {
    .reports-dashboard .filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .reports-dashboard .filter-actions {
        grid-column: span 2;
    }
}

@media (max-width: 575.98px) {
    .reports-dashboard .filter-grid {
        grid-template-columns: 1fr;
    }

    .reports-dashboard .filter-span,
    .reports-dashboard .filter-actions {
        grid-column: span 1;
    }
}
</style>
<div class="container-fluid py-4 reports-dashboard dashboard-shell">
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">Reports &amp; Analytics</h1>
            <div class="dashboard-subtitle mb-2">Key metrics and performance insights</div>
            <div class="scope-line">Current scope: <?php echo htmlspecialchars($scopeLabel); ?></div>
        </div>
        <div class="range-card">
            <label class="range-label" for="quickRange"><i class="fas fa-calendar-alt me-2"></i>Date Range</label>
            <select class="form-select" id="quickRange" aria-label="Date range selector">
                <option value="last_7" <?php echo $selectedRange === 'last_7' ? 'selected' : ''; ?>>Last 7 days</option>
                <option value="last_30" <?php echo $selectedRange === 'last_30' ? 'selected' : ''; ?>>Last 30 days</option>
                <option value="last_90" <?php echo $selectedRange === 'last_90' ? 'selected' : ''; ?>>Last 90 days</option>
                <option value="custom" <?php echo $selectedRange === 'custom' ? 'selected' : ''; ?>>Custom range</option>
            </select>
        </div>
    </div>
    <div class="filters-card">
        <form method="GET" class="filter-grid">
            <div class="filter-span">
                <label class="filter-label" for="startDate">Start</label>
                <input id="startDate" type="date" class="form-control" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="filter-span">
                <label class="filter-label" for="endDate">End</label>
                <input id="endDate" type="date" class="form-control" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="filter-span">
                <label class="filter-label" for="categoryFilter">Category</label>
                <select id="categoryFilter" class="form-select" name="category">
                    <option value="">All Categories</option>
                    <option value="repairs" <?php echo $category_filter === 'repairs' ? 'selected' : ''; ?>>Repairs</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo (int)$category['id']; ?>" <?php echo (string)$category_filter === (string)$category['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-span">
                <label class="filter-label" for="marginSort">Margin Sort</label>
                <select id="marginSort" class="form-select" name="margin_sort">
                    <option value="profit" <?php echo $margin_sort === 'profit' ? 'selected' : ''; ?>>Highest Profit</option>
                    <option value="margin" <?php echo $margin_sort === 'margin' ? 'selected' : ''; ?>>Highest Margin %</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary px-4" type="submit">Apply Filters</button>
                <a class="btn btn-outline-secondary px-4" href="reports.php">Reset</a>
            </div>
        </form>
    </div>
    <div class="kpi-grid">
        <div class="metric-card">
            <div class="metric-label">Total Sales</div>
            <div class="metric-value"><?php echo formatCurrency($kpi_sales['total'] ?? 0); ?></div>
            <div class="metric-trend positive">Revenue within selected scope</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total Profit</div>
            <div class="metric-value"><?php echo formatCurrency($kpi_profit['total'] ?? 0); ?></div>
            <div class="metric-trend positive">Margin performance across completed sales</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Active Repairs</div>
            <div class="metric-value"><?php echo number_format((int)($kpi_repairs['total'] ?? 0)); ?></div>
            <div class="metric-trend warning">Open jobs currently in service pipeline</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Inventory Cost Value</div>
            <div class="metric-value"><?php echo formatCurrency($inventory_totals['cost_value']); ?></div>
            <div class="metric-trend neutral">Capital currently tied to available stock</div>
        </div>
    </div>
    <div class="reports-grid">
        <section class="report-card" id="inventory-valuation-section">
            <div class="report-header">
                <div class="report-title-row">
                    <div class="report-icon"><i class="fas fa-boxes"></i></div>
                    <div>
                        <h2 class="report-title">Inventory Valuation Report</h2>
                        <div class="section-description">Key metrics on-hand units, cost value, retail value, and COGS across the selected scope.</div>
                    </div>
                </div>
                <div class="export-actions">
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-export-section="inventory-valuation-section" data-filename="inventory-valuation-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button>
                </div>
            </div>
            <div class="chart-panel" id="inventoryValuationChartPanel">
                <div class="chart-canvas"><canvas id="inventoryValuationChart"></canvas></div>
                <div class="chart-empty">No inventory valuation data is available for the selected filters.</div>
            </div>
        </section>
        <section class="report-card" id="turnover-section">
            <div class="report-header">
                <div class="report-title-row">
                    <div class="report-icon"><i class="fas fa-sync-alt"></i></div>
                    <div>
                        <h2 class="report-title">Inventory Turnover &amp; Sell-Through Rate</h2>
                        <div class="section-description">Opening, received, available, sold, turnover, and sell-through indicators for stock movement performance.</div>
                    </div>
                </div>
                <div class="export-actions">
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-export-section="turnover-section" data-filename="turnover-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button>
                </div>
            </div>
            <div class="chart-panel" id="turnoverChartPanel">
                <div class="chart-canvas"><canvas id="turnoverChart"></canvas></div>
                <div class="chart-empty">No turnover data is available for the selected filters.</div>
            </div>
        </section>
        <section class="report-card" id="margin-section">
            <div class="report-header">
                <div class="report-title-row">
                    <div class="report-icon"><i class="fas fa-chart-bar"></i></div>
                    <div>
                        <h2 class="report-title">Margin Analysis by Product</h2>
                        <div class="section-description">Product-level view of cost, sale price, margin percentage, and realized profit within the selected range.</div>
                    </div>
                </div>
                <div class="export-actions">
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-export-section="margin-section" data-filename="margin-analysis-<?php echo reportSlug($start_date . '-' . $end_date); ?>">PDF</button>
                </div>
            </div>
            <div class="chart-panel" id="marginChartPanel">
                <div class="chart-canvas"><canvas id="marginChart"></canvas></div>
                <div class="chart-empty">No margin data is available for the selected filters.</div>
            </div>
        </section>
        <section class="report-card" id="service-section">
            <div class="report-header">
                <div class="report-title-row">
                    <div class="report-icon"><i class="fas fa-tools"></i></div>
                    <div>
                        <h2 class="report-title">Service Performance</h2>
                        <div class="section-description">Average turnaround trend for completed repairs over time.</div>
                    </div>
                </div>
            </div>
            <div class="chart-panel" id="serviceChartPanel">
                <div class="chart-canvas"><canvas id="serviceChart"></canvas></div>
                <div class="chart-empty">No service performance data is available for the selected filters.</div>
            </div>
        </section>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script>
Chart.defaults.font.family = "Inter, system-ui, sans-serif";
Chart.defaults.color = "#6b7280";
const gridColor = "rgba(148,163,184,.18)";
const palette = {
    indigo: "#4f46e5",
    indigoSoft: "rgba(79,70,229,.14)",
    green: "#059669",
    amber: "#d97706",
    sky: "#0284c7"
};
const moneyTick = (v) => "$" + Number(v || 0).toLocaleString();
const percentTick = (v) => Number(v || 0).toLocaleString() + "%";
const hasData = (values) => Array.isArray(values) && values.some((value) => Number(value || 0) !== 0);
function setChartEmpty(panelId, empty) {
    const panel = document.getElementById(panelId);
    if (!panel) return;
    panel.classList.toggle("is-empty", empty);
}

const inventoryLabels = <?php echo json_encode(array_map(static fn($r) => $r['category_name'] . ' / ' . $r['location_name'], $inventoryTopLocations)); ?>;
const inventoryCostValues = <?php echo json_encode(array_map(static fn($r) => (float)$r['cost_value'], $inventoryTopLocations)); ?>;
const inventoryRetailValues = <?php echo json_encode(array_map(static fn($r) => (float)$r['retail_value'], $inventoryTopLocations)); ?>;
setChartEmpty("inventoryValuationChartPanel", !hasData(inventoryCostValues) && !hasData(inventoryRetailValues));
if (inventoryLabels.length) {
    new Chart(document.getElementById("inventoryValuationChart"), {
        type: "bar",
        data: {
            labels: inventoryLabels,
            datasets: [
                { label: "Cost Value", data: inventoryCostValues, backgroundColor: palette.indigo, borderRadius: 8, maxBarThickness: 32 },
                { label: "Retail Value", data: inventoryRetailValues, backgroundColor: palette.green, borderRadius: 8, maxBarThickness: 32 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: "top", align: "end" }, tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${moneyTick(ctx.parsed.y)}` } } },
            scales: { x: { grid: { display: false } }, y: { grid: { color: gridColor }, ticks: { callback: moneyTick } } }
        }
    });
}

const turnoverLabels = <?php echo json_encode(array_map(static fn($r) => $r['category_name'], array_slice($turnover_chart, 0, 12))); ?>;
const turnoverSellThrough = <?php echo json_encode(array_map(static fn($r) => round((float)$r['sell_through'], 2), array_slice($turnover_chart, 0, 12))); ?>;
const turnoverValues = <?php echo json_encode(array_map(static fn($r) => round((float)$r['turnover'], 2), array_slice($turnover_chart, 0, 12))); ?>;
setChartEmpty("turnoverChartPanel", !hasData(turnoverSellThrough) && !hasData(turnoverValues));
if (turnoverLabels.length) {
    new Chart(document.getElementById("turnoverChart"), {
        data: {
            labels: turnoverLabels,
            datasets: [
                { type: "bar", label: "Sell-Through %", data: turnoverSellThrough, backgroundColor: palette.sky, borderRadius: 8, yAxisID: "y", maxBarThickness: 32 },
                { type: "line", label: "Turnover", data: turnoverValues, borderColor: palette.amber, backgroundColor: palette.amber, yAxisID: "y1", tension: .35, pointRadius: 3, pointHoverRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: "top", align: "end" } },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { callback: percentTick } },
                y1: { beginAtZero: true, position: "right", grid: { drawOnChartArea: false }, ticks: { callback: (v) => Number(v).toFixed(1) + "x" } }
            }
        }
    });
}

const marginLabels = <?php echo json_encode(array_map(static fn($r) => $r['name'], array_slice($margin_rows, 0, 12))); ?>;
const marginProfits = <?php echo json_encode(array_map(static fn($r) => (float)$r['total_profit'], array_slice($margin_rows, 0, 12))); ?>;
setChartEmpty("marginChartPanel", !hasData(marginProfits));
if (marginLabels.length) {
    new Chart(document.getElementById("marginChart"), {
        type: "bar",
        data: { labels: marginLabels, datasets: [{ label: "Total Profit", data: marginProfits, backgroundColor: palette.green, borderRadius: 8 }] },
        options: {
            indexAxis: "y",
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => `Profit: ${moneyTick(ctx.parsed.x)}` } } },
            scales: { x: { grid: { color: gridColor }, ticks: { callback: moneyTick } }, y: { grid: { display: false } } }
        }
    });
}

const serviceLabels = <?php echo json_encode(array_map(static fn($r) => $r['day'], $service_performance)); ?>;
const serviceDays = <?php echo json_encode(array_map(static fn($r) => (float)$r['avg_days'], $service_performance)); ?>;
setChartEmpty("serviceChartPanel", !hasData(serviceDays));
if (serviceLabels.length) {
    new Chart(document.getElementById("serviceChart"), {
        type: "line",
        data: {
            labels: serviceLabels,
            datasets: [{ label: "Avg Turnaround Days", data: serviceDays, borderColor: palette.indigo, backgroundColor: palette.indigoSoft, fill: true, tension: .35, pointRadius: 3, pointHoverRadius: 4 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => `${Number(ctx.parsed.y).toFixed(1)} days` } } },
            scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: gridColor }, ticks: { callback: (v) => `${v}d` } } }
        }
    });
}

function exportTableToCSV(id, filename) { const table = document.getElementById(id); if (!table) return; const csv = [...table.querySelectorAll('tr')].map((tr) => [...tr.querySelectorAll('th,td')].map((cell) => `"${(cell.innerText || '').replace(/\s+/g, ' ').trim().replace(/"/g, '""')}"`).join(',')).join('\n'); const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' }); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = (filename || 'report') + '.csv'; link.click(); URL.revokeObjectURL(link.href); }
async function exportSectionToPDF(id, filename) { const el = document.getElementById(id); if (!el || !window.html2canvas || !window.jspdf?.jsPDF) return; const canvas = await window.html2canvas(el, { scale: 2, backgroundColor: '#ffffff', useCORS: true }); const { jsPDF } = window.jspdf; const pdf = new jsPDF('p', 'mm', 'a4'); const pageW = pdf.internal.pageSize.getWidth() - 10; const pageH = pdf.internal.pageSize.getHeight() - 10; const imgH = (canvas.height * pageW) / canvas.width; const img = canvas.toDataURL('image/png'); let left = imgH; let pos = 5; pdf.addImage(img, 'PNG', 5, pos, pageW, imgH); left -= pageH; while (left > 0) { pos = left - imgH + 5; pdf.addPage(); pdf.addImage(img, 'PNG', 5, pos, pageW, imgH); left -= pageH; } pdf.save((filename || 'report') + '.pdf'); }
document.querySelectorAll('[data-export-table]').forEach((btn) => btn.addEventListener('click', () => exportTableToCSV(btn.dataset.exportTable, btn.dataset.filename)));
document.querySelectorAll('[data-export-section]').forEach((btn) => btn.addEventListener('click', () => exportSectionToPDF(btn.dataset.exportSection, btn.dataset.filename)));
document.getElementById("quickRange")?.addEventListener("change", (event) => {
    const startInput = document.getElementById("startDate");
    const endInput = document.getElementById("endDate");
    if (!startInput || !endInput) return;
    if (event.target.value === "custom") return;
    const today = new Date();
    const end = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    const start = new Date(end);
    if (event.target.value === "last_7") start.setDate(end.getDate() - 6);
    if (event.target.value === "last_30") start.setDate(end.getDate() - 29);
    if (event.target.value === "last_90") start.setDate(end.getDate() - 89);
    const toIso = (value) => value.toISOString().slice(0, 10);
    startInput.value = toIso(start);
    endInput.value = toIso(end);
});
</script>
<?php include 'templates/footer.php'; ?>
