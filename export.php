<?php
// export.php
// Export reports to PDF, Excel, CSV, Email, Print
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$type = $_GET['type'] ?? 'csv';
$data = $_SESSION['export_data'] ?? [];
$filename = $_GET['filename'] ?? 'report';
if (empty($data)) exit('No data to export.');
if ($type === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($data[0]));
    foreach ($data as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
if ($type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_keys($data[0]));
    foreach ($data as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
if ($type === 'pdf') {
    // TODO: Integrate PDF library (e.g., TCPDF, FPDF)
    exit('PDF export not implemented.');
}
if ($type === 'print') {
    // Render print-friendly HTML
    include 'templates/header.php';
    echo '<h1>Print Report</h1>';
    echo '<table border="1">';
    echo '<tr>';
    foreach (array_keys($data[0]) as $col) echo '<th>' . htmlspecialchars($col) . '</th>';
    echo '</tr>';
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $val) echo '<td>' . htmlspecialchars($val) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    include 'templates/footer.php';
    exit;
}
if ($type === 'email') {
    // TODO: Integrate email sending with attachment
    exit('Email export not implemented.');
}
exit('Invalid export type.');
