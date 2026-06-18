<?php
/**
 * Plugin Name: Zen Membership Management
 * Description: Customer-facing membership management for Zenctuary accounts.
 * Version: 0.1.0
 * Author: Custom
 * Text Domain: zen-membership-management
 *
 * @package ZenMembershipManagement
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'ZMM_Zen_Membership_Management' ) ) {
	final class ZMM_Zen_Membership_Management {

		const VERSION = '0.1.0';
		const ENDPOINT = 'my-membership';
		const MEMBERSHIP_GRANT_META = '_cbb_coin_grant_amount';
		const CANCELLATION_DEADLINE_DAYS = 7;

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

			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_single_membership_add_to_cart' ), 20, 3 );
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_single_membership_cart' ) );

			if ( is_admin() ) {
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

			echo '<div class="zmm-membership">';
			self::render_membership_header( $membership );

			if ( $subscription ) {
				self::render_subscription_details( $subscription );
			}

			self::render_membership_details( $membership );

			if ( $subscription ) {
				self::render_subscription_totals( $subscription );
				self::render_related_orders( $subscription );
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
		 * Render subscription details.
		 *
		 * @param WC_Subscription $subscription Linked subscription.
		 */
		private static function render_subscription_details( $subscription ) {
			$rows = self::get_subscription_detail_rows( $subscription );
			?>
			<section class="zmm-panel">
				<h3><?php esc_html_e( 'Subscription', 'zen-membership-management' ); ?></h3>
				<table class="shop_table shop_table_responsive zmm-details">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><?php echo wp_kses_post( $row['value'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php $actions = self::get_subscription_actions( $subscription ); ?>
						<?php if ( ! empty( $actions ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Actions', 'zen-membership-management' ); ?></th>
								<td class="zmm-actions">
									<?php foreach ( $actions as $key => $action ) : ?>
										<a class="button zmm-action zmm-action--<?php echo esc_attr( sanitize_html_class( $key ) ); ?>" href="<?php echo esc_url( $action['url'] ); ?>">
											<?php echo esc_html( $action['name'] ); ?>
										</a>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
			<?php
		}

		/**
		 * Render membership details.
		 *
		 * @param object $membership User membership.
		 */
		private static function render_membership_details( $membership ) {
			$rows = self::get_membership_detail_rows( $membership );
			?>
			<section class="zmm-panel">
				<h3><?php esc_html_e( 'Membership', 'zen-membership-management' ); ?></h3>
				<table class="shop_table shop_table_responsive zmm-details">
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td><?php echo wp_kses_post( $row['value'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
			<?php
		}

		/**
		 * Build subscription detail rows.
		 *
		 * @param WC_Subscription $subscription Linked subscription.
		 * @return array
		 */
		private static function get_subscription_detail_rows( $subscription ) {
			$next_payment = $subscription->get_time( 'next_payment' );
			$rows = array(
				array(
					'label' => __( 'Status', 'zen-membership-management' ),
					'value' => esc_html( self::get_subscription_status_label( $subscription ) ),
				),
				array(
					'label' => __( 'Start date', 'zen-membership-management' ),
					'value' => $subscription->get_time( 'start' ) ? esc_html( self::format_timestamp( $subscription->get_time( 'start' ) ) ) : esc_html__( 'N/A', 'zen-membership-management' ),
				),
				array(
					'label' => __( 'Next payment date', 'zen-membership-management' ),
					'value' => $next_payment ? esc_html( self::format_timestamp( $next_payment ) ) : esc_html__( 'N/A', 'zen-membership-management' ),
				),
				array(
					'label' => __( 'Cancellation deadline', 'zen-membership-management' ),
					'value' => $next_payment ? esc_html( self::format_timestamp( strtotime( '-' . self::CANCELLATION_DEADLINE_DAYS . ' days', $next_payment ) ) ) : esc_html__( 'N/A', 'zen-membership-management' ),
				),
			);

			if ( $subscription->get_time( 'next_payment' ) > 0 ) {
				$rows[] = array(
					'label' => __( 'Payment method', 'zen-membership-management' ),
					'value' => esc_html( $subscription->get_payment_method_to_display( 'customer' ) ),
				);
			}

			return apply_filters( 'zmm_subscription_detail_rows', $rows, $subscription );
		}

		/**
		 * Build membership detail rows.
		 *
		 * @param object $membership User membership.
		 * @return array
		 */
		private static function get_membership_detail_rows( $membership ) {
			$rows = array(
				array(
					'label' => __( 'Status', 'zen-membership-management' ),
					'value' => esc_html( wc_memberships_get_user_membership_status_name( $membership->get_status() ) ),
				),
				array(
					'label' => __( 'Start date', 'zen-membership-management' ),
					'value' => esc_html( self::format_membership_date( $membership, 'start' ) ),
				),
			);

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

			return apply_filters( 'zmm_membership_detail_rows', $rows, $membership );
		}

		/**
		 * Get the customer-facing subscription status label.
		 *
		 * @param WC_Subscription $subscription Linked subscription.
		 * @return string
		 */
		private static function get_subscription_status_label( $subscription ) {
			if ( $subscription->has_status( 'pending-cancel' ) ) {
				return __( 'Pending Cancellation', 'zen-membership-management' );
			}

			return function_exists( 'wcs_get_subscription_status_name' )
				? wcs_get_subscription_status_name( $subscription->get_status() )
				: wc_get_order_status_name( $subscription->get_status() );
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

			return apply_filters( 'zmm_membership_subscription_actions', $actions, $subscription );
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
