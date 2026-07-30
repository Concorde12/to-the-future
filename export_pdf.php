<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_role('super_admin');

require_once __DIR__ . '/fpdf.php';

$type = $_GET['type'] ?? '';
$pdo  = db();

function headerRow(FPDF $pdf, array $headers): void {
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(43, 167, 255);
    $pdf->SetTextColor(255, 255, 255);
    for ($i = 0; $i < count($headers); $i++) {
        $pdf->Cell(190 / count($headers), 8, $headers[$i], 1, 0, 'C', true);
    }
    $pdf->Ln();
}

function dataRow(FPDF $pdf, array $row): void {
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(30, 41, 59);
    $n = count($row);
    for ($i = 0; $i < $n; $i++) {
        $pdf->Cell(190 / $n, 7, $row[$i], 1, 0, 'C');
    }
    $pdf->Ln();
}

$pdf = new FPDF('L');
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();

// Title
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 12, 'To The Future - ' . ucfirst($type) . ' Report', 0, 1, 'L');
$pdf->SetFont('Arial', '', 9);
$pdf->SetTextColor(100, 116, 139);
$pdf->Cell(0, 7, 'Generated: ' . date('d M Y H:i'), 0, 1, 'L');
$pdf->Ln(5);

try {
    if ($type === 'users') {
        $headers = ['ID', 'Name', 'Email', 'Phone', 'Role', 'Status'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT id, full_name, email, phone, role, status FROM users ORDER BY id")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'], $r['email'], $r['phone'] ?? '-', $r['role'], $r['status']]);
    } elseif ($type === 'savings') {
        $headers = ['ID', 'Member', 'Type', 'Amount', 'Status', 'Date'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT s.*, u.full_name FROM savings s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC LIMIT 200")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'], $r['type'], number_format((float)$r['amount'], 0), $r['status'], date('d M Y', strtotime($r['created_at']))]);
    } elseif ($type === 'loans') {
        $headers = ['ID', 'Member', 'Amount', 'Outstanding', 'Interest', 'Status'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT l.*, u.full_name FROM loans l JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 200")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'], number_format((float)$r['amount'], 0), number_format((float)$r['principal_outstanding'], 0), number_format((float)$r['interest_flat'], 0), $r['status']]);
    } elseif ($type === 'shares') {
        $headers = ['ID', 'Member', 'Shares', 'Price/Share', 'Total', 'Status'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT s.*, u.full_name FROM shares s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC LIMIT 200")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'], $r['share_count'], number_format((float)$r['price_per_share'], 0), number_format((float)$r['total_amount'], 0), $r['status']]);
    } elseif ($type === 'repayments') {
        $headers = ['ID', 'Loan ID', 'Member', 'Amount', 'Status', 'Date'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT r.*, u.full_name FROM repayments r JOIN users u ON u.id = r.user_id ORDER BY r.created_at DESC LIMIT 200")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['loan_id'] ?? '-', $r['full_name'], number_format((float)$r['amount'], 0), $r['status'], date('d M Y', strtotime($r['created_at']))]);
    } elseif ($type === 'audit') {
        $headers = ['ID', 'Actor', 'Action', 'IP', 'Date'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT 200")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'] ?? 'System', $r['action'], $r['ip_address'] ?? '-', date('d M Y H:i', strtotime($r['created_at']))]);
    } elseif ($type === 'verified_savings') {
        $headers = ['ID', 'Member', 'Current Balance', 'Proposed Balance'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT vs.*, u.full_name FROM verified_savings vs JOIN users u ON u.id = vs.user_id ORDER BY u.full_name")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'], number_format((float)$r['current_balance'], 0), number_format((float)$r['proposed_balance'], 0)]);
    } elseif ($type === 'suggestions') {
        $headers = ['ID', 'Member', 'Title', 'Status'];
        headerRow($pdf, $headers);
        $data = $pdo->query("SELECT s.*, u.full_name FROM suggestions s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC LIMIT 200")->fetchAll();
        foreach ($data as $r) dataRow($pdf, [$r['id'], $r['full_name'], $r['title'], $r['status']]);
    } else {
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'No data for: ' . $type, 0, 1, 'L');
    }
} catch (Exception $e) {
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Error: ' . $e->getMessage(), 0, 1, 'L');
}

$pdf->Output('D', $type . '_' . date('Ymd_His') . '.pdf');
