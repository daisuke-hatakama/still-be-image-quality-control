<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * デフォルトの品質設定を返す関数
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




function _stillbe_get_quality_level_array() {

	return array(
		'thumbnail_jpeg'    => 65,
		'thumbnail_png'     => 9,
		'thumbnail_webp'    => 65,
		'medium_jpeg'       => 75,
		'medium_png'        => 9,
		'medium_webp'       => 72,
		'medium_large_jpeg' => 80,
		'medium_large_png'  => 9,
		'medium_large_webp' => 80,
		'large_jpeg'        => 80,
		'large_png'         => 9,
		'large_webp'        => 82,
		'1536x1536_jpeg'    => 85,
		'1536x1536_png'     => 9,
		'1536x1536_webp'    => 86,
		'2048x2048_jpeg'    => 90,
		'2048x2048_png'     => 9,
		'2048x2048_webp'    => 92,
		'default_jpeg'      => 82,
		'default_png'       => 9,
		'default_webp'      => 86,
		'original_webp'     => 92,
		// WooCommerce 用を追加 @2.0.1
		'woocommerce_gallery_thumbnail_jpeg' => 56,
		'woocommerce_gallery_thumbnail_png'  => 9,
		'woocommerce_gallery_thumbnail_webp' => 56,
		'woocommerce_thumbnail_jpeg'         => 64,
		'woocommerce_thumbnail_png'          => 9,
		'woocommerce_thumbnail_webp'         => 64,
		'woocommerce_single_jpeg'            => 72,
		'woocommerce_single_png'             => 9,
		'woocommerce_single_webp'            => 76,
		// WooCommerce 用ここまで
		// AVIF 用を追加 @2.1.0
		'thumbnail_avif'    => 65,
		'medium_avif'       => 72,
		'medium_large_avif' => 76,
		'large_avif'        => 82,
		'1536x1536_avif'    => 82,
		'2048x2048_avif'    => 84,
		'default_avif'      => 82,
		'original_avif'     => 86,
		'woocommerce_gallery_thumbnail_avif' => 56,
		'woocommerce_thumbnail_avif'         => 64,
		'woocommerce_single_avif'            => 72,
		// AVIF 用ここまで
	);

}




// END of the File



