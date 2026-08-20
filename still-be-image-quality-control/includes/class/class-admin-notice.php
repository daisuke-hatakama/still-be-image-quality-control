<?php

namespace StillBE\Plugin\ImageQualityControl;

// Do not allow direct access to the file.
if( ! defined( 'ABSPATH' ) ) {
	exit;
}




class Admin_Notice {


	const PREFIX                      = 'still-be-common__';
	const OPTION_NAME_PRODUCTS_DATA   = self::PREFIX. 'products';
	const OPTION_NAME_DONATE_LINKS    = self::PREFIX. 'donate-links';
	const OPTION_NAME_NOTES           = self::PREFIX. 'notes';

	const ACTION_HOOK_UPDATE_PRODUCTS = self::PREFIX. 'update_products';
	const ACTION_HOOK_UPDATE_DONATES  = self::PREFIX. 'update_donate_links';
	const ACTION_HOOK_UPDATE_NOTES    = self::PREFIX. 'update_notes';

	const API_URI_ALL_PRODUCTS        = 'https://api.still-be.com/wp/products.json';
	const API_URI_DONATE_LINKS        = 'https://api.still-be.com/wp/donate-links.json';
	const API_URI_NOTES               = 'https://api.still-be.com/wp/notes.json';
	const API_REQUEST_TIMEOUT         = 1;    // [sec]
	const API_REQUEST_TIMEOUT_CRON    = 30;   // [sec]

	const CACHE_LIFETIME_DB           =  7 * 24 * 3600;   // [sec]

	const DISMISS_TIME_DONATE_LINK    = 60 * 24 * 3600;   // [sec]
	const DISMISS_TIME_NOTE           = 28 * 24 * 3600;   // [sec]

	const DEFAULT_LOCALE              = 'en_US';


	private static $instance = null;

	private $is_subscribed   = null;
	private $is_doing_cron   = null;


	public static function init() {

		if( empty( self::$instance ) ) {
			self::$instance = new self;
		}

		return self::$instance;

	}


	// Constructer
	private function __construct() {

		add_action( 'all_admin_notices', [ $this, 'other_products_show' ] );
		add_action( 'all_admin_notices', [ $this, 'donate_links_show'   ] );
		add_action( 'all_admin_notices', [ $this, 'notes_show'          ] );

		// Add Cron Action
		add_action( self::ACTION_HOOK_UPDATE_PRODUCTS, array( $this, 'update_product_data' ) );
		add_action( self::ACTION_HOOK_UPDATE_DONATES,  array( $this, 'update_donate_links' ) );
		add_action( self::ACTION_HOOK_UPDATE_NOTES,    array( $this, 'update_notes'        ) );

	}


	public function other_products_show() {

		$is_already_shown = ! empty( $GLOBALS['still-be/plugin/common/other-products-shown'] );

		if( $is_already_shown ) {
			return;
		}

		$GLOBALS['still-be/plugin/common/other-products-shown'] = true;

		require_once( ABSPATH. 'wp-admin/includes/plugin.php' );

		$plugins  = get_plugins();
		$products = $this->get_products();

		$others = array();

		foreach( $products as $slug => $data ) {
			$is_not_installed = true;
			foreach( $plugins as $key => $plugin ) {
				if( 0 === strpos( $key, $slug. '/' ) ) {
					$is_not_installed = false;
					break;
				}
			}
			if( $is_not_installed ) {
				$others[] = $data;
			}
		}

		if( empty( $others ) ) {
			return;
		}

		echo '<aside class="sb-notice-other-plugins-wrapper">';
		echo   '<p>'. esc_html__( 'Also try these plugins!', 'still-be-image-quality-control' ). '</p>';
		echo   '<ul class="sb-notice-other-plugins">';
		foreach( $others as $other ) {
			echo '<li>';
			echo   '<a href="'. esc_url( admin_url( $other['download'] ) ). '">'. esc_html( $other['name'] ). '</a>';
			echo   '<span>'. esc_html( $other['outline'] ). '</span>';
			echo '</li>';
		}
		echo   '</ul>';
		echo '</aside>';

	}


	public function get_products() {

		$cache = get_option( self::OPTION_NAME_PRODUCTS_DATA, array() );

		if( empty( $cache['created'] ) || $cache['created'] + self::CACHE_LIFETIME_DB < time() ) {

			$get_scheduled_cron = wp_next_scheduled( self::ACTION_HOOK_UPDATE_PRODUCTS );

			// Set a Single WP-Cron
			if( empty( $get_scheduled_cron ) ) {
				wp_schedule_single_event(
					time() + 60,
					self::ACTION_HOOK_UPDATE_PRODUCTS
				);
			}

		}

		$products = isset( $cache['data'] ) ? $cache['data'] : array();

		$locale = get_locale();

		if( isset( $products[ $locale ] ) ) {
			return $products[ $locale ];
		}

		return isset( $products[ self::DEFAULT_LOCALE ] ) ? $products[ self::DEFAULT_LOCALE ] : array();

	}


	public function update_product_data() {

		if( null === $this->is_doing_cron ) {
			$this->is_doing_cron = wp_doing_cron();
		}

		$timeout  = $this->is_doing_cron ? self::API_REQUEST_TIMEOUT_CRON : self::API_REQUEST_TIMEOUT;

		$response = wp_remote_get( self::API_URI_ALL_PRODUCTS, compact( 'timeout' ) );
		$json     = wp_remote_retrieve_body( $response );
		$code     = wp_remote_retrieve_response_code( $response );

		if( is_wp_error( $json ) || 200 != $code ) {
			return;
		}

		$data = @json_decode( $json, true );

		if( empty( $data ) ) {
			return;
		}

		$created = time();

		$cache = compact( 'data', 'created' );

		update_option( self::OPTION_NAME_PRODUCTS_DATA, $cache, false );

	}


	public function donate_links_show() {

		$is_already_shown = ! empty( $GLOBALS['still-be/plugin/common/donate-link-shown'] );

		if( $is_already_shown ) {
			return;
		}

		$GLOBALS['still-be/plugin/common/donate-link-shown'] = true;

		$cache = get_option( self::OPTION_NAME_DONATE_LINKS, array() );

		if( empty( $cache['created'] ) || $cache['created'] + self::CACHE_LIFETIME_DB < time() ) {

			$get_scheduled_cron = wp_next_scheduled( self::ACTION_HOOK_UPDATE_DONATES );

			// Set a Single WP-Cron
			if( empty( $get_scheduled_cron ) ) {
				wp_schedule_single_event(
					time() + 60,
					self::ACTION_HOOK_UPDATE_DONATES
				);
			}

		}

		// Is user subscribed to this plugin?
		if( null === $this->is_subscribed ) {
			$this->is_subscribed = class_exists( __NAMESPACE__. '\\Order' ) && Order::init()->is_subscribed( STILLBE_IQ_SLUG );
		}

		if( $this->is_subscribed ) {
			return;
		}

		$donate_links = isset( $cache['data'] ) ? $cache['data'] : array();

		$locale = get_locale();

		if( isset( $donate_links [ $locale ] ) ) {
			echo wp_kses_post( $donate_links [ $locale ] );
		} else {
			echo wp_kses_post( isset( $donate_links [ self::DEFAULT_LOCALE ] ) ? $donate_links [ self::DEFAULT_LOCALE ] : '' );
		}

		echo '
			<script>
				(function($dismissLifetimeInSec){
					const notices = document.querySelectorAll(".sb-dismissible-notice");
					Array.prototype.forEach.call(notices, $notice => {
						const type   = ($notice.dataset.type || "none").replace(/[^\w\-]/g, "");
						const button = $notice.querySelector(".notice-dismiss");
						button.addEventListener("click", $e => {
							$notice?.remove();
							localStorage.setItem(`still-be-${type}-visibility`, "hidden");
							localStorage.setItem(`still-be-${type}-dismissed`,  new Date().getTime());
						}, false);
						const visibility = localStorage.getItem(`still-be-${type}-visibility`) || "visible";
						const dismissed  = localStorage.getItem(`still-be-${type}-dismissed`)  || 0;
						if(visibility === "hidden" && dismissed * 1 + $dismissLifetimeInSec * 1e3 > new Date().getTime()){
							$notice?.remove();
						}
					});
				})('. absint( self::DISMISS_TIME_DONATE_LINK ). ');
			</script>
		';

	}


	public function update_donate_links() {

		if( null === $this->is_doing_cron ) {
			$this->is_doing_cron = wp_doing_cron();
		}

		$timeout  = $this->is_doing_cron ? self::API_REQUEST_TIMEOUT_CRON : self::API_REQUEST_TIMEOUT;

		$response = wp_remote_get( self::API_URI_DONATE_LINKS, compact( 'timeout' ) );
		$json     = wp_remote_retrieve_body( $response );
		$code     = wp_remote_retrieve_response_code( $response );

		if( is_wp_error( $json ) || 200 != $code ) {
			return;
		}

		$data = @json_decode( $json, true );

		if( empty( $data ) ) {
			return;
		}

		$created = time();

		$cache = compact( 'data', 'created' );

		update_option( self::OPTION_NAME_DONATE_LINKS, $cache, false );

	}


	public function notes_show() {

		$cache = get_option( self::OPTION_NAME_NOTES, array() );

		if( empty( $cache['created'] ) || $cache['created'] + self::CACHE_LIFETIME_DB < time() ) {

			$get_scheduled_cron = wp_next_scheduled( self::ACTION_HOOK_UPDATE_NOTES );

			// Set a Single WP-Cron
			if( empty( $get_scheduled_cron ) ) {
				wp_schedule_single_event(
					time() + 60,
					self::ACTION_HOOK_UPDATE_NOTES
				);
			}

		}

		$locale = get_locale();

		$notes  = empty( $cache['data'] ) ? array() : $cache['data'];

		$is_already_shown_common = ! empty( $GLOBALS['still-be/plugin/common/notes-shown/common'] );
		$is_already_shown_this   = ! empty( $GLOBALS['still-be/plugin/common/notes-shown/image-quality-control'] );

		if( ! $is_already_shown_common ) {

			$GLOBALS['still-be/plugin/common/notes-shown/common'] = true;

			if( ! empty( $notes['common'] ) ) {
				$note_common = isset( $notes['common'][ $locale ] ) ? $notes['common'][ $locale ] : $notes['common'][ self::DEFAULT_LOCALE ];
				echo '<div class="notice notice-info is-dismissible sb-dismissible-notice" data-type="note-common">';
				echo   '<h2 style="margin-top: 1em;">'. $note_common['title']. '</h2>';
				echo   '<ul class="sb-notice-note-common">';
				foreach( $note_common['notes'] as $_note ) {
					echo '<li>';
					echo   wp_kses_post( $_note );
					echo '</li>';
				}
				echo   '</ul>';
				echo   '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
				echo '</div>';
			}

		}

		if( ! $is_already_shown_this ) {

			$GLOBALS['still-be/plugin/common/notes-shown/image-quality-control'] = true;

			if( ! empty( $notes['image-quality-control'] ) ) {
				$note_this = isset( $notes['image-quality-control'][ $locale ] ) ? $notes['image-quality-control'][ $locale ] : $notes['image-quality-control'][ self::DEFAULT_LOCALE ];
				echo '<div class="notice notice-info is-dismissible sb-dismissible-notice" data-type="note-image-quality-control">';
				echo   '<h2 style="margin-top: 1em;">'. $note_this['title']. '</h2>';
				echo   '<ul class="sb-notice-note-this">';
				foreach( $note_this['notes'] as $_note ) {
					echo '<li>';
					echo   wp_kses_post( $_note );
					echo '</li>';
				}
				echo   '</ul>';
				echo   '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
				echo '</div>';
			}

		}

		echo '
			<script>
				(function($dismissLifetimeInSec){
					const notices = document.querySelectorAll(".sb-dismissible-notice");
					Array.prototype.forEach.call(notices, $notice => {
						const type   = ($notice.dataset.type || "none").replace(/[^\w\-]/g, "");
						const button = $notice.querySelector(".notice-dismiss");
						button.addEventListener("click", $e => {
							$notice?.remove();
							localStorage.setItem(`still-be-${type}-visibility`, "hidden");
							localStorage.setItem(`still-be-${type}-dismissed`,  new Date().getTime());
						}, false);
						const visibility = localStorage.getItem(`still-be-${type}-visibility`) || "visible";
						const dismissed  = localStorage.getItem(`still-be-${type}-dismissed`)  || 0;
						if(visibility === "hidden" && dismissed * 1 + $dismissLifetimeInSec * 1e3 > new Date().getTime()){
							$notice?.remove();
						}
					});
				})('. absint( self::DISMISS_TIME_NOTE ). ');
			</script>
		';

	}


	public function update_notes() {

		if( null === $this->is_doing_cron ) {
			$this->is_doing_cron = wp_doing_cron();
		}

		$timeout  = $this->is_doing_cron ? self::API_REQUEST_TIMEOUT_CRON : self::API_REQUEST_TIMEOUT;

		$response = wp_remote_get( self::API_URI_NOTES, compact( 'timeout' ) );
		$json     = wp_remote_retrieve_body( $response );
		$code     = wp_remote_retrieve_response_code( $response );

		if( is_wp_error( $json ) || 200 != $code ) {
			return;
		}

		$data = @json_decode( $json, true );

		if( empty( $data ) ) {
			return;
		}

		$created = time();

		$cache = compact( 'data', 'created' );

		update_option( self::OPTION_NAME_NOTES, $cache, false );

	}


}



