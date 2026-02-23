<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$pdo = db();
$flash = null;

// ---- ログアウト ----
if (isset($_GET['logout'])) {
    admin_logout();
    header('Location: edit.php');
    exit;
}

// ---- ログイン ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'login') {
    csrf_check();
    $pw = (string)($_POST['password'] ?? '');
    if (admin_login($pw)) {
        header('Location: edit.php');
        exit;
    }
    $flash = 'パスワードが違います。';
}

// ---- 未ログインならログイン画面 ----
if (!is_admin()) {
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>管理ログイン</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <h1>管理ログイン</h1>
  <p class="muted"><a href="index.php">← 閲覧ページへ</a></p>

  <div class="card">
    <?php if ($flash): ?><p class="error"><?= h($flash) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="login">
      <p>
        <label>管理パスワード</label><br>
        <input type="password" name="password" required>
      </p>
      <button class="btn" type="submit">ログイン</button>
    </form>
  </div>
</body>
</html>
<?php
    exit;
}

// ---- 管理画面（CRUD）----
$selectedId  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mode        = (string)($_GET['mode'] ?? 'new'); // new | edit
$selectedTag = isset($_GET['cat']) ? trim((string)$_GET['cat']) : ''; // ''=全て

// POST: CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    $tz = new DateTimeZone('Asia/Tokyo');
    $entryDate = (string)($_POST['entry_date'] ?? '');
    $entryTime = (string)($_POST['entry_time'] ?? '');
    if ($entryDate === '') $entryDate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    if ($entryTime === '') $entryTime = '00:00';
    $created = DateTimeImmutable::createFromFormat('Y-m-d H:i', $entryDate . ' ' . $entryTime, $tz);

    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));

        if ($title === '' || $body === '') {
            $flash = 'タイトルと本文は必須です。';
        } elseif (!$created) {
            $flash = '日付/時刻の形式が不正です。';
        } else {
            $createdAt = $created->format('c');
            $now = (new DateTimeImmutable('now', $tz))->format('c');

            $stmt = $pdo->prepare("
                INSERT INTO entries(title, body, created_at, updated_at)
                VALUES(:title, :body, :created_at, :updated_at)
            ");
            $stmt->execute([
                ':title' => $title,   // 例: 散歩[health][work]
                ':body' => $body,
                ':created_at' => $createdAt,
                ':updated_at' => $now,
            ]);

            $newId = (int)$pdo->lastInsertId();
            header('Location: edit.php?id=' . $newId);
            exit;
        }
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $body  = trim((string)($_POST['body'] ?? ''));

        if ($id <= 0 || $title === '' || $body === '') {
            $flash = '更新できませんでした（必須項目 or ID不正）。';
        } elseif (!$created) {
            $flash = '日付/時刻の形式が不正です。';
        } else {
            $createdAt = $created->format('c');
            $now = (new DateTimeImmutable('now', $tz))->format('c');

            $stmt = $pdo->prepare("
                UPDATE entries
                SET title = :title,
                    body = :body,
                    created_at = :created_at,
                    updated_at = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':created_at' => $createdAt,
                ':updated_at' => $now,
                ':id' => $id,
            ]);

            header('Location: edit.php?id=' . $id);
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM entries WHERE id = :id");
            $stmt->execute([':id' => $id]);
        }
        header('Location: edit.php' . ($selectedTag !== '' ? '?cat=' . urlencode($selectedTag) : ''));
        exit;
    }

    if ($action !== 'login') {
        http_response_code(400);
        exit('Bad Request');
    }
}

// ---- 一覧取得 & タグ集計 ----
$rows = $pdo->query("SELECT id, title, created_at, updated_at FROM entries ORDER BY id DESC")->fetchAll();

$tagCounts = [];
$entries = [];

foreach ($rows as $r) {
    $info = parse_title_tags((string)$r['title']);
    $tags = $info['tags'];

    foreach ($tags as $t) {
        $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
    }

    $entries[] = [
        'id' => (int)$r['id'],
        'title' => (string)$r['title'],
        'display_title' => $info['display_title'],
        'tags' => $tags,
        'created_at' => (string)$r['created_at'],
        'updated_at' => (string)$r['updated_at'],
    ];
}

uksort($tagCounts, function($a, $b) {
    if ($a === '未分類') return 1;
    if ($b === '未分類') return -1;
    return strnatcasecmp($a, $b);
});

$filtered = $entries;
if ($selectedTag !== '') {
    $filtered = array_values(array_filter($entries, fn($e) => in_array($selectedTag, $e['tags'], true)));
}

// 選択中取得
$active = null;
if ($selectedId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM entries WHERE id = :id");
    $stmt->execute([':id' => $selectedId]);
    $active = $stmt->fetch();
}
if ($mode === 'edit' && !$active) $mode = 'new';

// フォーム初期値（日付）
$tz = new DateTimeZone('Asia/Tokyo');
$defaultDate = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$defaultTime = '00:00';

$editDate = $defaultDate;
$editTime = $defaultTime;
if ($active) {
    [$editDate, $editTime] = split_iso_to_jst_date_time((string)$active['created_at']);
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>日記 管理</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header>
  <div>
    <strong>日記 管理</strong>
    <span class="muted">（ログイン中<?= $selectedTag !== '' ? ' / タグ: ' . h($selectedTag) : '' ?>）</span>
  </div>
  <div class="row">
    <a class="btn" href="index.php<?= $selectedTag !== '' ? '?cat=' . urlencode($selectedTag) : '' ?>">閲覧</a>
    <a class="btn" href="edit.php?logout=1">ログアウト</a>
  </div>
</header>

<div class="wrap">
  <aside>
    <div class="row">
      <a class="btn" href="edit.php?mode=new<?= $selectedTag !== '' ? '&cat=' . urlencode($selectedTag) : '' ?>">新規</a>
      <?php if ($active): ?>
        <a class="btn" href="edit.php?id=<?= (int)$selectedId ?>&mode=edit<?= $selectedTag !== '' ? '&cat=' . urlencode($selectedTag) : '' ?>">編集</a>
      <?php endif; ?>
    </div>

    <div style="margin-top:10px;">
      <a class="pill <?= $selectedTag === '' ? 'active' : '' ?>" href="edit.php">
        全て <small>(<?= (int)count($entries) ?>)</small>
      </a>
      <?php foreach ($tagCounts as $tag => $count): ?>
        <a class="pill <?= $selectedTag === $tag ? 'active' : '' ?>" href="edit.php?cat=<?= urlencode((string)$tag) ?>">
          <?= h((string)$tag) ?> <small>(<?= (int)$count ?>)</small>
        </a>
      <?php endforeach; ?>
    </div>

    <div style="margin-top: 10px;">
      <?php if (count($filtered) === 0): ?>
        <p class="muted">該当タグの投稿がありません。</p>
      <?php else: ?>
        <?php foreach ($filtered as $e): ?>
          <?php
            $isActive = ($active && (int)$e['id'] === (int)$selectedId);
            $href = 'edit.php?id=' . (int)$e['id'] . ($selectedTag !== '' ? '&cat=' . urlencode($selectedTag) : '');
          ?>
          <a class="entry <?= $isActive ? 'active' : '' ?>" href="<?= h($href) ?>">
            <div><strong><?= h($e['display_title']) ?></strong></div>
            <div class="muted"><?= h($e['created_at']) ?> / <?= h(implode(', ', $e['tags'])) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <main>
    <?php if ($flash): ?><div class="error"><?= h($flash) ?></div><?php endif; ?>

    <?php if ($active): ?>
      <?php $info = parse_title_tags((string)$active['title']); ?>
      <h1 style="margin:0 0 6px 0;"><?= h($info['display_title']) ?></h1>
      <div class="muted">
        タグ: <?= h(implode(', ', $info['tags'])) ?> /
        作成: <?= h((string)$active['created_at']) ?> /
        更新: <?= h((string)$active['updated_at']) ?>
      </div>

      <div class="card">
        <?= markdown_to_html((string)$active['body']) ?>
      </div>

      <div class="card">
        <form method="post" onsubmit="return confirm('削除しますか？');" style="margin:0;">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$selectedId ?>">
          <button class="btn danger" type="submit">削除</button>
        </form>
      </div>
    <?php else: ?>
      <h1 style="margin:0;">管理ページ</h1>
      <p class="muted">左から選ぶか、新規で投稿してください。</p>
    <?php endif; ?>

    <div class="card">
      <?php if ($mode === 'edit' && $active): ?>
        <h2 style="margin-top:0;">編集</h2>
        <p class="muted">タイトルに <code>[tag]</code> を複数入れられます（例: <code>散歩[health][work]</code>）。</p>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$selectedId ?>">

          <p>
            <label>日付</label><br>
            <input type="date" name="entry_date" value="<?= h($editDate) ?>">
          </p>
          <p>
            <label>時刻（任意）</label><br>
            <input type="time" name="entry_time" value="<?= h($editTime) ?>">
          </p>

          <p>
            <label>タイトル（[tag]を複数OK）</label><br>
            <input type="text" name="title" value="<?= h((string)$active['title']) ?>" required>
          </p>
          <p>
            <label>本文（Markdown）</label><br>
            <textarea name="body" required><?= h((string)$active['body']) ?></textarea>
          </p>

          <div class="row">
            <button class="btn primary" type="submit">更新</button>
            <a class="btn" href="edit.php?id=<?= (int)$selectedId ?><?= $selectedTag !== '' ? '&cat=' . urlencode($selectedTag) : '' ?>">キャンセル</a>
          </div>
        </form>
      <?php else: ?>
        <h2 style="margin-top:0;">新規投稿</h2>
        <p class="muted">タイトルに <code>[tag]</code> を複数入れられます（例: <code>散歩[health][work]</code>）。</p>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="create">

          <p>
            <label>日付</label><br>
            <input type="date" name="entry_date" value="<?= h($defaultDate) ?>">
          </p>
          <p>
            <label>時刻（任意）</label><br>
            <input type="time" name="entry_time" value="">
          </p>

          <p>
            <label>タイトル（[tag]を複数OK）</label><br>
            <input type="text" name="title" required>
          </p>
          <p>
            <label>本文（Markdown）</label><br>
            <textarea name="body" required></textarea>
          </p>

          <button class="btn primary" type="submit">保存</button>
        </form>
      <?php endif; ?>
    </div>
  </main>
</div>
</body>
</html>
