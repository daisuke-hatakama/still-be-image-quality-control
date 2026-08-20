<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * AVIF の読み書きに対応する最優先のエディタ Class を返す
 *
 * @since 2.1.0
 *
 * @return string エディタ Class 名 (対応エディタが無ければ空文字)
 */
function stillbe_iqc_get_avif_capable_editor_class() {

	$classes = stillbe_iqc_get_avif_capable_editor_classes();

	return empty( $classes ) ? '' : $classes[0];

}




// END of the File
