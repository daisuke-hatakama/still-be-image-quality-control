<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSIM 自動最適化の進捗・統計（保存/表示用）
 *
 * - Auto_Optimize は最適化ロジックのみを担当
 * - 本クラスは「進捗保存・統計保存・表示用の文字列生成」を担当
 *
 * @since 2.0.0
 */
class Auto_Optimize_Progress {

	const META_PROGRESS = 'sb-iqc-ao';
	const META_STATS    = 'sb-iqc-ao-stats';

	// 待機表示用のキー
	const PENDING_RECOMPRESS = 'recompress';
	const PENDING_OPTIMIZE   = 'optimize';
	const PENDING_WEBP       = 'webp';
	const PENDING_AVIF       = 'avif';

	/**
	 * 進捗メタを取得する
	 *
	 * @param array $meta 添付メタデータ
	 * @return array
	 */
	public static function get_progress( $meta ) {
		if( empty( $meta[ self::META_PROGRESS ] ) || ! is_array( $meta[ self::META_PROGRESS ] ) ) {
			return array();
		}
		return $meta[ self::META_PROGRESS ];
	}

	/**
	 * 統計メタを取得する
	 *
	 * @param array $meta 添付メタデータ
	 * @return array
	 */
	public static function get_stats( $meta ) {
		if( empty( $meta[ self::META_STATS ] ) || ! is_array( $meta[ self::META_STATS ] ) ) {
			return array();
		}
		return $meta[ self::META_STATS ];
	}

	/**
	 * 初期化（再圧縮時などでリセットしたい場合に使用）
	 *
	 * @param array $meta
	 * @return array
	 */
	public static function reset( $meta ) {
		unset( $meta[ self::META_PROGRESS ] );
		unset( $meta[ self::META_STATS ] );
		return $meta;
	}

	/**
	 * 代替配信 WebP の sb-iqc キー一覧
	 *
	 * @return string[]
	 */
	public static function delivery_webp_sb_iqc_keys() {

		return array( 'webp-file', 'webp-quality', 'webp-method', 'webp-lossless-level', 'webp-ssim', 'webp-mae', 'cwebp' );

	}


	/**
	 * sb-iqc から代替配信 WebP の項目を削除する
	 *
	 * @param array $sb_iqc
	 * @return array
	 */
	public static function unset_delivery_webp_sb_iqc_keys( array $sb_iqc ) {

		foreach( self::delivery_webp_sb_iqc_keys() as $key ) {
			unset( $sb_iqc[ $key ] );
		}

		return $sb_iqc;

	}


	/**
	 * 1 サイズ分の代替配信 WebP ファイルを削除する
	 *
	 * @param string $base_dir
	 * @param array  $size_data
	 * @param string $main_path
	 */
	public static function delete_delivery_webp_file_for_size( $base_dir, $size_data, $main_path ) {

		$webp_path = stillbe_iqc_resolve_delivery_webp_path( $base_dir, $size_data, $main_path );

		if( $webp_path && file_exists( $webp_path ) ) {
			@unlink( $webp_path );
		}

	}


	/**
	 * 代替配信 AVIF の sb-iqc キー一覧
	 *
	 * @return string[]
	 */
	public static function delivery_avif_sb_iqc_keys() {

		return array( 'avif-file', 'avif-quality', 'avif-method', 'avif-ssim', 'avif-mae' );

	}


	/**
	 * sb-iqc から代替配信 AVIF の項目を削除する
	 *
	 * @param array $sb_iqc
	 * @return array
	 */
	public static function unset_delivery_avif_sb_iqc_keys( array $sb_iqc ) {

		foreach( self::delivery_avif_sb_iqc_keys() as $key ) {
			unset( $sb_iqc[ $key ] );
		}

		return $sb_iqc;

	}


	/**
	 * 1 サイズ分の代替配信 AVIF ファイルを削除する
	 *
	 * @param string $base_dir
	 * @param array  $size_data
	 * @param string $main_path
	 */
	public static function delete_delivery_avif_file_for_size( $base_dir, $size_data, $main_path ) {

		$avif_path = stillbe_iqc_resolve_delivery_avif_path( $base_dir, $size_data, $main_path );

		if( $avif_path && file_exists( $avif_path ) ) {
			@unlink( $avif_path );
		}

	}


	/**
	 * 代替配信用 WebP ファイルとメタ情報を削除する
	 *
	 * @param int        $attachment_id
	 * @param array|null $meta
	 * @return array
	 */
	public static function purge_delivery_webp( $attachment_id, $meta = null ) {

		$attachment_id = absint( $attachment_id );

		if( null === $meta ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
		}

		if( empty( $meta['file'] ) ) {
			return is_array( $meta ) ? $meta : array();
		}

		$upload_dir    = wp_upload_dir();
		$original_file = path_join( $upload_dir['basedir'], $meta['file'] );
		$base_dir      = dirname( $original_file );
		$webp_keys     = self::delivery_webp_sb_iqc_keys();

		$orig_webp = stillbe_iqc_get_delivery_webp_path( $original_file );
		if( $orig_webp && file_exists( $orig_webp ) ) {
			@unlink( $orig_webp );
		}

		if( ! empty( $meta['sb-iqc'] ) && is_array( $meta['sb-iqc'] ) ) {
			foreach( $webp_keys as $key ) {
				unset( $meta['sb-iqc'][ $key ] );
			}
			if( empty( $meta['sb-iqc'] ) ) {
				unset( $meta['sb-iqc'] );
			}
		}

		if( empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return $meta;
		}

		foreach( $meta['sizes'] as $size_name => $size_data ) {
			if( empty( $size_data['file'] ) ) {
				continue;
			}

			$main_path = path_join( $base_dir, $size_data['file'] );
			$webp_path = stillbe_iqc_resolve_delivery_webp_path( $base_dir, $size_data, $main_path );

			if( $webp_path && file_exists( $webp_path ) ) {
				@unlink( $webp_path );
			}

			if( empty( $meta['sizes'][ $size_name ]['sb-iqc'] ) || ! is_array( $meta['sizes'][ $size_name ]['sb-iqc'] ) ) {
				continue;
			}

			foreach( $webp_keys as $key ) {
				unset( $meta['sizes'][ $size_name ]['sb-iqc'][ $key ] );
			}

			if( empty( $meta['sizes'][ $size_name ]['sb-iqc'] ) ) {
				unset( $meta['sizes'][ $size_name ]['sb-iqc'] );
			}
		}

		return $meta;

	}

	/**
	 * 一括再圧縮キューで処理待ちかどうか
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function is_waiting_recompress( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		$ids           = get_option( '_sb-iqc-image-ids', array() );

		if( ! is_array( $ids ) || ! in_array( $attachment_id, $ids, true ) ) {
			return false;
		}

		$settings = get_option( Setting::SETTING_NAME, array() );
		$number   = isset( $settings['auto-regen-wpcron']['number'] ) ? absint( $settings['auto-regen-wpcron']['number'] ) : 0;

		if( 1 > $number && ! wp_next_scheduled( 'stillbe_image_quality_control_arg_wpcron_run' ) ) {
			return false;
		}

		$current = absint( get_option( '_sb-iqc-current-id', 0 ) );

		if( 0 === $current ) {
			return (bool) wp_next_scheduled( 'stillbe_image_quality_control_arg_wpcron_run' );
		}

		return $attachment_id < $current;

	}

	/**
	 * 表示すべき待機状態の一覧を返す
	 *
	 * @param int   $attachment_id
	 * @param array $meta
	 * @return string[]
	 */
	public static function get_pending_statuses( $attachment_id, $meta ) {

		$attachment_id = absint( $attachment_id );
		$pending       = array();
		$progress      = self::get_progress( $meta );
		$is_processing = ! empty( $progress ) && empty( $progress['completed'] );
		$is_done       = ! empty( $progress['completed'] );

		if( self::is_waiting_recompress( $attachment_id ) ) {
			$pending[] = self::PENDING_RECOMPRESS;
		}

		if( $is_done || $is_processing ) {
			return $pending;
		}

		$settings        = get_option( Setting::SETTING_NAME, array() );
		$enable_ao       = isset( $settings['toggle']['enable-auto-optimize'] ) ? $settings['toggle']['enable-auto-optimize'] : STILLBE_IQ_ENABLE_AUTO_OPTIMIZE;
		$enable_webp     = isset( $settings['toggle']['enable-webp'] ) ? $settings['toggle']['enable-webp'] : STILLBE_IQ_ENABLE_WEBP;
		$enable_avif     = isset( $settings['toggle']['enable-avif'] ) ? $settings['toggle']['enable-avif'] : STILLBE_IQ_ENABLE_AVIF;
		$enable_avif     = (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', $enable_avif, 'generate' );
		$save_webp_sync  = apply_filters( 'stillbe_image_quality_control_enable_save_webp_sync', STILLBE_IQ_ENABLE_SAVE_WEBP_SYNC, $meta );
		$save_avif_sync  = apply_filters( 'stillbe_image_quality_control_enable_save_avif_sync', STILLBE_IQ_ENABLE_SAVE_AVIF_SYNC, $meta );
		$mime_type       = get_post_mime_type( $attachment_id );

		if( $enable_ao && (
			Cron_Jobs::is_queued_auto_optimize( $attachment_id ) ||
			self::has_scheduled_hook_for_attachment( 'stillbe_iqc/auto_optimize', $attachment_id )
		) ) {
			$pending[] = self::PENDING_OPTIMIZE;
		} elseif( $enable_webp && ! $save_webp_sync && 'image/webp' !== $mime_type &&
		          self::has_scheduled_hook_for_attachment( 'stillbe_iqc/generate_webp', $attachment_id ) ) {
			$pending[] = self::PENDING_WEBP;
		} elseif( $enable_avif && ! $save_avif_sync && 'image/avif' !== $mime_type &&
		          self::has_scheduled_hook_for_attachment( 'stillbe_iqc/generate_avif', $attachment_id ) ) {
			$pending[] = self::PENDING_AVIF;
		}

		return $pending;

	}

	/**
	 * 指定添付 ID 向けに WP-Cron が登録されているか
	 *
	 * @param string $hook
	 * @param int    $attachment_id
	 * @return bool
	 */
	public static function has_scheduled_hook_for_attachment( $hook, $attachment_id ) {

		$attachment_id = absint( $attachment_id );

		if( wp_next_scheduled( $hook, array( $attachment_id ) ) ) {
			return true;
		}

		$crons = _get_cron_array();
		if( empty( $crons ) || ! is_array( $crons ) ) {
			return false;
		}

		foreach( $crons as $hooks ) {
			if( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach( $hooks[ $hook ] as $event ) {
				if( empty( $event['args'][0] ) ) {
					continue;
				}
				if( $attachment_id === absint( $event['args'][0] ) ) {
					return true;
				}
			}
		}

		return false;

	}


	/**
	 * 実行ロックが有効か (WP-Cron 処理中)
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function is_actively_processing( $attachment_id ) {

		return (bool) get_transient( '_sb-iqc-ao-lock-' . absint( $attachment_id ) );

	}


	/**
	 * 実行ロックの開始時刻 [unix]
	 *
	 * @param int $attachment_id
	 * @return int
	 */
	public static function get_processing_lock_time( $attachment_id ) {

		$started = get_transient( '_sb-iqc-ao-lock-' . absint( $attachment_id ) );

		return is_numeric( $started ) ? absint( $started ) : 0;

	}


	/**
	 * 最適化の表示用ステータスを返す
	 *
	 * @param int        $attachment_id
	 * @param array|null $meta
	 * @return array
	 */
	public static function get_activity_status( $attachment_id, $meta = null ) {

		$attachment_id = absint( $attachment_id );

		if( null === $meta ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
		}
		if( ! is_array( $meta ) ) {
			$meta = array();
		}

		$settings = get_option( Setting::SETTING_NAME, array() );
		$progress = self::get_progress( $meta );
		$pending  = self::get_pending_statuses( $attachment_id, $meta );
		$is_lock  = self::is_actively_processing( $attachment_id );

		$done    = ( isset( $progress['done'] ) && is_array( $progress['done'] ) ) ? $progress['done'] : array();
		$targets = self::get_target_size_names( $meta, $attachment_id, $settings );
		$total   = count( $targets );
		$done_n  = count( array_intersect( $targets, $done ) );

		$state = 'none';
		if( ! empty( $progress['completed'] ) ) {
			$state = 'done';
		} elseif( ! empty( $progress ) && empty( $progress['completed'] ) ) {
			$state = 'processing';
		} elseif( $is_lock ) {
			$state = 'processing';
		} elseif( in_array( self::PENDING_OPTIMIZE, $pending, true ) ) {
			$state = 'pending_optimize';
		} elseif( ! empty( $pending ) ) {
			$state = 'pending_other';
		}

		$started = absint( $progress['started'] ?? 0 );
		$lock_ts = self::get_processing_lock_time( $attachment_id );
		if( ! $started && $lock_ts ) {
			$started = $lock_ts;
		}

		$queue_pos   = Cron_Jobs::get_auto_optimize_queue_position( $attachment_id );
		$queue_wait  = Cron_Jobs::get_auto_optimize_queue_wait_seconds( $attachment_id );
		$remaining   = array_values( array_diff( $targets, $done ) );
		$is_live     = in_array( $state, array( 'processing', 'pending_optimize', 'pending_other' ), true );

		return array(
			'state'           => $state,
			'live'            => $is_live,
			'pending'         => $pending,
			'progress'        => $progress,
			'done'            => $done,
			'targets'         => $targets,
			'total'           => $total,
			'done_count'      => $done_n,
			'remaining'       => $remaining,
			'started'         => $started,
			'updated'         => absint( $progress['updated'] ?? 0 ),
			'completed'       => absint( $progress['completed'] ?? 0 ),
			'attempt'         => max( 1, absint( $progress['attempt'] ?? 0 ) ),
			'target_level'    => (string) ( $progress['target'] ?? ( $settings['auto-optimize-target'] ?? 'balance' ) ),
			'queue_position'  => $queue_pos,
			'queue_wait'      => $queue_wait,
			'is_lock'         => $is_lock,
			'attachment_id'   => $attachment_id,
		);

	}


	/**
	 * 秒数を読みやすい文字列にする
	 *
	 * @param int $seconds
	 * @return string
	 */
	public static function format_duration( $seconds ) {

		$seconds = max( 0, absint( $seconds ) );

		if( 60 > $seconds ) {
			return sprintf(
				/* translators: %d: seconds */
				_n( '%d sec', '%d sec', $seconds, 'still-be-image-quality-control' ),
				$seconds
			);
		}

		$minutes = (int) floor( $seconds / 60 );
		$rest    = $seconds % 60;

		if( 60 > $minutes ) {
			if( 0 < $rest ) {
				return sprintf(
					/* translators: 1: minutes, 2: seconds */
					__( '%1$d min %2$d sec', 'still-be-image-quality-control' ),
					$minutes,
					$rest
				);
			}
			return sprintf(
				/* translators: %d: minutes */
				_n( '%d min', '%d min', $minutes, 'still-be-image-quality-control' ),
				$minutes
			);
		}

		$hours = (int) floor( $minutes / 60 );
		$mins  = $minutes % 60;

		if( 0 < $mins ) {
			return sprintf(
				/* translators: 1: hours, 2: minutes */
				__( '%1$d hr %2$d min', 'still-be-image-quality-control' ),
				$hours,
				$mins
			);
		}

		return sprintf(
			/* translators: %d: hours */
			_n( '%d hr', '%d hr', $hours, 'still-be-image-quality-control' ),
			$hours
		);

	}


	/**
	 * 目標レベルの表示ラベル
	 *
	 * @param string $level
	 * @return string
	 */
	public static function get_target_level_label( $level ) {

		$labels = array(
			'efficiency' => __( 'Efficiency', 'still-be-image-quality-control' ),
			'balance'    => __( 'Balance', 'still-be-image-quality-control' ),
			'quality'    => __( 'Quality', 'still-be-image-quality-control' ),
		);

		return $labels[ $level ] ?? $labels['balance'];

	}

	/**
	 * 試行回数・ターゲットレベル等の基本情報を更新する
	 *
	 * @param array  $meta
	 * @param int    $attempt
	 * @param string $target_level
	 * @param array  $largest_webp
	 * @return array
	 */
	public static function touch_progress( $meta, $attempt, $target_level = 'balance', $largest_webp = null, $largest_avif = null ) {

		$progress = self::get_progress( $meta );
		$done     = ( isset( $progress['done'] ) && is_array( $progress['done'] ) ) ? $progress['done'] : array();

		$progress = array(
			'done'         => array_values( array_unique( $done ) ),
			'attempt'      => absint( $attempt ),
			'target'       => (string) $target_level,
			'started'      => ! empty( $progress['started'] ) ? absint( $progress['started'] ) : time(),
			'updated'      => time(),
			'largest-webp' => ( isset( $progress['largest-webp'] ) && is_array( $progress['largest-webp'] ) ) ? $progress['largest-webp'] : array( 'width' => 0, 'quality' => 0 ),
			'largest-avif' => ( isset( $progress['largest-avif'] ) && is_array( $progress['largest-avif'] ) ) ? $progress['largest-avif'] : array( 'width' => 0, 'quality' => 0 ),
		);

		if( is_array( $largest_webp ) ) {
			$progress['largest-webp'] = $largest_webp;
		}
		if( is_array( $largest_avif ) ) {
			$progress['largest-avif'] = $largest_avif;
		}

		$meta[ self::META_PROGRESS ] = $progress;

		return $meta;
	}


	/**
	 * 処理中の進捗を DB に書き込む（サイズ単位で UI / 品質メタを更新するため）
	 *
	 * @param int         $attachment_id
	 * @param array       $done
	 * @param int         $attempt
	 * @param string      $target_level
	 * @param array|null  $largest_webp
	 * @param array|null  $working_meta  メモリ上の最新メタ (品質反映済み)
	 * @param string|null $size_name     今回完了したサイズ名 (original 可)
	 */
	public static function persist_progress( $attachment_id, $done, $attempt = 0, $target_level = 'balance', $largest_webp = null, $working_meta = null, $size_name = null, $largest_avif = null ) {

		$attachment_id = absint( $attachment_id );
		if( 1 > $attachment_id ) {
			return;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if( ! is_array( $meta ) ) {
			return;
		}

		$meta = self::touch_progress( $meta, $attempt, $target_level, $largest_webp, $largest_avif );
		$meta[ self::META_PROGRESS ]['done'] = array_values( array_unique( (array) $done ) );
		unset( $meta[ self::META_PROGRESS ]['completed'] );

		// 決定品質・ファイルサイズをサイズ単位で即時保存する
		if( is_array( $working_meta ) && null !== $size_name && '' !== (string) $size_name ) {
			if( 'original' === $size_name ) {
				if( isset( $working_meta['sb-iqc'] ) && is_array( $working_meta['sb-iqc'] ) ) {
					$meta['sb-iqc'] = $working_meta['sb-iqc'];
				} else {
					// 上限張り付きの破棄 (purge) を DB にも反映する
					unset( $meta['sb-iqc'] );
				}
			} elseif( isset( $working_meta['sizes'][ $size_name ] ) && is_array( $working_meta['sizes'][ $size_name ] ) ) {
				if( ! isset( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
					$meta['sizes'] = array();
				}
				if( ! isset( $meta['sizes'][ $size_name ] ) || ! is_array( $meta['sizes'][ $size_name ] ) ) {
					$meta['sizes'][ $size_name ] = array();
				}
				$src = $working_meta['sizes'][ $size_name ];
				if( isset( $src['sb-iqc'] ) && is_array( $src['sb-iqc'] ) ) {
					$meta['sizes'][ $size_name ]['sb-iqc'] = $src['sb-iqc'];
				} else {
					// 上限張り付きの破棄 (purge) を DB にも反映する
					unset( $meta['sizes'][ $size_name ]['sb-iqc'] );
				}
				if( isset( $src['filesize'] ) ) {
					$meta['sizes'][ $size_name ]['filesize'] = $src['filesize'];
				}
				if( isset( $src['updated'] ) ) {
					$meta['sizes'][ $size_name ]['updated'] = $src['updated'];
				}
			}
		}

		wp_update_attachment_metadata( $attachment_id, $meta );

	}

	/**
	 * 最適化対象の size_name 一覧を返す
	 *
	 * @param array      $meta
	 * @param int        $attachment_id
	 * @param array|null $settings
	 * @return string[]
	 */
	public static function get_target_size_names( $meta, $attachment_id = 0, $settings = null ) {

		if( empty( $meta['file'] ) ) {
			return array();
		}

		if( null === $settings ) {
			$settings = get_option( Setting::SETTING_NAME, array() );
		}

		$attachment_id = absint( $attachment_id );
		$mime_type     = $attachment_id ? (string) get_post_mime_type( $attachment_id ) : '';
		$enable_webp   = isset( $settings['toggle']['enable-webp'] ) ? $settings['toggle']['enable-webp'] : STILLBE_IQ_ENABLE_WEBP;
		$enable_webp   = (bool) apply_filters( 'stillbe_image_quality_control_enable_webp', $enable_webp, 'generate' );
		$enable_avif   = isset( $settings['toggle']['enable-avif'] ) ? $settings['toggle']['enable-avif'] : STILLBE_IQ_ENABLE_AVIF;
		$enable_avif   = (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', $enable_avif, 'generate' );

		$upload_dir = wp_upload_dir();
		$base_dir   = dirname( path_join( $upload_dir['basedir'], $meta['file'] ) );
		$targets    = array();
		$is_webp_source = ( 'image/webp' === $mime_type );
		$is_avif_source = ( 'image/avif' === $mime_type );

		if( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach( $meta['sizes'] as $size_name => $size_data ) {
				if( empty( $size_data['file'] ) ) {
					continue;
				}
				$size_mime = isset( $size_data['mime-type'] ) ? $size_data['mime-type'] : $mime_type;
				if( 'image/webp' === $size_mime && ! $is_webp_source ) {
					continue;
				}
				if( 'image/avif' === $size_mime && ! $is_avif_source ) {
					continue;
				}
				if( ! file_exists( path_join( $base_dir, $size_data['file'] ) ) ) {
					continue;
				}
				$targets[] = (string) $size_name;
			}
		}

		if( $is_webp_source || $is_avif_source || $enable_webp || $enable_avif ) {
			$targets[] = 'original';
		}

		return array_values( array_unique( $targets ) );

	}


	/**
	 * 全サイズの最適化が完了したか
	 *
	 * @param array      $meta
	 * @param array      $done
	 * @param int        $attachment_id
	 * @param array|null $settings
	 * @return bool
	 */
	public static function is_optimization_complete( $meta, $done, $attachment_id = 0, $settings = null ) {

		$targets = self::get_target_size_names( $meta, $attachment_id, $settings );
		if( empty( $targets ) ) {
			return true;
		}

		if( ! is_array( $done ) ) {
			return false;
		}

		foreach( $targets as $size_name ) {
			if( ! in_array( $size_name, $done, true ) ) {
				return false;
			}
		}

		return true;

	}


	/**
	 * 実行結果を進捗へ反映する
	 *
	 * @param array $meta
	 * @param array $result Auto_Optimize の戻り値
	 * @param int   $attachment_id
	 * @param array|null $settings
	 * @return array
	 */
	public static function apply_result( $meta, $result, $attachment_id = 0, $settings = null ) {

		$done         = ( isset( $result['done'] ) && is_array( $result['done'] ) ) ? $result['done'] : array();
		$attempt      = absint( $result['attempt'] ?? 0 );
		$target_level = (string) ( $result['target_level'] ?? 'balance' );
		$largest_webp = ( isset( $result['largest_webp'] ) && is_array( $result['largest_webp'] ) ) ? $result['largest_webp'] : array( 'width' => 0, 'quality' => 0 );
		$largest_avif = ( isset( $result['largest_avif'] ) && is_array( $result['largest_avif'] ) ) ? $result['largest_avif'] : array( 'width' => 0, 'quality' => 0 );
		$suspended    = ! empty( $result['suspended'] );

		$meta = self::touch_progress( $meta, $attempt, $target_level, $largest_webp, $largest_avif );
		$meta[ self::META_PROGRESS ]['done'] = array_values( array_unique( $done ) );

		$is_complete = self::is_optimization_complete( $meta, $done, $attachment_id, $settings );

		if( $is_complete && ! $suspended ) {
			$meta[ self::META_PROGRESS ]['completed'] = time();
		} else {
			unset( $meta[ self::META_PROGRESS ]['completed'] );
		}

		return $meta;
	}

	/**
	 * 統計（before）を未保存なら保存する
	 *
	 * @param int   $attachment_id
	 * @param array $meta
	 * @return array
	 */
	public static function ensure_stats_before( $attachment_id, $meta ) {

		$stats = self::get_stats( $meta );
		if( isset( $stats['orig_main_bytes'] ) && is_numeric( $stats['orig_main_bytes'] ) ) {
			return $meta;
		}
		if( isset( $stats['orig_bytes'] ) && is_numeric( $stats['orig_bytes'] ) ) {
			return $meta;
		}

		$breakdown = self::calc_size_breakdown( $attachment_id, $meta );
		$orig_main = (int) $breakdown['main'];
		$orig_full = (int) $breakdown['full'];
		$orig_webp = (int) $breakdown['webp'];
		$orig_avif = (int) $breakdown['avif'];

		$meta[ self::META_STATS ] = array(
			'orig_main_bytes' => $orig_main,
			'orig_full_bytes' => $orig_full,
			'orig_webp_bytes' => $orig_webp,
			'orig_avif_bytes' => $orig_avif,
			'orig_bytes'      => $orig_main + $orig_full + $orig_webp + $orig_avif,
			'orig_items'      => $breakdown['items'],
			'opt_main_bytes'  => null,
			'opt_webp_bytes'  => null,
			'opt_avif_bytes'  => null,
			'opt_bytes'       => null,
			'opt_items'       => null,
			'saved_bytes'     => null,
			'saved_ratio'     => null,
			'updated'         => time(),
		);

		return $meta;
	}

	/**
	 * 統計（after）を保存する（完了時に呼ぶ）
	 *
	 * @param int   $attachment_id
	 * @param array $meta
	 * @return array
	 */
	public static function finalize_stats( $attachment_id, $meta ) {

		$stats = self::get_stats( $meta );
		if( ! isset( $stats['orig_main_bytes'] ) || ! is_numeric( $stats['orig_main_bytes'] ) ) {
			$meta  = self::ensure_stats_before( $attachment_id, $meta );
			$stats = self::get_stats( $meta );
		}

		$breakdown = self::calc_size_breakdown( $attachment_id, $meta );
		$opt_main  = (int) $breakdown['main'];
		$opt_webp  = (int) $breakdown['webp'];
		$opt_avif  = (int) $breakdown['avif'];
		$orig      = isset( $stats['orig_bytes'] ) ? (int) $stats['orig_bytes'] : 0;
		$opt       = $opt_main + (int) ( $stats['orig_full_bytes'] ?? 0 ) + $opt_webp + $opt_avif;

		$saved = max( 0, $orig - $opt );
		$ratio = $orig > 0 ? ( $saved / $orig ) : 0;

		$meta[ self::META_STATS ] = array_merge(
			is_array( $stats ) ? $stats : array(),
			array(
				'opt_main_bytes' => $opt_main,
				'opt_webp_bytes' => $opt_webp,
				'opt_avif_bytes' => $opt_avif,
				'opt_bytes'      => $opt,
				'opt_items'      => $breakdown['items'],
				'saved_bytes'    => $saved,
				'saved_ratio'    => $ratio,
				'updated'        => time(),
			)
		);

		return $meta;
	}

	/**
	 * メディアライブラリの「最適化状況」列を出力する
	 *
	 * @param int $attachment_id
	 */
	public static function render_media_library_column( $attachment_id ) {

		$attachment_id = absint( $attachment_id );
		$meta          = wp_get_attachment_metadata( $attachment_id );

		if( ! is_array( $meta ) ) {
			self::_echo_column_empty();
			return;
		}

		$status = self::get_activity_status( $attachment_id, $meta );

		if( 'none' === $status['state'] ) {
			self::_echo_column_empty( esc_html__( 'Not optimized', 'still-be-image-quality-control' ) );
			return;
		}

		$live_attr = '';
		if( $status['live'] ) {
			$live_attr = ' data-sb-iqc-ao-live="' . esc_attr( $attachment_id ) . '"';
		}

		echo '<div class="sb-iqc-ao-col' . ( $status['live'] ? ' sb-iqc-ao-col--live' : '' ) . '"' . $live_attr . '>';

		if( ! empty( $status['pending'] ) && 'done' !== $status['state'] ) {
			self::_render_pending_statuses( $status['pending'], $status );
		}

		if( 'done' === $status['state'] ) {
			self::_render_done_status( $meta, $status );
		} elseif( 'processing' === $status['state'] ) {
			self::_render_active_processing_status( $meta, $status );
		}

		echo '</div>';

	}


	/**
	 * 完了時のヘッダ表示
	 *
	 * @param array $meta
	 * @param array $status
	 */
	private static function _render_done_status( $meta, $status ) {

		$stats     = self::get_stats( $meta );
		$completed = $status['completed'];

		echo '<span class="sb-iqc-ao-col__status sb-iqc-ao-col__status--done">';
		echo esc_html__( 'Completed', 'still-be-image-quality-control' );
		echo '</span>';

		if( $completed ) {
			echo '<div class="sb-iqc-ao-col__date">';
			echo esc_html(
				wp_date(
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
					$completed
				)
			);
			if( ! empty( $status['target_level'] ) ) {
				echo ' / ';
				printf(
					/* translators: %s: target level label */
					esc_html__( 'Target: %s', 'still-be-image-quality-control' ),
					esc_html( self::get_target_level_label( $status['target_level'] ) )
				);
			}
			echo '</div>';
		}

		self::_render_completed_stats( $stats, absint( $status['attachment_id'] ?? 0 ) );

	}


	/**
	 * 処理中のヘッダ・進捗表示
	 *
	 * @param array $meta
	 * @param array $status
	 */
	private static function _render_active_processing_status( $meta, $status ) {

		$stats       = self::get_stats( $meta );
		$attempt     = $status['attempt'];
		$total       = $status['total'];
		$done_count  = $status['done_count'];
		$started     = $status['started'];
		$updated     = $status['updated'];
		$elapsed_ref = $started ?: $updated;
		$elapsed     = $elapsed_ref ? ( time() - $elapsed_ref ) : 0;
		$pct         = ( 0 < $total ) ? min( 100, (int) round( ( $done_count / $total ) * 100 ) ) : 0;

		echo '<span class="sb-iqc-ao-col__status sb-iqc-ao-col__status--processing sb-iqc-ao-col__status--active">';
		if( $status['is_lock'] && 1 > $done_count && empty( $status['progress'] ) ) {
			echo esc_html__( 'Optimizing...', 'still-be-image-quality-control' );
		} else {
			printf(
				/* translators: %d: attempt count */
				esc_html__( 'Optimizing... (attempt %d)', 'still-be-image-quality-control' ),
				$attempt
			);
		}
		echo '</span>';

		echo '<div class="sb-iqc-ao-col__metrics">';

		if( 0 < $total ) {
			$is_indeterminate = $status['is_lock'] && 1 > $done_count;
			echo '<div class="sb-iqc-ao-col__progress-wrap">';
			echo '<div class="sb-iqc-ao-col__progress' . ( $is_indeterminate ? ' sb-iqc-ao-col__progress--indeterminate' : '' ) . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( $pct ) . '">';
			echo '<div class="sb-iqc-ao-col__progress-bar" style="width:' . esc_attr( $is_indeterminate ? '35' : $pct ) . '%;"></div>';
			echo '</div>';
			printf(
				'<span class="sb-iqc-ao-col__progress-label">%s</span>',
				esc_html(
					sprintf(
						/* translators: 1: done count, 2: total count, 3: percent */
						__( '%1$d / %2$d sizes (%3$d%%)', 'still-be-image-quality-control' ),
						$done_count,
						$total,
						$pct
					)
				)
			);
			echo '</div>';
		}

		if( $elapsed_ref ) {
			echo '<div class="sb-iqc-ao-col__elapsed">';
			printf(
				/* translators: %s: elapsed time */
				esc_html__( 'Elapsed: %s', 'still-be-image-quality-control' ),
				esc_html( self::format_duration( $elapsed ) )
			);
			echo '</div>';
		}

		echo '<div class="sb-iqc-ao-col__meta-line">';
		printf(
			/* translators: %s: target level label */
			esc_html__( 'Target: %s', 'still-be-image-quality-control' ),
			esc_html( self::get_target_level_label( $status['target_level'] ) )
		);
		if( $updated ) {
			echo ' · ';
			printf(
				/* translators: %s: time */
				esc_html__( 'Last update: %s', 'still-be-image-quality-control' ),
				esc_html( wp_date( get_option( 'time_format' ), $updated ) )
			);
		}
		echo '</div>';

		echo '</div>';

		if( ! empty( $status['remaining'] ) ) {
			echo '<details class="sb-iqc-ao-col__details sb-iqc-ao-col__details--inline">';
			echo '<summary class="sb-iqc-ao-col__summary">';
			printf(
				/* translators: %s: comma-separated size names */
				esc_html__( 'Remaining: %s', 'still-be-image-quality-control' ),
				esc_html( implode( ', ', array_slice( $status['remaining'], 0, 4 ) ) . ( 4 < count( $status['remaining'] ) ? '…' : '' ) )
			);
			echo '</summary>';
			echo '<div class="sb-iqc-ao-col__detail-note">';
			echo esc_html( implode( ', ', $status['remaining'] ) );
			echo '</div>';
			echo '</details>';
		}

		if( isset( $stats['orig_main_bytes'] ) && is_numeric( $stats['orig_main_bytes'] ) ) {
			self::_render_processing_stats( $stats, absint( $status['attachment_id'] ?? 0 ) );
		} elseif( isset( $stats['orig_bytes'] ) && is_numeric( $stats['orig_bytes'] ) ) {
			echo '<div class="sb-iqc-ao-col__compare">';
			printf(
				/* translators: %s: size before optimization */
				esc_html__( 'Baseline: subsizes + WebP total (%s)', 'still-be-image-quality-control' ),
				esc_html( self::format_size_display( (int) $stats['orig_bytes'] ) )
			);
			echo '</div>';
		}

	}

	/**
	 * 待機状態の表示
	 *
	 * @param string[] $pending
	 * @param array    $status get_activity_status() の戻り値
	 */
	private static function _render_pending_statuses( $pending, $status = array() ) {

		$labels = array(
			self::PENDING_RECOMPRESS => __( 'Waiting for recompress', 'still-be-image-quality-control' ),
			self::PENDING_OPTIMIZE   => __( 'Waiting for optimization', 'still-be-image-quality-control' ),
			self::PENDING_WEBP       => __( 'Waiting for WebP generation', 'still-be-image-quality-control' ),
			self::PENDING_AVIF       => __( 'Waiting for AVIF generation', 'still-be-image-quality-control' ),
		);

		echo '<div class="sb-iqc-ao-col__pending-list">';

		foreach( $pending as $key ) {
			if( empty( $labels[ $key ] ) ) {
				continue;
			}
			echo '<span class="sb-iqc-ao-col__status sb-iqc-ao-col__status--pending">';
			echo esc_html( $labels[ $key ] );

			if( self::PENDING_OPTIMIZE === $key ) {
				if( ! empty( $status['queue_position'] ) ) {
					printf(
						' (%s)',
						esc_html(
							sprintf(
								/* translators: %d: queue position (1-based) */
								__( 'queue #%d', 'still-be-image-quality-control' ),
								(int) $status['queue_position']
							)
						)
					);
				}
				if( ! empty( $status['queue_wait'] ) ) {
					printf(
						' — %s',
						esc_html(
							sprintf(
								/* translators: %s: waiting duration */
								__( 'waiting %s', 'still-be-image-quality-control' ),
								self::format_duration( (int) $status['queue_wait'] )
							)
						)
					);
				}
			}

			echo '</span>';
		}

		echo '</div>';

	}

	/**
	 * 元画像 MIME から表示用フォーマット名を返す
	 *
	 * @param string $mime_type
	 * @return string
	 */
	public static function get_source_format_label( $mime_type ) {

		switch( (string) $mime_type ) {
			case 'image/jpeg':
				return __( 'JPEG', 'still-be-image-quality-control' );
			case 'image/png':
				return __( 'PNG', 'still-be-image-quality-control' );
			case 'image/gif':
				return __( 'GIF', 'still-be-image-quality-control' );
			case 'image/webp':
				return __( 'WebP', 'still-be-image-quality-control' );
			case 'image/avif':
				return __( 'AVIF', 'still-be-image-quality-control' );
			default:
				return __( 'Original', 'still-be-image-quality-control' );
		}

	}


	/**
	 * バイト数を有効桁数 3 桁で表示する
	 *
	 * 例: 40.0 KB / 1.23 MB / 12.3 MB / 123 MB
	 *
	 * @param int $bytes
	 * @return string
	 */
	public static function format_size_display( $bytes ) {

		$bytes = max( 0, (int) $bytes );

		$units = array(
			array( 'B', 1 ),
			array( 'KB', defined( 'KB_IN_BYTES' ) ? KB_IN_BYTES : 1024 ),
			array( 'MB', defined( 'MB_IN_BYTES' ) ? MB_IN_BYTES : 1048576 ),
			array( 'GB', defined( 'GB_IN_BYTES' ) ? GB_IN_BYTES : 1073741824 ),
			array( 'TB', defined( 'TB_IN_BYTES' ) ? TB_IN_BYTES : 1099511627776 ),
		);

		if( 0 === $bytes ) {
			return number_format_i18n( 0, 1 ) . ' B';
		}

		$unit_index = 0;
		$last_index = count( $units ) - 1;
		for( $i = $last_index; $i >= 0; $i-- ) {
			if( $bytes >= $units[ $i ][1] ) {
				$unit_index = $i;
				break;
			}
		}

		$value = $bytes / $units[ $unit_index ][1];

		// 有効桁数 3 桁になるよう小数桁を決める
		$order    = (int) floor( log10( $value ) );
		$decimals = max( 0, 2 - $order );
		$rounded  = round( $value, $decimals );

		// 繰り上がりで次の単位へ（1000 以上は 4 桁表示を避ける）
		while( $rounded >= 1000 && $unit_index < $last_index ) {
			$unit_index++;
			$value    = $bytes / $units[ $unit_index ][1];
			$order    = (int) floor( log10( $value ) );
			$decimals = max( 0, 2 - $order );
			$rounded  = round( $value, $decimals );
		}

		// 繰り上がりで桁数が変わった場合に小数桁を再調整
		if( $rounded > 0 ) {
			$order    = (int) floor( log10( $rounded ) );
			$decimals = max( 0, 2 - $order );
		}

		return number_format_i18n( $rounded, $decimals ) . ' ' . $units[ $unit_index ][0];

	}


	/**
	 * 最適化前サイズの表示（サブサイズ合計 + Original 込みを括弧で併記）
	 *
	 * @param int $subsizes_bytes サブサイズ合計 [bytes]
	 * @param int $original_bytes Original [bytes]
	 * @return string
	 */
	public static function format_before_size_with_original( $subsizes_bytes, $original_bytes = 0 ) {

		$subsizes_bytes = (int) $subsizes_bytes;
		$original_bytes = (int) $original_bytes;

		if( 1 > $subsizes_bytes && 1 > $original_bytes ) {
			return '';
		}

		if( 1 > $original_bytes ) {
			return self::format_size_display( $subsizes_bytes );
		}

		$with_original = $subsizes_bytes + $original_bytes;

		return sprintf(
			/* translators: 1: subsizes total, 2: total including Original */
			__( '%1$s (%2$s incl. Original)', 'still-be-image-quality-control' ),
			self::format_size_display( $subsizes_bytes ),
			self::format_size_display( $with_original )
		);

	}


	/**
	 * 詳細テーブルの列構成を返す
	 *
	 * @param int   $attachment_id
	 * @param array $stats
	 * @return array
	 */
	public static function get_detail_table_layout( $attachment_id, $stats = array() ) {

		$attachment_id     = absint( $attachment_id );
		$mime_type         = $attachment_id ? (string) get_post_mime_type( $attachment_id ) : '';
		$is_webp_source    = ( 'image/webp' === $mime_type );
		$is_avif_source    = ( 'image/avif' === $mime_type );
		$is_inplace_source = ( $is_webp_source || $is_avif_source );
		$format_label      = self::get_source_format_label( $mime_type );

		// 代替配信列は「現在有効な形式」のみ表示する (無効化された形式の情報は表示しない)
		$has_webp_delivery = false;
		$has_avif_delivery = false;
		if( ! $is_inplace_source && in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/gif' ), true ) ) {
			$settings    = get_option( Setting::SETTING_NAME, array() );
			$enable_webp = isset( $settings['toggle']['enable-webp'] ) ? $settings['toggle']['enable-webp'] : STILLBE_IQ_ENABLE_WEBP;
			$enable_avif = isset( $settings['toggle']['enable-avif'] ) ? $settings['toggle']['enable-avif'] : STILLBE_IQ_ENABLE_AVIF;
			$has_webp_delivery = (bool) apply_filters( 'stillbe_image_quality_control_enable_webp', $enable_webp, 'generate' );
			$has_avif_delivery = (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', $enable_avif, 'generate' );
		}

		// Size + Not Optimized + (format | WebP/AVIF for in-place source) [+ delivery WebP] [+ delivery AVIF]
		$col_count    = 2 + ( $is_inplace_source ? 1 : ( 1 + ( $has_webp_delivery ? 1 : 0 ) + ( $has_avif_delivery ? 1 : 0 ) ) );
		$show_details = ( $is_inplace_source || $has_webp_delivery || $has_avif_delivery );

		return array(
			'mime_type'         => $mime_type,
			'is_webp_source'    => $is_webp_source,
			'is_avif_source'    => $is_avif_source,
			'is_inplace_source' => $is_inplace_source,
			'format_label'      => $format_label,
			'has_webp_delivery' => $has_webp_delivery,
			'has_avif_delivery' => $has_avif_delivery,
			'show_details'      => $show_details,
			'col_count'         => $col_count,
		);

	}


	/**
	 * 完了時の統計表示（summary / details）
	 *
	 * @param array $stats
	 * @param int   $attachment_id
	 */
	private static function _render_completed_stats( $stats, $attachment_id = 0 ) {

		if( isset( $stats['orig_main_bytes'] ) && is_numeric( $stats['orig_main_bytes'] ) ) {
			$layout = self::get_detail_table_layout( $attachment_id, $stats );

			if( empty( $layout['show_details'] ) ) {
				echo '<div class="sb-iqc-ao-col__summary-only">';
				self::_echo_compact_summary( $stats, $attachment_id );
				echo '</div>';
				return;
			}

			echo '<details class="sb-iqc-ao-col__details">';
			echo '<summary class="sb-iqc-ao-col__summary">';
			self::_echo_compact_summary( $stats, $attachment_id );
			echo '</summary>';
			self::_render_stats_details( $stats, false, $attachment_id );
			echo '</details>';
			return;
		}

		$orig = isset( $stats['orig_bytes'] ) ? (int) $stats['orig_bytes'] : 0;
		$opt  = isset( $stats['opt_bytes'] ) ? (int) $stats['opt_bytes'] : 0;
		if( $orig > 0 && $opt >= 0 ) {
			echo '<details class="sb-iqc-ao-col__details">';
			echo '<summary class="sb-iqc-ao-col__summary">';
			echo '<span class="sb-iqc-ao-col__summary-compact">';
			echo esc_html( self::format_size_display( $orig ) );
			echo ' → ';
			echo esc_html( self::format_size_display( $opt ) );
			echo ' ';
			echo esc_html( self::format_percent_paren( $orig, $opt ) );
			echo '</span>';
			echo '</summary></details>';
		}

	}

	/**
	 * 処理中のベースライン表示
	 *
	 * @param array $stats
	 * @param int   $attachment_id
	 */
	private static function _render_processing_stats( $stats, $attachment_id = 0 ) {

		$layout = self::get_detail_table_layout( $attachment_id, $stats );

		if( empty( $layout['show_details'] ) ) {
			echo '<div class="sb-iqc-ao-col__summary-only">';
			printf(
				/* translators: %s: size before optimization */
				esc_html__( 'Not optimized: %s', 'still-be-image-quality-control' ),
				esc_html( self::format_size_display( (int) $stats['orig_main_bytes'] ) )
			);
			echo '</div>';
			return;
		}

		echo '<details class="sb-iqc-ao-col__details">';
		echo '<summary class="sb-iqc-ao-col__summary">';
		echo '<span class="sb-iqc-ao-col__summary-compact">';
		printf(
			/* translators: %s: size before optimization */
			esc_html__( 'Baseline: %s', 'still-be-image-quality-control' ),
			esc_html( self::format_size_display( (int) $stats['orig_main_bytes'] ) )
		);
		echo '</span>';
		echo '</summary>';
		self::_render_stats_details( $stats, true, $attachment_id );
		echo '</details>';

	}


	/**
	 * サマリー（1 行・コンパクト）
	 *
	 * @param array $stats
	 * @param int   $attachment_id
	 */
	private static function _echo_compact_summary( $stats, $attachment_id = 0 ) {

		$layout     = self::get_detail_table_layout( $attachment_id, $stats );
		$orig_main  = (int) ( $stats['orig_main_bytes'] ?? 0 );
		$orig_full  = (int) ( $stats['orig_full_bytes'] ?? 0 );
		$opt_main   = (int) ( $stats['opt_main_bytes'] ?? 0 );
		$orig_items = isset( $stats['orig_items'] ) && is_array( $stats['orig_items'] ) ? $stats['orig_items'] : array();
		$opt_items  = isset( $stats['opt_items'] ) && is_array( $stats['opt_items'] ) ? $stats['opt_items'] : array();

		if( 1 > $orig_main && 1 > $orig_full ) {
			return;
		}

		echo '<span class="sb-iqc-ao-col__summary-compact">';

		// 元形式 (WebP / AVIF ソースの場合はインプレース最適化の結果)
		printf(
			/* translators: 1: size before, 2: optimized size, 3: percent, 4: format label */
			esc_html__( '%1$s → %4$s: %2$s %3$s', 'still-be-image-quality-control' ),
			esc_html( self::format_before_size_with_original( $orig_main, $orig_full ) ),
			esc_html( self::format_size_display( $opt_main ) ),
			esc_html( self::format_percent_paren( $orig_main, $opt_main ) ),
			esc_html( $layout['format_label'] )
		);

		// 代替配信 (生成されたサイズのみを比較対象とする)
		if( ! empty( $layout['has_webp_delivery'] ) ) {
			$cmp = self::sum_generated_alt_totals( $orig_items, $opt_items, 'webp' );
			printf(
				/* translators: 1: WebP total, 2: percent change */
				esc_html__( ', WebP: %1$s %2$s', 'still-be-image-quality-control' ),
				esc_html( 1 > $cmp['after'] ? '—' : self::format_size_display( $cmp['after'] ) ),
				esc_html( 1 > $cmp['after'] ? '' : self::format_percent_paren( $cmp['before'], $cmp['after'] ) )
			);
		}
		if( ! empty( $layout['has_avif_delivery'] ) ) {
			$cmp = self::sum_generated_alt_totals( $orig_items, $opt_items, 'avif' );
			printf(
				/* translators: 1: AVIF total, 2: percent change */
				esc_html__( ', AVIF: %1$s %2$s', 'still-be-image-quality-control' ),
				esc_html( 1 > $cmp['after'] ? '—' : self::format_size_display( $cmp['after'] ) ),
				esc_html( 1 > $cmp['after'] ? '' : self::format_percent_paren( $cmp['before'], $cmp['after'] ) )
			);
		}

		echo '</span>';

	}


	/**
	 * 生成済みサイズのみを対象に、代替配信の合計と比較基準 (対応する元形式の合計) を返す
	 *
	 * 上限張り付きで破棄されたサイズや未生成のサイズは、削減量の比較対象に含めない。
	 *
	 * @param array  $orig_items 最適化前のサイズ別内訳
	 * @param array  $opt_items  最適化後のサイズ別内訳 (空なら orig_items を生成状況として使う)
	 * @param string $format     webp|avif
	 * @return array{before:int,after:int}
	 */
	public static function sum_generated_alt_totals( $orig_items, $opt_items, $format ) {

		$items  = ! empty( $opt_items ) ? $opt_items : $orig_items;
		$before = 0;
		$after  = 0;

		if( ! is_array( $items ) ) {
			return array( 'before' => 0, 'after' => 0 );
		}

		foreach( $items as $size_name => $item ) {
			$bytes = (int) ( $item[ $format ] ?? 0 );
			if( 1 > $bytes ) {
				continue;
			}
			$after  += $bytes;
			$before += (int) ( $orig_items[ $size_name ]['main'] ?? ( $item['main'] ?? 0 ) );
		}

		return array( 'before' => $before, 'after' => $after );

	}

	/**
	 * 変化率を (±n.n%) 形式で返す
	 *
	 * @param int $before_bytes
	 * @param int $after_bytes
	 * @return string
	 */
	public static function format_percent_paren( $before_bytes, $after_bytes ) {

		$change = self::get_percent_change( $before_bytes, $after_bytes );

		if( empty( $change ) ) {
			return '';
		}

		if( 'neutral' === $change['direction'] ) {
			return '(0%)';
		}

		$prefix = 'reduced' === $change['direction'] ? '△' : '+';

		return sprintf(
			'(%s%s%%)',
			$prefix,
			number_format_i18n( $change['value'], 1 )
		);

	}


	/**
	 * 変化率の方向と絶対値 [%] を返す
	 *
	 * @param int $before_bytes
	 * @param int $after_bytes
	 * @return array{direction:string,value:float}|array{}
	 */
	public static function get_percent_change( $before_bytes, $after_bytes ) {

		$before_bytes = (int) $before_bytes;
		$after_bytes  = (int) $after_bytes;

		if( 1 > $before_bytes ) {
			return array();
		}

		$pct = ( $before_bytes - $after_bytes ) / $before_bytes * 100;

		if( 0.05 > abs( $pct ) ) {
			return array(
				'direction' => 'neutral',
				'value'     => 0.0,
			);
		}

		return array(
			'direction' => 0 <= $pct ? 'reduced' : 'increased',
			'value'     => abs( $pct ),
		);

	}


	/**
	 * 変化率を HTML で出力する（△ 減少=青、+ 増加=赤）
	 *
	 * @param int $before_bytes
	 * @param int $after_bytes
	 */
	public static function echo_percent_change_markup( $before_bytes, $after_bytes ) {

		$change = self::get_percent_change( $before_bytes, $after_bytes );

		if( empty( $change ) ) {
			return;
		}

		if( 'neutral' === $change['direction'] ) {
			echo '<span class="sb-iqc-ao-col__detail-pct sb-iqc-ao-col__detail-pct--neutral">';
			echo '(0%)';
			echo '</span>';
			return;
		}

		$is_reduced = 'reduced' === $change['direction'];
		$class      = $is_reduced ? 'sb-iqc-ao-col__detail-pct--reduced' : 'sb-iqc-ao-col__detail-pct--increased';
		$prefix     = $is_reduced ? '&#9651;' : '+';

		echo '<span class="sb-iqc-ao-col__detail-pct ' . esc_attr( $class ) . '">';
		echo '(';
		echo $prefix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- numeric entity
		echo esc_html( number_format_i18n( $change['value'], 1 ) );
		echo '%)';
		echo '</span>';

	}

	/**
	 * 詳細表示（サイズ別・1 テーブル）
	 *
	 * @param array $stats
	 * @param bool  $processing_only 処理中は最適化後を表示しない
	 * @param int   $attachment_id
	 */
	private static function _render_stats_details( $stats, $processing_only = false, $attachment_id = 0 ) {

		$orig_items = isset( $stats['orig_items'] ) && is_array( $stats['orig_items'] ) ? $stats['orig_items'] : array();
		$opt_items  = ( ! $processing_only && isset( $stats['opt_items'] ) && is_array( $stats['opt_items'] ) ) ? $stats['opt_items'] : array();

		if( empty( $orig_items ) ) {
			return;
		}

		$layout = self::get_detail_table_layout( $attachment_id, $stats );
		if( empty( $layout['show_details'] ) ) {
			return;
		}

		echo '<div class="sb-iqc-ao-col__detail-table-wrap">';
		self::_open_detail_table( $layout );

		foreach( $orig_items as $size_name => $orig_item ) {
			if( '_original' === $size_name ) {
				continue;
			}

			self::_echo_detail_row_from_items( $layout, $size_name, $orig_item, $opt_items, $processing_only );
		}

		if( ! empty( $orig_items['_original'] ) && is_array( $orig_items['_original'] ) ) {
			self::_echo_original_detail_row( $layout, $orig_items['_original'], $opt_items, $processing_only );
		}

		if( ! $processing_only ) {
			echo '</tbody>';
			self::_render_stats_table_footer( $stats, $layout, $opt_items );
		} else {
			echo '</tbody>';
		}

		echo '</table>';
		echo '</div>';

	}


	/**
	 * サブサイズ 1 行分を出力する
	 *
	 * @param array  $layout
	 * @param string $size_name
	 * @param array  $orig_item
	 * @param array  $opt_items
	 * @param bool   $processing_only
	 */
	private static function _echo_detail_row_from_items( $layout, $size_name, $orig_item, $opt_items, $processing_only ) {

		$orig_main = (int) ( $orig_item['main'] ?? 0 );
		$orig_webp = (int) ( $orig_item['webp'] ?? 0 );
		$orig_avif = (int) ( $orig_item['avif'] ?? 0 );

		if( 1 > $orig_main && 1 > $orig_webp && 1 > $orig_avif ) {
			return;
		}

		$opt_item = isset( $opt_items[ $size_name ] ) && is_array( $opt_items[ $size_name ] ) ? $opt_items[ $size_name ] : array();
		$opt_main = (int) ( $opt_item['main'] ?? 0 );
		$opt_webp = (int) ( $opt_item['webp'] ?? 0 );
		$opt_avif = (int) ( $opt_item['avif'] ?? 0 );

		if( $processing_only ) {
			$opt_webp = $orig_webp;
			$opt_avif = $orig_avif;
		}

		if( ! empty( $layout['is_inplace_source'] ) ) {
			self::_echo_detail_table_row(
				$layout,
				self::format_size_label( $size_name ),
				$orig_main,
				$processing_only ? null : ( $opt_main ?: null )
			);
			return;
		}

		self::_echo_detail_table_row(
			$layout,
			self::format_size_label( $size_name ),
			$orig_main,
			$processing_only ? null : ( $opt_main ?: null ),
			( $processing_only || 1 > $opt_webp ) ? null : $opt_webp,
			( $processing_only || 1 > $opt_avif ) ? null : $opt_avif
		);

	}


	/**
	 * 元画像（フルサイズ）行を出力する
	 *
	 * @param array $layout
	 * @param array $orig_item
	 * @param array $opt_items
	 * @param bool  $processing_only
	 */
	private static function _echo_original_detail_row( $layout, $orig_item, $opt_items, $processing_only ) {

		$orig_main = (int) ( $orig_item['main'] ?? 0 );
		$orig_webp = (int) ( $orig_item['webp'] ?? 0 );
		$orig_avif = (int) ( $orig_item['avif'] ?? 0 );

		if( 1 > $orig_main && 1 > $orig_webp && 1 > $orig_avif ) {
			return;
		}

		$opt_item = isset( $opt_items['_original'] ) && is_array( $opt_items['_original'] ) ? $opt_items['_original'] : array();
		$opt_webp = (int) ( $opt_item['webp'] ?? 0 );
		$opt_avif = (int) ( $opt_item['avif'] ?? 0 );

		if( ! empty( $layout['is_inplace_source'] ) ) {
			// 元画像は保持するため、変更なしとして表示する
			self::_echo_detail_table_row(
				$layout,
				self::format_size_label( '_original' ),
				$orig_main,
				null,
				null,
				null,
				true
			);
			return;
		}

		// 元形式は再最適化しない
		self::_echo_detail_table_row(
			$layout,
			self::format_size_label( '_original' ),
			$orig_main,
			null,
			( $processing_only || 1 > $opt_webp ) ? null : $opt_webp,
			( $processing_only || 1 > $opt_avif ) ? null : $opt_avif,
			true
		);

	}


	/**
	 * 詳細テーブルのフッター（合計行）
	 *
	 * @param array $stats
	 * @param array $layout
	 * @param array $opt_items
	 */
	private static function _render_stats_table_footer( $stats, $layout, $opt_items = array() ) {

		$orig_main  = (int) ( $stats['orig_main_bytes'] ?? 0 );
		$orig_full  = (int) ( $stats['orig_full_bytes'] ?? 0 );
		$orig_webp  = (int) ( $stats['orig_webp_bytes'] ?? 0 );
		$orig_avif  = (int) ( $stats['orig_avif_bytes'] ?? 0 );
		$opt_main   = (int) ( $stats['opt_main_bytes'] ?? 0 );
		$orig_items = isset( $stats['orig_items'] ) && is_array( $stats['orig_items'] ) ? $stats['orig_items'] : array();

		if( ! empty( $layout['is_inplace_source'] ) ) {
			if( 1 > $orig_main && 1 > $orig_full ) {
				return;
			}
			echo '<tfoot><tr>';
			echo '<th scope="row" class="sb-iqc-ao-col__detail-size">';
			echo esc_html__( 'Total', 'still-be-image-quality-control' );
			echo '</th>';
			self::_echo_before_size_cell( $orig_main, $orig_full );
			// Original は保持するため、比率はサブサイズのみで算出する
			self::_echo_after_size_cell( $orig_main, $opt_main );
			echo '</tr></tfoot>';
			return;
		}

		$orig_not_opt = $orig_main + $orig_full;

		if( 1 > $orig_not_opt && 1 > $orig_webp && 1 > $orig_avif ) {
			return;
		}

		echo '<tfoot><tr>';
		echo '<th scope="row" class="sb-iqc-ao-col__detail-size">';
		echo esc_html__( 'Total', 'still-be-image-quality-control' );
		echo '</th>';
		self::_echo_before_size_cell( $orig_main, $orig_full );
		// 元画像（Original）は再最適化しないため、比率はサブサイズのみで算出する
		self::_echo_after_size_cell( $orig_main, $opt_main );
		// 代替配信の合計は「生成されたサイズの元形式合計」と比較する
		if( ! empty( $layout['has_webp_delivery'] ) ) {
			$cmp = self::sum_generated_alt_totals( $orig_items, $opt_items, 'webp' );
			self::_echo_after_size_cell( $cmp['before'], ( 1 > $cmp['after'] ) ? null : $cmp['after'] );
		}
		if( ! empty( $layout['has_avif_delivery'] ) ) {
			$cmp = self::sum_generated_alt_totals( $orig_items, $opt_items, 'avif' );
			self::_echo_after_size_cell( $cmp['before'], ( 1 > $cmp['after'] ) ? null : $cmp['after'] );
		}
		echo '</tr></tfoot>';

	}


	/**
	 * 詳細テーブルの開始
	 *
	 * @param array $layout
	 */
	private static function _open_detail_table( $layout ) {

		$col_count = absint( $layout['col_count'] ?? 4 );

		echo '<table class="sb-iqc-ao-col__detail-table sb-iqc-ao-col__detail-table--cols-' . esc_attr( $col_count ) . '">';
		echo '<thead><tr>';
		echo '<th class="sb-iqc-ao-col__detail-size">'. esc_html__( 'Size', 'still-be-image-quality-control' ). '</th>';
		echo '<th class="sb-iqc-ao-col__detail-num">'. esc_html__( 'Not Optimized', 'still-be-image-quality-control' ). '</th>';

		echo '<th class="sb-iqc-ao-col__detail-num">'. esc_html( $layout['format_label'] ). '</th>';
		if( empty( $layout['is_inplace_source'] ) ) {
			if( ! empty( $layout['has_webp_delivery'] ) ) {
				echo '<th class="sb-iqc-ao-col__detail-num">'. esc_html__( 'WebP', 'still-be-image-quality-control' ). '</th>';
			}
			if( ! empty( $layout['has_avif_delivery'] ) ) {
				echo '<th class="sb-iqc-ao-col__detail-num">'. esc_html__( 'AVIF', 'still-be-image-quality-control' ). '</th>';
			}
		}

		echo '</tr></thead><tbody>';

	}


	/**
	 * 詳細テーブルの 1 行
	 *
	 * @param array    $layout
	 * @param string   $label
	 * @param int      $not_optimized
	 * @param int|null $format_after   元形式または WebP / AVIF 元画像の最適化後
	 * @param int|null $webp_after     代替配信 WebP の最適化後
	 * @param int|null $avif_after     代替配信 AVIF の最適化後
	 * @param bool     $format_unchanged 元形式は変更なし
	 */
	private static function _echo_detail_table_row( $layout, $label, $not_optimized, $format_after, $webp_after = null, $avif_after = null, $format_unchanged = false ) {

		echo '<tr>';
		echo '<th scope="row" class="sb-iqc-ao-col__detail-size">';
		echo esc_html( $label );
		echo '</th>';
		self::_echo_before_size_cell( $not_optimized );

		if( $format_unchanged ) {
			self::_echo_unchanged_format_cell();
		} else {
			self::_echo_after_size_cell( $not_optimized, $format_after );
		}

		if( empty( $layout['is_inplace_source'] ) ) {
			// 代替 WebP / AVIF は常に「この行の元形式」との比較 (以前の同形式同士ではない)
			if( ! empty( $layout['has_webp_delivery'] ) ) {
				self::_echo_after_size_cell( $not_optimized, $webp_after );
			}
			if( ! empty( $layout['has_avif_delivery'] ) ) {
				self::_echo_after_size_cell( $not_optimized, $avif_after );
			}
		}

		echo '</tr>';

	}


	/**
	 * 最適化前サイズセル
	 *
	 * @param int $bytes           サブサイズ合計、または行のサイズ
	 * @param int $original_bytes  合計行のみ: Original を括弧付きで併記する場合に渡す
	 */
	private static function _echo_before_size_cell( $bytes, $original_bytes = 0 ) {

		echo '<td class="sb-iqc-ao-col__detail-num">';
		if( 0 < $original_bytes ) {
			$label = self::format_before_size_with_original( $bytes, $original_bytes );
			if( '' === $label ) {
				echo '—';
			} else {
				echo esc_html( $label );
			}
		} elseif( 1 > $bytes ) {
			echo '—';
		} else {
			echo esc_html( self::format_size_display( $bytes ) );
		}
		echo '</td>';

	}


	/**
	 * 元形式は変更なしセル
	 */
	private static function _echo_unchanged_format_cell() {

		echo '<td class="sb-iqc-ao-col__detail-num sb-iqc-ao-col__detail-after sb-iqc-ao-col__detail-after--unchanged">';
		echo '—';
		echo ' <span class="sb-iqc-ao-col__detail-unchanged-note">';
		echo esc_html__( '(unchanged)', 'still-be-image-quality-control' );
		echo '</span>';
		echo '</td>';

	}


	/**
	 * 最適化後サイズセル（下に変化率）
	 *
	 * @param int      $before
	 * @param int|null $after
	 */
	private static function _echo_after_size_cell( $before, $after ) {

		echo '<td class="sb-iqc-ao-col__detail-num sb-iqc-ao-col__detail-after">';

		if( null === $after || 1 > $after ) {
			echo '—';
		} else {
			echo '<span class="sb-iqc-ao-col__detail-size-value">';
			echo esc_html( self::format_size_display( $after ) );
			echo '</span>';
			self::echo_percent_change_markup( $before, $after );
		}

		echo '</td>';

	}

	/**
	 * 詳細の注記行
	 *
	 * @param string $text
	 */
	private static function _echo_detail_note( $text ) {

		echo '<div class="sb-iqc-ao-col__detail-note">';
		echo esc_html( $text );
		echo '</div>';

	}

	/**
	 * サイズ名の表示ラベル
	 *
	 * @param string $size_name
	 * @return string
	 */
	public static function format_size_label( $size_name ) {

		if( '_original' === $size_name ) {
			return __( 'Original', 'still-be-image-quality-control' );
		}

		return (string) $size_name;

	}

	/**
	 * 削減/増加の要約文字列を返す
	 *
	 * @param int $orig_bytes
	 * @param int $opt_bytes
	 * @return string
	 */
	public static function format_change_summary( $orig_bytes, $opt_bytes ) {

		$orig_bytes = (int) $orig_bytes;
		$opt_bytes  = (int) $opt_bytes;

		if( 1 > $orig_bytes ) {
			return '';
		}

		$diff = $orig_bytes - $opt_bytes;
		$pct  = abs( $diff / $orig_bytes * 100 );
		$pct  = number_format_i18n( $pct, 1 );

		if( 0 === $diff ) {
			return esc_html__( 'No change', 'still-be-image-quality-control' );
		}

		if( 0 < $diff ) {
			return sprintf(
				/* translators: 1: percent, 2: saved size */
				esc_html__( '%1$s%% reduced (%2$s saved)', 'still-be-image-quality-control' ),
				$pct,
				self::format_size_display( $diff )
			);
		}

		return sprintf(
			/* translators: 1: percent, 2: increased size */
			esc_html__( '%1$s%% increased (%2$s larger)', 'still-be-image-quality-control' ),
			$pct,
			self::format_size_display( abs( $diff ) )
		);

	}

	/**
	 * 進捗表示用の短い文字列を返す
	 *
	 * @param array $meta
	 * @return string
	 */
	public static function get_status_label( $meta ) {

		$progress = self::get_progress( $meta );
		if( empty( $progress ) ) {
			return '';
		}

		$attempt = absint( $progress['attempt'] ?? 0 );
		$is_done = ! empty( $progress['completed'] );

		$stats = self::get_stats( $meta );

		if( $is_done ) {
			if( isset( $stats['orig_main_bytes'] ) && is_numeric( $stats['orig_main_bytes'] ) ) {
				$summary = self::format_change_summary(
					(int) $stats['orig_main_bytes'],
					(int) ( $stats['opt_main_bytes'] ?? 0 )
				);
				if( $summary ) {
					return sprintf(
						/* translators: %s: change summary for original format */
						esc_html__( 'Optimized (original format): %s', 'still-be-image-quality-control' ),
						$summary
					);
				}
			}
			$orig = isset( $stats['orig_bytes'] ) ? (int) $stats['orig_bytes'] : 0;
			$opt  = isset( $stats['opt_bytes'] ) ? (int) $stats['opt_bytes'] : 0;
			if( $orig > 0 && $opt >= 0 ) {
				$summary = self::format_change_summary( $orig, $opt );
				if( $summary ) {
					return sprintf(
						/* translators: %s: change summary */
						esc_html__( 'Optimized: %s', 'still-be-image-quality-control' ),
						$summary
					);
				}
			}
			return esc_html__( 'Optimized', 'still-be-image-quality-control' );
		}

		// 例: 処理中… (2回目)
		return sprintf(
			/* translators: %d: attempt count */
			esc_html__( 'Processing... (attempt %d)', 'still-be-image-quality-control' ),
			max( 1, $attempt )
		);

	}

	/**
	 * 統計用のサイズ内訳を計算する
	 *
	 * main  : サブサイズの元形式ファイル合計
	 * full  : 元画像（フルサイズ）の元形式ファイル
	 * webp  : 全 WebP ファイル合計
	 * items : サイズ別の main / webp [bytes]
	 *
	 * @param int   $attachment_id
	 * @param array $meta
	 * @return array{main:int,full:int,webp:int,items:array}
	 */
	public static function calc_size_breakdown( $attachment_id, $meta ) {

		$attachment_id = absint( $attachment_id );

		if( empty( $attachment_id ) || empty( $meta['file'] ) ) {
			return array(
				'main'  => 0,
				'full'  => 0,
				'webp'  => 0,
				'avif'  => 0,
				'items' => array(),
			);
		}

		$upload_dir    = wp_upload_dir();
		$original_file = path_join( $upload_dir['basedir'], $meta['file'] );
		$base_dir      = dirname( $original_file );

		$main_total  = 0;
		$webp_total  = 0;
		$avif_total  = 0;
		$items       = array();
		$counted     = array();

		$sizes = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
		foreach( $sizes as $size_name => $size_data ) {

			if( empty( $size_data['file'] ) ) {
				continue;
			}

			$main_path = path_join( $base_dir, $size_data['file'] );
			$main_size = 0;
			if( file_exists( $main_path ) ) {
				$_key = 'f:' . $main_path;
				if( empty( $counted[ $_key ] ) ) {
					$main_size = (int) @filesize( $main_path );
					$main_total += $main_size;
					$counted[ $_key ] = true;
				}
			}

			$webp_size = self::_filesize_webp_for_size( $base_dir, $size_data, $main_path, $counted );
			$webp_total += $webp_size;

			$avif_size = self::_filesize_avif_for_size( $base_dir, $size_data, $main_path, $counted );
			$avif_total += $avif_size;

			$items[ $size_name ] = array(
				'main' => $main_size,
				'webp' => $webp_size,
				'avif' => $avif_size,
			);

		}

		$full_size = 0;
		if( file_exists( $original_file ) ) {
			$_key = 'f:' . $original_file;
			if( empty( $counted[ $_key ] ) ) {
				$full_size = (int) @filesize( $original_file );
				$counted[ $_key ] = true;
			}
		}

		$orig_webp_size = 0;
		$orig_webp      = stillbe_iqc_get_delivery_webp_path( $original_file );
		if( $orig_webp && file_exists( $orig_webp ) ) {
			$_key = 'f:' . $orig_webp;
			if( empty( $counted[ $_key ] ) ) {
				$orig_webp_size = (int) @filesize( $orig_webp );
				$webp_total    += $orig_webp_size;
				$counted[ $_key ] = true;
			}
		}

		$orig_avif_size = 0;
		$orig_avif      = stillbe_iqc_get_delivery_avif_path( $original_file );
		if( $orig_avif && file_exists( $orig_avif ) ) {
			$_key = 'f:' . $orig_avif;
			if( empty( $counted[ $_key ] ) ) {
				$orig_avif_size = (int) @filesize( $orig_avif );
				$avif_total    += $orig_avif_size;
				$counted[ $_key ] = true;
			}
		}

		$items['_original'] = array(
			'main' => $full_size,
			'webp' => $orig_webp_size,
			'avif' => $orig_avif_size,
		);

		return array(
			'main'  => $main_total,
			'full'  => $full_size,
			'webp'  => $webp_total,
			'avif'  => $avif_total,
			'items' => $items,
		);

	}

	/**
	 * サブサイズの WebP ファイルサイズを取得する
	 *
	 * @param string $base_dir
	 * @param array  $size_data
	 * @param string $main_path
	 * @param array  $counted
	 * @return int
	 */
	private static function _filesize_webp_for_size( $base_dir, $size_data, $main_path, &$counted ) {

		$webp_path = stillbe_iqc_resolve_delivery_webp_path( $base_dir, $size_data, $main_path );

		if( ! $webp_path || ! file_exists( $webp_path ) ) {
			return 0;
		}

		$_key = 'f:' . $webp_path;
		if( ! empty( $counted[ $_key ] ) ) {
			return 0;
		}

		$counted[ $_key ] = true;
		return (int) @filesize( $webp_path );

	}

	/**
	 * サブサイズの AVIF ファイルサイズを取得する
	 *
	 * @param string $base_dir
	 * @param array  $size_data
	 * @param string $main_path
	 * @param array  $counted
	 * @return int
	 */
	private static function _filesize_avif_for_size( $base_dir, $size_data, $main_path, &$counted ) {

		$avif_path = stillbe_iqc_resolve_delivery_avif_path( $base_dir, $size_data, $main_path );

		if( ! $avif_path || ! file_exists( $avif_path ) ) {
			return 0;
		}

		$_key = 'f:' . $avif_path;
		if( ! empty( $counted[ $_key ] ) ) {
			return 0;
		}

		$counted[ $_key ] = true;
		return (int) @filesize( $avif_path );

	}

	/**
	 * 統計用の合計サイズ（bytes）を計算する
	 *
	 * @param int   $attachment_id
	 * @param array $meta
	 * @return int
	 */
	public static function calc_total_bytes( $attachment_id, $meta ) {

		$breakdown = self::calc_size_breakdown( $attachment_id, $meta );
		return (int) $breakdown['main'] + (int) $breakdown['full'] + (int) $breakdown['webp'];

	}

	/**
	 * 最適化状況列の空表示
	 *
	 * @param string $label
	 */
	private static function _echo_column_empty( $label = '—' ) {

		echo '<span class="sb-iqc-ao-col sb-iqc-ao-col--none">';
		echo esc_html( $label );
		echo '</span>';

	}

}


// END of the File

