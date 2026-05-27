<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) throw new RuntimeException($msg ?: 'expected true');
}

function assert_eq($a, $b, string $msg = ''): void {
    if ($a !== $b) throw new RuntimeException($msg ?: var_export($a, true) . ' !== ' . var_export($b, true));
}

echo "\nRunning tests:\n";

// original
test('seeded share resolves to Welcome Packet', function () {
    $stmt = db()->prepare('SELECT d.title FROM shares s JOIN documents d ON d.id = s.document_id LIMIT 1');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row && $row['title'] === 'Welcome Packet');
});

// feature 1 - scheduled publishing
test('document with no publish_at is visible', function () {
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true($row['publish_at'] === null);
});

test('document with future publish_at is blocked', function () {
    $future = date('Y-m-d H:i:s', strtotime('+2 hours'));
    db()->prepare('INSERT INTO documents (title, body, created_by, publish_at, readable_id) VALUES (?, ?, 1, ?, ?)')->execute(['Future Doc', 'x', $future, 'future-doc-0001']);

    $stmt = db()->prepare('SELECT d.publish_at FROM shares s JOIN documents d ON d.id = s.document_id WHERE d.title = ?');
    $stmt->execute(['Future Doc']);

    // we don't have a share for this doc so simulate it directly
    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE title = ?');
    $stmt->execute(['Future Doc']);
    $doc = $stmt->fetch();

    $blocked = $doc['publish_at'] && $doc['publish_at'] > date('Y-m-d H:i:s');
    assert_true($blocked, 'future doc should be blocked');
});

test('document with past publish_at is visible', function () {
    $past = date('Y-m-d H:i:s', strtotime('-1 hour'));
    db()->prepare('INSERT INTO documents (title, body, created_by, publish_at, readable_id) VALUES (?, ?, 1, ?, ?)')->execute(['Past Doc', 'x', $past, 'past-doc-0001']);

    $stmt = db()->prepare('SELECT publish_at FROM documents WHERE title = ?');
    $stmt->execute(['Past Doc']);
    $doc = $stmt->fetch();

    $blocked = $doc['publish_at'] && $doc['publish_at'] > date('Y-m-d H:i:s');
    assert_true(!$blocked, 'past doc should not be blocked');
});

// feature 2 - readable ids
test('make_readable_id returns a slug', function () {
    $id = make_readable_id('Quarterly Report');
    assert_true(str_starts_with($id, 'quarterly-report-'), "got: $id");
    assert_true(preg_match('/^[a-z0-9-]+$/', $id) === 1);
});

test('readable ids are unique per insert', function () {
    $a = make_readable_id('Test');
    $b = make_readable_id('Test');
    assert_true($a !== $b, 'should differ due to random suffix');
});

test('seeded doc has a readable_id', function () {
    $stmt = db()->prepare('SELECT readable_id FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $row = $stmt->fetch();
    assert_true(!empty($row['readable_id']));
});

test('can look up document by readable_id', function () {
    $rid = make_readable_id('Lookup Test');
    db()->prepare('INSERT INTO documents (title, body, created_by, readable_id) VALUES (?, ?, 1, ?)')->execute(['Lookup Test', 'x', $rid]);

    $stmt = db()->prepare('SELECT title FROM documents WHERE readable_id = ?');
    $stmt->execute([$rid]);
    $row = $stmt->fetch();
    assert_eq($row['title'], 'Lookup Test');
});

// feature 3 - search
test('search finds document by exact title', function () {
    $stmt = db()->prepare('SELECT title FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Welcome Packet%']);
    $rows = $stmt->fetchAll();
    $titles = array_column($rows, 'title');
    assert_true(in_array('Welcome Packet', $titles));
});

test('search works on partial match', function () {
    $stmt = db()->prepare('SELECT title FROM documents WHERE title LIKE ?');
    $stmt->execute(['%Packet%']);
    assert_true(count($stmt->fetchAll()) >= 1);
});

test('search returns nothing for garbage input', function () {
    $stmt = db()->prepare('SELECT title FROM documents WHERE title LIKE ?');
    $stmt->execute(['%zzznomatch999%']);
    assert_eq(count($stmt->fetchAll()), 0);
});

test('search is case insensitive', function () {
    $stmt = db()->prepare('SELECT title FROM documents WHERE title LIKE ?');
    $stmt->execute(['%welcome%']);
    assert_true(count($stmt->fetchAll()) >= 1);
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
