<?php

/**
 * Plugin Name:       Image Quality Control | Still BE
 * Description:       Keep image quality while making files smaller for faster pages - just install and you're set, with automatic WebP and AVIF.
 * Version:           2.2.0
 * Requires at least: 5.8.1
 * Requires PHP:      7.4
 * Author:            Daisuke Yamamoto
 * Author URI:        https://web.analogstd.com/
 * License:           GPL2
 * Text Domain:       still-be-image-quality-control
 */

namespace StillBE\Plugin\ImageQualityControl;




// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// Constant Values
require_once( __DIR__. '/settings/constant-values.php' );




// Load Translate File
add_action( 'init', function() {
	load_plugin_textdomain( 'still-be-image-quality-control' );
}, 1 );





// 関数読込
foreach( glob( __DIR__. '/includes/function/*.php' ) as $file ) {
	require_once( $file );
}


// コアの画像処理系に適応するフィルター
require_once( __DIR__. '/includes/add-filters.php' );

// メタデータ生成時に alt を exif から自動設定する
require_once( __DIR__. '/includes/operate-exif.php' );


// GD と Imagick の Class ファイルを読み込む
if( file_exists( ABSPATH. WPINC. '/class-wp-image-editor.php' ) ) {
	require_once( ABSPATH. WPINC. '/class-wp-image-editor.php' );
}
if( file_exists( ABSPATH. WPINC. '/class-wp-image-editor-gd.php' ) ) {
	require_once( ABSPATH. WPINC. '/class-wp-image-editor-gd.php' );
}
if( file_exists( ABSPATH. WPINC. '/class-wp-image-editor-imagick.php' ) ) {
	require_once( ABSPATH. WPINC. '/class-wp-image-editor-imagick.php' );
}


// trait 読込
foreach( glob( __DIR__. '/includes/trait/*.php' ) as $file ) {
	require_once( $file );
}


// WP の組込 Class を継承した GD 用エディタ Class
if( class_exists( 'WP_Image_Editor_GD' ) ) {
	require_once( __DIR__. '/includes/class/class-image-editor-gd.php' );
}

// WP の組込 Class を継承した Imagick 用エディタ Class
if( class_exists( 'WP_Image_Editor_Imagick' ) ) {
	require_once( __DIR__. '/includes/class/class-image-editor-imagick.php' );
	// Image_Editor_Imagick 定義後に可逆オプション用フィルターを登録
	require_once( __DIR__. '/includes/filter/filter-imagick-png-lossless-options.php' );
}

// class 読込
foreach( glob( __DIR__. '/includes/class/*.php' ) as $file ) {
	require_once( $file );
}




// 管理画面での通知表示を初期化
Admin_Notice::init();
Auto_Optimize_Notice::init();


// WP-Cron のバックグラウンド処理 (WebP 自動生成 / SSIM 自動最適化) を初期化
Cron_Jobs::init();
Schedule_Cron::init();


// 管理画面操作用 REST API (stillbe-iqc/v1)
Rest_Api::init();


// バージョンアップ時のマイグレーション (通常は get_option + 文字列比較のみ)
add_action( 'plugins_loaded', __NAMESPACE__. '\\stillbe_iqc_maybe_upgrade', 5 );


// 有効化の時に AVIF / WebP 置換用の htaccess を追加する
register_activation_hook( __FILE__, function() {
	$enable_webp = (bool) apply_filters( 'stillbe_image_quality_control_enable_webp', STILLBE_IQ_ENABLE_WEBP, 'activate' );
	$enable_avif = (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', STILLBE_IQ_ENABLE_AVIF, 'activate' );
	if( $enable_webp || $enable_avif ) {
		_stillbe_iqc_htaccess_webp( null );
	}
	update_option( STILLBE_IQ_DB_VERSION_OPTION, STILLBE_IQ_VERSION, true );
} );

// 無効化の時に AVIF / WebP 置換用の htaccess を削除する
register_deactivation_hook( __FILE__, function() {
	_stillbe_iqc_htaccess_webp( false );
} );


if( is_admin() ) {
	$GLOBALS['sb-iqc-setting'] = new Setting();
}


// 設定を適応するフィルター
require_once( __DIR__. '/includes/apply-filter-settings.php' );




// END of the File



