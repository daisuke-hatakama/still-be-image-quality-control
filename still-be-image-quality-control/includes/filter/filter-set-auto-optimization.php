<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * メタデータ更新時に自動最適化の処理を WP-Cron に登録するフィルター
 * 　・WebP の自動生成
 * 　・圧縮率の自動調整
 *
 * Client-side media processing (WP 7.1) では初回 create 時点で sizes が揃わないため
 * 登録せず、rest_after_client_side_media_finalize 側で同じ処理を積む。
 *
 * @since 2.0.0
 * @since 2.2.0 Client-side の create では WP-Cron を予約しない
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}



// WebP / AVIF の自動生成 / SSIM 自動最適化 の WP-Cron 登録
// @since 2.0.0
add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id, $context ) {

	// アップロード時のみ（画像編集・再生成などでの無限登録を防止）
	if( 'create' !== $context ) {
		return $metadata;
	}

	// Client-side は POST /media/{id}/finalize までサブサイズが揃わない
	if( Schedule_Cron::should_defer_until_finalize() ) {
		return $metadata;
	}

	Schedule_Cron::schedule( $attachment_id, $metadata );

	return $metadata;

}, 10, 3 );




// END of the File
