<?php
declare(strict_types=1);

/**
 * SQLite + 共通関数
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dbDir = __DIR__ . '/data';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0777, true);
    }

    $path = $dbDir . '/diary.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // 初回のみテーブル作成
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
    ");

    return $pdo;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// -------------------- Session --------------------
session_start();

// -------------------- CSRF --------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(403);
        exit('CSRF token mismatch');
    }
}

// -------------------- Admin Auth --------------------
// ★必ず変更：長くて強いパスワードに
const ADMIN_PASSWORD_HASH = '$2y$10$pUIPgXHxyol607RB3gQo9eWSyMzM1IR2MwiBOeQHAfxucSgNunOC2';

function is_admin(): bool {
    return !empty($_SESSION['is_admin']);
}

function admin_login(string $password): bool {
    if (password_verify($password, ADMIN_PASSWORD_HASH)) {
        $_SESSION['is_admin'] = true;
        session_regenerate_id(true);
        return true;
    }
    return false;
}

function admin_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// -------------------- Markdown --------------------
function markdown_to_html(string $markdown): string {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        return '<pre>' . h($markdown) . '</pre>';
    }

    require_once $autoload;

    if (!class_exists(\League\CommonMark\CommonMarkConverter::class)) {
        return '<pre>' . h($markdown) . '</pre>';
    }

    $config = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
        'max_nesting_level' => 20,
        'max_delimiters_per_line' => 200,
    ];

    $converter = new \League\CommonMark\CommonMarkConverter($config);

    if (method_exists($converter, 'convertToHtml')) {
        return (string)$converter->convertToHtml($markdown);
    }
    return (string)$converter->convert($markdown);
}

/**
 * ISO8601(created_at) を JST の date/time に分解
 * @return array{0:string,1:string} [Y-m-d, H:i]
 */
function split_iso_to_jst_date_time(string $iso): array {
    try {
        $tz = new DateTimeZone('Asia/Tokyo');
        $dt = new DateTimeImmutable($iso);
        $dt = $dt->setTimezone($tz);
        return [$dt->format('Y-m-d'), $dt->format('H:i')];
    } catch (Throwable $e) {
        $tz = new DateTimeZone('Asia/Tokyo');
        $now = new DateTimeImmutable('now', $tz);
        return [$now->format('Y-m-d'), '00:00'];
    }
}

/**
 * タイトルからタグ（カテゴリ）を複数抽出する
 * 例: "散歩した[health][work]" -> tags=["health","work"], display_title="散歩した"
 * [] が無い場合: tags=["未分類"]
 *
 * @return array{tags: string[], display_title: string}
 */
function parse_title_tags(string $title): array {
    $tags = [];

    if (preg_match_all('/\[([^\[\]]+)\]/u', $title, $m)) {
        foreach ($m[1] as $raw) {
            $t = trim((string)$raw);
            if ($t !== '') $tags[] = $t;
        }
    }

    // 重複排除（順序は維持）
    $uniq = [];
    foreach ($tags as $t) {
        if (!in_array($t, $uniq, true)) $uniq[] = $t;
    }
    $tags = $uniq;

    if (count($tags) === 0) {
        $tags = ['未分類'];
        $display = $title;
    } else {
        // 表示用タイトル：全ての [xxx] を除去してトリム
        $display = trim((string)preg_replace('/\s*\[[^\[\]]+\]\s*/u', ' ', $title));
        if ($display === '') $display = $title;
    }

    return ['tags' => $tags, 'display_title' => $display];
}
