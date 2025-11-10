# 同期ブロック（Synced Patterns）移行機能実装計画

## 概要

WP Single Post Migratorに同期ブロック（WordPress 6.3以降のSynced Patterns、旧Reusable Blocks）のエクスポート/インポート機能を追加する実装計画書。

## 機能要件

### エクスポート機能
- サイト内の全同期パターンをJSON形式でエクスポート
- パターン内で使用されている画像の自動収集・エクスポート
- 既存のZIPファイル構造への統合

### インポート機能
- JSON形式の同期パターンの復元
- パターン内画像URLの自動書き換え
- 既存パターンとの重複処理（上書き/スキップ選択）

### UI要件
- 既存のAdmin UIに「同期パターン」タブを追加
- エクスポート/インポートの進捗表示
- エラーハンドリングと結果表示

## 技術仕様

### データ構造

#### ZIPファイル構造
```
export.zip
├── post-data.xml          (既存)
├── images/               (既存)
│   ├── image1.jpg
│   └── image2.png
└── synced-patterns/      (新規)
    ├── pattern-1.json
    ├── pattern-2.json
    └── pattern-images/
        ├── pattern-img-1.jpg
        └── pattern-img-2.png
```

#### 同期パターンJSON形式
```json
{
  "id": 123,
  "title": "パターン名",
  "content": "<!-- wp:paragraph --><p>パターン内容</p><!-- /wp:paragraph -->",
  "status": "publish",
  "type": "wp_block",
  "meta": {
    "wp_pattern_sync_status": "full"
  },
  "categories": ["featured", "text"],
  "keywords": ["sample", "content"],
  "images": [
    {
      "original_url": "http://example.com/wp-content/uploads/image.jpg",
      "filename": "image.jpg",
      "alt_text": "画像の説明"
    }
  ]
}
```

## 実装ファイル構成

### 1. 新規ファイル

#### `includes/class-synced-pattern-handler.php`
同期パターンの処理を担当するメインクラス
```php
class IPBMFZ_Synced_Pattern_Handler
{
  // エクスポート用メソッド
  public function export_synced_patterns()
  public function collect_pattern_images($patterns)
  public function generate_pattern_json($pattern)

  // インポート用メソッド
  public function import_synced_patterns($json_files, $image_map)
  public function parse_pattern_json($json_content)
  public function create_or_update_pattern($pattern_data)

  // ユーティリティメソッド
  public function get_all_synced_patterns()
  public function extract_images_from_pattern_content($content)
  public function validate_pattern_data($data)
}
```

### 2. 既存ファイル修正

#### `includes/class-post-exporter.php`
- `export_post_with_media()`メソッドに同期パターンエクスポート処理を追加
- `collect_post_images()`メソッドを拡張してパターン画像も収集

#### `includes/class-post-importer.php`
- `import_post_with_media()`メソッドに同期パターンインポート処理を追加
- 画像URLリライト機能をパターンコンテンツにも適用

#### `includes/admin/class-admin-ui.php`
- 新しい「同期パターン」タブを追加
- エクスポート/インポートUIコントロールを追加

#### `includes/admin/ajax-handlers.php`
- `handle_export_synced_patterns()`エンドポイント追加
- `handle_import_synced_patterns()`エンドポイント追加

## 実装スケジュール

### Phase 1: コアロジック実装（3-4時間）
1. **Synced Pattern Handlerクラス作成**
   - 基本的なCRUD操作
   - JSON生成/解析機能
   - 画像収集ロジック

2. **エクスポート機能実装**
   - 既存のPost Exporterクラスとの統合
   - ZIP構造の拡張
   - パターンデータの収集

### Phase 2: インポート機能実装（2-3時間）
1. **インポート処理実装**
   - JSON解析とデータ復元
   - 画像URLリライト
   - 重複処理ロジック

2. **既存コードとの統合**
   - Post Importerクラスの拡張
   - Block Updaterとの連携

### Phase 3: UI実装（2-3時間）
1. **Admin UI拡張**
   - 新しいタブの追加
   - コントロール要素の実装
   - 進捗表示機能

2. **AJAX処理実装**
   - 新しいエンドポイントの追加
   - エラーハンドリング
   - レスポンス形式の統一

### Phase 4: テスト・検証（2-3時間）
1. **機能テスト**
   - エクスポート/インポートの動作確認
   - 画像処理の検証
   - エラーケースの確認

2. **統合テスト**
   - 既存機能との干渉チェック
   - パフォーマンステスト
   - クロスサイト移行テスト

## 実装上の考慮事項

### セキュリティ
- JSON入力の適切なサニタイゼーション
- ファイルアップロード時のバリデーション強化
- 権限チェック（`edit_theme_options`capability）

### パフォーマンス
- 大量のパターンを扱う際のメモリ使用量最適化
- 画像処理のバッチ処理化
- プログレス表示によるUX向上

### 互換性
- WordPress 5.9+ での動作保証
- 既存のReusable Blocksとの下位互換性
- テーマ・プラグイン依存の適切な警告表示

### エラーハンドリング
- パターンインポート時の部分失敗対応
- 依存プラグインが不足している場合の警告
- ロールバック機能（必要に応じて）

## 技術的制約と対応策

### 制約1: プラグイン依存ブロック
**対応策:** インポート前にブロック依存性をチェックし、不足プラグインを警告

### 制約2: テーマ依存スタイル
**対応策:** スタイル情報は移行対象外であることをユーザーに明示

### 制約3: パターン間の依存関係
**対応策:** パターンのインポート順序を最適化し、依存関係エラーを最小化

## 将来の拡張可能性

### 機能拡張
- 選択的パターンエクスポート（カテゴリ別、個別選択）
- パターンプレビュー機能
- 一括パターン管理機能

### 技術改善
- GraphQL APIを使用したより効率的なデータ取得
- WebP等の次世代画像フォーマット対応
- クラウドストレージとの直接連携

## 実装完了後のテスト項目

### 基本動作テスト
- [ ] 同期パターンのエクスポートが正常に動作する
- [ ] エクスポートされたZIPファイルに適切な構造でパターンデータが含まれる
- [ ] パターンのインポートが正常に動作する
- [ ] インポート後のパターンが正しく表示される

### 画像処理テスト
- [ ] パターン内の画像が正しく収集される
- [ ] インポート時に画像URLが適切にリライトされる
- [ ] 重複画像の処理が正常に動作する

### UI/UXテスト
- [ ] 新しいタブが正しく表示される
- [ ] エクスポート/インポートの進捗が適切に表示される
- [ ] エラー時に分かりやすいメッセージが表示される

### 互換性テスト
- [ ] 既存の投稿エクスポート/インポート機能に影響しない
- [ ] 画像のみインポート機能に影響しない
- [ ] 異なるWordPressバージョン間でのパターン移行が動作する

この実装計画に沿って段階的に開発を進めることで、安全かつ効率的に同期ブロック移行機能を追加できます。