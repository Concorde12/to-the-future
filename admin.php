<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_role('super_admin');

$pdo = db();
$me  = (int)$_SESSION['user_id'];

// ─── Helpers ─────────────────────────────────────────────────────────────────
function pendCount(array $arr): int { return count($arr); }

// ─── Handle POST Actions ─────────────────────────────────────────────────────
if (is_post()) {
    verify_csrf();
    $sub = $_POST['action'] ?? '';
    $msg = null;

    // ── A. Approve / Reject / Deactivate / Reactivate User ─────────────────────
    if ($sub === 'approve_user') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $stat = $_POST['user_status'] ?? 'approved';
        if ($stat === 'inactive') {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND status = 'approved'")->execute([$stat, $uid]);
        } elseif ($stat === 'approved') {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND status IN ('pending','inactive')")->execute([$stat, $uid]);
            $pdo->prepare("INSERT IGNORE INTO verified_savings (user_id, current_balance, proposed_balance) VALUES (?, 0, 0)")->execute([$uid]);
        } else {
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ? AND status = 'pending'")->execute([$stat, $uid]);
        }
        notify($uid, 'Your account has been ' . $stat . '.');
        audit_log('user_status_changed', ['user_id' => $uid, 'new_status' => $stat]);
        $msg = ['success', 'User status updated.'];
    }

    // ── A. Update Role ────────────────────────────────────────────────────────
    if ($sub === 'update_role') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? 'user';
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $uid]);
        audit_log('user_role_changed', ['user_id' => $uid, 'new_role' => $role]);
        $msg = ['success', 'Role updated.'];
    }

    // ── B. Approve / Reject Verified Savings Proposal ─────────────────────────
    if ($sub === 'proposal_action') {
        $vsid  = (int)($_POST['vs_id'] ?? 0);
        $pa    = $_POST['proposal_action'] ?? '';
        $vs    = $pdo->prepare("SELECT * FROM verified_savings WHERE id = ?");
        $vs->execute([$vsid]);
        $vrow = $vs->fetch();
        if ($vrow) {
            if ($pa === 'approve') {
                $pdo->prepare("UPDATE verified_savings SET current_balance = proposed_balance, verified_at = NOW() WHERE id = ?")->execute([$vsid]);
                notify((int)$vrow['user_id'], 'Your verified savings proposal has been approved.');
                audit_log('proposal_approved', ['vs_id' => $vsid, 'user_id' => $vrow['user_id']]);
                $msg = ['success', 'Proposal approved.'];
            } else {
                $pdo->prepare("UPDATE verified_savings SET proposed_balance = current_balance WHERE id = ?")->execute([$vsid]);
                notify((int)$vrow['user_id'], 'Your verified savings proposal has been rejected.');
                audit_log('proposal_rejected', ['vs_id' => $vsid, 'user_id' => $vrow['user_id']]);
                $msg = ['success', 'Proposal rejected.'];
            }
        } else {
            $msg = ['error', 'Proposal not found.'];
        }
    }

    // ── C. Update Settings ────────────────────────────────────────────────────
    if ($sub === 'update_settings') {
        $pdo->prepare("UPDATE settings SET interest_rate = ?, late_fee_rate = ?, term_days = ?, share_price = ?, min_shares_to_borrow = ?, updated_at = NOW() WHERE id = 1")->execute([
            (float)$_POST['interest_rate'], (float)$_POST['late_fee_rate'],
            (int)$_POST['term_days'], (float)$_POST['share_price'],
            (int)$_POST['min_shares_to_borrow'],
        ]);
        audit_log('settings_updated', $_POST);
        $msg = ['success', 'Settings updated.'];
    }

    // ── D. Final Approval (dual-approval with guardrails) ─────────────────────
    if ($sub === 'final_approve') {
        $ftype = $_POST['final_type'] ?? '';
        $fid   = (int)($_POST['final_id'] ?? 0);
        try {
            $pdo->beginTransaction();

            if ($ftype === 'savings') {
                $st = $pdo->prepare("SELECT s.*, u.id as uid FROM savings s JOIN users u ON u.id = s.user_id WHERE s.id = ? AND s.status = 'reviewer_approved'");
                $st->execute([$fid]); $row = $st->fetch();
                if (!$row) throw new Exception('Savings record not found.');
                // Guardrail: prevent withdrawal that would overdraw
                if ($row['type'] === 'withdrawal') {
                    $bal = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='deposit' AND status='approved' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type='withdrawal' AND status='approved' THEN amount ELSE 0 END), 0) as bal FROM savings WHERE user_id = ?");
                    $bal->execute([$row['uid']]);
                    $totalBal = (float)$bal->fetch()['bal'];
                    if ((float)$row['amount'] > $totalBal) throw new Exception('Insufficient savings balance for withdrawal.');
                }
                $pdo->prepare("UPDATE savings SET status = 'approved', admin_id = ? WHERE id = ?")->execute([$me, $fid]);
                $sign = $row['type'] === 'deposit' ? '+' : '-';
                $pdo->prepare("UPDATE verified_savings SET current_balance = current_balance $sign ?, proposed_balance = proposed_balance $sign ? WHERE user_id = ?")->execute([$row['amount'], $row['amount'], $row['uid']]);
                $refStr = $row['reference_number'] ? ' (Ref: ' . $row['reference_number'] . ')' : '';
                notify((int)$row['uid'], 'Your ' . $row['type'] . ' of ' . number_format((float)$row['amount'], 2) . ' RWF has been approved and added to your savings.' . $refStr);
                audit_log('final_approve_savings', ['id' => $fid, 'user_id' => $row['uid'], 'ref' => $row['reference_number']]);

            } elseif ($ftype === 'loan') {
                $st = $pdo->prepare("SELECT l.*, u.id as uid FROM loans l JOIN users u ON u.id = l.user_id WHERE l.id = ? AND l.status = 'reviewer_approved'");
                $st->execute([$fid]); $row = $st->fetch();
                if (!$row) throw new Exception('Loan not found.');
                // Guardrail: no active open loans for this user
                $active = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'approved_disbursed'");
                $active->execute([$row['uid']]);
                if ((int)$active->fetchColumn() > 0) throw new Exception('Member has an active loan already.');
                // Guardrail: amount <= 1.5x verified savings
                $vs = $pdo->prepare("SELECT COALESCE(current_balance, 0) FROM verified_savings WHERE user_id = ?");
                $vs->execute([$row['uid']]);
                $savBal = (float)$vs->fetchColumn();
                $maxLoan = $savBal * 1.5;
                if ((float)$row['amount'] > $maxLoan) throw new Exception('Loan amount exceeds 1.5x verified savings (' . number_format($maxLoan, 2) . ' RWF).');
                // Guardrail: minimum shares
                $sh = $pdo->prepare("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = ? AND status = 'approved'");
                $sh->execute([$row['uid']]);
                $totalShares = (int)$sh->fetchColumn();
                $sett = $pdo->query("SELECT min_shares_to_borrow, interest_rate, term_days FROM settings WHERE id = 1")->fetch();
                $minShares = (int)$sett['min_shares_to_borrow'];
                if ($totalShares < $minShares) throw new Exception('Minimum ' . $minShares . ' approved shares required.');
                // Calculate interest & due date
                $interestRate = (float)$sett['interest_rate'];
                $termDays = (int)$sett['term_days'];
                $interestFlat = (float)$row['amount'] * ($interestRate / 100);
                $pdo->prepare("UPDATE loans SET status = 'approved_disbursed', admin_id = ?, disbursed_at = NOW(), due_at = DATE_ADD(NOW(), INTERVAL ? DAY), interest_flat = ?, principal_outstanding = amount WHERE id = ?")->execute([$me, $termDays, $interestFlat, $fid]);
                $refStr = $row['reference_number'] ? ' (Ref: ' . $row['reference_number'] . ')' : '';
                notify((int)$row['uid'], 'Your loan of ' . number_format((float)$row['amount'], 2) . ' RWF has been disbursed.' . $refStr);
                audit_log('final_approve_loan', ['id' => $fid, 'user_id' => $row['uid'], 'ref' => $row['reference_number']]);

            } elseif ($ftype === 'share') {
                $st = $pdo->prepare("SELECT s.* FROM shares s WHERE s.id = ? AND s.status = 'reviewer_approved'");
                $st->execute([$fid]); $row = $st->fetch();
                if (!$row) throw new Exception('Share record not found.');
                $pdo->prepare("UPDATE shares SET status = 'approved', admin_id = ? WHERE id = ?")->execute([$me, $fid]);
                notify((int)$row['user_id'], 'Your share purchase of ' . $row['share_count'] . ' shares has been approved.');
                audit_log('final_approve_share', ['id' => $fid, 'user_id' => $row['user_id']]);

            } elseif ($ftype === 'repayment') {
                $st = $pdo->prepare("SELECT r.*, l.amount as loan_amount, l.principal_outstanding, l.interest_flat, l.interest_paid, l.late_fees, l.due_at, l.status as loan_status FROM repayments r JOIN loans l ON l.id = r.loan_id WHERE r.id = ? AND r.status = 'reviewer_approved'");
                $st->execute([$fid]); $row = $st->fetch();
                if (!$row) throw new Exception('Repayment not found.');
                if ($row['loan_status'] === 'closed') throw new Exception('Loan is already closed.');

                $payment  = (float)$row['amount'];
                $rem      = $payment;
                $loanId   = (int)$row['loan_id'];

                // Calculate overdue days and late fees
                $dueDate  = $row['due_at'] ? strtotime($row['due_at']) : time();
                $now      = time();
                $overdueDays = max(0, (int)(($now - $dueDate) / 86400));
                $lateRate = (float)$pdo->query("SELECT late_fee_rate FROM settings WHERE id = 1")->fetchColumn();
                $lateFeeToApply = 0;
                if ($overdueDays > 0) {
                    $lateFeeToApply = (float)$row['principal_outstanding'] * ($lateRate / 100) * $overdueDays;
                }

                // Distribution: late fees first, then interest, then principal
                $lateFeesOwed  = max(0, (float)$row['late_fees']);
                $interestOwed  = max(0, (float)$row['interest_flat'] - (float)$row['interest_paid'] + $lateFeeToApply);
                $principalOwed = (float)$row['principal_outstanding'];

                // Apply late fees first
                $latePaid = min($rem, $lateFeesOwed);
                $rem -= $latePaid;
                $lateFeesOwed -= $latePaid;

                // Then interest
                $intPaid = min($rem, $interestOwed);
                $rem -= $intPaid;

                // Then principal
                $prinPaid = min($rem, $principalOwed);
                $rem -= $prinPaid;

                $newLateFees  = max(0, $lateFeesOwed);
                $newIntPaid   = (float)$row['interest_paid'] + $intPaid + $latePaid;
                $newPrincipal = max(0, $principalOwed - $prinPaid);

                $pdo->prepare("UPDATE loans SET principal_outstanding = ?, interest_paid = ?, late_fees = ? WHERE id = ?")->execute([$newPrincipal, $newIntPaid, $newLateFees, $loanId]);

                if ($newPrincipal <= 0 && ($newIntPaid >= (float)$row['interest_flat'])) {
                    $pdo->prepare("UPDATE loans SET status = 'closed' WHERE id = ?")->execute([$loanId]);
                }

                $pdo->prepare("UPDATE repayments SET status = 'approved', approved_at = NOW(), admin_id = ? WHERE id = ?")->execute([$me, $fid]);
                $refStr = $row['reference_number'] ? ' (Ref: ' . $row['reference_number'] . ')' : '';
                notify((int)$row['user_id'], 'Your repayment of ' . number_format($payment, 2) . ' RWF has been processed.' . $refStr);
                audit_log('final_approve_repayment', ['id' => $fid, 'loan_id' => $loanId, 'amount' => $payment, 'ref' => $row['reference_number']]);

            } elseif ($ftype === 'suggestion') {
                $st = $pdo->prepare("SELECT * FROM suggestions WHERE id = ? AND status = 'reviewer_approved'");
                $st->execute([$fid]); $row = $st->fetch();
                if (!$row) throw new Exception('Suggestion not found.');
                $sugStat = $_POST['suggestion_status'] ?? 'approved';
                $pdo->prepare("UPDATE suggestions SET status = ? WHERE id = ?")->execute([$sugStat, $fid]);
                notify((int)$row['user_id'], 'Your suggestion "' . $row['title'] . '" has been ' . $sugStat . '.');
                audit_log('final_approve_suggestion', ['id' => $fid, 'status' => $sugStat]);
            }

            $pdo->commit();
            $msg = ['success', 'Final approval completed.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = ['error', $e->getMessage()];
        }
    }

    // ── Dividends ─────────────────────────────────────────────────────────────
    if ($sub === 'distribute_dividends') {
        $netProfit = (float)($_POST['net_profit'] ?? 0);
        try {
            if ($netProfit <= 0) throw new Exception('Net profit must be positive.');
            $ts = $pdo->query("SELECT COALESCE(SUM(share_count), 0) as total FROM shares WHERE status = 'approved'")->fetch();
            $totalSC = (int)$ts['total'];
            if ($totalSC <= 0) throw new Exception('No approved shares.');
            $dps = $netProfit / $totalSC;
            $us = $pdo->query("SELECT user_id, SUM(share_count) as shares FROM shares WHERE status = 'approved' GROUP BY user_id")->fetchAll();
            foreach ($us as $u) {
                $div = (float)$u['shares'] * $dps;
                $pdo->prepare("INSERT INTO repayments (loan_id, user_id, amount, status, approved_at) VALUES (NULL, ?, ?, 'approved', NOW())")->execute([(int)$u['user_id'], $div]);
                notify((int)$u['user_id'], 'Dividend of ' . number_format($div, 2) . ' RWF credited.');
            }
            audit_log('dividends_distributed', ['net_profit' => $netProfit, 'per_share' => $dps]);
            $msg = ['success', 'Dividends distributed successfully.'];
        } catch (Exception $e) {
            $msg = ['error', $e->getMessage()];
        }
    }

    // ── Set flash & redirect ──────────────────────────────────────────────────
    if ($msg) { $_SESSION['flash'] = ['type' => $msg[0], 'message' => $msg[1]]; }
    $rtab = $_POST['tab'] ?? $_GET['tab'] ?? '';
    redirect('admin.php' . ($rtab ? '?tab=' . urlencode($rtab) : ''));
}

// ─── Pagination ───────────────────────────────────────────────────────────────
$up   = max(1, (int)($_GET['up'] ?? 1));
$pp   = 20;
$totalUsers   = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages   = max(1, (int)ceil($totalUsers / $pp));
$offset       = ($up - 1) * $pp;
$allUsers     = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?");
$allUsers->execute([$pp, $offset]);
$allUsers     = $allUsers->fetchAll();

$settings     = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();

// ─── Final Approvals Queue Data ───────────────────────────────────────────────
$finalSavings     = $pdo->query("SELECT s.*, u.full_name, u.email FROM savings s JOIN users u ON u.id = s.user_id WHERE s.status = 'reviewer_approved' ORDER BY s.created_at DESC")->fetchAll();
$finalLoans       = $pdo->query("SELECT l.*, u.full_name, u.email FROM loans l JOIN users u ON u.id = l.user_id WHERE l.status = 'reviewer_approved' ORDER BY l.created_at DESC")->fetchAll();
$finalShares      = $pdo->query("SELECT s.*, u.full_name, u.email FROM shares s JOIN users u ON u.id = s.user_id WHERE s.status = 'reviewer_approved' ORDER BY s.created_at DESC")->fetchAll();
$finalRepayments  = $pdo->query("SELECT r.*, u.full_name, u.email, l.amount as loan_amount, l.principal_outstanding, l.interest_flat, l.interest_paid, l.late_fees, l.due_at FROM repayments r JOIN users u ON u.id = r.user_id LEFT JOIN loans l ON l.id = r.loan_id WHERE r.status = 'reviewer_approved' ORDER BY r.created_at DESC")->fetchAll();
$finalSuggestions = $pdo->query("SELECT s.*, u.full_name, u.email FROM suggestions s JOIN users u ON u.id = s.user_id WHERE s.status = 'reviewer_approved' ORDER BY s.created_at DESC")->fetchAll();
$pendingUsers     = $pdo->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();

// ─── Proposals ────────────────────────────────────────────────────────────────
$proposals = $pdo->query("SELECT vs.*, u.full_name, u.email FROM verified_savings vs JOIN users u ON u.id = vs.user_id WHERE vs.current_balance != vs.proposed_balance ORDER BY vs.verified_at DESC")->fetchAll();

// ─── Dashboard Aggregates ─────────────────────────────────────────────────────
$dashTotalSavings = (float)$pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM verified_savings")->fetchColumn();
$dashActiveLoans  = (int)$pdo->query("SELECT COUNT(*) FROM loans WHERE status = 'approved_disbursed'")->fetchColumn();
$dashTotalShares  = (int)$pdo->query("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE status = 'approved'")->fetchColumn();
$dashPendingAll   = count($pendingUsers) + pendCount($finalSavings) + pendCount($finalLoans) + pendCount($finalShares) + pendCount($finalRepayments) + pendCount($finalSuggestions);

// ─── Statements Data ──────────────────────────────────────────────────────────
$stFrom   = $_GET['st_from'] ?? date('Y-m-01');
$stTo     = $_GET['st_to']   ?? date('Y-m-t');
$stUserId = (int)($_GET['st_user_id'] ?? 0);
$stSearch = $_GET['st_search'] ?? '';

$stmtWhere  = "WHERE s.status = 'approved' AND s.created_at BETWEEN ? AND ?";
$stmtParams = [$stFrom . ' 00:00:00', $stTo . ' 23:59:59'];
if ($stUserId > 0) { $stmtWhere .= " AND s.user_id = ?"; $stmtParams[] = $stUserId; }
if ($stSearch !== '') { $stmtWhere .= " AND (u.full_name LIKE ? OR s.reference_number LIKE ?)"; $stmtParams[] = "%$stSearch%"; $stmtParams[] = "%$stSearch%"; }

$stmtSql = "SELECT s.*, u.full_name, u.email FROM savings s JOIN users u ON u.id = s.user_id $stmtWhere ORDER BY s.created_at DESC LIMIT 200";
$stStmt  = $pdo->prepare($stmtSql);
$stStmt->execute($stmtParams);
$stmtRows = $stStmt->fetchAll();

// ─── Loans Statement Data ─────────────────────────────────────────────────────
$lsFrom   = $_GET['ls_from'] ?? date('Y-m-01');
$lsTo     = $_GET['ls_to']   ?? date('Y-m-t');
$lsUserId = (int)($_GET['ls_user_id'] ?? 0);
$lsWhere  = "WHERE (l.status IN ('approved_disbursed','closed') OR r.id IS NOT NULL) AND COALESCE(r.created_at, l.created_at) BETWEEN ? AND ?";
$lsParams = [$lsFrom . ' 00:00:00', $lsTo . ' 23:59:59'];
if ($lsUserId > 0) { $lsWhere .= " AND l.user_id = ?"; $lsParams[] = $lsUserId; }
$lsSql = "SELECT l.id as loan_id, l.user_id, l.amount as loan_amount, l.interest_flat, l.interest_paid, l.late_fees, l.principal_outstanding, l.status as loan_status, l.disbursed_at, l.due_at, l.created_at as loan_created, r.id as repayment_id, r.amount as repayment_amount, r.created_at as repayment_date, u.full_name, u.email FROM loans l JOIN users u ON u.id = l.user_id LEFT JOIN repayments r ON r.loan_id = l.id AND r.status = 'approved' $lsWhere ORDER BY COALESCE(r.created_at, l.created_at) DESC LIMIT 200";
$lsStmt  = $pdo->prepare($lsSql);
$lsStmt->execute($lsParams);
$lsRows = $lsStmt->fetchAll();

// ─── Simulator ────────────────────────────────────────────────────────────────
function simulateOutstandingAt(PDO $pdo, int $loanId, string $atDate): array {
    $loan = $pdo->prepare("SELECT * FROM loans WHERE id = ?");
    $loan->execute([$loanId]); $l = $loan->fetch();
    if (!$l) return ['error' => 'Loan not found'];
    $sett = $pdo->query("SELECT interest_rate, late_fee_rate, term_days FROM settings WHERE id = 1")->fetch();
    $lateRate = (float)$sett['late_fee_rate'];
    $termDays = (int)$sett['term_days'];

    $disbursedAt = strtotime($l['disbursed_at']);
    $dueAt       = strtotime($l['due_at']);
    $simAt       = strtotime($atDate);
    $overdueDays = max(0, (int)(($simAt - $dueAt) / 86400));

    $totalRepaid = (float)$pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE loan_id = ? AND status = 'approved' AND approved_at <= ?")->execute([$loanId, $atDate . ' 23:59:59']) ? 0 : 0;
    // Actually recalc
    $rp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM repayments WHERE loan_id = ? AND status = 'approved' AND approved_at <= ?");
    $rp->execute([$loanId, $atDate . ' 23:59:59']);
    $totalRepaid = (float)$rp->fetchColumn();

    $principalOwed = max(0, (float)$l['amount'] - $totalRepaid);
    $lateFeeAccrued = $principalOwed * ($lateRate / 100) * $overdueDays;

    return [
        'loan_id'           => $loanId,
        'disbursed'         => $l['disbursed_at'],
        'due_at'            => $l['due_at'],
        'original_amount'   => (float)$l['amount'],
        'total_interest'    => (float)$l['interest_flat'],
        'total_repaid'      => $totalRepaid,
        'principal_remaining' => $principalOwed,
        'overdue_days'      => $overdueDays,
        'late_fees_accrued' => $lateFeeAccrued,
        'total_outstanding' => $principalOwed + max(0, (float)$l['interest_flat'] - (float)$l['interest_paid']) + $lateFeeAccrued,
    ];
}

$simLoanId = (int)($_GET['sim_loan_id'] ?? 0);
$simDate   = $_GET['sim_date'] ?? date('Y-m-d');
$simResult = $simLoanId > 0 ? simulateOutstandingAt($pdo, $simLoanId, $simDate) : null;

// ─── Audit Feed ───────────────────────────────────────────────────────────────
$auditLog = $pdo->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id ORDER BY a.created_at DESC LIMIT 50")->fetchAll();

// ─── My Activity ──────────────────────────────────────────────────────────────
$myAct = $pdo->prepare("SELECT * FROM audit_logs WHERE actor_id = ? ORDER BY created_at DESC LIMIT 100");
$myAct->execute([$me]); $myActRows = $myAct->fetchAll();

// ─── Users for dropdowns ──────────────────────────────────────────────────────
$userOptions = $pdo->query("SELECT id, full_name, email FROM users ORDER BY full_name ASC")->fetchAll();

// ─── Tab State ─────────────────────────────────────────────────────────────────
$validTabs = ['overview','users','proposals','approvals','statements','simulator','settings','dividends','audit','myactivity'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $validTabs, true)) $tab = 'overview';

// ─── Flash ────────────────────────────────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('app_name') ?> — Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>tailwind.config={theme:{extend:{colors:{dark:{bg:'#0b1220',panel:'#121a2b',border:'#1e2a45',hover:'#1a2540'},primary:{DEFAULT:'#2ba7ff',hover:'#1e8fe0',light:'#3db4ff'}},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
    <style>
        :root,[data-theme="dark"]{--bg:#0b1220;--panel:#121a2b;--border:#1e2a45;--hover:#1a2540;--text:#e2e8f0;--text-sec:#94a3b8;--text-muted:#64748b}
        [data-theme="light"]{--bg:#f1f5f9;--panel:#ffffff;--border:#e2e8f0;--hover:#f8fafc;--text:#1e293b;--text-sec:#475569;--text-muted:#94a3b8}
        *{scrollbar-width:thin;scrollbar-color:var(--border) transparent}
        body{background-color:var(--bg);font-family:'Inter',sans-serif;color:var(--text)}
        .panel{background-color:var(--panel);border:1px solid var(--border)}
        .input-field{background-color:var(--bg);border:1px solid var(--border);color:var(--text)}
        .input-field:focus{border-color:#2ba7ff;outline:none;box-shadow:0 0 0 3px rgba(43,167,255,0.15)}
        .sidebar{width:260px;height:100vh;position:fixed;top:0;left:0;z-index:40;transition:transform .3s}
        .main-content{margin-left:260px;transition:margin-left .3s}
        .nav-item{display:flex;align-items:center;gap:12px;padding:10px 16px;border-radius:10px;cursor:pointer;transition:all .2s;color:var(--text-sec);font-size:14px}
        .nav-item:hover{background:var(--hover);color:var(--text)}
        .nav-item.active{background:rgba(43,167,255,0.12);color:#2ba7ff;font-weight:600}
        .badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;font-size:11px;font-weight:600}
        .stat-card{background:linear-gradient(135deg,var(--panel) 0%,var(--hover) 100%);border:1px solid var(--border);border-radius:14px;padding:20px;transition:transform .2s,box-shadow .2s}
        .stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(0,0,0,0.2)}
        .toast{position:fixed;top:20px;right:20px;z-index:999;padding:14px 20px;border-radius:12px;font-size:14px;font-weight:500;transform:translateX(120%);transition:transform .4s cubic-bezier(.68,-.55,.27,1.55);box-shadow:0 8px 32px rgba(0,0,0,0.3)}
        .toast.show{transform:translateX(0)}.toast-success{background:#065f46;color:#d1fae5;border:1px solid #059669}
        .toast-error{background:#7f1d1d;color:#fecaca;border:1px solid #dc2626}
        .tab-content{display:none}.tab-content.active{display:block}
        .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:100;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
        .modal-overlay.show{display:flex}
        .modal-box{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:24px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,0.4)}
        @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}}
        .empty-state{text-align:center;padding:40px 20px}.empty-state i{font-size:48px;color:var(--text-muted);opacity:.3;margin-bottom:16px}
        .filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;padding:16px;border-radius:12px;background:var(--hover);border:1px solid var(--border);margin-bottom:16px}
        .filter-bar label{font-size:12px;font-weight:500;color:var(--text-sec);display:block;margin-bottom:4px}
        .pagination{display:flex;gap:6px;align-items:center;justify-content:center;margin-top:16px}
        .pagination a,.pagination span{padding:6px 14px;border-radius:8px;font-size:13px;transition:all .15s}
        .pagination a{background:var(--hover);color:var(--text-sec);text-decoration:none}.pagination a:hover{background:var(--border);color:var(--text)}
        .pagination .active{background:#2ba7ff;color:#fff}
        .sub-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;color:var(--text-sec);background:transparent}
        .sub-tab:hover{background:var(--hover);color:var(--text)}
        .sub-tab.active{background:rgba(43,167,255,0.12);color:#2ba7ff}
        .sim-card{background:var(--hover);border:1px solid var(--border);border-radius:12px;padding:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
        .sim-card .item{text-align:center}.sim-card .item .val{font-size:20px;font-weight:700;color:var(--text)}.sim-card .item .lbl{font-size:11px;color:var(--text-muted);margin-top:2px}
        .guardrail-pass{color:#34d399}.guardrail-fail{color:#f87171}
    </style>
    <script>
        (function(){const t=localStorage.getItem('theme')||'<?= $theme ?>';document.documentElement.setAttribute('data-theme',t)})();
        function toggleTheme(){const h=document.documentElement,n=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',n);localStorage.setItem('theme',n);document.querySelector('#theme-icon').className=n==='dark'?'fas fa-sun':'fas fa-moon'}
        function showToast(type,msg){const t=document.getElementById('toast');t.className='toast toast-'+type;t.innerHTML=(type==='success'?'<i class="fas fa-check-circle mr-2"></i>':'<i class="fas fa-exclamation-circle mr-2"></i>')+msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),4000)}
        function switchTab(tab){document.querySelectorAll('.tab-content').forEach(el=>el.classList.remove('active'));document.getElementById('tab-'+tab).classList.add('active');document.querySelectorAll('.tab-btn').forEach(el=>el.classList.remove('active','bg-primary/10','text-primary'));const btn=document.querySelector('[data-tab="'+tab+'"]');if(btn){btn.classList.add('active','bg-primary/10','text-primary')}const url=new URL(window.location);url.searchParams.set('tab',tab);history.replaceState({},'',url)}
        document.addEventListener('submit',function(e){const f=e.target;let inp=f.querySelector('input[name="tab"]');if(!inp){inp=document.createElement('input');inp.type='hidden';inp.name='tab';f.appendChild(inp)}const at=document.querySelector('.tab-content.active');if(at){inp.value=at.id.replace('tab-','')}})
        function confirmAction(formId){document.getElementById('confirm-modal').classList.add('show');document.getElementById('confirm-yes').onclick=function(){document.getElementById('confirm-modal').classList.remove('show');document.getElementById(formId).submit()}}
        function searchTable(inputId,tableId){const input=document.getElementById(inputId),filter=input.value.toUpperCase(),table=document.getElementById(tableId),rows=table.getElementsByTagName('tr');for(let i=1;i<rows.length;i++){const cells=rows[i].getElementsByTagName('td'),found=Array.from(cells).some(c=>c.textContent.toUpperCase().includes(filter));rows[i].style.display=found?'':'none'}}
        function switchSubTab(group,tab){document.querySelectorAll('['+group+']').forEach(el=>el.classList.remove('active'));const el=document.querySelector('['+group+'="'+tab+'"]');if(el)el.classList.add('active');document.querySelectorAll('.'+group+'-content').forEach(el=>el.classList.remove('active'));const ct=document.getElementById(group+'-'+tab);if(ct)ct.classList.add('active')}
        function showLoading(formId){document.getElementById(formId+'-spinner').classList.remove('hidden');return true}
        <?php if ($flash): ?>
        document.addEventListener('DOMContentLoaded',function(){showToast('<?= $flash['type'] ?>','<?= str_replace("'","\\'",$flash['message']) ?>')});
        <?php endif; ?>
    </script>
</head>
<body>
<div id="toast"></div>
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-30 hidden" onclick="document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.add('hidden')"></div>

<aside id="sidebar" class="sidebar panel border-r" style="border-color:var(--border)">
    <div class="flex items-center gap-3 px-6 h-16 border-b" style="border-color:var(--border)">
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary to-blue-600 flex items-center justify-center"><i class="fas fa-crown text-white text-sm"></i></div>
        <span class="font-bold text-lg" style="color:var(--text)"><?= t('app_name') ?></span>
        <span class="text-xs px-2 py-0.5 rounded-full bg-primary/10 text-primary font-medium ml-auto">Admin</span>
    </div>
    <nav class="p-4 space-y-1">
        <div class="nav-item tab-btn <?= $tab==='overview'?'active':'' ?>" data-tab="overview" onclick="switchTab('overview')"><i class="fas fa-chart-pie w-5 text-center"></i>Overview</div>
        <div class="nav-item tab-btn <?= $tab==='users'?'active':'' ?>" data-tab="users" onclick="switchTab('users')"><i class="fas fa-users w-5 text-center"></i>Users <?php if(count($pendingUsers)): ?><span class="badge text-white text-xs" style="background:#eab308"><?= count($pendingUsers) ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='proposals'?'active':'' ?>" data-tab="proposals" onclick="switchTab('proposals')"><i class="fas fa-file-invoice w-5 text-center"></i>Proposals <?php if(count($proposals)): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= count($proposals) ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='approvals'?'active':'' ?>" data-tab="approvals" onclick="switchTab('approvals')"><i class="fas fa-check-double w-5 text-center"></i>Approvals <?php if($dashPendingAll): ?><span class="badge text-white text-xs" style="background:#eab308"><?= $dashPendingAll ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='statements'?'active':'' ?>" data-tab="statements" onclick="switchTab('statements')"><i class="fas fa-file-alt w-5 text-center"></i>Statements</div>
        <div class="nav-item tab-btn <?= $tab==='simulator'?'active':'' ?>" data-tab="simulator" onclick="switchTab('simulator')"><i class="fas fa-calculator w-5 text-center"></i>Simulator</div>
        <div class="nav-item tab-btn <?= $tab==='settings'?'active':'' ?>" data-tab="settings" onclick="switchTab('settings')"><i class="fas fa-sliders-h w-5 text-center"></i>Settings</div>
        <div class="nav-item tab-btn <?= $tab==='dividends'?'active':'' ?>" data-tab="dividends" onclick="switchTab('dividends')"><i class="fas fa-gift w-5 text-center"></i>Dividends</div>
        <div class="nav-item tab-btn <?= $tab==='audit'?'active':'' ?>" data-tab="audit" onclick="switchTab('audit')"><i class="fas fa-history w-5 text-center"></i>Audit</div>
        <div class="nav-item tab-btn <?= $tab==='myactivity'?'active':'' ?>" data-tab="myactivity" onclick="switchTab('myactivity')"><i class="fas fa-user-clock w-5 text-center"></i>My Activity</div>
    </nav>
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t" style="border-color:var(--border)">
        <div class="flex items-center gap-3 px-3 py-2 rounded-lg" style="background:var(--hover)">
            <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate" style="color:var(--text)"><?= htmlspecialchars($_SESSION['full_name']) ?></p>
                <p class="text-xs" style="color:var(--text-muted)">Super Admin</p>
            </div>
            <button onclick="toggleTheme()" class="text-sm" style="color:var(--text-sec)"><i id="theme-icon" class="fas <?= $theme==='dark'?'fa-sun':'fa-moon' ?>"></i></button>
            <a href="logout.php" class="text-sm" style="color:var(--text-sec)" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</aside>

<div class="main-content min-h-screen">
    <header class="h-16 flex items-center justify-between px-6 border-b" style="background:var(--panel);border-color:var(--border)">
        <button class="md:hidden text-lg" style="color:var(--text-sec)" onclick="document.getElementById('sidebar').classList.toggle('open');document.getElementById('sidebar-overlay').classList.toggle('hidden')">
            <i class="fas fa-bars"></i>
        </button>
        <div class="flex items-center gap-4 ml-auto">
            <span class="text-sm" style="color:var(--text-sec)"><?= date('l, M j, Y') ?></span>
        </div>
    </header>

    <div class="p-6">

        <!-- ════════════════════════ OVERVIEW ════════════════════════ -->
        <div id="tab-overview" class="tab-content <?= $tab==='overview'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Dashboard</h1><p class="text-sm mt-1" style="color:var(--text-sec)">System overview and key metrics</p></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                <div class="stat-card"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)">Total Users</span><div class="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center"><i class="fas fa-users text-blue-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $totalUsers ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><span class="text-yellow-400"><?= $dashPendingAll ?></span> pending actions</p></div>
                <div class="stat-card"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)">Total Savings</span><div class="w-10 h-10 rounded-lg bg-green-500/10 flex items-center justify-center"><i class="fas fa-piggy-bank text-green-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= number_format($dashTotalSavings, 0) ?> RWF</p></div>
                <div class="stat-card"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)">Active Loans</span><div class="w-10 h-10 rounded-lg bg-yellow-500/10 flex items-center justify-center"><i class="fas fa-hand-holding-usd text-yellow-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $dashActiveLoans ?></p></div>
                <div class="stat-card"><div class="flex items-center justify-between mb-3"><span class="text-sm font-medium" style="color:var(--text-sec)">Approved Shares</span><div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center"><i class="fas fa-chart-pie text-purple-400"></i></div></div><p class="text-3xl font-bold" style="color:var(--text)"><?= number_format($dashTotalShares) ?></p></div>
            </div>
            <div class="panel rounded-xl p-6"><h2 class="text-lg font-semibold mb-4" style="color:var(--text)">Quick Actions</h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <button onclick="switchTab('users')" class="panel p-4 rounded-xl hover:bg-dark-hover transition-all text-center"><i class="fas fa-user-check text-2xl text-green-400 mb-2"></i><p class="text-sm font-medium" style="color:var(--text)">Manage Users</p></button>
                    <button onclick="switchTab('approvals')" class="panel p-4 rounded-xl hover:bg-dark-hover transition-all text-center"><i class="fas fa-check-circle text-2xl text-primary mb-2"></i><p class="text-sm font-medium" style="color:var(--text)">Review Approvals</p></button>
                    <button onclick="switchTab('proposals')" class="panel p-4 rounded-xl hover:bg-dark-hover transition-all text-center"><i class="fas fa-file-invoice text-2xl text-yellow-400 mb-2"></i><p class="text-sm font-medium" style="color:var(--text)">Savings Proposals</p></button>
                    <button onclick="switchTab('settings')" class="panel p-4 rounded-xl hover:bg-dark-hover transition-all text-center"><i class="fas fa-cog text-2xl text-purple-400 mb-2"></i><p class="text-sm font-medium" style="color:var(--text)">System Settings</p></button>
                </div>
            </div>
        </div>

        <!-- ════════════════════════ USERS ════════════════════════ -->
        <div id="tab-users" class="tab-content <?= $tab==='users'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">User Management</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Manage members, reviewers, and admins</p></div>
                <div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="text" id="userSearch" onkeyup="searchTable('userSearch','usersTable')" placeholder="Search users..." class="input-field pl-9 pr-4 py-2 rounded-lg text-sm w-48 lg:w-64"></div>
            </div>
            <div class="panel rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" id="usersTable">
                        <thead><tr style="background:var(--hover)">
                            <th class="text-left py-3.5 px-4 font-semibold" style="color:var(--text-sec)">ID</th>
                            <th class="text-left py-3.5 px-4 font-semibold" style="color:var(--text-sec)">Name</th>
                            <th class="text-left py-3.5 px-4 font-semibold hidden md:table-cell" style="color:var(--text-sec)">Email</th>
                            <th class="text-left py-3.5 px-4 font-semibold hidden lg:table-cell" style="color:var(--text-sec)">Phone</th>
                            <th class="text-left py-3.5 px-4 font-semibold hidden lg:table-cell" style="color:var(--text-sec)">DOB</th>
                            <th class="text-left py-3.5 px-4 font-semibold" style="color:var(--text-sec)">Role</th>
                            <th class="text-center py-3.5 px-4 font-semibold" style="color:var(--text-sec)">Status</th>
                            <th class="text-center py-3.5 px-4 font-semibold" style="color:var(--text-sec)">Actions</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($allUsers as $user): ?>
                            <tr class="border-t" style="border-color:var(--border);transition:background .15s" onmouseover="this.style.background='var(--hover)'" onmouseout="this.style.background=''">
                                <td class="py-3.5 px-4" style="color:var(--text-muted)">#<?= $user['id'] ?></td>
                                <td class="py-3.5 px-4"><div class="flex items-center gap-3"><div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xs"><?= strtoupper(substr($user['full_name'],0,1)) ?></div><span class="font-medium" style="color:var(--text)"><?= htmlspecialchars($user['full_name']) ?></span></div></td>
                                <td class="py-3.5 px-4 hidden md:table-cell" style="color:var(--text-sec)"><?= htmlspecialchars($user['email']) ?></td>
                                <td class="py-3.5 px-4 hidden lg:table-cell" style="color:var(--text-sec)"><?= htmlspecialchars($user['phone'] ?? '-') ?></td>
                                <td class="py-3.5 px-4 hidden lg:table-cell" style="color:var(--text-sec)"><?= $user['date_of_birth'] ?></td>
                                <td class="py-3.5 px-4">
                                    <form method="POST" class="flex items-center gap-2">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="update_role"><input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <select name="role" onchange="this.form.submit()" class="text-xs rounded-lg px-2.5 py-1.5 border" style="background:var(--bg);border-color:var(--border);color:var(--text)">
                                            <option value="user" <?= $user['role']==='user'?'selected':'' ?>>User</option>
                                            <option value="reviewer" <?= $user['role']==='reviewer'?'selected':'' ?>>Reviewer</option>
                                            <option value="super_admin" <?= $user['role']==='super_admin'?'selected':'' ?>>Admin</option>
                                        </select>
                                    </form>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <?php
                                    $s=$user['status'];
                                    $sc=match($s){'approved'=>'bg-green-500/10 text-green-400','rejected'=>'bg-red-500/10 text-red-400','inactive'=>'bg-gray-500/10 text-gray-400',default=>'bg-yellow-500/10 text-yellow-400'};
                                    $si=match($s){'approved'=>'fa-check-circle','rejected'=>'fa-times-circle','inactive'=>'fa-pause-circle',default=>'fa-clock'};
                                    ?>
                                    <span class="inline-flex items-center gap-1.5 text-xs px-3 py-1 rounded-full font-medium <?= $sc ?>"><i class="fas <?= $si ?>"></i><?= $s ?></span>
                                </td>
                                <td class="py-3.5 px-4 text-center">
                                    <?php if ($user['status']==='pending'): ?>
                                    <div class="flex gap-2 justify-center">
                                        <form method="POST" id="approve-<?= $user['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><input type="hidden" name="user_status" value="approved"><button type="button" onclick="confirmAction('approve-<?= $user['id'] ?>')" class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium"><i class="fas fa-check mr-1"></i>Approve</button></form>
                                        <form method="POST" id="reject-<?= $user['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><input type="hidden" name="user_status" value="rejected"><button type="button" onclick="confirmAction('reject-<?= $user['id'] ?>')" class="text-xs px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg transition-colors font-medium"><i class="fas fa-times mr-1"></i>Reject</button></form>
                                    </div>
                                    <?php elseif ($user['status']==='approved'): ?>
                                    <form method="POST" id="deact-<?= $user['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><input type="hidden" name="user_status" value="inactive"><button type="button" onclick="confirmAction('deact-<?= $user['id'] ?>')" class="text-xs px-3 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors font-medium"><i class="fas fa-pause mr-1"></i>Deactivate</button></form>
                                    <?php elseif ($user['status']==='inactive'): ?>
                                    <form method="POST" id="react-<?= $user['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="user_id" value="<?= $user['id'] ?>"><input type="hidden" name="user_status" value="approved"><button type="button" onclick="confirmAction('react-<?= $user['id'] ?>')" class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors font-medium"><i class="fas fa-play mr-1"></i>Reactivate</button></form>
                                    <?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Pagination -->
            <div class="pagination">
                <a href="?up=1">&laquo;</a>
                <a href="?up=<?= max(1, $up-1) ?>">&lsaquo;</a>
                <?php for ($i = max(1, $up-2); $i <= min($totalPages, $up+2); $i++): ?>
                    <?php if ($i === $up): ?><span class="active"><?= $i ?></span><?php else: ?><a href="?up=<?= $i ?>"><?= $i ?></a><?php endif; ?>
                <?php endfor; ?>
                <a href="?up=<?= min($totalPages, $up+1) ?>">&rsaquo;</a>
                <a href="?up=<?= $totalPages ?>">&raquo;</a>
                <span class="text-xs" style="color:var(--text-muted)">Page <?= $up ?> of <?= $totalPages ?> (<?= $totalUsers ?> users)</span>
            </div>
        </div>

        <!-- ════════════════════════ PROPOSALS ════════════════════════ -->
        <div id="tab-proposals" class="tab-content <?= $tab==='proposals'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Verified Savings Proposals</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Review proposed balance changes from verified savings audits</p></div>
            </div>
            <?php if (count($proposals) > 0): ?>
            <div class="panel rounded-xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead><tr style="background:var(--hover)">
                        <th class="text-left py-3 px-4 font-semibold" style="color:var(--text-sec)">Member</th>
                        <th class="text-right py-3 px-4 font-semibold" style="color:var(--text-sec)">Current Balance</th>
                        <th class="text-right py-3 px-4 font-semibold" style="color:var(--text-sec)">Proposed Balance</th>
                        <th class="text-right py-3 px-4 font-semibold" style="color:var(--text-sec)">Difference</th>
                        <th class="text-center py-3 px-4 font-semibold" style="color:var(--text-sec)">Actions</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($proposals as $p): $diff = (float)$p['proposed_balance'] - (float)$p['current_balance']; ?>
                        <tr class="border-t" style="border-color:var(--border)">
                            <td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($p['full_name']) ?></span><br><span class="text-xs" style="color:var(--text-muted)"><?= htmlspecialchars($p['email']) ?></span></td>
                            <td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$p['current_balance'], 2) ?></td>
                            <td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$p['proposed_balance'], 2) ?></td>
                            <td class="py-3 px-4 text-right font-medium <?= $diff >= 0 ? 'text-green-400' : 'text-red-400' ?>"><?= $diff >= 0 ? '+' : '' ?><?= number_format($diff, 2) ?></td>
                            <td class="py-3 px-4 text-center">
                                <div class="flex gap-2 justify-center">
                                    <form method="POST" id="pa-<?= $p['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="proposal_action"><input type="hidden" name="vs_id" value="<?= $p['id'] ?>"><input type="hidden" name="proposal_action" value="approve"><button type="button" onclick="confirmAction('pa-<?= $p['id'] ?>')" class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg"><i class="fas fa-check mr-1"></i>Approve</button></form>
                                    <form method="POST" id="pr-<?= $p['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="proposal_action"><input type="hidden" name="vs_id" value="<?= $p['id'] ?>"><input type="hidden" name="proposal_action" value="reject"><button type="button" onclick="confirmAction('pr-<?= $p['id'] ?>')" class="text-xs px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg"><i class="fas fa-times mr-1"></i>Reject</button></form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="panel rounded-xl p-12 text-center"><i class="fas fa-check-circle text-5xl mb-4" style="color:var(--text-muted);opacity:.3"></i><p style="color:var(--text-muted)">No pending proposals. All verified savings are in sync.</p></div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════ APPROVALS ════════════════════════ -->
        <div id="tab-approvals" class="tab-content <?= $tab==='approvals'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Final Approval Queue</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Reviewer-approved items awaiting your final sign-off with guardrails</p></div>
            </div>
            <!-- Sub-tabs -->
            <div class="flex flex-wrap gap-2 mb-5">
                <span class="sub-tab active" data-approval="savings" onclick="switchSubTab('data-approval','savings')"><i class="fas fa-piggy-bank"></i>Savings <?php if (count($finalSavings)): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= count($finalSavings) ?></span><?php endif; ?></span>
                <span class="sub-tab" data-approval="loans" onclick="switchSubTab('data-approval','loans')"><i class="fas fa-hand-holding-usd"></i>Loans <?php if (count($finalLoans)): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= count($finalLoans) ?></span><?php endif; ?></span>
                <span class="sub-tab" data-approval="shares" onclick="switchSubTab('data-approval','shares')"><i class="fas fa-chart-pie"></i>Shares <?php if (count($finalShares)): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= count($finalShares) ?></span><?php endif; ?></span>
                <span class="sub-tab" data-approval="repayments" onclick="switchSubTab('data-approval','repayments')"><i class="fas fa-credit-card"></i>Repayments <?php if (count($finalRepayments)): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= count($finalRepayments) ?></span><?php endif; ?></span>
                <span class="sub-tab" data-approval="suggestions" onclick="switchSubTab('data-approval','suggestions')"><i class="fas fa-lightbulb"></i>Suggestions <?php if (count($finalSuggestions)): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= count($finalSuggestions) ?></span><?php endif; ?></span>
            </div>

            <!-- Savings Queue -->
            <div id="approval-savings" class="data-approval-content approval-content active">
                <div class="panel rounded-xl overflow-hidden">
                    <table class="w-full text-sm">
                        <thead><tr style="background:var(--hover)">
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Member</th>
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Type</th>
                            <th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Amount</th>
                            <th class="text-left py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Ref #</th>
                            <th class="text-center py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Receipt</th>
                            <th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Guardrail</th>
                            <th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th>
                        </tr></thead>
                        <tbody>
                            <?php if (count($finalSavings) > 0): foreach ($finalSavings as $row):
                                $canApprove = true; $guardMsg = '';
                                if ($row['type'] === 'withdrawal') {
                                    $bal = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='deposit' AND status='approved' THEN amount ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN type='withdrawal' AND status='approved' THEN amount ELSE 0 END), 0) as bal FROM savings WHERE user_id = ?");
                                    $bal->execute([$row['user_id']]);
                                    $totalBal = (float)$bal->fetch()['bal'];
                                    if ((float)$row['amount'] > $totalBal) { $canApprove = false; $guardMsg = 'Insufficient balance (' . number_format($totalBal, 2) . ' RWF)'; }
                                }
                            ?>
                            <tr class="border-t" style="border-color:var(--border)">
                                <td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span></td>
                                <td class="py-3 px-4"><span class="text-xs px-2 py-1 rounded-full font-medium <?= $row['type']==='deposit'?'bg-green-500/10 text-green-400':'bg-red-500/10 text-red-400' ?>"><?= $row['type'] ?></span></td>
                                <td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['amount'], 0) ?> RWF</td>
                                <td class="py-3 px-4 hidden lg:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td>
                                <td class="py-3 px-4 text-center hidden lg:table-cell"><?php if (!empty($row['proof_file'])): ?><a href="uploads/<?= htmlspecialchars($row['proof_file']) ?>" target="_blank" class="text-xs text-primary hover:underline"><i class="fas fa-file"></i></a><?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?></td>
                                <td class="py-3 px-4 text-center"><?php if ($guardMsg): ?><span class="text-xs text-red-400"><i class="fas fa-exclamation-triangle mr-1"></i><?= $guardMsg ?></span><?php else: ?><span class="text-xs text-green-400"><i class="fas fa-check-circle"></i> OK</span><?php endif; ?></td>
                                <td class="py-3 px-4 text-center">
                                    <?php if ($canApprove): ?>
                                    <form method="POST" id="fa-s-<?= $row['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="final_approve"><input type="hidden" name="final_type" value="savings"><input type="hidden" name="final_id" value="<?= $row['id'] ?>"><button type="button" onclick="confirmAction('fa-s-<?= $row['id'] ?>')" class="text-xs px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium"><i class="fas fa-check mr-1"></i>Approve</button></form>
                                    <?php else: ?><span class="text-xs" style="color:var(--text-muted)">Blocked</span><?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="7" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)">No pending savings</p></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Loans Queue -->
            <div id="approval-loans" class="data-approval-content approval-content">
                <div class="panel rounded-xl overflow-hidden">
                    <table class="w-full text-sm">
                        <thead><tr style="background:var(--hover)">
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Member</th>
                            <th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Amount</th>
                            <th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Guardrails</th>
                            <th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th>
                        </tr></thead>
                        <tbody>
                            <?php if (count($finalLoans) > 0): foreach ($finalLoans as $row):
                                $canApprove = true; $guards = [];
                                $vs = $pdo->prepare("SELECT COALESCE(current_balance, 0) FROM verified_savings WHERE user_id = ?");
                                $vs->execute([$row['user_id']]); $savBal = (float)$vs->fetchColumn();
                                $maxLoan = $savBal * 1.5;
                                if ((float)$row['amount'] > $maxLoan) { $canApprove = false; $guards[] = 'Exceeds 1.5x savings'; }
                                $act = $pdo->prepare("SELECT COUNT(*) FROM loans WHERE user_id = ? AND status = 'approved_disbursed'");
                                $act->execute([$row['user_id']]);
                                if ((int)$act->fetchColumn() > 0) { $canApprove = false; $guards[] = 'Has active loan'; }
                                $sh = $pdo->prepare("SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = ? AND status = 'approved'");
                                $sh->execute([$row['user_id']]);
                                $minS = (int)$settings['min_shares_to_borrow'];
                                if ((int)$sh->fetchColumn() < $minS) { $canApprove = false; $guards[] = 'Needs ' . $minS . '+ shares'; }
                            ?>
                            <tr class="border-t" style="border-color:var(--border)">
                                <td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span></td>
                                <td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['amount'], 0) ?> RWF</td>
                                <td class="py-3 px-4 text-center"><?php if ($guards): foreach ($guards as $g): ?><span class="text-xs text-red-400 block"><i class="fas fa-times-circle mr-1"></i><?= $g ?></span><?php endforeach; else: ?><span class="text-xs text-green-400"><i class="fas fa-check-circle"></i> All passed</span><?php endif; ?></td>
                                <td class="py-3 px-4 text-center"><?php if ($canApprove): ?><form method="POST" id="fa-l-<?= $row['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="final_approve"><input type="hidden" name="final_type" value="loan"><input type="hidden" name="final_id" value="<?= $row['id'] ?>"><button type="button" onclick="confirmAction('fa-l-<?= $row['id'] ?>')" class="text-xs px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium"><i class="fas fa-check mr-1"></i>Disburse</button></form><?php else: ?><span class="text-xs" style="color:var(--text-muted)">Blocked</span><?php endif; ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)">No pending loans</p></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Shares Queue -->
            <div id="approval-shares" class="data-approval-content approval-content">
                <div class="panel rounded-xl overflow-hidden"><table class="w-full text-sm"><thead><tr style="background:var(--hover)"><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Member</th><th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Shares</th><th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Total</th><th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th></tr></thead><tbody>
                    <?php if (count($finalShares) > 0): foreach ($finalShares as $row): ?>
                    <tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span></td><td class="py-3 px-4 text-right" style="color:var(--text)"><?= $row['share_count'] ?></td><td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['total_amount'], 0) ?> RWF</td><td class="py-3 px-4 text-center"><form method="POST" id="fa-sh-<?= $row['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="final_approve"><input type="hidden" name="final_type" value="share"><input type="hidden" name="final_id" value="<?= $row['id'] ?>"><button type="button" onclick="confirmAction('fa-sh-<?= $row['id'] ?>')" class="text-xs px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium"><i class="fas fa-check mr-1"></i>Approve</button></form></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)">No pending shares</p></td></tr><?php endif; ?>
                </tbody></table></div>
            </div>

            <!-- Repayments Queue -->
            <div id="approval-repayments" class="data-approval-content approval-content">
                <div class="panel rounded-xl overflow-hidden"><table class="w-full text-sm"><thead><tr style="background:var(--hover)"><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Member</th><th class="text-left py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Ref #</th><th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Loan</th><th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Payment</th><th class="text-center py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Receipt</th><th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Overdue</th><th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th></tr></thead><tbody>
                    <?php if (count($finalRepayments) > 0): foreach ($finalRepayments as $row):
                        $od = $row['due_at'] ? max(0, (int)((time() - strtotime($row['due_at'])) / 86400)) : 0;
                    ?>
                    <tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span></td><td class="py-3 px-4 hidden lg:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td><td class="py-3 px-4 text-right" style="color:var(--text)"><?= number_format((float)$row['loan_amount'], 0) ?> RWF</td><td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['amount'], 0) ?> RWF</td><td class="py-3 px-4 text-center hidden lg:table-cell"><?php if (!empty($row['proof_file'])): ?><a href="uploads/<?= htmlspecialchars($row['proof_file']) ?>" target="_blank" class="text-xs text-primary hover:underline"><i class="fas fa-file"></i></a><?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?></td><td class="py-3 px-4 text-right <?= $od > 0 ? 'text-red-400' : 'text-green-400' ?>"><?= $od > 0 ? $od . ' days' : 'On time' ?></td><td class="py-3 px-4 text-center"><form method="POST" id="fa-r-<?= $row['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="final_approve"><input type="hidden" name="final_type" value="repayment"><input type="hidden" name="final_id" value="<?= $row['id'] ?>"><button type="button" onclick="confirmAction('fa-r-<?= $row['id'] ?>')" class="text-xs px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium"><i class="fas fa-check mr-1"></i>Process</button></form></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="7" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)">No pending repayments</p></td></tr><?php endif; ?>
                </tbody></table></div>
            </div>

            <!-- Suggestions Queue -->
            <div id="approval-suggestions" class="data-approval-content approval-content">
                <div class="panel rounded-xl overflow-hidden"><table class="w-full text-sm"><thead><tr style="background:var(--hover)"><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Member</th><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Title</th><th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)">Actions</th></tr></thead><tbody>
                    <?php if (count($finalSuggestions) > 0): foreach ($finalSuggestions as $row): ?>
                    <tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span></td><td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['title']) ?></span><br><span class="text-xs" style="color:var(--text-muted)"><?= htmlspecialchars(substr($row['description'], 0, 200)) ?></span></td><td class="py-3 px-4 text-center"><div class="flex gap-2 justify-center">
                        <form method="POST" id="fa-su-<?= $row['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="final_approve"><input type="hidden" name="final_type" value="suggestion"><input type="hidden" name="final_id" value="<?= $row['id'] ?>"><input type="hidden" name="suggestion_status" value="approved"><button type="button" onclick="confirmAction('fa-su-<?= $row['id'] ?>')" class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg"><i class="fas fa-check mr-1"></i>Approve</button></form>
                        <form method="POST" id="fa-sr-<?= $row['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="final_approve"><input type="hidden" name="final_type" value="suggestion"><input type="hidden" name="final_id" value="<?= $row['id'] ?>"><input type="hidden" name="suggestion_status" value="rejected"><button type="button" onclick="confirmAction('fa-sr-<?= $row['id'] ?>')" class="text-xs px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg"><i class="fas fa-times mr-1"></i>Reject</button></form>
                    </div></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="3" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)">No pending suggestions</p></td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </div>

        <!-- ════════════════════════ STATEMENTS ════════════════════════ -->
        <div id="tab-statements" class="tab-content <?= $tab==='statements'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Savings Statements</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Filter approved transactions by date range, user, or keyword</p></div>
            </div>

            <!-- Filter Bar -->
            <form method="GET" class="filter-bar">
                <input type="hidden" name="st_from" value="<?= $stFrom ?>">
                <div>
                    <label>From</label>
                    <input type="date" name="st_from" value="<?= $stFrom ?>" class="input-field px-3 py-2 rounded-lg text-sm">
                </div>
                <div>
                    <label>To</label>
                    <input type="date" name="st_to" value="<?= $stTo ?>" class="input-field px-3 py-2 rounded-lg text-sm">
                </div>
                <div>
                    <label>User</label>
                    <select name="st_user_id" class="input-field px-3 py-2 rounded-lg text-sm">
                        <option value="0">All Users</option>
                        <?php foreach ($userOptions as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $stUserId === (int)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Search</label>
                    <input type="text" name="st_search" value="<?= htmlspecialchars($stSearch) ?>" placeholder="Name or reference..." class="input-field px-3 py-2 rounded-lg text-sm">
                </div>
                <div>
                    <button type="submit" class="bg-primary hover:bg-primary-hover text-white px-4 py-2 rounded-lg text-sm font-medium"><i class="fas fa-filter mr-1"></i>Apply</button>
                    <a href="admin.php" class="text-xs ml-2" style="color:var(--text-muted)">Clear</a>
                </div>
            </form>

            <div class="panel rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr style="background:var(--hover)">
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Date</th>
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Member</th>
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Type</th>
                            <th class="text-right py-3 px-4 font-medium" style="color:var(--text-sec)">Amount</th>
                            <th class="text-left py-3 px-4 font-medium hidden md:table-cell" style="color:var(--text-sec)">Reference</th>
                        </tr></thead>
                        <tbody>
                            <?php if (count($stmtRows) > 0): foreach ($stmtRows as $row): ?>
                            <tr class="border-t" style="border-color:var(--border)">
                                <td class="py-3 px-4" style="color:var(--text-sec)"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                                <td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span></td>
                                <td class="py-3 px-4"><span class="text-xs px-2 py-1 rounded font-medium <?= $row['type']==='deposit'?'bg-green-500/10 text-green-400':'bg-red-500/10 text-red-400' ?>"><?= $row['type'] === 'deposit' ? '+' : '-' ?> <?= $row['type'] ?></span></td>
                                <td class="py-3 px-4 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['amount'], 2) ?> RWF</td>
                                <td class="py-3 px-4 hidden md:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="5" class="empty-state"><i class="fas fa-search"></i><p style="color:var(--text-muted)">No transactions found for the selected filters</p></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ════════════════════════ SIMULATOR ════════════════════════ -->
        <div id="tab-simulator" class="tab-content <?= $tab==='simulator'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Loan Simulator</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Calculate outstanding balance including late fees at any point in time</p></div>
            </div>
            <div class="panel rounded-xl p-6 mb-6">
                <form method="GET" class="flex flex-wrap gap-4 items-end">
                    <div><label class="block text-sm font-medium mb-1" style="color:var(--text-sec)">Loan ID</label>
                        <select name="sim_loan_id" class="input-field px-3 py-2 rounded-lg text-sm min-w-[200px]">
                            <option value="0">Select a loan...</option>
                            <?php
                            $simLoans = $pdo->query("SELECT l.id, u.full_name, l.amount FROM loans l JOIN users u ON u.id = l.user_id WHERE l.status IN ('approved_disbursed','closed') ORDER BY l.created_at DESC LIMIT 50")->fetchAll();
                            foreach ($simLoans as $sl): ?>
                            <option value="<?= $sl['id'] ?>" <?= $simLoanId === (int)$sl['id'] ? 'selected' : '' ?>>#<?= $sl['id'] ?> — <?= htmlspecialchars($sl['full_name']) ?> (<?= number_format((float)$sl['amount'], 0) ?> RWF)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label class="block text-sm font-medium mb-1" style="color:var(--text-sec)">As of Date</label><input type="date" name="sim_date" value="<?= $simDate ?>" class="input-field px-3 py-2 rounded-lg text-sm"></div>
                    <div><button type="submit" class="bg-primary hover:bg-primary-hover text-white px-5 py-2 rounded-lg text-sm font-medium"><i class="fas fa-calculator mr-1"></i>Simulate</button></div>
                </form>
            </div>

            <?php if ($simResult && !isset($simResult['error'])): ?>
            <div class="sim-card mb-6">
                <div class="item"><div class="val" style="color:var(--text)"><?= number_format($simResult['original_amount'], 0) ?></div><div class="lbl">Original Amount (RWF)</div></div>
                <div class="item"><div class="val" style="color:var(--text)"><?= number_format($simResult['total_repaid'], 0) ?></div><div class="lbl">Total Repaid (RWF)</div></div>
                <div class="item"><div class="val" style="color:#2ba7ff"><?= number_format($simResult['principal_remaining'], 0) ?></div><div class="lbl">Principal Remaining (RWF)</div></div>
                <div class="item"><div class="val <?= $simResult['overdue_days'] > 0 ? 'text-red-400' : 'text-green-400' ?>"><?= $simResult['overdue_days'] ?> days</div><div class="lbl">Overdue</div></div>
                <div class="item"><div class="val <?= $simResult['late_fees_accrued'] > 0 ? 'text-red-400' : 'text-green-400' ?>"><?= number_format($simResult['late_fees_accrued'], 2) ?></div><div class="lbl">Late Fees Accrued (RWF)</div></div>
                <div class="item"><div class="val" style="color:#f59e0b"><?= number_format($simResult['total_outstanding'], 2) ?></div><div class="lbl">Total Outstanding (RWF)</div></div>
            </div>
            <div class="panel rounded-xl p-4">
                <div class="flex items-center gap-2 text-sm"><i class="fas fa-info-circle text-primary"></i><span style="color:var(--text-sec)">Disbursed: <?= $simResult['disbursed'] ?> | Due: <?= $simResult['due_at'] ?> | Interest (flat): <?= number_format($simResult['total_interest'], 2) ?> RWF</span></div>
            </div>
            <?php elseif ($simResult && isset($simResult['error'])): ?>
            <div class="panel rounded-xl p-6 text-center"><p class="text-red-400"><?= $simResult['error'] ?></p></div>
            <?php endif; ?>
        </div>

        <!-- ════════════════════════ SETTINGS ════════════════════════ -->
        <div id="tab-settings" class="tab-content <?= $tab==='settings'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">System Settings</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Configure interest rates, fees, and share parameters</p></div>
            </div>
            <div class="panel rounded-xl p-6 max-w-3xl">
                <form method="POST">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update_settings">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div><label class="block text-sm font-medium mb-1.5" style="color:var(--text-sec)">Interest Rate (%)</label><div class="relative"><i class="fas fa-percentage absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="number" step="0.01" name="interest_rate" value="<?= $settings['interest_rate'] ?>" class="input-field w-full pl-9 pr-4 py-2.5 rounded-lg text-sm"></div></div>
                        <div><label class="block text-sm font-medium mb-1.5" style="color:var(--text-sec)">Late Fee Rate (%)</label><div class="relative"><i class="fas fa-exclamation-triangle absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="number" step="0.01" name="late_fee_rate" value="<?= $settings['late_fee_rate'] ?>" class="input-field w-full pl-9 pr-4 py-2.5 rounded-lg text-sm"></div></div>
                        <div><label class="block text-sm font-medium mb-1.5" style="color:var(--text-sec)">Loan Term (Days)</label><div class="relative"><i class="fas fa-calendar-day absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="number" name="term_days" value="<?= $settings['term_days'] ?>" class="input-field w-full pl-9 pr-4 py-2.5 rounded-lg text-sm"></div></div>
                        <div><label class="block text-sm font-medium mb-1.5" style="color:var(--text-sec)">Share Price (RWF)</label><div class="relative"><i class="fas fa-coins absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="number" step="0.01" name="share_price" value="<?= $settings['share_price'] ?>" class="input-field w-full pl-9 pr-4 py-2.5 rounded-lg text-sm"></div></div>
                        <div><label class="block text-sm font-medium mb-1.5" style="color:var(--text-sec)">Min Shares to Borrow</label><div class="relative"><i class="fas fa-chart-bar absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="number" name="min_shares_to_borrow" value="<?= $settings['min_shares_to_borrow'] ?>" class="input-field w-full pl-9 pr-4 py-2.5 rounded-lg text-sm"></div></div>
                        <div class="flex items-end"><button type="submit" class="w-full bg-primary hover:bg-primary-hover text-white font-medium py-2.5 rounded-lg transition-colors text-sm flex items-center justify-center gap-2"><i class="fas fa-save"></i> Save Settings</button></div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ════════════════════════ DIVIDENDS ════════════════════════ -->
        <div id="tab-dividends" class="tab-content <?= $tab==='dividends'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Dividend Distribution</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Calculate and distribute dividends to all shareholders</p></div>
            </div>
            <div class="panel rounded-xl p-6 max-w-lg">
                <div class="flex items-center gap-3 mb-5 p-3 rounded-lg" style="background:rgba(251,191,36,0.08);border:1px solid rgba(251,191,36,0.2)"><i class="fas fa-info-circle text-yellow-400 text-lg"></i><p class="text-sm" style="color:var(--text-sec)">Dividends are distributed proportionally based on each member's share count.</p></div>
                <form method="POST">
                    <?= csrf_field() ?><input type="hidden" name="action" value="distribute_dividends">
                    <div class="space-y-4">
                        <div><label class="block text-sm font-medium mb-1.5" style="color:var(--text-sec)">Net Profit (RWF)</label><div class="relative"><i class="fas fa-money-bill-wave absolute left-3 top-1/2 -translate-y-1/2 text-sm" style="color:var(--text-muted)"></i><input type="number" step="0.01" min="1" name="net_profit" placeholder="Enter total net profit" required class="input-field w-full pl-9 pr-4 py-2.5 rounded-lg text-sm"></div></div>
                        <button type="submit" class="w-full bg-gradient-to-r from-yellow-600 to-yellow-500 hover:from-yellow-700 hover:to-yellow-600 text-white font-medium py-2.5 rounded-lg transition-all text-sm flex items-center justify-center gap-2 shadow-lg shadow-yellow-600/20"><i class="fas fa-gift"></i> Distribute Dividends</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ════════════════════════ AUDIT ════════════════════════ -->
        <div id="tab-audit" class="tab-content <?= $tab==='audit'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)">Audit & Activity Log</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Latest 50 system-wide activities and automated alerts</p></div>
            </div>

            <!-- Export Cards Grid -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mb-6">
                <?php
                $exportTypes = [
                    ['type'=>'audit','label'=>'Audit Log','icon'=>'fa-history','color'=>'text-blue-400'],
                    ['type'=>'users','label'=>'Users','icon'=>'fa-users','color'=>'text-green-400'],
                    ['type'=>'savings','label'=>'Savings','icon'=>'fa-piggy-bank','color'=>'text-yellow-400'],
                    ['type'=>'loans','label'=>'Loans','icon'=>'fa-hand-holding-usd','color'=>'text-orange-400'],
                    ['type'=>'shares','label'=>'Shares','icon'=>'fa-chart-pie','color'=>'text-purple-400'],
                    ['type'=>'repayments','label'=>'Repayments','icon'=>'fa-credit-card','color'=>'text-blue-400'],
                    ['type'=>'verified_savings','label'=>'Verified Savings','icon'=>'fa-file-invoice','color'=>'text-teal-400'],
                    ['type'=>'suggestions','label'=>'Suggestions','icon'=>'fa-lightbulb','color'=>'text-yellow-400'],
                ];
                foreach ($exportTypes as $ex): ?>
                <div class="panel rounded-lg p-3 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <i class="fas <?= $ex['icon'] ?> <?= $ex['color'] ?>"></i>
                        <span class="text-xs font-medium" style="color:var(--text)"><?= $ex['label'] ?></span>
                    </div>
                    <div class="flex gap-1">
                        <a href="export.php?type=<?= $ex['type'] ?>" class="text-xs bg-primary hover:bg-primary-hover text-white px-2 py-1 rounded" title="Download CSV"><i class="fas fa-file-csv"></i></a>
                        <a href="export_pdf.php?type=<?= $ex['type'] ?>" class="text-xs bg-red-600 hover:bg-red-700 text-white px-2 py-1 rounded" title="Download PDF"><i class="fas fa-file-pdf"></i></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="panel rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr style="background:var(--hover)">
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Date/Time</th>
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Actor</th>
                            <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th>
                            <th class="text-left py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Details</th>
                            <th class="text-left py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">IP</th>
                        </tr></thead>
                        <tbody>
                            <?php if (count($auditLog) > 0): foreach ($auditLog as $log): ?>
                            <tr class="border-t" style="border-color:var(--border)">
                                <td class="py-3 px-4" style="color:var(--text-sec)"><?= date('d M Y H:i', strtotime($log['created_at'])) ?></td>
                                <td class="py-3 px-4"><span style="color:var(--text)"><?= htmlspecialchars($log['full_name'] ?? 'System') ?></span></td>
                                <td class="py-3 px-4" colspan="2" style="color:var(--text);max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars(audit_description($log['action'], $log['details'] ?? null)) ?>"><?= htmlspecialchars(audit_description($log['action'], $log['details'] ?? null)) ?></td>
                                <td class="py-3 px-4 hidden lg:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="5" class="empty-state"><i class="fas fa-history"></i><p style="color:var(--text-muted)">No audit records yet</p></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ MY ACTIVITY ═══════════════════ -->
        <div id="tab-myactivity" class="tab-content <?= $tab==='myactivity'?'active':'' ?>">
            <div class="flex items-center justify-between mb-6">
                <div><h1 class="text-2xl font-bold" style="color:var(--text)"><i class="fas fa-user-clock mr-2" style="color:var(--text-sec)"></i>My Activity</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Actions performed by you on the platform</p></div>
            </div>
            <div class="panel rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Date</th><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th><th class="text-left py-3 px-4 font-medium hidden lg:table-cell" style="color:var(--text-sec)">IP</th></tr></thead>
                        <tbody><?php if (count($myActRows) > 0): foreach ($myActRows as $r): ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-4" style="color:var(--text-sec)"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td class="py-3 px-4" style="color:var(--text)"><?= htmlspecialchars(audit_description($r['action'], $r['details'])) ?></td><td class="py-3 px-4 hidden lg:table-cell" style="color:var(--text-muted);font-family:monospace;font-size:0.75rem"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td></tr><?php endforeach; else: ?><tr><td colspan="3" class="empty-state"><i class="fas fa-history"></i><p style="color:var(--text-muted)">No activity recorded yet</p></td></tr><?php endif; ?></tbody>
                    </table>
                </div>
            </div>
        </div>

    </div><!-- /p-6 -->
</div><!-- /main-content -->

<!-- Confirmation Modal -->
<div id="confirm-modal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal-box">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-yellow-500/20 flex items-center justify-center"><i class="fas fa-exclamation-triangle text-yellow-400"></i></div>
            <div><h3 class="font-semibold" style="color:var(--text)">Confirm Action</h3><p class="text-sm" style="color:var(--text-sec)">Are you sure you want to proceed?</p></div>
        </div>
        <div class="flex gap-3 justify-end mt-6">
            <button onclick="document.getElementById('confirm-modal').classList.remove('show')" class="px-4 py-2 rounded-lg text-sm font-medium" style="background:var(--hover);color:var(--text-sec)">Cancel</button>
            <button id="confirm-yes" class="px-4 py-2 rounded-lg text-sm font-medium bg-green-600 hover:bg-green-700 text-white">Confirm</button>
        </div>
    </div>
</div>
</body>
</html>
