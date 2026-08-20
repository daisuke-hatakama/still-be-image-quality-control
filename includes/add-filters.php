<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * プラグインの各種フィルター処理を管理するファイル
 * 
 * このファイルでは以下のフィルター処理を提供します：
 * 1. 画像エディタの拡張クラスの追加
 * 2. WebP画像生成の制御
 * 3. メディアライブラリのカスタマイズ
 * 4. 画像キャッシュクリア機能
 * 5. その他の補助的な処理
 * 
 * @package StillBE\Plugin\ImageQualityControl
 * @since 1.0.0
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




/**
 * 画像エディタの拡張クラスを追加
 * 
 * WordPressの標準画像エディタを拡張し、
 * カスタムの画像処理機能を追加します。
 * 
 * @since 1.0.0
 */
require_once( __DIR__. '/filter/filter-add-extends-editor-classes.php' );


/**
 * WebP画像生成の制御
 * 
 * Image Editorのmake_subsizeメソッド以外のタイミングで
 * WebP画像を生成する機能を提供します。
 * 
 * @since 1.0.0
 */
require_once( __DIR__. '/filter/filter-make-webp-without-subsize-method.php' );


/**
 * メディアライブラリのカスタマイズ
 * 
 * メディアライブラリのリスト表示に
 * カスタム列を追加します。
 * 
 * @since 1.0.0
 */
require_once( __DIR__. '/filter/filter-add-columns-to-media-library.php' );


/**
 * 画像キャッシュクリア機能
 * 
 * 画像URLにキャッシュクリア用の
 * クエリ文字列を追加する機能を提供します。
 * 
 * @since 1.0.0
 */
require_once( __DIR__. '/filter/filter-add-image-cache-clear-query.php' );


/**
 * その他の補助的な処理
 * 
 * プラグインの動作に必要な
 * その他の補助的な処理を提供します。
 * 
 * @since 1.0.0
 */
require_once( __DIR__. '/filter/filter-other-supplementals.php' );


/**
 * 巨大な元画像の削除
 * 
 * スケーリングされた画像が生成された際に
 * 巨大な元画像を自動的に削除する機能を提供します。
 * 
 * @since 1.0.0
 */
require_once( __DIR__. '/filter/filter-remove-large-original-image.php' );


/**
 * バックグラウンド処理の WP-Cron 登録
 * 
 * メタデータ生成時に WebP の自動生成と
 * SSIM による自動最適化を WP-Cron に登録します。
 * 
 * @since 2.0.0
 */
require_once( __DIR__. '/filter/filter-set-auto-optimization.php' );
require_once( __DIR__. '/filter/filter-set-auto-optimization-avif.php' );


/**
 * PNG 可逆時の Imagick エンコードオプションは
 * class-image-editor-imagick.php 読込後に登録する
 * (Image_Editor_Imagick が未定義の時点ではコールバックを置けない)
 *
 * @since 2.1.1
 * @see stillbe-image-quality-control.php
 */




// END of the File



