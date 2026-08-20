<?php


/**
 * Check if "near-lossless" Option can be Used
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




function stillbe_iqc_extends_chk_near_lossless() {

	// Get the "cwebp" Version
	$version = stillbe_iqc_extends_get_cwebp_ver();

	// Version Check
	return version_compare( $version, '0.5.0', '>=' );

}






// END of the File



