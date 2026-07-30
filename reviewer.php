<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_role('reviewer', 'super_admin');

$pdo = db();
$me  = (int)$_SESSION['user_id'];

// ─── Handle Review Actions ────────────────────────────────────────────────
if (is_post()) {
    verify_csrf();
    $action = $_POST['review_action'] ?? '';
    $type   = $_POST['review_type'] ?? '';
    $id     = (int)($_POST['review_id'] ?? 0);
    $notes  = trim($_POST['reviewer_notes'] ?? '');
    $rejectReason = trim($_POST['rejection_reason'] ?? '');
    $msg    = null;

    try {
        $pdo->beginTransaction();
        $newStatus = $action === 'approve' ? 'reviewer_approved' : 'rejected';
        $tableMap  = ['savings' => 'savings', 'loan' => 'loans', 'share' => 'shares', 'repayment' => 'repayments', 'suggestion' => 'suggestions'];
        $table     = $tableMap[$type] ?? null;
        if (!$table) throw new Exception(t('error'));

        $fetchStmt = $pdo->prepare("SELECT user_id FROM `$table` WHERE id = ? AND status = 'pending'");
        $fetchStmt->execute([$id]);
        $targetRow = $fetchStmt->fetch();
        if (!$targetRow) throw new Exception('Record not found or already processed.');
        $targetUserId = (int)$targetRow['user_id'];

        if ($action === 'reject') {
            $reason = $rejectReason ?: ($notes ?: null);
            $stmt = $pdo->prepare("UPDATE `$table` SET status = 'rejected', reviewer_notes = ?, reviewed_by = ?, rejection_reason = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$reason, $me, $reason, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE `$table` SET status = 'reviewer_approved', reviewer_notes = ?, reviewed_by = ? WHERE id = ? AND status = 'pending'");
            $stmt->execute([$notes ?: null, $notes ? $me : null, $id]);
        }

        $actionLabel = $action === 'approve' ? 'approved by reviewer' : 'rejected';
        $msgSuffix = $action === 'reject' && $rejectReason ? ' (Reason: ' . $rejectReason . ')' : ($notes ? ' (Notes: ' . $notes . ')' : '');
        notify($targetUserId, 'Your ' . $type . ' request has been ' . $actionLabel . '.' . $msgSuffix);

        audit_log($notes ? 'review_action_with_notes' : 'review_action', ['type' => $type, 'id' => $id, 'action' => $action, 'has_notes' => (bool)$notes, 'rejection_reason' => $rejectReason ?: null]);

        $pdo->commit();
        $msg = ['success', 'Review action completed.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = ['error', $e->getMessage()];
    }
    if ($msg) { $_SESSION['flash'] = ['type' => $msg[0], 'message' => $msg[1]]; }
    $rtab = $_POST['tab'] ?? $_GET['tab'] ?? '';
    redirect('reviewer.php' . ($rtab ? '?tab=' . urlencode($rtab) : ''));
}

// ─── Fetch Pending Items ──────────────────────────────────────────────────
$pendingSavings    = $pdo->query("SELECT s.*, u.full_name, u.email, u.phone, u.date_of_birth, u.created_at as user_since FROM savings s JOIN users u ON u.id = s.user_id WHERE s.status = 'pending' ORDER BY s.created_at DESC")->fetchAll();
$pendingLoans      = $pdo->query("SELECT l.*, u.full_name, u.email, u.phone, u.date_of_birth, u.created_at as user_since FROM loans l JOIN users u ON u.id = l.user_id WHERE l.status = 'pending' ORDER BY l.created_at DESC")->fetchAll();
$pendingShares     = $pdo->query("SELECT s.*, u.full_name, u.email, u.phone, u.date_of_birth, u.created_at as user_since FROM shares s JOIN users u ON u.id = s.user_id WHERE s.status = 'pending' ORDER BY s.created_at DESC")->fetchAll();
$pendingRepayments = $pdo->query("SELECT r.*, u.full_name, u.email, u.phone, u.date_of_birth, u.created_at as user_since, l.amount as loan_amount, l.principal_outstanding, l.interest_flat, l.interest_paid, l.late_fees, l.due_at, l.status as loan_status FROM repayments r JOIN users u ON u.id = r.user_id LEFT JOIN loans l ON l.id = r.loan_id WHERE r.status = 'pending' ORDER BY r.created_at DESC")->fetchAll();
$pendingSuggestions = $pdo->query("SELECT s.*, u.full_name, u.email FROM suggestions s JOIN users u ON u.id = s.user_id WHERE s.status = 'pending' ORDER BY s.created_at DESC")->fetchAll();

// ─── Member Profiles Cache (for inspection modal) ─────────────────────────
$memberProfiles = $pdo->query("SELECT u.id, u.full_name, u.email, u.phone, u.date_of_birth, u.created_at as user_since, COALESCE(vs.current_balance, 0) as verified_balance, (SELECT COALESCE(SUM(share_count), 0) FROM shares WHERE user_id = u.id AND status IN ('approved','reviewer_approved')) as total_shares, (SELECT COUNT(*) FROM loans WHERE user_id = u.id AND status = 'approved_disbursed') as active_loans FROM users u LEFT JOIN verified_savings vs ON vs.user_id = u.id")->fetchAll();
$profileMap = [];
foreach ($memberProfiles as $mp) {
    $profileMap[(int)$mp['id']] = $mp;
}

// ─── Dashboard Stats ──────────────────────────────────────────────────────
$dashSavings    = count($pendingSavings);
$dashLoans      = count($pendingLoans);
$dashShares     = count($pendingShares);
$dashRepayments = count($pendingRepayments);
$dashSuggestions = count($pendingSuggestions);
$dashTotal      = $dashSavings + $dashLoans + $dashShares + $dashRepayments + $dashSuggestions;

// ─── Recent activity ──────────────────────────────────────────────────────
$recentActions = $pdo->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE a.action LIKE 'review_action%' ORDER BY a.created_at DESC LIMIT 10")->fetchAll();

// ─── My Activity ────────────────────────────────────────────────────────────
$myActivity = $pdo->prepare("SELECT * FROM audit_logs WHERE actor_id = ? ORDER BY created_at DESC LIMIT 100");
$myActivity->execute([$me]); $myActivityRows = $myActivity->fetchAll();

// ─── Duplicate Check ──────────────────────────────────────────────────────
function getDuplicateCount(PDO $pdo, string $table, int $userId, string $since = '-24 hours'): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE user_id = ? AND created_at >= ? AND status = 'pending'");
    $st->execute([$userId, date('Y-m-d H:i:s', strtotime($since))]);
    return (int)$st->fetchColumn();
}

// ─── Tab State ─────────────────────────────────────────────────────────────
$validTabs = ['dashboard','savings','loans','shares','repayments','suggestions','activity'];
$tab = $_GET['tab'] ?? 'dashboard';
if (!in_array($tab, $validTabs, true)) $tab = 'dashboard';

// ─── Flash ─────────────────────────────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ─── Settings Cache ───────────────────────────────────────────────────────
$settings = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch();
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('app_name') ?> — <?= t('review_queue') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>tailwind.config={theme:{extend:{colors:{dark:{bg:'#0b1220',panel:'#121a2b',border:'#1e2a45',hover:'#1a2540'},primary:{DEFAULT:'#2ba7ff',hover:'#1e8fe0',light:'#3db4ff'},risk:{low:'#34d399',medium:'#fbbf24',high:'#f87171'}},fontFamily:{sans:['Inter','sans-serif']}}}}</script>
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
        .modal-box{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:24px;max-width:520px;width:90%;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.4)}
        @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}}
        .empty-state{text-align:center;padding:40px 20px}.empty-state i{font-size:48px;color:var(--text-muted);opacity:.3;margin-bottom:16px}
        .filter-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;padding:16px;border-radius:12px;background:var(--hover);border:1px solid var(--border);margin-bottom:16px}
        .filter-bar label{font-size:12px;font-weight:500;color:var(--text-sec);display:block;margin-bottom:4px}
        .risk-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:600}
        .duplicate-alert{background:rgba(251,191,36,0.1);border:1px solid rgba(251,191,36,0.25);border-radius:8px;padding:6px 12px;font-size:12px;color:#fbbf24;display:inline-flex;align-items:center;gap:6px}
        .sub-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;cursor:pointer;transition:all .15s;color:var(--text-sec);background:transparent}
        .sub-tab:hover{background:var(--hover);color:var(--text)}.sub-tab.active{background:rgba(43,167,255,0.12);color:#2ba7ff}
    </style>
    <script>
        (function(){const t=localStorage.getItem('theme')||'<?= $theme ?>';document.documentElement.setAttribute('data-theme',t)})();
        function toggleTheme(){const h=document.documentElement,n=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',n);localStorage.setItem('theme',n);document.querySelector('#theme-icon').className=n==='dark'?'fas fa-sun':'fas fa-moon'}
        function showToast(type,msg){const t=document.getElementById('toast');t.className='toast toast-'+type;t.innerHTML=(type==='success'?'<i class="fas fa-check-circle mr-2"></i>':'<i class="fas fa-exclamation-circle mr-2"></i>')+msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),4000)}
        function switchTab(tab){document.querySelectorAll('.tab-content').forEach(el=>el.classList.remove('active'));document.getElementById('tab-'+tab).classList.add('active');document.querySelectorAll('.tab-btn').forEach(el=>el.classList.remove('active'));const btn=document.querySelector('[data-tab="'+tab+'"]');if(btn)btn.classList.add('active');const url=new URL(window.location);url.searchParams.set('tab',tab);history.replaceState({},'',url)}
        document.addEventListener('submit',function(e){const f=e.target;let inp=f.querySelector('input[name="tab"]');if(!inp){inp=document.createElement('input');inp.type='hidden';inp.name='tab';f.appendChild(inp)}const at=document.querySelector('.tab-content.active');if(at){inp.value=at.id.replace('tab-','')}})
        function switchSubTab(group,tab){document.querySelectorAll('['+group+']').forEach(el=>el.classList.remove('active'));const el=document.querySelector('['+group+'="'+tab+'"]');if(el)el.classList.add('active');document.querySelectorAll('.'+group+'-content').forEach(el=>el.classList.remove('active'));const ct=document.getElementById(group+'-'+tab);if(ct)ct.classList.add('active')}
        function filterTable(inputId,tableId){const input=document.getElementById(inputId),filter=input.value.toUpperCase(),table=document.getElementById(tableId),rows=table.getElementsByTagName('tr');for(let i=1;i<rows.length;i++){const cells=rows[i].getElementsByTagName('td'),found=Array.from(cells).some(c=>c.textContent.toUpperCase().includes(filter));rows[i].style.display=found?'':'none'}}
        function openInspect(userId){const m=<?= json_encode($profileMap) ?>;const p=m[userId];if(!p){document.getElementById('inspect-body').innerHTML='<p style="color:var(--text-muted)">Profile not found.</p>';document.getElementById('inspect-modal').classList.add('show');return}
        document.getElementById('inspect-modal').classList.add('show');
        document.getElementById('inspect-body').innerHTML=
        '<div class="grid grid-cols-2 gap-4 mb-4">'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('full_name') ?></div><div class="font-medium" style="color:var(--text)">'+p.full_name+'</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('email') ?></div><div class="font-medium text-sm" style="color:var(--text)">'+p.email+'</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('phone') ?></div><div class="font-medium" style="color:var(--text)">'+(p.phone||'-')+'</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('member_since') ?></div><div class="font-medium text-sm" style="color:var(--text)">'+p.user_since.slice(0,10)+'</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('verified_balance') ?></div><div class="font-medium text-green-400">'+Number(p.verified_balance).toLocaleString()+' RWF</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('share_holdings') ?></div><div class="font-medium" style="color:var(--text)">'+p.total_shares+' shares</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('active_loans_count') ?></div><div class="font-medium '+(p.active_loans>0?'text-red-400':'text-green-400')+'">'+(p.active_loans>0?p.active_loans+' active':'None')+'</div></div>'+
        '<div class="panel rounded-lg p-3"><div class="text-xs" style="color:var(--text-muted)"><?= t('date_of_birth') ?></div><div class="font-medium" style="color:var(--text)">'+p.date_of_birth+'</div></div>'+
        '</div>';}
        function showReviewModal(formId,type,id,action){document.getElementById('review-form-id').value=id;document.getElementById('review-form-type').value=type;document.getElementById('review-form-action').value=action;document.getElementById('review-action-label').textContent=action==='approve'?'<?= t('approve') ?>':'<?= t('reject') ?>';const rr=document.getElementById('rejection-reason-group');if(rr)rr.style.display=action==='reject'?'block':'none';document.getElementById('review-modal').classList.add('show');}
        function submitReview(){const f=document.getElementById('review-form');document.getElementById('review-loader').classList.remove('hidden');f.submit();}
        function openProof(url){document.getElementById('proof-frame').src=url;document.getElementById('proof-modal').classList.add('show')}
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
        <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-primary to-blue-600 flex items-center justify-center"><i class="fas fa-shield-alt text-white text-sm"></i></div>
        <span class="font-bold text-lg" style="color:var(--text)"><?= t('app_name') ?></span>
        <span class="text-xs px-2 py-0.5 rounded-full bg-yellow-500/10 text-yellow-400 font-medium ml-auto"><?= t('pending') ?></span>
    </div>
    <nav class="p-4 space-y-1">
        <div class="nav-item tab-btn <?= $tab==='dashboard'?'active':'' ?>" data-tab="dashboard" onclick="switchTab('dashboard')"><i class="fas fa-chart-pie w-5 text-center"></i><?= t('overview') ?></div>
        <div class="nav-item tab-btn <?= $tab==='savings'?'active':'' ?>" data-tab="savings" onclick="switchTab('savings')"><i class="fas fa-piggy-bank w-5 text-center"></i><?= t('savings') ?> <?php if($dashSavings): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= $dashSavings ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='loans'?'active':'' ?>" data-tab="loans" onclick="switchTab('loans')"><i class="fas fa-hand-holding-usd w-5 text-center"></i><?= t('loans') ?> <?php if($dashLoans): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= $dashLoans ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='shares'?'active':'' ?>" data-tab="shares" onclick="switchTab('shares')"><i class="fas fa-chart-pie w-5 text-center"></i><?= t('shares') ?> <?php if($dashShares): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= $dashShares ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='repayments'?'active':'' ?>" data-tab="repayments" onclick="switchTab('repayments')"><i class="fas fa-credit-card w-5 text-center"></i><?= t('repayments') ?> <?php if($dashRepayments): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= $dashRepayments ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='suggestions'?'active':'' ?>" data-tab="suggestions" onclick="switchTab('suggestions')"><i class="fas fa-lightbulb w-5 text-center"></i><?= t('suggestions') ?> <?php if($dashSuggestions): ?><span class="badge text-white text-xs" style="background:#2ba7ff"><?= $dashSuggestions ?></span><?php endif; ?></div>
        <div class="nav-item tab-btn <?= $tab==='activity'?'active':'' ?>" data-tab="activity" onclick="switchTab('activity')"><i class="fas fa-history w-5 text-center"></i>Activity</div>
    </nav>
    <div class="absolute bottom-0 left-0 right-0 p-4 border-t" style="border-color:var(--border)">
        <div class="flex items-center gap-3 px-3 py-2 rounded-lg" style="background:var(--hover)">
            <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center text-primary font-bold text-sm"><?= strtoupper(substr($_SESSION['full_name'],0,1)) ?></div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate" style="color:var(--text)"><?= htmlspecialchars($_SESSION['full_name']) ?></p>
                <p class="text-xs" style="color:var(--text-muted)">Reviewer</p>
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
            <span class="text-sm" style="color:var(--text-sec)"><?= date('l, M j, Y') ?></span>
        </div>
    </header>

    <div class="p-6">

    <!-- ═══════════════════ DASHBOARD ═══════════════════ -->
    <div id="tab-dashboard" class="tab-content <?= $tab==='dashboard'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('overview') ?></h1><p class="text-sm mt-1" style="color:var(--text-sec)"><?= t('review_queue') ?></p></div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
            <div class="stat-card text-center"><div class="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center mx-auto mb-3"><i class="fas fa-piggy-bank text-blue-400"></i></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $dashSavings ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('savings') ?></p></div>
            <div class="stat-card text-center"><div class="w-10 h-10 rounded-lg bg-yellow-500/10 flex items-center justify-center mx-auto mb-3"><i class="fas fa-hand-holding-usd text-yellow-400"></i></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $dashLoans ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('loans') ?></p></div>
            <div class="stat-card text-center"><div class="w-10 h-10 rounded-lg bg-purple-500/10 flex items-center justify-center mx-auto mb-3"><i class="fas fa-chart-pie text-purple-400"></i></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $dashShares ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('shares') ?></p></div>
            <div class="stat-card text-center"><div class="w-10 h-10 rounded-lg bg-green-500/10 flex items-center justify-center mx-auto mb-3"><i class="fas fa-credit-card text-green-400"></i></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $dashRepayments ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('repayments') ?></p></div>
            <div class="stat-card text-center"><div class="w-10 h-10 rounded-lg bg-orange-500/10 flex items-center justify-center mx-auto mb-3"><i class="fas fa-lightbulb text-orange-400"></i></div><p class="text-3xl font-bold" style="color:var(--text)"><?= $dashSuggestions ?></p><p class="text-xs mt-1" style="color:var(--text-muted)"><?= t('suggestions') ?></p></div>
        </div>

        <div class="panel rounded-xl p-6">
            <h2 class="text-lg font-semibold mb-4" style="color:var(--text)"><?= t('recent_activity') ?></h2>
            <?php if (count($recentActions) > 0): ?>
            <div class="space-y-3">
                <?php foreach ($recentActions as $ra): $raDetails = json_decode($ra['details'] ?? '{}', true); ?>
                <div class="flex items-center gap-3 p-3 rounded-lg" style="background:var(--hover)">
                    <div class="w-8 h-8 rounded-full <?= ($raDetails['action']??'')==='approve'?'bg-green-500/10':'bg-red-500/10' ?> flex items-center justify-center"><i class="fas <?= ($raDetails['action']??'')==='approve'?'fa-check text-green-400':'fa-times text-red-400' ?>"></i></div>
                    <div class="flex-1"><p class="text-sm" style="color:var(--text)"><strong><?= htmlspecialchars($ra['full_name'] ?? 'System') ?></strong> <?= ($raDetails['action']??'')==='approve'?'approved':'rejected' ?> <strong><?= $raDetails['type'] ?? '?' ?></strong> #<?= $raDetails['id'] ?? '?' ?></p><p class="text-xs" style="color:var(--text-muted)"><?= date('d M Y H:i', strtotime($ra['created_at'])) ?></p></div>
                    <?php if (!empty($raDetails['has_notes'])): ?><span class="text-xs px-2 py-1 rounded-full bg-primary/10 text-primary"><i class="fas fa-sticky-note mr-1"></i><?= t('notes') ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state"><i class="fas fa-history"></i><p style="color:var(--text-muted)"><?= t('no_records') ?></p></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════ SAVINGS ═══════════════════ -->
    <div id="tab-savings" class="tab-content <?= $tab==='savings'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('savings') ?> <span class="text-base font-normal" style="color:var(--text-muted)">— <?= t('pending') ?></span></h1></div>
            <div class="flex gap-2">
                <a href="export.php?type=savings" class="text-xs bg-primary hover:bg-primary-hover text-white px-3 py-1.5 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= t('export_csv') ?></a>
            </div>
        </div>
        <div class="filter-bar">
            <div><label><?= t('search') ?></label><input type="text" id="savings-search" onkeyup="filterTable('savings-search','savings-table')" placeholder="<?= t('search') ?>..." class="input-field px-3 py-2 rounded-lg text-sm"></div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="savings-table">
                    <thead><tr style="background:var(--hover)">
                        <th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('full_name') ?></th>
                        <th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('type') ?></th>
                        <th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('amount') ?></th>
                        <th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)">Ref #</th>
                        <th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)"><?= t('date') ?></th>
                        <th class="text-center py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Receipt</th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('risk_profile') ?></th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('actions') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (count($pendingSavings) > 0): foreach ($pendingSavings as $row):
                            $uid = (int)$row['user_id'];
                            $prof = $profileMap[$uid] ?? null;
                            $vsBal = $prof ? (float)$prof['verified_balance'] : 0;
                            $amt = (float)$row['amount'];
                            $risk = 'low'; $riskLabel = t('risk_low');
                            $dupCount = getDuplicateCount($pdo, 'savings', $uid);
                            if ($row['type'] === 'withdrawal' && $vsBal > 0) {
                                $ratio = $amt / $vsBal;
                                if ($ratio > 0.9) { $risk = 'high'; $riskLabel = t('risk_high'); }
                                elseif ($ratio > 0.5) { $risk = 'medium'; $riskLabel = t('risk_medium'); }
                            }
                        ?>
                        <tr class="border-t" style="border-color:var(--border)">
                            <td class="py-3 px-3">
                                <span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span>
                                <?php if ($dupCount >= 3): ?><div class="duplicate-alert mt-1"><i class="fas fa-exclamation-triangle"></i><?= $dupCount ?> <?= t('items_24h') ?></div><?php endif; ?>
                            </td>
                            <td class="py-3 px-3"><span class="text-xs px-2 py-1 rounded font-medium <?= $row['type']==='deposit'?'bg-green-500/10 text-green-400':'bg-red-500/10 text-red-400' ?>"><?= $row['type']==='deposit'?t('deposit'):t('withdrawal') ?></span></td>
                            <td class="py-3 px-3 text-right font-medium" style="color:var(--text)"><?= number_format($amt, 2) ?> RWF</td>
                            <td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td>
                            <td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-sec)"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                            <td class="py-3 px-3 text-center hidden lg:table-cell"><?php if (!empty($row['proof_file'])): ?><button onclick="openProof('uploads/<?= htmlspecialchars($row['proof_file']) ?>')" class="text-xs text-primary hover:underline bg-transparent border-0"><i class="fas fa-file"></i> View</button><?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?></td>
                            <td class="py-3 px-3 text-center">
                                <span class="risk-badge" style="background:<?= $risk==='high'?'rgba(248,113,113,0.15)':($risk==='medium'?'rgba(251,191,36,0.15)':'rgba(52,211,153,0.15)') ?>;color:<?= $risk==='high'?'var(--risk-high)':($risk==='medium'?'var(--risk-medium)':'var(--risk-low)') ?>">
                                    <i class="fas fa-<?= $risk==='high'?'exclamation-circle':($risk==='medium'?'exclamation-triangle':'check-circle') ?>"></i><?= $riskLabel ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-center">
                                <button onclick="openInspect(<?= $uid ?>)" class="text-xs px-2.5 py-1.5 rounded-lg border font-medium transition-all" style="border-color:var(--border);color:var(--text-sec);background:var(--hover)"><i class="fas fa-search mr-1"></i><?= t('inspect') ?></button>
                                <button onclick="showReviewModal('review-form','savings',<?= $row['id'] ?>,'approve')" class="text-xs px-2.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg ml-1"><i class="fas fa-check mr-1"></i><?= t('approve') ?></button>
                                <button onclick="showReviewModal('review-form','savings',<?= $row['id'] ?>,'reject')" class="text-xs px-2.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg ml-1"><i class="fas fa-times mr-1"></i><?= t('reject') ?></button>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="8" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_pending_items') ?></p></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ LOANS ═══════════════════ -->
    <div id="tab-loans" class="tab-content <?= $tab==='loans'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('loans') ?> <span class="text-base font-normal" style="color:var(--text-muted)">— <?= t('pending') ?></span></h1></div>
            <div class="flex gap-2">
                <a href="export.php?type=loans" class="text-xs bg-primary hover:bg-primary-hover text-white px-3 py-1.5 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= t('export_csv') ?></a>
            </div>
        </div>
        <div class="filter-bar">
            <div><label><?= t('search') ?></label><input type="text" id="loans-search" onkeyup="filterTable('loans-search','loans-table')" placeholder="<?= t('search') ?>..." class="input-field px-3 py-2 rounded-lg text-sm"></div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="loans-table">
                    <thead><tr style="background:var(--hover)">
                        <th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('full_name') ?></th>
                        <th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('amount') ?></th>
                        <th class="text-center py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)"><?= t('verified_balance') ?></th>
                        <th class="text-center py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)">1.5x Cap</th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('risk_profile') ?></th>
                        <th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)"><?= t('date') ?></th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('actions') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (count($pendingLoans) > 0): foreach ($pendingLoans as $row):
                            $uid = (int)$row['user_id'];
                            $prof = $profileMap[$uid] ?? null;
                            $vsBal = $prof ? (float)$prof['verified_balance'] : 0;
                            $maxLoan = $vsBal * 1.5;
                            $loanAmt = (float)$row['amount'];
                            $risk = 'low'; $riskLabel = t('risk_low');
                            $dupCount = getDuplicateCount($pdo, 'loans', $uid);
                            if ($vsBal > 0) {
                                $ratio = $loanAmt / $vsBal;
                                if ($ratio > 1.5) { $risk = 'high'; $riskLabel = t('risk_high'); }
                                elseif ($ratio > 1.2) { $risk = 'medium'; $riskLabel = t('risk_medium'); }
                            }
                        ?>
                        <tr class="border-t" style="border-color:var(--border)">
                            <td class="py-3 px-3">
                                <span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span>
                                <?php if ($dupCount >= 3): ?><div class="duplicate-alert mt-1"><i class="fas fa-exclamation-triangle"></i><?= $dupCount ?> <?= t('items_24h') ?></div><?php endif; ?>
                            </td>
                            <td class="py-3 px-3 text-right font-medium" style="color:var(--text)"><?= number_format($loanAmt, 2) ?> RWF</td>
                            <td class="py-3 px-3 text-center hidden lg:table-cell" style="color:var(--text-sec)"><?= number_format($vsBal, 2) ?> RWF</td>
                            <td class="py-3 px-3 text-center hidden lg:table-cell">
                                <?php if ($vsBal > 0): ?>
                                <span class="<?= $loanAmt > $maxLoan ? 'text-red-400' : 'text-green-400' ?>"><?= number_format($maxLoan, 2) ?> RWF</span>
                                <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                            </td>
                            <td class="py-3 px-3 text-center">
                                <span class="risk-badge" style="background:<?= $risk==='high'?'rgba(248,113,113,0.15)':($risk==='medium'?'rgba(251,191,36,0.15)':'rgba(52,211,153,0.15)') ?>;color:<?= $risk==='high'?'var(--risk-high)':($risk==='medium'?'var(--risk-medium)':'var(--risk-low)') ?>">
                                    <i class="fas fa-<?= $risk==='high'?'exclamation-circle':($risk==='medium'?'exclamation-triangle':'check-circle') ?>"></i><?= $riskLabel ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-sec)"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                            <td class="py-3 px-3 text-center">
                                <button onclick="openInspect(<?= $uid ?>)" class="text-xs px-2.5 py-1.5 rounded-lg border font-medium transition-all" style="border-color:var(--border);color:var(--text-sec);background:var(--hover)"><i class="fas fa-search mr-1"></i><?= t('inspect') ?></button>
                                <button onclick="showReviewModal('review-form','loan',<?= $row['id'] ?>,'approve')" class="text-xs px-2.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg ml-1"><i class="fas fa-check mr-1"></i><?= t('approve') ?></button>
                                <button onclick="showReviewModal('review-form','loan',<?= $row['id'] ?>,'reject')" class="text-xs px-2.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg ml-1"><i class="fas fa-times mr-1"></i><?= t('reject') ?></button>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="7" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_pending_items') ?></p></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ SHARES ═══════════════════ -->
    <div id="tab-shares" class="tab-content <?= $tab==='shares'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('shares') ?> <span class="text-base font-normal" style="color:var(--text-muted)">— <?= t('pending') ?></span></h1></div>
            <div class="flex gap-2">
                <a href="export.php?type=shares" class="text-xs bg-primary hover:bg-primary-hover text-white px-3 py-1.5 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= t('export_csv') ?></a>
            </div>
        </div>
        <div class="filter-bar">
            <div><label><?= t('search') ?></label><input type="text" id="shares-search" onkeyup="filterTable('shares-search','shares-table')" placeholder="<?= t('search') ?>..." class="input-field px-3 py-2 rounded-lg text-sm"></div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="shares-table">
                    <thead><tr style="background:var(--hover)">
                        <th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('full_name') ?></th>
                        <th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('shares') ?></th>
                        <th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('total') ?></th>
                        <th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)"><?= t('date') ?></th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('risk_profile') ?></th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('actions') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (count($pendingShares) > 0): foreach ($pendingShares as $row):
                            $uid = (int)$row['user_id'];
                            $dupCount = getDuplicateCount($pdo, 'shares', $uid);
                        ?>
                        <tr class="border-t" style="border-color:var(--border)">
                            <td class="py-3 px-3">
                                <span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span>
                                <?php if ($dupCount >= 3): ?><div class="duplicate-alert mt-1"><i class="fas fa-exclamation-triangle"></i><?= $dupCount ?> <?= t('items_24h') ?></div><?php endif; ?>
                            </td>
                            <td class="py-3 px-3 text-right" style="color:var(--text)"><?= (int)$row['share_count'] ?></td>
                            <td class="py-3 px-3 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['total_amount'], 2) ?> RWF</td>
                            <td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-sec)"><?= date('d M Y', strtotime($row['created_at'])) ?></td>
                            <td class="py-3 px-3 text-center"><span class="risk-badge" style="background:rgba(52,211,153,0.15);color:var(--risk-low)"><i class="fas fa-check-circle"></i><?= t('risk_low') ?></span></td>
                            <td class="py-3 px-3 text-center">
                                <button onclick="openInspect(<?= $uid ?>)" class="text-xs px-2.5 py-1.5 rounded-lg border font-medium transition-all" style="border-color:var(--border);color:var(--text-sec);background:var(--hover)"><i class="fas fa-search mr-1"></i><?= t('inspect') ?></button>
                                <button onclick="showReviewModal('review-form','share',<?= $row['id'] ?>,'approve')" class="text-xs px-2.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg ml-1"><i class="fas fa-check mr-1"></i><?= t('approve') ?></button>
                                <button onclick="showReviewModal('review-form','share',<?= $row['id'] ?>,'reject')" class="text-xs px-2.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg ml-1"><i class="fas fa-times mr-1"></i><?= t('reject') ?></button>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="6" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_pending_items') ?></p></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ REPAYMENTS ═══════════════════ -->
    <div id="tab-repayments" class="tab-content <?= $tab==='repayments'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('repayments') ?> <span class="text-base font-normal" style="color:var(--text-muted)">— <?= t('pending') ?></span></h1></div>
            <div class="flex gap-2">
                <a href="export.php?type=repayments" class="text-xs bg-primary hover:bg-primary-hover text-white px-3 py-1.5 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= t('export_csv') ?></a>
            </div>
        </div>
        <div class="filter-bar">
            <div><label><?= t('search') ?></label><input type="text" id="repayments-search" onkeyup="filterTable('repayments-search','repayments-table')" placeholder="<?= t('search') ?>..." class="input-field px-3 py-2 rounded-lg text-sm"></div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" id="repayments-table">
                    <thead><tr style="background:var(--hover)">
                        <th class="text-left py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('full_name') ?></th>
                        <th class="text-left py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)">Ref #</th>
                        <th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('loans') ?></th>
                        <th class="text-right py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('amount') ?></th>
                        <th class="text-center py-3 px-3 font-medium hidden lg:table-cell" style="color:var(--text-sec)">Receipt</th>
                        <th class="text-center py-3 px-3 font-medium hidden md:table-cell" style="color:var(--text-sec)"><?= t('overdue_days') ?></th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('risk_profile') ?></th>
                        <th class="text-center py-3 px-3 font-medium" style="color:var(--text-sec)"><?= t('actions') ?></th>
                    </tr></thead>
                    <tbody>
                        <?php if (count($pendingRepayments) > 0): foreach ($pendingRepayments as $row):
                            $uid = (int)$row['user_id'];
                            $od = $row['due_at'] ? max(0, (int)((time() - strtotime($row['due_at'])) / 86400)) : 0;
                            $risk = 'low'; $riskLabel = t('risk_low');
                            $dupCount = getDuplicateCount($pdo, 'repayments', $uid);
                            if ($od > 7) { $risk = 'high'; $riskLabel = t('risk_high'); }
                            elseif ($od > 1) { $risk = 'medium'; $riskLabel = t('risk_medium'); }
                        ?>
                        <tr class="border-t" style="border-color:var(--border)">
                            <td class="py-3 px-3">
                                <span style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></span>
                                <?php if ($dupCount >= 3): ?><div class="duplicate-alert mt-1"><i class="fas fa-exclamation-triangle"></i><?= $dupCount ?> <?= t('items_24h') ?></div><?php endif; ?>
                            </td>
                            <td class="py-3 px-3 hidden md:table-cell" style="color:var(--text-muted)"><?= htmlspecialchars($row['reference_number'] ?? '-') ?></td>
                            <td class="py-3 px-3 text-right" style="color:var(--text)"><?= number_format((float)$row['loan_amount'], 2) ?> RWF</td>
                            <td class="py-3 px-3 text-right font-medium" style="color:var(--text)"><?= number_format((float)$row['amount'], 2) ?> RWF</td>
                            <td class="py-3 px-3 text-center hidden lg:table-cell"><?php if (!empty($row['proof_file'])): ?><button onclick="openProof('uploads/<?= htmlspecialchars($row['proof_file']) ?>')" class="text-xs text-primary hover:underline bg-transparent border-0"><i class="fas fa-file"></i> View</button><?php else: ?><span class="text-xs" style="color:var(--text-muted)">—</span><?php endif; ?></td>
                            <td class="py-3 px-3 text-center hidden md:table-cell <?= $od > 0 ? 'text-red-400' : 'text-green-400' ?>"><?= $od > 0 ? $od . ' days' : 'On time' ?></td>
                            <td class="py-3 px-3 text-center">
                                <span class="risk-badge" style="background:<?= $risk==='high'?'rgba(248,113,113,0.15)':($risk==='medium'?'rgba(251,191,36,0.15)':'rgba(52,211,153,0.15)') ?>;color:<?= $risk==='high'?'var(--risk-high)':($risk==='medium'?'var(--risk-medium)':'var(--risk-low)') ?>">
                                    <i class="fas fa-<?= $risk==='high'?'exclamation-circle':($risk==='medium'?'exclamation-triangle':'check-circle') ?>"></i><?= $riskLabel ?>
                                </span>
                            </td>
                            <td class="py-3 px-3 text-center">
                                <button onclick="openInspect(<?= $uid ?>)" class="text-xs px-2.5 py-1.5 rounded-lg border font-medium transition-all" style="border-color:var(--border);color:var(--text-sec);background:var(--hover)"><i class="fas fa-search mr-1"></i><?= t('inspect') ?></button>
                                <button onclick="showReviewModal('review-form','repayment',<?= $row['id'] ?>,'approve')" class="text-xs px-2.5 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg ml-1"><i class="fas fa-check mr-1"></i><?= t('approve') ?></button>
                                <button onclick="showReviewModal('review-form','repayment',<?= $row['id'] ?>,'reject')" class="text-xs px-2.5 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg ml-1"><i class="fas fa-times mr-1"></i><?= t('reject') ?></button>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="8" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_pending_items') ?></p></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════ SUGGESTIONS ═══════════════════ -->
    <div id="tab-suggestions" class="tab-content <?= $tab==='suggestions'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><?= t('suggestions') ?> <span class="text-base font-normal" style="color:var(--text-muted)">— <?= t('pending') ?></span></h1></div>
            <div class="flex gap-2">
                <a href="export.php?type=suggestions" class="text-xs bg-primary hover:bg-primary-hover text-white px-3 py-1.5 rounded-lg"><i class="fas fa-file-csv mr-1"></i><?= t('export_csv') ?></a>
            </div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <table class="w-full text-sm">
                <thead><tr style="background:var(--hover)">
                    <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)"><?= t('full_name') ?></th>
                    <th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)"><?= t('title') ?></th>
                    <th class="text-left py-3 px-4 font-medium hidden md:table-cell" style="color:var(--text-sec)"><?= t('description') ?></th>
                    <th class="text-center py-3 px-4 font-medium" style="color:var(--text-sec)"><?= t('actions') ?></th>
                </tr></thead>
                <tbody>
                    <?php if (count($pendingSuggestions) > 0): foreach ($pendingSuggestions as $row): ?>
                    <tr class="border-t" style="border-color:var(--border)">
                        <td class="py-3 px-4" style="color:var(--text)"><?= htmlspecialchars($row['full_name']) ?></td>
                        <td class="py-3 px-4"><span class="font-medium" style="color:var(--text)"><?= htmlspecialchars($row['title']) ?></span></td>
                        <td class="py-3 px-4 hidden md:table-cell" style="color:var(--text-muted);max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(substr($row['description'], 0, 200)) ?></td>
                        <td class="py-3 px-4 text-center">
                            <button onclick="showReviewModal('review-form','suggestion',<?= $row['id'] ?>,'approve')" class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg"><i class="fas fa-check mr-1"></i><?= t('approve') ?></button>
                            <button onclick="showReviewModal('review-form','suggestion',<?= $row['id'] ?>,'reject')" class="text-xs px-3 py-1.5 bg-red-600/20 hover:bg-red-600/30 text-red-400 rounded-lg ml-1"><i class="fas fa-times mr-1"></i><?= t('reject') ?></button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" class="empty-state"><i class="fas fa-inbox"></i><p style="color:var(--text-muted)"><?= t('no_pending_items') ?></p></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════ ACTIVITY ═══════════════════ -->
    <div id="tab-activity" class="tab-content <?= $tab==='activity'?'active':'' ?>">
        <div class="flex items-center justify-between mb-6">
            <div><h1 class="text-2xl font-bold" style="color:var(--text)"><i class="fas fa-history mr-2" style="color:var(--text-sec)"></i>Activity Log</h1><p class="text-sm mt-1" style="color:var(--text-sec)">Your review actions and platform activity</p></div>
        </div>
        <div class="panel rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr style="background:var(--hover)"><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Date</th><th class="text-left py-3 px-4 font-medium" style="color:var(--text-sec)">Action</th><th class="text-left py-3 px-4 font-medium hidden md:table-cell" style="color:var(--text-sec)">IP</th></tr></thead>
                    <tbody><?php if (count($myActivityRows) > 0): foreach ($myActivityRows as $r): ?><tr class="border-t" style="border-color:var(--border)"><td class="py-3 px-4" style="color:var(--text-sec)"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td><td class="py-3 px-4" style="color:var(--text)"><?= htmlspecialchars(audit_description($r['action'], $r['details'])) ?></td><td class="py-3 px-4 hidden md:table-cell" style="color:var(--text-muted);font-family:monospace;font-size:0.75rem"><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td></tr><?php endforeach; else: ?><tr><td colspan="3" class="empty-state"><i class="fas fa-history"></i><p style="color:var(--text-muted)">No activity recorded yet</p></td></tr><?php endif; ?></tbody>
                </table>
            </div>
        </div>
    </div>

    </div><!-- /p-6 -->
</div><!-- /main-content -->

<!-- Inspection Modal -->
<div id="inspect-modal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal-box">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-primary/20 flex items-center justify-center"><i class="fas fa-user-shield text-primary"></i></div><h3 class="font-semibold text-lg" style="color:var(--text)"><?= t('member_details') ?></h3></div>
            <button onclick="document.getElementById('inspect-modal').classList.remove('show')" class="text-lg" style="color:var(--text-muted)"><i class="fas fa-times"></i></button>
        </div>
        <div id="inspect-body"></div>
    </div>
</div>

<!-- Review Confirmation Modal -->
<div id="review-modal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal-box">
        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-full bg-yellow-500/20 flex items-center justify-center"><i class="fas fa-exclamation-triangle text-yellow-400"></i></div>
            <div><h3 class="font-semibold" style="color:var(--text)"><?= t('confirm_review') ?></h3><p class="text-sm" style="color:var(--text-sec)"><?= t('reviewer_notes_label') ?> (<span class="text-xs" style="color:var(--text-muted)"><?= t('optional') ?></span>)</p></div>
        </div>
        <form id="review-form" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="review_id" id="review-form-id" value="">
            <input type="hidden" name="review_type" id="review-form-type" value="">
            <input type="hidden" name="review_action" id="review-form-action" value="">
            <div class="mb-3">
                <textarea name="reviewer_notes" rows="2" class="input-field w-full rounded-lg px-3 py-2 text-sm resize-none" placeholder="<?= t('notes') ?>..." style="background:var(--bg);border:1px solid var(--border);color:var(--text)"></textarea>
            </div>
            <div id="rejection-reason-group" class="mb-3" style="display:none">
                <label class="text-xs font-medium" style="color:var(--text-sec);display:block;margin-bottom:4px">Rejection Reason <span class="text-red-400">*</span></label>
                <textarea name="rejection_reason" rows="2" class="input-field w-full rounded-lg px-3 py-2 text-sm resize-none" placeholder="Why is this being rejected?" style="background:var(--bg);border:1px solid var(--border);color:var(--text)"></textarea>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm" style="color:var(--text-sec)"><i class="fas fa-info-circle mr-1"></i><?= t('review_queue') ?>: <strong id="review-action-label" class="text-white"></strong></span>
                <div class="flex gap-3">
                    <button type="button" onclick="document.getElementById('review-modal').classList.remove('show')" class="px-4 py-2 rounded-lg text-sm font-medium" style="background:var(--hover);color:var(--text-sec)"><?= t('cancel') ?></button>
                    <button type="button" onclick="submitReview()" class="px-4 py-2 rounded-lg text-sm font-medium bg-primary hover:bg-primary-hover text-white">
                        <i id="review-loader" class="fas fa-spinner fa-spin hidden mr-1"></i><?= t('submit') ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Proof Receipt Modal -->
<div id="proof-modal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal-box" style="max-width:700px">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold" style="color:var(--text)"><i class="fas fa-receipt mr-2 text-primary"></i>Receipt Proof</h3>
            <button onclick="document.getElementById('proof-modal').classList.remove('show')" class="text-lg" style="color:var(--text-muted)"><i class="fas fa-times"></i></button>
        </div>
        <iframe id="proof-frame" src="" style="width:100%;height:500px;border:1px solid var(--border);border-radius:8px;background:#fff"></iframe>
        <div class="mt-3 text-center"><a id="proof-download-link" href="#" target="_blank" class="text-xs text-primary hover:underline"><i class="fas fa-download mr-1"></i>Download file</a></div>
    </div>
</div>

</body>
</html>
