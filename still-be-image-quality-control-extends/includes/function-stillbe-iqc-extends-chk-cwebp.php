<?php


/**
 * Check if "cwebp" can be Used
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




function stillbe_iqc_extends_chk_cwebp() {

	// "cwebp" Check
	return !! stillbe_iqc_extends_get_cwebp_ver();

}






// END of the File



