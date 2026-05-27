<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    $when  = trim($_POST['publish_at'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $publish_at = null;
        if ($when !== '') {
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', $when);
            $publish_at = $dt ? $dt->format('Y-m-d H:i:s') : null;
        }

        $rid = make_readable_id($title);

        $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, publish_at, readable_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$title, $body, $staff['id'], $publish_at, $rid]);
        $docId = (int) db()->lastInsertId();

        audit_log('create', 'document', $docId, ['title' => $title, 'publish_at' => $publish_at]);

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

$q = trim($_GET['q'] ?? '');

if ($q !== '') {
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute(['%' . $q . '%']);
} else {
    $stmt = db()->query('
        SELECT d.*, s.name AS creator_name FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ');
}

$docs = $stmt->fetchAll();
$now  = date('Y-m-d H:i:s');

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>
<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required value="<?= h($_POST['title'] ?? '') ?>">
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required><?= h($_POST['body'] ?? '') ?></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Schedule for later <small>(optional)</small></label>
            <input type="datetime-local" id="publish_at" name="publish_at" value="<?= h($_POST['publish_at'] ?? '') ?>">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>

    <form method="get" class="search-form">
        <input type="text" name="q" placeholder="Search by title…" value="<?= h($q) ?>" class="search-input">
        <button type="submit" class="btn">Search</button>
        <?php if ($q): ?><a href="/admin.php" class="btn-link">Clear</a><?php endif ?>
    </form>

    <?php if ($q): ?>
        <p class="search-meta"><?= count($docs) ?> result<?= count($docs) !== 1 ? 's' : '' ?> for "<?= h($q) ?>"</p>
    <?php endif ?>

    <?php if (empty($docs)): ?>
        <p class="empty">No documents<?= $q ? ' matching that search' : '' ?>.</p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Readable ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                <?php
                    if (!$d['publish_at']) {
                        $badge = ['Published', 'status-published'];
                    } elseif ($d['publish_at'] > $now) {
                        $badge = ['Scheduled: ' . $d['publish_at'], 'status-scheduled'];
                    } else {
                        $badge = ['Published', 'status-published'];
                    }
                ?>
                <tr>
                    <td class="id">#<?= (int) $d['id'] ?></td>
                    <td class="readable-id"><?= h($d['readable_id'] ?? '—') ?></td>
                    <td><?= h($d['title']) ?></td>
                    <td><?= h($d['creator_name']) ?></td>
                    <td><span class="status <?= $badge[1] ?>"><?= h($badge[0]) ?></span></td>
                    <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
