<?php

namespace StillBE\Plugin\ImageQualityControl;


/**
 * Imagick を使った Image Editor を継承した class
 * 
 */


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




class Image_Editor_Imagick extends \WP_Image_Editor_Imagick {

	use Image_Editor_Common_Variables,
	    Image_Editor_Common_Overwrite,
	    Image_Editor_Common_Append;

	const IMAGE_EDITOR_LIBRARY = 'imagick';

	/**
	 * WebP 書き出し時の Imagick オプション (cwebp: -metadata icc -m 6 -mt -nostrong 相当)
	 *
	 * @since 2.1.1
	 */
	const OPTIONS_WEBP = array(
		'webp:metadata'     => 'icc', // -metadata icc
		'webp:method'       => '6',   // -m 6
		'webp:thread-level' => '1',   // -mt (0: multi-thread disabled, 1: maulti-thread enabled)
		'webp:filter-type'  => '0',   // -nostrong (0: nostrong, 1: simple, 2: complex)
	);

	/**
	 * AVIF 書き出し時の Imagick オプション (avifenc: -s 4 -y 420 --sharpyuv 相当)
	 *
	 * ImageMagick が参照するのは heic:speed / heic:chroma / heic:chroma-downsampling。
	 * tune (-a tune=…) と jobs (-j) は現行 heic コーダに定義がなく渡せないため、
	 * libheif / aom 側の既定に任せる (jobs 既定は all 相当)。
	 *
	 * @since 2.1.1
	 */
	const OPTIONS_AVIF = array(
		'heic:speed'                => '4',          // -s 4
		'heic:chroma'               => '420',        // -y 420
		'heic:chroma-downsampling'  => 'sharp-yuv',  // --sharpyuv (libheif >= 1.16)
	);

	/**
	 * HEIF / HEIC 書き出し時の Imagick オプション (AVIF と同じ heic: 定義を使用)
	 *
	 * @since 2.1.1
	 */
	const OPTIONS_HEIF = self::OPTIONS_AVIF;


	/**
	 * PNG 可逆エンコードを parent::_save() 内の quality 再設定に負けないよう強制するフラグ
	 *
	 * @since 2.1.1
	 * @var bool
	 */
	protected $force_png_lossless = false;


	// Get the Number of Colors for PNG when Loading Image
	public function load() {

		$loaded = parent::load();

		if( ! defined( 'STILLBE_IQ_ENABLE_INDEX_COLOR' ) ) {
			define( 'STILLBE_IQ_ENABLE_INDEX_COLOR', STILLBE_IQ_ENABLE_INDEX_COLOR_IMAGICK );
		}

		if( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		if( 0 !== strpos( $this->mime_type, 'image/' ) ) {
			$_mime_type = wp_get_image_mime( $this->file );
			if( 0 === strpos( $_mime_type, 'image/' ) ) {
				$this->mime_type = $_mime_type;
			}
		}

		if( 'image/png' === $this->mime_type ) {
			$this->color_num = $this->get_colors();
		}

		return $loaded;

	}


	protected function _save( $image, $filename = null, $mime_type = null ) {

		$quality = null;

		// Set PNG Compression Level
		if( 'image/png' === $this->mime_type ) {

			$quality = Setting::chk_num_type( $this->get_quality(), 'png' );

			if( ! $quality ) {
				$quality = $this->get_quality_from_size( 'default', 'image/png' );
			}

			$this->image->setOption( 'png:compression-level', (string) $quality );

			// When the Original Image is Index Color, the Resized Image is Converted to 256 Index Color.
			$is_conv_index = apply_filters( 'stillbe_image_quality_control_enable_png_index_color', STILLBE_IQ_ENABLE_INDEX_COLOR );

			// Force 256 Indexed Colors when Generating Resized Images.
			$is_force_index = apply_filters( 'stillbe_image_quality_control_enable_png_index_color_force', STILLBE_IQ_ENABLE_INDEX_COLOR_FORCE );

			// Convert Index Color
			if( $is_force_index || ( ! empty( $this->color_num ) && 256 >= $this->color_num && $is_conv_index ) ) {

				// Number of Used Colors
				$colors = min( 256, $this->get_colors() );
				$colors = 1 > $colors ? 256 : $colors;

				// Convert to Index Color
				$this->image->quantizeImage( $colors, \Imagick::COLORSPACE_SRGB, 0, false, false );
				$this->image->setImageDepth( 8 );

			}

		}

		// Set Interlace
		$is_added_progressive_filter = false;
		if( method_exists( $this->image, 'setInterlaceScheme' ) ) {

			if( 'image/jpeg' === $this->mime_type && apply_filters( 'stillbe_image_quality_control_enable_interlace_jpeg', STILLBE_IQ_ENABLE_INTERLACE_JPEG ) ) {
				$this->image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
				// refer; WP_Image_Editor_GD::_save   @since 6.5.0
				add_filter( 'image_save_progressive', '__return_true' );
				$is_added_progressive_filter = true;
			}

			if( 'image/png' === $this->mime_type && apply_filters( 'stillbe_image_quality_control_enable_interlace_png', STILLBE_IQ_ENABLE_INTERLACE_PNG ) ) {
				$this->image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
				// refer; WP_Image_Editor_GD::_save   @since 6.5.0
				add_filter( 'image_save_progressive', '__return_true' );
				$is_added_progressive_filter = true;
			}

		}

		// Strip EXIF Data
		if( apply_filters( 'stillbe_image_quality_control_enable_strip_exif', STILLBE_IQ_ENABLE_STRIP_EXIF ) ) {

			// Get the ICC Profile
			$profiles = $this->image->getImageProfiles( 'icc', true );

			// Strip EXIF & Comments
			$this->image->stripImage();

			// Restore the ICC Profile
			if( isset( $profiles['icc'] ) ) {
				$this->image->profileImage( 'icc', $profiles['icc'] );
			}

		}

		// WebP / AVIF: Imagick は setImageFormat 後に setCompressionQuality しないと
		// (特に AVIF) 品質が無視される。parent::_save() は format 前に品質を設定するため、
		// こちらで書き出す。
		// make_webp / make_avif 以外 (品質テストの save() 等) でも可逆オプションを適用する。
		if( in_array( $mime_type, array( 'image/webp', 'image/avif' ), true ) ) {
			$this->_prepare_png_lossless_encode( $mime_type );
			if( 'image/webp' === $mime_type ) {
				$options = apply_filters( 'still-be/image-quality-control/imagick/options/webp', self::OPTIONS_WEBP, array(), $this );
				$this->_apply_imagick_options( (array) $options, 'webp' );
			} else {
				$options = apply_filters( 'still-be/image-quality-control/imagick/options/avif', self::OPTIONS_AVIF, array(), $this );
				$this->_apply_imagick_options( (array) $options, 'heic' );
			}
			$force_png_lossless = ! empty( $this->force_png_lossless );
			$result = $this->_save_webp_or_avif( $filename, $mime_type, $force_png_lossless );
		} else {
			$force_png_lossless = false;
			$result = parent::_save( $image, $filename, $mime_type );
		}

		if( $force_png_lossless ) {
			$this->force_png_lossless = false;
		}

		// 同一リクエスト内の他画像へ影響しないように、追加したフィルターを解除する
		if( $is_added_progressive_filter ) {
			remove_filter( 'image_save_progressive', '__return_true' );
		}

		if( is_wp_error( $result ) ) {
			return $result;
		}

		// PNG ソース時 $quality は png:compression-level (1-9) になるため、
		// WebP / AVIF のメタには実際の出力品質を記録する
		$reported_quality = $this->get_quality();
		if( ! empty( $result['mime-type'] )
		    && ! in_array( $result['mime-type'], array( 'image/webp', 'image/avif' ), true )
		    && ! empty( $quality ) ) {
			$reported_quality = $quality;
		}

		$result['sb-iqc'] = array(
			'quality' => $reported_quality,
		);

		return $result;

	}


	/**
	 * Save WebP / AVIF with compression quality applied after setImageFormat.
	 *
	 * PHP Imagick's libheif path often ignores setImageCompressionQuality when it
	 * was set while the wand still had the source format (PNG/JPEG). WordPress
	 * core sets quality in get_output_format() before setImageFormat().
	 *
	 * @since 2.1.2
	 *
	 * @param string|null $filename           Destination path.
	 * @param string      $mime_type          image/webp or image/avif.
	 * @param bool        $force_png_lossless PNG lossless encode mode.
	 * @return array|\WP_Error
	 */
	protected function _save_webp_or_avif( $filename, $mime_type, $force_png_lossless = false ) {

		if( $force_png_lossless ) {
			add_filter( 'wp_editor_set_quality', array( $this, '_force_quality_100' ), PHP_INT_MAX, 3 );
		}

		try {

			list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

			if( ! $filename ) {
				$filename = $this->generate_filename( null, null, $extension );
			}

			$orig_format = $this->image->getImageFormat();
			$this->image->setImageFormat( strtoupper( $this->get_extension( $mime_type ) ) );

			// Imagick WebP: lossless=true + quality<100 は near-lossless になるため、
			// 真のロスレスでは書き込み・報告とも quality 100 とする。
			// (cwebp の -lossless -q N は N が努力度でロスレスのままなので別物)
			$quality = $force_png_lossless ? 100 : (int) $this->get_quality();
			if( $force_png_lossless ) {
				$this->quality = 100;
			}

			// Object-level quality is what AVIF/HEIC actually reads (Imagick #711).
			$this->image->setCompressionQuality( $quality );
			$this->image->setImageCompressionQuality( $quality );

			if( 'image/webp' === $mime_type && $force_png_lossless ) {
				$this->image->setOption( 'webp:lossless', 'true' );
			}

			$orig_interlace = null;
			if( method_exists( $this->image, 'setInterlaceScheme' )
			    && method_exists( $this->image, 'getInterlaceScheme' )
			    && defined( '\Imagick::INTERLACE_PLANE' ) ) {
				$orig_interlace = $this->image->getInterlaceScheme();
				if( apply_filters( 'image_save_progressive', false, $mime_type ) ) {
					$this->image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
				} else {
					$this->image->setInterlaceScheme( \Imagick::INTERLACE_NO );
				}
			}

			if( function_exists( 'wp_is_stream' ) && wp_is_stream( $filename ) ) {
				if( file_put_contents( $filename, $this->image->getImageBlob() ) === false ) {
					return new \WP_Error(
						'image_save_error',
						sprintf( __( 'Image Editor Save Failed: %s', 'still-be-image-quality-control' ), $filename )
					);
				}
			} else {
				$dirname = dirname( $filename );
				if( ! wp_is_stream( $dirname ) ) {
					wp_mkdir_p( $dirname );
				}
				$this->image->writeImage( $filename );
			}

			$this->image->setImageFormat( $orig_format );
			if( null !== $orig_interlace ) {
				$this->image->setInterlaceScheme( $orig_interlace );
			}

			$stat  = stat( dirname( $filename ) );
			$perms = $stat['mode'] & 0000666;
			chmod( $filename, $perms );

			return array(
				'path'      => $filename,
				'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
				'width'     => $this->size['width'],
				'height'    => $this->size['height'],
				'mime-type' => $mime_type,
				'filesize'  => function_exists( 'wp_filesize' ) ? wp_filesize( $filename ) : filesize( $filename ),
			);

		} catch( \Exception $e ) {

			return new \WP_Error( 'image_save_error', $e->getMessage(), $filename );

		} finally {

			if( $force_png_lossless ) {
				remove_filter( 'wp_editor_set_quality', array( $this, '_force_quality_100' ), PHP_INT_MAX );
			}

		}

	}


	// PNG の圧縮レベルを設定する
	public function stream( $mime_type = null ) {

		if( 'image/png' === $mime_type ) {

			$quality = Setting::chk_num_type( $this->get_quality(), 'png' );

			if( ! $quality ) {
				$quality = $this->get_quality_from_size( 'default', 'image/png' );
			}

			$this->image->setOption( 'png:compression-level', (string) $quality );

			// When the Original Image is Index Color, the Resized Image is Converted to 256 Index Color.
			$is_conv_index = apply_filters( 'stillbe_image_quality_control_enable_png_index_color', STILLBE_IQ_ENABLE_INDEX_COLOR );

			// Force 256 Indexed Colors when Generating Resized Images.
			$is_force_index = apply_filters( 'stillbe_image_quality_control_enable_png_index_color_force', STILLBE_IQ_ENABLE_INDEX_COLOR_FORCE );

			// Convert Index Color
			if( $is_force_index || ( ! empty( $this->color_num ) && 256 >= $this->color_num && $is_conv_index ) ) {

				// Number of Used Colors
				$colors = min( 256, $this->get_colors() );
				$colors = 1 > $colors ? 256 : $colors;

				// Convert to Index Color
				$this->image->quantizeImage( $colors, \Imagick::COLORSPACE_SRGB, 0, false, false );
				$this->image->setImageDepth( 8 );

			}

		}

		// Set Interlace
		if( method_exists( $this->image, 'setInterlaceScheme' ) && apply_filters( 'stillbe_image_quality_control_enable_interlace', STILLBE_IQ_ENABLE_INTERLACE ) ) {
			$this->image->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
		}

		return parent::stream( $mime_type );

	}


	// Overwrite 'supports_mime_type' for using 'cwebp'
	public static function supports_mime_type( $mime_type ) {

		if( 'image/webp' === $mime_type ) {
			if( apply_filters( 'stillbe_image_quality_control_enable_cwebp_lib', STILLBE_IQ_ENABLE_CWEBP_LIBRARY )
			      && stillbe_iqc_is_enabled_cwebp() ) {
				return true;
			}
		}

		return parent::supports_mime_type( $mime_type );

	}


	// Make WebP Function with Imagick Library
	protected function _make_webp_embed_library( $filename = null, $size_data = array() ) {

		// Check Filename
		if( empty( $filename ) ) {
			return new \WP_Error( 'error_webp_filename', __( 'No WebP filename has be passed' ) );
		}

		// Store Original Size & Image
		$orig_size  = $this->size;
		$orig_image = $this->image->getImage();

		// Max Size
		if( ! isset( $size_data['width'] ) ) {
			$size_data['width'] = $this->size['width'];
		}
		if ( ! isset( $size_data['height'] ) ) {
			$size_data['height'] = $this->size['height'];
		}
		if ( ! isset( $size_data['crop'] ) ) {
			$size_data['crop'] = false;
		}

		// Get the Quality
		$current_quality = $this->get_quality();
		$size            = empty( $size_data['size_name'] ) ? 'default' : $size_data['size_name'];
		$webp_quality    = $this->get_quality_from_size( $size, 'image/webp' );
		$this->set_quality( $webp_quality );

		// WP 5.8 対応で hook を追加する (@since 0.8.0)
		$this->_set_mk_size( $size );
		$this->_set_mk_mime( 'image/webp' );
		add_filter( 'wp_editor_set_quality', array( $this, '_set_quality_hook' ), 1, 2 );

		// Resize
		$resized = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

		// Save
		if( is_wp_error( $resized ) ) {
			$this->size  = $orig_size;
			$this->image->clear();
			$this->image->destroy();
			$this->image = null;
			$this->image = $orig_image;
			$this->set_quality( $current_quality );
			// WP 5.8 対応で追加した hook を削除する (@since 0.8.0)
			remove_filter( 'wp_editor_set_quality', array( $this, '_set_quality_hook' ), 1 );
			return $resized;
		} else {
			/**
			 * Filters Imagick options applied immediately before WebP encoding.
			 *
			 * Lossless and other encode tweaks can be injected here.
			 *
			 * @since 2.1.1
			 *
			 * @param array                 $options   Option name => value for Imagick::setOption().
			 * @param array                 $size_data Size / crop data for this conversion.
			 * @param Image_Editor_Imagick  $editor    Current image editor instance.
			 */
			$this->_prepare_png_lossless_encode( 'image/webp' );
			$encode_method = ! empty( $this->force_png_lossless ) ? 'lossless' : 'lossy';
			$options = apply_filters( 'still-be/image-quality-control/imagick/options/webp', self::OPTIONS_WEBP, $size_data, $this );
			$this->_apply_imagick_options( (array) $options, 'webp' );
			$saved = $this->_save( $resized, $filename, 'image/webp' );
			$this->output_mime_type = null;   // @since 0.10.9
			$this->image->clear();
			$this->image->destroy();
			$this->image = null;
		}

		if( is_wp_error( $saved ) ) {
			return $saved;
		}

		$file_size   = @filesize( $saved['path'] );
		$_quality  = $this->get_quality();

		$this->size  = $orig_size;
		$this->image = $orig_image;
		$this->set_quality( $current_quality );

		// WP 5.8 対応で追加した hook を削除する (@since 0.8.0)
		remove_filter( 'wp_editor_set_quality', array( $this, '_set_quality_hook' ), 1 );

		return array(
			'path'   => $saved['path'],
			'file'   => $saved['file'],
			'size'   => $file_size,
			'q'      => $_quality,
			'method' => isset( $encode_method ) ? $encode_method : 'lossy',
		);

	}


	// Make AVIF Function with Imagick Library
	protected function _make_avif_embed_library( $filename = null, $size_data = array() ) {

		// Check Filename
		if( empty( $filename ) ) {
			return new \WP_Error( 'error_avif_filename', __( 'No AVIF filename has be passed' ) );
		}

		if( ! self::supports_mime_type( 'image/avif' ) ) {
			return new \WP_Error( 'image_avif_unsupported', __( 'AVIF is not supported by Imagick on this server.', 'still-be-image-quality-control' ) );
		}

		// Store Original Size & Image
		$orig_size  = $this->size;
		$orig_image = $this->image->getImage();

		// Max Size
		if( ! isset( $size_data['width'] ) ) {
			$size_data['width'] = $this->size['width'];
		}
		if ( ! isset( $size_data['height'] ) ) {
			$size_data['height'] = $this->size['height'];
		}
		if ( ! isset( $size_data['crop'] ) ) {
			$size_data['crop'] = false;
		}

		// Get the Quality
		$current_quality = $this->get_quality();
		$size            = empty( $size_data['size_name'] ) ? 'default' : $size_data['size_name'];
		$avif_quality    = $this->get_quality_from_size( $size, 'image/avif' );
		$this->set_quality( $avif_quality );

		$this->_set_mk_size( $size );
		$this->_set_mk_mime( 'image/avif' );
		add_filter( 'wp_editor_set_quality', array( $this, '_set_quality_hook' ), 1, 2 );

		// Resize
		$resized = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

		// Save
		if( is_wp_error( $resized ) ) {
			$this->size  = $orig_size;
			$this->image->clear();
			$this->image->destroy();
			$this->image = null;
			$this->image = $orig_image;
			$this->set_quality( $current_quality );
			remove_filter( 'wp_editor_set_quality', array( $this, '_set_quality_hook' ), 1 );
			return $resized;
		} else {
			/**
			 * Filters Imagick options applied immediately before AVIF encoding.
			 *
			 * Lossless and other encode tweaks can be injected here.
			 *
			 * @since 2.1.1
			 *
			 * @param array                 $options   Option name => value for Imagick::setOption().
			 * @param array                 $size_data Size / crop data for this conversion.
			 * @param Image_Editor_Imagick  $editor    Current image editor instance.
			 */
			$this->_prepare_png_lossless_encode( 'image/avif' );
			$encode_method = stillbe_iqc_resolve_avif_encode_mode(
				! empty( $this->force_png_lossless ) ? 100 : $this->get_quality(),
				self::IMAGE_EDITOR_LIBRARY
			);
			$options = apply_filters( 'still-be/image-quality-control/imagick/options/avif', self::OPTIONS_AVIF, $size_data, $this );
			$this->_apply_imagick_options( (array) $options, 'heic' );
			$saved = $this->_save( $resized, $filename, 'image/avif' );
			$this->output_mime_type = null;
			$this->image->clear();
			$this->image->destroy();
			$this->image = null;
		}

		if( is_wp_error( $saved ) ) {
			return $saved;
		}

		$file_size = @filesize( $saved['path'] );
		$_quality  = $this->get_quality();

		$this->size  = $orig_size;
		$this->image = $orig_image;
		$this->set_quality( $current_quality );

		remove_filter( 'wp_editor_set_quality', array( $this, '_set_quality_hook' ), 1 );

		return array(
			'path'   => $saved['path'],
			'file'   => $saved['file'],
			'size'   => $file_size,
			'q'      => $_quality,
			'method' => isset( $encode_method ) ? $encode_method : 'lossy',
		);

	}


	/**
	 * Apply Imagick encode options after lightweight sanitization.
	 *
	 * Only `{prefix}:{option}` names are accepted. The option part must be
	 * alphanumeric or hyphen. Values are cast to string without further checks.
	 *
	 * @since 2.1.1
	 *
	 * @param array  $options Option name => value.
	 * @param string $prefix  Allowed prefix without colon (e.g. webp, heic).
	 */
	protected function _apply_imagick_options( $options, $prefix ) {

		$prefix = (string) $prefix;
		if( '' === $prefix || ! preg_match( '/^[a-zA-Z0-9-]+$/', $prefix ) ) {
			return;
		}

		$pattern = '/^' . preg_quote( $prefix, '/' ) . ':[a-zA-Z0-9-]+$/';

		foreach( (array) $options as $name => $value ) {
			$name = (string) $name;
			if( ! preg_match( $pattern, $name ) ) {
				continue;
			}
			$this->image->setOption( $name, (string) $value );
		}

	}


	/**
	 * Whether PNG/GIF lossless encode options should be applied for this editor.
	 *
	 * Source type is taken from the editor mime and the loaded file when possible.
	 *
	 * @since 2.1.1
	 *
	 * @param mixed $editor Image editor instance.
	 * @return bool
	 */
	protected static function _should_apply_png_lossless( $editor ) {

		if( ! ( $editor instanceof self ) ) {
			return false;
		}

		if( ! apply_filters( 'stillbe_image_quality_control_enable_webp_lossless_for_png_gif', STILLBE_IQ_ENABLE_WEBP_LOSSLESS ) ) {
			return false;
		}

		$candidates = array();
		if( ! empty( $editor->mime_type ) ) {
			$candidates[] = (string) $editor->mime_type;
		}
		if( ! empty( $editor->file ) ) {
			$candidates[] = (string) wp_get_image_mime( $editor->file );
		}

		foreach( array_unique( array_filter( $candidates ) ) as $mime ) {
			if( in_array( $mime, array( 'image/png', 'image/gif', 'image/x-png' ), true ) ) {
				return true;
			}
		}

		return false;

	}


	/**
	 * Mark this encode as PNG lossless and pin quality to 100.
	 *
	 * Imagick WebP requires quality 100 for true lossless; quality &lt; 100 with
	 * webp:lossless becomes near-lossless. AVIF lossless also requires quality 100.
	 *
	 * @since 2.1.2
	 *
	 * @param string $output_mime image/webp or image/avif.
	 */
	protected function _prepare_png_lossless_encode( $output_mime ) {

		if( ! self::_should_apply_png_lossless( $this ) ) {
			return;
		}

		$this->force_png_lossless = true;
		self::_set_lossless_quality( $this, 100 );

		if( 'image/webp' === $output_mime ) {
			$this->image->setOption( 'webp:lossless', 'true' );
		}

	}


	/**
	 * Force compression quality without wp_editor_set_quality overrides.
	 *
	 * `_set_quality_hook` would otherwise replace an explicit 100 with the
	 * AVIF/WebP table value (AVIF is capped at 99 for lossy mode).
	 *
	 * @since 2.1.1
	 *
	 * @param Image_Editor_Imagick $editor  Editor instance.
	 * @param int                  $quality Quality to set.
	 */
	protected static function _set_lossless_quality( $editor, $quality ) {

		remove_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1 );
		$editor->set_quality( (int) $quality );
		add_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1, 2 );

	}


	/**
	 * Always return quality 100 (used while parent::_save resets quality).
	 *
	 * @since 2.1.1
	 *
	 * @param int    $quality   Quality from previous filters.
	 * @param string $mime_type Output mime type.
	 * @param array  $size      Image size.
	 * @return int
	 */
	public function _force_quality_100( $quality, $mime_type = '', $size = array() ) {

		return 100;

	}


	/**
	 * Inject WebP lossless option for PNG sources.
	 *
	 * @since 2.1.1
	 *
	 * @param array                $options   Imagick options.
	 * @param array                $size_data Size data.
	 * @param Image_Editor_Imagick $editor    Editor instance.
	 * @return array
	 */
	public static function filter_imagick_options_webp_lossless( $options, $size_data, $editor ) {

		if( ! self::_should_apply_png_lossless( $editor ) ) {
			return $options;
		}

		$options = (array) $options;
		$options['webp:lossless'] = 'true';
		$editor->force_png_lossless = true;
		// quality < 100 + lossless は near-lossless になるため 100 に固定する
		self::_set_lossless_quality( $editor, 100 );

		return $options;

	}


	/**
	 * Switch AVIF to lossless (quality 100) for PNG sources and drop 4:2:0 chroma.
	 *
	 * @since 2.1.1
	 *
	 * @param array                $options   Imagick options.
	 * @param array                $size_data Size data.
	 * @param Image_Editor_Imagick $editor    Editor instance.
	 * @return array
	 */
	public static function filter_imagick_options_avif_lossless( $options, $size_data, $editor ) {

		if( ! self::_should_apply_png_lossless( $editor ) ) {
			return $options;
		}

		$options = (array) $options;
		unset( $options['heic:chroma'] );
		$editor->force_png_lossless = true;

		self::_set_lossless_quality( $editor, 100 );

		return $options;

	}


	// Convert to True Color
	public function conv2truecolor() {

		$this->image->setImageType( \Imagick::IMGTYPE_TRUECOLOR );

	}


	// Get Number of Used Colors
	public function get_colors( $image = null ) {

		if( empty( $image ) ) {
			$image = $this->image;
		}

		return (int) $image->getImageColors();

	}


	protected function _get_grayscale_luminance_array() {

		if( ! empty( $this->luminance_array ) ) {
			return $this->luminance_array;
		}

		// グレースケール画像を作成
	//	$grayscale_image = clone $this->image;
	//	$grayscale_image->transformImageColorspace( \Imagick::COLORSPACE_GRAY );

	//	$raw_pixels = $grayscale_image->exportImagePixels( 0, 0, $this->size['width'], $this->size['height'], 'I', \Imagick::PIXEL_CHAR );
		$raw_pixels = $this->image->exportImagePixels( 0, 0, $this->size['width'], $this->size['height'], 'I', \Imagick::PIXEL_CHAR );

		// 値を取得してキャッシュする
		$this->luminance_array = \SplFixedArray::fromArray( $raw_pixels );

		// グレースケール画像を破棄する
	//	$grayscale_image->clear();

		return $this->luminance_array;

	}


	// Get Image Depth
	public function get_image_depth() {

		return $this->image->getImageDepth();

	}


	/**
	 * Edge Residual Mean 用に Imagick 画像のコピーを返す (色空間は変換しない)
	 *
	 * 呼び出し側で clear()/destroy() すること。
	 *
	 * @return \Imagick|\WP_Error
	 */
	public function export_image_copy() {

		try {
			return clone $this->image;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'imagick_export_failed', $e->getMessage() );
		}

	}


	/**
	 * Edge Residual Mean 用にグレースケール化した Imagick 画像のコピーを返す
	 *
	 * 呼び出し側で clear()/destroy() すること。
	 *
	 * @return \Imagick|\WP_Error
	 */
	public function export_grayscale_image() {

		try {
			$img = clone $this->image;
			$img->transformImageColorspace( \Imagick::COLORSPACE_GRAY );
			return $img;
		} catch ( \Exception $e ) {
			return new \WP_Error( 'imagick_gray_failed', $e->getMessage() );
		}

	}


}





// END of the File



