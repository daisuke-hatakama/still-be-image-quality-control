<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * Trait Used in a Class that extends WP_Image_Editor_GD/Imagick Class
 * 
 *  * Define the Methods to Append
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




// 追加する共通メソッドを定義
trait Image_Editor_Common_Append {


	// サイズに対応した圧縮品質を取得する
	public function get_quality_from_size( $size, $mime_type = 'image/jpeg' ) {

		if( empty( $this->qualities ) ) {
			// Quality Setting Data
			$qualities = $this->_get_quality_array();
			$this->qualities = apply_filters( 'stillbe_image_quality_list', $qualities );
		}

		if( empty( $this->original_webp ) ) {
			// Original WebP Quality Setting
			$_defaults      = _stillbe_get_quality_level_array();
			$_original_webp = array(
				array(
					'lossy'    => $_defaults['original_webp'],
					'lossless' => 9,
				),
			);
			$this->original_webp = apply_filters( 'stillbe_image_quality_original_webp_settings', $_original_webp );
		}

		if( empty( $this->is_lossless_options ) ) {
			// Is Enabled Lossless Compression? (availability = \Imagick exists)
			$this->is_lossless_options = class_exists( '\Imagick' )
			                               && apply_filters( 'stillbe_image_quality_control_enable_webp_lossless_for_png_gif', STILLBE_IQ_ENABLE_WEBP_LOSSLESS );
		}

		$mime = explode( '/', $mime_type );
		$mime = end( $mime );

		if( 'original' !== strtolower( $size ) ) {
			$name    = $size. '_'. $mime;
			$defname = 'default_'. $mime;

			$quality = empty( $this->qualities[ $name ] ) ?
			             apply_filters( "stillbe_image_quality_default_{$mime}", $this->qualities[ $defname ] ) :
			             $this->qualities[ $name ];
			$quality = absint( apply_filters( "stillbe_image_quality_{$size}_{$mime}", $quality ) );

		} elseif( 'webp' !== $mime ) {

			// WebP 以外 (AVIF 等) は元画像用のしきい値テーブルを持たないため、専用のデフォルト値を使う
			$_defaults = _stillbe_get_quality_level_array();
			$defkey    = 'original_'. $mime;
			$quality   = isset( $_defaults[ $defkey ] ) ? $_defaults[ $defkey ] : $_defaults['original_webp'];
			$quality   = absint( apply_filters( "stillbe_image_quality_original_{$mime}", $quality ) );

		} else {

			$original_webp = $this->original_webp;

			$width  = $this->size['width'];
			$height = $this->size['height'];

			$webp = array_shift( $original_webp );
			$_defaults = _stillbe_get_quality_level_array();

			while( $_webp = array_pop( $original_webp ) ) {

				if( ! isset( $_webp['width'] ) || ! isset( $_webp['height'] ) ) {
					continue;
				}

				if( ( $_webp['width'] && $width > $_webp['width'] ) ||
				      ( $_webp['height'] && $height > $_webp['height'] ) ) {
					break;
				}

				$webp = $_webp;

				if( ! is_array( $webp ) ) {
					$webp = array();
				}
				if( empty( $webp['lossy'] ) ) {
					$webp['lossy'] = $_defaults['original_webp'];
				}
				if( empty( $webp['lossless'] ) ) {
					$webp['lossless'] = 9;
				}

			}

			$is_lossless = $this->is_lossless_options && 'png' === $mime;

			$quality = $webp[( $is_lossless ? 'lossless' : 'lossy' )];
			$quality = absint( apply_filters( "stillbe_image_quality_original_{$mime}", $quality ) );

		}

		if( 'image/avif' === $mime_type && $quality > 99 ) {
			$quality = 99;
		}

		if( 'image/png' !== $mime_type && 'image/avif' !== $mime_type && $quality > 100 ) {
			$quality = 100;
		}

		if( 'image/png' === $mime_type && $quality > 9 ){
			$quality = 9;
		}

		if( $quality < 1 ) {
			$quality = 1;
		}

		return $quality;

	}


	// Get Quality Settings
	protected function _get_quality_array() {

		return apply_filters(
			'stillbe_image_quality_default_list',
			_stillbe_get_quality_level_array()
		);

	}


	// 半角英数字と-_以外が含まれる場合はファイル名を置換する
	public function generate_safe_filename( $filename = '', $suffix = null, $original = false ) {

		if( apply_filters( 'stillbe_image_quality_control_convert_safename', true ) &&
		      preg_match( '%(.*?/)([^/]*?[^/](?=[^\/\.\-_a-zA-Z0-9])[^/]*?)(\.(?:jpe?g|png|gif|avif)(?:\.webp)?)$%', $filename, $m ) ) {
			// $m[1]: dir path, $m[2]: unsafe file name, $m[3]: file extension
			$now  = wp_date( 'YmdHis' );
			$hash = substr( md5( $m[2] ), 7, 8 );
			if( $original ) {
				return $m[1]. "{$now}-{$hash}". $m[3];
			}
			// $suffix will be appended to the destination filename, just before the extension.
			if( ! $suffix ) {
				$suffix = $this->get_suffix();
			}
			return $m[1]. "{$now}-{$hash}-{$suffix}". $m[3];
		}

		return $filename;

	}


	// Get the Mime-Type of Original Image
	public function get_original_mime() {
		return $this->mime_type;
	}


	// Set the Making Size to Private Var for 'wp_editor_set_quality' hook
	public function _set_mk_size( $size = '' ) {
		$this->mk_size = $size;
		return $this->mk_size;
	}


	// Set the Making Mime-Type to Private Var for 'wp_editor_set_quality' hook
	public function _set_mk_mime( $mime_type = '' ) {
		if( preg_match( '$image/(jpeg|png|webp|avif)$', $mime_type ) ) {
			$this->mk_mime = $mime_type;
		} else {
			$this->mk_mime = '';
		}
		return $this->mk_mime;
	}


	// Set the Making Quality for 'wp_editor_set_quality' hook
	// This Option is Used by only generating Test Image
	public function _set_mk_quality( $quality = 0, $mime = 'jpeg' ) {
		$q = Setting::chk_num_type( $quality, $mime, 'avif' === $mime );
		$this->mk_q = empty( $q ) ? 0 : $q;
		return $this->mk_q;
	}


	// Function for 'wp_editor_set_quality' hook
	public function _set_quality_hook( $default_quality, $mime_type ) {

		if( ! method_exists( $this, 'get_default_quality' ) ) {
			return $default_quality;
		}

		// Only Test Image
		if( $this->mk_q ) {
			return $this->mk_q;
		}

		if( empty( $this->mk_mime ) ) {
			$this->mk_mime = $mime_type;
		}

		// If it has already been changed, return $default_quality that is not change
		$_default = $this->get_default_quality( $this->mk_mime );
		if( $_default !== $default_quality &&
		      apply_filters( 'stillbe_image_quality_control_force_priority', false ) ) {
			return $default_quality;
		}

		return $this->get_quality_from_size( $this->mk_size, $this->mk_mime );

	}


	// Convert to WebP with GD or Imagick
	abstract protected function _make_webp_embed_library( $filename, $size_data );


	// Convert to AVIF with GD or Imagick
	abstract protected function _make_avif_embed_library( $filename, $size_data );


	// Generate WebP
	public function make_webp( $filename = null, $size_data = null ) {

		if( $filename && preg_match( '/\.webp\.webp$/i', (string) $filename ) ) {
			return false;
		}

		if( ! apply_filters( 'stillbe_image_quality_control_enable_webp', STILLBE_IQ_ENABLE_WEBP, 'generate' ) && 'image/webp' !== $this->mime_type ) {
			return false;
		}

	//	@since 0.5.1 Deleted
	//	$this->var_cwebp['is_exists'] = isset( $this->var_cwebp['is_exists'] ) ? $this->var_cwebp['is_exists'] : $this->_server_cmd_exists( 'cwebp' );
		if( apply_filters( 'stillbe_image_quality_control_enable_cwebp_lib', STILLBE_IQ_ENABLE_CWEBP_LIBRARY )
		      && stillbe_iqc_is_enabled_cwebp() ) {
			// @since 0.9.0 Added
			//   Enable Conversion in "cwebp" if the Extension Plugin is Installed
			$size         = empty( $size_data['size_name'] ) ? 'default' : $size_data['size_name'];
			$quality      = $this->get_quality_from_size( $size, $this->mime_type );
			$webp_quality = $this->get_quality_from_size( $size, 'image/webp' );
			// Oprions
			$options = array(
				'quality' => array( $webp_quality, $quality ),
				'mime'    => $this->mime_type,
				'size'    => $this->size,
			);
			// @since 1.7.3 Added
			//   Rotate based on exif orientation before compressing with cwebp.
			//   Compromise by using WP_Image_Editor to convert PNG without using a lossless conversion utilities, such as jpegtran or exiftran.
			$temp_filename   = null;
			$source_filename = $this->file;
			$mime_type       = ( new \finfo( FILEINFO_MIME_TYPE ) )->file( $source_filename );
		//	error_log( $mime_type );
			if( 'image/jpeg' === $mime_type ) {
				$orientation = null;
				if( is_callable( 'exif_read_data' ) ) {
					$exif_data = @exif_read_data( $source_filename );
					if( ! empty( $exif_data['Orientation'] ) ) {
						$orientation = (int) $exif_data['Orientation'];
					}
				}
				$orientation = apply_filters( 'wp_image_maybe_exif_rotate', $orientation, $source_filename );
				if( isset( $orientation ) && 1 < $orientation ) {
					if( ! function_exists( 'wp_tempnam' ) ) {
						require_once( ABSPATH . 'wp-admin/includes/file.php' );
					}
					$temp_filename = wp_tempnam();
					$_editor = wp_get_image_editor( $this->file );
					$is_rotated = $_editor->maybe_exif_rotate();
					$is_saved   = $_editor->save( $temp_filename, 'image/png' );
					if( true === $is_rotated && ! is_wp_error( $is_saved ) ) {
						$source_filename = $temp_filename;
					}
				}
			}
		//	error_log( $source_filename );
		//	error_log( $temp_filename ?? '' );
			// Make WebP usign "cwebp"
			$result_cwebp = stillbe_iqc_extends_conv_cwebp( $source_filename, $filename, $size_data, $options );
			if( ! empty( $temp_filename ) ) {
				@unlink( $temp_filename );
			}
			return $result_cwebp;
		} elseif( $this->supports_mime_type( 'image/webp' ) ) {
			return $this->_make_webp_embed_library( $filename, $size_data );
		}

		return false;

	}


	// Generate AVIF
	public function make_avif( $filename = null, $size_data = null ) {

		if( $filename && preg_match( '/\.avif\.avif$/i', (string) $filename ) ) {
			return false;
		}

		if( ! defined( 'STILLBE_IQ_ENABLE_AVIF' ) ) {
			return false;
		}

		if( ! apply_filters( 'stillbe_image_quality_control_enable_avif', STILLBE_IQ_ENABLE_AVIF, 'generate' ) && 'image/avif' !== $this->mime_type ) {
			return false;
		}

		if( $this->supports_mime_type( 'image/avif' ) ) {
			return $this->_make_avif_embed_library( $filename, $size_data );
		}

		$editor_class = stillbe_iqc_get_avif_capable_editor_class();
		if( empty( $editor_class ) ) {
			return false;
		}
		$avif_editor = new $editor_class( $this->file );

		if( ! empty( $this->mk_size ) ) {
			$avif_editor->_set_mk_size( $this->mk_size );
		}
		$avif_editor->_set_mk_mime( ! empty( $this->mk_mime ) ? $this->mk_mime : 'image/avif' );

		$loaded = $avif_editor->load();
		if( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		add_filter( 'wp_editor_set_quality', array( $avif_editor, '_set_quality_hook' ), 1, 2 );
		$result = $avif_editor->make_avif( $filename, $size_data );
		remove_filter( 'wp_editor_set_quality', array( $avif_editor, '_set_quality_hook' ), 1 );

		return $result;

	}


	// Get a Number of Used Colors in Original Image
	public function get_original_color_num() {

		return $this->color_num;

	}


	// Tries to convert an attachment URL including the size & quality suffix into a post ID.
	static public function attachment_url_to_postid( $url ) {

		$post_id = attachment_url_to_postid( $url );

		if( 0 < $post_id ) {
			return $post_id;
		}

		$url = preg_replace(
			'/(-[1-9]%d*x[1-9]%d*(-q[1-9]%d*)?|(-[1-9]%d*x[1-9]%d*)?-q[1-9]%d*).*?\.(jpe?g|png|avif|webp|gif|)$/i',
			'',
			$url
		);

		return attachment_url_to_postid( $url );

	}


	// Get a Luminance Array
	public function get_luminance_array( $cx, $cy, $r = 0 ) {

		// 輝度情報の一次元配列
		$lums = $this->_get_grayscale_luminance_array();

		$size = ( (int) $r ) * 2 + 1;
		$dest = new \SplFixedArray( $size * $size );

		$img_width  = $this->size['width'];
		$img_height = $this->size['height'];

		$index = 0;

		for( $y = $cy - $r, $y_end = $cy + $r; $y <= $y_end; ++$y ) {
			if( $y < 0 || $y >= $img_height ) {
				// $yが画像の範囲外の時
				for( $i = 0; $i < $size; ++$i ) {
					$dest[ $index++ ] = 0.0;
				}
				continue;
			}
			$row_offset = $y * $img_width;
			for( $x = $cx - $r, $x_end = $cx + $r; $x <= $x_end; ++$x ) {
				// $xが画像の範囲外の時
				if( $x < 0 || $x >= $img_width ) {
					$dest[ $index++ ] = 0.0;
				} else {
					$dest[ $index++ ] = $lums[ $row_offset + $x ];
				}
			}
		}

		return $dest;

	}


}




// END of the File



