<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}



/**
 * アップロード完了時の WP-Cron 登録
 *
 * WebP / AVIF の非同期生成と SSIM 自動最適化を、
 * サーバ側アップロードと Client-side finalize の両方から同じ経路で積む。
 *
 * @since 2.2.0
 */
class Schedule_Cron {


	// Client-side finalize 由来の添付を示す post meta
	const META_CLIENT_SIDE_FINALIZE = '_sb-iqc-client-side-finalize';


	private static $instance = null;

	// 現在の REST リクエスト
	private static $current_rest_request = null;

	// 同一リクエスト内で添付ごとに 1 回だけ登録する
	private static $scheduled = array();


	public static function init() {

		if( empty( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;

	}


	private function __construct() {

		add_filter( 'rest_pre_dispatch', [ $this, 'capture_rest_request' ], 1, 3 );

	}


	/**
	 * 現在の REST リクエストを保持する
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public function capture_rest_request( $result, $server, $request ) {

		if( $request instanceof \WP_REST_Request ) {
			self::$current_rest_request = $request;
		}

		return $result;

	}


	/**
	 * 現在の REST リクエストを保持 / 取得する
	 *
	 * @param \WP_REST_Request|null $request セットする場合のリクエスト
	 * @return \WP_REST_Request|null
	 */
	public static function current_rest_request( $request = null ) {

		if( $request instanceof \WP_REST_Request ) {
			self::$current_rest_request = $request;
		}

		return self::$current_rest_request;

	}


	/**
	 * REST ルートが添付の finalize か
	 *
	 * @param \WP_REST_Request|null $request
	 * @return bool
	 */
	public static function is_finalize_request( $request = null ) {

		$request = $request instanceof \WP_REST_Request ? $request : self::current_rest_request();
		if( ! $request instanceof \WP_REST_Request ) {
			return false;
		}

		$route = untrailingslashit( (string) $request->get_route() );

		return (bool) preg_match( '#/media/\d+/finalize$#', $route );

	}


	/**
	 * REST ルートが添付の sideload か
	 *
	 * @param \WP_REST_Request|null $request
	 * @return bool
	 */
	public static function is_sideload_request( $request = null ) {

		$request = $request instanceof \WP_REST_Request ? $request : self::current_rest_request();
		if( ! $request instanceof \WP_REST_Request ) {
			return false;
		}

		$route = untrailingslashit( (string) $request->get_route() );

		return (bool) preg_match( '#/media/\d+/sideload$#', $route );

	}


	/**
	 * Client-side の初回アップロード (サブサイズは後続の finalize 待ち) か
	 *
	 * @param \WP_REST_Request|null $request
	 * @return bool
	 */
	public static function is_create_request( $request = null ) {

		$request = $request instanceof \WP_REST_Request ? $request : self::current_rest_request();
		if( ! $request instanceof \WP_REST_Request ) {
			return false;
		}

		if( 'POST' !== $request->get_method() ) {
			return false;
		}

		$route = untrailingslashit( (string) $request->get_route() );
		if( ! preg_match( '#/media$#', $route ) ) {
			return false;
		}

		$generate = $request->get_param( 'generate_sub_sizes' );

		return false === $generate || 0 === $generate || '0' === $generate || 'false' === $generate;

	}


	/**
	 * サブサイズ未確定のため WP-Cron を finalize まで遅らせるか
	 *
	 * @return bool
	 */
	public static function should_defer_until_finalize() {

		if( function_exists( 'wp_is_client_side_media_processing_enabled' )
		      && ! wp_is_client_side_media_processing_enabled() ) {
			return false;
		}

		return self::is_create_request();

	}


	/**
	 * Client-side finalize 由来であることを添付に記録する
	 *
	 * @param int $attachment_id
	 */
	public static function mark_client_side_finalize( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		update_post_meta( $attachment_id, self::META_CLIENT_SIDE_FINALIZE, 1 );

	}


	/**
	 * Client-side finalize 由来か
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function is_client_side_finalize( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return false;
		}

		return (bool) get_post_meta( $attachment_id, self::META_CLIENT_SIDE_FINALIZE, true );

	}


	/**
	 * Client-side finalize の記録を消す
	 *
	 * @param int $attachment_id
	 */
	public static function clear_client_side_finalize( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		delete_post_meta( $attachment_id, self::META_CLIENT_SIDE_FINALIZE );

	}


	/**
	 * Client-side 製のサブサイズをプラグインのエディタで再保存すべきか
	 *
	 * @param int   $attachment_id
	 * @param array $size_data
	 * @return bool
	 */
	public static function should_recompress_client_side_size( $attachment_id, $size_data = array() ) {

		if( ! self::is_client_side_finalize( $attachment_id ) ) {
			return false;
		}

		// このプラグインが既に品質を記録しているサイズはサーバ側エンコード済み
		if( ! empty( $size_data['sb-iqc']['quality'] ) ) {
			return false;
		}

		return true;

	}


	/**
	 * Client-side のサブサイズ sideload が揃ったあと (finalize) にバックグラウンド処理を予約する
	 *
	 * @param int                   $attachment_id
	 * @param mixed                 $client_extended_data
	 * @param array                 $metadata
	 * @param \WP_REST_Request|null $request
	 */
	public static function on_client_side_media_finalize( $attachment_id, $client_extended_data = null, $metadata = array(), $request = null ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		if( $request instanceof \WP_REST_Request ) {
			self::current_rest_request( $request );
		}

		if( ! is_array( $metadata ) || empty( $metadata ) ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		// finalize 後の $metadata は sizes / original_image などサーバ保存済みの値。
		// 代替配信 WebP/AVIF はクライアントが作らないため、既存どおり元画像から WP-Cron で生成する。
		// $client_extended_data は wasm-vips 側の付加情報で、サイドカー生成の入力には使わない。

		self::schedule(
			$attachment_id,
			is_array( $metadata ) ? $metadata : array(),
			array( 'from_finalize' => true )
		);

	}


	/**
	 * アップロード完了時と同じ WP-Cron を登録する
	 *
	 * WebP / AVIF の非同期生成と、有効なら SSIM 自動最適化を積む。
	 * 同一リクエストで複数回呼ばれても添付ごとに 1 回だけ登録する。
	 *
	 * @param int   $attachment_id
	 * @param array $metadata
	 * @param array $args {
	 *     @type bool $from_finalize Client-side finalize 由来か
	 * }
	 */
	public static function schedule( $attachment_id, $metadata = null, $args = array() ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		if( isset( self::$scheduled[ $attachment_id ] ) ) {
			return;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if( ! is_string( $mime_type ) || 0 !== strpos( $mime_type, 'image/' ) ) {
			return;
		}

		self::$scheduled[ $attachment_id ] = true;

		$from_finalize = ! empty( $args['from_finalize'] );

		$settings                 = get_option( Setting::SETTING_NAME, null );
		$is_enabled_webp          = isset( $settings['toggle']['enable-webp'] ) ? $settings['toggle']['enable-webp'] : STILLBE_IQ_ENABLE_WEBP;
		$is_enabled_avif          = isset( $settings['toggle']['enable-avif'] ) ? $settings['toggle']['enable-avif'] : STILLBE_IQ_ENABLE_AVIF;
		$is_enabled_auto_optimize = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;

		$is_enabled_save_webp_sync = apply_filters( 'stillbe_image_quality_control_enable_save_webp_sync', STILLBE_IQ_ENABLE_SAVE_WEBP_SYNC, $metadata );
		$is_enabled_save_avif_sync = apply_filters( 'stillbe_image_quality_control_enable_save_avif_sync', STILLBE_IQ_ENABLE_SAVE_AVIF_SYNC, $metadata );

		if( $from_finalize && $is_enabled_auto_optimize ) {
			self::mark_client_side_finalize( $attachment_id );
		}

		// 自動最適化が有効な場合、代替配信の生成は最適化処理に任せる
		if( $is_enabled_webp && ! $is_enabled_auto_optimize && ! $is_enabled_save_webp_sync &&
		      ! wp_next_scheduled( 'stillbe_iqc/generate_webp', array( $attachment_id ) ) ) {
			wp_schedule_single_event(
				time() + 60,
				'stillbe_iqc/generate_webp',
				array( $attachment_id )
			);
		}

		if( $is_enabled_avif && ! $is_enabled_auto_optimize && ! $is_enabled_save_avif_sync &&
		      ! wp_next_scheduled( 'stillbe_iqc/generate_avif', array( $attachment_id ) ) ) {
			wp_schedule_single_event(
				time() + 60,
				'stillbe_iqc/generate_avif',
				array( $attachment_id )
			);
		}

		if( $is_enabled_auto_optimize ) {
			Cron_Jobs::schedule_auto_optimize( $attachment_id, 0, 60 );
		}

	}


}




// END of the File
