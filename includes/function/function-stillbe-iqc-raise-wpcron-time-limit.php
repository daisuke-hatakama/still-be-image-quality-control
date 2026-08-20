<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




/**
 * WP-Cron 実行時の max_execution_time を一時的に引き上げる
 *
 * @param string $context 処理識別子 (例: auto-optimize, generate-webp, auto-regen)
 */
function stillbe_iqc_raise_wpcron_time_limit( $context = '' ) {

	$limit = absint( STILLBE_IQ_WPCRON_MAX_EXECUTION_TIME );

	if( $context ) {
		$limit = absint( apply_filters(
			'still-be/image-quality-control/'. $context. '/max-execution-time',
			$limit,
			$context
		) );
	}

	$limit = absint( apply_filters(
		'still-be/image-quality-control/wpcron/max-execution-time',
		$limit,
		$context
	) );

	if( 1 > $limit ) {
		return;
	}

	if( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( $limit );
	}

	if( function_exists( 'ini_set' ) ) {
		@ini_set( 'max_execution_time', (string) $limit );
	}

}




// END

?>
