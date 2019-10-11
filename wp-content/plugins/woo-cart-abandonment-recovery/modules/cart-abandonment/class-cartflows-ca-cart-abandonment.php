<?php
/**
 * Cart Abandonment
 *
 * @package Woocommerce-Cart-Abandonment-Recovery
 */

/**
 * Cart abandonment tracking class.
 */
class Cartflows_Ca_Cart_Abandonment {



	/**
	 * Member Variable
	 *
	 * @var object instance
	 */
	private static $instance;

	/**
	 *  Initiator
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 *  Constructor function that initializes required actions and hooks.
	 */
	public function __construct() {

		$this->define_cart_abandonment_constants();

		// Adding menu to view cart abandonment report.
		add_action( 'admin_menu', array( $this, 'abandoned_cart_tracking_menu' ), 999 );

		// Adding the styles and scripts for the cart abandonment.
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_cart_abandonment_script' ), 20 );

		if ( wcf_ca()->utils->is_cart_abandonment_tracking_enabled() && ! isset( $_COOKIE['wcf_ca_skip_track_data'] ) ) {

			// Add script to track the cart abandonment.
			add_action( 'woocommerce_after_checkout_form', array( $this, 'cart_abandonment_tracking_script' ) );

			// Store user details from the current checkout page.
			add_action( 'wp_ajax_cartflows_save_cart_abandonment_data', array( $this, 'save_cart_abandonment_data' ) );
			add_action( 'wp_ajax_nopriv_cartflows_save_cart_abandonment_data', array( $this, 'save_cart_abandonment_data' ) );

			// GDPR actions.
			add_action( 'wp_ajax_cartflows_skip_cart_tracking_gdpr', array( $this, 'skip_cart_tracking_by_gdpr' ) );
			add_action( 'wp_ajax_nopriv_cartflows_skip_cart_tracking_gdpr', array( $this, 'skip_cart_tracking_by_gdpr' ) );

			// Delete the stored cart abandonment data once order gets created.
			add_action( 'woocommerce_new_order', array( $this, 'delete_cart_abandonment_data' ) );
			add_action( 'woocommerce_thankyou', array( $this, 'delete_cart_abandonment_data' ) );
			add_action( 'woocommerce_order_status_changed', array( $this, 'wcf_ca_update_order_status' ), 999, 3 );

			// Adding filter to restore the data if recreating abandonment order.
			add_filter( 'wp', array( $this, 'restore_cart_abandonment_data' ), 10 );
			add_filter( 'wp', array( $this, 'unsubscribe_cart_abandonment_emails' ), 10 );

			add_action( 'wp_ajax_wcf_ca_preview_email_send', array( $this, 'send_preview_email' ) );

			$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING );
			if ( WCF_CA_PAGE_NAME === $page ) {
				// Adding filter to add new button to add custom fields.
				add_filter( 'mce_buttons', array( $this, 'wcf_filter_mce_button' ) );
				add_filter( 'mce_external_plugins', array( $this, 'wcf_filter_mce_plugin' ), 9 );
			}

			add_filter( 'cron_schedules', array( $this, 'cartflows_ca_update_order_status_action' ) );

			// Schedule an action if it's not already scheduled.
			if ( ! wp_next_scheduled( 'cartflows_ca_update_order_status_action' ) ) {
				wp_schedule_event( time(), 'every_fifteen_minutes', 'cartflows_ca_update_order_status_action' );
			}
			add_action( 'cartflows_ca_update_order_status_action', array( $this, 'update_order_status' ) );

		}

	}


	/**
	 * Update the Order status.
	 *
	 * @param integer $order_id order id.
	 * @param string  $old_order_status old order status.
	 * @param string  $new_order_status new order status.
	 */
	public function wcf_ca_update_order_status( $order_id, $old_order_status, $new_order_status ) {

		$acceptable_order_statuses = array( 'completed', 'processing', 'failed' );

		if ( $order_id && in_array( $new_order_status, $acceptable_order_statuses, true ) ) {

			$order = wc_get_order( $order_id );

			$order_email   = $order->get_billing_email();
			$captured_data = ( WCF_CART_FAILED_ORDER === $new_order_status ) ? $this->get_tracked_data_without_status( $order_email ) : $this->get_captured_data_by_email( $order_email );

			if ( $captured_data && is_object( $captured_data ) ) {
				$capture_status = $captured_data->order_status;
				global $wpdb;
				$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;

				if ( ( WCF_CART_NORMAL_ORDER === $capture_status ) ) {
					$wpdb->delete( $cart_abandonment_table, array( 'session_id' => sanitize_key( $captured_data->session_id ) ) );
				}

				if ( ( WCF_CART_ABANDONED_ORDER === $capture_status || WCF_CART_LOST_ORDER === $capture_status ) ) {
					$wpdb->update( $cart_abandonment_table, array( 'order_status' => WCF_CART_COMPLETED_ORDER ), array( 'session_id' => sanitize_key( $captured_data->session_id ) ) );
					$this->trigger_zapier_webhook( $captured_data->session_id, WCF_CART_COMPLETED_ORDER );
					$note = __( 'This order was abandoned & subsequently recovered.', 'woo-cart-abandonment-recovery' );
					$order->add_order_note( $note );
					$order->save();
				}

				if ( WCF_CART_COMPLETED_ORDER === $capture_status ) {
					// Revert the complete order status to abandoned when payment failed.
					$wpdb->update( $cart_abandonment_table, array( 'order_status' => WCF_CART_ABANDONED_ORDER ), array( 'session_id' => sanitize_key( $captured_data->session_id ) ) );
				}
			}
		}

	}

	/**
	 *  Send preview emails.
	 */
	public function send_preview_email() {

		check_ajax_referer( WCF_EMAIL_TEMPLATES_NONCE, 'security' );
		$mail_result = $this->send_email_templates( null, true );
		if ( $mail_result ) {
			wp_send_json_success( __( 'Mail has been sent successfully!', 'woo-cart-abandonment-recovery' ) );
		} else {
			wp_send_json_error( __( 'Mail sending failed!', 'woo-cart-abandonment-recovery' ) );
		}
	}


	/**
	 *  Delete tracked data and set cookie for the user.
	 */
	public function skip_cart_tracking_by_gdpr() {
		check_ajax_referer( 'cartflows_skip_cart_tracking_gdpr', 'security' );

		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;

		$session_id = WC()->session->get( 'wcf_session_id' );
		if ( $session_id ) {
			$wpdb->delete( $cart_abandonment_table, array( 'session_id' => sanitize_key( $session_id ) ) );
		}

		setcookie( 'wcf_ca_skip_track_data', 'true', 0, '/' );
		wp_send_json_success();

	}


	/**
	 * Create custom schedule.
	 *
	 * @param array $schedules schedules.
	 * @return mixed
	 */
	function cartflows_ca_update_order_status_action( $schedules ) {

		/**
		 * Add filter to change the cron interval time to uodate order status.
		 */
		$cron_time = apply_filters( 'woo_ca_update_order_cron_interval', 15 );

		$schedules['every_fifteen_minutes'] = array(
			'interval' => $cron_time * MINUTE_IN_SECONDS,
			'display'  => __( 'Every Fifteen Minutes', 'woo-cart-abandonment-recovery' ),
		);

		return $schedules;
	}

	/**
	 *  Generate new coupon code for abandoned cart.
	 *
	 * @param string $discount_type discount type.
	 * @param float  $amount amount.
	 * @param string $expiry expiry.
	 * @param string $free_shipping is free shipping.
	 * @param string $individual_use use coupon individual.
	 */
	function generate_coupon_code( $discount_type, $amount, $expiry = '', $free_shipping = 'no', $individual_use = 'no' ) {

		$coupon_code = '';

			$coupon_code = wp_generate_password( 8, false, false );

			$coupon = array(
				'post_title'   => $coupon_code,
				'post_content' => '',
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_type'    => 'shop_coupon',
			);

			$new_coupon_id = wp_insert_post( $coupon );

			update_post_meta( $new_coupon_id, 'discount_type', $discount_type );
			update_post_meta( $new_coupon_id, 'description', 'This coupon is for abandoned cart email templates.' );
			update_post_meta( $new_coupon_id, 'coupon_amount', $amount );
			update_post_meta( $new_coupon_id, 'individual_use', $individual_use );
			update_post_meta( $new_coupon_id, 'product_ids', '' );
			update_post_meta( $new_coupon_id, 'exclude_product_ids', '' );
			update_post_meta( $new_coupon_id, 'usage_limit', '1' );
			update_post_meta( $new_coupon_id, 'date_expires', $expiry );
			update_post_meta( $new_coupon_id, 'apply_before_tax', 'yes' );
			update_post_meta( $new_coupon_id, 'free_shipping', $free_shipping );

			return $coupon_code;
	}

	/**
	 *  Unsubscribe the user from the mailing list.
	 */
	function unsubscribe_cart_abandonment_emails() {

		$unsubscribe  = filter_input( INPUT_GET, 'unsubscribe', FILTER_VALIDATE_BOOLEAN );
		$wcf_ac_token = filter_input( INPUT_GET, 'wcf_ac_token', FILTER_SANITIZE_STRING );
		if ( $unsubscribe && $this->is_valid_token( $wcf_ac_token ) ) {
			$token_data = $this->wcf_decode_token( $wcf_ac_token );
			if ( isset( $token_data['wcf_session_id'] ) ) {
				$session_id = $token_data['wcf_session_id'];

				global $wpdb;
				$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
				$wpdb->update(
					$cart_abandonment_table,
					array( 'unsubscribed' => true ),
					array( 'session_id' => $session_id )
				);
				wp_die( __( 'You have successfully unsubscribed from our email list.', 'woo-cart-abandonment-recovery' ), __( 'Unsubscribed', 'woo-cart-abandonment-recovery' ) );

			}
		}

	}


	/**
	 * Link JS to mce button.
	 *
	 * @param  array $plugins mce pluggins.
	 * @return mixed
	 */
	function wcf_filter_mce_plugin( $plugins ) {
		$plugins['cartflows_ac'] = CARTFLOWS_CA_URL . 'admin/assets/js/admin-mce.js';
		return $plugins;
	}

	/**
	 * Register button.
	 *
	 * @param  array $buttons mce buttons.
	 * @return mixed
	 */
	function wcf_filter_mce_button( $buttons ) {
		array_push( $buttons, 'cartflows_ac' );
		return $buttons;
	}

	/**
	 *  Initialise all the constants
	 */
	function define_cart_abandonment_constants() {
		define( 'CARTFLOWS_CART_ABANDONMENT_TRACKING_DIR', CARTFLOWS_CA_DIR . 'modules/cart-abandonment/' );
		define( 'CARTFLOWS_CART_ABANDONMENT_TRACKING_URL', CARTFLOWS_CA_URL . 'modules/cart-abandonment/' );
		define( 'WCF_CART_ABANDONED_ORDER', 'abandoned' );
		define( 'WCF_CART_COMPLETED_ORDER', 'completed' );
		define( 'WCF_CART_LOST_ORDER', 'lost' );
		define( 'WCF_CART_NORMAL_ORDER', 'normal' );
		define( 'WCF_CART_FAILED_ORDER', 'failed' );
		define( 'CARTFLOWS_ZAPIER_ACTION_AFTER_TIME', 1800 );

		define( 'WCF_ACTION_ABANDONED_CARTS', 'abandoned_carts' );
		define( 'WCF_ACTION_RECOVERED_CARTS', 'recovered_carts' );
		define( 'WCF_ACTION_LOST_CARTS', 'lost_carts' );
		define( 'WCF_ACTION_SETTINGS', 'settings' );
		define( 'WCF_ACTION_REPORTS', 'reports' );

		define( 'WCF_SUB_ACTION_REPORTS_VIEW', 'view' );
		define( 'WCF_SUB_ACTION_REPORTS_RESCHEDULE', 'reschedule' );

		define( 'WCF_DEFAULT_CUT_OFF_TIME', 15 );
		define( 'WCF_DEFAULT_COUPON_AMOUNT', 10 );

		define( 'WCF_CA_DATETIME_FORMAT', 'Y-m-d H:i:s' );
	}

	/**
	 * Restore cart abandonemnt data on checkout page.
	 *
	 * @param  array $fields checkout fields values.
	 * @return array field values
	 */
	function restore_cart_abandonment_data( $fields = array() ) {
		global $woocommerce;
		$result = array();
		// Restore only of user is not logged in.
		$wcf_ac_token = filter_input( INPUT_GET, 'wcf_ac_token', FILTER_SANITIZE_STRING );
		if ( $this->is_valid_token( $wcf_ac_token ) ) {

			// Check if `wcf_restore_token` exists to restore cart data.
			$token_data = $this->wcf_decode_token( $wcf_ac_token );
			if ( is_array( $token_data ) && isset( $token_data['wcf_session_id'] ) ) {
				$result = $this->get_checkout_details( $token_data['wcf_session_id'] );
				if ( isset( $result ) && WCF_CART_ABANDONED_ORDER === $result->order_status || WCF_CART_LOST_ORDER === $result->order_status ) {
					WC()->session->set( 'wcf_session_id', $token_data['wcf_session_id'] );
				}
			}

			if ( $result ) {
				$cart_content = unserialize( $result->cart_contents );

				if ( $cart_content ) {
					$woocommerce->cart->empty_cart();
					wc_clear_notices();
					foreach ( $cart_content as $cart_item ) {

						// Skip bundled products when added main product.
						if ( isset( $cart_item['bundled_by'] ) ) {
							continue;
						}

						$id  = $cart_item['product_id'];
						$qty = $cart_item['quantity'];

						$cart_item_data = array();
						if ( isset( $cart_item['cartflows_bump'] ) ) {
							$cart_item_data['cartflows_bump'] = $cart_item['cartflows_bump'];
						}

						if ( isset( $cart_item['custom_price'] ) ) {
							$cart_item_data['custom_price'] = $cart_item['custom_price'];
						}

						$woocommerce->cart->add_to_cart( $id, $qty, $cart_item['variation_id'], array(), $cart_item_data );
					}

					if ( isset( $token_data['wcf_coupon_code'] ) && ! $woocommerce->cart->applied_coupons ) {
						$woocommerce->cart->add_discount( $token_data['wcf_coupon_code'] );
					}
				}
				$other_fields = unserialize( $result->other_fields );

				$parts = explode( ',', $other_fields['wcf_location'] );
				if ( count( $parts ) > 1 ) {
					$country = $parts[0];
					$city    = trim( $parts[1] );
				} else {
					$country = $parts[0];
					$city    = '';
				}

				foreach ( $other_fields as $key => $value ) {
					$key           = str_replace( 'wcf_', '', $key );
					$_POST[ $key ] = sanitize_text_field( $value );
				}
				$_POST['billing_first_name'] = sanitize_text_field( $other_fields['wcf_first_name'] );
				$_POST['billing_last_name']  = sanitize_text_field( $other_fields['wcf_last_name'] );
				$_POST['billing_phone']      = sanitize_text_field( $other_fields['wcf_phone_number'] );
				$_POST['billing_email']      = sanitize_email( $result->email );
				$_POST['billing_city']       = sanitize_text_field( $city );
				$_POST['billing_country']    = sanitize_text_field( $country );

			}
		}
		return $fields;
	}
	/**
	 * Load cart abandonemnt tracking script.
	 *
	 * @return void
	 */
	function cart_abandonment_tracking_script() {

		$wcf_ca_ignore_users = get_option( 'wcf_ca_ignore_users' );
		$current_user        = wp_get_current_user();
		$roles               = $current_user->roles;
		$role                = array_shift( $roles );
		if ( ! empty( $wcf_ca_ignore_users ) ) {
			foreach ( $wcf_ca_ignore_users as $user ) {
				$user = lcfirst( $user );
				if ( $role === $user ) {
					return;
				}
			}
		}

		global $post;
		wp_enqueue_script(
			'cartflows-cart-abandonment-tracking',
			CARTFLOWS_CART_ABANDONMENT_TRACKING_URL . 'assets/js/cart-abandonment-tracking.js',
			array( 'jquery' ),
			CARTFLOWS_CA_VER,
			true
		);

		$vars = array(
			'ajaxurl'                   => admin_url( 'admin-ajax.php' ),
			'_nonce'                    => wp_create_nonce( 'cartflows_save_cart_abandonment_data' ),
			'_gdpr_nonce'               => wp_create_nonce( 'cartflows_skip_cart_tracking_gdpr' ),
			'_post_id'                  => get_the_ID(),
			'_show_gdpr_message'        => ( wcf_ca()->utils->is_gdpr_enabled() && ! isset( $_COOKIE['wcf_ca_skip_track_data'] ) ),
			'_gdpr_message'             => get_option( 'wcf_ca_gdpr_message' ),
			'_gdpr_nothanks_msg'        => __( 'No Thanks', 'woo-cart-abandonment-recovery' ),
			'_gdpr_after_no_thanks_msg' => __( 'You won\'t receive further emails from us, thank you!', 'woo-cart-abandonment-recovery' ),
			'enable_ca_tracking'        => true,
		);

		wp_localize_script( 'cartflows-cart-abandonment-tracking', 'CartFlowsProCAVars', $vars );

	}

	/**
	 * Validate the token before use.
	 *
	 * @param  string $token token form the url.
	 * @return bool
	 */
	function is_valid_token( $token ) {
		$is_valid   = false;
		$token_data = $this->wcf_decode_token( $token );
		if ( is_array( $token_data ) && array_key_exists( 'wcf_session_id', $token_data ) ) {
			$result = $this->get_checkout_details( $token_data['wcf_session_id'] );
			if ( isset( $result ) ) {
				$is_valid = true;
			}
		}
		return $is_valid;
	}

	/**
	 * Execute Zapier webhook for further action inside Zapier.
	 *
	 * @since 1.0.0
	 */
	function update_order_status() {

		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$email_history_table    = $wpdb->prefix . CARTFLOWS_CA_EMAIL_HISTORY_TABLE;
		$minutes                = wcf_ca()->utils->get_cart_abandonment_tracking_cut_off_time();

		/**
		 * Delete abandoned cart orders if empty.
		 */
		$this->delete_empty_abandoned_order();

		$wp_current_datetime = current_time( WCF_CA_DATETIME_FORMAT );
		$abandoned_ids       = $wpdb->get_results(
             $wpdb->prepare('SELECT `session_id` FROM `' . $cart_abandonment_table . '` WHERE `order_status` = %s AND ADDDATE( `time`, INTERVAL %d MINUTE) <= %s', WCF_CART_NORMAL_ORDER, $minutes, $wp_current_datetime ), ARRAY_A // phpcs:ignore
		);

		foreach ( $abandoned_ids as $session_id ) {

			if ( isset( $session_id['session_id'] ) ) {

				$current_session_id = $session_id['session_id'];
				$this->schedule_emails( $current_session_id );

				$coupon_code               = '';
				$wcf_ca_coupon_code_status = get_option( 'wcf_ca_coupon_code_status' );

				if ( 'on' === $wcf_ca_coupon_code_status ) {
					$discount_type        = get_option( 'wcf_ca_discount_type' );
					$discount_type        = $discount_type ? $discount_type : 'percent';
					$amount               = get_option( 'wcf_ca_coupon_amount' );
					$amount               = $amount ? $amount : WCF_DEFAULT_COUPON_AMOUNT;
					$coupon_expiry_date   = get_option( 'wcf_ca_coupon_expiry' );
					$coupon_expiry_unit   = get_option( 'wcf_ca_coupon_expiry_unit' );
					$coupon_expiry_date   = $coupon_expiry_date ? strtotime( $wp_current_datetime . ' +' . $coupon_expiry_date . ' ' . $coupon_expiry_unit ) : '';
					$free_shipping_coupon = get_option( 'wcf_ca_free_shipping_coupon' );
					$free_shipping        = ( isset( $free_shipping_coupon ) && ( $free_shipping_coupon->meta_value ) ) ? 'yes' : 'no';

					$individual_use_only = get_option( 'wcf_ca_individual_use_only' );
					$individual_use      = ( isset( $individual_use_only ) && ( $individual_use_only->meta_value ) ) ? 'yes' : 'no';

					$coupon_code = $this->generate_coupon_code( $discount_type, $amount, $coupon_expiry_date, $free_shipping, $individual_use );
				}

				$wpdb->update(
					$cart_abandonment_table,
					array(
						'order_status' => WCF_CART_ABANDONED_ORDER,
						'coupon_code'  => $coupon_code,
					),
					array( 'session_id' => $current_session_id )
				);

				$this->trigger_zapier_webhook( $current_session_id, WCF_CART_ABANDONED_ORDER );
			}
		}

		/**
		 * Send scheduled emails.
		 */
		$this->send_emails_to_callback();

		// Update order status to lost after campaign complete.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $cart_abandonment_table as ca SET order_status = 'lost' WHERE ca.order_status = %s AND DATE(ca.time) <= DATE_SUB( %s , INTERVAL 30 DAY)
              AND ( (SELECT count(*) FROM $email_history_table WHERE ca_session_id = ca.session_id ) = 
              (SELECT count(*) FROM $email_history_table WHERE ca_session_id = ca.session_id AND email_sent = 1) )",
				WCF_CART_ABANDONED_ORDER,
				$wp_current_datetime
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	}

	/**
	 * Send zapier webhook.
	 *
	 * @param string $session_id   session id.
	 * @param string $order_status order status.
	 */
	function trigger_zapier_webhook( $session_id, $order_status ) {

		$checkout_details = $this->get_checkout_details( $session_id );

		if ( $checkout_details && wcf_ca()->utils->is_zapier_trigger_enabled() ) {
			$trigger_details = array();
			$url             = get_option( 'wcf_ca_zapier_cart_abandoned_webhook' );

			$other_details                       = unserialize( $checkout_details->other_fields );
			$trigger_details['first_name']       = $other_details['wcf_first_name'];
			$trigger_details['last_name']        = $other_details['wcf_last_name'];
			$trigger_details['phone_number']     = $other_details['wcf_phone_number'];
			$trigger_details['billing_address']  = $other_details['wcf_billing_company'] . ' ' . $other_details['wcf_billing_address_1'] . ', ' . $other_details['wcf_billing_state'] . ', ' . $other_details['wcf_location'] . ', ' . $other_details['wcf_billing_postcode'];
			$trigger_details['billing_address']  = trim( $trigger_details['billing_address'], ', ' );
			$trigger_details['shipping_address'] = $other_details['wcf_shipping_company'] . ' ' . $other_details['wcf_shipping_address_1'] . ', ' . $other_details['wcf_shipping_city'] . ', ' . $other_details['wcf_shipping_state'] . ', ' . $other_details['wcf_shipping_postcode'];
			$trigger_details['shipping_address'] = trim( $trigger_details['shipping_address'], ', ' );
			$trigger_details['email']            = $checkout_details->email;
			$token_data                          = array( 'wcf_session_id' => $checkout_details->session_id );
			$trigger_details['checkout_url']     = $this->get_checkout_url( $checkout_details->checkout_id, $token_data );
			$trigger_details['product_names']    = $this->get_comma_separated_products( $checkout_details->cart_contents );
			$trigger_details['coupon_code']      = $checkout_details->coupon_code;
			$trigger_details['order_status']     = $order_status;
			$trigger_details['cart_total']       = $checkout_details->cart_total;

			$parameters = http_build_query( $trigger_details );
			$args       = array(
				'body'        => $parameters,
				'timeout'     => '5',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(),
				'cookies'     => array(),
			);
			wp_remote_post( $url, $args );

		}
	}


	/**
	 * Sanitize post array.
	 *
	 * @return array
	 */
	function sanitize_post_data() {

		$input_post_values = array(
			'wcf_billing_company'     => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_email'               => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_EMAIL,
			),
			'wcf_billing_address_1'   => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_billing_address_2'   => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_billing_state'       => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_billing_postcode'    => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_first_name' => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_last_name'  => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_company'    => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_country'    => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_address_1'  => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_address_2'  => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_city'       => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_state'      => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_shipping_postcode'   => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_order_comments'      => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_name'                => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_surname'             => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_phone'               => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_country'             => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_city'                => array(
				'default'  => '',
				'sanitize' => FILTER_SANITIZE_STRING,
			),
			'wcf_post_id'             => array(
				'default'  => 0,
				'sanitize' => FILTER_SANITIZE_NUMBER_INT,
			),
		);

		$sanitized_post = array();
		foreach ( $input_post_values as $key => $input_post_value ) {

			if ( isset( $_POST[ $key ] ) ) {
				$sanitized_post[ $key ] = filter_input( INPUT_POST, $key, $input_post_value['sanitize'] );
			} else {
				$sanitized_post[ $key ] = $input_post_value['default'];
			}
		}
		return $sanitized_post;

	}


	/**
	 * Save cart abandonment tracking and schedule new event.
	 *
	 * @since 1.0.0
	 */
	function save_cart_abandonment_data() {

		check_ajax_referer( 'cartflows_save_cart_abandonment_data', 'security' );
		$post_data = $this->sanitize_post_data();
		if ( isset( $post_data['wcf_email'] ) ) {
			$user_email = sanitize_email( $post_data['wcf_email'] );
			global $wpdb;
			$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;

			// Verify if email is already exists.
			$session_id               = WC()->session->get( 'wcf_session_id' );
			$session_checkout_details = null;
			if ( isset( $session_id ) ) {
				$session_checkout_details = $this->get_checkout_details( $session_id );
			} else {
				$session_checkout_details = $this->get_checkout_details_by_email( $user_email );
				if ( $session_checkout_details ) {
					$session_id = $session_checkout_details->session_id;
					WC()->session->set( 'wcf_session_id', $session_id );
				} else {
					$session_id = md5( uniqid( rand(), true ) );
				}
			}

			$checkout_details = $this->prepare_abandonment_data( $post_data );

			if ( isset( $session_checkout_details ) && WCF_CART_COMPLETED_ORDER === $session_checkout_details->order_status ) {
				WC()->session->__unset( 'wcf_session_id' );
				$session_id = md5( uniqid( rand(), true ) );
			}

			if ( ( ! is_null( $session_id ) ) && ! is_null( $session_checkout_details ) ) {

				// Updating row in the Database where users Session id = same as prevously saved in Session.
				$wpdb->update(
					$cart_abandonment_table,
					$checkout_details,
					array( 'session_id' => $session_id )
				);

			} else {

				$checkout_details['session_id'] = sanitize_text_field( $session_id );
				// Inserting row into Database.
				$wpdb->insert(
					$cart_abandonment_table,
					$checkout_details
				);

				// Storing session_id in WooCommerce session.
				WC()->session->set( 'wcf_session_id', $session_id );

			}

			wp_send_json_success();
		}
	}


	/**
	 * Prepare cart data to save for abandonment.
	 *
	 * @param array $post_data post data.
	 * @return array
	 */
	function prepare_abandonment_data( $post_data = array() ) {

		if ( function_exists( 'WC' ) ) {

			// Retrieving cart total value and currency.
			$cart_total = WC()->cart->total;

			// Retrieving cart products and their quantities.
			$products     = WC()->cart->get_cart();
			$current_time = current_time( WCF_CA_DATETIME_FORMAT );
			$other_fields = array(
				'wcf_billing_company'     => $post_data['wcf_billing_company'],
				'wcf_billing_address_1'   => $post_data['wcf_billing_address_1'],
				'wcf_billing_address_2'   => $post_data['wcf_billing_address_2'],
				'wcf_billing_state'       => $post_data['wcf_billing_state'],
				'wcf_billing_postcode'    => $post_data['wcf_billing_postcode'],
				'wcf_shipping_first_name' => $post_data['wcf_shipping_first_name'],
				'wcf_shipping_last_name'  => $post_data['wcf_shipping_last_name'],
				'wcf_shipping_company'    => $post_data['wcf_shipping_company'],
				'wcf_shipping_country'    => $post_data['wcf_shipping_country'],
				'wcf_shipping_address_1'  => $post_data['wcf_shipping_address_1'],
				'wcf_shipping_address_2'  => $post_data['wcf_shipping_address_2'],
				'wcf_shipping_city'       => $post_data['wcf_shipping_city'],
				'wcf_shipping_state'      => $post_data['wcf_shipping_state'],
				'wcf_shipping_postcode'   => $post_data['wcf_shipping_postcode'],
				'wcf_order_comments'      => $post_data['wcf_order_comments'],
				'wcf_first_name'          => $post_data['wcf_name'],
				'wcf_last_name'           => $post_data['wcf_surname'],
				'wcf_phone_number'        => $post_data['wcf_phone'],
				'wcf_location'            => $post_data['wcf_country'] . ', ' . $post_data['wcf_city'],
			);

			$checkout_details = array(
				'email'         => $post_data['wcf_email'],
				'cart_contents' => serialize( $products ),
				'cart_total'    => sanitize_text_field( $cart_total ),
				'time'          => sanitize_text_field( $current_time ),
				'other_fields'  => serialize( $other_fields ),
				'checkout_id'   => $post_data['wcf_post_id'],
			);
		}
		return $checkout_details;
	}

	/**
	 * Deletes cart abandonment tracking and scheduled event.
	 *
	 * @param int $order_id Order ID.
	 * @since 1.0.0
	 */
	function delete_cart_abandonment_data( $order_id ) {

		$acceptable_order_statuses = array( 'completed', 'processing' );
		$order                     = wc_get_order( $order_id );
		$order_status              = $order->get_status();
		if ( ! in_array( $order_status, $acceptable_order_statuses, true ) ) {
			// Proceed if order status in completed or processing.
			return;
		}

		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$email_history_table    = $wpdb->prefix . CARTFLOWS_CA_EMAIL_HISTORY_TABLE;

		if ( isset( WC()->session ) ) {
			$session_id = WC()->session->get( 'wcf_session_id' );

			if ( isset( $session_id ) ) {
				$checkout_details = $this->get_checkout_details( $session_id );

				$has_mail_sent = count( $this->fetch_scheduled_emails( $session_id, true ) );

				if ( ! $has_mail_sent ) {
					$wpdb->delete( $cart_abandonment_table, array( 'session_id' => sanitize_key( $session_id ) ) );
				} else {
					if ( $checkout_details && ( WCF_CART_ABANDONED_ORDER === $checkout_details->order_status || WCF_CART_LOST_ORDER === $checkout_details->order_status ) ) {

						// Skip Future email sending..
						$wpdb->update(
							$email_history_table,
							array( 'email_sent' => -1 ),
							array(
								'ca_session_id' => $session_id,
								'email_sent'    => 0,
							)
						);

						// Update order status.
						$wpdb->update(
							$cart_abandonment_table,
							array(
								'order_status' => WCF_CART_COMPLETED_ORDER,
							),
							array(
								'session_id' => $session_id,
							)
						);

						$this->trigger_zapier_webhook( $session_id, WCF_CART_COMPLETED_ORDER );

						$order = wc_get_order( $order_id );
						$note  = __( 'This order was abandoned & subsequently recovered.', 'woo-cart-abandonment-recovery' );
						$order->add_order_note( $note );
						$order->save();

					} elseif ( WCF_CART_COMPLETED_ORDER !== $checkout_details->order_status ) {
						// Normal checkout.

						$billing_email = filter_input( INPUT_POST, 'billing_email', FILTER_SANITIZE_EMAIL );

						if ( $billing_email ) {
							$order_data = $this->get_captured_data_by_email( $billing_email );

							if ( ! is_null( $order_data ) ) {
								$existing_cart_contents = unserialize( $order_data->cart_contents );
								$order_cart_contents    = unserialize( $checkout_details->cart_contents );
								$existing_cart_products = array_keys( (array) $existing_cart_contents );
								$order_cart_products    = array_keys( (array) $order_cart_contents );
								if ( $this->check_if_similar_cart( $existing_cart_products, $order_cart_products ) ) {
									$wpdb->update(
										$cart_abandonment_table,
										array(
											'order_status' => WCF_CART_COMPLETED_ORDER,
										),
										array(
											'session_id' => $order_data->session_id,
										)
									);
								}
							}
						}
						$wpdb->delete( $cart_abandonment_table, array( 'session_id' => sanitize_key( $session_id ) ) );
					}
				}
			}
			WC()->session->__unset( 'wcf_session_id' );
		}
	}


	/**
	 * Compare cart if similar products.
	 *
	 * @param array $cart_a cart_a.
	 * @param array $cart_b cart_b.
	 * @return bool
	 */
	function check_if_similar_cart( $cart_a, $cart_b ) {
		return (
			is_array( $cart_a )
			&& is_array( $cart_b )
			&& count( $cart_a ) === count( $cart_b )
			&& array_diff( $cart_a, $cart_b ) === array_diff( $cart_b, $cart_a )
		);
	}


	/**
	 * Get the checkout details for the user.
	 *
	 * @param string $wcf_session_id checkout page session id.
	 * @since 1.0.0
	 */
	function get_checkout_details( $wcf_session_id ) {
		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$result                 = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM `' . $cart_abandonment_table . '` WHERE session_id = %s', $wcf_session_id ) // phpcs:ignore
		);
		return $result;
	}

	/**
	 * Get the checkout details for the user.
	 *
	 * @param string $email user email.
	 * @since 1.0.0
	 */
	function get_checkout_details_by_email( $email ) {
		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$result                 = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM `' . $cart_abandonment_table . '` WHERE email = %s AND `order_status` IN ( %s, %s )', $email, WCF_CART_ABANDONED_ORDER, WCF_CART_NORMAL_ORDER ) // phpcs:ignore
		);
		return $result;
	}


	/**
	 * Get the checkout details for the user.
	 *
	 * @param string $value value.
	 * @since 1.0.0
	 */
	function get_captured_data_by_email( $value ) {
		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$result                 = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $cart_abandonment_table . '` WHERE email = %s AND `order_status` IN (%s, %s) ORDER BY `time` DESC LIMIT 1', $value, WCF_CART_ABANDONED_ORDER, WCF_CART_LOST_ORDER ) // phpcs:ignore
		);
		return $result;
	}


	/**
	 * Get the checkout details for the user.
	 *
	 * @param string $value value.
	 * @since 1.0.0
	 */
	function get_tracked_data_without_status( $value ) {
		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$result                 = $wpdb->get_row(
			$wpdb->prepare(
                'SELECT * FROM `' . $cart_abandonment_table . '` WHERE email = %s LIMIT 1', $value ) // phpcs:ignore
		);
		return $result;
	}

	/**
	 * Add submenu to admin menu.
	 *
	 * @since 1.1.5
	 */
	function abandoned_cart_tracking_menu() {

		$parent_slug = 'woocommerce';
		$page_title  = __( 'Cart Abandonment', 'woo-cart-abandonment-recovery' );
		$menu_title  = __( 'Cart Abandonment', 'woo-cart-abandonment-recovery' );
		$capability  = 'manage_options';
		$menu_slug   = WCF_CA_PAGE_NAME;
		$callback    = array( $this, 'render_abandoned_cart_tracking' );

		add_submenu_page(
			$parent_slug,
			$page_title,
			$menu_title,
			$capability,
			$menu_slug,
			$callback
		);
	}

	/**
	 * Render table view for cart abandonment tracking.
	 *
	 * @since 1.1.5
	 */
	function render_abandoned_cart_tracking() {

		$wcf_list_table = new Cartflows_Ca_Cart_Abandonment_Table();

		if ( 'delete' === $wcf_list_table->current_action() ) {

			$ids = array();
			if ( isset( $_REQUEST['id'] ) && is_array( $_REQUEST['id'] ) ) {
				$ids = array_map( 'intval', $_REQUEST['id'] );
			}
			$deleted_row_count = empty( $ids ) ? 1 : count( $ids );

			$wcf_list_table->process_bulk_action();
			$message = '<div class="notice notice-success is-dismissible" id="message"><p>' . sprintf( __( 'Items deleted: %d', 'woo-cart-abandonment-recovery' ), $deleted_row_count ) . '</p></div>'; // phpcs:ignore
			set_transient( 'wcf_ca_show_message', $message, 5 );
			if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
				wp_safe_redirect( $_SERVER['HTTP_REFERER'] );
			}
		} elseif ( 'unsubscribe' === $wcf_list_table->current_action() ) {

			global $wpdb;
			$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
			$id                     = filter_input( INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT );

			$wpdb->update(
				$cart_abandonment_table,
				array( 'unsubscribed' => true ),
				array( 'id' => $id )
			);

            $message = '<div class="notice notice-success is-dismissible" id="message"><p>' . sprintf( __( 'User unsubscribed successfully!', 'woo-cart-abandonment-recovery' ) ) . '</p></div>'; // phpcs:ignore
			set_transient( 'wcf_ca_show_message', $message, 5 );
			if ( isset( $_SERVER['HTTP_REFERER'] ) ) {
				wp_safe_redirect( $_SERVER['HTTP_REFERER'] );
			}
		}
		?>

		<?php
		include_once CARTFLOWS_CART_ABANDONMENT_TRACKING_DIR . 'includes/admin/cartflows-cart-abandonment-tabs.php';
		?>
		<?php
	}

	/**
	 * Count abandoned carts
	 *
	 * @since 1.1.5
	 */
	function abandoned_cart_count() {
		global $wpdb;
		$cart_abandonment_table_name = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;

        $query       = $wpdb->prepare( "SELECT   COUNT(`id`) FROM {$cart_abandonment_table_name}  WHERE `order_status` = %s", WCF_CART_ABANDONED_ORDER ); // phpcs:ignore
        $total_items = $wpdb->get_var( $query ); // phpcs:ignore
		return $total_items;
	}

	/**
	 * Load analytics scripts.
	 */
	function load_admin_cart_abandonment_script() {

		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING );

		if ( ! ( WCF_CA_PAGE_NAME === $page ) ) {
			return;
		}

		// Styles.
		wp_enqueue_style( 'cartflows-cart-abandonment-admin', CARTFLOWS_CA_URL . 'admin/assets/css/admin-cart-abandonment.css', array(), CARTFLOWS_CA_VER );

	}


	/**
	 * Render Cart abandonment display button beside title.
	 */
	public function setup_cart_abandonment_button() {

		if ( ! Cartflows_Admin::is_flow_edit_admin() ) {
			return;
		}

		$reports_btn_markup  = '<style>.wrap{ position:relative;}</style>';
		$reports_btn_markup .= "<div class='wcf-reports-button-wrap'>";
		$reports_btn_markup .= "<button class='wcf-cart-abandonment-reports-popup button button-secondary'>";
		$reports_btn_markup .= esc_html( 'View Report', 'woo-cart-abandonment-recovery' );
		$reports_btn_markup .= '</button>';
		$reports_btn_markup .= '</div>';

		echo $reports_btn_markup;

	}

	/**
	 * Get start and end date for given interval.
	 *
	 * @param  string $interval interval .
	 * @return array
	 */
	function get_start_end_by_interval( $interval ) {

		if ( 'today' === $interval ) {
			$start_date = date( 'Y-m-d' );
			$end_date   = date( 'Y-m-d' );
		} else {

			$days = $interval;

			$start_date = date( 'Y-m-d', strtotime( '-' . $days . ' days' ) );
			$end_date   = date( 'Y-m-d' );
		}

		return array(
			'start' => $start_date,
			'end'   => $end_date,
		);
	}


	/**
	 *  Get Attributable revenue.
	 *  Represents the revenue generated by this campaign.
	 *
	 * @param string $type abondened|completed.
	 * @param string $from_date from date.
	 * @param string $to_date to date.
	 */
	function get_report_by_type( $type = WCF_CART_ABANDONED_ORDER, $from_date, $to_date ) {
		global $wpdb;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$minutes                = wcf_ca()->utils->get_cart_abandonment_tracking_cut_off_time();
		$attributable_revenue   = $wpdb->get_row(
		        $wpdb->prepare( "SELECT  SUM(`cart_total`) as revenue, count('*') as no_of_orders  FROM {$cart_abandonment_table} WHERE `order_status` = %s AND DATE(`time`) >= %s AND DATE(`time`) <= %s  ",  $type, $from_date, $to_date ), // phpcs:ignore
			ARRAY_A
		);
		return $attributable_revenue;
	}


	/**
	 * Get checkout url.
	 *
	 * @param  integer $post_id    post id.
	 * @param  string  $token_data token data.
	 * @return string
	 */
	function get_checkout_url( $post_id, $token_data ) {

		$token        = $this->wcf_generate_token( (array) $token_data );
		$checkout_url = get_permalink( $post_id ) . '?wcf_ac_token=' . $token;
		return esc_url( $checkout_url );
	}

	/**
	 *  Geberate the token for the given data.
	 *
	 * @param array $data data.
	 */
	function wcf_generate_token( $data ) {
		return urlencode( base64_encode( http_build_query( $data ) ) );
	}

	/**
	 *  Decode and get the original contents.
	 *
	 * @param string $token token.
	 */
	function wcf_decode_token( $token ) {
		$token = sanitize_text_field( $token );
		parse_str( base64_decode( urldecode( $token ) ), $token );
		return $token;
	}

	/**
	 * Render Cart abandonment tabs.
	 *
	 * @since 1.1.5
	 */
	function wcf_display_tabs() {

		$action     = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_STRING );
		$sub_action = filter_input( INPUT_GET, 'sub_action', FILTER_SANITIZE_STRING );

		if ( ! $action ) {
			$action                 = WCF_ACTION_REPORTS;
			$active_settings        = '';
			$active_reports         = '';
			$active_email_templates = '';
		}

		switch ( $action ) {
			case WCF_ACTION_SETTINGS:
				$active_settings = 'nav-tab-active';
				break;
			case WCF_ACTION_REPORTS:
				$active_reports = 'nav-tab-active';
				break;
			case WCF_ACTION_EMAIL_TEMPLATES:
				$active_email_templates = 'nav-tab-active';
				break;
			default:
				$active_reports = 'nav-tab-active';
				break;
		}
        // phpcs:disable
     ?>


		<div class="nav-tab-wrapper woo-nav-tab-wrapper">

            <?php
            $url = add_query_arg( array(
                'page' => WCF_CA_PAGE_NAME,
                'action' => WCF_ACTION_REPORTS
            ), admin_url( '/admin.php' ) )
            ?>
            <a href="<?php echo $url; ?>"
               class="nav-tab
            <?php
               if ( isset( $active_reports ) ) {
				echo $active_reports;}
               ?>
				">
                <?php _e( 'Report', 'woo-cart-abandonment-recovery' ); ?>
            </a>

            <?php
            $url = add_query_arg( array(
                'page' => WCF_CA_PAGE_NAME,
                'action' => WCF_ACTION_EMAIL_TEMPLATES
            ), admin_url( '/admin.php' ) )
            ?>
            <a href="<?php echo $url; ?>"
               class="nav-tab
            <?php
               if ( isset( $active_email_templates ) ) {
				echo $active_email_templates;}
               ?>
				">
                <?php _e( 'Follow-Up Emails', 'woo-cart-abandonment-recovery' ); ?>
            </a>

            <?php
            $url = add_query_arg( array(
                'page' => WCF_CA_PAGE_NAME,
                'action' => WCF_ACTION_SETTINGS
            ), admin_url( '/admin.php' ) )
               ?>
			<a href="<?php echo $url; ?>"
			   class="nav-tab 
            <?php
          if ( isset( $active_settings ) ) {
                    echo $active_settings;}
          ?>
				">
          <?php _e( 'Settings', 'woo-cart-abandonment-recovery' ); ?>
			</a>

		</div>
        <?php
        // phpcs:enable
	}

	/**
	 * Render Cart abandonment settings.
	 *
	 * @since 1.1.5
	 */
	function wcf_display_settings() {
		?>

		<form method="post" action="options.php">
		<?php settings_fields( WCF_CA_SETTINGS_OPTION_GROUP ); ?>
		<?php do_settings_sections( WCF_CA_PAGE_NAME ); ?>
		<?php submit_button(); ?>
		</form>

		<?php
	}

	/**
	 * Render Cart abandonment reports.
	 *
	 * @since 1.1.5
	 */
	function wcf_display_reports() {

		$filter       = filter_input( INPUT_GET, 'filter', FILTER_SANITIZE_STRING );
		$filter_table = filter_input( INPUT_GET, 'filter_table', FILTER_SANITIZE_STRING );

		if ( ! $filter ) {
			$filter = 'last_month';
		}
		if ( ! $filter_table ) {
			$filter_table = WCF_CART_ABANDONED_ORDER;
		}

		$from_date = filter_input( INPUT_GET, 'from_date', FILTER_SANITIZE_STRING );
		$to_date   = filter_input( INPUT_GET, 'to_date', FILTER_SANITIZE_STRING );

		switch ( $filter ) {

			case 'yesterday':
				$to_date   = date( 'Y-m-d', strtotime( '-1 days' ) );
				$from_date = $to_date;
				break;
			case 'today':
				$to_date   = date( 'Y-m-d' );
				$from_date = $to_date;
				break;
			case 'last_week':
				$from_date = date( 'Y-m-d', strtotime( '-7 days' ) );
				$to_date   = date( 'Y-m-d' );
				break;
			case 'last_month':
				$from_date = date( 'Y-m-d', strtotime( '-1 months' ) );
				$to_date   = date( 'Y-m-d' );
				break;
			case 'custom':
				$to_date   = $to_date ? $to_date : date( 'Y-m-d' );
				$from_date = $from_date ? $from_date : $to_date;
				break;

		}

		$abandoned_report = $this->get_report_by_type( WCF_CART_ABANDONED_ORDER, $from_date, $to_date );
		$recovered_report = $this->get_report_by_type( WCF_CART_COMPLETED_ORDER, $from_date, $to_date );
		$lost_report      = $this->get_report_by_type( WCF_CART_LOST_ORDER, $from_date, $to_date );

		$wcf_list_table = new Cartflows_Ca_Cart_Abandonment_Table();
		$wcf_list_table->prepare_items( $filter_table, $from_date, $to_date );

		$conversion_rate = 0;
		$total_orders    = ( $recovered_report['no_of_orders'] + $abandoned_report['no_of_orders'] + $lost_report['no_of_orders'] );
		if ( $total_orders ) {
			$conversion_rate = ( $recovered_report['no_of_orders'] / $total_orders ) * 100;
		}

		global  $woocommerce;
		$conversion_rate = number_format_i18n( $conversion_rate, 2 );
		$currency_symbol = get_woocommerce_currency_symbol();
		require_once CARTFLOWS_CART_ABANDONMENT_TRACKING_DIR . 'includes/admin/cartflows-cart-abandonment-reports.php';
	}


	/**
	 * Show report details for specific order.
	 */
	function wcf_display_report_details() {

		$sesson_id = filter_input( INPUT_GET, 'session_id', FILTER_SANITIZE_STRING );

		if ( $sesson_id ) {
			$details          = $this->get_checkout_details( $sesson_id );
			$user_details     = (object) unserialize( $details->other_fields );
			$scheduled_emails = $this->fetch_scheduled_emails( $sesson_id );

			require_once  CARTFLOWS_CART_ABANDONMENT_TRACKING_DIR . 'includes/admin/cartflows-ca-single-report-details.php';
		}

	}

	/**
	 *  Check and show warning message if cart abandonment is disabled.
	 */
	function wcf_show_warning_ca() {
		$settings_url = add_query_arg(
			array(
				'page'   => WCF_CA_PAGE_NAME,
				'action' => WCF_ACTION_SETTINGS,
			),
			admin_url( '/admin.php' )
		);

		if ( ! wcf_ca()->utils->is_cart_abandonment_tracking_enabled() ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
                    <?php echo __('Looks like abandonment tracking is disabled! Please enable it from  <a href=' . esc_url($settings_url) . '> <strong>settings</strong></a>.', 'woo-cart-abandonment-recovery'); // phpcs:ignore
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 *  Callback trigger event to send the emails.
	 */
	function send_emails_to_callback() {

		global $wpdb;
		$email_history_table    = $wpdb->prefix . CARTFLOWS_CA_EMAIL_HISTORY_TABLE;
		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;
		$email_template_table   = $wpdb->prefix . CARTFLOWS_CA_EMAIL_TEMPLATE_TABLE;

		$current_time = current_time( WCF_CA_DATETIME_FORMAT );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$emails_send_to = $wpdb->get_results(

			$wpdb->prepare(
				'SELECT *, EHT.id as email_history_id, ETT.id as email_template_id FROM ' . $email_history_table . ' as EHT
		        INNER JOIN ' . $cart_abandonment_table . ' as CAT ON EHT.`ca_session_id` = CAT.`session_id` 
		        INNER JOIN ' . $email_template_table . ' as ETT ON ETT.`id` = EHT.`template_id` 
		        WHERE CAT.`order_status` = %s AND CAT.unsubscribed = 0 AND EHT.`email_sent` = 0 AND EHT.`scheduled_time` <= %s',
				WCF_CART_ABANDONED_ORDER,
				$current_time
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $emails_send_to as $email_send_to ) {
			$email_result = $this->send_email_templates( $email_send_to );
			if ( $email_result ) {
				$wpdb->update(
					$email_history_table,
					array( 'email_sent' => true ),
					array( 'id' => $email_send_to->email_history_id )
				);
			}
		}
	}


	/**
	 * Create a dummy object for the preview email.
	 *
	 * @return stdClass
	 */
	public function create_dummy_session_for_preview_email() {

		$email_data                    = new stdClass();
		$current_user                  = wp_get_current_user();
		$user_data                     = array(
			'wcf_first_name' => $current_user->user_firstname,
			'wcf_last_name'  => $current_user->user_lastname,
		);
		$email_data->email_template_id = null;
		$email_data->checkout_id       = wc_get_page_id( 'checkout' );
		$email_data->session_id        = 'dummy-session-id';
		$email_data->email_template_id = null;

		$email_send_to             = filter_input( INPUT_POST, 'email_send_to', FILTER_SANITIZE_EMAIL );
		$email_data->email         = $email_send_to ? $email_send_to : $current_user->user_email;
		$email_data->email_body    = filter_input( INPUT_POST, 'email_body', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$email_data->email_subject = filter_input( INPUT_POST, 'email_subject', FILTER_SANITIZE_STRING );
		$email_data->email_body    = html_entity_decode( $email_data->email_body );

		$email_data->other_fields = serialize( $user_data );
		if ( ! WC()->cart->get_cart_contents_count() ) {
			$args = array(
				'posts_per_page' => 1,
				'orderby'        => 'rand',
				'post_type'      => 'product',
				'meta_query'     => array(
					// Exclude out of stock products.
					array(
						'key'     => '_stock_status',
						'value'   => 'outofstock',
						'compare' => 'NOT IN',
					),
				),
				'tax_query'      => array(
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => 'simple',
					),
				),
			);

			$random_products = get_posts( $args );
			if ( ! empty( $random_products ) ) {
				$random_product = reset( $random_products );
				WC()->cart->add_to_cart( $random_product->ID );
			}
		}

		$email_data->cart_total    = WC()->cart->total + floatval( WC()->cart->get_cart_shipping_total() );
		$email_data->cart_contents = serialize( WC()->cart->get_cart() );
		$email_data->time          = current_time( WCF_CA_DATETIME_FORMAT );
		return $email_data;
	}

	/**
	 * Callback function to send email templates.
	 *
	 * @param array   $email_data email data  .
	 * @param boolean $preview_email preview email.
	 * @since 1.0.0
	 */
	function send_email_templates( $email_data, $preview_email = false ) {

		if ( $preview_email ) {
			$email_data = $this->create_dummy_session_for_preview_email();
		}

		if ( filter_var( $email_data->email, FILTER_VALIDATE_EMAIL ) ) {

			$other_fields = unserialize( $email_data->other_fields );

			$from_email_name    = get_option( 'wcf_ca_from_name' );
			$reply_name_preview = get_option( 'wcf_ca_reply_email' );
			$from_email_preview = get_option( 'wcf_ca_from_email' );

			$user_first_name = ucfirst( $other_fields['wcf_first_name'] );
			$user_first_name = $user_first_name ? $user_first_name : __( 'there', 'woo-cart-abandonment-recovery' );
			$user_last_name  = ucfirst( $other_fields['wcf_last_name'] );
			$user_full_name  = trim( $user_first_name . ' ' . $user_last_name );

			$subject_email_preview = stripslashes( html_entity_decode( $email_data->email_subject, ENT_QUOTES ) );
			$subject_email_preview = convert_smilies( $subject_email_preview );
			$subject_email_preview = str_replace( '{{customer.firstname}}', $user_first_name, $subject_email_preview );
			$body_email_preview    = convert_smilies( $email_data->email_body );
			$body_email_preview    = str_replace( '{{customer.firstname}}', $user_first_name, $body_email_preview );
			$body_email_preview    = str_replace( '{{customer.lastname}}', $user_last_name, $body_email_preview );
			$body_email_preview    = str_replace( '{{customer.fullname}}', $user_full_name, $body_email_preview );

			$email_instance = Cartflows_Ca_Email_Templates::get_instance();
			if ( $preview_email ) {
				$coupon_code = 'DUMMY-COUPON';
			} else {
				$override_global_coupon = $email_instance->get_email_template_meta_by_key( $email_data->email_template_id, 'override_global_coupon' );
				if ( $override_global_coupon->meta_value ) {
					$email_history = $email_instance->get_email_history_by_id( $email_data->email_history_id );
					$coupon_code   = $email_history->coupon_code;
				} else {
					$coupon_code = $email_data->coupon_code;
				}
			}

			$auto_apply_coupon = $email_instance->get_email_template_meta_by_key( $email_data->email_template_id, 'auto_coupon' );

			$token_data = array(
				'wcf_session_id'  => $email_data->session_id,
				'wcf_coupon_code' => isset( $auto_apply_coupon ) && $auto_apply_coupon->meta_value ? $coupon_code : null,
			);

			$checkout_url = $this->get_checkout_url( $email_data->checkout_id, $token_data );

			$body_email_preview = str_replace( '{{cart.coupon_code}}', $coupon_code, $body_email_preview );

			$current_time_stamp  = $email_data->time;
			$body_email_preview  = str_replace( '{{cart.abandoned_date}}', $current_time_stamp, $body_email_preview );
			$unsubscribe_element = '<a target="_blank" style="color: lightgray" href="' . $checkout_url . '&unsubscribe=true' . '">' . __( 'Unsubscribe', 'woo-cart-abandonment-recovery' ) . '</a>';
			$body_email_preview  = str_replace( '{{cart.unsubscribe}}', $unsubscribe_element, $body_email_preview );
			$body_email_preview  = str_replace( 'http://{{cart.checkout_url}}', '{{cart.checkout_url}}', $body_email_preview );
			$body_email_preview  = str_replace( 'https://{{cart.checkout_url}}', '{{cart.checkout_url}}', $body_email_preview );
			$body_email_preview  = str_replace( '{{cart.checkout_url}}', $checkout_url, $body_email_preview );
			$host                = parse_url( get_site_url() );
			$body_email_preview  = str_replace( '{{site.url}}', $host['host'], $body_email_preview );

			if ( false !== strpos( $body_email_preview, '{{cart.product.names}}' ) ) {
				$body_email_preview = str_replace( '{{cart.product.names}}', $this->get_comma_separated_products( $email_data->cart_contents ), $body_email_preview );
			}

			$admin_user         = get_users(
				array(
					'role'   => 'Administrator',
					'number' => 1,
				)
			);
			$admin_user         = reset( $admin_user );
			$admin_first_name   = $admin_user->user_firstname ? $admin_user->user_firstname : 'Admin';
			$body_email_preview = str_replace( '{{admin.firstname}}', $admin_first_name, $body_email_preview );
			$body_email_preview = str_replace( '{{admin.company}}', get_bloginfo( 'name' ), $body_email_preview );

			$headers  = 'From: ' . $from_email_name . ' <' . $from_email_preview . '>' . "\r\n";
			$headers .= 'Content-Type: text/html' . "\r\n";
			$headers .= 'Reply-To:  ' . $reply_name_preview . ' ' . "\r\n";
			$var      = $this->get_email_product_block( $email_data->cart_contents, $email_data->cart_total );

			$body_email_preview = str_replace( '{{cart.product.table}}', $var, $body_email_preview );
			$body_email_preview = wpautop( $body_email_preview );
			$mail_result        = wp_mail( $email_data->email, $subject_email_preview, stripslashes( $body_email_preview ), $headers );
			if ( $mail_result ) {
				return true;
			} else {
				// Retry sending mail.
				$mail_result = wp_mail( $email_data->email, $subject_email_preview, stripslashes( $body_email_preview ), $headers );
				if ( ! $preview_email ) {
					return true;
				}
				return false;
			}
		} else {
			return false;
		}

	}

	/**
	 * Generate comma separated products.
	 *
	 * @param object $cart_contents user cart details.
	 */
	function get_comma_separated_products( $cart_contents ) {
		$cart_comma_string = '';
		if ( ! $cart_contents ) {
			return $cart_comma_string;
		}
		$cart_data = unserialize( $cart_contents );

		$cart_length = count( $cart_data );
		$index       = 0;
		foreach ( $cart_data as $key => $product ) {

			if ( ! isset( $product['product_id'] ) ) {
				continue;
			}

			$cart_product = wc_get_product( $product['product_id'] );

			$cart_comma_string = $cart_comma_string . $cart_product->get_title();
			if ( ( $cart_length - 2 ) === $index ) {
				$cart_comma_string = $cart_comma_string . ' & ';
			} elseif ( ( $cart_length - 1 ) !== $index ) {
				$cart_comma_string = $cart_comma_string . ', ';
			}
			$index++;
		}
		return $cart_comma_string;

	}

	/**
	 * Generate the view for email product cart block.
	 *
	 * @param  object $cart_contents user cart contents details.
	 * @param  float  $cart_total user cart total.
	 * @return string
	 */
	function get_email_product_block( $cart_contents, $cart_total ) {

		$cart_items = unserialize( $cart_contents );

		if ( ! is_array( $cart_items ) || ! count( $cart_items ) ) {
			return;
		}

		$currency_symbol = get_woocommerce_currency_symbol();
		$tr              = '';
		$style           = array(
			'product_image' => array(
				'style' => 'height: 42px; width: 42px;',
			),
			'table'         => array(
				'style' => 'color: #636363; border: 1px solid #e5e5e5;',
			),
		);

		$style               = apply_filters( 'woo_ca_email_template_table_style', $style );
		$product_image_style = isset( $style['product_image']['style'] ) ? $style['product_image']['style'] : '';
		$style               = isset( $style['table']['style'] ) ? $style['table']['style'] : '';

		foreach ( $cart_items as $cart_item ) {

			if ( isset( $cart_item['product_id'] ) && isset( $cart_item['quantity'] ) && isset( $cart_item['line_total'] ) ) {
				$product = wc_get_product( $cart_item['product_id'] );
			} else {
				continue;
			}

			$tr = $tr . '<tr style=' . $style . ' align="center">
                           <td style="' . $style . '"><img  class="demo_img" style="' . $product_image_style . '" src="' . esc_url( get_the_post_thumbnail_url( $product->get_id() ) ) . '"></td>
                           <td style="' . $style . '">' . $product->get_title() . '</td>
                           <td style="' . $style . '"> ' . $cart_item['quantity'] . ' </td>
                           <td style="' . $style . '">' . $currency_symbol . number_format_i18n( $cart_item['line_total'], 2 ) . '</td>
                           <td style="' . $style . '" >' . $currency_symbol . number_format_i18n( $cart_item['line_total'], 2 ) . '</td>
                        </tr> ';
		}

		/**
		 * Add filter to toggle the Cart Total row.
		 */
		$enable_cart_total = apply_filters( 'woo_ca_recovery_enable_cart_total', false );
		if ( $enable_cart_total ) {
			$tr = $tr . '<tr style="' . $style . '" align="center">
                           <td colspan="4" style="' . $style . '"> ' . __( 'Cart Total ( Cart Total + Shipping + Tax )', 'woo-cart-abandonment-recovery' ) . ' </td>
                           <td style="' . $style . '" >' . $currency_symbol . number_format_i18n( $cart_total, 2 ) . '</td>
                        </tr> ';
		}

		return '<table align="left" cellpadding="10" cellspacing="0" style="float: none; border: 1px solid #e5e5e5;">
	                <tr align="center">
	                   <th  style="' . $style . '">' . __( 'Item', 'woo-cart-abandonment-recovery' ) . '</th>
	                   <th  style="' . $style . '">' . __( 'Name', 'woo-cart-abandonment-recovery' ) . '</th>
	                   <th  style="' . $style . '">' . __( 'Quantity', 'woo-cart-abandonment-recovery' ) . '</th>
	                   <th  style="' . $style . '">' . __( 'Price', 'woo-cart-abandonment-recovery' ) . '</th>
	                   <th  style="' . $style . '">' . __( 'Line Subtotal', 'woo-cart-abandonment-recovery' ) . '</th>
	                </tr> ' . $tr . '                 
	        </table>';

	}

	/**
	 * Generate the view for admin product cart block.
	 *
	 * @param  object $cart_contents user cart contents details.
	 * @param  float  $cart_total user cart total.
	 * @return string
	 */
	function get_admin_product_block( $cart_contents, $cart_total ) {

		$cart_items = unserialize( $cart_contents );

		if ( ! is_array( $cart_items ) || ! count( $cart_items ) ) {
			return;
		}

		$currency_symbol = get_woocommerce_currency_symbol();
		$tr              = '';
		$total           = 0;
		$discount        = 0;
		$tax             = 0;

		foreach ( $cart_items as $cart_item ) {

			if ( isset( $cart_item['product_id'] ) && isset( $cart_item['quantity'] ) && isset( $cart_item['line_total'] ) && isset( $cart_item['line_subtotal'] ) ) {
				$product = wc_get_product( $cart_item['product_id'] );
			} else {
				continue;
			}

			$discount = number_format_i18n( $discount + ( $cart_item['line_subtotal'] - $cart_item['line_total'] ), 2 );
			$total    = number_format_i18n( $total + $cart_item['line_subtotal'], 2 );
			$tax      = number_format_i18n( $tax + $cart_item['line_tax'], 2 );

			$tr = $tr . '<tr  align="center">
                           <td ><img class="demo_img" width="42" height="42" src=" ' . esc_url( get_the_post_thumbnail_url( $product->get_id() ) ) . ' "/></td>
                           <td >' . $product->get_title() . '</td>
                           <td > ' . $cart_item['quantity'] . ' </td>
                           <td >' . $currency_symbol . number_format_i18n( $cart_item['line_total'], 2 ) . '</td>
                           <td  >' . $currency_symbol . number_format_i18n( $cart_item['line_total'], 2 ) . '</td>
                        </tr> ';
		}

		return '<table align="left" cellspacing="0" class="widefat fixed striped posts">
					<thead>
		                <tr align="center">
		                   <th  >' . __( 'Item', 'woo-cart-abandonment-recovery' ) . '</th>
		                   <th  >' . __( 'Name', 'woo-cart-abandonment-recovery' ) . '</th>
		                   <th  >' . __( 'Quantity', 'woo-cart-abandonment-recovery' ) . '</th>
		                   <th  >' . __( 'Price', 'woo-cart-abandonment-recovery' ) . '</th>
		                   <th  >' . __( 'Line Subtotal', 'woo-cart-abandonment-recovery' ) . '</th>
		                </tr>
	                </thead>
	                <tbody>
	                   ' . $tr . ' 
	                   	<tr align="center" id="wcf-ca-discount">
							<td  colspan="4" >' . __( 'Discount', 'woo-cart-abandonment-recovery' ) . '</td>
							<td>' . $currency_symbol . ( $discount ) . '</td>
						</tr>
						<tr align="center" id="wcf-ca-other">
							<td colspan="4" >' . __( 'Other', 'woo-cart-abandonment-recovery' ) . '</td>
							<td>' . $currency_symbol . ( $tax ) . '</td>
						</tr>

						<tr align="center" id="wcf-ca-shipping">
							<td colspan="4" >' . __( 'Shipping', 'woo-cart-abandonment-recovery' ) . '</td>
							<td>' . $currency_symbol . number_format_i18n( $discount + ( $cart_total - $total ) - $tax, 2 ) . '</td>
						</tr>
						<tr align="center" id="wcf-ca-cart-total">
							<td colspan="4" >' . __( 'Cart Total', 'woo-cart-abandonment-recovery' ) . '</td>
							<td>' . $currency_symbol . $cart_total . '</td>
						</tr>
	                </tbody>
	        	</table>';
	}

	/**
	 * Copied WC function for date parameter addition.
	 *
	 * @param string $customer_email customer email.
	 * @param int    $product_id product id.
	 * @param int    $days days.
	 * @return array|bool|mixed|void
	 */
	function wcf_ca_wc_customer_bought_product( $customer_email, $product_id, $days = 365 ) {
		global $wpdb;

		$statuses = array_map( 'esc_sql', wc_get_is_paid_statuses() );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->prefix}posts AS p
                            INNER JOIN {$wpdb->prefix}postmeta AS pm ON p.ID = pm.post_id
                            INNER JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON p.ID = woi.order_id
                            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS woim ON woi.order_item_id = woim.order_item_id
                            WHERE p.post_status IN ( 'wc-" . implode( "','wc-", $statuses ) . "' )
                            AND pm.meta_key = '_billing_email'
                            AND pm.meta_value = %s
                            AND woim.meta_key IN ( '_product_id', '_variation_id' )
                            AND woim.meta_value = %s
                            AND p.post_date > '" . date( 'Y-m-d', strtotime( '-' . $days . ' days' ) ) . "'
                            ",
				$customer_email,
				$product_id
			)
		);

        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return intval( $result ) > 0 ? true : false;

	}

	/**
	 * Schedule events for the abadoned carts to send emails.
	 *
	 * @param integer $session_id user session id.
	 * @param boolean $force_reschedule force reschedule.
	 */
	function schedule_emails( $session_id, $force_reschedule = false ) {

		$checkout_details = $this->get_checkout_details( $session_id );

		if ( ( $checkout_details->unsubscribed ) || ( WCF_CART_COMPLETED_ORDER === $checkout_details->order_status ) ) {
			return;
		}
		$scheduled_time_from = current_time( WCF_CA_DATETIME_FORMAT );
		$scheduled_emails    = $this->fetch_scheduled_emails( $session_id );
		$scheduled_templates = array_column( $scheduled_emails, 'template_id' );

		// Skip if forcfully rescheduled.
		if ( ! $force_reschedule ) {
			$scheduled_time_from = $checkout_details->time;
			$user_exist          = get_user_by( 'email', $checkout_details->email );
			// Don't schedule emails if products are already purchased.
			if ( $user_exist ) {
				$purchasing_products = unserialize( $checkout_details->cart_contents );
				$already_purchased   = true;
				foreach ( $purchasing_products as $purchasing_product ) {
					if ( isset( $purchasing_product['product_id'] ) ) {
						$has_already_purchased = $this->wcf_ca_wc_customer_bought_product( $user_exist->user_email, $purchasing_product['product_id'], 30 );
						if ( ! $has_already_purchased ) {
							$already_purchased = false;
							break;
						}
					}
				}
				if ( $already_purchased ) {
					return;
				}
			}
		}

		$email_tmpl = Cartflows_Ca_Email_Templates::get_instance();
		$templates  = $email_tmpl->fetch_all_active_templates();

		global $wpdb;

		$email_history_table = $wpdb->prefix . CARTFLOWS_CA_EMAIL_HISTORY_TABLE;

		foreach ( $templates as $template ) {

			if ( false !== array_search( $template->id, $scheduled_templates, true ) ) {
				continue;
			}

			$timestamp_str  = '+' . $template->frequency . ' ' . $template->frequency_unit . 'S';
			$scheduled_time = date( WCF_CA_DATETIME_FORMAT, strtotime( $scheduled_time_from . $timestamp_str ) );
			$discount_type  = $email_tmpl->get_email_template_meta_by_key( $template->id, 'discount_type' );
			$discount_type  = isset( $discount_type->meta_value ) ? $discount_type->meta_value : '';
			$amount         = $email_tmpl->get_email_template_meta_by_key( $template->id, 'coupon_amount' );
			$amount         = isset( $amount->meta_value ) ? $amount->meta_value : '';

			$coupon_expiry_date = $email_tmpl->get_email_template_meta_by_key( $template->id, 'coupon_expiry_date' );
			$coupon_expiry_unit = $email_tmpl->get_email_template_meta_by_key( $template->id, 'coupon_expiry_unit' );
			$coupon_expiry_date = isset( $coupon_expiry_date->meta_value ) ? $coupon_expiry_date->meta_value : '';
			$coupon_expiry_unit = isset( $coupon_expiry_unit->meta_value ) ? $coupon_expiry_unit->meta_value : 'hours';

			$coupon_expiry_date = $coupon_expiry_date ? strtotime( $scheduled_time . ' +' . $coupon_expiry_date . ' ' . $coupon_expiry_unit ) : '';

			$free_shipping_coupon = $email_tmpl->get_email_template_meta_by_key( $template->id, 'free_shipping_coupon' );
			$free_shipping        = ( isset( $free_shipping_coupon ) && ( $free_shipping_coupon->meta_value ) ) ? 'yes' : 'no';

			$individual_use_only = $email_tmpl->get_email_template_meta_by_key( $template->id, 'individual_use_only' );
			$individual_use      = ( isset( $individual_use_only ) && ( $individual_use_only->meta_value ) ) ? 'yes' : 'no';

			$override_global_coupon = $email_tmpl->get_email_template_meta_by_key( $template->id, 'override_global_coupon' );

			$new_coupon_code = '';
			if ( $override_global_coupon->meta_value ) {
				$new_coupon_code = $this->generate_coupon_code( $discount_type, $amount, $coupon_expiry_date, $free_shipping, $individual_use );
			}

			$wpdb->replace(
				$email_history_table,
				array(
					'template_id'    => $template->id,
					'ca_session_id'  => $checkout_details->session_id,
					'coupon_code'    => $new_coupon_code,
					'scheduled_time' => $scheduled_time,
				)
			);
		}
	}

	/**
	 * Fetch all the scheduled emails with templates for the specific session.
	 *
	 * @param string  $session_id session id.
	 * @param boolean $fetch_sent sfetch sent emails.
	 * @return array|object|null
	 */
	function fetch_scheduled_emails( $session_id, $fetch_sent = false ) {
		global $wpdb;
		$email_history_table  = $wpdb->prefix . CARTFLOWS_CA_EMAIL_HISTORY_TABLE;
		$email_template_table = $wpdb->prefix . CARTFLOWS_CA_EMAIL_TEMPLATE_TABLE;

		$query =   $wpdb->prepare("SELECT * FROM  $email_history_table as eht INNER JOIN $email_template_table as ett ON eht.template_id = ett.id WHERE ca_session_id = %s", sanitize_text_field($session_id)); // phpcs:ignore

		if ( $fetch_sent ) {
			$query .= ' AND email_sent = 1';
		}

		$result = $wpdb->get_results( $query ); // phpcs:ignore
		return $result;
	}





	/**
	 * Delete orders from cart abandonment table whose cart total is zero and order status is abandoned.
	 */
	function delete_empty_abandoned_order() {
		global $wpdb;

		$cart_abandonment_table = $wpdb->prefix . CARTFLOWS_CA_CART_ABANDONMENT_TABLE;

		$where = array(
			'cart_contents' => 'a:0:{}',
		);

		$wpdb->delete( $cart_abandonment_table, $where );
	}

}

Cartflows_Ca_Cart_Abandonment::get_instance();
