=== Import Post Block Media from ZIP ===
Contributors: (your-username)
Tags: media, import, zip, blocks, gutenberg, lazyblocks
Requires at least: 5.9
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress記事ページに対して、ZIP形式でまとめられた画像ファイルを一括アップロードし、記事内のブロックエディタコンテンツ（コアブロック・LazyBlocksカスタムブロック）およびメタフィールド（カスタムフィールド）と自動的に紐付けることで、ローカル環境からステージング環境への記事移行を効率化する

== Description ==

**Import Post Block Media from ZIP** は、WordPress記事の画像移行を効率化するプラグインです。

= 主な機能 =

* **ZIP一括アップロード**: 複数の画像をZIPファイルで一括アップロード
* **自動ブロック更新**: ファイル名に基づいて画像ブロックを自動更新
* **メタフィールド対応**: ACFやカスタムフィールドの画像データも自動更新
* **ネストしたJSON対応**: 複雑な構造の画像データも再帰的に処理
* **JSON安全性確保**: ブロックエディタエラーを防ぐ安全なJSONエンコーディング
* **重複管理**: 既存ファイルとの重複を自動処理
* **サブフォルダ対応**: ZIP内のサブフォルダも自動認識
* **複数形式対応**: JPG, PNG, GIF, WebP, SVG に対応

= 使用場面 =

* ローカル環境からステージング環境への記事移行
* 大量の画像を含む記事のインポート
* LazyBlocksやACFを使用したカスタムブロックの画像移行
* メディアファイルの一括置換
* 開発環境でのコンテンツ同期

= 対応ブロック =

* 画像ブロック (core/image)
* ギャラリーブロック (core/gallery)
* LazyBlocksカスタムブロック (lazyblock/*)
* ACFフィールド（画像、ギャラリー、リピーターフィールド内の画像）
* その他プラグインのメタフィールド（JSON形式の画像データ）

= システム要件 =

* WordPress 5.9以上（ブロックエディタ必須）
* PHP 7.4以上
* PHP ZipArchive拡張モジュール

== Installation ==

1. プラグインファイルを `/wp-content/plugins/import-post-block-media-from-zip/` ディレクトリにアップロード
2. WordPress管理画面でプラグインを有効化
3. 記事編集画面のサイドバーに「Import Media from ZIP」セクションが表示されます

== Frequently Asked Questions ==

= どのファイル形式に対応していますか？ =

JPG, JPEG, PNG, GIF, WebP, SVG に対応しています。

= ZIPファイルのサイズ制限はありますか？ =

PHPの `upload_max_filesize` 設定に従います。推奨は10MB以下です。

= 既存のファイルと同名の場合はどうなりますか？ =

既存ファイルに親記事が設定されていない場合は再利用し、設定済みの場合は新しいファイル名で保存されます。

= 処理に失敗した場合はどうなりますか？ =

一時ファイルは自動削除され、エラーメッセージが表示されます。記事内容は変更されません。

= LazyBlocksプラグインに対応していますか？ =

はい。LazyBlocksで作成されたカスタムブロックの画像データ（JSON形式）も自動的に更新されます。タイトル内の改行文字も正しく保持されます。

= ACFやその他のメタフィールドに対応していますか？ =

はい。Advanced Custom Fields（ACF）やその他のプラグインで作成されたメタフィールドに保存された画像データも自動的に更新されます。JSON形式、URL-エンコードされたJSON、ネストした配列構造にも対応しています。

= ブロックエディタで "not a valid JSON response" エラーが発生することはありますか？ =

このプラグインは安全なJSONエンコーディングとHTMLサニタイゼーションを実装しているため、ブロックエディタでのJSON関連エラーを防ぎます。LazyBlocksの複雑なデータ構造も安全に処理されます。

== Screenshots ==

1. 記事編集画面のメタボックス
2. ZIPファイル選択とアップロード
3. 処理進行状況の表示
4. インポート結果の詳細表示

== Changelog ==

= 1.0.0 =
* 初回リリース
* 基本的なZIPインポート機能
* 画像・ギャラリーブロック対応
* LazyBlocksカスタムブロック対応
* メタフィールド（ACF等）対応
* ネストしたJSON構造の再帰的処理
* 改行文字保護機能
* JSON安全性確保（ブロックエディタエラー防止）
* 重複ファイル管理
* 進行状況表示

== Upgrade Notice ==

= 1.0.0 =
初回リリースです。

== Technical Details ==

= セキュリティ =

* 適切な権限チェック
* Nonce検証
* ファイルタイプ検証
* SQLインジェクション対策

= パフォーマンス =

* 一時ファイルの自動削除
* メモリ効率的なファイル処理
* 大容量ファイルの警告

= 開発者向け =

* LazyBlocksプラグイン完全対応
* メタフィールド（ACF、カスタムフィールド）対応
* JSON形式画像データの解析・更新
* 再帰的なネストデータ処理
* 安全なJSONエンコーディング
* 改行文字保護機能
* HTMLサニタイゼーション
* 詳細なログ出力
* 包括的なエラーハンドリング

== Support ==

サポートが必要な場合は、GitHubリポジトリでIssueを作成してください。

== License ==

このプラグインはGPLv2以降のライセンスの下で配布されています。