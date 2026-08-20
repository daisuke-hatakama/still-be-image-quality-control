<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * パスが AVIF ファイルかどうか
 *
 * @param string $path
 * @return bool
 */
function stillbe_iqc_is_avif_path( $path ) {

	return (bool) preg_match( '/\.avif$/i', (string) $path );

}


/**
 * 代替配信用 AVIF のパスを返す（元が AVIF / WebP の場合は null）
 *
 * @param string $main_path 元形式ファイルのフルパス
 * @return string|null
 */
function stillbe_iqc_get_delivery_avif_path( $main_path ) {

	$main_path = (string) $main_path;

	if( '' === $main_path || stillbe_iqc_is_avif_path( $main_path ) || stillbe_iqc_is_webp_path( $main_path ) ) {
		return null;
	}

	return $main_path . '.avif';

}


/**
 * 代替配信用 AVIF パス（フィルター適用済み）
 *
 * @param string $main_path
 * @return string|null
 */
function stillbe_iqc_get_filtered_delivery_avif_path( $main_path ) {

	$avif_path = stillbe_iqc_get_delivery_avif_path( $main_path );

	if( null === $avif_path ) {
		return null;
	}

	return apply_filters( 'stillbe_uploaded_image_avif_name', $avif_path );

}


/**
 * サイズデータから代替配信用 AVIF パスを解決する
 *
 * @param string $base_dir
 * @param array  $size_data
 * @param string $main_path
 * @return string|null
 */
function stillbe_iqc_resolve_delivery_avif_path( $base_dir, $size_data, $main_path ) {

	if( ! empty( $size_data['sb-iqc']['avif-file'] ) ) {
		return path_join( $base_dir, $size_data['sb-iqc']['avif-file'] );
	}

	return stillbe_iqc_get_filtered_delivery_avif_path( $main_path );

}


/**
 * 代替配信用 AVIF を生成すべきか
 *
 * @param string $mime_type
 * @param string $file_path
 * @return bool
 */
function stillbe_iqc_should_generate_delivery_avif( $mime_type = '', $file_path = '' ) {

	if( 'image/avif' === $mime_type || stillbe_iqc_is_avif_path( $file_path ) ) {
		return false;
	}

	return (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', STILLBE_IQ_ENABLE_AVIF, 'generate' );

}




// END
