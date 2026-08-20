<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SSIM による圧縮品質の自動最適化
 *
 * - 進捗の保存や統計集計は本クラスの責務に含めない（Auto_Optimize_Progress が担当）
 * - 品質テーブル値は上限（天井）として扱い、目標 SSIM を満たす最小品質を二分探索する
 * - SSIM 採用品質がトリガー未満のとき、Edge Residual Mean (ERE) で品質を引き上げるセカンドオピニオンを行う
 *
 * @since 2.0.0
 */
class Auto_Optimize {

	// 品質の上書き値（make_webp 用のフィルターで使用）
	private $image_quality = 0;

	// この実行で作成した候補一時ファイル
	private $candidate_temp_files = array();

	/**
	 * 添付ファイル 1 件の最適化を実行する
	 *
	 * @param array $ctx コンテキスト
	 * @return array {
	 *   @type array  $meta         更新後のメタデータ
	 *   @type array  $done         処理済み size_name の配列
	 *   @type array  $largest_webp array( 'width' => int, 'quality' => int )
	 *   @type bool   $suspended    時間切れで中断したか
	 * }
	 */
	public function run( $ctx ) {

		$attachment_id = absint( $ctx['attachment_id'] ?? 0 );
		$metadata      = $ctx['metadata'] ?? array();
		$settings      = $ctx['settings'] ?? array();
		$mime_type     = (string) ( $ctx['mime_type'] ?? '' );

		$done          = ( isset( $ctx['done'] ) && is_array( $ctx['done'] ) ) ? $ctx['done'] : array();
		$largest_webp  = ( isset( $ctx['largest_webp'] ) && is_array( $ctx['largest_webp'] ) ) ? $ctx['largest_webp'] : array( 'width' => 0, 'quality' => 0 );
		$largest_avif  = ( isset( $ctx['largest_avif'] ) && is_array( $ctx['largest_avif'] ) ) ? $ctx['largest_avif'] : array( 'width' => 0, 'quality' => 0 );
		$attempt       = absint( $ctx['attempt'] ?? 0 ); // 現状未使用（将来のログ等用）

		if( empty( $attachment_id ) || empty( $metadata['file'] ) ) {
			return array(
				'meta'         => $metadata,
				'done'         => $done,
				'largest_webp' => $largest_webp,
				'largest_avif' => $largest_avif,
				'suspended'    => true,
				'target_level' => isset( $settings['auto-optimize-target'] ) ? $settings['auto-optimize-target'] : 'balance',
				'attempt'      => $attempt,
			);
		}

		$upload_dir    = wp_upload_dir();
		$original_file = path_join( $upload_dir['basedir'], $metadata['file'] );
		$base_dir      = dirname( $original_file );

		if( ! file_exists( $original_file ) ) {
			return array(
				'meta'         => $metadata,
				'done'         => $done,
				'largest_webp' => $largest_webp,
				'largest_avif' => $largest_avif,
				'suspended'    => true,
				'target_level' => isset( $settings['auto-optimize-target'] ) ? $settings['auto-optimize-target'] : 'balance',
				'attempt'      => $attempt,
			);
		}

		if( ! function_exists( 'wp_tempnam' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		wp_raise_memory_limit( 'image' );

		// 目標 SSIM
		$target_level = isset( $settings['auto-optimize-target'] ) ? $settings['auto-optimize-target'] : 'balance';
		$targets      = $this->_get_ssim_targets( $target_level );

		$mark_done = function( $size_name ) use ( &$done, &$new_meta, $attachment_id, $attempt, $target_level, &$largest_webp, &$largest_avif ) {
			$done[] = $size_name;
			Auto_Optimize_Progress::persist_progress( $attachment_id, $done, $attempt, $target_level, $largest_webp, $new_meta, $size_name, $largest_avif );
		};

		// WebP 生成が有効か
		$is_enabled_webp = (bool) apply_filters( 'stillbe_image_quality_control_enable_webp', STILLBE_IQ_ENABLE_WEBP, 'generate' );
		$is_enabled_avif = (bool) apply_filters( 'stillbe_image_quality_control_enable_avif', STILLBE_IQ_ENABLE_AVIF, 'generate' );

		// cwebp (拡張プラグイン) が有効か
		$is_cwebp = apply_filters( 'stillbe_image_quality_control_enable_cwebp_lib', STILLBE_IQ_ENABLE_CWEBP_LIBRARY )
		              && stillbe_iqc_is_enabled_cwebp();

		$is_webp_source = ( 'image/webp' === $mime_type );
		$is_avif_source = ( 'image/avif' === $mime_type );

		$this->_ao_debug_log( sprintf(
			'webp flags: enable_webp=%d, cwebp=%d, webp_source=%d, enable_avif=%d, avif_source=%d',
			$is_enabled_webp ? 1 : 0,
			$is_cwebp ? 1 : 0,
			$is_webp_source ? 1 : 0,
			$is_enabled_avif ? 1 : 0,
			$is_avif_source ? 1 : 0
		) );

		// 実行時間の予算 (max_execution_time の半分)
		$max_execution_time = absint( ini_get( 'max_execution_time' ) );
		if( 1 > $max_execution_time ) {
			$max_execution_time = 30; // DEFAULT
		}
		$time_budget = $max_execution_time / 2;
		$start_time  = time();

		// 登録済みサイズ (crop フラグの取得用)
		$registered_sizes = wp_get_registered_image_subsizes();

		// WebP 生成用エディタ (ラスター画像の代替配信 WebP 用)
		$webp_editor = null;
		if( $is_enabled_webp && ! $is_webp_source ) {
			$webp_editor = wp_get_image_editor( $original_file );
			if( is_wp_error( $webp_editor ) ) {
				$this->_ao_debug_log( sprintf(
					'webp_editor unavailable: %s',
					$webp_editor->get_error_message()
				) );
				$webp_editor = null;
			} else {
				$loaded = $webp_editor->load();
				if( is_wp_error( $loaded ) ) {
					$this->_ao_debug_log( sprintf(
						'webp_editor load failed: %s',
						$loaded->get_error_message()
					) );
					$webp_editor = null;
				}
			}
		} elseif( $is_enabled_webp && $is_webp_source ) {
			$this->_ao_debug_log( 'webp_editor skipped: source is already WebP (in-place optimize path)' );
		} elseif( ! $is_enabled_webp ) {
			$this->_ao_debug_log( 'webp_editor skipped: "Enable Automatically Generating WebP" is off' );
		}

		// AVIF 生成用エディタ (ラスター画像の代替配信 AVIF 用)
		$avif_editor = null;
		if( $is_enabled_avif && ! $is_avif_source ) {
			$avif_editor = $this->_make_avif_capable_editor( $original_file );
			if( is_wp_error( $avif_editor ) ) {
				$this->_ao_debug_log( sprintf(
					'avif_editor unavailable: %s',
					$avif_editor->get_error_message()
				) );
				$avif_editor = null;
			}
		} elseif( $is_enabled_avif && $is_avif_source ) {
			$this->_ao_debug_log( 'avif_editor skipped: source is already AVIF (in-place optimize path)' );
		} elseif( ! $is_enabled_avif ) {
			$this->_ao_debug_log( 'avif_editor skipped: "Enable Automatically Generating AVIF" is off' );
		}

		// 同一サイズへのリサイズをエラーにしない
		add_filter( 'wp_image_resize_identical_dimensions', '__return_true' );

		$new_meta     = $metadata;
		$sizes        = empty( $metadata['sizes'] ) ? array() : $metadata['sizes'];
		$is_suspended = false;

		foreach( $sizes as $size_name => $size_data ) {

			if( in_array( $size_name, $done, true ) ) {
				continue;
			}

			// 時間切れなら中断して次回に回す
			if( $time_budget <= ( time() - $start_time ) ) {
				$is_suspended = true;
				break;
			}

			$size_file = path_join( $base_dir, $size_data['file'] );
			$size_mime = isset( $size_data['mime-type'] ) ? $size_data['mime-type'] : $mime_type;

			// ファイルが無い・ラスター添付の WebP / AVIF サブサイズはスキップ
			if( ! file_exists( $size_file ) ||
			      ( 'image/webp' === $size_mime && ! $is_webp_source ) ||
			      ( 'image/avif' === $size_mime && ! $is_avif_source ) ) {
				$mark_done( $size_name );
				continue;
			}

			$reg  = isset( $registered_sizes[ $size_name ] ) ? $registered_sizes[ $size_name ] : null;
			$crop = $this->_resolve_crop( (int) $metadata['width'], (int) $metadata['height'], (int) $size_data['width'], (int) $size_data['height'], $reg );

			$opt = $this->_optimize_size( array(
				'attachment_id'   => $attachment_id,
				'metadata'        => $new_meta,
				'original_file'   => $original_file,
				'base_dir'        => $base_dir,
				'size_name'       => $size_name,
				'size_data'       => $size_data,
				'size_file'       => $size_file,
				'size_mime'       => $size_mime,
				'crop'            => $crop,
				'targets'         => $targets,
				'target_level'    => $target_level,
				'is_enabled_webp' => $is_enabled_webp,
				'is_enabled_avif' => $is_enabled_avif,
				'is_cwebp'        => $is_cwebp,
				'webp_editor'     => $webp_editor,
				'avif_editor'     => $avif_editor,
				'source_mime'     => $mime_type,
				'original_size'   => array( 'width' => (int) $metadata['width'], 'height' => (int) $metadata['height'] ),
			) );

			if( ! is_wp_error( $opt ) && ( ! empty( $opt['sb-iqc'] ) || ! empty( $opt['purge_delivery_webp'] ) || ! empty( $opt['purge_delivery_avif'] ) ) ) {
				$_current_sb_iqc = isset( $new_meta['sizes'][ $size_name ]['sb-iqc'] ) && is_array( $new_meta['sizes'][ $size_name ]['sb-iqc'] ) ?
				                     $new_meta['sizes'][ $size_name ]['sb-iqc'] :
				                     array();
				$new_meta['sizes'][ $size_name ]['sb-iqc']  = array_merge( $_current_sb_iqc, $opt['sb-iqc'] ?? array() );
				if( ! empty( $opt['purge_delivery_webp'] ) ) {
					$new_meta['sizes'][ $size_name ]['sb-iqc'] = Auto_Optimize_Progress::unset_delivery_webp_sb_iqc_keys(
						$new_meta['sizes'][ $size_name ]['sb-iqc']
					);
				}
				if( ! empty( $opt['purge_delivery_avif'] ) ) {
					$new_meta['sizes'][ $size_name ]['sb-iqc'] = Auto_Optimize_Progress::unset_delivery_avif_sb_iqc_keys(
						$new_meta['sizes'][ $size_name ]['sb-iqc']
					);
				}
				if( ( ! empty( $opt['purge_delivery_webp'] ) || ! empty( $opt['purge_delivery_avif'] ) )
				      && empty( $new_meta['sizes'][ $size_name ]['sb-iqc'] ) ) {
					unset( $new_meta['sizes'][ $size_name ]['sb-iqc'] );
				}
				$new_meta['sizes'][ $size_name ]['updated'] = time();
				if( ! empty( $opt['jpeg_resaved'] ) && isset( $new_meta['sizes'][ $size_name ]['filesize'] ) ) {
					$new_meta['sizes'][ $size_name ]['filesize'] = (int) @filesize( $size_file );
				}
				if( $is_webp_source && ! empty( $opt['webp_resaved'] ) && isset( $new_meta['sizes'][ $size_name ]['filesize'] ) ) {
					$new_meta['sizes'][ $size_name ]['filesize'] = (int) @filesize( $size_file );
				}
				if( $is_avif_source && ! empty( $opt['avif_resaved'] ) && isset( $new_meta['sizes'][ $size_name ]['filesize'] ) ) {
					$new_meta['sizes'][ $size_name ]['filesize'] = (int) @filesize( $size_file );
				}
			// 元画像 WebP に流用するため、最大サブサイズの WebP 品質を保持する
			if( ! empty( $opt['webp_quality'] ) && (int) $size_data['width'] > (int) ( $largest_webp['width'] ?? 0 ) ) {
				$largest_webp = array_merge( $largest_webp, array(
					'width'   => (int) $size_data['width'],
					'quality' => (int) $opt['webp_quality'],
				) );
			}
			if( ! empty( $opt['avif_quality'] ) && (int) $size_data['width'] > (int) ( $largest_avif['width'] ?? 0 ) ) {
				$largest_avif = array_merge( $largest_avif, array(
					'width'   => (int) $size_data['width'],
					'quality' => (int) $opt['avif_quality'],
				) );
			}
			// 上限張り付きの破棄が発生したことを記録する (中断・再開をまたいで保持するため largest 配列に載せる)
			// purged_width は破棄されたサイズの最大幅 (最大サイズが破棄されたかの判定に使う)
			if( ! empty( $opt['purge_delivery_webp'] ) ) {
				$largest_webp['purged']       = true;
				$largest_webp['purged_width'] = max( (int) ( $largest_webp['purged_width'] ?? 0 ), (int) $size_data['width'] );
			}
			if( ! empty( $opt['purge_delivery_avif'] ) ) {
				$largest_avif['purged']       = true;
				$largest_avif['purged_width'] = max( (int) ( $largest_avif['purged_width'] ?? 0 ), (int) $size_data['width'] );
			}
			}

			// 成否に関わらず処理済みとする (失敗サイズの無限リトライを防止)
			// 決定した品質は new_meta 反映後に DB へ書き、中断・表示ずれを防ぐ
			$mark_done( $size_name );

		}

		// 元画像の代替配信 WebP / AVIF
		if( ! $is_suspended && ! $is_webp_source && ! $is_avif_source && ! in_array( 'original', $done, true ) ) {

			$_root_sb_iqc = isset( $new_meta['sb-iqc'] ) && is_array( $new_meta['sb-iqc'] ) ? $new_meta['sb-iqc'] : array();

			// サブサイズが全て上限張り付きで破棄された場合、元画像の代替配信も生成せず破棄する
			$purge_orig_webp = ! empty( $largest_webp['purged'] ) && empty( $largest_webp['quality'] );
			$purge_orig_avif = ! empty( $largest_avif['purged'] ) && empty( $largest_avif['quality'] );

			// 最大サイズが上限張り付きで破棄された場合、より小さいサイズの品質との差が大きくなる懸念があるため、
			// 元画像には生成できたサイズの品質を流用せずテーブル設定値をそのまま使う
			$ignore_largest_webp_q = ! empty( $largest_webp['purged'] )
				&& (int) ( $largest_webp['purged_width'] ?? 0 ) >= (int) ( $largest_webp['width'] ?? 0 );
			$ignore_largest_avif_q = ! empty( $largest_avif['purged'] )
				&& (int) ( $largest_avif['purged_width'] ?? 0 ) >= (int) ( $largest_avif['width'] ?? 0 );

			if( $is_enabled_webp && $webp_editor && $purge_orig_webp ) {
				$this->_ao_debug_log( '[original/webp] skip delivery: all sub-sizes were ceiling-stuck; purge file and meta' );
				$orig_webp_file = stillbe_iqc_resolve_delivery_webp_path( $base_dir, array( 'sb-iqc' => $_root_sb_iqc ), $original_file );
				if( $orig_webp_file && file_exists( $orig_webp_file ) ) {
					@unlink( $orig_webp_file );
				}
				$_root_sb_iqc = Auto_Optimize_Progress::unset_delivery_webp_sb_iqc_keys( $_root_sb_iqc );
			}
			if( $is_enabled_avif && $avif_editor && $purge_orig_avif ) {
				$this->_ao_debug_log( '[original/avif] skip delivery: all sub-sizes were ceiling-stuck; purge file and meta' );
				$orig_avif_file = stillbe_iqc_resolve_delivery_avif_path( $base_dir, array( 'sb-iqc' => $_root_sb_iqc ), $original_file );
				if( $orig_avif_file && file_exists( $orig_avif_file ) ) {
					@unlink( $orig_avif_file );
				}
				$_root_sb_iqc = Auto_Optimize_Progress::unset_delivery_avif_sb_iqc_keys( $_root_sb_iqc );
			}

			if( $is_enabled_webp && $webp_editor && ! $purge_orig_webp ) {
				$orig_webp_path = stillbe_iqc_get_filtered_delivery_webp_path( $original_file );
				if( $orig_webp_path ) {
					$orig_ceiling = $webp_editor->get_quality_from_size( 'original', 'image/webp' );
					$orig_quality = ( empty( $largest_webp['quality'] ) || $ignore_largest_webp_q ) ?
					                  $orig_ceiling :
					                  min( $orig_ceiling, (int) $largest_webp['quality'] );

					$this->_ao_debug_log( sprintf(
						'[original/webp] no SSIM search; encode with q=%d (ceiling=%d, largest_size_q=%d%s)',
						(int) $orig_quality,
						(int) $orig_ceiling,
						(int) ( $largest_webp['quality'] ?? 0 ),
						$ignore_largest_webp_q ? ', ignored: largest sub-size was ceiling-stuck' : ''
					) );

					$this->image_quality = $orig_quality;
					add_filter( 'stillbe_image_quality_original_webp', [ $this, 'quality_based_on_ssim' ], 99999 );
					add_filter( 'wp_editor_set_quality', [ $webp_editor, '_set_quality_hook' ], 1, 2 );

					$webp_data = null;
					try {
						$webp_editor->_set_mk_size( 'original' );
						$webp_data = $webp_editor->make_webp( $orig_webp_path, array(
							'size_name' => 'original',
							'width'     => (int) $metadata['width'],
							'height'    => (int) $metadata['height'],
							'file'      => basename( $metadata['file'] ),
						) );
					} finally {
						remove_filter( 'wp_editor_set_quality', [ $webp_editor, '_set_quality_hook' ], 1 );
						remove_filter( 'stillbe_image_quality_original_webp', [ $this, 'quality_based_on_ssim' ], 99999 );
						$this->image_quality = 0;
					}

					if( ! is_wp_error( $webp_data ) && ! empty( $webp_data['size'] ) ) {
						$_root_sb_iqc['webp-file']    = $webp_data['file'];
						$_root_sb_iqc['webp-quality'] = $webp_data['q'];
						if( isset( $webp_data['cwebp'] ) ) {
							$_root_sb_iqc['cwebp'] = $webp_data['cwebp'];
						}
						stillbe_iqc_merge_encode_method_meta( $_root_sb_iqc, $webp_data, 'webp' );
					}
				}
			}

			if( $is_enabled_avif && $avif_editor && ! $purge_orig_avif ) {
				$orig_avif_path = stillbe_iqc_get_filtered_delivery_avif_path( $original_file );
				if( $orig_avif_path ) {
					$orig_ceiling = $avif_editor->get_quality_from_size( 'original', 'image/avif' );
					$orig_quality = ( empty( $largest_avif['quality'] ) || $ignore_largest_avif_q ) ?
					                  $orig_ceiling :
					                  min( $orig_ceiling, (int) $largest_avif['quality'] );

					$this->_ao_debug_log( sprintf(
						'[original/avif] no SSIM search; encode with q=%d (ceiling=%d, largest_size_q=%d%s)',
						(int) $orig_quality,
						(int) $orig_ceiling,
						(int) ( $largest_avif['quality'] ?? 0 ),
						$ignore_largest_avif_q ? ', ignored: largest sub-size was ceiling-stuck' : ''
					) );

					$this->image_quality = $orig_quality;
					add_filter( 'stillbe_image_quality_original_avif', [ $this, 'quality_based_on_ssim' ], 99999 );
					add_filter( 'wp_editor_set_quality', [ $avif_editor, '_set_quality_hook' ], 1, 2 );

					$avif_data = null;
					try {
						$avif_editor->_set_mk_size( 'original' );
						$avif_data = $avif_editor->make_avif( $orig_avif_path, array(
							'size_name' => 'original',
							'width'     => (int) $metadata['width'],
							'height'    => (int) $metadata['height'],
							'file'      => basename( $metadata['file'] ),
						) );
					} finally {
						remove_filter( 'wp_editor_set_quality', [ $avif_editor, '_set_quality_hook' ], 1 );
						remove_filter( 'stillbe_image_quality_original_avif', [ $this, 'quality_based_on_ssim' ], 99999 );
						$this->image_quality = 0;
					}

					if( ! is_wp_error( $avif_data ) && ! empty( $avif_data['size'] ) ) {
						$_root_sb_iqc['avif-file']    = $avif_data['file'];
						$_root_sb_iqc['avif-quality'] = $avif_data['q'];
						stillbe_iqc_merge_encode_method_meta( $_root_sb_iqc, $avif_data, 'avif' );
					}
				}
			}

			if( ! empty( $_root_sb_iqc ) ) {
				$new_meta['sb-iqc'] = $_root_sb_iqc;
			} elseif( $purge_orig_webp || $purge_orig_avif ) {
				unset( $new_meta['sb-iqc'] );
			}

			if( ( $is_enabled_webp && $webp_editor ) || ( $is_enabled_avif && $avif_editor ) ) {
				$mark_done( 'original' );
			}

		} elseif( ! $is_suspended && $is_webp_source && ! in_array( 'original', $done, true ) ) {

			$reg  = null;
			$crop = false;
			$orig_opt = $this->_optimize_webp_source_size( array(
				'attachment_id'   => $attachment_id,
				'metadata'        => $new_meta,
				'original_file'   => $original_file,
				'base_dir'        => $base_dir,
				'size_name'       => 'original',
				'size_data'       => array(
					'width'  => (int) $metadata['width'],
					'height' => (int) $metadata['height'],
					'file'   => basename( $metadata['file'] ),
				),
				'size_file'       => $original_file,
				'size_mime'       => 'image/webp',
				'crop'            => $crop,
				'targets'         => $targets,
				'target_level'    => $target_level,
				'is_cwebp'        => $is_cwebp,
				'source_mime'     => $mime_type,
				'original_size'   => array( 'width' => (int) $metadata['width'], 'height' => (int) $metadata['height'] ),
				'quality_override'=> empty( $largest_webp['quality'] ) ? 0 : (int) $largest_webp['quality'],
			) );

			if( ! is_wp_error( $orig_opt ) && ! empty( $orig_opt['sb-iqc'] ) ) {
				$_root_sb_iqc = isset( $new_meta['sb-iqc'] ) && is_array( $new_meta['sb-iqc'] ) ? $new_meta['sb-iqc'] : array();
				$new_meta['sb-iqc'] = array_merge( $_root_sb_iqc, $orig_opt['sb-iqc'] );
			}

			$mark_done( 'original' );

		} elseif( ! $is_suspended && $is_avif_source && ! in_array( 'original', $done, true ) ) {

			$reg  = null;
			$crop = false;
			$orig_opt = $this->_optimize_avif_source_size( array(
				'attachment_id'   => $attachment_id,
				'metadata'        => $new_meta,
				'original_file'   => $original_file,
				'base_dir'        => $base_dir,
				'size_name'       => 'original',
				'size_data'       => array(
					'width'  => (int) $metadata['width'],
					'height' => (int) $metadata['height'],
					'file'   => basename( $metadata['file'] ),
				),
				'size_file'       => $original_file,
				'size_mime'       => 'image/avif',
				'crop'            => $crop,
				'targets'         => $targets,
				'target_level'    => $target_level,
				'source_mime'     => $mime_type,
				'original_size'   => array( 'width' => (int) $metadata['width'], 'height' => (int) $metadata['height'] ),
				'quality_override'=> empty( $largest_avif['quality'] ) ? 0 : (int) $largest_avif['quality'],
			) );

			if( ! is_wp_error( $orig_opt ) && ! empty( $orig_opt['sb-iqc'] ) ) {
				$_root_sb_iqc = isset( $new_meta['sb-iqc'] ) && is_array( $new_meta['sb-iqc'] ) ? $new_meta['sb-iqc'] : array();
				$new_meta['sb-iqc'] = array_merge( $_root_sb_iqc, $orig_opt['sb-iqc'] );
			}

			$mark_done( 'original' );

		}

		remove_filter( 'wp_image_resize_identical_dimensions', '__return_true' );

		// Clean up
		$webp_editor = null;
		unset( $webp_editor );
		$avif_editor = null;
		unset( $avif_editor );

		return array(
			'meta'         => $new_meta,
			'done'         => array_values( array_unique( $done ) ),
			'largest_webp' => $largest_webp,
			'largest_avif' => $largest_avif,
			'suspended'    => $is_suspended,
			'target_level' => $target_level,
			'attempt'      => $attempt,
		);

	}


	/**
	 * 1 サイズ分の最適化
	 *
	 * @param array $args コンテキスト
	 * @return array|\WP_Error array( 'sb-iqc' => array, 'webp_quality' => int, 'jpeg_resaved' => bool )
	 */
	private function _optimize_size( $args ) {

		$size_name      = $args['size_name'];
		$size_data      = $args['size_data'];
		$source_is_jpeg = ( 'image/jpeg' === $args['size_mime'] );
		$source_is_webp = ( 'image/webp' === $args['size_mime'] );
		$source_is_avif = ( 'image/avif' === $args['size_mime'] );

		if( $source_is_webp ) {
			return $this->_optimize_webp_source_size( $args );
		}

		if( $source_is_avif ) {
			return $this->_optimize_avif_source_size( $args );
		}

		$sb_iqc              = array();
		$webp_q_final        = 0;
		$avif_q_final        = 0;
		$jpeg_resaved        = false;
		$skip_delivery_webp  = false;
		$skip_delivery_avif  = false;

		// PNG / GIF は SSIM 探索の対象外 (PNG の圧縮レベルはロスレスのため)
		// WebP / AVIF のみテーブル品質のまま生成する
		if( ! $source_is_jpeg ) {
			if( $args['is_enabled_webp'] && $args['webp_editor'] ) {
				$webp_data = $this->_make_webp_for_size( $args['webp_editor'], $args, 0 );
				if( ! is_wp_error( $webp_data ) && ! empty( $webp_data['size'] ) ) {
					$sb_iqc['webp-file']    = $webp_data['file'];
					$sb_iqc['webp-quality'] = $webp_data['q'];
					if( isset( $webp_data['cwebp'] ) ) {
						$sb_iqc['cwebp'] = $webp_data['cwebp'];
					}
					stillbe_iqc_merge_encode_method_meta( $sb_iqc, $webp_data, 'webp' );
				}
			}
			if( $args['is_enabled_avif'] && $args['avif_editor'] ) {
				$avif_data = $this->_make_avif_for_size( $args['avif_editor'], $args, 0 );
				if( ! is_wp_error( $avif_data ) && ! empty( $avif_data['size'] ) ) {
					$sb_iqc['avif-file']    = $avif_data['file'];
					$sb_iqc['avif-quality'] = $avif_data['q'];
					stillbe_iqc_merge_encode_method_meta( $sb_iqc, $avif_data, 'avif' );
				}
			}
			return array( 'sb-iqc' => $sb_iqc, 'webp_quality' => 0, 'avif_quality' => 0, 'jpeg_resaved' => false, 'webp_resaved' => false, 'avif_resaved' => false );
		}

		// 参照エディタ (元画像からこのサイズへリサイズした非圧縮の画像)
		$ref_editor = $this->_make_reference_editor( $args['original_file'], (int) $size_data['width'], (int) $size_data['height'], $args['crop'] );
		if( is_wp_error( $ref_editor ) ) {
			$ref_editor = null;
		}

		// --- JPEG サブサイズの最適化 ---
		if( $ref_editor ) {

			$jpeg_ceiling = $ref_editor->get_quality_from_size( $size_name, 'image/jpeg' );
			$jpeg_floor   = $this->_get_quality_floor( $args, 'image/jpeg' );

			if( $jpeg_ceiling > $jpeg_floor ) {

				$measure = function( $q ) use ( $ref_editor, $args ) {
					return $this->_measure_candidate_ssim( $ref_editor, $q, 'image/jpeg', 'jpg', $args );
				};

				$found = $this->_search_optimal_quality(
					$measure,
					$jpeg_floor,
					$jpeg_ceiling,
					$this->_adjust_ssim_target_for_ceiling(
						$args['targets']['jpeg'],
						$jpeg_ceiling,
						STILLBE_IQ_SSIM_REF_JPEG_QUALITY,
						'jpeg',
						$size_name
					),
					"{$size_name}/jpeg/ssim"
				);

				if( ! is_wp_error( $found ) ) {
					$measure_mae = function( $q ) use ( $ref_editor, $args ) {
						return $this->_measure_candidate_mae( $ref_editor, $q, 'image/jpeg', 'jpg', $args );
					};
					$found = $this->_apply_mae_second_opinion(
						$found,
						$measure_mae,
						$jpeg_ceiling,
						"{$size_name}/jpeg",
						$args['target_level'] ?? 'balance',
						$measure
					);

					if( $found['quality'] < $jpeg_ceiling ) {
						// 確定品質でサブサイズを上書き保存する
						$ref_editor->set_quality( $found['quality'] );
						$saved = $ref_editor->save( $args['size_file'], 'image/jpeg' );
						if( ! is_wp_error( $saved ) ) {
							$sb_iqc['quality'] = $found['quality'];
							$sb_iqc['ssim']    = round( $found['ssim'], 4 );
							if( isset( $found['mae'] ) ) {
								$sb_iqc['mae'] = round( (float) $found['mae'], 6 );
							}
							$jpeg_resaved = true;
						}
					} else {
						// 天井と同値: 既存ファイルのまま SSIM の実測値だけ記録する
						$sb_iqc['quality'] = $found['quality'];
						$sb_iqc['ssim']    = round( $found['ssim'], 4 );
						if( isset( $found['mae'] ) ) {
							$sb_iqc['mae'] = round( (float) $found['mae'], 6 );
						}
					}
				}

			} else {
				// 天井が下限以下: 探索せず設定品質をメタに明示する
				$sb_iqc['quality'] = $jpeg_ceiling;
			}

		}

		// --- WebP の最適化 ---
		if( $args['is_enabled_webp'] && $args['webp_editor'] ) {

			/*
			 * JPEG の save() で参照エディタが汚れるため、WebP 探索の前に常に作り直す。
			 * （jpeg_resaved 時だけ作り直すと、参照再作成失敗時に探索ごと黙ってスキップされログも出なくなる）
			 */
			if( ! $args['is_cwebp'] ) {
				if( $ref_editor ) {
					$ref_editor = null;
					unset( $ref_editor );
				}
				$ref_editor = $this->_make_reference_editor(
					$args['original_file'],
					(int) $size_data['width'],
					(int) $size_data['height'],
					$args['crop']
				);
				if( is_wp_error( $ref_editor ) ) {
					$this->_ao_debug_log( sprintf(
						'[%s/webp] reference editor recreate failed: %s',
						$size_name,
						$ref_editor->get_error_message()
					) );
					$ref_editor = null;
				}
			}

			$webp_ceiling = $args['webp_editor']->get_quality_from_size( $size_name, 'image/webp' );
			$webp_floor   = $this->_get_quality_floor( $args, 'image/webp' );
			$found_webp   = null;

			$this->_ao_debug_log( sprintf(
				'[%s/webp] prepare: floor=%d, ceiling=%d, cwebp=%d, has_ref=%d, target_level_ssim=%.4f',
				$size_name,
				$webp_floor,
				$webp_ceiling,
				! empty( $args['is_cwebp'] ) ? 1 : 0,
				$ref_editor ? 1 : 0,
				(float) ( $args['targets']['webp'] ?? 0 )
			) );

			if( $webp_ceiling > $webp_floor ) {

				if( $args['is_cwebp'] ) {

					// cwebp の -print_ssim を使い、取れないときだけ内側で PHP にフォールバックする
					$measure = function( $q ) use ( $args ) {
						return $this->_measure_cwebp_candidate_ssim( $args, $q, null );
					};

				} elseif( $ref_editor ) {

					// 組込ライブラリで候補を保存して PHP 側で SSIM を計算する
					$measure = function( $q ) use ( $ref_editor, $args ) {
						return $this->_measure_candidate_ssim( $ref_editor, $q, 'image/webp', 'webp', $args );
					};

				} else {

					$measure = null;
					$this->_ao_debug_log( sprintf(
						'[%s/webp] skip search: no reference editor for built-in encode',
						$size_name
					) );

				}

				if( $measure ) {
					$webp_target = $this->_adjust_ssim_target_for_ceiling(
						$args['targets']['webp'],
						$webp_ceiling,
						STILLBE_IQ_SSIM_REF_WEBP_QUALITY,
						'webp',
						$size_name
					);
					$found_webp = $this->_search_optimal_quality( $measure, $webp_floor, $webp_ceiling, $webp_target, "{$size_name}/webp/ssim" );
					if( is_wp_error( $found_webp ) ) {
						$this->_ao_debug_log( sprintf(
							'[%s/webp] search aborted: %s',
							$size_name,
							$found_webp->get_error_message()
						) );
						$found_webp = null;
					} else {
						$ref_for_mae = $ref_editor;
						if( ! $ref_for_mae ) {
							$ref_for_mae = $this->_make_reference_editor(
								$args['original_file'],
								(int) $size_data['width'],
								(int) $size_data['height'],
								$args['crop']
							);
							if( is_wp_error( $ref_for_mae ) ) {
								$this->_ao_debug_log( sprintf(
									'[%s/webp] MAE skipped: reference editor failed: %s',
									$size_name,
									$ref_for_mae->get_error_message()
								) );
								$ref_for_mae = null;
							}
						}
						if( $ref_for_mae ) {
							if( $args['is_cwebp'] ) {
								$measure_mae = function( $q ) use ( $args, $ref_for_mae ) {
									return $this->_measure_cwebp_candidate_mae( $args, $q, $ref_for_mae );
								};
							} else {
								$measure_mae = function( $q ) use ( $ref_for_mae, $args ) {
									return $this->_measure_candidate_mae( $ref_for_mae, $q, 'image/webp', 'webp', $args );
								};
							}
							$found_webp = $this->_apply_mae_second_opinion(
								$found_webp,
								$measure_mae,
								$webp_ceiling,
								"{$size_name}/webp",
								$args['target_level'] ?? 'balance',
								$measure
							);
						}
					}
				}

			} else {
				$this->_ao_debug_log( sprintf(
					'[%s/webp] skip search: ceiling (%d) <= floor (%d)',
					$size_name,
					$webp_ceiling,
					$webp_floor
				) );
			}

		// 確定品質 (探索不成立時はテーブル値のまま) で本番の WebP を生成する
		// 上限張り付き = ERE 探索の結果が上限で終了 (SSIM のみの上限到達では削除しない)
		$skip_delivery_webp = $found_webp
			&& ! empty( $found_webp['ere_searched'] )
			&& (int) $found_webp['quality'] >= (int) $webp_ceiling;

		if( $skip_delivery_webp ) {
			$this->_ao_debug_log( sprintf(
				'[%s/webp] skip delivery: ERE ceiling-stuck at q=%d (ceiling=%d); purge file and meta',
				$size_name,
				(int) $found_webp['quality'],
				(int) $webp_ceiling
			) );
				$main_path = path_join( $args['base_dir'], $args['size_data']['file'] );
				Auto_Optimize_Progress::delete_delivery_webp_file_for_size( $args['base_dir'], $args['size_data'], $main_path );
			} else {
				$final_webp_q = $found_webp ? $found_webp['quality'] : 0;
				$this->_ao_debug_log( sprintf(
					'[%s/webp] encode: override_q=%d (0 means table quality)',
					$size_name,
					(int) $final_webp_q
				) );
				$webp_data = $this->_make_webp_for_size( $args['webp_editor'], $args, $final_webp_q );

				if( ! is_wp_error( $webp_data ) && ! empty( $webp_data['size'] ) ) {
					$sb_iqc['webp-file']    = $webp_data['file'];
					$sb_iqc['webp-quality'] = $webp_data['q'];
					if( $found_webp ) {
						$sb_iqc['webp-ssim'] = round( $found_webp['ssim'], 4 );
						if( isset( $found_webp['mae'] ) ) {
							$sb_iqc['webp-mae'] = round( (float) $found_webp['mae'], 6 );
						}
					}
					if( isset( $webp_data['cwebp'] ) ) {
						$sb_iqc['cwebp'] = $webp_data['cwebp'];
					}
					stillbe_iqc_merge_encode_method_meta( $sb_iqc, $webp_data, 'webp' );
					$webp_q_final = (int) $webp_data['q'];
				}
			}

		} elseif( $this->_ao_debug_enabled() ) {
			if( empty( $args['is_enabled_webp'] ) ) {
				$this->_ao_debug_log( sprintf(
					'[%s/webp] skipped: "Enable Automatically Generating WebP" is off (no delivery WebP search)',
					$size_name
				) );
			} else {
				$this->_ao_debug_log( sprintf(
					'[%s/webp] skipped: webp_editor is unavailable',
					$size_name
				) );
			}
		}

		// --- AVIF の最適化 ---
		if( $args['is_enabled_avif'] && $args['avif_editor'] ) {

			if( $ref_editor ) {
				$ref_editor = null;
				unset( $ref_editor );
			}
			/*
			 * AVIF 候補は AVIF 対応エディタ (Imagick 非対応環境では GD) でリサイズ+エンコードされる。
			 * 参照側を別エディタで作るとリサイズ差分が SSIM / ERE に劣化として乗るため、
			 * 参照も同じ AVIF 対応エディタで作成する (ERE はエディタ型一致が必須)。
			 */
			$ref_editor = $this->_make_reference_editor(
				$args['original_file'],
				(int) $size_data['width'],
				(int) $size_data['height'],
				$args['crop'],
				'image/avif'
			);
			if( is_wp_error( $ref_editor ) ) {
				$this->_ao_debug_log( sprintf(
					'[%s/avif] reference editor recreate failed: %s',
					$size_name,
					$ref_editor->get_error_message()
				) );
				$ref_editor = null;
			}

			$avif_ceiling = $args['avif_editor']->get_quality_from_size( $size_name, 'image/avif' );
			$avif_floor   = $this->_get_quality_floor( $args, 'image/avif' );
			$found_avif   = null;

			if( $avif_ceiling > $avif_floor ) {

				$measure = function( $q ) use ( $ref_editor, $args ) {
					return $this->_measure_candidate_ssim( $ref_editor, $q, 'image/avif', 'avif', $args );
				};

				$found_avif = $this->_search_optimal_quality(
					$measure,
					$avif_floor,
					$avif_ceiling,
					$this->_adjust_ssim_target_for_ceiling(
						$args['targets']['avif'],
						$avif_ceiling,
						STILLBE_IQ_SSIM_REF_AVIF_QUALITY,
						'avif',
						$size_name
					),
					"{$size_name}/avif/ssim"
				);

				if( is_wp_error( $found_avif ) ) {
					$found_avif = null;
				} else {
					if( $ref_editor ) {
						$ref_for_mae = $ref_editor;
					} else {
						$ref_for_mae = $this->_make_reference_editor(
							$args['original_file'],
							(int) $size_data['width'],
							(int) $size_data['height'],
							$args['crop'],
							'image/avif'
						);
						if( is_wp_error( $ref_for_mae ) ) {
							$ref_for_mae = null;
						}
					}
					if( $ref_for_mae ) {
						$measure_mae = function( $q ) use ( $ref_for_mae, $args ) {
							return $this->_measure_candidate_mae( $ref_for_mae, $q, 'image/avif', 'avif', $args );
						};
						$found_avif = $this->_apply_mae_second_opinion(
							$found_avif,
							$measure_mae,
							$avif_ceiling,
							"{$size_name}/avif",
							$args['target_level'] ?? 'balance',
							$measure
						);
					}
				}

			}

		// 上限張り付き = ERE 探索の結果が上限で終了 (SSIM のみの上限到達では削除しない)
		$skip_delivery_avif = $found_avif
			&& ! empty( $found_avif['ere_searched'] )
			&& (int) $found_avif['quality'] >= (int) $avif_ceiling;

		if( $skip_delivery_avif ) {
			$this->_ao_debug_log( sprintf(
				'[%s/avif] skip delivery: ERE ceiling-stuck at q=%d (ceiling=%d); purge file and meta',
				$size_name,
				(int) $found_avif['quality'],
				(int) $avif_ceiling
			) );
				$main_path = path_join( $args['base_dir'], $args['size_data']['file'] );
				Auto_Optimize_Progress::delete_delivery_avif_file_for_size( $args['base_dir'], $args['size_data'], $main_path );
			} else {
				$final_avif_q = ( $found_avif && ! is_wp_error( $found_avif ) ) ? $found_avif['quality'] : 0;
				$avif_data    = $this->_make_avif_for_size( $args['avif_editor'], $args, $final_avif_q );

				if( ! is_wp_error( $avif_data ) && ! empty( $avif_data['size'] ) ) {
					$sb_iqc['avif-file']    = $avif_data['file'];
					$sb_iqc['avif-quality'] = $avif_data['q'];
					if( $found_avif ) {
						$sb_iqc['avif-ssim'] = round( $found_avif['ssim'], 4 );
						if( isset( $found_avif['mae'] ) ) {
							$sb_iqc['avif-mae'] = round( (float) $found_avif['mae'], 6 );
						}
					}
					stillbe_iqc_merge_encode_method_meta( $sb_iqc, $avif_data, 'avif' );
					$avif_q_final = (int) $avif_data['q'];
				}
			}

		} elseif( $this->_ao_debug_enabled() ) {
			if( empty( $args['is_enabled_avif'] ) ) {
				$this->_ao_debug_log( sprintf(
					'[%s/avif] skipped: "Enable Automatically Generating AVIF" is off (no delivery AVIF search)',
					$size_name
				) );
			} else {
				$this->_ao_debug_log( sprintf(
					'[%s/avif] skipped: avif_editor is unavailable',
					$size_name
				) );
			}
		}

		// 参照エディタを破棄
		$ref_editor = null;
		unset( $ref_editor );

		return array(
			'sb-iqc'              => $sb_iqc,
			'webp_quality'        => $webp_q_final,
			'avif_quality'        => $avif_q_final,
			'jpeg_resaved'        => $jpeg_resaved,
			'webp_resaved'        => false,
			'avif_resaved'        => false,
			'purge_delivery_webp' => ! empty( $skip_delivery_webp ),
			'purge_delivery_avif' => ! empty( $skip_delivery_avif ),
		);

	}


	/**
	 * WebP 元画像のサブサイズ / フルサイズをインプレースで SSIM 最適化する
	 *
	 * @param array $args
	 * @return array
	 */
	private function _optimize_webp_source_size( $args ) {

		$sb_iqc           = array();
		$webp_q_final     = 0;
		$webp_resaved     = false;
		$quality_override = absint( $args['quality_override'] ?? 0 );

		$ref_editor = $this->_make_reference_editor(
			$args['original_file'],
			(int) $args['size_data']['width'],
			(int) $args['size_data']['height'],
			$args['crop']
		);
		if( is_wp_error( $ref_editor ) ) {
			return array(
				'sb-iqc'       => $sb_iqc,
				'webp_quality' => 0,
				'jpeg_resaved' => false,
				'webp_resaved' => false,
			);
		}

		if( ! function_exists( 'wp_tempnam' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/file.php' );
		}

		$webp_ceiling = $ref_editor->get_quality_from_size( $args['size_name'], 'image/webp' );
		$webp_floor   = $this->_get_quality_floor( $args, 'image/webp' );
		$found_webp   = null;

		if( $quality_override > 0 ) {
			$found_webp = array(
				'quality' => min( $webp_ceiling, $quality_override ),
				'ssim'    => 0,
			);
			$this->_ao_debug_log( sprintf(
				'[%s/webp-src] skip search: quality_override=%d -> q=%d (ceiling=%d)',
				$args['size_name'],
				$quality_override,
				(int) $found_webp['quality'],
				$webp_ceiling
			) );
		} elseif( $webp_ceiling > $webp_floor ) {

			if( $args['is_cwebp'] ) {

				$measure = function( $q ) use ( $args ) {
					return $this->_measure_cwebp_candidate_ssim( $args, $q, null, true );
				};

			} else {

				$measure = function( $q ) use ( $ref_editor, $args ) {
					return $this->_measure_candidate_ssim( $ref_editor, $q, 'image/webp', 'webp', $args );
				};

			}

			$webp_target = $this->_adjust_ssim_target_for_ceiling(
				$args['targets']['webp'],
				$webp_ceiling,
				STILLBE_IQ_SSIM_REF_WEBP_QUALITY,
				'webp',
				$args['size_name']
			);
			$found_webp = $this->_search_optimal_quality(
				$measure,
				$webp_floor,
				$webp_ceiling,
				$webp_target,
				"{$args['size_name']}/webp-src/ssim"
			);
			if( is_wp_error( $found_webp ) ) {
				$found_webp = null;
			} else {
				if( $args['is_cwebp'] ) {
					$measure_mae = function( $q ) use ( $args, $ref_editor ) {
						return $this->_measure_cwebp_candidate_mae( $args, $q, $ref_editor, true );
					};
				} else {
					$measure_mae = function( $q ) use ( $ref_editor, $args ) {
						return $this->_measure_candidate_mae( $ref_editor, $q, 'image/webp', 'webp', $args );
					};
				}
				$found_webp = $this->_apply_mae_second_opinion(
					$found_webp,
					$measure_mae,
					$webp_ceiling,
					"{$args['size_name']}/webp-src",
					$args['target_level'] ?? 'balance',
					$measure
				);
			}

		}

		if( $found_webp && ! is_wp_error( $found_webp ) ) {
			$ref_editor->set_quality( (int) $found_webp['quality'] );
			$ref_editor->_set_mk_size( $args['size_name'] );
			add_filter( 'wp_editor_set_quality', [ $ref_editor, '_set_quality_hook' ], 1, 2 );
			$saved = $ref_editor->save( $args['size_file'], 'image/webp' );
			remove_filter( 'wp_editor_set_quality', [ $ref_editor, '_set_quality_hook' ], 1 );

			if( ! is_wp_error( $saved ) ) {
				$sb_iqc['quality']      = (int) $found_webp['quality'];
				$sb_iqc['webp-quality'] = (int) $found_webp['quality'];
				if( ! empty( $found_webp['ssim'] ) ) {
					$sb_iqc['ssim']      = round( (float) $found_webp['ssim'], 4 );
					$sb_iqc['webp-ssim'] = round( (float) $found_webp['ssim'], 4 );
				}
				if( isset( $found_webp['mae'] ) ) {
					$sb_iqc['mae']      = round( (float) $found_webp['mae'], 6 );
					$sb_iqc['webp-mae'] = round( (float) $found_webp['mae'], 6 );
				}
				$webp_q_final = (int) $found_webp['quality'];
				$webp_resaved = true;
			}
		}

		$ref_editor = null;
		unset( $ref_editor );

		return array(
			'sb-iqc'       => $sb_iqc,
			'webp_quality' => $webp_q_final,
			'jpeg_resaved' => false,
			'webp_resaved' => $webp_resaved,
		);

	}


	/**
	 * AVIF 元画像のサブサイズ / フルサイズをインプレースで SSIM 最適化する
	 *
	 * @param array $args
	 * @return array
	 */
	private function _optimize_avif_source_size( $args ) {

		$sb_iqc           = array();
		$avif_q_final     = 0;
		$avif_resaved     = false;
		$quality_override = absint( $args['quality_override'] ?? 0 );

		// 候補 (AVIF) と同じエディタ型でないと ERE が計測できないため、AVIF 対応エディタで参照を作る
		$ref_editor = $this->_make_reference_editor(
			$args['original_file'],
			(int) $args['size_data']['width'],
			(int) $args['size_data']['height'],
			$args['crop'],
			'image/avif'
		);
		if( is_wp_error( $ref_editor ) ) {
			return array(
				'sb-iqc'       => $sb_iqc,
				'avif_quality' => 0,
				'jpeg_resaved' => false,
				'avif_resaved' => false,
			);
		}

		$avif_ceiling = $ref_editor->get_quality_from_size( $args['size_name'], 'image/avif' );
		$avif_floor   = $this->_get_quality_floor( $args, 'image/avif' );
		$found_avif   = null;

		if( $quality_override > 0 ) {
			$found_avif = array(
				'quality' => min( $avif_ceiling, $quality_override ),
				'ssim'    => 0,
			);
		} elseif( $avif_ceiling > $avif_floor ) {

			$measure = function( $q ) use ( $ref_editor, $args ) {
				return $this->_measure_candidate_ssim( $ref_editor, $q, 'image/avif', 'avif', $args );
			};

			$avif_target = $this->_adjust_ssim_target_for_ceiling(
				$args['targets']['avif'],
				$avif_ceiling,
				STILLBE_IQ_SSIM_REF_AVIF_QUALITY,
				'avif',
				$args['size_name']
			);
			$found_avif = $this->_search_optimal_quality(
				$measure,
				$avif_floor,
				$avif_ceiling,
				$avif_target,
				"{$args['size_name']}/avif-src/ssim"
			);
			if( is_wp_error( $found_avif ) ) {
				$found_avif = null;
			} else {
				$measure_mae = function( $q ) use ( $ref_editor, $args ) {
					return $this->_measure_candidate_mae( $ref_editor, $q, 'image/avif', 'avif', $args );
				};
				$found_avif = $this->_apply_mae_second_opinion(
					$found_avif,
					$measure_mae,
					$avif_ceiling,
					"{$args['size_name']}/avif-src",
					$args['target_level'] ?? 'balance',
					$measure
				);
			}

		}

		if( $found_avif && ! is_wp_error( $found_avif ) ) {
			$ref_editor->set_quality( (int) $found_avif['quality'] );
			$ref_editor->_set_mk_size( $args['size_name'] );
			add_filter( 'wp_editor_set_quality', [ $ref_editor, '_set_quality_hook' ], 1, 2 );
			$saved = $ref_editor->save( $args['size_file'], 'image/avif' );
			remove_filter( 'wp_editor_set_quality', [ $ref_editor, '_set_quality_hook' ], 1 );

			if( ! is_wp_error( $saved ) ) {
				$sb_iqc['quality']      = (int) $found_avif['quality'];
				$sb_iqc['avif-quality'] = (int) $found_avif['quality'];
				if( ! empty( $found_avif['ssim'] ) ) {
					$sb_iqc['ssim']      = round( (float) $found_avif['ssim'], 4 );
					$sb_iqc['avif-ssim'] = round( (float) $found_avif['ssim'], 4 );
				}
				if( isset( $found_avif['mae'] ) ) {
					$sb_iqc['mae']      = round( (float) $found_avif['mae'], 6 );
					$sb_iqc['avif-mae'] = round( (float) $found_avif['mae'], 6 );
				}
				$avif_q_final = (int) $found_avif['quality'];
				$avif_resaved = true;
			}
		}

		$ref_editor = null;
		unset( $ref_editor );

		return array(
			'sb-iqc'       => $sb_iqc,
			'avif_quality' => $avif_q_final,
			'jpeg_resaved' => false,
			'avif_resaved' => $avif_resaved,
		);

	}


	/**
	 * 本番の WebP を生成する (既存の make_webp 経路 = 本番と同じエンコーダを使用)
	 *
	 * @param object $webp_editor 元画像をロード済みのエディタ
	 * @param array $args _optimize_size のコンテキスト
	 * @param int $quality_override 0 以外の場合はこの品質で生成する
	 */
	private function _make_webp_for_size( $webp_editor, $args, $quality_override = 0 ) {

		$size_name = $args['size_name'];

		$size_data              = $args['size_data'];
		$size_data['size_name'] = $size_name;
		$size_data['crop']      = $args['crop'];

		$override_hook = "stillbe_image_quality_{$size_name}_webp";

		if( $quality_override ) {
			$this->image_quality = absint( $quality_override );
			add_filter( $override_hook, [ $this, 'quality_based_on_ssim' ], 99999 );
		}

		$webp_editor->_set_mk_size( $size_name );
		add_filter( 'wp_editor_set_quality', [ $webp_editor, '_set_quality_hook' ], 1, 2 );

		try {
			$webp_name = stillbe_iqc_get_filtered_delivery_webp_path( path_join( $args['base_dir'], $args['size_data']['file'] ) );
			if( ! $webp_name ) {
				return new \WP_Error( 'webp_skip', 'Delivery WebP is not applicable.' );
			}
			$webp_data = $webp_editor->make_webp( $webp_name, $size_data );
		} finally {
			remove_filter( 'wp_editor_set_quality', [ $webp_editor, '_set_quality_hook' ], 1 );

			if( $quality_override ) {
				remove_filter( $override_hook, [ $this, 'quality_based_on_ssim' ], 99999 );
				$this->image_quality = 0;
			}
		}

		return $webp_data;

	}


	/**
	 * 本番の AVIF を生成する
	 *
	 * @param object $avif_editor
	 * @param array  $args
	 * @param int    $quality_override
	 */
	private function _make_avif_for_size( $avif_editor, $args, $quality_override = 0 ) {

		$size_name = $args['size_name'];

		$size_data              = $args['size_data'];
		$size_data['size_name'] = $size_name;
		$size_data['crop']      = $args['crop'];

		$override_hook = "stillbe_image_quality_{$size_name}_avif";

		if( $quality_override ) {
			$this->image_quality = absint( $quality_override );
			add_filter( $override_hook, [ $this, 'quality_based_on_ssim' ], 99999 );
		}

		$avif_editor->_set_mk_size( $size_name );
		add_filter( 'wp_editor_set_quality', [ $avif_editor, '_set_quality_hook' ], 1, 2 );

		try {
			$avif_name = stillbe_iqc_get_filtered_delivery_avif_path( path_join( $args['base_dir'], $args['size_data']['file'] ) );
			if( ! $avif_name ) {
				return new \WP_Error( 'avif_skip', 'Delivery AVIF is not applicable.' );
			}
			$avif_data = $avif_editor->make_avif( $avif_name, $size_data );
		} finally {
			remove_filter( 'wp_editor_set_quality', [ $avif_editor, '_set_quality_hook' ], 1 );

			if( $quality_override ) {
				remove_filter( $override_hook, [ $this, 'quality_based_on_ssim' ], 99999 );
				$this->image_quality = 0;
			}
		}

		return $avif_data;

	}


	/**
	 * 参照エディタを作成する (元画像を指定サイズへリサイズした非圧縮の状態)
	 *
	 * @param string $output_mime 候補の出力 MIME。AVIF の場合は本番生成と同じ対応エディタを選ぶ。
	 * @return object|\WP_Error
	 */
	private function _make_reference_editor( $original_file, $width, $height, $crop, $output_mime = '' ) {

		// cwebp は読み込みに使えないため、組込ライブラリのエディタを選択させる
		add_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 99999 );
		$editor = 'image/avif' === $output_mime
			? $this->_make_avif_capable_editor( $original_file )
			: wp_get_image_editor( $original_file );
		remove_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 99999 );

		if( is_wp_error( $editor ) ) {
			return $editor;
		}

		if( is_callable( array( $editor, 'maybe_exif_rotate' ) ) ) {
			$editor->maybe_exif_rotate();
		}

		$resized = $editor->resize( $width, $height, $crop );
		if( is_wp_error( $resized ) ) {
			return $resized;
		}

		return $editor;

	}


	/**
	 * 本番生成と同じ優先順で AVIF の読み書きに対応するエディタを作成する
	 *
	 * @param string $filename
	 * @return object|\WP_Error
	 */
	private function _make_avif_capable_editor( $filename ) {

		foreach( stillbe_iqc_get_avif_capable_editor_classes() as $editor_class ) {

			$editor = new $editor_class( $filename );
			$loaded = $editor->load();
			if( ! is_wp_error( $loaded ) ) {
				return $editor;
			}
		}

		return new \WP_Error(
			'image_avif_unsupported',
			__( 'AVIF is not supported by the available image editors on this server.', 'still-be-image-quality-control' )
		);

	}


	/**
	 * プラグイン管理下の一時ディレクトリパスを返す (なければ作成)
	 *
	 * 既定: {WP_CONTENT_DIR}/sb-temp
	 *
	 * @return string|\WP_Error
	 */
	public static function get_plugin_temp_dir() {

		/**
		 * Filters the temporary directory used for SSIM candidate images.
		 *
		 * @since 2.0.0
		 *
		 * @param string $dir Absolute path without trailing slash.
		 */
		$dir = (string) apply_filters(
			'still-be/image-quality-control/temp-dir',
			trailingslashit( WP_CONTENT_DIR ) . 'sb-temp'
		);
		$dir = untrailingslashit( $dir );

		if( '' === $dir ) {
			return new \WP_Error( 'temp_dir_empty', 'Temporary directory path is empty.' );
		}

		if( ! is_dir( $dir ) ) {
			if( ! wp_mkdir_p( $dir ) ) {
				return new \WP_Error( 'temp_dir_create_failed', sprintf( 'Failed to create temp directory: %s', $dir ) );
			}
		}

		if( ! is_writable( $dir ) ) {
			return new \WP_Error( 'temp_dir_not_writable', sprintf( 'Temp directory is not writable: %s', $dir ) );
		}

		$index = $dir . '/index.php';
		if( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		$htaccess = $dir . '/.htaccess';
		if( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Deny from all\n" );
		}

		return $dir;

	}


	/**
	 * この実行で作成した候補一時ファイルを削除する
	 *
	 * メタデータ保存完了後に呼ぶ。
	 */
	public function cleanup_candidate_temps() {

		if( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		foreach( $this->candidate_temp_files as $file ) {
			if( $file && file_exists( $file ) ) {
				@unlink( $file );
			}
		}
		$this->candidate_temp_files = array();

	}


	/**
	 * sb-temp 内の古い一時ファイルを削除する (日次 Cron 用フォールバック)
	 *
	 * 候補画像 (sb-iqc-cand*) と ERE デバッグ PNG (sb-iqc-ere*) など、
	 * プラグインが出力する sb-iqc-* を対象にする (index.php / .htaccess は除外)。
	 *
	 * @param int $max_age この秒数より古いファイルを削除 (既定: 24 時間)
	 * @return int 削除した件数
	 */
	public static function cleanup_old_temp_files( $max_age = null ) {

		if( null === $max_age ) {
			$max_age = DAY_IN_SECONDS;
		}
		$max_age = absint( $max_age );
		if( 1 > $max_age ) {
			$max_age = DAY_IN_SECONDS;
		}

		$dir = self::get_plugin_temp_dir();
		if( is_wp_error( $dir ) ) {
			return 0;
		}

		$expire = time() - $max_age;
		$files  = glob( trailingslashit( $dir ) . 'sb-iqc-*' );
		if( ! is_array( $files ) ) {
			return 0;
		}

		$deleted = 0;
		foreach( $files as $file ) {
			if( ! is_file( $file ) ) {
				continue;
			}
			$mtime = @filemtime( $file );
			if( $mtime && $mtime < $expire ) {
				if( @unlink( $file ) ) {
					$deleted++;
				}
			}
		}

		return $deleted;

	}


	/**
	 * SSIM 計測用の一時ファイルパスを作る (拡張子付き・ステップごとに一意)
	 *
	 * wp_tempnam() は $dir 末尾に / を付けず結合するため sb-temp 配下に置かない。
	 * 拡張子も常に .tmp になるので、sb-temp 内に直接パスを組み立てる。
	 *
	 * @param string $extension jpg|webp など
	 * @return string|\WP_Error
	 */
	private function _create_candidate_temp_path( $extension ) {

		$extension = strtolower( ltrim( (string) $extension, '.' ) );
		if( '' === $extension ) {
			$extension = 'tmp';
		}

		$dir = self::get_plugin_temp_dir();
		if( is_wp_error( $dir ) ) {
			return $dir;
		}

		$dir = trailingslashit( $dir );
		$name = wp_unique_filename(
			untrailingslashit( $dir ),
			'sb-iqc-cand-' . wp_generate_password( 6, false ) . '.' . $extension
		);
		$path = $dir . $name;

		$this->candidate_temp_files[] = $path;
		return $path;

	}


	/**
	 * 組込エディタで候補を保存し SSIM を測る (ステップごとに新規一時ファイル)
	 *
	 * 参照エディタは SSIM 比較専用とし、候補の save() は毎ステップ別エディタで行う。
	 * ref_editor->save() すると WP コアが file/mime_type を書き換え、以降の品質探索が壊れる。
	 *
	 * @param object $ref_editor SSIM 参照用 (save しない)
	 * @param int    $quality
	 * @param string $mime_type
	 * @param string $extension
	 * @param array  $args
	 * @return float|\WP_Error
	 */
	private function _measure_candidate_ssim( $ref_editor, $quality, $mime_type, $extension, $args ) {

		if( empty( $args['original_file'] ) || empty( $args['size_data'] ) ) {
			return new \WP_Error( 'missing_args', 'Candidate encode arguments are required.' );
		}

		$encoder = $this->_make_reference_editor(
			$args['original_file'],
			(int) $args['size_data']['width'],
			(int) $args['size_data']['height'],
			$args['crop'],
			$mime_type
		);
		if( is_wp_error( $encoder ) ) {
			return $encoder;
		}

		$temp = $this->_create_candidate_temp_path( $extension );
		if( is_wp_error( $temp ) ) {
			return $temp;
		}

		$q = absint( $quality );

		/*
		 * JPEG→WebP など形式変換時、WP_Image_Editor::get_output_format() が
		 * set_quality() を引数なしで呼び、WebP 既定品質 (86) に上書きする。
		 * 探索中の品質を維持するため、保存中だけフィルターで固定する。
		 */
		$pin_quality = static function( $default_quality, $filter_mime = '' ) use ( $q ) {
			return $q;
		};
		add_filter( 'wp_editor_set_quality', $pin_quality, 99999, 2 );

		$saved = null;
		try {
			$encoder->set_quality( $q );
			$saved = $encoder->save( $temp, $mime_type );
		} finally {
			remove_filter( 'wp_editor_set_quality', $pin_quality, 99999 );
		}

		if( is_wp_error( $saved ) ) {
			$encoder = null;
			unset( $encoder );
			return $saved;
		}

		$path = ! empty( $saved['path'] ) ? $saved['path'] : $temp;
		if( $path && $path !== $temp ) {
			$this->candidate_temp_files[] = $path;
		}

		$ssim = $this->_measure_ssim_file( $ref_editor, $path, $mime_type );

		$encoder = null;
		unset( $encoder );
		if( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		return $ssim;

	}


	/**
	 * cwebp で候補を生成し SSIM を測る (ステップごとに新規一時ファイル)
	 *
	 * cwebp の `-print_ssim` から SSIM が取れた場合はそれを使い、
	 * 内部エディタでの再計算は行わない。取得できないときだけ PHP 側へフォールバックする。
	 *
	 * @param array       $args
	 * @param int         $quality
	 * @param object|null $ref_editor
	 * @param bool        $source_is_webp true なら mime を image/webp にする
	 * @return float|\WP_Error
	 */
	private function _measure_cwebp_candidate_ssim( $args, $quality, $ref_editor = null, $source_is_webp = false ) {

		if( ! function_exists( 'stillbe_iqc_extends_conv_cwebp' ) ) {
			return new \WP_Error( 'cwebp_unavailable', 'cwebp conversion function is not available.' );
		}

		$temp = $this->_create_candidate_temp_path( 'webp' );
		if( is_wp_error( $temp ) ) {
			return $temp;
		}

		$options = array(
			'quality' => array( absint( $quality ), absint( $quality ) ),
			'mime'    => $source_is_webp ? 'image/webp' : ( $args['source_mime'] ?? 'image/jpeg' ),
			'size'    => $args['original_size'],
		);
		$cand_size_data              = $args['size_data'];
		$cand_size_data['size_name'] = $args['size_name'];
		$cand_size_data['crop']      = $args['crop'];

		$result = stillbe_iqc_extends_conv_cwebp( $args['original_file'], $temp, $cand_size_data, $options );
		if( is_wp_error( $result ) || empty( $result ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'cwebp_failed', 'cwebp conversion failed.' );
		}

		$cwebp_meta = ( isset( $result['cwebp'] ) && is_array( $result['cwebp'] ) ) ? $result['cwebp'] : array();
		$cwebp_ssim = $this->_extract_cwebp_ssim( $cwebp_meta );

		if( null !== $cwebp_ssim ) {
			return $cwebp_ssim;
		}

		// cwebp から SSIM が得られない場合のみ内部エディタで再計算する
		if( ! $ref_editor ) {
			$ref_editor = $this->_make_reference_editor(
				$args['original_file'],
				(int) ( $args['size_data']['width'] ?? 0 ),
				(int) ( $args['size_data']['height'] ?? 0 ),
				$args['crop'] ?? false
			);
			if( is_wp_error( $ref_editor ) ) {
				return new \WP_Error(
					'no_ssim',
					'cwebp SSIM is unavailable and reference editor failed: ' . $ref_editor->get_error_message()
				);
			}
		}

		return $this->_measure_ssim_file( $ref_editor, $temp, 'image/webp' );

	}


	/**
	 * cwebp メタから有効な SSIM (0-1) を取り出す
	 *
	 * ssim=0 はパース失敗時の番兵なので無効とする。
	 *
	 * @param array $cwebp_meta
	 * @return float|null
	 */
	private function _extract_cwebp_ssim( $cwebp_meta ) {

		if( ! is_array( $cwebp_meta ) ) {
			return null;
		}

		if( isset( $cwebp_meta['ssim'] ) && is_numeric( $cwebp_meta['ssim'] ) ) {
			$ssim = (float) $cwebp_meta['ssim'];
			if( $ssim > 0 && $ssim <= 1 ) {
				return $ssim;
			}
		}

		if( isset( $cwebp_meta['ssim_in_dB'] ) && is_numeric( $cwebp_meta['ssim_in_dB'] ) ) {
			$db = (float) $cwebp_meta['ssim_in_dB'];
			if( $db > 0 ) {
				return (float) ( 1 - ( 10 ** ( -$db / 10 ) ) );
			}
		}

		return null;

	}


	/**
	 * 参照エディタと保存済みファイルの SSIM (mean) を計算する
	 *
	 * @return float|\WP_Error
	 */
	private function _measure_ssim_file( $ref_editor, $filename, $mime_type ) {

		if( ! is_string( $filename ) || '' === $filename || ! file_exists( $filename ) || 1 > (int) @filesize( $filename ) ) {
			return new \WP_Error(
				'missing_candidate',
				sprintf( 'Candidate image is missing or empty: %s', is_string( $filename ) ? $filename : '(invalid)' )
			);
		}

		// cwebp は読み込みに使えないため、組込ライブラリのエディタを選択させる
		add_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 99999 );
		$candidate = 'image/avif' === $mime_type
			? $this->_make_avif_capable_editor( $filename )
			: wp_get_image_editor( $filename, array( 'mime_type' => $mime_type ) );
		remove_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 99999 );

		if( is_wp_error( $candidate ) ) {
			return $candidate;
		}

		$ssim = new SSIM( $ref_editor, $candidate );
		$calc = $ssim->calc();

		$candidate = null;
		unset( $candidate, $ssim );
		if( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		if( is_wp_error( $calc ) ) {
			return $calc;
		}

		return (float) $calc['mean'];

	}


	/**
	 * 組込エディタで候補を保存し MAE を測る (ステップごとに新規一時ファイル)
	 *
	 * @param object $ref_editor MAE 参照用 (save しない)
	 * @param int    $quality
	 * @param string $mime_type
	 * @param string $extension
	 * @param array  $args
	 * @return float|\WP_Error 正規化 mean MAE
	 */
	private function _measure_candidate_mae( $ref_editor, $quality, $mime_type, $extension, $args ) {

		if( empty( $args['original_file'] ) || empty( $args['size_data'] ) ) {
			return new \WP_Error( 'missing_args', 'Candidate encode arguments are required.' );
		}

		$encoder = $this->_make_reference_editor(
			$args['original_file'],
			(int) $args['size_data']['width'],
			(int) $args['size_data']['height'],
			$args['crop'],
			$mime_type
		);
		if( is_wp_error( $encoder ) ) {
			return $encoder;
		}

		$temp = $this->_create_candidate_temp_path( $extension );
		if( is_wp_error( $temp ) ) {
			return $temp;
		}

		$q = absint( $quality );

		$pin_quality = static function( $default_quality, $filter_mime = '' ) use ( $q ) {
			return $q;
		};
		add_filter( 'wp_editor_set_quality', $pin_quality, 99999, 2 );

		$saved = null;
		try {
			$encoder->set_quality( $q );
			$saved = $encoder->save( $temp, $mime_type );
		} finally {
			remove_filter( 'wp_editor_set_quality', $pin_quality, 99999 );
		}

		if( is_wp_error( $saved ) ) {
			$encoder = null;
			unset( $encoder );
			return $saved;
		}

		$path = ! empty( $saved['path'] ) ? $saved['path'] : $temp;
		if( $path && $path !== $temp ) {
			$this->candidate_temp_files[] = $path;
		}

		$mae = $this->_measure_mae_file(
			$ref_editor,
			$path,
			$mime_type,
			$q,
			$this->_ere_debug_library_from_editor( $encoder ),
			$args['size_name'] ?? ''
		);

		$encoder = null;
		unset( $encoder );
		if( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		return $mae;

	}


	/**
	 * cwebp で候補を生成し MAE を測る (ステップごとに新規一時ファイル)
	 *
	 * @param array  $args
	 * @param int    $quality
	 * @param object $ref_editor
	 * @param bool   $source_is_webp true なら mime を image/webp にする
	 * @return float|\WP_Error
	 */
	private function _measure_cwebp_candidate_mae( $args, $quality, $ref_editor, $source_is_webp = false ) {

		if( ! function_exists( 'stillbe_iqc_extends_conv_cwebp' ) ) {
			return new \WP_Error( 'cwebp_unavailable', 'cwebp conversion function is not available.' );
		}

		if( empty( $ref_editor ) || is_wp_error( $ref_editor ) ) {
			return new \WP_Error( 'no_ref_editor', 'Reference editor is required for MAE.' );
		}

		$temp = $this->_create_candidate_temp_path( 'webp' );
		if( is_wp_error( $temp ) ) {
			return $temp;
		}

		$options = array(
			'quality' => array( absint( $quality ), absint( $quality ) ),
			'mime'    => $source_is_webp ? 'image/webp' : ( $args['source_mime'] ?? 'image/jpeg' ),
			'size'    => $args['original_size'],
		);
		$cand_size_data              = $args['size_data'];
		$cand_size_data['size_name'] = $args['size_name'];
		$cand_size_data['crop']      = $args['crop'];

		$result = stillbe_iqc_extends_conv_cwebp( $args['original_file'], $temp, $cand_size_data, $options );
		if( is_wp_error( $result ) || empty( $result ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'cwebp_failed', 'cwebp conversion failed.' );
		}

		return $this->_measure_mae_file(
			$ref_editor,
			$temp,
			'image/webp',
			absint( $quality ),
			'cwebp',
			$args['size_name'] ?? ''
		);

	}


	/**
	 * ERE デバッグ用のエンコードライブラリ名をエディタから推定する
	 *
	 * @param object|null $editor
	 * @return string gd|imagick|''
	 */
	private function _ere_debug_library_from_editor( $editor ) {

		if( $editor instanceof Image_Editor_Imagick ) {
			return 'imagick';
		}
		if( $editor instanceof Image_Editor_GD ) {
			return 'gd';
		}

		return '';

	}


	/**
	 * MIME タイプをデバッグ用の短い形式名へ変換する
	 *
	 * @param string $mime_type
	 * @return string jpeg|webp|avif|png|...
	 */
	private function _ere_debug_type_from_mime( $mime_type ) {

		$mime_type = strtolower( (string) $mime_type );
		if( 0 === strpos( $mime_type, 'image/' ) ) {
			$mime_type = substr( $mime_type, 6 );
		}

		return preg_replace( '/[^a-z0-9]/i', '', $mime_type );

	}


	/**
	 * 候補一時ファイル名から共有トークンを取り出す
	 *
	 * 例: sb-iqc-cand-EGZtvC.webp → EGZtvC
	 *
	 * @param string $filename
	 * @return string
	 */
	private function _ere_debug_token_from_filename( $filename ) {

		$base = pathinfo( (string) $filename, PATHINFO_FILENAME );
		if( preg_match( '/^sb-iqc-cand-(.+)$/i', $base, $m ) ) {
			$token = preg_replace( '/[^a-z0-9]/i', '', $m[1] );
			if( '' !== $token ) {
				return $token;
			}
		}

		return '';

	}


	/**
	 * 参照エディタと保存済みファイルの ERE (誤差上位約 1% バンド平均) を計算する
	 *
	 * @param object      $ref_editor
	 * @param string      $filename
	 * @param string      $mime_type
	 * @param int|null    $quality   デバッグ PNG ファイル名用
	 * @param string|null $library   デバッグ PNG ファイル名用 (gd|imagick|cwebp)
	 * @param string|null $size_name デバッグ PNG ファイル名用 (medium|large|...)
	 * @return float|\WP_Error 正規化 top_mean
	 */
	private function _measure_mae_file( $ref_editor, $filename, $mime_type, $quality = null, $library = null, $size_name = null ) {

		if( ! is_string( $filename ) || '' === $filename || ! file_exists( $filename ) || 1 > (int) @filesize( $filename ) ) {
			return new \WP_Error(
				'missing_candidate',
				sprintf( 'Candidate image is missing or empty: %s', is_string( $filename ) ? $filename : '(invalid)' )
			);
		}

		add_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 99999 );
		$candidate = 'image/avif' === $mime_type
			? $this->_make_avif_capable_editor( $filename )
			: wp_get_image_editor( $filename, array( 'mime_type' => $mime_type ) );
		remove_filter( 'stillbe_image_quality_control_enable_cwebp_lib', '__return_false', 99999 );

		if( is_wp_error( $candidate ) ) {
			return $candidate;
		}

		// ブロック構造に合わせて上位バンド幅を形式別にする
		// AVIF: 大ブロック化で劣化面積が小さいため狭く / JPEG: デブロッキングが無く残差が高く出るため広く均す
		switch( $mime_type ) {
			case 'image/avif':
				$keep_ratio = defined( 'STILLBE_IQ_ERE_TOP_KEEP_AVIF' ) ? (float) STILLBE_IQ_ERE_TOP_KEEP_AVIF : 0.0011;
				$trim_ratio = defined( 'STILLBE_IQ_ERE_TOP_TRIM_AVIF' ) ? (float) STILLBE_IQ_ERE_TOP_TRIM_AVIF : 0.0001;
			break;
			case 'image/jpeg':
				$keep_ratio = defined( 'STILLBE_IQ_ERE_TOP_KEEP_JPEG' ) ? (float) STILLBE_IQ_ERE_TOP_KEEP_JPEG : 0.022;
				$trim_ratio = defined( 'STILLBE_IQ_ERE_TOP_TRIM_JPEG' ) ? (float) STILLBE_IQ_ERE_TOP_TRIM_JPEG : 0.002;
			break;
			default:
				$keep_ratio = defined( 'STILLBE_IQ_ERE_TOP_KEEP_DEFAULT' ) ? (float) STILLBE_IQ_ERE_TOP_KEEP_DEFAULT : 0.011;
				$trim_ratio = defined( 'STILLBE_IQ_ERE_TOP_TRIM_DEFAULT' ) ? (float) STILLBE_IQ_ERE_TOP_TRIM_DEFAULT : 0.001;
		}

		if( null === $library || '' === $library ) {
			$library = $this->_ere_debug_library_from_editor( $ref_editor );
			if( '' === $library ) {
				$library = $this->_ere_debug_library_from_editor( $candidate );
			}
		}

		$mae  = new Edge_Residual_Mean( $ref_editor, $candidate );
		$calc = $mae->calc( (object) array(
			'normalize'      => true,
			'top_keep_ratio' => $keep_ratio,
			'top_trim_ratio' => $trim_ratio,
			'token'          => $this->_ere_debug_token_from_filename( $filename ),
			'size'           => $size_name,
			'quality'        => $quality,
			'type'           => $this->_ere_debug_type_from_mime( $mime_type ),
			'library'        => $library,
		) );

		$candidate = null;
		unset( $candidate, $mae );
		if( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		if( is_wp_error( $calc ) ) {
			return $calc;
		}

		// 全画素平均は平坦部に薄まるため、局所劣化を反映する上位バンド平均を判定に使う
		return isset( $calc['top_mean'] ) ? (float) $calc['top_mean'] : (float) $calc['mean'];

	}


	/**
	 * SSIM 探索の品質下限を返す
	 *
	 * @param array  $args        _optimize_size / _optimize_webp_source_size のコンテキスト
	 * @param string $search_mime 探索中の mime-type (例: image/jpeg, image/webp)
	 * @return int 1 以上
	 */
	private function _get_quality_floor( $args, $search_mime ) {

		$size_name     = (string) ( $args['size_name'] ?? '' );
		$source_mime   = (string) ( $args['source_mime'] ?? '' );
		$metadata      = ( isset( $args['metadata'] ) && is_array( $args['metadata'] ) ) ? $args['metadata'] : null;
		$attachment_id = absint( $args['attachment_id'] ?? 0 );
		$floor         = defined( 'STILLBE_IQ_SSIM_QUALITY_FLOOR' ) ? (int) STILLBE_IQ_SSIM_QUALITY_FLOOR : 40;

		/**
		 * Filters the quality floor used when searching optimal compression quality.
		 *
		 * Return a value greater than or equal to the size's quality ceiling to skip
		 * SSIM search and keep the configured quality.
		 *
		 * @since 2.0.0
		 *
		 * @param int         $floor          Quality floor (default STILLBE_IQ_SSIM_QUALITY_FLOOR).
		 * @param string      $size_name      Current image size name (e.g. medium, large, original).
		 * @param string      $source_mime    Original attachment mime-type.
		 * @param string      $search_mime    Mime-type currently being optimized / searched.
		 * @param array|null  $metadata       Attachment metadata when available.
		 * @param int         $attachment_id  Attachment ID when available.
		 */
		return max( 1, absint( apply_filters(
			'still-be/image-quality-control/auto-optimize/quality-floor',
			$floor,
			$size_name,
			$source_mime,
			$search_mime,
			$metadata,
			$attachment_id
		) ) );

	}


	/**
	 * SSIM が目標値を満たす最小の品質を二分探索する
	 *
	 * @param callable $measure fn( int $quality ): float|\WP_Error
	 * @param int $floor 品質の下限
	 * @param int $ceiling 品質の上限 (品質テーブルの設定値)
	 * @param float $target 目標 SSIM
	 * @param string $label ログ用のラベル (例: medium/jpeg/ssim)
	 * @return array|\WP_Error array( 'quality' => int, 'ssim' => float )
	 *                         天井でも目標未達の場合は天井値を返す
	 */
	private function _search_optimal_quality( $measure, $floor, $ceiling, $target, $label = '' ) {

		$floor   = max( 1, absint( $floor ) );
		$ceiling = max( $floor, absint( $ceiling ) );
		$debug   = $this->_ao_debug_enabled();
		$prefix  = $label ? "[{$label}] " : '';

		$measured = array();

		$lo = $floor;
		$hi = $ceiling;

		if( $debug ) {
			$this->_ao_debug_log( sprintf(
				'%ssearch start: floor=%d, ceiling=%d, target_ssim=%.4f',
				$prefix,
				$floor,
				$ceiling,
				$target
			) );
		}

		$step = 0;
		while( $lo < $hi ) {
			$q    = (int) ( ( $lo + $hi ) / 2 );
			$ssim = call_user_func( $measure, $q );
			if( is_wp_error( $ssim ) ) {
				if( $debug ) {
					$this->_ao_debug_log( sprintf(
						'%sstep %d: q=%d failed: %s',
						$prefix,
						++$step,
						$q,
						$ssim->get_error_message()
					) );
				}
				return $ssim;
			}
			$measured[ $q ] = $ssim;
			$pass = ( $ssim >= $target );
			if( $debug ) {
				$this->_ao_debug_log( sprintf(
					'%sstep %d: q=%d, ssim=%.6f, target=%.4f, %s -> next lo=%d hi=%d',
					$prefix,
					++$step,
					$q,
					$ssim,
					$target,
					$pass ? 'pass' : 'fail',
					$pass ? $lo : ( $q + 1 ),
					$pass ? $q : $hi
				) );
			}
			if( $pass ) {
				$hi = $q;
			} else {
				$lo = $q + 1;
			}
		}

		$final = $hi;

		if( ! isset( $measured[ $final ] ) ) {
			$ssim = call_user_func( $measure, $final );
			if( is_wp_error( $ssim ) ) {
				if( $debug ) {
					$this->_ao_debug_log( sprintf(
						'%sfinal q=%d failed: %s',
						$prefix,
						$final,
						$ssim->get_error_message()
					) );
				}
				return $ssim;
			}
			$measured[ $final ] = $ssim;
		}

		if( $debug ) {
			$this->_ao_debug_log( sprintf(
				'%sresult: quality=%d, ssim=%.6f (steps=%d)',
				$prefix,
				$final,
				(float) $measured[ $final ],
				$step
			) );
		}

		return array(
			'quality' => $final,
			'ssim'    => (float) $measured[ $final ],
		);

	}


	/**
	 * SSIM 採用品質がトリガー未満なら MAE で品質を引き上げる
	 *
	 * MAE 探索の下限は SSIM 結果品質 (下げない)。品質が上がった場合は SSIM を再計測する。
	 *
	 * @param array         $found         array( 'quality' => int, 'ssim' => float )
	 * @param callable      $measure_mae   fn( int $quality ): float|\WP_Error
	 * @param int           $ceiling
	 * @param string        $label
	 * @param string        $target_level  efficiency|balance|quality
	 * @param callable|null $measure_ssim  品質上昇時の SSIM 再計測用
	 * @return array array( 'quality' => int, 'ssim' => float, 'mae'?: float )
	 */
	private function _apply_mae_second_opinion( $found, $measure_mae, $ceiling, $label, $target_level, $measure_ssim = null ) {

		if( empty( $found ) || ! isset( $found['quality'] ) ) {
			return $found;
		}

		$ssim_q  = (int) $found['quality'];
		$trigger = $this->_get_mae_trigger_quality( $target_level );
		$target  = $this->_get_mae_target( $target_level );
		$prefix  = $label ? "[{$label}] " : '';

		if( $ssim_q >= $trigger ) {
			$this->_ao_debug_log( sprintf(
				'%sERE skip: q=%d >= trigger=%d',
				$prefix,
				$ssim_q,
				$trigger
			) );
			return $found;
		}

		$this->_ao_debug_log( sprintf(
			'%sERE second opinion: ssim_q=%d < trigger=%d, ere_target=%.6f, ceiling=%d',
			$prefix,
			$ssim_q,
			$trigger,
			$target,
			(int) $ceiling
		) );

		$mae_found = $this->_search_optimal_quality_mae(
			$measure_mae,
			$ssim_q,
			$ceiling,
			$target,
			"{$label}/ere"
		);

		if( is_wp_error( $mae_found ) ) {
			$this->_ao_debug_log( sprintf(
				'%sERE search aborted: %s (keep SSIM q=%d)',
				$prefix,
				$mae_found->get_error_message(),
				$ssim_q
			) );
			return $found;
		}

		$result = array(
			'quality'      => (int) $mae_found['quality'],
			'ssim'         => (float) $found['ssim'],
			'mae'          => (float) $mae_found['mae'],
			'ere_searched' => true,
		);

		if( $result['quality'] !== $ssim_q && is_callable( $measure_ssim ) ) {
			$ssim = call_user_func( $measure_ssim, $result['quality'] );
			if( ! is_wp_error( $ssim ) ) {
				$result['ssim'] = (float) $ssim;
			}
		}

		$this->_ao_debug_log( sprintf(
			'%sERE result: quality=%d (ssim_q=%d), ere=%.6f, ssim=%.6f',
			$prefix,
			$result['quality'],
			$ssim_q,
			$result['mae'],
			$result['ssim']
		) );

		return $result;

	}


	/**
	 * MAE が目標値以下を満たす最小の品質を二分探索する (下限は下げない)
	 *
	 * @param callable $measure fn( int $quality ): float|\WP_Error 正規化 MAE
	 * @param int      $floor
	 * @param int      $ceiling
	 * @param float    $target  MAE 上限 (小さいほど厳しい)
	 * @param string   $label
	 * @return array|\WP_Error array( 'quality' => int, 'mae' => float )
	 */
	private function _search_optimal_quality_mae( $measure, $floor, $ceiling, $target, $label = '' ) {

		$floor   = max( 1, absint( $floor ) );
		$ceiling = max( $floor, absint( $ceiling ) );
		$debug   = $this->_ao_debug_enabled();
		$prefix  = $label ? "[{$label}] " : '';

		$measured = array();

		$lo = $floor;
		$hi = $ceiling;

		if( $debug ) {
			$this->_ao_debug_log( sprintf(
				'%ssearch start: floor=%d, ceiling=%d, target_mae=%.6f',
				$prefix,
				$floor,
				$ceiling,
				$target
			) );
		}

		$step = 0;
		while( $lo < $hi ) {
			$q   = (int) ( ( $lo + $hi ) / 2 );
			$mae = call_user_func( $measure, $q );
			if( is_wp_error( $mae ) ) {
				if( $debug ) {
					$this->_ao_debug_log( sprintf(
						'%sstep %d: q=%d failed: %s',
						$prefix,
						++$step,
						$q,
						$mae->get_error_message()
					) );
				}
				return $mae;
			}
			$measured[ $q ] = $mae;
			// 小さいほど良い: 目標以下なら合格
			$pass = ( $mae <= $target );
			if( $debug ) {
				$this->_ao_debug_log( sprintf(
					'%sstep %d: q=%d, mae=%.6f, target=%.6f, %s -> next lo=%d hi=%d',
					$prefix,
					++$step,
					$q,
					$mae,
					$target,
					$pass ? 'pass' : 'fail',
					$pass ? $lo : ( $q + 1 ),
					$pass ? $q : $hi
				) );
			}
			if( $pass ) {
				$hi = $q;
			} else {
				$lo = $q + 1;
			}
		}

		$final = $hi;

		if( ! isset( $measured[ $final ] ) ) {
			$mae = call_user_func( $measure, $final );
			if( is_wp_error( $mae ) ) {
				if( $debug ) {
					$this->_ao_debug_log( sprintf(
						'%sfinal q=%d failed: %s',
						$prefix,
						$final,
						$mae->get_error_message()
					) );
				}
				return $mae;
			}
			$measured[ $final ] = $mae;
		}

		if( $debug ) {
			$this->_ao_debug_log( sprintf(
				'%sresult: quality=%d, mae=%.6f (steps=%d)',
				$prefix,
				$final,
				(float) $measured[ $final ],
				$step
			) );
		}

		return array(
			'quality' => $final,
			'mae'     => (float) $measured[ $final ],
		);

	}


	/**
	 * MAE セカンドオピニオンを開始する品質トリガーを返す
	 *
	 * @param string $level efficiency|balance|quality
	 * @return int
	 */
	private function _get_mae_trigger_quality( $level = 'balance' ) {

		switch( $level ) {
			case 'efficiency':
				$trigger = defined( 'STILLBE_IQ_MAE_TRIGGER_QUALITY_EFFICIENCY' ) ? (int) STILLBE_IQ_MAE_TRIGGER_QUALITY_EFFICIENCY : 60;
			break;
			case 'quality':
				$trigger = defined( 'STILLBE_IQ_MAE_TRIGGER_QUALITY_QUALITY' ) ? (int) STILLBE_IQ_MAE_TRIGGER_QUALITY_QUALITY : 70;
			break;
			case 'balance':
			default:
				$trigger = defined( 'STILLBE_IQ_MAE_TRIGGER_QUALITY_BALANCE' ) ? (int) STILLBE_IQ_MAE_TRIGGER_QUALITY_BALANCE : 65;
		}

		/**
		 * Filters the quality below which MAE second-opinion search runs.
		 *
		 * @since 2.0.2
		 *
		 * @param int    $trigger Quality trigger.
		 * @param string $level   Auto-optimize target level.
		 */
		return max( 1, absint( apply_filters(
			'still-be/image-quality-control/auto-optimize/mae/trigger-quality',
			$trigger,
			$level
		) ) );

	}


	/**
	 * MAE セカンドオピニオンの目標上限 (正規化 mean) を返す
	 *
	 * @param string $level efficiency|balance|quality
	 * @return float
	 */
	private function _get_mae_target( $level = 'balance' ) {

		switch( $level ) {
			case 'efficiency':
				$target = defined( 'STILLBE_IQ_MAE_TARGET_EFFICIENCY' ) ? (float) STILLBE_IQ_MAE_TARGET_EFFICIENCY : 0.095;
			break;
			case 'quality':
				$target = defined( 'STILLBE_IQ_MAE_TARGET_QUALITY' ) ? (float) STILLBE_IQ_MAE_TARGET_QUALITY : 0.065;
			break;
			case 'balance':
			default:
				$target = defined( 'STILLBE_IQ_MAE_TARGET_BALANCE' ) ? (float) STILLBE_IQ_MAE_TARGET_BALANCE : 0.080;
		}

		/**
		 * Filters the MAE upper bound used by second-opinion search.
		 *
		 * @since 2.0.2
		 *
		 * @param float  $target Normalized mean MAE upper bound (lower is stricter).
		 * @param string $level  Auto-optimize target level.
		 */
		$target = (float) apply_filters(
			'still-be/image-quality-control/auto-optimize/mae/target',
			$target,
			$level
		);

		if( $target <= 0 ) {
			$target = 0.008;
		}

		return $target;

	}


	// SSIM の目標値を取得する
	private function _get_ssim_targets( $level = 'balance' ) {

		switch( $level ) {
			case 'efficiency':
				$targets = array(
					'jpeg' => STILLBE_IQ_SSIM_TARGET_JPEG_EFFICIENCY,
					'webp' => STILLBE_IQ_SSIM_TARGET_WEBP_EFFICIENCY,
					'avif' => STILLBE_IQ_SSIM_TARGET_AVIF_EFFICIENCY,
				);
			break;
			case 'quality':
				$targets = array(
					'jpeg' => STILLBE_IQ_SSIM_TARGET_JPEG_QUALITY,
					'webp' => STILLBE_IQ_SSIM_TARGET_WEBP_QUALITY,
					'avif' => STILLBE_IQ_SSIM_TARGET_AVIF_QUALITY,
				);
			break;
			case 'balance':
			default:
				$targets = array(
					'jpeg' => STILLBE_IQ_SSIM_TARGET_JPEG_BALANCE,
					'webp' => STILLBE_IQ_SSIM_TARGET_WEBP_BALANCE,
					'avif' => STILLBE_IQ_SSIM_TARGET_AVIF_BALANCE,
				);
		}

		return apply_filters( 'still-be/image-quality-control/auto-optimize/ssim/targets', $targets, $level );

	}


	/**
	 * SSIM を dB 表現に変換する (1 - SSIM の対数損失)
	 *
	 * @param float $ssim
	 * @return float
	 */
	private function _ssim_to_db( $ssim ) {

		$ssim = (float) $ssim;
		$ssim = max( 0.0, min( 1.0 - 1e-12, $ssim ) );

		return -10 * log10( 1.0 - $ssim );

	}


	/**
	 * dB 表現から SSIM に戻す
	 *
	 * @param float $db
	 * @return float
	 */
	private function _db_to_ssim( $db ) {

		$db = max( 0.0, (float) $db );

		return min( 1.0, max( 0.0, 1.0 - pow( 10, -$db / 10 ) ) );

	}


	/**
	 * 品質上限テーブルに応じて SSIM 目標値を微調整する
	 *
	 * 基準品質 (JPEG 72 / WebP 76) での目標 SSIM を、上限との差分に応じて dB 空間でシフトする。
	 * 例: 目標 0.98・基準 72・上限 60 → 約 0.97 (12 ポイント差で 0.01 相当)
	 *
	 * @param float  $base_ssim   プリセット目標 SSIM
	 * @param int    $ceiling     品質テーブルの上限
	 * @param int    $ref_quality 基準品質
	 * @param string $format      jpeg|webp
	 * @param string $size_name   サイズ名
	 * @return float
	 */
	private function _adjust_ssim_target_for_ceiling( $base_ssim, $ceiling, $ref_quality, $format, $size_name ) {

		$base_ssim   = (float) $base_ssim;
		$ceiling     = absint( $ceiling );
		$ref_quality = absint( $ref_quality );
		$delta_q     = $ceiling - $ref_quality;

		if( 0 === $delta_q ) {
			return (float) apply_filters(
				'still-be/image-quality-control/auto-optimize/ssim/target-adjusted',
				$base_ssim,
				$base_ssim,
				$ceiling,
				$ref_quality,
				$format,
				$size_name
			);
		}

		$step_q    = absint( apply_filters(
			'still-be/image-quality-control/auto-optimize/ssim/step-quality-delta',
			STILLBE_IQ_SSIM_TARGET_STEP_QUALITY_DELTA
		) );
		$step_ssim = (float) apply_filters(
			'still-be/image-quality-control/auto-optimize/ssim/step-value',
			STILLBE_IQ_SSIM_TARGET_STEP_VALUE
		);

		if( 1 > $step_q ) {
			$step_q = 12;
		}
		if( 0 >= $step_ssim ) {
			$step_ssim = 0.01;
		}

		$db       = $this->_ssim_to_db( $base_ssim );
		$min_ssim = (float) apply_filters(
			'still-be/image-quality-control/auto-optimize/ssim/target-min',
			STILLBE_IQ_SSIM_TARGET_MIN
		);
		$db_step  = abs( $db - $this->_ssim_to_db( max( $min_ssim, $base_ssim - $step_ssim ) ) );
		$scale    = $db_step / $step_q;
		$adjusted = $this->_db_to_ssim( $db + ( $scale * $delta_q ) );

		$max_ssim = (float) apply_filters(
			'still-be/image-quality-control/auto-optimize/ssim/target-max',
			STILLBE_IQ_SSIM_TARGET_MAX
		);
		$adjusted = max( $min_ssim, min( $max_ssim, $adjusted ) );

		return (float) apply_filters(
			'still-be/image-quality-control/auto-optimize/ssim/target-adjusted',
			$adjusted,
			$base_ssim,
			$ceiling,
			$ref_quality,
			$format,
			$size_name
		);

	}


	// crop フラグを解決する (未登録サイズはアスペクト比の差から推定)
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


	// 品質の上書きフック (探索で確定した品質を返す)
	public function quality_based_on_ssim( $quality ) {
		return $this->image_quality ?: $quality;
	}


	/**
	 * 自動最適化デバッグログが有効か
	 *
	 * @return bool
	 */
	private function _ao_debug_enabled() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}


	/**
	 * 自動最適化のデバッグログを書く (WP_DEBUG 時のみ)
	 *
	 * @param string $message プレフィックスなしの本文
	 */
	private function _ao_debug_log( $message ) {
		if( ! $this->_ao_debug_enabled() ) {
			return;
		}
		error_log( '[StillBE IQC Auto Optimize] ' . $message );
	}

}


// END of the File

