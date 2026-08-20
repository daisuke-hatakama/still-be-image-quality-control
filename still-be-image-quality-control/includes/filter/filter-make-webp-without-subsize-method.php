<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * Image Editor の make_subsize メソッド以外の時に WebP を追加するフィルター
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// アップロード前に安全なファイル名に変換する
// ファイル保存前に名前を確定させることで、保存後のコピー/削除を不要にする
// @since 2.0.0  wp_handle_upload でのコピーによるリネームから移行
add_filter( 'wp_handle_upload_prefilter', function( $file ) {

	if( empty( $file['name'] ) ) {
		return $file;
	}

	// Deny a Multibyte Filename
	if( ! apply_filters( 'stillbe_image_quality_control_convert_safename', true ) ) {
		return $file;
	}

	// 対象は画像ファイルのみ
	if( ! preg_match( '/^(.*?)(\.(?:jpe?g|png|gif|avif|webp))$/i', $file['name'], $m ) ) {
		return $file;
	}

	// 半角英数字と ._- 以外が含まれる場合のみ置換する
	if( ! preg_match( '/[^\.\-_a-zA-Z0-9]/', $m[1] ) ) {
		return $file;
	}

	$now  = wp_date( 'YmdHis' );
	$hash = substr( md5( $m[1] ), 7, 8 );

	$file['name'] = "{$now}-{$hash}{$m[2]}";

	return $file;

} );




// アップロード時、オリジナルサイズの画像のWebPを生成する
add_filter( 'wp_handle_upload', function( $upload ) {

	// 画像 (image/*) 以外は何もしない
	// Mime Type は wp_get_image_editor 関数で行われる

	// Client-side の sideload はサムネイル単位で呼ばれるため、ここでは作らない。
	// 代替配信は finalize 後の WP-Cron (generate_webp / generate_avif / auto_optimize) に任せる。
	if( Schedule_Cron::is_sideload_request() ) {
		return $upload;
	}

	// 自動最適化が有効な場合、WebP 生成は auto_optimize に一本化する
	$settings = get_option( Setting::SETTING_NAME, array() );
	$is_auto  = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;
	if( $is_auto ) {
		return $upload;
	}

	// Image Editor
	$editor = wp_get_image_editor( $upload['file'] );
	if( is_wp_error( $editor ) ) {
		// This image cannot be edited.
		return $upload;
	}

	// WebP ではスキップ
	$_mime = wp_get_image_mime( $upload['file'] );
	if( 'image/webp' === $_mime || 'image/avif' === $_mime ) {
		return $upload;
	}

	// 画像をロード
	$editor->load();

	// WebP 画像を生成
	$editor->_set_mk_size( 'original' );
	add_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1, 2 );
	add_filter( 'wp_image_resize_identical_dimensions', '__return_true' );
	if( stillbe_iqc_should_generate_delivery_webp( $_mime, $upload['file'] ) ) {
		$editor->make_webp( "{$upload['file']}.webp", array( 'size_name' => 'original' ) );
	}
	if( stillbe_iqc_should_generate_delivery_avif( $_mime, $upload['file'] ) ) {
		$editor->make_avif( "{$upload['file']}.avif", array( 'size_name' => 'original' ) );
	}
	remove_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1 );
	remove_filter( 'wp_image_resize_identical_dimensions', '__return_true' );

	// $editor を削除
	$editor = null;
	unset( $editor );

	// フィルターの値は変更せず返す
	return $upload;

} );




// 大きな画像の自動リサイズ時に -scaled ファイルの WebP を生成する
add_filter( 'update_attached_file', function( $scaled_file ) {

	// -scaled ファイル以外はスキップ
	// (このフィルターは添付ファイルパスの更新すべてで呼ばれるため対象を限定する)
	if( ! preg_match( '/-scaled\.[^.\/]+$/i', (string) $scaled_file ) ) {
		return $scaled_file;
	}

	// 自動最適化が有効な場合、WebP 生成は auto_optimize に一本化する
	$settings = get_option( Setting::SETTING_NAME, array() );
	$is_auto  = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;
	if( $is_auto ) {
		return $scaled_file;
	}

	// Image Editor
	$editor = wp_get_image_editor( $scaled_file );
	if( is_wp_error( $editor ) ) {
		// This image cannot be edited.
		return $scaled_file;
	}

	// WebP ではスキップ
	$_mime = wp_get_image_mime( $scaled_file );
	if( 'image/webp' === $_mime || 'image/avif' === $_mime ) {
		return $scaled_file;
	}

	// 画像をロード
	$editor->load();

	// WebP 画像を生成
	$editor->_set_mk_size( 'original' );
	add_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1, 2 );
	add_filter( 'wp_image_resize_identical_dimensions', '__return_true' );
	if( stillbe_iqc_should_generate_delivery_webp( $_mime, $scaled_file ) ) {
		$editor->make_webp( "{$scaled_file}.webp", array( 'size_name' => 'original' ) );
	}
	if( stillbe_iqc_should_generate_delivery_avif( $_mime, $scaled_file ) ) {
		$editor->make_avif( "{$scaled_file}.avif", array( 'size_name' => 'original' ) );
	}
	remove_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1 );
	remove_filter( 'wp_image_resize_identical_dimensions', '__return_true' );

	// $editor を削除
	$editor = null;
	unset( $editor );

	// フィルターの値は変更せず返す
	return $scaled_file;

} );




// END of the File



