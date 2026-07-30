<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (is_authenticated()) {
    match ($_SESSION['role']) {
        'super_admin' => redirect('admin.php'),
        'reviewer'    => redirect('reviewer.php'),
        default       => redirect('user.php'),
    };
}

$showRegister = ($_GET['action'] ?? '') === 'register';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('app_name') ?> — <?= t($showRegister ? 'register' : 'login') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        dark: { bg: '#0b1220', panel: '#121a2b', border: '#1e2a45', hover: '#1a2540' },
                        primary: { DEFAULT: '#2ba7ff', hover: '#1e8fe0', light: '#3db4ff' },
                    },
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                }
            }
        }
    </script>
    <style>
        :root, [data-theme="dark"] { --bg: #0b1220; --panel: #121a2b; --border: #1e2a45; --hover: #1a2540; --text: #e2e8f0; --text-sec: #94a3b8; --text-muted: #64748b; }
        [data-theme="light"] { --bg: #f1f5f9; --panel: #ffffff; --border: #e2e8f0; --hover: #f8fafc; --text: #1e293b; --text-sec: #475569; --text-muted: #94a3b8; }
        body { background-color: var(--bg); font-family: 'Inter', sans-serif; color: var(--text); }
        .panel { background-color: var(--panel); border: 1px solid var(--border); }
        .input-field { background-color: var(--bg); border: 1px solid var(--border); color: var(--text); }
        .input-field:focus { border-color: #2ba7ff; outline: none; box-shadow: 0 0 0 2px rgba(43,167,255,0.2); }
    </style>
    <script>
        (function(){ const t = localStorage.getItem('theme') || '<?= $theme ?>'; document.documentElement.setAttribute('data-theme', t); })();
        function toggleTheme() {
            const h = document.documentElement;
            const n = h.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            h.setAttribute('data-theme', n);
            localStorage.setItem('theme', n);
            document.querySelector('#theme-icon').className = n === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    </script>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold" style="color: var(--text)"><?= t('app_name') ?></h1>
            <p class="mt-2" style="color: var(--text-sec)"><?= t($showRegister ? 'register' : 'login') ?></p>
        </div>

        <?php if ($flash): ?>
            <div class="panel p-4 mb-6 rounded-lg border <?= $flash['type'] === 'error' ? 'border-red-500/50 text-red-400' : 'border-green-500/50 text-green-400' ?>">
                <?= $flash['message'] ?>
            </div>
        <?php endif; ?>

        <div class="panel p-8 rounded-xl shadow-2xl">
            <div class="flex justify-end gap-2 mb-4">
                <button onclick="toggleTheme()" class="text-xs px-2 py-1 rounded" style="color: var(--text-muted);">
                    <i id="theme-icon" class="fas <?= $theme === 'dark' ? 'fa-sun' : 'fa-moon' ?>"></i>
                </button>
                <a href="?lang=en<?= $showRegister ? '&action=register' : '' ?>" class="text-xs px-2 py-1 rounded <?= $locale === 'en' ? 'bg-primary text-white' : '' ?>" style="<?= $locale !== 'en' ? 'color: var(--text-muted);' : '' ?>">EN</a>
                <a href="?lang=rw<?= $showRegister ? '&action=register' : '' ?>" class="text-xs px-2 py-1 rounded <?= $locale === 'rw' ? 'bg-primary text-white' : '' ?>" style="<?= $locale !== 'rw' ? 'color: var(--text-muted);' : '' ?>">RW</a>
                <a href="?lang=fr<?= $showRegister ? '&action=register' : '' ?>" class="text-xs px-2 py-1 rounded <?= $locale === 'fr' ? 'bg-primary text-white' : '' ?>" style="<?= $locale !== 'fr' ? 'color: var(--text-muted);' : '' ?>">FR</a>
            </div>

            <div class="flex gap-4 mb-6">
                <a href="index.php" class="text-sm font-medium" style="color: <?= $showRegister ? 'var(--text-muted)' : '#2ba7ff' ?>; border-bottom: 2px solid <?= $showRegister ? 'transparent' : '#2ba7ff' ?>;"><?= t('login') ?></a>
                <a href="index.php?action=register" class="text-sm font-medium" style="color: <?= $showRegister ? '#2ba7ff' : 'var(--text-muted)' ?>; border-bottom: 2px solid <?= $showRegister ? '#2ba7ff' : 'transparent' ?>;"><?= t('register') ?></a>
            </div>

            <form id="form-login" method="POST" action="auth.php?action=login" class="<?= $showRegister ? 'hidden' : '' ?>">
                <?= csrf_field() ?>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('email') ?></label>
                        <input type="email" name="email" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('password') ?></label>
                        <input type="password" name="password" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-primary-hover text-white font-medium py-2.5 rounded-lg transition-colors"><?= t('login') ?></button>
                </div>
            </form>

            <form id="form-register" method="POST" action="auth.php?action=register" class="<?= $showRegister ? '' : 'hidden' ?>">
                <?= csrf_field() ?>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('full_name') ?></label>
                        <input type="text" name="full_name" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('email') ?></label>
                        <input type="email" name="email" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('phone') ?></label>
                        <input type="tel" name="phone" class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('date_of_birth') ?></label>
                        <input type="date" name="date_of_birth" required class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);"><?= t('password') ?></label>
                        <input type="password" name="password" required minlength="8" class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm mb-1" style="color: var(--text-sec);">Confirm Password</label>
                        <input type="password" name="confirm_password" required minlength="8" class="input-field w-full px-4 py-2.5 rounded-lg text-sm">
                    </div>
                    <button type="submit" class="w-full bg-primary hover:bg-primary-hover text-white font-medium py-2.5 rounded-lg transition-colors"><?= t('register') ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            document.getElementById('form-login').classList.toggle('hidden', tab !== 'login');
            document.getElementById('form-register').classList.toggle('hidden', tab !== 'register');
            document.getElementById('tab-login').className = 'px-4 py-2 text-sm font-medium border-b-2 transition-colors';
            document.getElementById('tab-register').className = 'px-4 py-2 text-sm font-medium border-b-2 transition-colors';
            if (tab === 'login') {
                document.getElementById('tab-login').style.cssText = 'color: #2ba7ff; border-color: #2ba7ff;';
                document.getElementById('tab-register').style.cssText = 'color: var(--text-muted); border-color: transparent;';
            } else {
                document.getElementById('tab-register').style.cssText = 'color: #2ba7ff; border-color: #2ba7ff;';
                document.getElementById('tab-login').style.cssText = 'color: var(--text-muted); border-color: transparent;';
            }
        }
    </script>
</body>
</html>
