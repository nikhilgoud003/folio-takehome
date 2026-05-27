<?php

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/migrate.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
run_migrations($pdo);

$pdo->exec("INSERT INTO staff (email, name) VALUES ('freddy@folio.example', 'Freddy Folio')");

// regular doc, visible immediately
$rid1 = make_readable_id('Welcome Packet');
$stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by, readable_id) VALUES (?, ?, 1, ?)');
$stmt->execute(['Welcome Packet', "Welcome to Folio!\n\nThis is the body of your welcome packet.", $rid1]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)')->execute([$docId, $token, 'recipient@example.com']);

// scheduled doc - good for testing the gate
$future = date('Y-m-d H:i:s', strtotime('+1 hour'));
$rid2 = make_readable_id('Onboarding Guide');
$stmt = $pdo->prepare('INSERT INTO documents (title, body, created_by, publish_at, readable_id) VALUES (?, ?, 1, ?, ?)');
$stmt->execute(['Onboarding Guide', "This one is scheduled and won't show up yet.", $future, $rid2]);
$docId2 = (int) $pdo->lastInsertId();

$token2 = random_token();
$pdo->prepare('INSERT INTO shares (document_id, token, recipient_email) VALUES (?, ?, ?)')->execute([$docId2, $token2, 'newstaff@example.com']);

echo "done.\n";
echo "admin:           http://localhost:8000/admin.php\n";
echo "normal share:    http://localhost:8000/view.php?token={$token}\n";
echo "scheduled share: http://localhost:8000/view.php?token={$token2}  (blocked until {$future})\n";
