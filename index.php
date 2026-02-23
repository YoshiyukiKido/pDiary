<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$pdo = db();

$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedTag = isset($_GET['cat']) ? trim((string)$_GET['cat']) : ''; // '' = 全て
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;

$perPage = 10;

$rows = $pdo->query("SELECT id, title, created_at, updated_at FROM entries ORDER BY id DESC")->fetchAll();

/**
 * entries: id, display_title, tags[], created_at...
 * tagCounts: tag => count
 */
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

// タグを見やすくソート（未分類は最後）
uksort($tagCounts, function($a, $b) {
    if ($a === '未分類') return 1;
    if ($b === '未分類') return -1;
    return strnatcasecmp($a, $b);
});

// フィルタ適用：選択タグを含む記事だけ
$filtered = $entries;
if ($selectedTag !== '') {
    $filtered = array_values(array_filter(
        $entries,
        fn($e) => in_array($selectedTag, $e['tags'], true)
    ));
}

// ---- ページング準備 ----
$total = count($filtered);
$totalPages = max(1, (int)ceil($total / $perPage));

// id指定があり、かつフィルタ内に存在する場合は、その記事が載るページに自動ジャンプ（pが未指定のとき）
if (!isset($_GET['p']) && $selectedId > 0 && $total > 0) {
    foreach ($filtered as $i => $e) {
        if ($e['id'] === $selectedId) {
            $page = (int)floor($i / $perPage) + 1;
            break;
        }
    }
}

$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$paged = array_slice($filtered, $offset, $perPage);

// 右側に表示する対象
$active = null;

// id が指定されていて、フィルタ内に存在するならそれを表示（ページ外でもOK）
if ($selectedId > 0 && $total > 0) {
    foreach ($filtered as $e) {
        if ($e['id'] === $selectedId) {
            $stmt = $pdo->prepare("SELECT * FROM entries WHERE id = :id");
            $stmt->execute([':id' => $selectedId]);
            $active = $stmt->fetch();
            break;
        }
    }
}

// id指定がない/見つからないなら「このページの先頭」を表示
if (!$active && count($paged) > 0) {
    $latestOnPageId = (int)$paged[0]['id'];
    $stmt = $pdo->prepare("SELECT * FROM entries WHERE id = :id");
    $stmt->execute([':id' => $latestOnPageId]);
    $active = $stmt->fetch();
    $selectedId = $latestOnPageId;
}

// ページリンク生成（cat維持）
function build_url(array $params): string {
    $base = 'index.php';
    $q = http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== null));
    return $q ? ($base . '?' . $q) : $base;
}

$commonParams = [
    'cat' => $selectedTag,
];

$newerUrl = ($page > 1)
    ? build_url($commonParams + ['p' => $page - 1]) // 新しい方向（前ページ）
    : null;

$olderUrl = ($page < $totalPages)
    ? build_url($commonParams + ['p' => $page + 1]) // 古い方向（次ページ）
    : null;

?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <title>日記</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
<header>
  <div>
    <strong>日記</strong>
    <span class="muted">閲覧ページ<?= $selectedTag !== '' ? ' / タグ: ' . h($selectedTag) : '' ?></span>
  </div>
  <div class="muted"><a href="edit.php">管理</a></div>
</header>

<div class="wrap">
  <aside>
    <!-- タグ（カテゴリ）一覧 -->
    <div>
      <a class="pill <?= $selectedTag === '' ? 'active' : '' ?>" href="<?= h(build_url(['cat' => ''])) ?>">
        全て <small>(<?= (int)count($entries) ?>)</small>
      </a>
      <?php foreach ($tagCounts as $tag => $count): ?>
        <a class="pill <?= $selectedTag === $tag ? 'active' : '' ?>" href="<?= h(build_url(['cat' => (string)$tag])) ?>">
          <?= h((string)$tag) ?> <small>(<?= (int)$count ?>)</small>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- ページング矢印 -->
    <div class="row" style="margin-top: 10px; justify-content: space-between;">
      <div>
        <?php if ($newerUrl): ?>
          <a class="btn" href="<?= h($newerUrl) ?>">← 新しい</a>
        <?php else: ?>
          <span class="btn" style="opacity:.4; cursor:default;">← 新しい</span>
        <?php endif; ?>
      </div>

      <div class="muted" style="align-self:center;">
        <?= (int)$page ?> / <?= (int)$totalPages ?>
      </div>

      <div>
        <?php if ($olderUrl): ?>
          <a class="btn" href="<?= h($olderUrl) ?>">古い →</a>
        <?php else: ?>
          <span class="btn" style="opacity:.4; cursor:default;">古い →</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- 左：一覧（最大10件） -->
    <div style="margin-top: 10px;">
      <?php if (count($paged) === 0): ?>
        <p class="muted">該当タグの投稿がありません。</p>
      <?php else: ?>
        <?php foreach ($paged as $e): ?>
          <?php
            $isActive = ($active && (int)$e['id'] === (int)$selectedId);
            $href = build_url([
                'id' => (int)$e['id'],
                'cat' => $selectedTag,
                'p' => $page,
            ]);
          ?>
          <a class="entry <?= $isActive ? 'active' : '' ?>" href="<?= h($href) ?>">
            <div><strong><?= h($e['display_title']) ?></strong></div>
            <div class="muted">
              <?= h($e['created_at']) ?> /
              <?= h(implode(', ', $e['tags'])) ?>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <main>
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
    <?php else: ?>
      <h1 style="margin:0;">日記</h1>
      <p class="muted">まだ投稿がありません。</p>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
