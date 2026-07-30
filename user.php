<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_role('user');

try {

$userId = (int)$_SESSION['user_id'];
$pdo    = db();
$me     = $userId;

// ─── Helpers ─────────────────────────────────────────────────────────────────
function userStatusLabel(string $s): string {
    return match($s) {
        'pending'            => t('under_review'),
        'reviewer_approved'  => t('pending_final'),
        'approved','approved_disbursed','closed' => t('completed'),
        'rejected'           => t('declined'),
        default              => $s,
    };
}
function statusClass(string $s): string {
    return match($s) {
        'pending'            => 'bg-gray-500/10 text-gray-400',
        'reviewer_approved'  => 'bg-yellow-500/10 text-yellow-400',
        'approved','approved_disbursed','closed' => 'bg-green-500/10 text-green-400',
        'rejected'           => 'bg-red-500/10 text-red-400',
        default              => 'bg-gray-500/10 text-gray-400',
    };
}
function tab_redirect(): never {
    $rtab = $_POST['tab'] ?? $_GET['tab'] ?? '';
    $base = $_SERVER['SCRIPT_NAME'] ?? 'user.php';
    redirect($base . ($rtab ? '?tab=' . urlencode($rtab) : ''));
}

// ─── POST Handlers ───────────────────────────────────────────────────────────
if (is_post()) {
    verify_csrf();
    $sub = $_POST['action'] ?? '';
    $msg = null;

    // ── Savings Deposit / Withdrawal ──────────────────────────────────────────
    if ($sub === 'deposit' || $sub === 'withdrawal') {
        $amount = (float)($_POST['amount'] ?? 0);
        $refNum = trim($_POST['reference_number'] ?? '');
        try {
            if ($amount <= 0) throw new Exception(t('error'));
            if ($sub === 'withdrawal') {
                $vs = $pdo->prepare("SELECT current_balance, locked_balance FROM verified_savings WHERE user_id = ?");
                $vs->execute([$me]); $v = $vs->fetch();
                $available = (float)($v['current_balance'] ?? 0) - (float)($v['locked_balance'] ?? 0);
                if ($amount > $available) throw new Exception('Withdrawal exceeds available balance (' . number_format($available, 2) . ' RWF).');
            }
            $pdo->prepare("INSERT INTO savings (user_id, type, amount, reference_number, status) VALUES (?, ?, ?, ?, 'pending')")->execute([$me, $sub, $amount, $refNum ?: null]);
            $savId = (int)$pdo->lastInsertId();
            $proofFile = null;
            if (!empty($_FILES['proof']['name']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','application/pdf'];
                $maxSize = 5 * 1024 * 1024;
                $file    = $_FILES['proof'];
                $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($file['type'], $allowed, true) && !in_array($ext, ['jpg','jpeg','png','pdf'], true))
                    throw new Exception(t('invalid_file_type'));
                if ($file['size'] > $maxSize) throw new Exception(t('file_too_large'));
                $fname = 'proof_sav_' . $savId . '_' . time() . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fname)) {
                    $proofFile = $fname;
                    $pdo->prepare("UPDATE savings SET proof_file = ? WHERE id = ?")->execute([$proofFile, $savId]);
                }
            }
            notify($me, ucfirst($sub) . ' of ' . number_format($amount, 2) . ' RWF ' . t('under_review'));
            audit_log('savings_request', ['user_id' => $me, 'type' => $sub, 'amount' => $amount, 'ref' => $refNum]);
            $msg = ['success', t('success')];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Loan Application ──────────────────────────────────────────────────────
    if ($sub === 'loan_apply') {
        $amount = (float)($_POST['amount'] ?? 0);
        try {
            if ($amount <= 0) throw new Exception(t('error'));
            $vs = $pdo->prepare("SELECT current_balance, locked_balance FROM verified_savings WHERE user_id = ?");
            $vs->execute([$me]); $v = $vs->fetch();
            $savBal = (float)($v['current_balance'] ?? 0);
            $maxLoan = $savBal * 1.5;
            if ($amount > $maxLoan) throw new Exception('Loan exceeds 1.5x verified savings (' . number_format($maxLoan, 2) . ' RWF).');
            $sh = $pdo->prepare("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = ? AND status = 'approved'");
            $sh->execute([$me]);
            $totalShares = (int)$sh->fetchColumn();
            $sett = $pdo->query("SELECT min_shares_to_borrow, interest_rate, term_days, share_price FROM settings WHERE id = 1")->fetch();
            $minShares = (int)($sett['min_shares_to_borrow'] ?? 0);
            if ($totalShares < $minShares) throw new Exception(t('shares') . ': ' . $minShares . '+ ' . t('required'));
            $interestRate = (float)($sett['interest_rate'] ?? 0);
            $termDays = (int)($sett['term_days'] ?? 30);
            $interestFlat = $amount * ($interestRate / 100);
            $dueAt = date('Y-m-d H:i:s', strtotime('+' . $termDays . ' days'));
            $pdo->prepare("INSERT INTO loans (user_id, amount, principal_outstanding, interest_flat, due_at) VALUES (?, ?, ?, ?, ?)")->execute([$me, $amount, $amount, $interestFlat, $dueAt]);
            $loanId = (int)$pdo->lastInsertId();
            $guarantorId = (int)($_POST['guarantor_id'] ?? 0);
            if ($guarantorId > 0 && $guarantorId !== $me) {
                $gAmt = (float)($_POST['guaranteed_amount'] ?? 0);
                if ($gAmt <= 0) $gAmt = $amount;
                $pdo->prepare("INSERT INTO guarantors (loan_id, guarantor_id, borrower_id, locked_amount) VALUES (?, ?, ?, ?)")->execute([$loanId, $guarantorId, $me, $gAmt]);
                notify($guarantorId, $_SESSION['full_name'] . ' requested you as guarantor for ' . number_format($gAmt, 2) . ' RWF.');
            }
            audit_log('loan_applied', ['user_id' => $me, 'amount' => $amount]);
            notify($me, t('loans') . ' ' . number_format($amount, 2) . ' RWF ' . t('under_review'));
            $msg = ['success', t('success')];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Cancel Loan ──────────────────────────────────────────────────────────
    if ($sub === 'loan_cancel') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        try {
            $l = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ? AND status = 'pending'");
            $l->execute([$loanId, $me]); $loan = $l->fetch();
            if (!$loan) throw new Exception(t('error'));
            $pdo->prepare("UPDATE loans SET status = 'rejected' WHERE id = ?")->execute([$loanId]);
            audit_log('loan_cancelled', ['user_id' => $me, 'loan_id' => $loanId, 'amount' => $loan['amount']]);
            notify($me, 'Loan #' . $loanId . ' has been cancelled.');
            $msg = ['success', 'Loan cancelled.'];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Edit Loan (within 3h, pending only) ──────────────────────────────────
    if ($sub === 'loan_edit') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $newAmount = (float)($_POST['amount'] ?? 0);
        try {
            if ($newAmount <= 0) throw new Exception(t('error'));
            $l = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND user_id = ? AND status = 'pending'");
            $l->execute([$loanId, $me]); $loan = $l->fetch();
            if (!$loan) throw new Exception(t('error'));
            $createdAt = strtotime($loan['created_at']);
            if (time() - $createdAt > 3 * 3600) throw new Exception('Edit window expired (3 hours from submission).');
            $vs = $pdo->prepare("SELECT current_balance, locked_balance FROM verified_savings WHERE user_id = ?");
            $vs->execute([$me]); $v = $vs->fetch();
            $savBal = (float)($v['current_balance'] ?? 0);
            $maxLoan = $savBal * 1.5;
            if ($newAmount > $maxLoan) throw new Exception('Loan exceeds 1.5x verified savings (' . number_format($maxLoan, 2) . ' RWF).');
            $sh = $pdo->prepare("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = ? AND status = 'approved'");
            $sh->execute([$me]);
            $totalShares = (int)$sh->fetchColumn();
            $settEdit = $pdo->query("SELECT min_shares_to_borrow, interest_rate, term_days, share_price FROM settings WHERE id = 1")->fetch();
            $minShares = (int)($settEdit['min_shares_to_borrow'] ?? 0);
            if ($totalShares < $minShares) throw new Exception(t('shares') . ': ' . $minShares . '+ ' . t('required'));
            $interestRate = (float)($settEdit['interest_rate'] ?? 0);
            $termDays = (int)($settEdit['term_days'] ?? 30);
            $interestFlat = $newAmount * ($interestRate / 100);
            $dueAt = date('Y-m-d H:i:s', strtotime('+' . $termDays . ' days'));
            $pdo->prepare("UPDATE loans SET amount = ?, principal_outstanding = ?, interest_flat = ?, due_at = ? WHERE id = ?")->execute([$newAmount, $newAmount, $interestFlat, $dueAt, $loanId]);
            audit_log('loan_edited', ['user_id' => $me, 'loan_id' => $loanId, 'old_amount' => $loan['amount'], 'new_amount' => $newAmount]);
            notify($me, 'Loan #' . $loanId . ' updated to ' . number_format($newAmount, 2) . ' RWF.');
            $msg = ['success', 'Loan updated.'];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Buy Shares ────────────────────────────────────────────────────────────
    if ($sub === 'buy_shares') {
        $count = (int)($_POST['share_count'] ?? 0);
        try {
            if ($count <= 0) throw new Exception(t('error'));
            $sett = $pdo->query("SELECT share_price FROM settings WHERE id = 1")->fetch();
            $price = (float)($sett['share_price'] ?? 0);
            $total = $count * $price;
            $pdo->prepare("INSERT INTO shares (user_id, share_count, price_per_share, total_amount) VALUES (?, ?, ?, ?)")->execute([$me, $count, $price, $total]);
            audit_log('shares_purchased', ['user_id' => $me, 'count' => $count, 'total' => $total]);
            notify($me, $count . ' ' . t('shares') . ' ' . t('under_review'));
            $msg = ['success', t('success')];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Repayment ─────────────────────────────────────────────────────────────
    if ($sub === 'repay') {
        $loanId = (int)($_POST['loan_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $refNum = trim($_POST['reference_number'] ?? '');
        try {
            if ($amount <= 0) throw new Exception(t('error'));
            $pdo->prepare("INSERT INTO repayments (loan_id, user_id, amount, reference_number, status) VALUES (?, ?, ?, ?, 'pending')")->execute([$loanId, $me, $amount, $refNum ?: null]);
            $repId = (int)$pdo->lastInsertId();
            $proofFile = null;
            if (!empty($_FILES['proof']['name']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','application/pdf'];
                $maxSize = 5 * 1024 * 1024;
                $file    = $_FILES['proof'];
                $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($file['type'], $allowed, true) && !in_array($ext, ['jpg','jpeg','png','pdf'], true))
                    throw new Exception(t('invalid_file_type'));
                if ($file['size'] > $maxSize) throw new Exception(t('file_too_large'));
                $fname = 'proof_rep_' . $repId . '_' . time() . '.' . $ext;
                $uploadDir = __DIR__ . '/uploads';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $fname)) {
                    $proofFile = $fname;
                    $pdo->prepare("UPDATE repayments SET proof_file = ? WHERE id = ?")->execute([$proofFile, $repId]);
                }
            }
            audit_log('repayment_submitted', ['user_id' => $me, 'loan_id' => $loanId, 'amount' => $amount, 'ref' => $refNum]);
            notify($me, t('repayments') . ' ' . number_format($amount, 2) . ' RWF ' . t('under_review'));
            $msg = ['success', t('success')];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Suggestion ────────────────────────────────────────────────────────────
    if ($sub === 'submit_suggestion') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        try {
            if ($title === '' || $description === '') throw new Exception(t('error'));
            $pdo->prepare("INSERT INTO suggestions (user_id, title, description) VALUES (?, ?, ?)")->execute([$me, $title, $description]);
            notify($me, t('suggestions') . ' ' . t('under_review'));
            $msg = ['success', t('success')];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Guarantor Response ────────────────────────────────────────────────────
    if ($sub === 'accept_guarantee' || $sub === 'decline_guarantee') {
        $gid = (int)($_POST['guarantor_id'] ?? 0);
        try {
            $g = $pdo->prepare("SELECT * FROM guarantors WHERE id = ? AND guarantor_id = ? AND status = 'pending'");
            $g->execute([$gid, $me]); $gRow = $g->fetch();
            if (!$gRow) throw new Exception(t('error'));
            if ($sub === 'accept_guarantee') {
                $lockAmt = (float)$gRow['locked_amount'];
                $vs = $pdo->prepare("SELECT current_balance, locked_balance FROM verified_savings WHERE user_id = ?");
                $vs->execute([$me]); $v = $vs->fetch();
                $available = (float)($v['current_balance'] ?? 0) - (float)($v['locked_balance'] ?? 0);
                if ($lockAmt > $available) throw new Exception('Insufficient available savings to guarantee this amount.');
                $pdo->prepare("UPDATE verified_savings SET locked_balance = locked_balance + ? WHERE user_id = ?")->execute([$lockAmt, $me]);
                $pdo->prepare("UPDATE guarantors SET status = 'accepted' WHERE id = ?")->execute([$gid]);
                notify((int)$gRow['borrower_id'], $_SESSION['full_name'] . ' accepted your guarantor request.');
            } else {
                $pdo->prepare("UPDATE guarantors SET status = 'declined' WHERE id = ?")->execute([$gid]);
                notify((int)$gRow['borrower_id'], $_SESSION['full_name'] . ' declined your guarantor request.');
            }
            audit_log('guarantor_' . $sub, ['guarantor_id' => $gid]);
            $msg = ['success', t('success')];
        } catch (Throwable $e) { $msg = ['error', $e->getMessage()]; }
        $_SESSION['flash'] = $msg; tab_redirect();
    }

    // ── Notifications ─────────────────────────────────────────────────────────
    if ($sub === 'mark_read') {
        $nid = (int)($_POST['notification_id'] ?? 0);
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$nid, $me]);
        json_response(['ok' => true]);
    }
    if ($sub === 'mark_all_read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$me]);
        json_response(['ok' => true]);
    }

    // ── Statement Download ────────────────────────────────────────────────────
    if ($sub === 'download_statement') {
        $from = $_POST['st_from'] ?? date('Y-m-01');
        $to   = $_POST['st_to'] ?? date('Y-m-t');
        $savRows = $pdo->prepare("SELECT * FROM savings WHERE user_id = ? AND status = 'approved' AND created_at BETWEEN ? AND ? ORDER BY created_at ASC");
        $savRows->execute([$me, $from . ' 00:00:00', $to . ' 23:59:59']);
        $savRows = $savRows->fetchAll();
        $repRows = $pdo->prepare("SELECT r.*, l.amount as loan_amount FROM repayments r JOIN loans l ON l.id = r.loan_id WHERE r.user_id = ? AND r.status = 'approved' AND r.created_at BETWEEN ? AND ? ORDER BY r.created_at ASC");
        $repRows->execute([$me, $from . ' 00:00:00', $to . ' 23:59:59']);
        $repRows = $repRows->fetchAll();
        require_once __DIR__ . '/fpdf.php';
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Cell(0, 10, $_SESSION['full_name'] . ' - Account Statement', 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 8, 'Period: ' . $from . ' to ' . $to, 0, 1, 'C');
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Savings Transactions', 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
        $header = ['Date', 'Type', 'Amount', 'Reference'];
        $w = [35, 35, 50, 60];
        for ($i = 0; $i < 4; $i++) { $pdf->Cell($w[$i], 7, $header[$i], 1); }
        $pdf->Ln();
        $pdf->SetFont('Helvetica', '', 9);
        foreach ($savRows as $r) {
            $pdf->Cell($w[0], 6, date('d M Y', strtotime($r['created_at'])), 1);
            $pdf->Cell($w[1], 6, $r['type'], 1);
            $pdf->Cell($w[2], 6, number_format((float)$r['amount'], 2) . ' RWF', 1);
            $pdf->Cell($w[3], 6, $r['reference_number'] ?? '-', 1);
            $pdf->Ln();
        }
        $pdf->Ln(5);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 8, 'Repayments', 0, 1);
        $pdf->SetFont('Helvetica', '', 9);
        $header2 = ['Date', 'Loan Amount', 'Payment'];
        $w2 = [35, 50, 50];
        for ($i = 0; $i < 3; $i++) { $pdf->Cell($w2[$i], 7, $header2[$i], 1); }
        $pdf->Ln();
        foreach ($repRows as $r) {
            $pdf->Cell($w2[0], 6, date('d M Y', strtotime($r['created_at'])), 1);
            $pdf->Cell($w2[1], 6, number_format((float)$r['loan_amount'], 2) . ' RWF', 1);
            $pdf->Cell($w2[2], 6, number_format((float)$r['amount'], 2) . ' RWF', 1);
            $pdf->Ln();
        }
        $pdf->Output('D', 'statement_' . $from . '_to_' . $to . '.pdf');
        exit;
    }

    tab_redirect();
}

// ─── Data ─────────────────────────────────────────────────────────────────────
$vs = $pdo->prepare("SELECT current_balance, locked_balance FROM verified_savings WHERE user_id = ?");
$vs->execute([$me]); $vsData = $vs->fetch();
$verifiedBalance = (float)($vsData['current_balance'] ?? 0);
$lockedBalance   = (float)($vsData['locked_balance'] ?? 0);
$availableBalance = $verifiedBalance - $lockedBalance;

$pd = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM savings WHERE user_id = ? AND status = 'pending' AND type = 'deposit'");
$pd->execute([$me]); $pendingDeposits = (float)$pd->fetchColumn();

$activeLoan = $pdo->prepare("SELECT * FROM loans WHERE user_id = ? AND status = 'approved_disbursed' ORDER BY created_at DESC LIMIT 1");
$activeLoan->execute([$me]); $activeLoanData = $activeLoan->fetch();

$sc = $pdo->prepare("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = ? AND status IN ('pending','reviewer_approved','approved')");
$sc->execute([$me]); $shareCount = (int)$sc->fetchColumn();
$sharePrice = (float)$pdo->query("SELECT share_price FROM settings WHERE id = 1")->fetchColumn();
$shareEquity = $shareCount * $sharePrice;

$dt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE user_id = ? AND status = 'approved' AND loan_id IS NULL");
$dt->execute([$me]); $dividendTotal = (float)$dt->fetchColumn();

// Credit Score
$score = 500;
$completedLoans = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'closed'");
$completedLoans->execute([$me]); $score += (int)$completedLoans->fetchColumn() * 10;
$approvedShares = $pdo->prepare("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = ? AND status = 'approved'");
$approvedShares->execute([$me]); $score += (int)$approvedShares->fetchColumn() * 2;
$rejected = $pdo->prepare("SELECT COUNT(*) FROM savings WHERE user_id = ? AND status = 'rejected'");
$rejected->execute([$me]); $score -= (int)$rejected->fetchColumn() * 5;
$score = max(300, min(999, $score));
$scoreBadge = $score >= 750 ? 'Excellent' : ($score >= 600 ? 'Good' : ($score >= 450 ? 'Fair' : 'Needs Work'));
$scoreColor = $score >= 750 ? 'text-green-400' : ($score >= 600 ? 'text-blue-400' : ($score >= 450 ? 'text-yellow-400' : 'text-red-400'));

// Transactions
$sr = $pdo->prepare("SELECT * FROM savings WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$sr->execute([$me]); $savingsRows = $sr->fetchAll();

$lr = $pdo->prepare("SELECT * FROM loans WHERE user_id = ? ORDER BY created_at DESC");
$lr->execute([$me]); $loanRows = $lr->fetchAll();

$shr = $pdo->prepare("SELECT * FROM shares WHERE user_id = ? ORDER BY created_at DESC");
$shr->execute([$me]); $shareRows = $shr->fetchAll();

$rr = $pdo->prepare("SELECT r.*, l.amount as loan_amount FROM repayments r LEFT JOIN loans l ON l.id = r.loan_id WHERE r.user_id = ? ORDER BY r.created_at DESC");
$rr->execute([$me]); $repaymentRows = $rr->fetchAll();

$sugr = $pdo->prepare("SELECT * FROM suggestions WHERE user_id = ? ORDER BY created_at DESC");
$sugr->execute([$me]); $suggestionRows = $sugr->fetchAll();

$nr = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 30");
$nr->execute([$me]); $notificationRows = $nr->fetchAll();
$unreadCount = 0;
foreach ($notificationRows as $n) { if (!$n['is_read']) $unreadCount++; }

// Guarantor requests
$incomingGuarantees = $pdo->prepare("SELECT g.*, u.full_name as borrower_name, l.amount as loan_amount FROM guarantors g JOIN users u ON u.id = g.borrower_id JOIN loans l ON l.id = g.loan_id WHERE g.guarantor_id = ? AND g.status = 'pending' ORDER BY g.created_at DESC");
$incomingGuarantees->execute([$me]); $incomingGuarantees = $incomingGuarantees->fetchAll();
$outgoingGuarantees = $pdo->prepare("SELECT g.*, u.full_name as guarantor_name FROM guarantors g JOIN users u ON u.id = g.guarantor_id WHERE g.borrower_id = ? ORDER BY g.created_at DESC");
$outgoingGuarantees->execute([$me]); $outgoingGuarantees = $outgoingGuarantees->fetchAll();

// Loan options for repayment calculator
$calcLoans = $pdo->prepare("SELECT id, amount, principal_outstanding, interest_flat, interest_paid, late_fees, due_at FROM loans WHERE user_id = ? AND status IN ('approved_disbursed','closed') ORDER BY created_at DESC LIMIT 20");
$calcLoans->execute([$me]); $calcLoans = $calcLoans->fetchAll();

// Activity log (my own actions)
$actStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE actor_id = ? ORDER BY created_at DESC LIMIT 100");
$actStmt->execute([$me]); $activityRows = $actStmt->fetchAll();

// Settings (with safe fallback)
$sett = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
if ($sett === false) $sett = [];

// ─── Tab State ─────────────────────────────────────────────────────────────
$validTabs = ['dashboard','savings','loans','shares','repayments','suggestions','guarantors','account','activity'];
$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, $validTabs, true)) $tab = 'dashboard';

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashJson = $flash ? json_encode($flash) : 'null';
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('app_name') ?> — <?= t('dashboard') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>tailwind.config={theme:{extend:{colors:{dark:{bg:'#0b1220',panel:'#121a2b',border:'#1e2a45',hover:'#1a2540'},primary:{DEFAULT:'#2ba7ff',hover:'#1e8fe0',light:'#3db4ff'}},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>
        :root,[data-theme="dark"]{--bg:#0b1220;--panel:#121a2b;--border:#1e2a45;--hover:#1a2540;--text:#e2e8f0;--text-sec:#94a3b8;--text-muted:#64748b}
        [data-theme="light"]{--bg:#f1f5f9;--panel:#ffffff;--border:#e2e8f0;--hover:#f8fafc;--text:#1e293b;--text-sec:#475569;--text-muted:#94a3b8}
        *{scrollbar-width:thin;scrollbar-color:var(--border) transparent}body{background-color:var(--bg);font-family:'Inter',sans-serif;color:var(--text)}
        .panel{background-color:var(--panel);border:1px solid var(--border)}.input-field{background-color:var(--bg);border:1px solid var(--border);color:var(--text)}
        .input-field:focus{border-color:#2ba7ff;outline:none;box-shadow:0 0 0 3px rgba(43,167,255,0.15)}
        .sidebar{width:250px;height:100vh;position:fixed;top:0;left:0;z-index:40;transition:transform .3s}
        .main-content{margin-left:250px;transition:margin-left .3s}
        .nav-item{display:flex;align-items:center;gap:12px;padding:10px 16px;border-radius:10px;cursor:pointer;transition:all .2s;color:var(--text-sec);font-size:14px}
        .nav-item:hover{background:var(--hover);color:var(--text)}.nav-item.active{background:rgba(43,167,255,0.12);color:#2ba7ff;font-weight:600}
        .badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;font-size:11px;font-weight:600}
        .stat-card{background:linear-gradient(135deg,var(--panel) 0%,var(--hover) 100%);border:1px solid var(--border);border-radius:14px;padding:20px;transition:transform .2s}
        .stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(0,0,0,0.2)}
        .toast{position:fixed;top:20px;right:20px;z-index:999;padding:14px 20px;border-radius:12px;font-size:14px;font-weight:500;transform:translateX(120%);transition:transform .4s cubic-bezier(.68,-.55,.27,1.55);box-shadow:0 8px 32px rgba(0,0,0,0.3)}
        .toast.show{transform:translateX(0)}.toast-success{background:#065f46;color:#d1fae5;border:1px solid #059669}.toast-error{background:#7f1d1d;color:#fecaca;border:1px solid #dc2626}
        .tab-content{display:none}.tab-content.active{display:block}
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:100;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px)}.modal-overlay.show{display:flex}
        .modal-box{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:24px;max-width:480px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.4)}
        @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}}
        .empty-state{text-align:center;padding:40px 20px}.empty-state i{font-size:48px;color:var(--text-muted);opacity:.3;margin-bottom:16px}
        .sim-card{background:var(--hover);border:1px solid var(--border);border-radius:12px;padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
        .sim-card .item{text-align:center}.sim-card .item .val{font-size:20px;font-weight:700;color:var(--text)}.sim-card .item .lbl{font-size:11px;color:var(--text-muted);margin-top:2px}
        .score-ring{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;margin:0 auto}
        .notification-drop{position:absolute;top:100%;right:0;width:360px;max-height:420px;overflow-y:auto;z-index:50;display:none;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.3)}.notification-drop.show{display:block}
        .sub-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;color:var(--text-sec);background:transparent}
        .sub-tab:hover{background:var(--hover);color:var(--text)}.sub-tab.active{background:rgba(43,167,255,0.12);color:#2ba7ff}
        <?php if ($activeLoanData): ?>
        .loan-bar{background:linear-gradient(90deg,var(--text-sec),var(--text-muted));height:6px;border-radius:3px}
        <?php endif; ?>
    </style>
    <script>
        (function(){const t=localStorage.getItem('theme')||'<?= $theme ?>';document.documentElement.setAttribute('data-theme',t)})();
        function toggleTheme(){const h=document.documentElement,n=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',n);localStorage.setItem('theme',n);document.querySelector('#theme-icon').className=n==='dark'?'fas fa-sun':'fas fa-moon'}
        function showToast(type,msg){const t=document.getElementById('toast');t.className='toast toast-'+type;t.innerHTML=(type==='success'?'<i class="fas fa-check-circle mr-2"></i>':'<i class="fas fa-exclamation-circle mr-2"></i>')+msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),4000)}
        function switchTab(tab){document.querySelectorAll('.tab-content').forEach(el=>el.classList.remove('active'));document.getElementById('tab-'+tab).classList.add('active');document.querySelectorAll('.tab-btn').forEach(el=>el.classList.remove('active'));const btn=document.querySelector('[data-tab="'+tab+'"]');if(btn)btn.classList.add('active');const url=new URL(window.location);url.searchParams.set('tab',tab);history.replaceState({},'',url)}
        document.addEventListener('submit',function(e){const f=e.target;if(f.querySelector('input[name="action"]')&&!f.querySelector('input[name="tab"]')){const inp=document.createElement('input');inp.type='hidden';inp.name='tab';f.appendChild(inp);const at=document.querySelector('.tab-content.active');if(at)inp.value=at.id.replace('tab-','')}})
        function toggleNotif(){const d=document.getElementById('notif-drop');d.classList.toggle('show');if(d.classList.contains('show'))document.addEventListener('click',function h(e){if(!d.contains(e.target)&&e.target!==document.querySelector('[onclick="toggleNotif()"]')){d.classList.remove('show');document.removeEventListener('click',h)}})}
        function filterTable(id,tid){const inp=document.getElementById(id),f=inp.value.toUpperCase(),t=document.getElementById(tid),r=t.getElementsByTagName('tr');for(let i=1;i<r.length;i++){const c=r[i].getElementsByTagName('td'),found=Array.from(c).some(c=>c.textContent.toUpperCase().includes(f));r[i].style.display=found?'':'none'}}
        function confirmAction(id){document.getElementById('confirm-modal').classList.add('show');document.getElementById('confirm-yes').onclick=function(){document.getElementById('confirm-modal').classList.remove('show');document.getElementById(id).submit()}}
        function calcRepayment(){const loanId=document.getElementById('calc-loan-id').value,date=document.getElementById('calc-date').value;if(!loanId||!date)return;const loans=<?php $cl = json_encode($calcLoans); echo $cl !== false ? $cl : '[]' ?>;const loan=loans.find(l=>l.id==parseInt(loanId));if(!loan){document.getElementById('calc-result').innerHTML='<p style="color:var(--text-muted)">Loan not found</p>';return}
        const due=new Date(loan.due_at),sim=new Date(date),od=Math.max(0,Math.floor((sim-due)/(86400000)));
        const lateRate=<?= (float)($sett['late_fee_rate'] ?? 0) ?>;const prinOut=parseFloat(loan.principal_outstanding);
        const lateAccrued=prinOut*(lateRate/100)*od;const intOwed=Math.max(0,parseFloat(loan.interest_flat)-parseFloat(loan.interest_paid));
        const totalOut=prinOut+intOwed+lateAccrued;
        document.getElementById('calc-result').innerHTML=
        '<div class="sim-card"><div class="item"><div class="val" style="color:var(--text)">'+prinOut.toLocaleString()+'</div><div class="lbl"><?= t('principal') ?> (RWF)</div></div>'+
        '<div class="item"><div class="val" style="color:#f59e0b">'+intOwed.toLocaleString()+'</div><div class="lbl"><?= t('interest') ?> (RWF)</div></div>'+
        '<div class="item"><div class="val'+(od>0?' text-red-400':' text-green-400')+'">'+(od>0?od+' days':'0')+'</div><div class="lbl"><?= t('overdue_days') ?></div></div>'+
        '<div class="item"><div class="val'+(lateAccrued>0?' text-red-400':' text-green-400')+'">'+lateAccrued.toFixed(2)+'</div><div class="lbl"><?= t('late_fees') ?> (RWF)</div></div>'+
        '<div class="item"><div class="val" style="color:#2ba7ff">'+totalOut.toFixed(2)+'</div><div class="lbl"><?= t('total') ?> (RWF)</div></div></div>'+
        '<div class="text-xs mt-3 p-3 rounded-lg" style="background:var(--hover);color:var(--text-sec)"><i class="fas fa-info-circle mr-1"></i>Distribution: Late Fees &rarr; Interest &rarr; Principal</div>';}
        <?php if ($flash): ?>
        document.addEventListener('DOMContentLoaded',function(){var f=<?= $flashJson ?>;if(f&&f[1])showToast(f[0],f[1])});
        <?php endif; ?>
    </script>
</head>
<body>
<div id="toast"></div>
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-30 hidden" onclick="document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.add('hidden')"></div>

<aside id="sidebar" class="sidebar panel border-r" style="border-color:var(--border)">
    <div class="flex items-center gap-3 px-6 h-16 border-b" style="border-color:var(--border)">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary to-blue-600 flex items-center justify-center"><i class="fas fa-chart-line text-white text-sm"></i></div>
        <span class="font-bold text-lg" style="color:var(--text)"><?= t('app_name') ?></span>
        <span class="text-xs px-2 py-0.5 rounded-full bg-green-500/10 text-green-400 font-medium ml-auto">User</span>
    </div>
    <nav class="p-4 space-y-1">
        <div class="nav-item tab-btn <?= $tab==='dashboard'?'active':'' ?>" data-tab="dashboard" onclick="switchTab('dashboard')"><i class="fas fa-chart-pie w-5 text-center"></i><?= t('dashboard') ?></div>
        <div class="nav-item tab-btn <?= $tab==='savings'?'active':'' ?>" data-tab="savings" onclick="switchTab('savings')"><i class="fas fa-piggy-bank w-5 text-center"></i><?= t('savings') ?></div>
        <div class="nav-item tab-btn <?= $tab==='loans'?'active':'' ?>" data-tab="loans" onclick="switchTab('loans')"><i class="fas fa-hand-holding-usd w-5 text-center"></i><?= t('loans') ?></div>
        <div class="nav-item tab-btn <?= $tab==='shares'?'active':'' ?>" data-tab="shares" onclick="switchTab('shares')"><i class="fas fa-chart-pie w-5 text-center"></i><?= t('shares') ?></div>
        <div class="nav-item tab-btn <?= $tab==='repayments'?'active':'' ?>" data-tab="repayments" onclick="switchTab('repayments')"><i class="fas fa-credit-card w-5 text-center"></i><?= t('repayments') ?></div>
        <div class="nav-item tab-btn <?= $tab==='suggestions'?'active':'' ?>" data-tab="suggestions" onclick="switchTab('suggestions')"><i class="fas fa-lightbulb w-5 text-center"></i><?= t('suggestions') ?></div>
        <div class="nav-item tab-btn <?= $tab==='guarantors'?'active':'' ?>" data-tab="guarantors" onclick="switchTab('guarantors')"><i class="fas fa-handshake w-5 text-center"></i><?= t('guarantors') ?> <?php if (count($incomingGuarantees) > 0): ?><span class="badge text-white text-xs" style="background:#eab308"><?= count($incomingGuarantees) ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='account'?'active':'' ?>" data-tab="account" onclick="switchTab('account')"><i class="fas fa-cog w-5 text-center"></i><?= t('account') ?></div>
        <div class="nav-item tab-btn <?= $tab==='activity'?'active':'' ?>" data-tab="activity" onclick="switchTab('activity')"><i class="fas fa-history w-5 text-center"></i>Activity</div>
    </nav>
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t" style="border-color:var(--border)">
        <div class="flex items-center gap-3 px-3 py-2 rounded-lg" style="background:var(--hover)">
            <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate" style="color:var(--text)"><?= htmlspecialchars($_SESSION['full_name']) ?></p>
                <p class="text-xs" style="color:var(--text-muted)">Member</p>
            </div>
            <button onclick="toggleTheme()" style="color:var(--text-sec)"><i id="theme-icon" class="fas <?= $theme==='dark'?'fa-sun':'fa-moon' ?>"></i></button>
            <a href="logout.php" style="color:var(--text-sec)" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</aside>

<div class="main-content min-h-screen">
    <header class="h-16 flex items-center justify-between px-6 border-b" style="background:var(--panel);border-color:var(--border)">
        <button class="md:hidden text-lg" style="color:var(--text-sec)" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebar-overlay').classList.toggle('hidden')"><i class="fas fa-bars"></i></button>
        <div class="flex items-center gap-4 ml-auto">
            <div class="relative">
                <button onclick="toggleNotif()" class="relative text-lg" style="color:var(--text-sec)"><i class="fas fa-bell"></i><?php if ($unreadCount): ?><span class="absolute -top-2 -right-2 w-5 h-5 rounded-full bg-red-500 text-white text-xs flex items-center justify-center font-bold" style="font-size:10px"><?= min(99, $unreadCount) ?></span><?php endif; ?></button>
                <div id="notif-drop" class="notification-drop panel" onclick="event.stopPropagation()">
                    <div class="flex items-center justify-between p-3 border-b" style="border-color:var(--border)">
                        <span class="font-semibold text-sm" style="color:var(--text)"><?= t('notifications') ?></span>
                        <?php if ($unreadCount): ?><button onclick="var t='<?= csrf_token() ?>';fetch('user.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+t+'&action=mark_all_read'}).then(()=>location.reload())" class="text-xs text-primary"><?= t('mark_all_read') ?></button><?php endif; ?>
                    </div>
                    <div class="divide-y" style="border-color:var(--border)">
                        <?php if (count($notificationRows) > 0): foreach ($notificationRows as $n): ?>
                        <div class="flex items-start gap-3 p-3 <?= $n['is_read'] ? '' : 'bg-primary/5' ?>">
                            <div class="w-2 h-2 rounded-full mt-2 <?= $n['is_read'] ? 'bg-gray-600' : 'bg-primary' ?>"></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs" style="color:var(--text)"><?= htmlspecialchars($n['message']) ?></p>
                                <p class="text-xs mt-1" style="color:var(--text-muted)"><?= date('d M H:i', strtotime($n['created_at'])) ?></p>
                            </div>
                            <?php if (!$n['is_read']): ?>
                            <button onclick="var t='<?= csrf_token() ?>';var btn=this;fetch('user.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'csrf_token='+t+'&action=mark_read&notification_id=<?= $n['id'] ?>'}).then(()=>{btn.closest('div.flex').classList.remove('bg-primary\\/5');btn.remove()})" class="text-xs" style="color:var(--text-muted)"><i class="fas fa-check"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="p-6 text-center text-sm" style="color:var(--text-muted)"><?= t('no_records') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <span class="text-sm hidden md:inline" style="color:var(--text-sec)"><?= date('l, M j, Y') ?></span>
        </div>
    </header>

    <div class="p-6">

    <!-- ═══════════════════ DASHBOARD ═══════════════════ -->
    <div id="tab-dashboard" class="tab-content <?= $tab==='dashboard'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('dashboard') ?></h1><p class="text-sm mt-1" style="color:var(--text-sec)"><?= t('welcome') ?>, <?= htmlspecialchars($_SESSION['full_name']) ?></p></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
            <div class="stat-card" style="cursor:pointer" onclick="switchTab('savings')"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)"><?= t('total_savings') ?></span><div class="w-10 h-10 rounded-lg bg-green-500/10 flex items-center justify-center"><i class="fas fa-piggy-bank text-green-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= number_format($verifiedBalance, 2) ?> RWF</p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('available_balance') ?>: <?= number_format($availableBalance, 2) ?> RWF<?php if ($lockedBalance > 0): ?> | <?= t('locked_savings') ?>: <?= number_format($lockedBalance, 2) ?><?php endif; ?></p></div>
            <div class="stat-card" style="cursor:pointer" onclick="switchTab('shares')"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)"><?= t('total_shares') ?></span><div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center"><i class="fas fa-chart-pie text-purple-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= number_format($shareCount) ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('share_equity') ?>: <?= number_format($shareEquity, 2) ?> RWF @ <?= number_format($sharePrice, 2) ?> RWF</p></div>
            <div class="stat-card" style="cursor:pointer" onclick="switchTab('loans')"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)"><?= t('active_loan') ?></span><div class="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center"><i class="fas fa-hand-holding-usd text-blue-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $activeLoanData ? number_format((float)$activeLoanData['principal_outstanding'], 2) . ' RWF' : '0.00 RWF' ?></p></div>
            <div class="stat-card" style="cursor:pointer" onclick="switchTab('savings')"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)"><?= t('pending_deposits') ?></span><div class="w-10 h-10 rounded-lg bg-yellow-500/10 flex items-center justify-center"><i class="fas fa-clock text-yellow-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= number_format($pendingDeposits, 2) ?> RWF</p></div>
        </div>
        <!-- Credit Score -->
        <div class="panel rounded-xl p-6 mb-6">
            <div class="flex items-center gap-6">
                <div class="text-center">
                    <div class="score-ring" style="background:conic-gradient(<?= $score >= 750 ? '#34d399' : ($score >= 600 ? '#2ba7ff' : ($score >= 450 ? '#fbbf24' : '#f87171')) ?> <?= ($score/999)*360 ?>deg, var(--hover) <?= ($score/999)*360 ?>deg)">
                        <span style="background:var(--panel);border-radius:50%;width:65px;height:65px;display:flex;align-items:center;justify-content:center" class="<?= $scoreColor ?>"><?= $score ?></span>
                    </div>
                    <p class="text-xs mt-2 font-medium <?= $scoreColor ?>"><?= $scoreBadge ?></p>
                </div>
                <div><h3 class="font-semibold" style="color:var(--text)"><?= t('credit_score') ?></h3><p class="text-sm mt-1" style="color:var(--text-sec)">Based on loan repayments, share holdings, and account activity.</p></div>
            </div>
        </div>
        <!-- Recent Activity -->
        <div class="panel rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4" style="color:var(--text)"><?= t('recent_activity') ?></h2>
            <div class="space-y-3 max-h-64 overflow-y-auto">
                <?php
                $allActivity = array_merge(
                    array_map(fn($r)=>['type'=>'savings','data'=>$r,'date'=>$r['created_at']], $savingsRows),
                    array_map(fn($r)=>['type'=>'loan','data'=>$r,'date'=>$r['created_at']], $loanRows),
                    array_map(fn($r)=>['type'=>'share','data'=>$r,'date'=>$r['created_at']], $shareRows),
                    array_map(fn($r)=>['type'=>'repayment','data'=>$r,'date'=>$r['created_at']], $repaymentRows)
                );
                usort($allActivity, fn($a,$b)=>strtotime($b['date'])-strtotime($a['date']));
                $allActivity = array_slice($allActivity, 0, 15);
                ?>
                <?php foreach ($allActivity as $act): $d = $act['data']; ?>
                <div class="flex items-center gap-3 p-2.5 rounded-lg" style="background:var(--hover)">
                    <div class="w-8 h-8 rounded-full <?= match($act['type']){'savings'=>'bg-green-500/10','loan'=>'bg-yellow-500/10','share'=>'bg-purple-500/10','repayment'=>'bg-blue-500/10',default=>'bg-gray-500/10'} ?> flex items-center justify-center">
                        <i class="fas fa-<?= match($act['type']){'savings'=>'piggy-bank','loan'=>'hand-holding-usd','share'=>'chart-pie','repayment'=>'credit-card',default=>'circle'} ?> <?= match($act['type']){'savings'=>'text-green-400','loan'=>'text-yellow-400','share'=>'text-purple-400','repayment'=>'text-blue-400',default=>'text-gray-400'} ?>"></i>
                    </div>
                    <div class="flex-1"><p class="text-sm" style="color:var(--text)"><strong><?= ucfirst($act['type']) ?></strong> — <?= number_format((float)($d['total_amount'] ?? $d['amount'] ?? 0), 2) ?> RWF <span class="text-xs px-1.5 py-0.5 rounded <?= statusClass($d['status'] ?? '') ?>"><?= userStatusLabel($d['status'] ?? '') ?></span></p><p class="text-xs" style="color:var(--text-muted)"><?= date('d M Y', strtotime($d['created_at'])) ?></p></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($allActivity)): ?><div class="empty-state"><i class="fas fa-history"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ SAVINGS ═══════════════════ -->
    <div id="tab-savings" class="tab-content <?= $tab==='savings'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('savings') ?></h1><p class="text-sm mt-1" style="color:var(--text-sec)"><?= t('available_balance') ?>: <strong><?= number_format($availableBalance, 2) ?> RWF</strong> | <?= t('verified_balance') ?>: <?= number_format($verifiedBalance, 2) ?> RWF</p></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-arrow-down text-green-400 mr-2"></i><?= t('deposit') ?></h3>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?><input type="hidden" name="action" value="deposit">
                    <div class="space-y-3">
                        <input type="number" step="0.01" min="1" name="amount" placeholder="<?= t('amount') ?> (RWF)" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                        <input type="text" name="reference_number" placeholder="<?= t('transaction_id') ?> (Ref #)" class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                        <div><label class="text-xs" style="color:var(--text-sec)"><?= t('upload_proof') ?> (JPEG/PNG/PDF, max 5MB)</label><input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" class="input-field w-full text-sm px-3 py-2 rounded-lg mt-1"></div>
                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-arrow-down mr-1"></i><?= t('deposit') ?></button>
                    </div>
                </form>
            </div>
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-arrow-up text-red-400 mr-2"></i><?= t('withdrawal') ?></h3>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?><input type="hidden" name="action" value="withdrawal">
                    <div class="space-y-3">
                        <input type="number" step="0.01" min="1" max="<?= $availableBalance ?>" name="amount" placeholder="<?= t('amount') ?>" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                        <p class="text-xs" style="color:var(--text-muted)"><?= t('available_balance') ?>: <?= number_format($availableBalance, 2) ?> RWF | <?= t('locked_savings') ?>: <?= number_format($lockedBalance, 2) ?> RWF</p>
                        <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-arrow-up mr-1"></i><?= t('withdrawal') ?></button>
                    </div>
                </form>
            </div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="flex items-center justify-between p-4 border-b" style="border-color:var(--border)"><span class="font-semibold" style="color:var(--text)"><?= t('transaction_history') ?></span><input type="text" id="savings-filter" onkeyup="filterTable('savings-filter','savings-tbl')" placeholder="<?= t('search') ?>..." class="input-field px-3 py-1.5 rounded-lg text-sm w-48"></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="savings-tbl">
                    <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('date') ?></th><th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('type') ?></th><th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('amount') ?></th><th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)">Ref #</th><th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('status') ?></th><th class="text-center py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Receipt</th><th class="text-left py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)"><?= t('notes') ?></th></tr></thead>
                    <tbody><?php if (count($savingsRows) > 0): foreach ($savingsRows as $r): ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-3" style="color:var(--text-sec)"><?= date('d M Y', strtotime($r['created_at'])) ?></td><td class="py-3 px-3"><span class="text-xs px-2 py-0.5 rounded font-medium <?= $r['type']==='deposit'?'bg-green-500/10 text-green-400':'bg-red-500/10 text-red-400' ?>"><?= $r['type']==='deposit'?t('deposit'):t('withdrawal') ?></span></td><td class="py-3 px-3 text-right font-medium" style="color:var(--text)"><?= number_format((float)$r['amount'], 2) ?></td><td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars((string)($r['reference_number'] ?? '-')) ?></td><td class="py-3 px-3 text-center"><span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($r['status']) ?>"><?= userStatusLabel($r['status']) ?></span></td><td class="py-3 px-3 text-center hidden lg:table-cell"><?php if (!empty($r['proof_file'])): ?><a href="uploads/<?= htmlspecialchars($r['proof_file']) ?>" target="_blank" class="text-xs text-primary hover:underline"><i class="fas fa-file"></i> View</a><?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?></td><td class="py-3 px-3 hidden lg:table-cell" style="color:var(--text-muted);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars((string)($r['reviewer_notes'] ?? '')) ?></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ LOANS ═══════════════════ -->
    <div id="tab-loans" class="tab-content <?= $tab==='loans'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('loans') ?></h1><p class="text-sm mt-1" style="color:var(--text-sec)"><?= t('max_loan') ?>: <strong><?= number_format($verifiedBalance * 1.5, 2) ?> RWF</strong> | <?= t('shares') ?>: <?= $shareCount ?></p></div>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div>
                <div class="panel rounded-xl p-6 mb-6">
                    <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-plus-circle text-yellow-400 mr-2"></i><?= t('loans') ?></h3>
                    <form method="POST">
                        <?= csrf_field() ?><input type="hidden" name="action" value="loan_apply">
                        <div class="space-y-3">
                            <input type="number" step="0.01" min="1" name="amount" placeholder="<?= t('amount') ?> (RWF)" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                            <div><label class="text-xs" style="color:var(--text-sec)"><?= t('guarantor') ?> (<?= t('optional') ?>)</label>
                            <select name="guarantor_id" class="input-field w-full px-4 py-2.5 rounded-lg text-sm mt-1">
                                <option value="">— <?= t('none') ?> —</option>
                                <?php
                                $guarantorOpts = $pdo->query("SELECT id, full_name FROM users WHERE role = 'user' AND id != $me AND status = 'approved' ORDER BY full_name ASC")->fetchAll();
                                foreach ($guarantorOpts as $gu): ?>
                                <option value="<?= $gu['id'] ?>"><?= htmlspecialchars($gu['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select></div>
                            <p class="text-xs" style="color:var(--text-muted)"><?= t('interest_rate') ?>: <?= (float)($sett['interest_rate'] ?? 5) ?>% | <?= t('term') ?>: <?= (int)($sett['term_days'] ?? 30) ?> <?= t('days') ?></p>
                            <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-paper-plane mr-1"></i><?= t('submit') ?></button>
                        </div>
                    </form>
                </div>
                <!-- Repayment Calculator -->
                <div class="panel rounded-xl p-6">
                    <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-calculator text-blue-400 mr-2"></i><?= t('repayment_calculator') ?></h3>
                    <div class="space-y-3">
                        <select id="calc-loan-id" class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                            <option value="">— <?= t('select') ?> —</option>
                            <?php foreach ($calcLoans as $cl): ?>
                            <option value="<?= $cl['id'] ?>">#<?= $cl['id'] ?> — <?= number_format((float)$cl['amount'], 0) ?> RWF</option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" id="calc-date" class="input-field w-full px-4 py-2.5 rounded-lg text-sm" value="<?= date('Y-m-d') ?>">
                        <button onclick="calcRepayment()" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-calculator mr-1"></i><?= t('calculate') ?></button>
                        <div id="calc-result"></div>
                    </div>
                </div>
            </div>
            <div>
                <?php if ($activeLoanData):
                    $prinOut = (float)$activeLoanData['principal_outstanding'];
                    $intTotal = (float)$activeLoanData['interest_flat'];
                    $intPaid = (float)$activeLoanData['interest_paid'];
                    $lateFees = (float)$activeLoanData['late_fees'];
                    $dueAt = strtotime($activeLoanData['due_at']);
                    $overdueDays = max(0, (int)((time() - $dueAt) / 86400));
                ?>
                <div class="panel rounded-xl p-6 mb-6">
                    <div class="flex items-center justify-between mb-4"><h3 class="font-semibold" style="color:var(--text)"><i class="fas fa-info-circle text-blue-400 mr-2"></i><?= t('active_loan') ?> #<?= $activeLoanData['id'] ?></h3></div>
                    <div class="space-y-3">
                        <div class="flex justify-between"><span class="text-sm" style="color:var(--text-sec)"><?= t('principal') ?></span><span class="text-sm font-medium" style="color:var(--text)"><?= number_format($prinOut, 2) ?> RWF</span></div>
                        <div class="flex justify-between"><span class="text-sm" style="color:var(--text-sec)"><?= t('interest') ?></span><span class="text-sm font-medium" style="color:var(--text)"><?= number_format(max(0, $intTotal - $intPaid), 2) ?> RWF (<?= number_format($intPaid, 2) ?> <?= t('paid') ?>)</span></div>
                        <div class="flex justify-between"><span class="text-sm" style="color:var(--text-sec)"><?= t('late_fees') ?></span><span class="text-sm font-medium <?= $lateFees > 0 ? 'text-red-400' : 'text-green-400' ?>"><?= number_format($lateFees, 2) ?> RWF</span></div>
                        <div class="flex justify-between"><span class="text-sm" style="color:var(--text-sec)"><?= t('due_date') ?></span><span class="text-sm font-medium <?= $overdueDays > 0 ? 'text-red-400' : '' ?>"><?= date('d M Y', $dueAt) ?> <?= $overdueDays > 0 ? '(' . $overdueDays . ' days overdue)' : '' ?></span></div>
                        <?php if ($overdueDays > 0): ?><div class="loan-bar mt-2" style="width:<?= min(100, $overdueDays * 5) ?>%"></div><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Loan History -->
                <div class="panel rounded-xl overflow-hidden">
                    <div class="flex items-center justify-between p-4 border-b" style="border-color:var(--border)"><span class="font-semibold" style="color:var(--text)"><?= t('history') ?></span></div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)">#</th><th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('amount') ?></th><th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('status') ?></th><th class="text-right py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)"><?= t('due_date') ?></th><th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)">Actions</th></tr></thead>
                            <tbody><?php if (count($loanRows) > 0): foreach ($loanRows as $r): $canEdit = $r['status'] === 'pending' && (time() - strtotime($r['created_at'])) <= 10800; $canCancel = $r['status'] === 'pending'; ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-3" style="color:var(--text-muted)"><?= $r['id'] ?></td><td class="py-3 px-3 text-right" style="color:var(--text)"><?= number_format((float)$r['amount'], 2) ?></td><td class="py-3 px-3 text-center"><span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($r['status']) ?>"><?= userStatusLabel($r['status']) ?></span></td><td class="py-3 px-3 text-right hidden md:table-cell" style="color:var(--text-sec)"><?= $r['due_at'] ? date('d M Y', strtotime($r['due_at'])) : '—' ?></td><td class="py-3 px-3 text-center"><?php if ($canEdit): ?><button onclick="openLoanEdit(<?= $r['id'] ?>, <?= $r['amount'] ?>)" class="text-xs px-2 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded mr-1"><i class="fas fa-edit"></i></button><?php endif; ?><?php if ($canCancel): ?><form method="POST" class="inline" onsubmit="return confirm('Cancel this loan request?')"><?= csrf_field() ?><input type="hidden" name="action" value="loan_cancel"><input type="hidden" name="loan_id" value="<?= $r['id'] ?>"><input type="hidden" name="tab" value="loans"><button type="submit" class="text-xs px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded"><i class="fas fa-times"></i></button></form><?php endif; ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></td></tr><?php endif; ?></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ SHARES ═══════════════════ -->
    <div id="tab-shares" class="tab-content <?= $tab==='shares'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('shares') ?></h1><p class="text-sm mt-1" style="color:var(--text-sec)"><?= t('share_price') ?>: <strong><?= number_format($sharePrice, 2) ?> RWF</strong> | <?= t('your_shares') ?>: <?= $shareCount ?> | <?= t('equity') ?>: <?= number_format($shareEquity, 2) ?> RWF</p></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-shopping-cart text-purple-400 mr-2"></i><?= t('buy_shares') ?></h3>
                <form method="POST">
                    <?= csrf_field() ?><input type="hidden" name="action" value="buy_shares">
                    <div class="space-y-3">
                        <input type="number" min="1" name="share_count" placeholder="<?= t('number_of_shares') ?>" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                        <p class="text-xs" style="color:var(--text-muted)"><?= number_format($sharePrice, 2) ?> RWF <?= t('per_share') ?></p>
                        <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-shopping-cart mr-1"></i><?= t('buy') ?></button>
                    </div>
                </form>
            </div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="flex items-center justify-between p-4 border-b" style="border-color:var(--border)"><span class="font-semibold" style="color:var(--text)"><?= t('share_history') ?></span></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('date') ?></th><th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('shares') ?></th><th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('total') ?></th><th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('status') ?></th></tr></thead>
                    <tbody><?php if (count($shareRows) > 0): foreach ($shareRows as $r): ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-3" style="color:var(--text-sec)"><?= date('d M Y', strtotime($r['created_at'])) ?></td><td class="py-3 px-3 text-right" style="color:var(--text)"><?= (int)$r['share_count'] ?></td><td class="py-3 px-3 text-right" style="color:var(--text)"><?= number_format((float)$r['total_amount'], 2) ?> RWF</td><td class="py-3 px-3 text-center"><span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($r['status']) ?>"><?= userStatusLabel($r['status']) ?></span></td></tr><?php endforeach; else: ?><tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ REPAYMENTS ═══════════════════ -->
    <div id="tab-repayments" class="tab-content <?= $tab==='repayments'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('repayments') ?></h1><p class="text-sm mt-1" style="color:var(--text-sec)"><?= t('dividends') ?>: <strong><?= number_format($dividendTotal, 2) ?> RWF</strong></p></div>
        </div>
        <div class="panel rounded-xl p-6 mb-6">
            <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-credit-card text-blue-400 mr-2"></i><?= t('make_repayment') ?></h3>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="action" value="repay">
                <div class="space-y-3">
                    <select name="loan_id" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                        <option value="">— <?= t('select_loan') ?> —</option>
                        <?php foreach ($loanRows as $lr): if ($lr['status'] === 'approved_disbursed'): ?>
                        <option value="<?= $lr['id'] ?>">#<?= $lr['id'] ?> — <?= number_format((float)$lr['amount'], 2) ?> RWF (<?= number_format((float)$lr['principal_outstanding'], 2) ?> <?= t('outstanding') ?>)</option>
                        <?php endif; endforeach; ?>
                    </select>
                    <input type="number" step="0.01" min="1" name="amount" placeholder="<?= t('payment_amount') ?> (RWF)" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    <input type="text" name="reference_number" placeholder="<?= t('transaction_id') ?> (Ref #)" class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    <div><label class="text-xs" style="color:var(--text-sec)"><?= t('upload_proof') ?> (JPEG/PNG/PDF, max 5MB)</label><input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" class="input-field w-full text-sm px-3 py-2 rounded-lg mt-1"></div>
                    <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-credit-card mr-1"></i><?= t('submit') ?></button>
                </div>
            </form>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="flex items-center justify-between p-4 border-b" style="border-color:var(--border)"><span class="font-semibold" style="color:var(--text)"><?= t('repayment_history') ?></span></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('date') ?></th><th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('amount') ?></th><th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)">Ref #</th><th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('status') ?></th><th class="text-center py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Receipt</th></tr></thead>
                    <tbody><?php if (count($repaymentRows) > 0): foreach ($repaymentRows as $r): ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-3" style="color:var(--text-sec)"><?= date('d M Y', strtotime($r['created_at'])) ?></td><td class="py-3 px-3 text-right font-medium" style="color:var(--text)"><?= number_format((float)$r['amount'], 2) ?> RWF</td><td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars((string)($r['reference_number'] ?? '-')) ?></td><td class="py-3 px-3 text-center"><span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($r['status']) ?>"><?= userStatusLabel($r['status']) ?></span></td><td class="py-3 px-3 text-center hidden lg:table-cell"><?php if (!empty($r['proof_file'])): ?><a href="uploads/<?= htmlspecialchars($r['proof_file']) ?>" target="_blank" class="text-xs text-primary hover:underline"><i class="fas fa-file"></i></a><?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ SUGGESTIONS ═══════════════════ -->
    <div id="tab-suggestions" class="tab-content <?= $tab==='suggestions'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('suggestions') ?></h1></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-lightbulb text-yellow-400 mr-2"></i><?= t('new_suggestion') ?></h3>
                <form method="POST">
                    <?= csrf_field() ?><input type="hidden" name="action" value="submit_suggestion">
                    <div class="space-y-3">
                        <input type="text" name="title" placeholder="<?= t('title') ?>" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                        <textarea name="description" rows="4" placeholder="<?= t('description') ?>" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm"></textarea>
                        <button type="submit" class="w-full bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-paper-plane mr-1"></i><?= t('submit') ?></button>
                    </div>
                </form>
            </div>
            <div class="space-y-3 max-h-96 overflow-y-auto">
                <?php if (count($suggestionRows) > 0): foreach ($suggestionRows as $s): ?>
                <div class="panel rounded-xl p-4">
                    <div class="flex items-start justify-between">
                        <div><h4 class="font-semibold text-sm" style="color:var(--text)"><?= htmlspecialchars($s['title']) ?></h4><p class="text-xs mt-1" style="color:var(--text-sec)"><?= htmlspecialchars($s['description']) ?></p></div>
                        <span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($s['status']) ?>"><?= userStatusLabel($s['status']) ?></span>
                    </div>
                    <p class="text-xs mt-2" style="color:var(--text-muted)"><?= date('d M Y', strtotime($s['created_at'])) ?></p>
                </div>
                <?php endforeach; else: ?>
                <div class="empty-state"><i class="fas fa-lightbulb"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ GUARANTORS ═══════════════════ -->
    <div id="tab-guarantors" class="tab-content <?= $tab==='guarantors'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('guarantors') ?></h1></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-inbox text-yellow-400 mr-2"></i><?= t('incoming_requests') ?></h3>
                <?php if (count($incomingGuarantees) > 0): foreach ($incomingGuarantees as $g): ?>
                <div class="border-b py-3" style="border-color:var(--border)">
                    <div class="flex items-center justify-between">
                        <div><p class="text-sm font-medium" style="color:var(--text)"><?= htmlspecialchars($g['borrower_name']) ?></p><p class="text-xs" style="color:var(--text-sec)"><?= number_format((float)$g['locked_amount'], 2) ?> RWF — <?= number_format((float)$g['loan_amount'], 2) ?> RWF <?= t('loan') ?></p></div>
                        <div class="flex gap-2">
                            <form method="POST" id="ag-<?= $g['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="accept_guarantee"><input type="hidden" name="guarantor_id" value="<?= $g['id'] ?>"><button type="submit" class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg"><i class="fas fa-check mr-1"></i><?= t('accept') ?></button></form>
                            <form method="POST" id="dg-<?= $g['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="decline_guarantee"><input type="hidden" name="guarantor_id" value="<?= $g['id'] ?>"><button type="submit" class="text-xs px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg"><i class="fas fa-times mr-1"></i><?= t('decline') ?></button></form>
                        </div>
                    </div>
                </div>
                <?php endforeach; else: ?><div class="empty-state"><i class="fas fa-handshake"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></div><?php endif; ?>
            </div>
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-paper-plane text-blue-400 mr-2"></i><?= t('outgoing_requests') ?></h3>
                <?php if (count($outgoingGuarantees) > 0): foreach ($outgoingGuarantees as $g): ?>
                <div class="border-b py-3" style="border-color:var(--border)">
                    <div class="flex items-center justify-between">
                        <div><p class="text-sm font-medium" style="color:var(--text)"><?= htmlspecialchars($g['guarantor_name']) ?></p><p class="text-xs" style="color:var(--text-sec)"><?= number_format((float)$g['locked_amount'], 2) ?> RWF <?= t('locked') ?></p></div>
                        <span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($g['status']) ?>"><?= userStatusLabel($g['status']) ?></span>
                    </div>
                </div>
                <?php endforeach; else: ?><div class="empty-state"><i class="fas fa-paper-plane"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ ACCOUNT ═══════════════════ -->
    <div id="tab-account" class="tab-content <?= $tab==='account'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('account') ?></h1></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-user text-primary mr-2"></i><?= t('profile') ?></h3>
                <?php $userInfo = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $userInfo->execute([$me]); $u = $userInfo->fetch(); ?>
                <div class="space-y-3 text-sm">
                    <div class="flex justify-between"><span style="color:var(--text-sec)"><?= t('full_name') ?></span><span style="color:var(--text)"><?= htmlspecialchars($u['full_name']) ?></span></div>
                    <div class="flex justify-between"><span style="color:var(--text-sec)"><?= t('email') ?></span><span style="color:var(--text)"><?= htmlspecialchars($u['email']) ?></span></div>
                    <div class="flex justify-between"><span style="color:var(--text-sec)"><?= t('phone') ?></span><span style="color:var(--text)"><?= htmlspecialchars($u['phone'] ?? '—') ?></span></div>
                    <div class="flex justify-between"><span style="color:var(--text-sec)"><?= t('date_of_birth') ?></span><span style="color:var(--text)"><?= htmlspecialchars($u['date_of_birth'] ?? '—') ?></span></div>
                    <div class="flex justify-between"><span style="color:var(--text-sec)"><?= t('status') ?></span><span class="text-xs px-2 py-0.5 rounded font-medium <?= statusClass($u['status']) ?>"><?= userStatusLabel($u['status']) ?></span></div>
                    <div class="flex justify-between"><span style="color:var(--text-sec)"><?= t('member_since') ?></span><span style="color:var(--text)"><?= date('d M Y', strtotime($u['created_at'])) ?></span></div>
                </div>
            </div>
            <div class="panel rounded-xl p-6">
                <h3 class="font-semibold mb-4" style="color:var(--text)"><i class="fas fa-file-pdf text-red-400 mr-2"></i><?= t('download_statement') ?></h3>
                <form method="POST" class="space-y-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="download_statement">
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="text-xs" style="color:var(--text-sec)"><?= t('from') ?></label><input type="date" name="st_from" value="<?= date('Y-m-01') ?>" class="input-field w-full px-3 py-2 rounded-lg text-sm mt-1"></div>
                        <div><label class="text-xs" style="color:var(--text-sec)"><?= t('to') ?></label><input type="date" name="st_to" value="<?= date('Y-m-t') ?>" class="input-field w-full px-3 py-2 rounded-lg text-sm mt-1"></div>
                    </div>
                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-file-pdf mr-1"></i><?= t('download_statement') ?></button>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ ACTIVITY ═══════════════════ -->
    <div id="tab-activity" class="tab-content <?= $tab==='activity'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><i class="fas fa-history mr-2" style="color:var(--text-sec)"></i>Activity Log</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Your recent actions on the platform</p></div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Date</th><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th><th class="text-left py-3 px-4 font-medium hidden md:table-cell" style="color:var(--text-sec)">IP</th></tr></thead>
                    <tbody><?php if (count($activityRows) > 0): foreach ($activityRows as $r): ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-4" style="color:var(--text-sec)"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td class="py-3 px-4" style="color:var(--text)"><?= htmlspecialchars(audit_description($r['action'], $r['details'])) ?></td><td class="py-3 px-4 hidden md:table-cell" style="color:var(--text-muted);font-family:monospace;font-size:0.75rem"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td></tr><?php endforeach; else: ?><tr><td colspan="3" class="empty-state"><i class="fas fa-history"></i><p style="color:var(--text-muted)">No activity recorded yet</p></td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </div>
    </div>

    </div><!-- /p-6 -->
</div><!-- /main-content -->

<!-- Edit Loan Modal -->
<div id="loan-edit-modal" style="display:none;position:fixed;inset:0;z-index:999;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;" onclick="if(event.target===this)closeLoanEdit()">
    <div class="panel rounded-xl p-6" style="max-width:420px;width:90%;margin:auto;">
        <div class="flex items-center justify-between mb-4"><h3 class="font-semibold" style="color:var(--text)"><i class="fas fa-edit text-blue-400 mr-2"></i>Edit Loan</h3><button onclick="closeLoanEdit()" style="color:var(--text-muted);font-size:1.2rem;background:none;border:none;cursor:pointer">&times;</button></div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="loan_edit">
            <input type="hidden" name="loan_id" id="edit-loan-id">
            <input type="hidden" name="tab" value="loans">
            <div class="space-y-3">
                <div><label class="text-xs" style="color:var(--text-sec)">Amount (RWF)</label><input type="number" step="0.01" min="1" name="amount" id="edit-loan-amount" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm mt-1"></div>
                <p class="text-xs" style="color:var(--text-muted)">Edits allowed within 3 hours of submission while request is pending.</p>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2.5 rounded-lg text-sm"><i class="fas fa-save mr-1"></i>Update Loan</button>
            </div>
        </form>
    </div>
</div>
<script>
function openLoanEdit(id, amount) {
    document.getElementById('edit-loan-id').value = id;
    document.getElementById('edit-loan-amount').value = amount;
    document.getElementById('loan-edit-modal').style.display = 'flex';
}
function closeLoanEdit() {
    document.getElementById('loan-edit-modal').style.display = 'none';
}
</script>
</body>
</html>
<?php
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>Internal Error</h2><pre>' . htmlspecialchars((string)$e) . '</pre>';
    exit;
}
?>