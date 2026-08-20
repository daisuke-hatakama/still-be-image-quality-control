<?php

namespace StillBE\Plugin\ImageQualityControl;


// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




/**
 * Temporary admin notices while automatic optimization leaves beta.
 *
 * Shown when the saved setting is disabled, or when it has never been saved
 * (the new default is enabled). Dismissing stores a cookie for 30 days.
 * Remove this class in the next version after the transition.
 *
 * @since 2.2.0
 */
class Auto_Optimize_Notice {


	const COOKIE_DISABLED   = 'sb_iqc_ao_notice_disabled';
	const COOKIE_DEFAULT_ON = 'sb_iqc_ao_notice_default_on';
	const COOKIE_DAYS       = 30;


	private static $shown_kind = '';


	public static function init() {

		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_footer',  array( __CLASS__, 'print_script' ) );

	}


	public static function render() {

		if( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$kind = self::notice_kind();

		if( empty( $kind ) ) {
			return;
		}

		$cookie = ( 'disabled' === $kind ) ? self::COOKIE_DISABLED : self::COOKIE_DEFAULT_ON;

		if( ! empty( $_COOKIE[ $cookie ] ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page='. STILLBE_IQ_PREFIX. 'setting-page' );

		$message = '<h2 style="margin-top: 0;">Image Quality Control | Still BE</h2>';

		if( 'disabled' === $kind ) {
			$message .= __( 'Automatic optimization is currently disabled. Would you like to enable it?', 'still-be-image-quality-control' );
			$message .= '<br>';
			$message .= __( 'Images will be analyzed automatically to compress them further.', 'still-be-image-quality-control' );
			$message .= '<br><br>';
			$message .= sprintf(
				/* translators: %s: URL of the Image Quality Control settings screen */
				__( 'You can change this from the <a href="%s">Image Quality Control settings</a>.', 'still-be-image-quality-control' ),
				esc_url( $settings_url )
			);
		} else {
			$message .= __( 'Automatic optimization is now enabled by default. Newly uploaded images will be optimized in the background.', 'still-be-image-quality-control' );
			$message .= '<br><br>';
			$message .= sprintf(
				/* translators: %s: URL of the Image Quality Control settings screen */
				__( 'You can change this from the <a href="%s">Image Quality Control settings</a>.', 'still-be-image-quality-control' ),
				esc_url( $settings_url )
			);
		}

		$classes = array(
			'stillbe-iqc-ao-notice',
			'stillbe-iqc-ao-notice--'. $kind,
		);

		if( function_exists( 'wp_admin_notice' ) ) {
			wp_admin_notice(
				$message,
				array(
					'type'               => 'info',
					'dismissible'        => true,
					'additional_classes' => $classes,
				)
			);
		} else {
			printf(
				'<div class="notice notice-info is-dismissible %1$s"><p>%2$s</p></div>',
				esc_attr( implode( ' ', $classes ) ),
				wp_kses(
					$message,
					array(
						'a' => array(
							'href' => array(),
						),
					)
				)
			);
		}

		self::$shown_kind = $kind;

	}


	public static function print_script() {

		if( empty( self::$shown_kind ) ) {
			return;
		}

		$js = sprintf(
			'(function(){document.addEventListener("click",function(event){var button=event.target.closest(".notice-dismiss");if(!button){return;}var notice=button.closest(".stillbe-iqc-ao-notice");if(!notice){return;}var name=notice.classList.contains("stillbe-iqc-ao-notice--disabled")?%1$s:%2$s;document.cookie=name+"=1; max-age="+%3$d+"; path=/; SameSite=Lax";});})();',
			wp_json_encode( self::COOKIE_DISABLED ),
			wp_json_encode( self::COOKIE_DEFAULT_ON ),
			self::COOKIE_DAYS * DAY_IN_SECONDS
		);

		if( function_exists( 'wp_print_inline_script_tag' ) ) {
			wp_print_inline_script_tag( $js );
		} else {
			echo '<script>'. $js. '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

	}


	/**
	 * @return string '' | 'disabled' | 'default_on'
	 */
	private static function notice_kind() {

		$settings = get_option( Setting::SETTING_NAME, null );

		if( ! is_array( $settings ) ) {
			return 'default_on';
		}

		$toggle  = ( isset( $settings['toggle'] ) && is_array( $settings['toggle'] ) ) ? $settings['toggle'] : array();
		$has_key = array_key_exists( 'enable-auto-optimize', $toggle );

		if( ! $has_key ) {
			return 'default_on';
		}

		if( empty( $toggle['enable-auto-optimize'] ) ) {
			return 'disabled';
		}

		return '';

	}


}
