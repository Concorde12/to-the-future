<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

// ─── Registration ─────────────────────────────────────────────────────────
if ($action === 'register' && is_post()) {
    verify_csrf();

    $fullName    = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $dob         = $_POST['date_of_birth'] ?? '';
    $password    = $_POST['password'] ?? '';
    $confirm     = $_POST['confirm_password'] ?? '';

    $errors = [];

    if (strlen($fullName) < 2) $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    // Validate DOB: not in the future, at least 18 years old
    $dobTimestamp = strtotime($dob);
    if (!$dobTimestamp) {
        $errors[] = 'Invalid date of birth.';
    } else {
        if ($dobTimestamp > time()) $errors[] = 'Date of birth cannot be in the future.';
        $age = (int)((time() - $dobTimestamp) / 31536000);
        if ($age < 18) $errors[] = 'You must be at least 18 years old.';
    }

    if (empty($errors)) {
        $pdo = db();
        // Check email uniqueness
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $errors[] = 'Email already registered.';
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        $stmt = $pdo->prepare('INSERT INTO users (full_name, email, phone, date_of_birth, password_hash, role, status) VALUES (?, ?, ?, ?, ?, \'user\', \'pending\')');
        $stmt->execute([$fullName, $email, $phone, $dob, $hash]);

        $userId = (int)$pdo->lastInsertId();

        // Create verified savings record
        $pdo->prepare('INSERT INTO verified_savings (user_id, current_balance, proposed_balance) VALUES (?, 0, 0)')->execute([$userId]);

        audit_log('user_registered', ['user_id' => $userId, 'email' => $email]);
        notify($userId, 'Your account has been created and is pending approval.');

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Registration successful. Awaiting admin approval.'];
        redirect('index.php');
    }

    $_SESSION['flash'] = ['type' => 'error', 'message' => implode('<br>', $errors)];
    redirect('index.php?action=register');
}

// ─── Login ────────────────────────────────────────────────────────────────
if ($action === 'login' && is_post()) {
    verify_csrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Email and password are required.'];
        redirect('index.php');
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        audit_log('login_failed', ['email' => $email]);
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid email or password.'];
        redirect('index.php');
    }

    if ($user['status'] !== 'approved') {
        $reason = $user['status'] === 'inactive' ? 'Your account has been deactivated. Contact administration.' : 'Your account is ' . $user['status'] . '. Please wait for approval.';
        $_SESSION['flash'] = ['type' => 'error', 'message' => $reason];
        redirect('index.php');
    }

    // Regenerate session on login
    session_regenerate_id(true);
    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['_created'] = time();

    audit_log('user_login', ['user_id' => $user['id']]);

    // Role-based redirect
    match ($user['role']) {
        'super_admin' => redirect('admin.php'),
        'reviewer'    => redirect('reviewer.php'),
        default       => redirect('user.php'),
    };
}

// If no action matched, redirect
redirect('index.php');
