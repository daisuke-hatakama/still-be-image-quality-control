<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * メディアライブラリをリスト表示した時の追加列
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// 画像エディタに追加したClassを加える
add_filter( 'wp_image_editors', function( $image_editors ) {

	if( ! is_array( $image_editors ) ) {
		return $image_editors;
	}

	$to_prepend = array();
	foreach( stillbe_iqc_get_ordered_image_editor_classes() as $editor_class ) {
		if( $editor_class::test() ) {
			$to_prepend[] = $editor_class;
		}
	}

	if( empty( $to_prepend ) ) {
		return $image_editors;
	}

	$new_editors = $image_editors;
	foreach( array_reverse( $to_prepend ) as $editor_class ) {
		array_unshift( $new_editors, $editor_class );
	}

	return $new_editors;

} );





// END of the File


