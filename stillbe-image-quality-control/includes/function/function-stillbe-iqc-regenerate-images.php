<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// Regenerate Resized Images Function
function stillbe_iqc_regenerate_images( $attachment_id = 0 ) {

	// Get the attachment ID
	$attachment_id = absint( $attachment_id );

	// When a Attachment ID is not Specified
	$is_single_process = false;
	if( empty( $attachment_id ) ) {
		// Get the Image IDs
		$attachment_ids = (array) get_option( '_sb-iqc-image-ids', array() );
		if( empty( $attachment_ids ) ) {
			$attachment_ids = stillbe_iqc_get_attachment_ids();
			update_option( '_sb-iqc-current-id', 0, false );
		}
		$attachment_ids = array_map( 'absint', $attachment_ids );
		rsort( $attachment_ids, SORT_NUMERIC );
		// Get the Regenerated Image ID
		$generated_last_id = (int) get_option( '_sb-iqc-current-id', 0 );
		if( empty( $generated_last_id ) && count( $attachment_ids ) ) {
			$attachment_id = $attachment_ids[0];
		} else {
			foreach( $attachment_ids as $id ) {
				$_id = absint( $id );
				if( $generated_last_id > $_id ) {
					$attachment_id = $_id;
					break;
				}
			}
		}
		// When NO Valid Attachment ID Exists
		if( empty( $attachment_id ) ) {
			update_option( '_sb-iqc-current-id', 0, false );
			return null;
		}
	} else {
		// DO NOT change the Current Processing File on '_sb_iqc_current_id'
		$is_single_process = true;
	}

	// Update Current Processing Attchment ID
	$is_completed   = false;
	if( ! $is_single_process ) {
		$attachment_ids = $attachment_ids ?: stillbe_iqc_get_attachment_ids();
		$last_id        = end( $attachment_ids );
		$is_completed   = $last_id >= $attachment_id;
		update_option( '_sb-iqc-current-id', ( $is_completed ? 0 : $attachment_id ), false );
	}

	// Get the Current Metadata
	$old_meta = wp_get_attachment_metadata( $attachment_id );

	// When NO Metadata Exists
	if( empty( $old_meta ) || empty( $old_meta['file'] ) ) {
		return array(
			'ok'        => false,
			'id'        => $attachment_id,
			'message'   => sprintf( __( 'Attachment ID = %d is not found....', 'still-be-image-quality-control' ), $attachment_id ),
			'completed' => $is_completed,
		);
	}

	// Site Icon
	$site_icon_id = get_option( 'site_icon' );
	if( $site_icon_id == $attachment_id ) {
		require_once( ABSPATH. 'wp-admin/includes/class-wp-site-icon.php' );
		add_filter( 'intermediate_image_sizes_advanced', function( $new_sizes ) {
			$site_icon = new \WP_Site_Icon;
			return $site_icon->additional_sizes( $new_sizes );
		} );
	}

	// Original File Path
	$upload_dir = wp_upload_dir();
	$filename   = $upload_dir['basedir']. '/'. $old_meta['file'];

	// 誤って生成された .webp.webp を削除する
	if( stillbe_iqc_is_webp_path( $filename ) ) {
		$bogus_delivery = $filename . '.webp';
		if( file_exists( $bogus_delivery ) ) {
			@unlink( $bogus_delivery );
		}
	}

	// 誤って生成された .avif.avif を削除する
	if( stillbe_iqc_is_avif_path( $filename ) ) {
		$bogus_delivery = $filename . '.avif';
		if( file_exists( $bogus_delivery ) ) {
			@unlink( $bogus_delivery );
		}
	}

	// Registered Image Sizes
	$registered_sizes = wp_get_registered_image_subsizes();
	$registered_sizes = apply_filters( 'intermediate_image_sizes_advanced', $registered_sizes, array(), $attachment_id );

	// Re-Compression Target Conditions
	//   When Re-Compression a Single Image, Target All Sizes
	$target = $is_single_process ? array() : get_option( '_sb-iqc-recomp-target-condition', array() );

	// Disabled Generating 'Auto Generated WebP' insted of Other Types
	if( isset( $target['type']['auto-webp'] ) && ! $target['type']['auto-webp'] ) {
		add_filter( 'stillbe_image_quality_control_enable_webp', '__return_false', PHP_INT_MAX );
	}

	if( isset( $target['type']['auto-avif'] ) && ! $target['type']['auto-avif'] ) {
		add_filter( 'stillbe_image_quality_control_enable_avif', '__return_false', PHP_INT_MAX );
	}

	// Target Sizes
	if( ! empty( $target['size'] ) && is_array( $target['size'] ) ) {
		add_filter( 'intermediate_image_sizes_advanced', function( $new_sizes ) use( $target ) {
			$_sizes = $new_sizes;
			foreach( $new_sizes as $_name => $_size ) {
				if( isset( $target['size'][ $_name ] ) && ! $target['size'][ $_name ] ) {
					unset( $_sizes[ $_name ] );
				}
			}
			return $_sizes;
		}, PHP_INT_MAX );
	}

	// Load Image Editor
	require_once( ABSPATH. 'wp-admin/includes/image.php' );

	// Mime Type
	$mime_type      = get_post_mime_type( $attachment_id ) ?: '';
	$_mime          = explode( '/', (string) $mime_type );
	$mime           = end( $_mime );
	$is_webp_source = ( 'image/webp' === $mime_type );
	$is_avif_source = ( 'image/avif' === $mime_type );

	// Generate a WebP for Original File (raster originals only)
	if( stillbe_iqc_should_generate_delivery_webp( $mime_type, $filename ) ) {
		$editor = wp_get_image_editor( $filename );
		if( ! is_wp_error( $editor ) ) {
			$func_name = __NAMESPACE__ . '\\__sb_iqc_return_original_dim';
			if( ! function_exists( $func_name ) ) {
				function __sb_iqc_return_original_dim( $_null, $orig_w, $orig_h, $dest_w, $dest_h, $crop ) {
					return array( 0, 0, 0, 0, (int) $orig_w, (int) $orig_h, (int) $orig_w, (int) $orig_h );
				}
			}
			add_filter( 'image_resize_dimensions', $func_name, 10, 6 );
			$editor->load();
			$webp_path = stillbe_iqc_get_filtered_delivery_webp_path( $filename );
			if( $webp_path ) {
				$editor->make_webp( $webp_path, array( 'size_name' => 'original' ) );
			}
			$editor = null;
			unset( $editor );
			remove_filter( 'image_resize_dimensions', $func_name, 10 );
		}
	}

	// Generate an AVIF for Original File (raster originals only)
	if( stillbe_iqc_should_generate_delivery_avif( $mime_type, $filename ) ) {
		$editor = wp_get_image_editor( $filename );
		if( ! is_wp_error( $editor ) ) {
			$func_name = __NAMESPACE__ . '\\__sb_iqc_return_original_dim';
			if( ! function_exists( $func_name ) ) {
				function __sb_iqc_return_original_dim( $_null, $orig_w, $orig_h, $dest_w, $dest_h, $crop ) {
					return array( 0, 0, 0, 0, (int) $orig_w, (int) $orig_h, (int) $orig_w, (int) $orig_h );
				}
			}
			add_filter( 'image_resize_dimensions', $func_name, 10, 6 );
			$editor->load();
			$avif_path = stillbe_iqc_get_filtered_delivery_avif_path( $filename );
			if( $avif_path ) {
				$editor->make_avif( $avif_path, array( 'size_name' => 'original' ) );
			}
			$editor = null;
			unset( $editor );
			remove_filter( 'image_resize_dimensions', $func_name, 10 );
		}
	}

	$type_enabled = ! isset( $target['type'][ $mime ] ) || ! empty( $target['type'][ $mime ] );

	if( $mime_type && $type_enabled ) {
		// Regenerate Resized Images & Update Metadata
		// context を update にして、アップロード時専用の Cron 登録を発火させない
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $filename, 'update' );
		$generate_method = 'wp_generate_attachment_metadata';
	} elseif( ! $is_webp_source && ! $is_avif_source &&
	          ( ! empty( $target['type']['auto-webp'] ) || ! empty( $target['type']['auto-avif'] ) ) ) {
		// Re-Compression only Auto Generated WebP / AVIF
		$new_meta = $old_meta;
		// Image Editor
		$editor = wp_get_image_editor( $filename );
		if( is_wp_error( $editor ) ) {
			return array(
				'ok'        => false,
				'id'        => $attachment_id,
				'message'   => __( 'Failed to load image editor....', 'still-be-image-quality-control' ),
				'completed' => $is_completed,
				'wp_errors' => $editor->get_error_messages(),
			);
		}
		// Target Sizes
		$target_sizes = wp_get_registered_image_subsizes();
		$target_sizes = apply_filters( 'intermediate_image_sizes_advanced', $target_sizes, $old_meta, $attachment_id );
		// Save WebP / AVIF
		$new_meta['sizes'] = isset( $new_meta['sizes'] ) ? $new_meta['sizes'] : array();
		foreach( $old_meta['sizes'] as $_name => $_size ) {
			if( empty( $target_sizes[ $_name ] ) ) {
				// Not Target
				continue;
			}
			if( empty( $_size['file'] ) || stillbe_iqc_is_webp_path( $_size['file'] ) || stillbe_iqc_is_avif_path( $_size['file'] ) ) {
				continue;
			}
			$basedir   = dirname( $filename );
			$main_path = path_join( $basedir, $_size['file'] );
			if( ! empty( $target['type']['auto-webp'] ) ) {
				$webp_name = stillbe_iqc_get_filtered_delivery_webp_path( $main_path );
				if( $webp_name ) {
					$webp_data = $editor->make_webp( $webp_name, $target_sizes[ $_name ] );
					if( ! is_wp_error( $webp_data ) && ! empty( $webp_data['size'] ) ) {
						$new_meta['sizes'][ $_name ]           = isset( $new_meta['sizes'][ $_name ] ) ? $new_meta['sizes'][ $_name ] : array();
						$new_meta['sizes'][ $_name ]['sb-iqc'] = isset( $new_meta['sizes'][ $_name ]['sb-iqc'] ) ? $new_meta['sizes'][ $_name ]['sb-iqc'] : array();
						$new_meta['sizes'][ $_name ]['sb-iqc']['webp-file']    = $webp_data['file'];
						$new_meta['sizes'][ $_name ]['sb-iqc']['webp-quality'] = $webp_data['q'];
						if( isset( $webp_data['cwebp'] ) ) {
							$new_meta['sizes'][ $_name ]['sb-iqc']['cwebp']    = $webp_data['cwebp'];
						}
						stillbe_iqc_merge_encode_method_meta( $new_meta['sizes'][ $_name ]['sb-iqc'], $webp_data, 'webp' );
					}
				}
			}
			if( ! empty( $target['type']['auto-avif'] ) ) {
				$avif_name = stillbe_iqc_get_filtered_delivery_avif_path( $main_path );
				if( $avif_name ) {
					$avif_data = $editor->make_avif( $avif_name, $target_sizes[ $_name ] );
					if( ! is_wp_error( $avif_data ) && ! empty( $avif_data['size'] ) ) {
						$new_meta['sizes'][ $_name ]           = isset( $new_meta['sizes'][ $_name ] ) ? $new_meta['sizes'][ $_name ] : array();
						$new_meta['sizes'][ $_name ]['sb-iqc'] = isset( $new_meta['sizes'][ $_name ]['sb-iqc'] ) ? $new_meta['sizes'][ $_name ]['sb-iqc'] : array();
						$new_meta['sizes'][ $_name ]['sb-iqc']['avif-file']    = $avif_data['file'];
						$new_meta['sizes'][ $_name ]['sb-iqc']['avif-quality'] = $avif_data['q'];
						stillbe_iqc_merge_encode_method_meta( $new_meta['sizes'][ $_name ]['sb-iqc'], $avif_data, 'avif' );
					}
				}
			}
		}
		$generate_method = 'make_webp_avif';
	} else {
		// No Image to Re-compress
		return array(
			'ok'        => false,
			'id'        => $attachment_id,
			'message'   => __( 'No image to recompress....', 'still-be-image-quality-control' ),
			'completed' => $is_completed,
			'meta'      => array( 'old' => $old_meta ),
			'mime_type' => $mime_type,
		);
	}

	// Restore Metadata of Excluded Sizes
	if( isset( $new_meta['sizes'] ) && is_array( $new_meta['sizes'] ) &&
	      ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ) {
		foreach( $old_meta['sizes'] as $_name => $_size ) {
			if( empty( $registered_sizes[ $_name ] ) ) {
				continue;
			}
			// Complete the Excluded Size
			if( ! isset( $new_meta['sizes'][ $_name ] ) ) {
				$new_meta['sizes'][ $_name ] = $_size;
			}
			// Completing Compression Information for WebP
			$new_meta['sizes'][ $_name ]['sb-iqc'] = isset( $new_meta['sizes'][ $_name ]['sb-iqc'] ) ?
			                                           $new_meta['sizes'][ $_name ]['sb-iqc'] :
			                                           array();
			foreach( array( 'webp-file', 'webp-quality', 'webp-method', 'webp-lossless-level', 'cwebp', 'avif-file', 'avif-quality', 'avif-method' ) as $delivery_key ) {
				if( empty( $new_meta['sizes'][ $_name ]['sb-iqc'][ $delivery_key ] ) &&
				      isset( $old_meta['sizes'][ $_name ]['sb-iqc'][ $delivery_key ] ) ) {
					if( 'cwebp' === $delivery_key &&
					      ( ! apply_filters( 'stillbe_image_quality_control_enable_cwebp_lib', STILLBE_IQ_ENABLE_CWEBP_LIBRARY ) || ! stillbe_iqc_is_enabled_cwebp() ) ) {
						continue;
					}
					if( 'webp-file' === $delivery_key && 'image/webp' === $_size['mime-type'] ) {
						continue;
					}
					if( 'avif-file' === $delivery_key && 'image/avif' === $_size['mime-type'] ) {
						continue;
					}
					$new_meta['sizes'][ $_name ]['sb-iqc'][ $delivery_key ] = $old_meta['sizes'][ $_name ]['sb-iqc'][ $delivery_key ];
				}
			}
		}
	}

	// Execute it because the reference of the wp_generate_attachment_metadata()
	// function instructs to use it together with the wp_update_attachment_metadata() function.
	// However, since the metadata is updated inside the wp_generate_attachment_metadata() function,
	// the return value of the wp_update_attachment_metadata() function is always (boolern) false
	// (because it tries to update to the same data as the data in the DB).
	// Therefore, the return value of the wp_update_attachment_metadata() function
	// is not used for success / failure judgment.
	wp_update_attachment_metadata( $attachment_id, $new_meta );

	// Re-Compression 後の SSIM 自動最適化（ユーザ選択・既定無効）
	$settings = get_option( Setting::SETTING_NAME, array() );
	$is_auto_optimize        = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;
	$is_auto_optimize_recomp = ! empty( $settings['toggle']['enable-auto-optimize-on-recompress'] );
	if( $is_auto_optimize && $is_auto_optimize_recomp ) {
		// 代替配信用 WebP の即時削除は任意 (既定は残し、最適化完了まで旧 WebP を配信する)
		if( ! empty( $settings['toggle']['enable-purge-delivery-webp-on-recompress'] ) ) {
			$new_meta = Auto_Optimize_Progress::purge_delivery_webp( $attachment_id, $new_meta );
		}
		$new_meta = Auto_Optimize_Progress::reset( $new_meta );
		wp_update_attachment_metadata( $attachment_id, $new_meta );
		Cron_Jobs::schedule_auto_optimize( $attachment_id, 0, null, true );
	}

	// Result
	$result = ! empty( $new_meta );

	return array(
		'ok'        => $result,
		'id'        => $attachment_id,
		'message'   => $result ? __( 'Success!!', 'still-be-image-quality-control' ) : __( 'Failed....', 'still-be-image-quality-control' ),
		'meta'      => array(
			'old'   => $old_meta,
			'new'   => $new_meta,
		),
		'completed' => $is_completed,
		'sizes'     => $registered_sizes,
		'target'    => $target,
		'method'    => $generate_method,
	);

}





// END

?>