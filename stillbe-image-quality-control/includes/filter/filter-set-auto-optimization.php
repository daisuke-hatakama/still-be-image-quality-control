<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * メタデータ更新時に自動最適化の処理を WP-Cron に登録するフィルター
 * 　・WebP の自動生成
 * 　・圧縮率の自動調整
 * 
 * @since 2.0.0
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}



// WebP の自動生成 / SSIM 自動最適化 の WP-Cron 登録
// @since 2.0.0
add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id, $context ) {

	// Load Settings
	$settings                 = get_option( Setting::SETTING_NAME, null );
	$is_enabled_webp          = isset( $settings['toggle']['enable-webp'] ) ? $settings['toggle']['enable-webp'] : STILLBE_IQ_ENABLE_WEBP;
	$is_enabled_auto_optimize = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;

	// アップロード時に WebP を同期的に生成するかどうか
	$is_enabled_save_webp_sync = apply_filters( 'stillbe_image_quality_control_enable_save_webp_sync', STILLBE_IQ_ENABLE_SAVE_WEBP_SYNC, $metadata );

	// アップロード時のみ（画像編集・再生成などでの無限登録を防止）
	if( 'create' !== $context ) {
		return $metadata;
	}

	// WebP の自動生成
	// ただし、自動最適化が有効な場合には WebP 生成は最適化処理に任せて個別では作成しない
	if( $is_enabled_webp && ! $is_enabled_auto_optimize && ! $is_enabled_save_webp_sync &&
	      ! wp_next_scheduled( 'stillbe_iqc/generate_webp', [ $attachment_id ] ) ) {
		wp_schedule_single_event(
			time() + 60,
			'stillbe_iqc/generate_webp',
			[ $attachment_id ]
		);
	}

	// 圧縮率の自動調整
	// 第 2 引数は再スケジュール回数 (WebP 生成の有無は実行時に設定から判定する)
	if( $is_enabled_auto_optimize ) {
		Cron_Jobs::schedule_auto_optimize( $attachment_id, 0, 60 );
	}

	return $metadata;

}, 10, 3 );




// END of the File 
