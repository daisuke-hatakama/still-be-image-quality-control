<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




/**
 * WP-Cron で実行するバックグラウンド処理
 * 
 *  1. WebP の自動生成 (stillbe_iqc/generate_webp)
 *  2. AVIF の自動生成 (stillbe_iqc/generate_avif)
 *  3. SSIM による圧縮品質の自動最適化 (stillbe_iqc/auto_optimize)
 * 
 * @since 2.0.0
 */
class Cron_Jobs {


	// 自動最適化の再スケジュール上限回数 (無限ループ防止)
	const MAX_ATTEMPTS = 5;

	// 多重実行防止ロックの有効期間 [sec]
	const LOCK_TTL = 600;

	// 自動最適化 Cron の基本遅延 [sec]
	const AUTO_OPTIMIZE_DELAY = 30;

	// 自動最適化の待ち行列 (wp_options)
	const OPTION_AUTO_OPTIMIZE_QUEUE = 'sb-iqc-ao-queue';

	// 自動最適化の実行中ジョブ (attachment_id => started_ts)
	const OPTION_AUTO_OPTIMIZE_RUNNING = 'sb-iqc-ao-running';


	private static $instance = null;

	// 進捗管理クラス
	// （Auto_Optimize は最適化ロジックのみを担当し、進捗や統計の保存は Auto_Optimize_Progress が担当）


	public static function init() {
		if( empty( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}


	/**
	 * 指定添付の自動最適化 Cron をすべて解除する
	 *
	 * @param int $attachment_id
	 */
	public static function unschedule_auto_optimize( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		$crons = _get_cron_array();
		if( empty( $crons ) || ! is_array( $crons ) ) {
			return;
		}

		foreach( $crons as $timestamp => $hooks ) {
			if( empty( $hooks['stillbe_iqc/auto_optimize'] ) || ! is_array( $hooks['stillbe_iqc/auto_optimize'] ) ) {
				continue;
			}
			foreach( $hooks['stillbe_iqc/auto_optimize'] as $event ) {
				if( empty( $event['args'][0] ) ) {
					continue;
				}
				if( $attachment_id !== absint( $event['args'][0] ) ) {
					continue;
				}
				wp_unschedule_event( $timestamp, 'stillbe_iqc/auto_optimize', $event['args'] );
			}
		}

	}


	/**
	 * 自動最適化 Cron を登録する
	 *
	 * 直接 stillbe_iqc/auto_optimize を積まず、待ち行列経由で 1 件ずつ処理する。
	 *
	 * @param int  $attachment_id
	 * @param int  $attempt
	 * @param int  $delay         未使用 (互換用)
	 * @param bool $replace_all   true なら既存の同添付イベントをすべて解除してから登録
	 */
	public static function schedule_auto_optimize( $attachment_id, $attempt = 0, $delay = null, $replace_all = false ) {

		$attachment_id = absint( $attachment_id );
		$attempt       = absint( $attempt );

		if( empty( $attachment_id ) ) {
			return;
		}

		if( $replace_all ) {
			self::unschedule_auto_optimize( $attachment_id );
			self::dequeue_auto_optimize( $attachment_id );
		}

		self::enqueue_auto_optimize( $attachment_id, $attempt );

	}


	/**
	 * 待ち行列に追加する
	 *
	 * @param int $attachment_id
	 * @param int $attempt
	 */
	public static function enqueue_auto_optimize( $attachment_id, $attempt = 0 ) {

		$attachment_id = absint( $attachment_id );
		$attempt       = absint( $attempt );

		if( empty( $attachment_id ) ) {
			return;
		}

		self::_with_queue_lock( function() use ( $attachment_id, $attempt ) {

			$queue = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );
			if( ! is_array( $queue ) ) {
				$queue = array();
			}

			$next = array();
			foreach( $queue as $item ) {
				if( empty( $item['id'] ) || $attachment_id !== absint( $item['id'] ) ) {
					$next[] = $item;
				}
			}

			$next[] = array(
				'id'      => $attachment_id,
				'attempt' => $attempt,
				'ts'      => time(),
			);

			update_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, $next, false );
			self::schedule_queue_runner();

		} );

	}


	/**
	 * 待ち行列から指定添付を除去する
	 *
	 * @param int $attachment_id
	 */
	public static function dequeue_auto_optimize( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		self::_with_queue_lock( function() use ( $attachment_id ) {

			$queue = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );
			if( ! is_array( $queue ) || empty( $queue ) ) {
				return;
			}

			$next = array();
			foreach( $queue as $item ) {
				if( empty( $item['id'] ) || $attachment_id !== absint( $item['id'] ) ) {
					$next[] = $item;
				}
			}

			update_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, $next, false );

		} );

	}


	/**
	 * 待ち行列に入っているか
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function is_queued_auto_optimize( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		$queue         = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );

		if( ! is_array( $queue ) ) {
			return false;
		}

		foreach( $queue as $item ) {
			if( ! empty( $item['id'] ) && $attachment_id === absint( $item['id'] ) ) {
				return true;
			}
		}

		return false;

	}


	/**
	 * 待ち行列内の位置 (1 始まり)。未登録なら 0
	 *
	 * @param int $attachment_id
	 * @return int
	 */
	public static function get_auto_optimize_queue_position( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		$queue         = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );

		if( ! is_array( $queue ) ) {
			return 0;
		}

		foreach( $queue as $index => $item ) {
			if( ! empty( $item['id'] ) && $attachment_id === absint( $item['id'] ) ) {
				return $index + 1;
			}
		}

		return 0;

	}


	/**
	 * 待ち行列に入ってからの経過秒。未登録なら 0
	 *
	 * @param int $attachment_id
	 * @return int
	 */
	public static function get_auto_optimize_queue_wait_seconds( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		$queue         = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );

		if( ! is_array( $queue ) ) {
			return 0;
		}

		foreach( $queue as $item ) {
			if( empty( $item['id'] ) || $attachment_id !== absint( $item['id'] ) ) {
				continue;
			}
			$ts = absint( $item['ts'] ?? 0 );
			return $ts ? max( 0, time() - $ts ) : 0;
		}

		return 0;

	}


	/**
	 * 自動最適化の同時実行上限を返す
	 *
	 * @return int 1 以上
	 */
	public static function get_concurrency_limit() {

		$settings = get_option( Setting::SETTING_NAME, array() );
		$default  = defined( 'STILLBE_IQ_AUTO_OPTIMIZE_CONCURRENCY' ) ? (int) STILLBE_IQ_AUTO_OPTIMIZE_CONCURRENCY : 2;
		$limit    = isset( $settings['auto-optimize-concurrency'] ) ? absint( $settings['auto-optimize-concurrency'] ) : $default;

		if( $limit < 1 ) {
			$limit = max( 1, $default );
		}

		/**
		 * Filters the maximum number of concurrent auto-optimize jobs.
		 *
		 * @since 2.0.0
		 *
		 * @param int $limit Current concurrency limit (from settings / default).
		 */
		return max( 1, absint( apply_filters( 'still-be/image-quality-control/auto-optimize/concurrency', $limit ) ) );

	}


	/**
	 * 待ち行列ワーカーの Cron を登録する
	 *
	 * @param int  $delay 遅延秒数
	 * @param bool $force true なら引数付きで追加登録し、同時実行スロットを埋める
	 */
	public static function schedule_queue_runner( $delay = 5, $force = false ) {

		$delay = max( 1, absint( $delay ) );
		$hook  = 'stillbe_iqc/auto_optimize_queue';

		if( $force ) {
			wp_schedule_single_event( time() + $delay, $hook, array( microtime( true ) ) );
			return;
		}

		if( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_single_event( time() + $delay, $hook );
		}

	}


	/**
	 * 空きスロットがあれば追加のキューワーカーを起こす
	 */
	public static function maybe_spawn_parallel_runners() {

		$should_spawn = false;

		self::_with_queue_lock( function() use ( &$should_spawn ) {

			self::_prune_running();
			$limit   = self::get_concurrency_limit();
			$running = self::_get_running();
			$queue   = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );

			if( ! is_array( $queue ) || empty( $queue ) ) {
				return;
			}

			if( count( $running ) < $limit ) {
				$should_spawn = true;
			}

		} );

		if( $should_spawn ) {
			self::schedule_queue_runner( 1, true );
		}

	}


	/**
	 * 実行中ジョブ一覧を取得する (呼び出し側でキューロックを保持すること)
	 *
	 * @return array<int,int> attachment_id => started_ts
	 */
	private static function _get_running() {

		$running = get_option( self::OPTION_AUTO_OPTIMIZE_RUNNING, array() );
		return is_array( $running ) ? $running : array();

	}


	/**
	 * 期限切れの実行中マークを除去する (呼び出し側でキューロックを保持すること)
	 */
	private static function _prune_running() {

		$running = self::_get_running();
		if( empty( $running ) ) {
			return;
		}

		$now  = time();
		$next = array();

		foreach( $running as $attachment_id => $started ) {
			$attachment_id = absint( $attachment_id );
			$started       = absint( $started );
			if( empty( $attachment_id ) || empty( $started ) ) {
				continue;
			}
			if( ( $now - $started ) < self::LOCK_TTL ) {
				$next[ $attachment_id ] = $started;
			}
		}

		if( $next !== $running ) {
			update_option( self::OPTION_AUTO_OPTIMIZE_RUNNING, $next, false );
		}

	}


	/**
	 * 実行中としてマークする (呼び出し側でキューロックを保持すること)
	 *
	 * @param int $attachment_id
	 */
	private static function _mark_running( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		$running = self::_get_running();
		$running[ $attachment_id ] = time();
		update_option( self::OPTION_AUTO_OPTIMIZE_RUNNING, $running, false );

	}


	/**
	 * 実行中マークを外す
	 *
	 * @param int $attachment_id
	 */
	private static function _unmark_running( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		if( empty( $attachment_id ) ) {
			return;
		}

		self::_with_queue_lock( function() use ( $attachment_id ) {

			$running = self::_get_running();
			if( ! isset( $running[ $attachment_id ] ) ) {
				return;
			}

			unset( $running[ $attachment_id ] );
			update_option( self::OPTION_AUTO_OPTIMIZE_RUNNING, $running, false );

		} );

	}


	/**
	 * 待ち行列の排他制御
	 *
	 * @param callable $callback
	 */
	private static function _with_queue_lock( $callback ) {

		$lock_key = 'sb-iqc-ao-queue-lock';
		$waited   = 0;

		while( get_transient( $lock_key ) ) {
			usleep( 50000 );
			$waited += 50;
			if( 3000 < $waited ) {
				break;
			}
		}

		set_transient( $lock_key, time(), 30 );

		try {
			call_user_func( $callback );
		} finally {
			delete_transient( $lock_key );
		}

	}


	private function __construct() {
		// Add Cron Action
		add_action( 'stillbe_iqc/generate_webp', [ $this, 'generate_webp' ], 10, 2 );
		add_action( 'stillbe_iqc/generate_avif', [ $this, 'generate_avif' ], 10, 2 );
		add_action( 'stillbe_iqc/auto_optimize', [ $this, 'auto_optimize' ], 10, 2 );
		add_action( 'stillbe_iqc/auto_optimize_queue', [ $this, 'process_auto_optimize_queue' ], 10, 1 );
		add_action( 'stillbe_iqc/cleanup_temp_files', [ $this, 'cleanup_old_temp_files' ] );

		self::schedule_temp_cleanup();
	}


	/**
	 * 深夜の日次一時ファイル掃除を登録する
	 */
	public static function schedule_temp_cleanup() {

		$hook = 'stillbe_iqc/cleanup_temp_files';
		if( wp_next_scheduled( $hook ) ) {
			return;
		}

		// サイトタイムゾーンで翌 3:36 (過ぎていれば翌日)
		$tz  = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$now = new \DateTimeImmutable( 'now', $tz );
		$at  = $now->setTime( 3, 36, 0 );
		if( $at <= $now ) {
			$at = $at->modify( '+1 day' );
		}

		wp_schedule_event( $at->getTimestamp(), 'daily', $hook );

	}


	/**
	 * 24 時間以上前の sb-temp 一時ファイル (候補画像 / ERE デバッグ PNG) を削除する (日次 Cron)
	 */
	public function cleanup_old_temp_files() {

		Auto_Optimize::cleanup_old_temp_files( DAY_IN_SECONDS );

	}


	/**
	 * 自動最適化の待ち行列を 1 件だけ処理する
	 *
	 * 同時実行数は get_concurrency_limit() で制限する。
	 *
	 * @param mixed $unused Cron 引数 (並列起動用の一意値。未使用)
	 */
	public function process_auto_optimize_queue( $unused = null ) {

		stillbe_iqc_raise_wpcron_time_limit( 'auto-optimize' );

		$item        = null;
		$at_capacity = false;

		self::_with_queue_lock( function() use ( &$item, &$at_capacity ) {

			self::_prune_running();

			$limit   = self::get_concurrency_limit();
			$running = self::_get_running();

			if( count( $running ) >= $limit ) {
				$at_capacity = true;
				return;
			}

			$queue = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );
			if( ! is_array( $queue ) || empty( $queue ) ) {
				return;
			}

			$item  = array_shift( $queue );
			$queue = array_values( $queue );
			update_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, $queue, false );

			if( ! empty( $item['id'] ) ) {
				self::_mark_running( absint( $item['id'] ) );
			}

		} );

		if( $at_capacity ) {
			$remaining = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );
			if( is_array( $remaining ) && ! empty( $remaining ) ) {
				self::schedule_queue_runner( 15 );
			}
			return;
		}

		if( empty( $item['id'] ) ) {
			return;
		}

		$attachment_id = absint( $item['id'] );
		$attempt       = absint( $item['attempt'] ?? 0 );

		// 長時間処理の前に空きスロット分のワーカーを起こす
		self::maybe_spawn_parallel_runners();

		try {
			$this->auto_optimize( $attachment_id, $attempt );
		} finally {
			self::_unmark_running( $attachment_id );
		}

		$remaining = get_option( self::OPTION_AUTO_OPTIMIZE_QUEUE, array() );
		if( is_array( $remaining ) && ! empty( $remaining ) ) {
			self::schedule_queue_runner( 1 );
			self::maybe_spawn_parallel_runners();
		}

	}


	/**
	 * WebP を生成する
	 * この処理は WP-Cron での実行を前提としている
	 * 
	 * @param int $attachment_id 添付ファイル ID
	 * @param array $generated_sizes 生成されたサイズ (このサイズはスキップする)
	 */
	public function generate_webp( $attachment_id, $generated_sizes = [] ) {

		stillbe_iqc_raise_wpcron_time_limit( 'generate-webp' );

		$metadata = wp_get_attachment_metadata( $attachment_id );
	
		if( empty( $metadata['file'] ) ) {
			return;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if( 'image/webp' === $mime_type ) {
			return;
		}
	
		$upload_dir    = wp_upload_dir();
		$ud_base_dir   = $upload_dir['basedir'];
		$original_file = path_join( $ud_base_dir, $metadata['file'] );
		$base_dir      = dirname( $original_file );
	
		// Get image editor
		$editor = wp_get_image_editor( $original_file );
		if( is_wp_error( $editor ) ) {
			return;
		}
	
		// Sizes
		$sizes = $metadata['sizes'];
		if( empty( $sizes ) ) {
			$sizes = array();
		}
		$sizes['original'] = array(
			'width'     => $metadata['width'],
			'height'    => $metadata['height'],
			'mime-type' => get_post_mime_type( $attachment_id ),
			'file'      => basename( $metadata['file'] ),
		);
	
		// New Metadata
		$new_meta = $metadata;
	
		// Load image
		$editor->load();
	
		// Max execution time
		$max_execution_time = ini_get( 'max_execution_time' );
		$max_execution_time = absint( $max_execution_time );
		if( 1 > $max_execution_time ) {
			$max_execution_time = 30;   // DEFAULT
		}
	
		// Start Time
		$start_time = time();
	
		// 登録済みサイズ (crop フラグの取得用)
		$registered_sizes = wp_get_registered_image_subsizes();
	
		add_filter( 'wp_editor_set_quality', [ $editor, '_set_quality_hook' ], 1, 2 );
		add_filter( 'wp_image_resize_identical_dimensions', '__return_true' );
	
		// Generate WebP images each sizes
		foreach( $sizes as $size_name => $size_data ) {
			if( in_array( $size_name, $generated_sizes, true ) ) {
				continue;
			}
			$editor->_set_mk_size( $size_name );
			// サイズ名に対応した品質が使われるように size_name を補完する
			if( empty( $size_data['size_name'] ) ) {
				$size_data['size_name'] = $size_name;
			}
			// crop フラグを補完する (JPEG 側とアスペクト比を一致させるため)
			if( ! isset( $size_data['crop'] ) && 'original' !== $size_name ) {
				$reg = isset( $registered_sizes[ $size_name ] ) ? $registered_sizes[ $size_name ] : null;
				$size_data['crop'] = $this->_resolve_crop( $metadata['width'], $metadata['height'], $size_data['width'], $size_data['height'], $reg );
			}
			if( empty( $size_data['file'] ) || stillbe_iqc_is_webp_path( $size_data['file'] ) ) {
				$generated_sizes[] = $size_name;
				continue;
			}
			$size_file = path_join( $base_dir, $size_data['file'] );
			$webp_name = stillbe_iqc_get_filtered_delivery_webp_path( $size_file );
			if( ! $webp_name ) {
				$generated_sizes[] = $size_name;
				continue;
			}
			$webp_data = $editor->make_webp( $webp_name, $size_data );
			// 成否に関わらず処理済みとする (失敗サイズの無限リトライを防止)
			$generated_sizes[] = $size_name;
			if( 'original' !== $size_name && ! is_wp_error( $webp_data ) && ! empty( $webp_data['size'] ) ) {
				// Update New Metadata
				$new_meta['sizes'][ $size_name ]           = isset( $new_meta['sizes'][ $size_name ] ) ? $new_meta['sizes'][ $size_name ] : array();
				$new_meta['sizes'][ $size_name ]['sb-iqc'] = isset( $new_meta['sizes'][ $size_name ]['sb-iqc'] ) ? $new_meta['sizes'][ $size_name ]['sb-iqc'] : array();
				$new_meta['sizes'][ $size_name ]['sb-iqc']['webp-file']    = $webp_data['file'];
				$new_meta['sizes'][ $size_name ]['sb-iqc']['webp-quality'] = $webp_data['q'];
				if( isset( $webp_data['cwebp'] ) ) {
					$new_meta['sizes'][ $size_name ]['sb-iqc']['cwebp']    = $webp_data['cwebp'];
				}
				stillbe_iqc_merge_encode_method_meta( $new_meta['sizes'][ $size_name ]['sb-iqc'], $webp_data, 'webp' );
			}
			if( $max_execution_time / 2 <= ( time() - $start_time ) ) {
				// 実行時間の半分を超えたら中断して次回に回す
				break;
			}
		}
	
		remove_filter( 'wp_editor_set_quality', [ $editor, '_set_quality_hook' ], 1 );
		remove_filter( 'wp_image_resize_identical_dimensions', '__return_true' );
	
		// Update Metadata
		wp_update_attachment_metadata( $attachment_id, $new_meta );
	
		// Clean up
		$editor = null;
		unset( $editor );

		// 残っていたら次回の WP-Cron を設定
		if( count( $sizes ) > count( $generated_sizes ) ) {
			wp_schedule_single_event(
				time() + 30,
				'stillbe_iqc/generate_webp',
				[ $attachment_id, $generated_sizes ]
			);
		}
	
	}


	/**
	 * AVIF を生成する
	 * この処理は WP-Cron での実行を前提としている
	 *
	 * @param int   $attachment_id
	 * @param array $generated_sizes
	 */
	public function generate_avif( $attachment_id, $generated_sizes = [] ) {

		stillbe_iqc_raise_wpcron_time_limit( 'generate-avif' );

		if( ! stillbe_iqc_should_generate_delivery_avif() ) {
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if( empty( $metadata['file'] ) ) {
			return;
		}

		$mime_type = get_post_mime_type( $attachment_id );
		if( 'image/avif' === $mime_type ) {
			return;
		}

		$upload_dir    = wp_upload_dir();
		$ud_base_dir   = $upload_dir['basedir'];
		$original_file = path_join( $ud_base_dir, $metadata['file'] );
		$base_dir      = dirname( $original_file );

		$editor = wp_get_image_editor( $original_file );
		if( is_wp_error( $editor ) ) {
			return;
		}

		$sizes = $metadata['sizes'];
		if( empty( $sizes ) ) {
			$sizes = array();
		}
		$sizes['original'] = array(
			'width'     => $metadata['width'],
			'height'    => $metadata['height'],
			'mime-type' => get_post_mime_type( $attachment_id ),
			'file'      => basename( $metadata['file'] ),
		);

		$new_meta = $metadata;

		$editor->load();

		$max_execution_time = ini_get( 'max_execution_time' );
		$max_execution_time = absint( $max_execution_time );
		if( 1 > $max_execution_time ) {
			$max_execution_time = 30;
		}

		$start_time = time();

		$registered_sizes = wp_get_registered_image_subsizes();

		add_filter( 'wp_editor_set_quality', [ $editor, '_set_quality_hook' ], 1, 2 );
		add_filter( 'wp_image_resize_identical_dimensions', '__return_true' );

		foreach( $sizes as $size_name => $size_data ) {
			if( in_array( $size_name, $generated_sizes, true ) ) {
				continue;
			}
			$editor->_set_mk_size( $size_name );
			if( empty( $size_data['size_name'] ) ) {
				$size_data['size_name'] = $size_name;
			}
			if( ! isset( $size_data['crop'] ) && 'original' !== $size_name ) {
				$reg = isset( $registered_sizes[ $size_name ] ) ? $registered_sizes[ $size_name ] : null;
				$size_data['crop'] = $this->_resolve_crop( $metadata['width'], $metadata['height'], $size_data['width'], $size_data['height'], $reg );
			}
			if( empty( $size_data['file'] ) || stillbe_iqc_is_avif_path( $size_data['file'] ) || stillbe_iqc_is_webp_path( $size_data['file'] ) ) {
				$generated_sizes[] = $size_name;
				continue;
			}
			$size_file = path_join( $base_dir, $size_data['file'] );
			$avif_name = stillbe_iqc_get_filtered_delivery_avif_path( $size_file );
			if( ! $avif_name ) {
				$generated_sizes[] = $size_name;
				continue;
			}
			$avif_data = $editor->make_avif( $avif_name, $size_data );
			$generated_sizes[] = $size_name;
			if( 'original' !== $size_name && ! is_wp_error( $avif_data ) && ! empty( $avif_data['size'] ) ) {
				$new_meta['sizes'][ $size_name ]           = isset( $new_meta['sizes'][ $size_name ] ) ? $new_meta['sizes'][ $size_name ] : array();
				$new_meta['sizes'][ $size_name ]['sb-iqc'] = isset( $new_meta['sizes'][ $size_name ]['sb-iqc'] ) ? $new_meta['sizes'][ $size_name ]['sb-iqc'] : array();
				$new_meta['sizes'][ $size_name ]['sb-iqc']['avif-file']    = $avif_data['file'];
				$new_meta['sizes'][ $size_name ]['sb-iqc']['avif-quality'] = $avif_data['q'];
				stillbe_iqc_merge_encode_method_meta( $new_meta['sizes'][ $size_name ]['sb-iqc'], $avif_data, 'avif' );
			}
			if( $max_execution_time / 2 <= ( time() - $start_time ) ) {
				break;
			}
		}

		remove_filter( 'wp_editor_set_quality', [ $editor, '_set_quality_hook' ], 1 );
		remove_filter( 'wp_image_resize_identical_dimensions', '__return_true' );

		wp_update_attachment_metadata( $attachment_id, $new_meta );

		$editor = null;
		unset( $editor );

		if( count( $sizes ) > count( $generated_sizes ) ) {
			wp_schedule_single_event(
				time() + 30,
				'stillbe_iqc/generate_avif',
				[ $attachment_id, $generated_sizes ]
			);
		}

	}


	/**
	 * SSIM による圧縮品質の自動最適化
	 * この処理は WP-Cron での実行を前提としている
	 * 
	 * 各サイズについて「SSIM が目標値を満たす最小の品質」を二分探索で求め、
	 * その品質でサブサイズ JPEG の再圧縮と WebP の生成を行う。
	 * 品質テーブルの設定値は上限 (天井) として扱う。
	 * 
	 * @param int $attachment_id 添付ファイル ID
	 * @param int $attempt 再スケジュール回数
	 */
	public function auto_optimize( $attachment_id = 0, $attempt = 0 ) {

		stillbe_iqc_raise_wpcron_time_limit( 'auto-optimize' );

		$attachment_id = absint( $attachment_id );
		$attempt       = absint( $attempt );

		if( empty( $attachment_id ) ) {
			return;
		}

		// 実行時に設定を再確認 (処理待ちの間に無効化された場合は何もしない)
		$settings   = get_option( Setting::SETTING_NAME, array() );
		$is_enabled = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;
		if( ! $is_enabled ) {
			Schedule_Cron::clear_client_side_finalize( $attachment_id );
			return;
		}

		// 対象は JPEG / PNG / GIF / WebP (元 WebP はインプレース最適化)
		$mime_type = get_post_mime_type( $attachment_id );
		$supported = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		if( ! in_array( $mime_type, $supported, true ) ) {
			if( ! wp_next_scheduled( 'stillbe_iqc/generate_webp', [ $attachment_id ] ) ) {
				wp_schedule_single_event( time() + 30, 'stillbe_iqc/generate_webp', [ $attachment_id ] );
			}
			if( ! wp_next_scheduled( 'stillbe_iqc/generate_avif', [ $attachment_id ] ) ) {
				wp_schedule_single_event( time() + 30, 'stillbe_iqc/generate_avif', [ $attachment_id ] );
			}
			return;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if( empty( $metadata['file'] ) ) {
			Schedule_Cron::clear_client_side_finalize( $attachment_id );
			return;
		}

		// 試行回数の上限を超えたら、未処理サイズの WebP 生成だけ従来方式で完了させて終了する
		// 処理済みサイズを渡すことで、最適化済みの WebP がテーブル品質で上書きされるのを防ぐ
		if( self::MAX_ATTEMPTS < $attempt ) {
			Schedule_Cron::clear_client_side_finalize( $attachment_id );
			if( 'image/webp' !== $mime_type && 'image/avif' !== $mime_type ) {
				$done = ( isset( $metadata[ Auto_Optimize_Progress::META_PROGRESS ]['done'] ) && is_array( $metadata[ Auto_Optimize_Progress::META_PROGRESS ]['done'] ) ) ?
				          $metadata[ Auto_Optimize_Progress::META_PROGRESS ]['done'] :
				          array();
				if( stillbe_iqc_should_generate_delivery_webp( $mime_type ) &&
				      ! wp_next_scheduled( 'stillbe_iqc/generate_webp', [ $attachment_id, $done ] ) ) {
					wp_schedule_single_event( time() + 30, 'stillbe_iqc/generate_webp', [ $attachment_id, $done ] );
				}
				if( stillbe_iqc_should_generate_delivery_avif( $mime_type ) &&
				      ! wp_next_scheduled( 'stillbe_iqc/generate_avif', [ $attachment_id, $done ] ) ) {
					wp_schedule_single_event( time() + 30, 'stillbe_iqc/generate_avif', [ $attachment_id, $done ] );
				}
			}
			return;
		}

		// 多重実行防止ロック (添付 ID ごと)
		$lock_key = '_sb-iqc-ao-lock-'. $attachment_id;
		if( get_transient( $lock_key ) ) {
			self::schedule_auto_optimize( $attachment_id, $attempt, 45 );
			return;
		}
		set_transient( $lock_key, time(), self::LOCK_TTL );

		try {
			// ロック取得後にメタを再読込 (並列再圧縮などで古いスナップショットを避ける)
			$metadata = wp_get_attachment_metadata( $attachment_id );
			if( empty( $metadata['file'] ) ) {
				Schedule_Cron::clear_client_side_finalize( $attachment_id );
				return;
			}

			// 進捗・統計（before）を初期化
			$metadata = Auto_Optimize_Progress::touch_progress( $metadata, $attempt, $settings['auto-optimize-target'] ?? 'balance' );
			$metadata = Auto_Optimize_Progress::ensure_stats_before( $attachment_id, $metadata );

			$progress     = Auto_Optimize_Progress::get_progress( $metadata );
			$done         = ( isset( $progress['done'] ) && is_array( $progress['done'] ) ) ? $progress['done'] : array();
			$largest_webp = ( isset( $progress['largest-webp'] ) && is_array( $progress['largest-webp'] ) ) ? $progress['largest-webp'] : array( 'width' => 0, 'quality' => 0 );
			$largest_avif = ( isset( $progress['largest-avif'] ) && is_array( $progress['largest-avif'] ) ) ? $progress['largest-avif'] : array( 'width' => 0, 'quality' => 0 );

			$optimizer = new Auto_Optimize();
			try {
				$result = $optimizer->run( array(
					'attachment_id' => $attachment_id,
					'attempt'       => $attempt,
					'metadata'      => $metadata,
					'settings'      => $settings,
					'mime_type'     => $mime_type,
					'done'          => $done,
					'largest_webp'  => $largest_webp,
					'largest_avif'  => $largest_avif,
				) );

				$new_meta = is_array( $result['meta'] ?? null ) ? $result['meta'] : $metadata;
				$new_meta = Auto_Optimize_Progress::apply_result( $new_meta, $result, $attachment_id, $settings );

				$is_complete = Auto_Optimize_Progress::is_optimization_complete(
					$new_meta,
					$result['done'] ?? array(),
					$attachment_id,
					$settings
				);

				// 完了時は統計（after）を保存
				if( $is_complete && empty( $result['suspended'] ) ) {
					$new_meta = Auto_Optimize_Progress::finalize_stats( $attachment_id, $new_meta );
					Schedule_Cron::clear_client_side_finalize( $attachment_id );
				}

				wp_update_attachment_metadata( $attachment_id, $new_meta );

				// 未完了または時間切れなら次回を待ち行列へ
				if( ! $is_complete || ! empty( $result['suspended'] ) ) {
					if( self::MAX_ATTEMPTS >= $attempt ) {
						$this->_schedule_next( $attachment_id, $attempt + 1 );
					}
				}
			} finally {
				// メタデータ保存後 (または中断時) にこの実行の一時ファイルを削除
				$optimizer->cleanup_candidate_temps();
			}
		} finally {
			delete_transient( $lock_key );
		}

	}


	// 次回の自動最適化をスケジュールする
	private function _schedule_next( $attachment_id, $attempt ) {

		self::schedule_auto_optimize( $attachment_id, $attempt );

	}


	// crop フラグを解決する (未登録サイズはアスペクト比の差から推定)
	// generate_webp 用（Auto_Optimize と責務分離しているため、ここにも最小限の補助メソッドを置く）
	private function _resolve_crop( $orig_w, $orig_h, $w, $h, $registered_size = null ) {

		if( isset( $registered_size['crop'] ) ) {
			return $registered_size['crop'];
		}

		if( $orig_w && $orig_h && $w && $h ) {
			// アスペクト比が元画像と異なる場合はクロップされたサイズとみなす
			return 0.01 < abs( ( $orig_w / $orig_h ) - ( $w / $h ) );
		}

		return false;

	}


}




// END of the File
