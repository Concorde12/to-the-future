<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$tables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $tables[] = $row[0];
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

foreach ($tables as $table) {
    if ($table === 'users') {
        continue;
    }
    $pdo->exec("TRUNCATE TABLE `{$table}`");
}

$pdo->exec("DELETE FROM users WHERE role != 'super_admin'");

$pdo->exec("INSERT IGNORE INTO settings (id) VALUES (1)");

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "Cleanup complete.\n";
printf("Tables processed: %d\n", count($tables));
$cnt = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
printf("Remaining users (super_admin only): %d\n", (int)$cnt);
