<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_role('super_admin');

$type = $_GET['type'] ?? '';
$pdo  = db();

$filename = '';
$headers  = [];
$rows     = [];

try {
    if ($type === 'savings') {
        $filename = 'savings_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Member', 'Type', 'Amount', 'Reference', 'Status', 'Date'];
        $data = $pdo->query("SELECT s.*, u.full_name FROM savings s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'], $row['type'], $row['amount'], $row['reference_number'], $row['status'], $row['created_at']]; }
    } elseif ($type === 'loans') {
        $filename = 'loans_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Member', 'Amount', 'Outstanding', 'Interest', 'Interest Paid', 'Late Fees', 'Status', 'Disbursed', 'Due'];
        $data = $pdo->query("SELECT l.*, u.full_name FROM loans l JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'], $row['amount'], $row['principal_outstanding'], $row['interest_flat'], $row['interest_paid'], $row['late_fees'], $row['status'], $row['disbursed_at'], $row['due_at']]; }
    } elseif ($type === 'shares') {
        $filename = 'shares_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Member', 'Shares', 'Price/Share', 'Total', 'Status', 'Date'];
        $data = $pdo->query("SELECT s.*, u.full_name FROM shares s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'], $row['share_count'], $row['price_per_share'], $row['total_amount'], $row['status'], $row['created_at']]; }
    } elseif ($type === 'users') {
        $filename = 'users_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Name', 'Email', 'Phone', 'DOB', 'Role', 'Status', 'Created'];
        $data = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'], $row['email'], $row['phone'], $row['date_of_birth'], $row['role'], $row['status'], $row['created_at']]; }
    } elseif ($type === 'repayments') {
        $filename = 'repayments_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Loan ID', 'Member', 'Amount', 'Status', 'Approved At', 'Created'];
        $data = $pdo->query("SELECT r.*, u.full_name FROM repayments r JOIN users u ON u.id = r.user_id ORDER BY r.created_at DESC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['loan_id'], $row['full_name'], $row['amount'], $row['status'], $row['approved_at'], $row['created_at']]; }
    } elseif ($type === 'audit') {
        $filename = 'audit_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Actor', 'Action', 'Details', 'IP', 'Date'];
        $data = $pdo->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT 1000")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'] ?? 'System', $row['action'], json_encode($row['details'] ?? ''), $row['ip_address'], $row['created_at']]; }
    } elseif ($type === 'suggestions') {
        $filename = 'suggestions_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Member', 'Title', 'Description', 'Status', 'Date'];
        $data = $pdo->query("SELECT s.*, u.full_name FROM suggestions s JOIN users u ON u.id = s.user_id ORDER BY s.created_at DESC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'], $row['title'], $row['description'], $row['status'], $row['created_at']]; }
    } elseif ($type === 'verified_savings') {
        $filename = 'verified_savings_' . date('Ymd_His') . '.csv';
        $headers = ['ID', 'Member', 'Current Balance', 'Proposed Balance', 'Last Verified'];
        $data = $pdo->query("SELECT vs.*, u.full_name, u.email FROM verified_savings vs JOIN users u ON u.id = vs.user_id ORDER BY u.full_name ASC")->fetchAll();
        foreach ($data as $row) { $rows[] = [$row['id'], $row['full_name'], $row['current_balance'], $row['proposed_balance'], $row['verified_at']]; }
    } else {
        $filename = 'empty.csv';
        $headers = ['No data'];
    }
} catch (Exception $e) {
    $filename = 'empty.csv';
    $headers = ['No data'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($output, $headers);
foreach ($rows as $row) { fputcsv($output, $row); }
fclose($output);
exit;
