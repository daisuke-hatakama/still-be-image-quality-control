<?php

/*
Plugin Name:      Image Quality Control Extends | Still BE
Description:      A plugin for extending the "Image Quality Control" plugin to enable "cwebp"
Requires Plugins: still-be-image-quality-control
Version:          2.0.0
Author:           Daisuke Yamamoto
Author URI:       https://web.analogstd.com/
License:          GPL2
*/


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// This Plugin Version
define( 'STILLBE_IQ_EXT_PLUGIN_VER', '2.0.0' );




// cwebp が使用できるか確認する関数
require_once( __DIR__. '/includes/function-stillbe-iqc-extends-chk-cwebp.php' );

// cwebp のバージョンを取得する関数
require_once( __DIR__. '/includes/function-stillbe-iqc-extends-get-cwebp-ver.php' );

// Near-Lossless オプションが使用できるか確認する関数
require_once( __DIR__. '/includes/function-stillbe-iqc-extends-chk-near-lossless.php' );

// cwebp を使って WebP に変換する関数
require_once( __DIR__. '/includes/function-stillbe-iqc-extends-conv-cwebp.php' );





// END of the File



