<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * PNG 可逆時の Imagick WebP / AVIF エンコードオプション
 *
 * @since 2.1.1
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




add_filter(
	'still-be/image-quality-control/imagick/options/webp',
	array( Image_Editor_Imagick::class, 'filter_imagick_options_webp_lossless' ),
	20,
	3
);

add_filter(
	'still-be/image-quality-control/imagick/options/avif',
	array( Image_Editor_Imagick::class, 'filter_imagick_options_avif_lossless' ),
	20,
	3
);
