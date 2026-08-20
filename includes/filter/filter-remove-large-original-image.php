<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * メタデータ更新時に巨大な元画像を削除するフィルター
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}



// メタデータ更新時に巨大な元画像を削除する
add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id ) {
    
	// Load Settings
	$settings   = get_option( Setting::SETTING_NAME, array() );
	$is_enabled = isset( $settings['toggle']['delete-original-large'] ) ?
	              (bool) $settings['toggle']['delete-original-large'] :
	              STILLBE_IQ_ENABLE_DELETE_ORIGINAL_LARGE;

	// 設定が無効の場合は何もしない
	if( ! $is_enabled ) {
		return $metadata;
	}

	// メタデータが空の場合は何もしない
	if( empty( $metadata ) || empty( $metadata['file'] ) ) {
		return $metadata;
	}

	// 添付ファイルの情報を取得
	$attachment = get_post( $attachment_id );
	if( ! $attachment || ! preg_match( '/^image\//', $attachment->post_mime_type ) ) {
		return $metadata;
	}

	// アップロードディレクトリのパスを取得
	$upload_dir = wp_upload_dir();
	$base_dir   = $upload_dir['basedir'];

	// スケーリングされた画像のパスを取得
	$scaled_file = path_join( $base_dir, $metadata['file'] );

	// 現在のファイルに -scaled が付いていない場合は処理しない
	if( ! preg_match( '/-scaled\.([^.]+)$/', $scaled_file ) ) {
		return $metadata;
	}

	// 元画像のパスを取得（-scaled を除去）
	$ext = pathinfo( $scaled_file, PATHINFO_EXTENSION );
	$original_file = preg_replace( '/-scaled\.'. $ext .'$/', '.'. $ext, $scaled_file );

	// 元画像が存在し、スケーリングされた画像と異なる場合のみ削除
	if( file_exists( $original_file ) && $original_file !== $scaled_file ) {
		@unlink( $original_file );
	}

	// 元画像のWebPバージョンも削除
	$original_webp = preg_replace( '/-scaled\.([^.]+)$/', '.$1.webp', $scaled_file );
	if( file_exists( $original_webp ) ) {
		@unlink( $original_webp );
	}

	// 元画像を削除したため 'original_image' キーを除去する
	// (残したままだと wp_get_original_image_path() が削除済みファイルを指してしまう)
	unset( $metadata['original_image'] );

	return $metadata;

}, 20, 2 );  // 優先度を20に設定して、サブサイズ生成後に実行されるようにする



// END of the File 