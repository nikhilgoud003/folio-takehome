<?php

function run_migrations(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
        filename TEXT PRIMARY KEY,
        applied_at TEXT DEFAULT (datetime('now'))
    )");

    $files = glob(__DIR__ . '/../migrations/*.sql');
    if (!$files) return;
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);

        $row = $pdo->prepare("SELECT 1 FROM migrations WHERE filename = ?")->execute([$name]);
        $already = $pdo->prepare("SELECT 1 FROM migrations WHERE filename = ?");
        $already->execute([$name]);
        if ($already->fetch()) continue;

        $sql = file_get_contents($file);
        $sql = preg_replace('/^\s*--[^\n]*/m', '', $sql);
        $sql = trim($sql);

        $pdo->beginTransaction();
        try {
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $s) {
                if ($s) $pdo->exec($s);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration {$name} failed: " . $e->getMessage());
        }

        $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)")->execute([$name]);
        echo "  applied: {$name}\n";
    }
}
