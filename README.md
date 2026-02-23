# Simple PHP Diary

SQLite を使用したシンプルな日記システムです。

## 特徴

- Markdown 対応
- タグ対応（タイトル中の `[tag]` を複数認識）
- 管理画面はパスワード認証
- パスワードは `password_hash()` でハッシュ保存
- 左側一覧は 10 件ずつページング
- 共通CSS分離済み

---

# ディレクトリ構成
```​
diary/  
├── index.php  
├── edit.php  
├── db.php  
├── css/  
│     └── style.css  
├── data/ (自動生成)  
└── vendor/ (composer install 後に生成)  
```

---
# 動作環境
- PHP 8.0 以上推奨
- SQLite3 有効
- Apache（mod_php または php-fpm）
- Composer

---
# インストール手順
## 1.ファイルを配置
DocumentRoot 以下に配置します。
例：/var/www/html/diary

---
## 2.Composer で Markdown ライブラリをインストール
```​
cd diary
composer require league/commonmark
```

`vendor/` ディレクトリが生成されます。

---

## 3.data ディレクトリの権限設定
```​
mkdir -p data
chmod 755 data
```​

Apache が書き込み可能である必要があります。
必要に応じて：
```​
chown www-data:www-data data
```​

（環境に応じて変更してください）
---
## 4.管理パスワードを設定
### ハッシュを生成
```​
php -r 'echo password_hash("あなたのパスワード", PASSWORD_DEFAULT) . PHP_EOL;'
```​

出力された文字列をコピー。

---
### `db.php` を編集
```​
const ADMIN_PASSWORD_HASH = '$2y$10$ここに貼り付ける';
```​

⚠ 平文のパスワードは絶対に書かないでください。

---

## 5.ブラウザでアクセス

閲覧ページ：http:/your-domain/pdiary/index.php


管理ページ：http://your-domain/pdiary/edit.php


---

# タグ（カテゴリ）仕様

タイトルに `[tag]` を含めるとタグとして認識されます。

例：散歩した[health][life]


- タグは複数指定可能
- タグでフィルタ可能
- タグが無い場合は「未分類」

---

# Markdown 仕様

本文は Markdown で記述できます。


---

# ページング仕様

- 左側一覧は 10 件表示
- 「← 新しい」「古い →」で移動
- タグフィルタと連動

---

# セキュリティ対策

実装済み：

- PDO プリペアドステートメント（SQLインジェクション対策）
- CSRF トークン
- パスワードはハッシュ保存
- セッション固定攻撃対策（`session_regenerate_id()`）
- Markdown の unsafe HTML 無効化

---

# ⚠ 本番環境での推奨設定

## 1.SQLite を公開領域外へ移動（推奨）

例：
```​
/var/www/diary
/var/lib/diary-data/diary.sqlite
```​

`db.php` のパスを変更してください。

---

## 2.data ディレクトリを直接アクセス不可にする

`data/` に `.htaccess` を置く：

Require all denied


---

## 3. HTTPS を使用する

本番公開時は HTTPS を必須にしてください。

---

# パスワード変更方法

1. 新しいハッシュを生成
2. `ADMIN_PASSWORD_HASH` を差し替え
3. 完了

---

# カスタマイズ

共通CSSは：css/style.css

を編集してください。

---

# ライセンス

MIT License
Copyright (c) 2025 Yoshiyuki Kido

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.