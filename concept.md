# WordPress プラグイン「Import/Export Post Block Media from ZIP」仕様書

## 1. プラグイン概要

### 1.1 プラグイン名

**Import/Export Post Block Media from ZIP**

### 1.2 目的

WordPress記事ページ（投稿、固定ページ、カスタム投稿タイプ）に対して、以下の包括的な移行機能を提供する：

1. **エクスポート機能**: 記事データをXML形式でエクスポートし、関連画像と共にZIPファイル化
2. **インポート機能**: XMLと画像データを含むZIPファイルから完全な記事を復元
3. **画像のみインポート機能**: ZIP形式でまとめられた画像ファイルを一括アップロードし、既存記事のブロックエディタコンテンツと自動紐付け

これらの機能により、ローカル環境からステージング環境への記事移行、サイト間のコンテンツ移植、バックアップ・復元を効率化する。

### 1.3 動作環境

- WordPress 5.9以上（ブロックエディタ必須）
- PHP 7.4以上
- ZipArchive拡張モジュール有効

---

## 2. 機能要件

### 2.1 UI要素

#### 2.1.1 統合メタボックス「Import/Export Post with Media」

- **配置場所**: 記事編集画面（ブロックエディタ）のサイドバー内
- **表示条件**:
  - 投稿タイプが `post`, `page`, またはカスタム投稿タイプ
  - 既存の記事（下書き・公開済み問わず）
  - 新規記事では機能制限表示（post_id が必要な機能は無効化）
- **構成**: 3つの機能セクションに分割

#### 2.1.2 エクスポートセクション

- **「記事をエクスポート」ボタン**: 現在の記事をZIPファイルとしてエクスポート
- **オプション選択**:
  - ☑ 画像ファイルを含める（デフォルト: ON）
  - ☑ メタフィールドを含める（デフォルト: ON）
- **処理中表示**: 進行状況バーと「エクスポート中...」メッセージ
- **完了表示**: ダウンロードリンクと統計情報

#### 2.1.3 インポートセクション

- **ZIPファイル選択**: ドラッグ&ドロップまたはファイル選択ダイアログ
- **記事置き換えモード**: 現在の記事内容を新しいデータで置き換え（確認ダイアログ付き）
- **オプション選択**:
  - ☑ 画像ファイルをインポート（デフォルト: ON）
  - ☑ メタフィールドをインポート（デフォルト: ON）
- **「記事をインポート」ボタン**: 選択されたZIPファイルからインポート実行

#### 2.1.4 画像のみインポートセクション（従来機能）

- **ZIPファイル選択**: 画像のみを含むZIPファイル
- **「画像のみインポート」ボタン**: 既存記事のブロック更新のみ実行
- **従来機能の継承**: 後方互換性のため独立セクションとして維持

#### 2.1.5 共通UI要素

- **ファイル形式制限**: `.zip` のみ受付
- **ファイルサイズ制限**: PHPの `upload_max_filesize` に準拠（推奨: 10MB以下を警告表示）
- **ドラッグ&ドロップ対応**: 各セクションでファイルのドラッグ&ドロップをサポート
- **処理中インジケーター**:
  - アップロード中: プログレスバーと進捗メッセージ
  - 処理完了: 成功通知と詳細結果表示
- **結果表示**:
  - エクスポート: ファイルサイズ、画像数、ダウンロードリンク
  - インポート: 記事置き換え情報、画像数、ブロック更新数
  - 画像のみ: インポート画像数、更新ブロック数

---

### 2.2 コア機能

#### 2.2.1 エクスポート機能

**処理フロー:**

1. **記事データの収集**
   - 記事のメタデータ（タイトル、コンテンツ、投稿日時、ステータス等）を取得
   - オプションに応じてメタフィールド（ACF、カスタムフィールド）を収集

2. **XML生成**
   - WordPress WXR（eXtended RSS）形式でXMLを生成
   - DOMDocumentを使用した安全なXML構築
   - メタフィールドデータの完全保持

3. **画像収集**
   - ブロック内の画像URL解析（`core/image`, `core/gallery`, `lazyblock/*`）
   - メタフィールド内の画像データ解析（JSON形式、ネストした構造も対応）
   - 画像ファイルの物理的コピー

4. **ZIPパッケージ化**
   - 一時ディレクトリの作成: `wp-content/uploads/temp-export-{post_id}-{timestamp}/`
   - XMLファイルとimagesディレクトリを含むZIP作成
   - ブラウザダウンロード用のURLの生成

**エクスポートされるファイル構造:**

```
export-{post-title}-{post-id}-{timestamp}.zip
├── {post-title}-{post-id}.xml  # 記事データ（WXR形式）
└── images/
    ├── image1.jpg
    ├── image2.png
    └── image3.webp
```

**WXR形式の例:**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:wp="http://wordpress.org/export/1.2/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/">
  <channel>
    <title>Site Title</title>
    <wp:wxr_version>1.2</wp:wxr_version>
    <item>
      <title>記事タイトル</title>
      <content:encoded><![CDATA[記事コンテンツ]]></content:encoded>
      <wp:post_id>123</wp:post_id>
      <wp:post_type>post</wp:post_type>
      <wp:status>publish</wp:status>
      <wp:postmeta>
        <wp:meta_key>_custom_field</wp:meta_key>
        <wp:meta_value><![CDATA[カスタム値]]></wp:meta_value>
      </wp:postmeta>
    </item>
  </channel>
</rss>
```

---

#### 2.2.2 インポート機能

**処理フロー:**

1. **ZIPファイルの展開**
   - 一時ディレクトリへの展開: `wp-content/uploads/temp-import-{timestamp}/`
   - XMLファイルとimagesディレクトリの検出

2. **XML解析**
   - WXR形式およびシンプルXML形式の自動判定
   - 記事データとメタフィールドデータの抽出
   - データサニタイゼーション（XSS対策、SQLインジェクション対策）

3. **記事の置き換え更新**
   - 記事置き換えモード: `wp_update_post()` で既存記事を更新
   - メタフィールドの復元: `update_post_meta()` で各フィールドを設定

4. **画像インポートとURL書き換え**
   - 画像ファイルのメディアライブラリ登録
   - XMLおよび記事コンテンツ内の画像URLを新しいURLに自動書き換え
   - ブロック属性の更新

**XML形式の対応:**

- **WXR形式**: WordPress標準エクスポート形式
- **シンプルXML形式**: 独自の軽量XML形式

**インポートモード:**

- **記事置き換え**: 現在の記事内容を完全に置き換え（確認ダイアログ付き）

---

#### 2.2.3 ZIPファイルのアップロードと展開（共通処理）

**処理フロー:**

1. ZIPファイルを `wp_handle_upload()` で一時ディレクトリにアップロード
2. `ZipArchive` クラスで展開
3. 展開先: `wp-content/uploads/temp-import-{post_id}-{timestamp}/`
4. 処理完了後、一時ディレクトリを削除

**対応ファイル形式:**

- 画像: `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.svg`
- 他のファイル形式は無視（警告表示）

**ZIP構造:**

```
archive.zip
├── image1.jpg
├── image2.png
└── subfolder/
    └── image3.jpg  ← サブフォルダ内も対応
```

**エラーハンドリング:**

- ZIPが破損している場合: エラーメッセージ表示
- 対応形式の画像が0件の場合: 警告表示
- PHP ZipArchive未対応の場合: 管理画面に警告表示

---

#### 2.2.2 メディアライブラリへの登録

**処理内容:**

1. **重複チェック**
   - ファイル名で既存の添付ファイルを検索
   - 検索方法: `wp_postmeta` テーブルの `_wp_attached_file` から検索
2. **重複時の処理（フェーズ1: 自動判定）**

   - 既存ファイルの `post_parent` が未設定（NULL or 0）の場合:
     - `post_parent` を現在の記事IDに更新
     - 既存の attachment_id を使用
   - 既存ファイルの `post_parent` が設定済みの場合:
     - 新規ファイルとしてアップロード（WordPressが自動でファイル名末尾に番号を付与）

3. **新規アップロード処理**

```php
   $attachment_id = wp_insert_attachment([
       'post_title'     => sanitize_file_name(basename($filename, $ext)),
       'post_content'   => '',
       'post_status'    => 'inherit',
       'post_mime_type' => $mime_type,
       'post_parent'    => $post_id,  // 必須
   ], $file_path);
```

4. **メタデータ生成**

```php
   require_once(ABSPATH . 'wp-admin/includes/image.php');
   $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
   wp_update_attachment_metadata($attachment_id, $attach_data);
```

**生成される画像サイズ:**

- サムネイル（thumbnail）
- 中サイズ（medium）
- 大サイズ（large）
- フルサイズ（full）
- テーマやプラグインで定義された追加サイズ

---

#### 2.2.3 記事内ブロックとの紐付け

**処理フロー:**

1. **ブロックデータの取得**

```php
   $blocks = parse_blocks($post->post_content);
```

2. **対象ブロックタイプ**
   - `core/image` - 画像ブロック
   - `core/gallery` - ギャラリーブロック
   - `lazyblock/*` - LazyBlocksプラグインのカスタムブロック
   - **メタフィールド** - ACFやカスタムフィールドに保存された画像データ
3. **画像マッチングロジック**

```
   記事内画像URL: https://local.example.com/wp-content/uploads/2024/01/sample-image.jpg
   ↓ ファイル名を抽出
   マッチング対象: sample-image.jpg
   ↓ ZIP内のファイル名と照合
   ZIP内ファイル: sample-image.jpg
   ↓ マッチング成功
   新しいattachment_idとURLで置換
```

4. **ブロック属性の更新**

   **画像ブロック (`core/image`) の場合:**

```php
   $block['attrs']['id'] = $new_attachment_id;
   $block['attrs']['url'] = wp_get_attachment_url($new_attachment_id);

   // innerHTMLも更新
   $block['innerHTML'] = preg_replace(
       '/class="([^"]*)"/',
       'class="$1 wp-image-' . $new_attachment_id . '"',
       $block['innerHTML']
   );
   $block['innerHTML'] = preg_replace(
       '/src="[^"]*"/',
       'src="' . wp_get_attachment_url($new_attachment_id) . '"',
       $block['innerHTML']
   );
```

**ギャラリーブロック (`core/gallery`) の場合:**

```php
   foreach ($block['innerBlocks'] as &$image_block) {
       if ($image_block['blockName'] === 'core/image') {
           // 上記と同様の処理
       }
   }
```

**LazyBlocksカスタムブロック (`lazyblock/*`) の場合:**

```php
   // URL-エンコードされたJSON形式の画像データを処理
   foreach ($block['attrs'] as $attr_name => &$attr_value) {
       if (is_string($attr_value) && $this->looks_like_json_image_data($attr_value)) {
           $decoded = urldecode($attr_value);
           $image_data = json_decode($decoded, true);

           // 画像URL、ID、サイズ情報を更新
           if (isset($image_data['url'])) {
               $matched_id = $this->find_matching_attachment($filename, $image_map);
               if ($matched_id) {
                   $image_data['id'] = $matched_id;
                   $image_data['url'] = wp_get_attachment_url($matched_id);
                   // タイトルの改行文字は保護
                   $attr_value = urlencode(json_encode($image_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
               }
           }
       }
   }
```

5. **記事コンテンツの保存**

```php
   $new_content = serialize_blocks($blocks);

   // 改行文字の破損を修正（LazyBlocksのタイトルなど）
   $new_content = $this->fix_newline_corruption($new_content);

   wp_update_post([
       'ID'           => $post_id,
       'post_content' => $new_content,
   ]);
```

6. **改行文字保護機能**

WordPress の `serialize_blocks()` 関数は、LazyBlocks のタイトル内の改行文字（`\n`）を `n` に破損させる場合があります。この問題を解決するため、シリアライゼーション後に以下のパターンマッチングで修正します：

- `"季節のゆず庵nコース"` → `"季節のゆず庵\nコース"`
- `"平日限定ランチn御膳"` → `"平日限定ランチ\n御膳"`
- 日本語文字間の `n` → `\n` に修正

7. **メタフィールド処理機能**

ブロック処理の後に、記事のメタフィールド（カスタムフィールド）も処理します：

```php
   // ブロック処理後にメタフィールドを処理
   $meta_updates = $this->process_meta_fields($post_id, $image_map, $results);

   // 対応するメタフィールド形式
   // 1. シンプルな画像フィールド: {"id": 123, "url": "...", "alt": "..."}
   // 2. URL-エンコードされたJSON: %7B%22id%22%3A123%2C%22url%22%3A%22...%22%7D
   // 3. ネストした画像配列: [{"itemImage": {"id": 123, "url": "..."}, "itemName": "..."}]
```

8. **JSON安全性の確保**

LazyBlocksやメタフィールドのJSON形式を処理する際、以下の安全対策を実装：

- 安全なJSONエンコーディングフラグの使用（`JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP`）
- HTMLコンテンツのサニタイゼーション
- JSON検証とエラーハンドリング
- ブロックエディタエラー（"not a valid JSON response"）の防止

**マッチング失敗時の処理:**

- ブロックは変更しない（元のURLを保持）
- 処理サマリーに「マッチングできなかった画像」をリスト表示

---

### 2.3 セキュリティ要件

#### 2.3.1 権限チェック

- 実行可能ユーザー: `edit_post` 権限を持つユーザー
- Nonce検証必須
- AJAX リクエストの検証

#### 2.3.2 ファイルバリデーション

- MIMEタイプチェック: `wp_check_filetype()` 使用
- ファイル名のサニタイズ: `sanitize_file_name()` 使用
- アップロードディレクトリの書き込み権限チェック

#### 2.3.3 SQLインジェクション対策

- すべてのデータベースクエリで `$wpdb->prepare()` 使用

---

## 3. 技術仕様

### 3.1 ファイル構造

```
import-post-block-media-from-zip/
├── import-post-block-media-from-zip.php  # メインファイル
├── includes/
│   ├── class-zip-handler.php             # ZIP処理クラス（共通）
│   ├── class-media-importer.php          # メディアインポートクラス（共通）
│   ├── class-block-updater.php           # ブロック更新クラス（共通）
│   ├── class-post-exporter.php           # 記事エクスポートクラス（新規）
│   ├── class-post-importer.php           # 記事インポートクラス（新規）
│   └── admin/
│       ├── class-admin-ui.php            # 管理画面UI（拡張）
│       └── ajax-handlers.php             # AJAX処理（拡張）
├── assets/
│   ├── js/
│   │   └── admin.js                      # 管理画面用JavaScript（拡張）
│   └── css/
│       └── admin.css                     # 管理画面用CSS（拡張）
├── readme.txt
├── concept.md                            # 仕様書
└── CLAUDE.md                             # 開発者向けガイド
```

### 3.2 主要クラスとメソッド

#### 3.2.1 IPBMFZ_Post_Exporter クラス（新規）

**責務:** 記事データのエクスポートと画像収集

```php
class IPBMFZ_Post_Exporter {
    public function export_post($post_id, $options = array());
    private function generate_post_xml($post, $temp_dir, $options);
    private function generate_wxr_xml($post, $options);
    private function generate_simple_xml($post, $options);
    private function collect_post_images($post, $temp_dir);
    private function extract_images_from_blocks($blocks);
    private function extract_images_from_meta($post_id);
    private function create_export_zip($temp_dir, $post);
}
```

**メソッド詳細:**

- `export_post(int $post_id, array $options): array|WP_Error`
  - オプション: `['include_images' => bool, 'include_meta' => bool, 'export_format' => 'wxr'|'simple']`
  - 戻り値: `['zip_file' => string, 'zip_url' => string, 'image_count' => int]` または `WP_Error`
- `generate_wxr_xml(WP_Post $post, array $options): string`
  - WordPress WXR形式でXMLを生成
- `collect_post_images(WP_Post $post, string $temp_dir): array|WP_Error`
  - ブロックとメタフィールドから画像を収集してコピー

#### 3.2.2 IPBMFZ_Post_Importer クラス（新規）

**責務:** XMLと画像の統合インポート処理

```php
class IPBMFZ_Post_Importer {
    public function import_post_from_zip($file_data, $options = array());
    public function import_images_only($file_data, $post_id);
    private function find_and_parse_xml($extract_path);
    private function parse_wxr_xml($xml_content);
    private function parse_simple_xml($xml_content);
    private function import_post_data($post_data, $meta_data, $options);
    private function sanitize_post_data($post_data);
}
```

**メソッド詳細:**

- `import_post_from_zip(array $file_data, array $options): array|WP_Error`
  - オプション: `['import_mode' => 'create_new'|'replace_current', 'target_post_id' => int]`
  - 戻り値: `['post_id' => int, 'is_new_post' => bool, 'imported_images' => int]` または `WP_Error`
- `find_and_parse_xml(string $extract_path): array|WP_Error`
  - XMLファイルを検出して形式を自動判定・解析
- `import_images_only(array $file_data, int $post_id): array|WP_Error`
  - 従来の画像のみインポート機能（後方互換性）

#### 3.2.3 ZIP_Handler クラス（共通）

**責務:** ZIPファイルのアップロード・展開・削除

```php
class ZIP_Handler {
    public function upload_and_extract($file_data, $post_id);
    public function get_image_files($extract_path);
    public function cleanup($extract_path);
}
```

**メソッド詳細:**

- `upload_and_extract(array $file_data, int $post_id): array|WP_Error`
  - 戻り値: `['extract_path' => string, 'files' => array]` または `WP_Error`
- `get_image_files(string $extract_path): array`
  - 戻り値: 画像ファイルパスの配列
- `cleanup(string $extract_path): bool`
  - 戻り値: 成功時 true

#### 3.2.2 Media_Importer クラス

**責務:** メディアライブラリへの登録・重複チェック

```php
class Media_Importer {
    public function check_existing_attachment($filename);
    public function import_image($file_path, $post_id, $filename);
    private function update_existing_attachment($attachment_id, $post_id);
}
```

**メソッド詳細:**

- `check_existing_attachment(string $filename): int|false`
  - 戻り値: 既存のattachment_id または false
- `import_image(string $file_path, int $post_id, string $filename): int|WP_Error`
  - 戻り値: attachment_id または WP_Error
- `update_existing_attachment(int $attachment_id, int $post_id): bool`
  - 戻り値: 成功時 true

#### 3.2.3 Block_Updater クラス

**責務:** ブロックコンテンツの解析・更新

```php
class Block_Updater {
    public function update_blocks($post_id, $image_map);
    private function update_image_block(&$block, $image_map);
    private function update_gallery_block(&$block, $image_map);
    private function update_lazyblock_images(&$block, $image_map, &$results);
    private function process_meta_fields($post_id, $image_map, &$results);
    private function update_image_data_recursively(&$data, $image_map, &$results);
    private function clean_html_in_json_data(&$data);
    private function fix_newline_corruption($content);
    private function match_image($block, $image_map);
}
```

**メソッド詳細:**

- `update_blocks(int $post_id, array $image_map): array`
  - `$image_map`: `['filename' => attachment_id]` の連想配列
  - 戻り値: `['updated_blocks' => int, 'failed_matches' => array]`
- `update_image_block(array &$block, array $image_map): bool`
  - 戻り値: 更新成功時 true
- `update_lazyblock_images(array &$block, array $image_map, array &$results): int`
  - LazyBlocksカスタムブロックの画像データ更新
  - JSON形式の画像データをパース・更新・再エンコード
  - 戻り値: 更新されたブロック数
- `process_meta_fields(int $post_id, array $image_map, array &$results): int`
  - 記事のメタフィールド（カスタムフィールド）内の画像データを更新
  - ACF、LazyBlocks設定、その他のプラグインのメタフィールドに対応
  - 戻り値: 更新されたメタフィールド数
- `update_image_data_recursively(array &$data, array $image_map, array &$results): bool`
  - ネストした構造の画像データを再帰的に更新
  - ブロック属性とメタフィールドの両方で使用
  - 任意の深さのJSON構造に対応
- `clean_html_in_json_data(array &$data): void`
  - JSON内のHTMLコンテンツをサニタイズ
  - ブロックエディタエラーを防止するため安全な文字エスケープを実行
- `fix_newline_corruption(string $content): string`
  - シリアライズされたブロックコンテンツの改行文字破損を修正
  - 戻り値: 修正されたコンテンツ
- `match_image(array $block, array $image_map): int|false`
  - 戻り値: マッチしたattachment_id または false

#### 3.2.4 Admin_UI クラス

**責務:** 管理画面UIの追加

```php
class Admin_UI {
    public function register_meta_box();
    public function render_meta_box($post);
    public function enqueue_scripts($hook);
}
```

### 3.3 AJAX処理

#### 3.3.1 エクスポート用エンドポイント: `wp_ajax_export_post_with_media`

**リクエスト:**

```javascript
{
    action: 'export_post_with_media',
    post_id: 123,
    nonce: 'xxxxx',
    include_images: '1',
    include_meta: '1'
}
```

**レスポンス（成功時）:**

```json
{
  "success": true,
  "data": {
    "zip_url": "https://example.com/wp-content/uploads/export-title-123-1698765432.zip",
    "post_title": "記事タイトル",
    "image_count": 8,
    "file_size": 2547892,
    "file_size_formatted": "2.4 MB",
    "message": "記事「記事タイトル」をエクスポートしました。画像: 8件, ファイルサイズ: 2.4 MB"
  }
}
```

#### 3.3.2 インポート用エンドポイント: `wp_ajax_import_post_with_media`

**リクエスト:**

```javascript
{
    action: 'import_post_with_media',
    post_id: 123,
    nonce: 'xxxxx',
    zip_file: File object,
    import_mode: 'create_new',
    include_images: '1',
    include_meta: '1'
}
```

**レスポンス（成功時）:**

```json
{
  "success": true,
  "data": {
    "post_id": 456,
    "post_title": "インポートされた記事",
    "post_url": "https://example.com/wp-admin/post.php?post=456&action=edit",
    "is_new_post": true,
    "imported_images": 8,
    "updated_blocks": 12,
    "failed_matches": [],
    "message": "記事を作成し、8件の画像をインポートして12個のブロックを更新しました。"
  }
}
```

#### 3.3.3 画像のみインポート用エンドポイント: `wp_ajax_import_images_only`

**リクエスト:**

```javascript
{
    action: 'import_images_only',
    post_id: 123,
    nonce: 'xxxxx',
    zip_file: File object
}
```

**レスポンス（成功時）:**

```json
{
  "success": true,
  "data": {
    "imported_count": 5,
    "updated_blocks": 5,
    "failed_matches": [],
    "message": "5件の画像をインポートし、5個のブロックを更新しました。"
  }
}
```

**エラーレスポンス（全エンドポイント共通）:**

```json
{
  "success": false,
  "data": {
    "message": "ZIPファイルの展開に失敗しました。"
  }
}
```

### 3.4 データベース操作

**使用テーブル:**

- `wp_posts` - 記事本体と添付ファイル
- `wp_postmeta` - 添付ファイルメタデータ

**重要なメタキー:**

- `_wp_attached_file` - ファイルの相対パス
- `_wp_attachment_metadata` - 画像サイズ情報

**サンプルクエリ:**

```php
// ファイル名から既存の添付ファイルを検索
global $wpdb;
$attachment_id = $wpdb->get_var($wpdb->prepare("
    SELECT post_id
    FROM $wpdb->postmeta
    WHERE meta_key = '_wp_attached_file'
    AND meta_value LIKE %s
    LIMIT 1
", '%' . $wpdb->esc_like($filename)));
```

---

## 4. 処理フロー全体図

### 4.1 エクスポート処理フロー

```
[ユーザー操作]
    ↓
[1] 記事編集画面で「記事をエクスポート」ボタンクリック
    ↓
[2] オプション確認（画像含む・メタフィールド含む）
    ↓
[3] AJAX リクエスト送信
    ↓
[サーバー側処理]
    ↓
[4] Post_Exporter::export_post()
    ├─ 記事データ収集
    ├─ XML生成（WXR形式）
    ├─ 画像収集・コピー
    └─ ZIPファイル作成
    ↓
[5] AJAX レスポンス返却
    ↓
[クライアント側]
    ↓
[6] ダウンロードリンク表示・統計情報表示
```

### 4.2 インポート処理フロー

```
[ユーザー操作]
    ↓
[1] 記事編集画面で「記事をインポート」ボタンクリック
    ↓
[2] ZIPファイル選択・確認ダイアログ
    ↓
[3] AJAX リクエスト送信
    ↓
[サーバー側処理]
    ↓
[4] Post_Importer::import_post_from_zip()
    ├─ ZIP展開・XML検出
    ├─ XML解析（WXR/シンプル形式）
    ├─ 記事作成/更新
    ├─ 画像インポート
    └─ ブロック更新
    ↓
[5] AJAX レスポンス返却
    ↓
[クライアント側]
    ↓
[6] 成功通知・記事編集リンク表示
```

### 4.3 画像のみインポート処理フロー（従来機能）

```
[ユーザー操作]
    ↓
[1] 記事編集画面で「画像のみインポート」ボタンクリック
    ↓
[2] ZIPファイル選択
    ↓
[3] AJAX リクエスト送信
    ↓
[サーバー側処理]
    ↓
[4] Post_Importer::import_images_only()
    ├─ ZIP展開・画像検出
    ├─ Media_Importer::import_multiple_images()
    └─ Block_Updater::update_blocks()
    ↓
[5] AJAX レスポンス返却
    ↓
[クライアント側]
    ↓
[6] 成功通知・ページリロード（オプション）
```

---

## 5. エラーハンドリング

### 5.1 想定エラーとその対処

| エラー状況             | エラーメッセージ                                         | 対処方法                             |
| ---------------------- | -------------------------------------------------------- | ------------------------------------ |
| ZipArchive拡張未対応   | 「このサーバーはZIPファイルの展開に対応していません」    | 管理画面に警告表示、プラグイン無効化 |
| ZIPファイル破損        | 「ZIPファイルが破損しています」                          | ユーザーにファイル再確認を促す       |
| 画像ファイル0件        | 「ZIPファイル内に対応する画像が見つかりませんでした」    | 対応形式をリスト表示                 |
| アップロードサイズ超過 | 「ファイルサイズが上限を超えています」                   | `upload_max_filesize` の値を表示     |
| 書き込み権限なし       | 「アップロードディレクトリへの書き込み権限がありません」 | サーバー管理者に連絡を促す           |
| メモリ不足             | 「処理中にメモリ不足が発生しました」                     | より小さいZIPファイルに分割を提案    |

### 5.2 ログ記録

**ログ出力先:** `wp-content/debug.log` (WP_DEBUG_LOG有効時)

**ログレベル:**

- ERROR: 処理失敗・例外発生
- WARNING: 一部画像のマッチング失敗
- INFO: 処理開始・完了

**ログ例:**

```
[2025-10-15 10:30:00] Import Media from ZIP - INFO: Started import for post ID 123
[2025-10-15 10:30:05] Import Media from ZIP - WARNING: No match found for image: old-image.jpg
[2025-10-15 10:30:10] Import Media from ZIP - INFO: Import completed. 5 images imported, 4 blocks updated.
```

---

## 6. 今後の拡張可能性（フェーズ2以降）

### 6.1 対応ブロックタイプの拡張

- `core/cover` - カバーブロック（背景画像）
- `core/media-text` - メディアとテキストブロック
- カスタムブロック対応

### 6.2 ユーザー選択オプション

- 重複画像の上書き/スキップ選択UI
- 画像サイズの指定（thumbnail/medium/large/full）

### 6.3 一括処理機能

- 複数記事の一括インポート
- カスタム投稿タイプ一覧からの選択

### 6.4 ロールバック機能

- インポート前の状態をバックアップ
- 元に戻すボタンの実装

---

## 7. テストケース

### 7.1 単体テスト

#### ZIP_Handler クラス

- [ ] 正常なZIPファイルの展開
- [ ] 破損したZIPファイルの処理
- [ ] 画像ファイルのみの抽出
- [ ] サブフォルダ内画像の処理
- [ ] 一時ファイルの削除

#### Media_Importer クラス

- [ ] 新規画像のインポート
- [ ] 重複画像の検出
- [ ] post_parentの正しい設定
- [ ] メタデータの生成

#### Block_Updater クラス

- [ ] 画像ブロックの更新
- [ ] ギャラリーブロックの更新
- [ ] ファイル名マッチング
- [ ] マッチング失敗時の処理

### 7.2 統合テスト

- [ ] 記事編集画面でのボタン表示
- [ ] ZIPアップロードから記事更新までの完全フロー
- [ ] 複数画像の一括処理
- [ ] エラー発生時のロールバック

### 7.3 環境テスト

- [ ] WordPress 5.9, 6.0, 6.1, 6.2, 6.3, 6.4での動作確認
- [ ] PHP 7.4, 8.0, 8.1, 8.2での動作確認
- [ ] 主要テーマ（Twenty Twenty-Three等）での動作確認

---

## 8. 開発スケジュール（推定）

### フェーズ1: MVP（2-3日）

- Day 1: ZIP処理とメディアインポート機能
- Day 2: ブロック更新機能
- Day 3: UI実装とテスト

### フェーズ2: 拡張（1-2日）

- カバーブロックなど追加対応
- エラーハンドリング強化

### フェーズ3: 最適化（1日）

- パフォーマンス改善
- ドキュメント作成

---

## 9. 参考資料

- [WordPress Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)
- [wp_insert_attachment()](https://developer.wordpress.org/reference/functions/wp_insert_attachment/)
- [parse_blocks()](https://developer.wordpress.org/reference/functions/parse_blocks/)

---

## 10. 実装時の注意事項

1. **必ず `post_parent` を設定する** - メディアと記事の紐付けの要
2. **ブロック属性の `id` も更新する** - URLだけでは不十分
3. **innerHTML も更新する** - `wp-image-{id}` クラスの追加
4. **一時ファイルは必ず削除する** - ストレージの無駄遣い防止
5. **大容量ZIPは分割を推奨する** - メモリ・タイムアウト対策
6. **Nonce検証を忘れない** - セキュリティの基本
