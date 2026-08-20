<?php


/**
 * Convert to WebP using "cwebp"
 *
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




function stillbe_iqc_extends_conv_cwebp( $original_file, $filename, $size_data = array(), $opt = array() ) {

	// Check Filename
	if( empty( $original_file ) || empty( $filename ) ) {
		return new WP_Error( 'error_webp_filename', __( 'No WebP filename has be passed' ) );
	}

	// Check "-near_lossless" Option
	// Default is FALSE
	$is_near_lossless = false;
	if( apply_filters( 'stillbe_image_quality_control_enable_webp_near_lossless', STILLBE_IQ_ENABLE_WEBP_NEAR_LOSSLESS ) ) {
		$is_near_lossless = stillbe_iqc_extends_chk_near_lossless();
	}

	// Get the Size
	$size = empty( $opt['size'] ) ? null : $opt['size'];
	if( empty( $opt['size'] ) ) {
		return new WP_Error( 'error_getting_size', __( 'No original image size has be passed' ) );
	}

	// Get the Quality (cast early so shell options stay numeric)
	$webp_quality = empty( $opt['quality'] ) ? 0 : absint( $opt['quality'][0] );
	$quality      = empty( $opt['quality'] ) ? 0 : absint( $opt['quality'][1] );
	if( empty( $webp_quality * $quality ) ) {
		return new WP_Error( 'error_getting_webp_quality', __( 'No WebP quality has be passed' ) );
	}

	$webp_quality = max( 1, min( 100, $webp_quality ) );

	// for Test / lossless PNG level
	$quality = \StillBE\Plugin\ImageQualityControl\Setting::chk_num_type( $quality, 'png' );
	if( empty( $quality ) ) {
		$quality = 9;
	}

	// Get the Mime-Type
	$mime_type = empty( $opt['mime'] ) ? 'image/jpeg' : (string) $opt['mime'];
	if( 0 !== strpos( $mime_type, 'image/' ) ) {
		$mime_type = 'image/jpeg';
	}

	// Set Compression Quality Options
	$options = '';
	$_cwebp  = array();
	if( 'image/png' === $mime_type || 'image/gif' === $mime_type ) {
		if( apply_filters( 'stillbe_image_quality_control_enable_webp_lossless_for_png_gif', STILLBE_IQ_ENABLE_WEBP_LOSSLESS ) ) {
			// LossLess or Near-Lossless Compression
			if( $is_near_lossless ) {
				$options .= "-near_lossless {$webp_quality}";
				// Convert Info
				$_cwebp['quality'] = $webp_quality;
				$_cwebp['method']  = 'near-lossless';
			} else {
				if( 'image/gif' === $mime_type ) {
					$quality = 9;
				}
				$_quality  = function_exists( '\\StillBE\\Plugin\\ImageQualityControl\\stillbe_iqc_png_level_to_lossless_quality' )
				             ? \StillBE\Plugin\ImageQualityControl\stillbe_iqc_png_level_to_lossless_quality( $quality )
				             : min( absint( $quality * 10 + 6 ), 100 );   // Quality = 100 in Lossless is Very Time Consuming
				$options .= "-lossless -q {$_quality}";
				// Convert Info
				$_cwebp['q']       = $quality;
				$_cwebp['quality'] = $_quality;
				$_cwebp['method']  = 'lossless';
			}
		} else {
			// Lossy Compression
			$options .= "-q {$webp_quality}";
			// Convert Info
			$_cwebp['quality'] = $webp_quality;
			$_cwebp['method']  = 'lossy';
		}
	} else {
		// Lossy Compression (JPEG / WebP / other raster)
		$options .= "-metadata icc -q {$webp_quality}";
		// 追加オプション
		$options .= ' -nostrong';
		// Convert Info
		$_cwebp['quality'] = $webp_quality;
		$_cwebp['method']  = 'lossy';
	}

	// Additional Options (@since 1.0.0)
	//   -m : Compression method to use (1-6, default = 4)
	//   -mt: Use multi-threading for encoding, if possible.
	$options .= ' -m 6 -mt';

	// Set Crop & Resize Options
	if( ! isset( $size_data['width'] ) ) {
		$size_data['width'] = $size['width'];
	}
	if ( ! isset( $size_data['height'] ) ) {
		$size_data['height'] = $size['height'];
	}
	if ( ! isset( $size_data['crop'] ) ) {
		$size_data['crop'] = false;
	}

	// Resize
	$_w = 0;
	$_h = 0;
	if( $size['width'] > $size_data['width'] || $size['height'] > $size_data['height'] ) {
		$dims = image_resize_dimensions( $size['width'], $size['height'], $size_data['width'], $size_data['height'], $size_data['crop'] );
		if( ! $dims ) {
			return new WP_Error( 'error_getting_dimensions', __( 'Could not calculate resized image dimensions' ) );
		}
		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;
		$src_x = absint( $src_x );
		$src_y = absint( $src_y );
		$src_w = absint( $src_w );
		$src_h = absint( $src_h );
		$dst_w = absint( $dst_w );
		$dst_h = absint( $dst_h );
		if( $size_data['crop'] ) {
			$options .= " -crop {$src_x} {$src_y} {$src_w} {$src_h}";
		}
		$options .= " -resize {$dst_w} {$dst_h}";
		$_w = $dst_w;
		$_h = $dst_h;
	}


	// @since 2.0.0  Use proc_open() instead of passthru()

	$is_stream = '-' === $filename;

	$command = sprintf(
		'cwebp %s %s -o %s -print_ssim',
		$options,
		escapeshellarg( $original_file ),
		escapeshellarg( $filename )
	);

	$descriptorspec = array(
		0 => array( 'pipe', 'r' ),   // stdin
		1 => array( 'pipe', 'w' ),   // stdout (stream output / leftover messages)
		2 => array( 'pipe', 'w' ),   // stderr (SSIM info)
	);

	$stderr_output = '';
	$stdout_output = '';
	$result_code   = -1;

	// Convert Image using "cwebp"
	$process = proc_open( $command, $descriptorspec, $pipes );

	if( ! is_resource( $process ) ) {
		return new WP_Error(
			'error_cwebp_exec',
			__( 'Failed to start cwebp process.' ),
			$options
		);
	}

	// cwebp はファイル引数から読むので stdin は不要。開いたままにしない。
	fclose( $pipes[0] );

	// stdout / stderr を並行して読み、ストリーム出力時のパイプデッドロックを防ぐ
	$drained = _stillbe_iqc_extends_drain_cwebp_pipes( $pipes[1], $pipes[2] );
	$stdout_output = $drained['stdout'];
	$stderr_output = $drained['stderr'];

	fclose( $pipes[1] );
	fclose( $pipes[2] );

	$result_code = proc_close( $process );

	if( preg_match( '/SSIM:.*?(?:All|Total):\s*([\d\.]+)/i', $stderr_output, $m ) ) {
		$ssim_db              = floatval( $m[1] );
		$_cwebp['ssim_in_dB'] = $ssim_db;
		$_cwebp['ssim']       = 1 - 10 ** ( -$ssim_db / 10 );
	} else {
		// 番兵: 呼び出し側は ssim > 0 のときだけ有効値として扱うこと
		$_cwebp['ssim'] = 0;
	}

	// テスト等で stdout に画像を出す場合
	if( $is_stream && '' !== $stdout_output ) {
		echo $stdout_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image stream
	}

	// Error Handling
	if( 0 !== (int) $result_code ) {
		$message = trim( $stderr_output );
		if( '' === $message ) {
			$message = sprintf(
				/* translators: %d: process exit code */
				__( 'cwebp exited with code %d.' ),
				(int) $result_code
			);
		}
		return new WP_Error( 'error_cwebp_exec', $message, $options );
	}

	// テストの場合はここで終了
	if( $is_stream ) {
		return $_cwebp;
	}

	if( ! file_exists( $filename ) || 1 > (int) @filesize( $filename ) ) {
		return new WP_Error(
			'error_cwebp_exec',
			__( 'cwebp did not produce an output file.' ),
			$options
		);
	}

	$_cwebp['convert_result'] = $stderr_output;

	// Return Result
	return array(
		'path'  => $filename,
		'file'  => wp_basename( $filename ),
		'size'  => @filesize( $filename ),
		'q'     => $_cwebp['quality'],
		'cwebp' => $_cwebp,
		'meta'  => array(
			'file'       => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
			'mime-type'  => 'image/webp',
			'width'      => $_w,
			'height'     => $_h,
		),
	);

}


/**
 * cwebp の stdout / stderr をデッドロックなく読み取る
 *
 * @param resource $stdout_pipe
 * @param resource $stderr_pipe
 * @return array{stdout:string,stderr:string}
 */
function _stillbe_iqc_extends_drain_cwebp_pipes( $stdout_pipe, $stderr_pipe ) {

	$stdout = '';
	$stderr = '';

	stream_set_blocking( $stdout_pipe, false );
	stream_set_blocking( $stderr_pipe, false );

	$stdout_open = true;
	$stderr_open = true;
	$deadline    = time() + 120;

	while( ( $stdout_open || $stderr_open ) && time() < $deadline ) {

		$read = array();
		if( $stdout_open ) {
			$read[] = $stdout_pipe;
		}
		if( $stderr_open ) {
			$read[] = $stderr_pipe;
		}

		$write  = null;
		$except = null;
		$ready  = @stream_select( $read, $write, $except, 1 );

		if( false === $ready ) {
			break;
		}

		if( 0 === $ready ) {
			// タイムアウト時も EOF を確認する
			if( $stdout_open && feof( $stdout_pipe ) ) {
				$stdout_open = false;
			}
			if( $stderr_open && feof( $stderr_pipe ) ) {
				$stderr_open = false;
			}
			continue;
		}

		foreach( $read as $pipe ) {
			$chunk = fread( $pipe, 8192 );
			if( false === $chunk || '' === $chunk ) {
				if( feof( $pipe ) ) {
					if( $pipe === $stdout_pipe ) {
						$stdout_open = false;
					} else {
						$stderr_open = false;
					}
				}
				continue;
			}
			if( $pipe === $stdout_pipe ) {
				$stdout .= $chunk;
			} else {
				$stderr .= $chunk;
			}
		}

		if( $stdout_open && feof( $stdout_pipe ) ) {
			$stdout_open = false;
		}
		if( $stderr_open && feof( $stderr_pipe ) ) {
			$stderr_open = false;
		}

	}

	return array(
		'stdout' => $stdout,
		'stderr' => $stderr,
	);

}






// END of the File
