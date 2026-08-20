<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * WordPress 7.1 Client-side media processing の finalize で WP-Cron を登録する
 *
 * Gutenberg / Core の POST /wp/v2/media/{id}/finalize 完了後に、
 * サーバ側アップロード時と同じ WebP / AVIF 生成と自動最適化を積む。
 *
 * @since 2.2.0
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}



add_action( 'rest_after_client_side_media_finalize', array( Schedule_Cron::class, 'on_client_side_media_finalize' ), 10, 4 );


// Gutenberg プラグインが無い WP 7.1 Core では上記アクションが無いため、
// finalize エンドポイントからの wp_generate_attachment_metadata (context=update) をフォールバックにする。
add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id, $context ) {

	if( 'update' !== $context ) {
		return $metadata;
	}

	if( ! Schedule_Cron::is_finalize_request() ) {
		return $metadata;
	}

	Schedule_Cron::schedule(
		$attachment_id,
		$metadata,
		array( 'from_finalize' => true )
	);

	return $metadata;

}, 20, 3 );




// END of the File
