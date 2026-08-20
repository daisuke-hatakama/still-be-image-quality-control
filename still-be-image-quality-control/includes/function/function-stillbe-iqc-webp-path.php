<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * パスが WebP ファイルかどうか
 *
 * @param string $path
 * @return bool
 */
function stillbe_iqc_is_webp_path( $path ) {

	return (bool) preg_match( '/\.webp$/i', (string) $path );

}


/**
 * 代替配信用 WebP のパスを返す（元が WebP の場合は null）
 *
 * JPEG/PNG/GIF などに対してのみ「元ファイル名 + .webp」を返す。
 *
 * @param string $main_path 元形式ファイルのフルパス
 * @return string|null
 */
function stillbe_iqc_get_delivery_webp_path( $main_path ) {

	$main_path = (string) $main_path;

	if( '' === $main_path || stillbe_iqc_is_webp_path( $main_path ) ) {
		return null;
	}

	return $main_path . '.webp';

}


/**
 * 代替配信用 WebP パス（フィルター適用済み）
 *
 * @param string $main_path
 * @return string|null
 */
function stillbe_iqc_get_filtered_delivery_webp_path( $main_path ) {

	$webp_path = stillbe_iqc_get_delivery_webp_path( $main_path );

	if( null === $webp_path ) {
		return null;
	}

	return apply_filters( 'stillbe_uploaded_image_webp_name', $webp_path );

}


/**
 * サイズデータから代替配信用 WebP パスを解決する
 *
 * @param string $base_dir
 * @param array  $size_data
 * @param string $main_path
 * @return string|null
 */
function stillbe_iqc_resolve_delivery_webp_path( $base_dir, $size_data, $main_path ) {

	if( ! empty( $size_data['sb-iqc']['webp-file'] ) ) {
		return path_join( $base_dir, $size_data['sb-iqc']['webp-file'] );
	}

	return stillbe_iqc_get_filtered_delivery_webp_path( $main_path );

}


/**
 * 代替配信用 WebP を生成すべきか
 *
 * @param string $mime_type
 * @param string $file_path
 * @return bool
 */
function stillbe_iqc_should_generate_delivery_webp( $mime_type = '', $file_path = '' ) {

	if( 'image/webp' === $mime_type || stillbe_iqc_is_webp_path( $file_path ) ) {
		return false;
	}

	return (bool) apply_filters( 'stillbe_image_quality_control_enable_webp', STILLBE_IQ_ENABLE_WEBP, 'generate' );

}




// END
