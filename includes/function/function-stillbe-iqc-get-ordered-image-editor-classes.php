<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Plugin image editor classes in preference order.
 *
 * @since 2.1.2
 *
 * @return string[] Class names that exist (preferred first).
 */
function stillbe_iqc_get_ordered_image_editor_classes() {

	if( 'gd' === stillbe_iqc_get_preferred_image_editor_library() ) {
		$order = array( Image_Editor_GD::class, Image_Editor_Imagick::class );
	} else {
		$order = array( Image_Editor_Imagick::class, Image_Editor_GD::class );
	}

	$classes = array();
	foreach( $order as $editor_class ) {
		if( class_exists( $editor_class ) ) {
			$classes[] = $editor_class;
		}
	}

	return $classes;

}




// END of the File
