<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




/**
 * 管理画面操作用 REST API
 *
 * Namespace: stillbe-iqc/v1
 *
 * @since 2.0.0
 */
class Rest_Api {


	const REST_NAMESPACE = 'stillbe-iqc/v1';

	private static $instance = null;


	public static function init() {
		if( empty( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}


	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_binary_image' ), 10, 4 );
	}


	/**
	 * ルート登録
	 */
	public function register_routes() {

		register_rest_route( self::REST_NAMESPACE, '/attachments/(?P<id>\d+)/meta', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_attachment_meta' ),
			'permission_callback' => array( $this, 'permission_read_attachment' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/attachments/(?P<id>\d+)/optimize-status', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_optimize_status' ),
			'permission_callback' => array( $this, 'permission_read_attachment' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/attachments/(?P<id>\d+)/regenerate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'regenerate_attachment' ),
			'permission_callback' => array( $this, 'permission_edit_attachment' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/regenerate', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'regenerate_batch_next' ),
			'permission_callback' => array( $this, 'permission_manage_options' ),
		) );

		register_rest_route( self::REST_NAMESPACE, '/attachment-ids', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'get_attachment_ids' ),
			'permission_callback' => array( $this, 'permission_manage_options' ),
		) );

		register_rest_route( self::REST_NAMESPACE, '/settings/reset', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'reset_settings' ),
			'permission_callback' => array( $this, 'permission_manage_options' ),
		) );

		register_rest_route( self::REST_NAMESPACE, '/attachments/(?P<id>\d+)/test-image', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'generate_test_image' ),
			'permission_callback' => array( $this, 'permission_manage_options' ),
			'args'                => array(
				'id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'size' => array(
					'required' => true,
					'type'     => 'string',
				),
				'mime' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => '',
				),
				'quality' => array(
					'required' => false,
					'type'     => 'string',
					'default'  => '',
				),
				'filters' => array(
					'required' => false,
					'type'     => 'object',
					'default'  => array(),
				),
			),
		) );

		register_rest_route( self::REST_NAMESPACE, '/test-image-info', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'get_test_image_info' ),
			'permission_callback' => array( $this, 'permission_manage_options' ),
			'args'                => array(
				'key' => array(
					'required' => true,
					'type'     => 'string',
				),
			),
		) );

	}


	// --- Permissions ---

	public function permission_manage_options() {
		return current_user_can( 'manage_options' );
	}


	public function permission_read_attachment( $request ) {
		$id = absint( $request['id'] );
		return $id && current_user_can( 'read_post', $id );
	}


	public function permission_edit_attachment( $request ) {
		$id = absint( $request['id'] );
		return $id && current_user_can( 'edit_post', $id );
	}


	// --- Handlers ---

	/**
	 * GET /attachments/{id}/meta
	 */
	public function get_attachment_meta( $request ) {

		$attachment_id = absint( $request['id'] );
		$meta          = wp_get_attachment_metadata( $attachment_id );

		if( empty( $meta ) || empty( $meta['file'] ) ) {
			return array(
				'ok'      => false,
				'id'      => $attachment_id,
				'message' => sprintf( esc_html__( 'Attachment ID = %d is not found....', 'still-be-image-quality-control' ), $attachment_id ),
			);
		}

		$meta = $this->_enrich_sb_iqc_encode_modes( $meta );

		return array(
			'ok'        => true,
			'id'        => $attachment_id,
			'mime_type' => (string) get_post_mime_type( $attachment_id ),
			'meta'      => $meta,
			'message'   => esc_html__( 'Success !!', 'still-be-image-quality-control' ),
		);

	}


	/**
	 * Fill missing webp/avif encode modes for the compression info UI.
	 *
	 * Older metadata only stored quality. Imagick WebP without cwebp was always
	 * shown as "lossy" even when the file was lossless.
	 *
	 * @since 2.1.2
	 *
	 * @param array $meta Attachment metadata.
	 * @return array
	 */
	protected function _enrich_sb_iqc_encode_modes( $meta ) {

		$uploads = wp_get_upload_dir();
		if( empty( $uploads['basedir'] ) || empty( $meta['file'] ) ) {
			return $meta;
		}

		$base_dir = path_join( $uploads['basedir'], dirname( $meta['file'] ) );

		if( ! empty( $meta['sb-iqc'] ) && is_array( $meta['sb-iqc'] ) ) {
			$meta['sb-iqc'] = $this->_enrich_one_sb_iqc_encode_modes( $meta['sb-iqc'], $base_dir );
		}

		if( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach( $meta['sizes'] as $size_name => $size_data ) {
				if( empty( $size_data['sb-iqc'] ) || ! is_array( $size_data['sb-iqc'] ) ) {
					continue;
				}
				$meta['sizes'][ $size_name ]['sb-iqc'] = $this->_enrich_one_sb_iqc_encode_modes(
					$size_data['sb-iqc'],
					$base_dir
				);
			}
		}

		return $meta;

	}


	/**
	 * @since 2.1.2
	 *
	 * @param array  $sb_iqc  sb-iqc meta.
	 * @param string $base_dir Upload subdirectory for this attachment.
	 * @return array
	 */
	protected function _enrich_one_sb_iqc_encode_modes( $sb_iqc, $base_dir ) {

		if( empty( $sb_iqc['webp-method'] ) && empty( $sb_iqc['cwebp']['method'] ) && ! empty( $sb_iqc['webp-file'] ) ) {
			$webp_path = path_join( $base_dir, $sb_iqc['webp-file'] );
			$mode      = stillbe_iqc_detect_webp_encode_mode( $webp_path );
			if( '' !== $mode ) {
				$sb_iqc['webp-method'] = $mode;
			}
		} elseif( empty( $sb_iqc['webp-method'] ) && ! empty( $sb_iqc['cwebp']['method'] ) ) {
			$sb_iqc['webp-method'] = $sb_iqc['cwebp']['method'];
		}

		if( empty( $sb_iqc['avif-method'] ) && isset( $sb_iqc['avif-quality'] ) && '' !== $sb_iqc['avif-quality'] && null !== $sb_iqc['avif-quality'] ) {
			// Prefer the site's AVIF editor library; GD never produces lossless AVIF.
			$library = '';
			$avif_editor_class = stillbe_iqc_get_avif_capable_editor_class();
			if( '' !== $avif_editor_class && defined( $avif_editor_class. '::IMAGE_EDITOR_LIBRARY' ) ) {
				$library = $avif_editor_class::IMAGE_EDITOR_LIBRARY;
			}
			$sb_iqc['avif-method'] = stillbe_iqc_resolve_avif_encode_mode( $sb_iqc['avif-quality'], $library );
		}

		return $sb_iqc;

	}


	/**
	 * GET /attachments/{id}/optimize-status
	 */
	public function get_optimize_status( $request ) {

		$attachment_id = absint( $request['id'] );

		if( ! get_post( $attachment_id ) ) {
			return array(
				'ok'      => false,
				'id'      => $attachment_id,
				'message' => sprintf( esc_html__( 'Attachment ID = %d is not found....', 'still-be-image-quality-control' ), $attachment_id ),
			);
		}

		$status = Auto_Optimize_Progress::get_activity_status( $attachment_id );

		ob_start();
		Auto_Optimize_Progress::render_media_library_column( $attachment_id );
		$html = ob_get_clean();

		return array(
			'ok'    => true,
			'id'    => $attachment_id,
			'state' => $status['state'],
			'live'  => ! empty( $status['live'] ),
			'html'  => $html,
		);

	}


	/**
	 * POST /attachments/{id}/regenerate
	 */
	public function regenerate_attachment( $request ) {
		return $this->_regenerate_response( absint( $request['id'] ) );
	}


	/**
	 * POST /regenerate (batch next)
	 */
	public function regenerate_batch_next( $request ) {
		return $this->_regenerate_response( 0 );
	}


	/**
	 * 再圧縮結果を既存 JS 互換の形で返す
	 *
	 * @param int $attachment_id 0 = キューの次件
	 * @return array
	 */
	private function _regenerate_response( $attachment_id ) {

		// PNG 可逆や AVIF 生成は時間が掛かるため、自動最適化と同様に実行時間を引き上げる
		stillbe_iqc_raise_wpcron_time_limit( 'regenerate' );

		$result = stillbe_iqc_regenerate_images( $attachment_id );

		if( null === $result || ! is_array( $result ) ) {
			return array(
				'ok'      => false,
				'id'      => null,
				'message' => __( 'Attachment ID is not accepted....', 'still-be-image-quality-control' ),
			);
		}

		$attachment_ids  = (array) get_option( '_sb-iqc-image-ids', array() );
		$generated_id    = isset( $result['id'] ) ? (int) $result['id'] : 0;
		$generated_index = (int) array_search( $generated_id, $attachment_ids, true );
		$progress_ratio  = ( $generated_index + 1 ) / max( 1, count( $attachment_ids ) );
		$next_index      = $generated_index + 1;
		$next_id         = isset( $attachment_ids[ $next_index ] ) ? $attachment_ids[ $next_index ] : 0;

		$result['genereted_id']    = $generated_id;
		$result['genereted_index'] = $generated_index;
		$result['progress_ratio']  = $progress_ratio;
		$result['next_id']         = $next_id;
		$result['next_index']      = isset( $attachment_ids[ $next_index ] ) ? $next_index : null;

		if( ! empty( $result['completed'] ) ) {
			$settings   = get_option( Setting::SETTING_NAME, array() );
			$auto_regen = isset( $settings['auto-regen-wpcron'] ) ? $settings['auto-regen-wpcron']    : array();
			$_interval  = isset( $auto_regen['interval']        ) ? absint( $auto_regen['interval'] ) : 60;
			$settings['auto-regen-wpcron'] = array(
				'number'   => 0,
				'interval' => $_interval,
			);
			update_option( Setting::SETTING_NAME, $settings );
		}

		return $result;

	}


	/**
	 * POST /attachment-ids
	 */
	public function get_attachment_ids( $request ) {

		$target = $request->get_param( 'target' );
		if( is_string( $target ) ) {
			$decoded = json_decode( $target, true );
			$target  = is_array( $decoded ) ? $decoded : array();
		} elseif( ! is_array( $target ) ) {
			$target = array();
		}

		update_option( '_sb-iqc-recomp-target-condition', $target, false );

		$get_ids        = stillbe_iqc_get_attachment_ids( $target );
		$attachment_ids = isset( $get_ids['ids']  ) ? $get_ids['ids']  : $get_ids;
		$args           = isset( $get_ids['args'] ) ? $get_ids['args'] : null;

		update_option( '_sb-iqc-current-id', 0,               false );
		update_option( '_sb-iqc-image-ids',  $attachment_ids, false );

		return array(
			'ok'      => true,
			'message' => __( 'Return IDs of all images!!', 'still-be-image-quality-control' ),
			'ids'     => $attachment_ids,
			'target'  => $target,
			'args'    => $args,
		);

	}


	/**
	 * POST /settings/reset
	 */
	public function reset_settings( $request ) {

		$result = delete_option( Setting::SETTING_NAME );

		delete_option( '_sb-iqc-image-ids' );
		delete_option( '_sb-iqc-current-id' );
		delete_option( '_sb-iqc-recomp-target-condition' );

		return array(
			'ok'      => $result,
			'message' => $result ? __( 'The settings have been reset!!', 'still-be-image-quality-control' ) :
			                       __( 'Reset failed or it is not set.', 'still-be-image-quality-control' ),
			'deleted' => $result ? ( 'Option Name: '. Setting::SETTING_NAME ) : 'null',
		);

	}


	/**
	 * GET /test-image-info
	 */
	public function get_test_image_info( $request ) {

		$info_key = (string) $request->get_param( 'key' );

		if( 0 !== strpos( $info_key, '_sb-iqc-' ) ) {
			return array(
				'ok'   => false,
				'key'  => $info_key,
				'info' => null,
			);
		}

		$info = get_user_meta( get_current_user_id(), $info_key, true );
		delete_user_meta( get_current_user_id(), $info_key );

		return array(
			'ok'   => ! empty( $info ),
			'key'  => $info_key,
			'info' => $info,
		);

	}


	/**
	 * GET /attachments/{id}/test-image
	 *
	 * 成功時はバイナリ画像 + X-IQC-Info-Key。失敗時は JSON (ok: false)。
	 */
	public function generate_test_image( $request ) {

		$attachment_id = absint( $request['id'] );
		$size          = (string) $request->get_param( 'size' );
		$mime          = (string) $request->get_param( 'mime' );
		$quality = $request->get_param( 'quality' );
		$filters = $request->get_param( 'filters' );
		if( ! is_array( $filters ) ) {
			// filters[hook]=1 形式のクエリを拾う
			$query   = $request->get_query_params();
			$filters = ( isset( $query['filters'] ) && is_array( $query['filters'] ) ) ? $query['filters'] : array();
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if( empty( $meta ) || empty( $meta['file'] ) ) {
			return array(
				'ok'      => false,
				'id'      => $attachment_id,
				'message' => sprintf( esc_html__( 'Attachment ID = %d is not found....', 'still-be-image-quality-control' ), $attachment_id ),
			);
		}

		$upload_dir = wp_upload_dir();
		$filename   = $upload_dir['basedir']. '/'. $meta['file'];
		if( ! file_exists( $filename ) ) {
			return array(
				'ok'      => false,
				'id'      => $attachment_id,
				'meta'    => $meta,
				'file'    => $filename,
				'message' => sprintf( esc_html__( 'Attachment ID = %d is not exists....', 'still-be-image-quality-control' ), $attachment_id ),
			);
		}

		$sizes = wp_get_registered_image_subsizes();
		if( 'Original' !== $size && ( empty( $size ) || empty( $sizes[ $size ] ) ) ) {
			return array(
				'ok'      => false,
				'id'      => $attachment_id,
				'size'    => array(
					'request' => $size,
					'sizes'   => $sizes,
				),
				'message' => sprintf( esc_html__( 'Size name = %s is not found....', 'still-be-image-quality-control' ), $size ),
			);
		}

		$img_width  = $meta['width'];
		$img_height = $meta['height'];
		$max_width  = empty( $sizes[ $size ] ) ? 0 : $sizes[ $size ]['width'];
		$max_height = empty( $sizes[ $size ] ) ? 0 : $sizes[ $size ]['height'];
		if( 'Original' !== $size && $max_width >= $img_width && $max_height >= $img_height ) {
			return array(
				'ok'      => false,
				'id'      => $attachment_id,
				'message' => sprintf( esc_html__( 'Size; %s  cannot be generated because it is larger than the original image....', 'still-be-image-quality-control' ), $size ),
				'size'    => array(
					'request' => $size,
					'sizes'   => $sizes,
				),
			);
		}

		$allow_mimes = array( 'jpeg', 'png', 'webp', 'avif' );
		if( ! in_array( $mime, $allow_mimes, true ) ) {
			$mime = wp_get_image_mime( $filename );
			if( ! $mime || false === strpos( $mime, 'image/' ) ) {
				return array(
					'ok'      => false,
					'id'      => $attachment_id,
					'mime'    => $mime,
					'message' => esc_html__( 'Could not get the supported Mime-Type....', 'still-be-image-quality-control' ),
				);
			}
			$mime = str_replace( 'image/', '', $mime );
		}
		$mime = strtolower( $mime );

		$quality = Setting::chk_num_type( $quality, $mime, 'avif' === $mime );

		if( ! is_array( $filters ) ) {
			$filters = array();
		}

		require_once( ABSPATH. 'wp-admin/includes/image.php' );

		// AVIF は通常の wp_get_image_editor() では非対応のことがあるため、対応エディタを明示選択する
		if( 'avif' === $mime ) {
			$avif_editor_class = stillbe_iqc_get_avif_capable_editor_class();
			if( '' === $avif_editor_class ) {
				return array(
					'ok'      => false,
					'id'      => $attachment_id,
					'message' => sprintf( esc_html__( 'Image Editor does not support "%s"', 'still-be-image-quality-control' ), 'image/avif' ),
				);
			}
			$editor = new $avif_editor_class( $filename );
			$loaded = $editor->load();
			if( is_wp_error( $loaded ) ) {
				return array(
					'ok'      => false,
					'id'      => $attachment_id,
					'message' => esc_html__( 'The WP Image Editor could not be initialized....', 'still-be-image-quality-control' ),
				);
			}
		} else {
			$editor = wp_get_image_editor( $filename );
			if( is_wp_error( $editor ) ) {
				return array(
					'ok'      => false,
					'id'      => $attachment_id,
					'message' => esc_html__( 'The WP Image Editor could not be initialized....', 'still-be-image-quality-control' ),
				);
			}

			if( ! $editor::supports_mime_type( 'image/'. $mime ) ) {
				return array(
					'ok'      => false,
					'id'      => $attachment_id,
					'message' => sprintf( esc_html__( 'Image Editor does not support "%s"', 'still-be-image-quality-control' ), esc_html( 'image/'. $mime ) ),
				);
			}
		}

		$applied_filters = array();
		foreach( $filters as $hook => $boolern ) {
			$_hook = trim( (string) $hook );
			if( 0 !== strpos( $_hook, 'stillbe_image_quality_control_enable_' ) ) {
				continue;
			}
			$toggle = ! empty( $boolern );
			add_filter( $_hook, ( $toggle ? '__return_true' : '__return_false' ), 99999 );
			$applied_filters[ $_hook ] = $toggle;
		}

		$is_conv_cwebp = false;
		$size_data     = null;
		$options       = null;
		if( apply_filters( 'stillbe_image_quality_control_enable_cwebp_lib', STILLBE_IQ_ENABLE_CWEBP_LIBRARY )
		      && 'webp' === $mime && stillbe_iqc_is_enabled_cwebp() ) {
			$is_conv_cwebp = true;
			$webp_quality  = empty( $quality ) ? $editor->get_quality_from_size( $size, 'image/webp' )                 : $quality;
			$quality       = empty( $quality ) ? $editor->get_quality_from_size( $size, $editor->get_original_mime() ) : $quality;
			$options = array(
				'quality' => array( $webp_quality, $quality ),
				'mime'    => $editor->get_original_mime(),
				'size'    => $editor->get_size(),
			);
			$size_data = 'Original' === $size ? $editor->get_size() : $sizes[ $size ];
		}

		$start_time = microtime( true );

		if( ! $is_conv_cwebp ) {
			if( 'Original' !== $size ) {
				$editor->resize( $sizes[ $size ]['width'], $sizes[ $size ]['height'], $sizes[ $size ]['crop'] );
			} elseif( 'webp' === $mime || 'avif' === $mime ) {
				$editor->conv2truecolor();
			}

			if( 'Original' === $size && empty( $quality ) ) {
				switch( $mime ) {
					case 'jpeg':
						$quality = 100;
					break;
					case 'png':
						$quality = 9;
					break;
					case 'webp':
						$quality = $editor->get_quality_from_size( 'original', 'image/webp' );
					break;
					case 'avif':
						$quality = $editor->get_quality_from_size( 'original', 'image/avif' );
					break;
				}
			} elseif( empty( $quality ) ) {
				$quality = $editor->get_quality_from_size( $size, 'image/'. $mime );
			}
			$editor->set_quality( $quality );

			$editor->_set_mk_size( $size );
			$editor->_set_mk_quality( $quality, $mime );
			add_filter( 'wp_editor_set_quality', array( $editor, '_set_quality_hook' ), 1, 2 );
		}

		$info_key      = uniqid( '_sb-iqc-', true );
		$mime_type     = "image/{$mime}";
		$extension     = wp_get_default_extension_for_mime_type( $mime_type );

		if( ! function_exists( 'wp_tempnam' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}
		$temp_filename = wp_tempnam(). '.'. $extension;

		if( $is_conv_cwebp ) {
			$result = stillbe_iqc_extends_conv_cwebp( $filename, $temp_filename, $size_data, $options );
		} else {
			$result = $editor->save( $temp_filename, $mime_type );
		}

		if( false !== $result && ! is_wp_error( $result ) && file_exists( $temp_filename ) ) {
			$decimals   = 0;
			$dec_multi  = 10 ** $decimals;
			$processing = ( intval( ( microtime( true ) - $start_time ) * 1000 * $dec_multi ) / $dec_multi ). 'ms';
			$memory     = ( intval( memory_get_peak_usage() / ( 1024 * 1024 ) * $dec_multi ) / $dec_multi ). 'MiB';
			$colors     = $editor->get_colors();
			$original   = $editor->get_original_color_num();

			$cwebp_meta = ( $is_conv_cwebp && isset( $result['cwebp'] ) && is_array( $result['cwebp'] ) ) ? $result['cwebp'] : array();

			if( $is_conv_cwebp ) {
				$image_editor_label = 'cwebp';
			} else {
				$class   = get_class( $editor );
				$library = defined( "{$class}::IMAGE_EDITOR_LIBRARY" ) ? $class::IMAGE_EDITOR_LIBRARY : '';
				if( 'imagick' === $library ) {
					$image_editor_label = 'Imagick';
				} elseif( 'gd' === $library ) {
					$image_editor_label = 'GD';
				} else {
					$image_editor_label = '' !== $library ? $library : '-';
				}
			}

			$conversion_info = array(
				'Quality-Level' => ( $is_conv_cwebp
					? ( isset( $cwebp_meta['quality'] ) ? $cwebp_meta['quality'] : ( $options['quality'][0] ?? $quality ) )
					: $editor->get_quality() ),
				'Image-Editor'  => $image_editor_label,
				'Convert-Time'  => $processing,
				'Memory-Peak'   => $memory,
				'Using-Colors'  => $colors,
				'Original-Num'  => $original,
				'Filters'       => json_encode( $applied_filters ),
				'Using-Cwebp'   => ( $is_conv_cwebp ? 'true' : 'false' ),
			);

			if( $is_conv_cwebp ) {
				$conversion_info['Encode-Mode']       = isset( $cwebp_meta['method'] ) ? $cwebp_meta['method'] : '-';
				$conversion_info['Compression-Level'] = ( empty( $result['q'] ) ? '0' : $result['q'] );
			} elseif( in_array( $mime, array( 'webp', 'avif' ), true ) ) {
				// Imagick / GD: テスト UI の Compression Mode 表示用
				if( 'webp' === $mime ) {
					$encode_mode = stillbe_iqc_detect_webp_encode_mode( $temp_filename );
					$conversion_info['Encode-Mode'] = ( '' !== $encode_mode ) ? $encode_mode : 'lossy';
				} else {
					$class   = get_class( $editor );
					$library = defined( "{$class}::IMAGE_EDITOR_LIBRARY" ) ? $class::IMAGE_EDITOR_LIBRARY : '';
					$conversion_info['Encode-Mode'] = stillbe_iqc_resolve_avif_encode_mode( $editor->get_quality(), $library );
				}
			}

			// cwebp が有効な SSIM を返す場合はそれを使い、無い場合のみ PHP 側で計算する
			// ssim=0 はパース失敗の番兵なので無効扱いとする
			$has_cwebp_ssim = isset( $cwebp_meta['ssim'] ) && is_numeric( $cwebp_meta['ssim'] ) && (float) $cwebp_meta['ssim'] > 0;
			if( ! $has_cwebp_ssim && isset( $cwebp_meta['ssim_in_dB'] ) && is_numeric( $cwebp_meta['ssim_in_dB'] ) && (float) $cwebp_meta['ssim_in_dB'] > 0 ) {
				$ssim_db = (float) $cwebp_meta['ssim_in_dB'];
				$cwebp_meta['ssim'] = 1 - ( 10 ** ( -$ssim_db / 10 ) );
				$has_cwebp_ssim = true;
			}
			if( $has_cwebp_ssim ) {
				$mssim = (float) $cwebp_meta['ssim'];
				$conversion_info['SSIM']       = $mssim;
				$conversion_info['SSIM-In-dB'] = isset( $cwebp_meta['ssim_in_dB'] ) && is_numeric( $cwebp_meta['ssim_in_dB'] )
					? (float) $cwebp_meta['ssim_in_dB']
					: SSIM::convert_to_dB( $mssim );
				$conversion_info['SSIM-Time']  = 'none (in conversion process)';
			} else {
				// cwebp 経路ではリサイズしていないため、比較前に参照側を揃える
				if( $is_conv_cwebp ) {
					if( 'Original' !== $size && ! empty( $sizes[ $size ] ) ) {
						$editor->resize( $sizes[ $size ]['width'], $sizes[ $size ]['height'], $sizes[ $size ]['crop'] );
					} else {
						$editor->conv2truecolor();
					}
				}

				add_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 9999 );
				if( 'image/avif' === $mime_type ) {
					$avif_editor_class = stillbe_iqc_get_avif_capable_editor_class();
					if( '' !== $avif_editor_class ) {
						$saved = new $avif_editor_class( $temp_filename );
						$loaded_saved = $saved->load();
						if( is_wp_error( $loaded_saved ) ) {
							$saved = $loaded_saved;
						}
					} else {
						$saved = new \WP_Error( 'no_avif_editor', 'AVIF capable editor is not available.' );
					}
				} else {
					$saved = wp_get_image_editor( $temp_filename, compact( 'mime_type' ) );
				}
				remove_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 9999 );

				$start_time_ssim = hrtime( true );
				$mssim = -1.0;
				$min   = null;
				$max   = null;

				if( ! is_wp_error( $saved ) ) {
					$ssim = new SSIM( $editor, $saved );
					$calc = $ssim->calc();
					if( ! is_wp_error( $calc ) && isset( $calc['mean'] ) ) {
						$mssim = is_wp_error( $calc['mean'] ) ? -1.0 : (float) $calc['mean'];
						$min   = $calc['min'] ?? null;
						$max   = $calc['max'] ?? null;
					}
				}

				$processing_time_ssim = round( ( hrtime( true ) - $start_time_ssim ) / 1e+6 );
				$conversion_info['SSIM']       = $mssim;
				$conversion_info['SSIM-In-dB'] = SSIM::convert_to_dB( $mssim );
				$conversion_info['SSIM-Time']  = $processing_time_ssim. 'ms';
				if( null !== $min ) {
					$conversion_info['SSIM-Min'] = $min;
				}
				if( null !== $max ) {
					$conversion_info['SSIM-Max'] = $max;
				}
			}

			update_user_meta( get_current_user_id(), $info_key, $conversion_info );

			$binary = file_get_contents( $temp_filename );
			@unlink( $temp_filename );

			$response = new \WP_REST_Response( $binary, 200 );
			$response->header( 'Content-Type', $mime_type );
			$response->header( 'X-IQC-Info-Key', $info_key );
			$response->header( 'X-IQC-Binary', '1' );
			return $response;
		}

		if( file_exists( $temp_filename ) ) {
			@unlink( $temp_filename );
		}

		return array(
			'ok'      => false,
			'id'      => $attachment_id,
			'stream'  => array(
				'result' => is_wp_error( $result ) ? 'WP_Error' : false,
				'errors' => is_wp_error( $result ) ? $result->get_error_messages() : null,
			),
			'quality' => array(
				'request'     => $quality,
				'get_quality' => $editor->get_quality(),
			),
			'filters' => $applied_filters,
			'message' => __( 'Failed....', 'still-be-image-quality-control' ),
		);

	}


	/**
	 * バイナリ画像レスポンスを JSON エンコードせずそのまま出力する
	 *
	 * @param bool             $served
	 * @param \WP_HTTP_Response $result
	 * @param \WP_REST_Request  $request
	 * @param \WP_REST_Server   $server
	 * @return bool
	 */
	public function serve_binary_image( $served, $result, $request, $server ) {

		if( $served || ! ( $result instanceof \WP_REST_Response ) ) {
			return $served;
		}

		$headers = $result->get_headers();
		if( empty( $headers['X-IQC-Binary'] ) ) {
			return $served;
		}

		$data = $result->get_data();
		if( ! is_string( $data ) ) {
			return $served;
		}

		if( ! headers_sent() ) {
			foreach( $headers as $key => $value ) {
				if( 'X-IQC-Binary' === $key ) {
					continue;
				}
				header( sprintf( '%s: %s', $key, $value ) );
			}
			header( 'Content-Length: '. strlen( $data ) );
		}

		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image body
		return true;

	}


}





// END of the File
