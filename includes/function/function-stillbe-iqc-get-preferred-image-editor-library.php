<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Preferred image editor library slug.
 *
 * @since 2.1.2
 *
 * @return string 'imagick' or 'gd'
 */
function stillbe_iqc_get_preferred_image_editor_library() {

	$default = defined( 'STILLBE_IQ_PREFERRED_IMAGE_EDITOR' ) ? STILLBE_IQ_PREFERRED_IMAGE_EDITOR : 'imagick';
	$pref    = apply_filters( 'stillbe_image_quality_control_preferred_image_editor', $default );

	return ( 'gd' === $pref ) ? 'gd' : 'imagick';

}




// END of the File
