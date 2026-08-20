<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * Set ".htaccess" to Automatically use AVIF / WebP in Compatible Browsers
 *
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




function _stillbe_iqc_htaccess_webp( $enabled = null ) {

	// Initialize WP_FileSystem
	require_once( ABSPATH. 'wp-admin/includes/file.php' );
	global $wp_filesystem;
	if( WP_Filesystem() ) {
		$file_system = &$wp_filesystem;
	} else {
		wp_die( __( 'WP Filesystem is not available.', 'still-be-image-quality-control' ) );
	}

	// Uploads Directory
	$uploads = wp_upload_dir();
	$up_dir  = $uploads['basedir'];

	// プラグインが追加した .htaccess ブロック（旧: WebP のみ / 新: WebP and AVIF）
	$block_pattern = '|[\n\r]*# Replace to WebP(?: and AVIF)?[\s\S]*?# /Replace to WebP(?: and AVIF)?[\n\r]*|';

	if( null === $enabled ) {
		$enabled = stillbe_iqc_is_delivery_htaccess_enabled();
	}

	if( $enabled ) {

		if( $up_dir ) {
			// File Path
			$up_file   = $up_dir. '/.htaccess';
			$base_file = STILLBE_IQ_BASE_DIR. '/asset/htaccess.tmp';
			if( file_exists( $up_file ) ) {
				$htaccess = $file_system->get_contents( $up_file );
				$base     = $file_system->get_contents( $base_file );
				if( false === $htaccess || false === $base ) {
					wp_die( __( 'Server error. Could not get &quot;.htaccess&quot;.', 'still-be-image-quality-control' ) );
				}
				$htaccess  = preg_replace( $block_pattern, '', $htaccess );
				$htaccess .= "\n\n". $base;
				// Replace
				$file_system->put_contents( $up_file, $htaccess );
			} else {
				$file_system->copy( $base_file, $up_file );
			}
		}

	} else {

		if( $up_dir ) {
			// File Path
			$up_file   = $up_dir. '/.htaccess';
			$base_file = STILLBE_IQ_BASE_DIR. '/.htaccess';
			if( file_exists( $up_file ) ) {
				$htaccess = $file_system->get_contents( $up_file );
				$base     = $file_system->get_contents( $base_file );
				if( isset( $htaccess ) && isset( $base ) ) {
					$htaccess  = preg_replace( $block_pattern, '', $htaccess );
					// Replace
					$file_system->put_contents( $up_file, $htaccess. "\n" );
				}
			}
		}

	}

}




/**
 * 代替配信用 .htaccess を書き込むべきか (WebP または AVIF が有効)
 *
 * @return bool
 */
function stillbe_iqc_is_delivery_htaccess_enabled() {

	$enable_webp = (bool) apply_filters( 'stillbe_image_quality_control_enable_webp', STILLBE_IQ_ENABLE_WEBP, 'htaccess' );
	$enable_avif = (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', STILLBE_IQ_ENABLE_AVIF, 'htaccess' );

	return $enable_webp || $enable_avif;

}




// END of the File


