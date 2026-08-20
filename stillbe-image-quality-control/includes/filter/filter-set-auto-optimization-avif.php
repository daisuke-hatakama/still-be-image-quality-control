<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * メタデータ更新時に AVIF 自動生成の WP-Cron を登録するフィルター
 *
 * @since 2.1.0
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}



add_filter( 'wp_generate_attachment_metadata', function( $metadata, $attachment_id, $context ) {

	$settings                 = get_option( Setting::SETTING_NAME, null );
	$is_enabled_avif          = isset( $settings['toggle']['enable-avif'] ) ? $settings['toggle']['enable-avif'] : STILLBE_IQ_ENABLE_AVIF;
	$is_enabled_auto_optimize = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;
	$is_enabled_save_avif_sync = apply_filters( 'stillbe_image_quality_control_enable_save_avif_sync', STILLBE_IQ_ENABLE_SAVE_AVIF_SYNC, $metadata );

	if( 'create' !== $context ) {
		return $metadata;
	}

	if( $is_enabled_avif && ! $is_enabled_auto_optimize && ! $is_enabled_save_avif_sync &&
	      ! wp_next_scheduled( 'stillbe_iqc/generate_avif', [ $attachment_id ] ) ) {
		wp_schedule_single_event(
			time() + 60,
			'stillbe_iqc/generate_avif',
			[ $attachment_id ]
		);
	}

	return $metadata;

}, 10, 3 );




// END of the File
