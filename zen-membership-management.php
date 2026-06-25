<?php
/**
 * Plugin Name: Zen Membership Management
 * Description: Customer-facing membership management for Zenctuary accounts.
 * Version: 0.1.4
 * Author: Custom
 * Text Domain: zen-membership-management
 *
 * @package ZenMembershipManagement
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZMM_Zen_Membership_Management' ) ) {
	final class ZMM_Zen_Membership_Management {

		const VERSION = '0.1.4';
		const ENDPOINT = 'my-membership';
		const MEMBERSHIP_GRANT_META = '_cbb_coin_grant_amount';
		const CANCELLATION_DEADLINE_DAYS = 7;
		const OPTION_CANCELLATION_DEADLINE_DAYS = 'zmm_monthly_cancellation_deadline_days';
		const META_CANCEL_AFTER_NEXT_PAYMENT = '_zmm_cancel_after_next_payment';
		const META_CANCEL_REQUESTED_AT = '_zmm_cancel_requested_at';
		const META_CANCEL_REQUESTED_BY = '_zmm_cancel_requested_by';
		const META_CANCEL_TARGET_END = '_zmm_cancel_target_end';

		/**
		 * Boot plugin hooks.
		 */
		public static function init() {
			register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
			register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );

			add_action( 'plugins_loaded', array( __CLASS__, 'register_hooks' ), 20 );
		}

		/**
		 * Register rewrite endpoint on activation.
		 */
		public static function activate() {
			self::register_endpoint();
			flush_rewrite_rules();
		}

		/**
		 * Flush rewrite endpoint on deactivation.
		 */
		public static function deactivate() {
			flush_rewrite_rules();
		}

		/**
		 * Register runtime hooks.
		 */
		public static function register_hooks() {
			add_action( 'init', array( __CLASS__, 'register_endpoint' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'filter_account_menu_items' ), 1001 );
			add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_account_endpoint' ) );
			add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'filter_woocommerce_query_vars' ) );
			add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_change_payment_method_to_account' ), 5 );
			add_filter( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', array( __CLASS__, 'filter_change_payment_method_success_redirect' ), 20, 2 );

			add_action( 'wp_loaded', array( __CLASS__, 'maybe_intercept_late_subscription_cancellation' ), 80 );
			add_action( 'wp_loaded', array( __CLASS__, 'maybe_reactivate_late_cancellation' ), 80 );
			add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'complete_scheduled_late_cancellation' ), 30, 2 );
			add_action( 'woocommerce_subscription_renewal_payment_failed', array( __CLASS__, 'cancel_scheduled_late_cancellation_after_failed_renewal' ), 30, 2 );

			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_single_membership_add_to_cart' ), 20, 3 );
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_single_membership_cart' ) );

			if ( is_admin() ) {
				add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
				add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
				add_action( 'admin_notices', array( __CLASS__, 'maybe_dependency_notice' ) );
			}
		}

		/**
		 * Register the WooCommerce account endpoint.
		 */
		public static function register_endpoint() {
			add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		}

		/**
		 * Add endpoint to WooCommerce query vars.
		 *
		 * @param array $vars Query vars.
		 * @return array
		 */
		public static function filter_woocommerce_query_vars( $vars ) {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;

			return $vars;
		}

		/**
		 * Check whether required plugins are available.
		 *
		 * @return bool
		 */
		private static function dependencies_loaded() {
			return function_exists( 'WC' )
				&& function_exists( 'wc_memberships' )
				&& function_exists( 'wc_memberships_get_user_memberships' );
		}

		/**
		 * Register the plugin settings page.
		 */
		public static function register_settings_page() {
			add_submenu_page(
				'woocommerce',
				__( 'Zen Membership Management', 'zen-membership-management' ),
				__( 'Zen Membership', 'zen-membership-management' ),
				'manage_woocommerce',
				'zen-membership-management',
				array( __CLASS__, 'render_settings_page' )
			);
		}

		/**
		 * Register plugin settings.
		 */
		public static function register_settings() {
			register_setting(
				'zmm_settings',
				self::OPTION_CANCELLATION_DEADLINE_DAYS,
				array(
					'type'              => 'integer',
					'sanitize_callback' => array( __CLASS__, 'sanitize_cancellation_deadline_days' ),
					'default'           => self::CANCELLATION_DEADLINE_DAYS,
				)
			);

			add_settings_section(
				'zmm_monthly_rules',
				__( 'Monthly Membership Rules', 'zen-membership-management' ),
				'__return_false',
				'zmm_settings'
			);

			add_settings_field(
				self::OPTION_CANCELLATION_DEADLINE_DAYS,
				__( 'Cancellation deadline', 'zen-membership-management' ),
				array( __CLASS__, 'render_cancellation_deadline_field' ),
				'zmm_settings',
				'zmm_monthly_rules'
			);
		}

		/**
		 * Sanitize cancellation deadline setting.
		 *
		 * @param mixed $value Raw setting value.
		 * @return int
		 */
		public static function sanitize_cancellation_deadline_days( $value ) {
			return min( 365, max( 0, absint( $value ) ) );
		}

		/**
		 * Render plugin settings page.
		 */
		public static function render_settings_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Zen Membership Management', 'zen-membership-management' ); ?></h1>
				<form action="options.php" method="post">
					<?php
					settings_fields( 'zmm_settings' );
					do_settings_sections( 'zmm_settings' );
					submit_button();
					?>
				</form>
			</div>
			<?php
		}

		/**
		 * Render cancellation deadline field.
		 */
		public static function render_cancellation_deadline_field() {
			$value = self::get_cancellation_deadline_days();
			?>
			<input
				id="<?php echo esc_attr( self::OPTION_CANCELLATION_DEADLINE_DAYS ); ?>"
				name="<?php echo esc_attr( self::OPTION_CANCELLATION_DEADLINE_DAYS ); ?>"
				type="number"
				min="0"
				max="365"
				step="1"
				value="<?php echo esc_attr( $value ); ?>"
				class="small-text"
			/>
			<span><?php esc_html_e( 'days before the next payment date', 'zen-membership-management' ); ?></span>
			<p class="description">
				<?php esc_html_e( 'Monthly cancellations before this deadline stop the next payment. Cancellations after this deadline are accepted, but the upcoming payment still runs and the membership ends after that paid month.', 'zen-membership-management' ); ?>
			</p>
			<?php
		}

		/**
		 * Admin notice for missing dependencies.
		 */
		public static function maybe_dependency_notice() {
			if ( self::dependencies_loaded() || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Zen Membership Management requires WooCommerce and WooCommerce Memberships.', 'zen-membership-management' );
			echo '</p></div>';
		}

		/**
		 * Enqueue account styling.
		 */
		public static function enqueue_assets() {
			if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
				return;
			}

			wp_enqueue_style(
				'zmm-membership-management',
				plugin_dir_url( __FILE__ ) . 'assets/css/zen-membership-management.css',
				array(),
				self::VERSION
			);

			wp_enqueue_script(
				'zmm-membership-management',
				plugin_dir_url( __FILE__ ) . 'assets/js/zen-membership-management.js',
				array(),
				self::VERSION,
				true
			);

			if ( isset( $_GET['zmm_change_payment_method'] ) ) {
				wp_enqueue_script( 'wc-checkout' );
			}
		}

		/**
		 * Move native checkout/order-pay change-payment requests into the account shell.
		 */
		public static function maybe_redirect_change_payment_method_to_account() {
			global $wp;

			if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'is_account_page' ) && is_account_page() ) || ! isset( $_GET['change_payment_method'] ) ) {
				return;
			}

			$subscription_id = absint( wp_unslash( $_GET['change_payment_method'] ) );
			$order_pay_id    = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;

			if ( ! $subscription_id || ! $order_pay_id || $subscription_id !== $order_pay_id || ! function_exists( 'wcs_get_subscription' ) ) {
				return;
			}

			$subscription = wcs_get_subscription( $subscription_id );

			if ( ! $subscription instanceof WC_Subscription || ! current_user_can( 'edit_shop_subscription_payment_method', $subscription_id ) ) {
				return;
			}

			wp_safe_redirect( self::get_change_payment_method_account_url( $subscription ) );
			exit;
		}

		/**
		 * Replace the Memberships list item with a direct My Membership endpoint.
		 *
		 * @param array $items Account menu items.
		 * @return array
		 */
		public static function filter_account_menu_items( $items ) {
			$legacy_keys = array( 'memberships', 'my-memberships', 'members-area' );

			if ( function_exists( 'wc_memberships_get_members_area_endpoint' ) ) {
				$legacy_keys[] = wc_memberships_get_members_area_endpoint();
			}

			foreach ( array_unique( array_filter( $legacy_keys ) ) as $legacy_key ) {
				if ( self::ENDPOINT !== $legacy_key ) {
					unset( $items[ $legacy_key ] );
				}
			}

			foreach ( $items as $key => $label ) {
				$normalized_label = strtolower( trim( wp_strip_all_tags( (string) $label ) ) );

				if ( self::ENDPOINT !== $key && in_array( $normalized_label, array( 'my membership', 'memberships' ), true ) ) {
					unset( $items[ $key ] );
				}
			}

			$new_items = array();
			$inserted  = false;

			foreach ( $items as $key => $label ) {
				$new_items[ $key ] = $label;

				if ( ! $inserted && in_array( $key, array( 'wallet', 'woo-wallet', 'edit-account', 'payment-methods' ), true ) ) {
					$new_items[ self::ENDPOINT ] = __( 'My Membership', 'zen-membership-management' );
					$inserted = true;
				}
			}

			if ( ! $inserted ) {
				$new_items[ self::ENDPOINT ] = __( 'My Membership', 'zen-membership-management' );
			}

			return $new_items;
		}

		/**
		 * Render the membership account endpoint.
		 */
		public static function render_account_endpoint() {
			if ( ! self::dependencies_loaded() ) {
				wc_print_notice( __( 'Membership details are unavailable right now.', 'zen-membership-management' ), 'notice' );
				return;
			}

			$membership = self::get_current_user_membership();

			if ( ! $membership ) {
				self::render_no_membership();
				return;
			}

			$subscription = self::get_membership_subscription( $membership );

			if ( $subscription && self::is_change_payment_method_request( $subscription ) ) {
				self::render_change_payment_method_form( $subscription );
				return;
			}

			echo '<div class="zmm-membership">';
			self::render_membership_header( $membership );

			if ( $subscription ) {
				self::render_subscription_totals( $subscription );
			}

			self::render_membership_details( $membership, $subscription );

			if ( $subscription ) {
				self::render_related_orders( $subscription );
			}

			if ( $subscription ) {
				self::render_membership_cancellation_section( $subscription );
			}
			echo '</div>';
		}

		/**
		 * Get the single current membership for the logged-in customer.
		 *
		 * @return WC_Memberships_User_Membership|null
		 */
		private static function get_current_user_membership() {
			$user_id = get_current_user_id();

			if ( ! $user_id ) {
				return null;
			}

			$memberships = function_exists( 'wc_memberships_get_user_active_memberships' )
				? wc_memberships_get_user_active_memberships( $user_id )
				: wc_memberships_get_user_memberships( $user_id );

			if ( empty( $memberships ) ) {
				$memberships = wc_memberships_get_user_memberships( $user_id );
			}

			$memberships = array_filter(
				(array) $memberships,
				static function ( $membership ) {
					return is_object( $membership ) && is_callable( array( $membership, 'get_plan' ) ) && $membership->get_plan();
				}
			);

			usort(
				$memberships,
				static function ( $a, $b ) {
					$a_start = is_callable( array( $a, 'get_local_start_date' ) ) ? (int) $a->get_local_start_date( 'timestamp' ) : 0;
					$b_start = is_callable( array( $b, 'get_local_start_date' ) ) ? (int) $b->get_local_start_date( 'timestamp' ) : 0;

					return $b_start <=> $a_start;
				}
			);

			return ! empty( $memberships ) ? $memberships[0] : null;
		}

		/**
		 * Get linked subscription for a membership, when available.
		 *
		 * @param object $membership User membership.
		 * @return WC_Subscription|null
		 */
		private static function get_membership_subscription( $membership ) {
			if ( is_object( $membership ) && is_callable( array( $membership, 'get_subscription' ) ) ) {
				$subscription = $membership->get_subscription();

				if ( $subscription instanceof WC_Subscription ) {
					return $subscription;
				}
			}

			if ( function_exists( 'wc_memberships' ) && is_callable( array( wc_memberships(), 'get_integrations_instance' ) ) ) {
				$integrations = wc_memberships()->get_integrations_instance();
				$subscriptions = $integrations && is_callable( array( $integrations, 'get_subscriptions_instance' ) ) ? $integrations->get_subscriptions_instance() : null;

				if ( $subscriptions && is_callable( array( $subscriptions, 'get_subscription_from_membership' ) ) ) {
					$subscription = $subscriptions->get_subscription_from_membership( $membership );

					if ( $subscription instanceof WC_Subscription ) {
						return $subscription;
					}
				}
			}

			return null;
		}

		/**
		 * Render no-membership state.
		 */
		private static function render_no_membership() {
			?>
			<section class="zmm-empty">
				<h2><?php esc_html_e( 'My Membership', 'zen-membership-management' ); ?></h2>
				<p><?php esc_html_e( 'You do not have an active membership yet.', 'zen-membership-management' ); ?></p>
			</section>
			<?php
		}

		/**
		 * Render membership page header.
		 *
		 * @param object $membership User membership.
		 */
		private static function render_membership_header( $membership ) {
			$plan = $membership->get_plan();
			?>
			<header class="zmm-membership__header">
				<div>
					<p class="zmm-membership__eyebrow"><?php esc_html_e( 'My Membership', 'zen-membership-management' ); ?></p>
					<h2><?php echo esc_html( $plan ? $plan->get_name() : __( 'Membership', 'zen-membership-management' ) ); ?></h2>
				</div>
				<span class="zmm-status zmm-status--<?php echo esc_attr( sanitize_html_class( $membership->get_status() ) ); ?>">
					<?php echo esc_html( wc_memberships_get_user_membership_status_name( $membership->get_status() ) ); ?>
				</span>
			</header>
			<?php
		}

		/**
		 * Render membership details.
		 *
		 * @param object               $membership   User membership.
		 * @param WC_Subscription|null $subscription Linked subscription.
		 */
		private static function render_membership_details( $membership, $subscription = null ) {
			$rows = self::get_membership_detail_rows( $membership, $subscription );
			?>
			<section class="zmm-panel">
				<table class="shop_table shop_table_responsive zmm-details">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><?php echo wp_kses_post( $row['value'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( $subscription ) : ?>
							<?php $actions = self::get_subscription_primary_actions( $subscription ); ?>
							<?php if ( ! empty( $actions ) ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( 1 === count( $actions ) ? __( 'Action', 'zen-membership-management' ) : __( 'Actions', 'zen-membership-management' ) ); ?>
									</th>
									<td class="zmm-actions">
										<?php foreach ( $actions as $key => $action ) : ?>
											<a class="button zmm-action zmm-action--<?php echo esc_attr( sanitize_html_class( $key ) ); ?>" href="<?php echo esc_url( $action['url'] ); ?>">
												<?php echo esc_html( $action['name'] ); ?>
											</a>
										<?php endforeach; ?>
									</td>
								</tr>
							<?php endif; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
			<?php
		}

		/**
		 * Build membership detail rows.
		 *
		 * @param object               $membership   User membership.
		 * @param WC_Subscription|null $subscription Linked subscription.
		 * @return array
		 */
		private static function get_membership_detail_rows( $membership, $subscription = null ) {
			$rows = array(
				array(
					'label' => __( 'Status', 'zen-membership-management' ),
					'value' => esc_html( self::get_customer_status_label( $membership, $subscription ) ),
				),
				array(
					'label' => __( 'Start date', 'zen-membership-management' ),
					'value' => esc_html( self::format_membership_date( $membership, 'start' ) ),
				),
			);

			if ( $subscription ) {
				$next_payment = $subscription->get_time( 'next_payment' );
				$deadline     = self::get_cancellation_deadline_time( $subscription );
				$end_time     = self::get_cancellation_end_time( $subscription );
				$payment      = $subscription->get_payment_method_to_display( 'customer' );
				$is_yearly    = self::is_yearly_contract_subscription( $subscription );

				$rows[] = array(
					'label' => __( 'Next payment date', 'zen-membership-management' ),
					'value' => esc_html( self::get_next_payment_display( $subscription, $next_payment ) ),
				);

				if ( $is_yearly ) {
					$contract_end_time = self::get_yearly_contract_end_time( $subscription );

					$rows[] = array(
						'label' => __( 'Contract end date', 'zen-membership-management' ),
						'value' => $contract_end_time ? esc_html( self::format_timestamp( $contract_end_time ) ) : esc_html__( 'N/A', 'zen-membership-management' ),
					);
				}

				if ( ! $is_yearly ) {
					$rows[] = array(
						'label' => __( 'Cancellation deadline', 'zen-membership-management' ),
						'value' => $deadline ? esc_html( self::format_timestamp( $deadline ) ) : esc_html__( 'N/A', 'zen-membership-management' ),
					);
				}

				if ( $end_time ) {
					$rows[] = array(
						'label' => __( 'End date', 'zen-membership-management' ),
						'value' => esc_html( self::format_timestamp( $end_time ) ),
					);
				}
			}

			$coin_grant = self::get_membership_coin_grant( $membership );

			if ( $coin_grant > 0 ) {
				$rows[] = array(
					'label' => __( 'Included Zencoins', 'zen-membership-management' ),
					'value' => sprintf(
						/* translators: %d: Zencoin amount */
						esc_html__( '%d ZC / month', 'zen-membership-management' ),
						$coin_grant
					),
				);
			}

			if ( $subscription && ! empty( $payment ) ) {
				$rows[] = array(
					'label' => __( 'Payment method', 'zen-membership-management' ),
					'value' => esc_html( $payment ),
				);
			}

			return apply_filters( 'zmm_membership_detail_rows', $rows, $membership, $subscription );
		}

		/**
		 * Get the customer-facing status label.
		 *
		 * @param object               $membership   User membership.
		 * @param WC_Subscription|null $subscription Linked subscription.
		 * @return string
		 */
		private static function get_customer_status_label( $membership, $subscription ) {
			if ( $subscription && ( $subscription->has_status( 'pending-cancel' ) || self::is_late_cancellation_scheduled( $subscription ) ) ) {
				return __( 'Pending Cancellation', 'zen-membership-management' );
			}

			return wc_memberships_get_user_membership_status_name( $membership->get_status() );
		}

		/**
		 * Format membership date.
		 *
		 * @param object $membership User membership.
		 * @param string $type Date type.
		 * @return string
		 */
		private static function format_membership_date( $membership, $type ) {
			$method = 'start' === $type ? 'get_local_start_date' : 'get_local_end_date';
			$time   = is_callable( array( $membership, $method ) ) ? $membership->{$method}( 'timestamp' ) : 0;

			return $time ? self::format_timestamp( $time ) : __( 'N/A', 'zen-membership-management' );
		}

		/**
		 * Format timestamp using WooCommerce date settings.
		 *
		 * @param int $timestamp Timestamp.
		 * @return string
		 */
		private static function format_timestamp( $timestamp ) {
			return date_i18n( wc_date_format(), (int) $timestamp );
		}

		/**
		 * Format the next payment display value.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @param int             $next_payment Next payment timestamp.
		 * @return string
		 */
		private static function get_next_payment_display( $subscription, $next_payment ) {
			if ( self::should_show_no_upcoming_payment( $subscription ) ) {
				return __( 'No upcoming payment', 'zen-membership-management' );
			}

			return $next_payment ? self::format_timestamp( $next_payment ) : __( 'N/A', 'zen-membership-management' );
		}

		/**
		 * Get the Zencoin amount granted by the membership product.
		 *
		 * @param object $membership User membership.
		 * @return int
		 */
		private static function get_membership_coin_grant( $membership ) {
			$product_id = is_callable( array( $membership, 'get_product_id' ) ) ? (int) $membership->get_product_id( true ) : 0;

			if ( ! $product_id && is_callable( array( $membership, 'get_product_id' ) ) ) {
				$product_id = (int) $membership->get_product_id();
			}

			return $product_id ? absint( get_post_meta( $product_id, self::MEMBERSHIP_GRANT_META, true ) ) : 0;
		}

		/**
		 * Render the monthly cancellation action in its own bottom section.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function render_membership_cancellation_section( $subscription ) {
			$action = self::get_monthly_cancellation_action( $subscription );

			if ( empty( $action ) ) {
				return;
			}
			?>
			<section class="zmm-panel zmm-panel--cancel-membership">
				<h3><?php esc_html_e( 'Cancel Membership', 'zen-membership-management' ); ?></h3>
				<a class="button zmm-action zmm-action--cancel" href="<?php echo esc_url( $action['url'] ); ?>" data-zmm-cancel-trigger>
					<?php echo esc_html( $action['name'] ); ?>
				</a>
				<div class="zmm-cancel-modal" data-zmm-cancel-modal hidden>
					<div class="zmm-cancel-modal__backdrop" data-zmm-cancel-dismiss></div>
					<div class="zmm-cancel-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="zmm-cancel-modal-title-<?php echo esc_attr( $subscription->get_id() ); ?>" tabindex="-1">
						<h3 id="zmm-cancel-modal-title-<?php echo esc_attr( $subscription->get_id() ); ?>"><?php esc_html_e( 'Cancel membership?', 'zen-membership-management' ); ?></h3>
						<p><?php esc_html_e( 'Please confirm you want to cancel your monthly membership. You will continue to receive your Zencoins and benefits until the membership ends, and no further charges will be made after that date.', 'zen-membership-management' ); ?></p>
						<a class="button zmm-cancel-modal__confirm" href="<?php echo esc_url( $action['url'] ); ?>">
							<?php esc_html_e( 'Confirm Cancellation', 'zen-membership-management' ); ?>
						</a>
						<button type="button" class="button zmm-cancel-modal__keep" data-zmm-cancel-dismiss>
							<?php esc_html_e( 'Keep Membership', 'zen-membership-management' ); ?>
						</button>
					</div>
				</div>
			</section>
			<?php
		}

		/**
		 * Get non-cancellation subscription actions for the membership details table.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return array
		 */
		private static function get_subscription_primary_actions( $subscription ) {
			return array_filter(
				self::get_subscription_actions( $subscription ),
				static function ( $action, $action_key ) {
					return self::is_primary_subscription_action( $action, $action_key );
				},
				ARRAY_FILTER_USE_BOTH
			);
		}

		/**
		 * Get the cancellation action only for monthly memberships.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return array
		 */
		private static function get_monthly_cancellation_action( $subscription ) {
			if ( ! $subscription instanceof WC_Subscription || ! self::is_monthly_subscription( $subscription ) || self::is_yearly_contract_subscription( $subscription ) ) {
				return array();
			}

			foreach ( self::get_subscription_actions( $subscription ) as $action_key => $action ) {
				if ( self::is_cancellation_action( $action, $action_key ) ) {
					return $action;
				}
			}

			return array();
		}

		/**
		 * Check whether an action should remain in the membership details action row.
		 *
		 * @param array  $action     Subscription action.
		 * @param string $action_key Subscription action key.
		 * @return bool
		 */
		private static function is_primary_subscription_action( $action, $action_key ) {
			return ! self::is_cancellation_action( $action, $action_key );
		}

		/**
		 * Check whether a subscription action is the cancellation action.
		 *
		 * @param array  $action     Subscription action.
		 * @param string $action_key Subscription action key.
		 * @return bool
		 */
		private static function is_cancellation_action( $action, $action_key ) {
			$action_name = isset( $action['name'] ) ? wp_strip_all_tags( $action['name'] ) : '';
			$action_url  = isset( $action['url'] ) ? (string) $action['url'] : '';

			return 'cancel' === $action_key || false !== stripos( $action_name, 'cancel' ) || false !== strpos( $action_url, 'change_subscription_to=cancelled' );
		}

		/**
		 * Get supported subscription actions, hiding early-renewal by default.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return array
		 */
		private static function get_subscription_actions( $subscription ) {
			$actions = function_exists( 'wcs_get_all_user_actions_for_subscription' )
				? wcs_get_all_user_actions_for_subscription( $subscription, get_current_user_id() )
				: array();

			unset( $actions['renew_now'], $actions['renew'] );

			$actions = self::localize_change_payment_method_actions( $actions, $subscription );

			if ( self::is_yearly_contract_subscription( $subscription ) ) {
				$actions = self::get_yearly_contract_allowed_actions( $actions );
			} elseif ( self::is_late_cancellation_scheduled( $subscription ) ) {
				unset( $actions['cancel'] );

				$actions['zmm_reactivate_late_cancellation'] = array(
					'url'  => self::get_late_cancellation_reactivation_url( $subscription ),
					'name' => __( 'Reactivate Membership', 'zen-membership-management' ),
					'role' => 'button',
				);
			} elseif ( isset( $actions['reactivate'] ) ) {
				$actions['reactivate']['name'] = __( 'Reactivate Membership', 'zen-membership-management' );
			}

			return apply_filters( 'zmm_membership_subscription_actions', $actions, $subscription );
		}

		/**
		 * Return successful membership payment-method updates to the custom membership detail screen.
		 *
		 * @param array           $result       Gateway payment result.
		 * @param WC_Subscription $subscription Subscription.
		 * @return array
		 */
		public static function filter_change_payment_method_success_redirect( $result, $subscription ) {
			if (
				is_array( $result )
				&& isset( $result['result'] )
				&& 'success' === $result['result']
				&& $subscription instanceof WC_Subscription
				&& self::is_membership_subscription_for_current_user( $subscription )
				&& isset( $_POST['_wcsnonce'], $_POST['woocommerce_change_payment'] )
				&& wp_verify_nonce( wc_clean( wp_unslash( $_POST['_wcsnonce'] ) ), 'wcs_change_payment_method' )
			) {
				$result['redirect'] = wc_get_account_endpoint_url( self::ENDPOINT );
			}

			return $result;
		}

		/**
		 * Keep only payment-change actions for yearly contract memberships.
		 *
		 * @param array $actions Subscription actions.
		 * @return array
		 */
		private static function get_yearly_contract_allowed_actions( $actions ) {
			$allowed_actions = array();

			foreach ( $actions as $action_key => $action ) {
				$action_name = isset( $action['name'] ) ? wp_strip_all_tags( $action['name'] ) : '';

				if ( in_array( $action_key, array( 'change_payment_method', 'change_payment' ), true ) || false !== stripos( $action_name, 'change payment' ) ) {
					$allowed_actions[ $action_key ] = $action;
				}
			}

			return $allowed_actions;
		}

		/**
		 * Point subscription payment-method actions back into the My Membership account tab.
		 *
		 * @param array           $actions      Subscription actions.
		 * @param WC_Subscription $subscription Subscription.
		 * @return array
		 */
		private static function localize_change_payment_method_actions( $actions, $subscription ) {
			foreach ( $actions as $action_key => $action ) {
				$action_name = isset( $action['name'] ) ? wp_strip_all_tags( $action['name'] ) : '';

				if ( in_array( $action_key, array( 'change_payment_method', 'change_payment' ), true ) || false !== stripos( $action_name, 'change payment' ) || false !== stripos( $action_name, 'add payment' ) ) {
					$actions[ $action_key ]['url'] = self::get_change_payment_method_account_url( $subscription );
				}
			}

			return $actions;
		}

		/**
		 * Build the in-account payment-method update URL.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return string
		 */
		private static function get_change_payment_method_account_url( $subscription ) {
			return add_query_arg(
				array(
					'zmm_change_payment_method' => $subscription->get_id(),
					'change_payment_method'     => $subscription->get_id(),
					'key'                       => $subscription->get_order_key(),
					'pay_for_order'             => 'true',
					'_wpnonce'                  => wp_create_nonce(),
				),
				wc_get_account_endpoint_url( self::ENDPOINT )
			);
		}

		/**
		 * Check whether the current request should render the change-payment flow.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function is_change_payment_method_request( $subscription ) {
			$requested_id = isset( $_GET['zmm_change_payment_method'] ) ? absint( wp_unslash( $_GET['zmm_change_payment_method'] ) ) : 0;

			return $requested_id && (int) $subscription->get_id() === $requested_id;
		}

		/**
		 * Render the native WooCommerce Subscriptions change-payment form inside My Account.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function render_change_payment_method_form( $subscription ) {
			echo '<div class="zmm-membership zmm-membership--change-payment">';
			self::render_membership_header_for_change_payment();

			$valid_request = self::validate_change_payment_method_request( $subscription );

			if ( $valid_request ) {
				self::set_order_pay_query_var_for_change_payment( $subscription );
				self::prepare_subscription_customer_location( $subscription );
				self::add_change_payment_method_notice( $subscription );
			}

			wc_print_notices();

			if ( $valid_request ) {
				echo '<section class="zmm-panel zmm-panel--change-payment">';
				add_filter( 'woocommerce_is_checkout', '__return_true' );
				wc_get_template( 'checkout/form-change-payment-method.php', array( 'subscription' => $subscription ), '', WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' ) );
				remove_filter( 'woocommerce_is_checkout', '__return_true' );
				echo '</section>';
			}

			echo '</div>';
		}

		/**
		 * Render a compact heading for the payment-method update view.
		 */
		private static function render_membership_header_for_change_payment() {
			?>
			<header class="zmm-membership__header">
				<div>
					<p class="zmm-membership__eyebrow"><?php esc_html_e( 'My Membership', 'zen-membership-management' ); ?></p>
					<h2><?php esc_html_e( 'Change payment method', 'zen-membership-management' ); ?></h2>
				</div>
				<a class="button zmm-action" href="<?php echo esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ); ?>">
					<?php esc_html_e( 'Back to membership', 'zen-membership-management' ); ?>
				</a>
			</header>
			<?php
		}

		/**
		 * Validate the account-hosted change-payment request.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function validate_change_payment_method_request( $subscription ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? wc_clean( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			$key   = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';

			if ( ! $nonce || false === wp_verify_nonce( $nonce ) ) {
				wc_add_notice( __( 'There was an error with your request. Please try again.', 'zen-membership-management' ), 'error' );
				return false;
			}

			if ( ! function_exists( 'wcs_get_subscription' ) || ! $subscription instanceof WC_Subscription ) {
				wc_add_notice( __( 'Invalid subscription.', 'zen-membership-management' ), 'error' );
				return false;
			}

			if ( ! current_user_can( 'edit_shop_subscription_payment_method', $subscription->get_id() ) ) {
				wc_add_notice( __( 'That does not appear to be one of your subscriptions.', 'zen-membership-management' ), 'error' );
				return false;
			}

			if ( ! $subscription->can_be_updated_to( 'new-payment-method' ) ) {
				wc_add_notice( __( 'The payment method can not be changed for that subscription.', 'zen-membership-management' ), 'error' );
				return false;
			}

			if ( $subscription->get_order_key() !== $key ) {
				wc_add_notice( __( 'Invalid order.', 'zen-membership-management' ), 'error' );
				return false;
			}

			return true;
		}

		/**
		 * Make gateway code see this account-hosted form as the subscription order-pay flow.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function set_order_pay_query_var_for_change_payment( $subscription ) {
			global $wp;

			if ( isset( $wp ) && is_object( $wp ) ) {
				$wp->query_vars['order-pay'] = $subscription->get_id();
			}
		}

		/**
		 * Show the standard Subscriptions change-payment notice.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function add_change_payment_method_notice( $subscription ) {
			if ( $subscription->get_time( 'next_payment' ) > 0 ) {
				$next_payment_string = sprintf(
					/* translators: %s: next payment date */
					__( ' Next payment is due %s.', 'woocommerce-subscriptions' ),
					$subscription->get_date_to_display( 'next_payment' )
				);
			} else {
				$next_payment_string = '';
			}

			wc_add_notice(
				apply_filters(
					'woocommerce_subscriptions_change_payment_method_page_notice_message',
					sprintf(
						/* translators: %s: optional next payment date sentence */
						__( 'Choose a new payment method.%s', 'woocommerce-subscriptions' ),
						$next_payment_string
					),
					$subscription
				),
				'notice'
			);
		}

		/**
		 * Set the customer billing location to the subscription billing location.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function prepare_subscription_customer_location( $subscription ) {
			if ( ! WC()->customer ) {
				return;
			}

			foreach ( array( 'country', 'state', 'postcode' ) as $address_property ) {
				$subscription_address = $subscription->{"get_billing_$address_property"}();

				if ( $subscription_address ) {
					WC()->customer->{"set_billing_$address_property"}( $subscription_address );
				}
			}
		}

		/**
		 * Get custom reactivation URL for a late-cancellation scheduled subscription.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return string
		 */
		private static function get_late_cancellation_reactivation_url( $subscription ) {
			return wp_nonce_url(
				add_query_arg(
					array(
						'zmm_membership_action' => 'reactivate_late_cancellation',
						'subscription_id'       => $subscription->get_id(),
					),
					wc_get_account_endpoint_url( self::ENDPOINT )
				),
				self::get_late_cancellation_reactivation_nonce_action( $subscription )
			);
		}

		/**
		 * Get custom reactivation nonce action.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return string
		 */
		private static function get_late_cancellation_reactivation_nonce_action( $subscription ) {
			return 'zmm_reactivate_late_cancellation_' . $subscription->get_id();
		}

		/**
		 * Handle reactivation for our custom late-cancellation scheduled state.
		 */
		public static function maybe_reactivate_late_cancellation() {
			if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ! self::dependencies_loaded() || ! function_exists( 'wcs_get_subscription' ) ) {
				return;
			}

			$requested_action = isset( $_GET['zmm_membership_action'] ) ? wc_clean( wp_unslash( $_GET['zmm_membership_action'] ) ) : '';

			if ( 'reactivate_late_cancellation' !== $requested_action ) {
				return;
			}

			$subscription_id = isset( $_GET['subscription_id'] ) ? absint( wp_unslash( $_GET['subscription_id'] ) ) : 0;
			$nonce           = isset( $_GET['_wpnonce'] ) ? wc_clean( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			$subscription    = $subscription_id ? wcs_get_subscription( $subscription_id ) : null;

			if ( ! $subscription instanceof WC_Subscription || ! self::is_membership_subscription_for_current_user( $subscription ) || ! self::is_monthly_subscription( $subscription ) ) {
				return;
			}

			if ( ! wp_verify_nonce( $nonce, self::get_late_cancellation_reactivation_nonce_action( $subscription ) ) || ! current_user_can( 'edit_shop_subscription_status', $subscription->get_id() ) ) {
				wc_add_notice( __( 'We could not verify this reactivation request. Please try again.', 'zen-membership-management' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
				exit;
			}

			if ( ! self::is_late_cancellation_scheduled( $subscription ) ) {
				wc_add_notice( __( 'This membership is not scheduled for cancellation.', 'zen-membership-management' ), 'notice' );
				wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
				exit;
			}

			self::clear_late_cancellation_schedule( $subscription );

			$subscription->add_order_note( __( 'Customer reactivated membership after a late cancellation request. Scheduled cancellation was removed.', 'zen-membership-management' ) );
			$subscription->save();

			wc_add_notice( __( 'Your membership has been reactivated.', 'zen-membership-management' ), 'success' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		/**
		 * Remove the custom late-cancellation schedule from a subscription.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function clear_late_cancellation_schedule( $subscription ) {
			$subscription->delete_meta_data( self::META_CANCEL_AFTER_NEXT_PAYMENT );
			$subscription->delete_meta_data( self::META_CANCEL_REQUESTED_AT );
			$subscription->delete_meta_data( self::META_CANCEL_REQUESTED_BY );
			$subscription->delete_meta_data( self::META_CANCEL_TARGET_END );
		}

		/**
		 * Intercept monthly cancellation requests after the 7-day deadline.
		 */
		public static function maybe_intercept_late_subscription_cancellation() {
			if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ! self::dependencies_loaded() || ! function_exists( 'wcs_get_subscription' ) ) {
				return;
			}

			$requested_status = isset( $_GET['change_subscription_to'] ) ? wc_clean( wp_unslash( $_GET['change_subscription_to'] ) ) : '';

			if ( 'cancelled' !== $requested_status ) {
				return;
			}

			$subscription_id = isset( $_GET['subscription_id'] ) ? absint( wp_unslash( $_GET['subscription_id'] ) ) : 0;
			$nonce           = isset( $_GET['_wpnonce'] ) ? wc_clean( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			$subscription    = $subscription_id ? wcs_get_subscription( $subscription_id ) : null;

			if ( ! $subscription instanceof WC_Subscription || ! self::is_membership_subscription_for_current_user( $subscription ) ) {
				return;
			}

			if ( self::is_yearly_contract_subscription( $subscription ) ) {
				self::block_yearly_contract_cancellation_request();
			}

			if ( ! self::is_monthly_subscription( $subscription ) ) {
				return;
			}

			if ( ! self::is_after_cancellation_deadline( $subscription ) ) {
				return;
			}

			if ( ! self::customer_can_cancel_subscription( $subscription, $nonce ) ) {
				wc_add_notice( __( 'We could not verify this cancellation request. Please try again.', 'zen-membership-management' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
				exit;
			}

			if ( self::is_late_cancellation_scheduled( $subscription ) ) {
				wc_add_notice( __( 'Your membership cancellation is already scheduled after the next paid month.', 'zen-membership-management' ), 'notice' );
				wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
				exit;
			}

			self::schedule_late_cancellation( $subscription );

			wc_add_notice( __( 'Your cancellation has been accepted. The upcoming payment will still be charged, and your membership will end after that paid month.', 'zen-membership-management' ), 'success' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		/**
		 * Block customer cancellation for yearly contract memberships.
		 */
		private static function block_yearly_contract_cancellation_request() {
			wc_add_notice( __( 'Yearly memberships end automatically on the contract end date. You can update your payment method while the membership is active.', 'zen-membership-management' ), 'notice' );
			wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
			exit;
		}

		/**
		 * Move a late-cancelled subscription to pending cancellation after the charged renewal.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @param WC_Order|null   $last_order   Renewal order.
		 */
		public static function complete_scheduled_late_cancellation( $subscription, $last_order = null ) {
			if ( ! $subscription instanceof WC_Subscription || ! self::is_late_cancellation_scheduled( $subscription ) ) {
				return;
			}

			if ( $subscription->has_status( 'pending-cancel' ) ) {
				$subscription->delete_meta_data( self::META_CANCEL_AFTER_NEXT_PAYMENT );
				$subscription->save();
				return;
			}

			if ( ! $subscription->can_be_updated_to( 'cancelled' ) ) {
				return;
			}

			$subscription->cancel_order( __( 'Subscription moved to pending cancellation after the final charged renewal.', 'zen-membership-management' ) );
			$subscription->delete_meta_data( self::META_CANCEL_AFTER_NEXT_PAYMENT );
			$subscription->save();

			if ( $last_order instanceof WC_Order ) {
				$last_order->add_order_note( __( 'Membership cancellation request completed after this renewal payment.', 'zen-membership-management' ) );
			}
		}

		/**
		 * Cancel immediately if the required final renewal payment fails.
		 *
		 * @param WC_Subscription $subscription  Subscription.
		 * @param WC_Order|null   $renewal_order Failed renewal order.
		 */
		public static function cancel_scheduled_late_cancellation_after_failed_renewal( $subscription, $renewal_order = null ) {
			if ( ! $subscription instanceof WC_Subscription || ! self::is_late_cancellation_scheduled( $subscription ) ) {
				return;
			}

			self::clear_late_cancellation_schedule( $subscription );

			$note = __( 'Membership cancellation request completed immediately because the required final renewal payment failed.', 'zen-membership-management' );

			if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
				$subscription->update_status( 'cancelled', $note );
			} else {
				$subscription->add_order_note( $note );
			}

			$subscription->save();

			if ( $renewal_order instanceof WC_Order ) {
				$renewal_order->add_order_note( __( 'Membership was cancelled because this required final renewal payment failed.', 'zen-membership-management' ) );
			}
		}

		/**
		 * Schedule cancellation after the next successful renewal payment.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function schedule_late_cancellation( $subscription ) {
			$subscription->update_meta_data( self::META_CANCEL_AFTER_NEXT_PAYMENT, 'yes' );
			$subscription->update_meta_data( self::META_CANCEL_REQUESTED_AT, current_time( 'mysql', true ) );
			$subscription->update_meta_data( self::META_CANCEL_REQUESTED_BY, get_current_user_id() );

			$target_end_time = self::get_late_cancellation_target_end_time( $subscription );

			if ( $target_end_time ) {
				$subscription->update_meta_data( self::META_CANCEL_TARGET_END, gmdate( 'Y-m-d H:i:s', $target_end_time ) );
			}

			$subscription->add_order_note( __( 'Customer requested cancellation after the deadline. Subscription will be set to pending cancellation after the next successful renewal payment.', 'zen-membership-management' ) );
			$subscription->save();
		}

		/**
		 * Validate the cancel link for the current customer.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @param string          $nonce        Request nonce.
		 * @return bool
		 */
		private static function customer_can_cancel_subscription( $subscription, $nonce ) {
			return get_current_user_id()
				&& wp_verify_nonce( $nonce, $subscription->get_id() . $subscription->get_status() )
				&& current_user_can( 'edit_shop_subscription_status', $subscription->get_id() )
				&& $subscription->can_be_updated_to( 'cancelled' );
		}

		/**
		 * Check whether this is the account user's linked membership subscription.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function is_membership_subscription_for_current_user( $subscription ) {
			$membership          = self::get_current_user_membership();
			$linked_subscription = $membership ? self::get_membership_subscription( $membership ) : null;

			return $linked_subscription instanceof WC_Subscription && (int) $linked_subscription->get_id() === (int) $subscription->get_id();
		}

		/**
		 * Check whether the subscription uses the monthly rule set.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function is_monthly_subscription( $subscription ) {
			return 1 === (int) $subscription->get_billing_interval() && 'month' === $subscription->get_billing_period();
		}

		/**
		 * Check whether the subscription is a monthly-billed yearly contract.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function is_yearly_contract_subscription( $subscription ) {
			$is_yearly_contract = false;

			if ( self::is_monthly_subscription( $subscription ) ) {
				foreach ( $subscription->get_items() as $item ) {
					$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;

					if ( $product instanceof WC_Product && self::product_has_yearly_contract_meta( $product ) && self::subscription_item_indicates_yearly_contract( $item, $product ) ) {
						$is_yearly_contract = true;
						break;
					}
				}
			}

			return (bool) apply_filters( 'zmm_is_yearly_contract_subscription', $is_yearly_contract, $subscription );
		}

		/**
		 * Check whether a product has the monthly-billed yearly contract subscription settings.
		 *
		 * @param WC_Product $product Product.
		 * @return bool
		 */
		private static function product_has_yearly_contract_meta( $product ) {
			foreach ( self::get_product_and_parent_ids( $product ) as $product_id ) {
				$length   = absint( get_post_meta( $product_id, '_subscription_length', true ) );
				$period   = (string) get_post_meta( $product_id, '_subscription_period', true );
				$interval = absint( get_post_meta( $product_id, '_subscription_period_interval', true ) );

				if ( 12 === $length && 'month' === $period && 1 === $interval ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check whether the purchased item represents the yearly membership variation.
		 *
		 * @param WC_Order_Item_Product $item    Subscription item.
		 * @param WC_Product            $product Product.
		 * @return bool
		 */
		private static function subscription_item_indicates_yearly_contract( $item, $product ) {
			if ( self::text_indicates_yearly_contract( $item->get_name() ) || self::text_indicates_yearly_contract( $product->get_name() ) ) {
				return true;
			}

			foreach ( (array) $product->get_attributes() as $attribute_value ) {
				if ( is_array( $attribute_value ) ) {
					$attribute_value = implode( ' ', $attribute_value );
				}

				if ( self::text_indicates_yearly_contract( (string) $attribute_value ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check whether text identifies the yearly contract variation.
		 *
		 * @param string $text Text.
		 * @return bool
		 */
		private static function text_indicates_yearly_contract( $text ) {
			return false !== stripos( (string) $text, 'yearly' );
		}

		/**
		 * Get a product ID and its parent ID when applicable.
		 *
		 * @param WC_Product $product Product.
		 * @return array
		 */
		private static function get_product_and_parent_ids( $product ) {
			$product_ids = array( (int) $product->get_id() );

			if ( $product->is_type( 'variation' ) || $product->is_type( 'subscription_variation' ) ) {
				$product_ids[] = (int) $product->get_parent_id();
			}

			return array_values( array_unique( array_filter( $product_ids ) ) );
		}

		/**
		 * Check whether cancellation was requested after the monthly deadline.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function is_after_cancellation_deadline( $subscription ) {
			$deadline = self::get_cancellation_deadline_time( $subscription );

			return $deadline && current_time( 'timestamp', true ) > $deadline;
		}

		/**
		 * Get cancellation deadline timestamp.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return int
		 */
		private static function get_cancellation_deadline_time( $subscription ) {
			$next_payment = $subscription->get_time( 'next_payment' );

			return $next_payment ? max( 0, (int) $next_payment - ( self::get_cancellation_deadline_days() * DAY_IN_SECONDS ) ) : 0;
		}

		/**
		 * Get configured cancellation deadline in days.
		 *
		 * @return int
		 */
		private static function get_cancellation_deadline_days() {
			$configured_days = get_option( self::OPTION_CANCELLATION_DEADLINE_DAYS, self::CANCELLATION_DEADLINE_DAYS );

			return max(
				0,
				absint(
					apply_filters(
						'zmm_monthly_cancellation_deadline_days',
						$configured_days
					)
				)
			);
		}

		/**
		 * Get yearly contract end timestamp.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return int
		 */
		private static function get_yearly_contract_end_time( $subscription ) {
			$end_time = (int) $subscription->get_time( 'end' );

			if ( $end_time > 0 ) {
				return $end_time;
			}

			$start_time = (int) $subscription->get_time( 'start' );

			if ( $start_time && function_exists( 'wcs_add_time' ) ) {
				return (int) wcs_add_time( 1, 'year', $start_time );
			}

			return $start_time ? (int) strtotime( '+1 year', $start_time ) : 0;
		}

		/**
		 * Get the customer-facing cancellation end timestamp.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return int
		 */
		private static function get_cancellation_end_time( $subscription ) {
			if ( self::is_late_cancellation_scheduled( $subscription ) ) {
				return self::get_late_cancellation_target_end_time( $subscription );
			}

			if ( $subscription->has_status( 'pending-cancel' ) ) {
				return (int) $subscription->get_time( 'end' );
			}

			return 0;
		}

		/**
		 * Check whether the table should show no upcoming payment.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function should_show_no_upcoming_payment( $subscription ) {
			return $subscription->has_status( 'pending-cancel' ) && ! self::is_late_cancellation_scheduled( $subscription );
		}

		/**
		 * Check scheduled late cancellation flag.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return bool
		 */
		private static function is_late_cancellation_scheduled( $subscription ) {
			return 'yes' === $subscription->get_meta( self::META_CANCEL_AFTER_NEXT_PAYMENT, true );
		}

		/**
		 * Calculate the date when a late-cancelled membership should end.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 * @return int
		 */
		private static function get_late_cancellation_target_end_time( $subscription ) {
			$stored_target = $subscription->get_meta( self::META_CANCEL_TARGET_END, true );

			if ( $stored_target ) {
				return (int) strtotime( $stored_target . ' UTC' );
			}

			$next_payment = $subscription->get_time( 'next_payment' );

			if ( ! $next_payment ) {
				return 0;
			}

			if ( function_exists( 'wcs_add_time' ) ) {
				return (int) wcs_add_time( $subscription->get_billing_interval(), $subscription->get_billing_period(), $next_payment );
			}

			return (int) strtotime( '+' . (int) $subscription->get_billing_interval() . ' ' . $subscription->get_billing_period(), $next_payment );
		}

		/**
		 * Render native subscription totals.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function render_subscription_totals( $subscription ) {
			if ( ! has_action( 'woocommerce_subscription_totals_table' ) ) {
				return;
			}

			echo '<section class="zmm-panel zmm-panel--totals">';
			do_action( 'woocommerce_subscription_totals_table', $subscription );
			echo '</section>';
		}

		/**
		 * Render native related orders table.
		 *
		 * @param WC_Subscription $subscription Subscription.
		 */
		private static function render_related_orders( $subscription ) {
			if ( ! has_action( 'woocommerce_subscription_details_after_subscription_table' ) ) {
				return;
			}

			echo '<section class="zmm-panel zmm-panel--orders">';
			do_action( 'woocommerce_subscription_details_after_subscription_table', $subscription );
			echo '</section>';
		}

		/**
		 * Block adding a membership product when it would create multiple memberships.
		 *
		 * @param bool $passed     Validation state.
		 * @param int  $product_id Product ID.
		 * @param int  $quantity   Quantity.
		 * @return bool
		 */
		public static function validate_single_membership_add_to_cart( $passed, $product_id, $quantity ) {
			if ( ! $passed || ! self::dependencies_loaded() ) {
				return $passed;
			}

			$product = wc_get_product( $product_id );

			if ( ! $product || ! self::product_grants_membership_access( $product ) ) {
				return $passed;
			}

			if ( self::customer_has_membership() || self::cart_contains_membership_product() ) {
				wc_add_notice( __( 'Only one membership can be active at a time. Please manage your existing membership before choosing another plan.', 'zen-membership-management' ), 'error' );
				return false;
			}

			return $passed;
		}

		/**
		 * Validate cart contents before checkout.
		 */
		public static function validate_single_membership_cart() {
			if ( ! self::dependencies_loaded() || ! WC()->cart ) {
				return;
			}

			$membership_items = 0;

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;

				if ( $product && self::product_grants_membership_access( $product ) ) {
					$membership_items += (int) $cart_item['quantity'];
				}
			}

			if ( $membership_items > 1 || ( $membership_items > 0 && self::customer_has_membership() ) ) {
				wc_add_notice( __( 'Your cart can contain only one membership, and customers can have only one active membership.', 'zen-membership-management' ), 'error' );
			}
		}

		/**
		 * Check if customer already has a current membership.
		 *
		 * @return bool
		 */
		private static function customer_has_membership() {
			return (bool) self::get_current_user_membership();
		}

		/**
		 * Check cart for membership-granting products.
		 *
		 * @return bool
		 */
		private static function cart_contains_membership_product() {
			if ( ! WC()->cart ) {
				return false;
			}

			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;

				if ( $product && self::product_grants_membership_access( $product ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Determine whether a product grants access to a membership plan.
		 *
		 * @param WC_Product $product Product.
		 * @return bool
		 */
		private static function product_grants_membership_access( $product ) {
			if ( ! $product instanceof WC_Product || ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
				return false;
			}

			$product_ids = array( (int) $product->get_id() );

			if ( $product->is_type( 'variation' ) || $product->is_type( 'subscription_variation' ) ) {
				$product_ids[] = (int) $product->get_parent_id();
			}

			foreach ( wc_memberships_get_membership_plans() as $plan ) {
				if ( ! is_object( $plan ) || ! is_callable( array( $plan, 'has_product' ) ) ) {
					continue;
				}

				foreach ( array_filter( $product_ids ) as $product_id ) {
					if ( $plan->has_product( $product_id ) ) {
						return true;
					}
				}
			}

			return false;
		}
	}

	ZMM_Zen_Membership_Management::init();
}
