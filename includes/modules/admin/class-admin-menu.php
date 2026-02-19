<?php
/**
 * Admin menu and screens.
 *
 * @package WbcomSmartUpsellOrderBump
 */

namespace Wbcom\SmartUpsell\Modules\Admin;

use Wbcom\SmartUpsell\Modules\Analytics\AnalyticsRepository;
use Wbcom\SmartUpsell\Modules\Offers\OfferRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AdminMenu {
	/**
	 * Repository.
	 *
	 * @var OfferRepository
	 */
	private OfferRepository $offers;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->offers = new OfferRepository();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wbcom_suo_save_offer', array( $this, 'handle_save_offer' ) );
		add_action( 'admin_post_wbcom_suo_delete_offer', array( $this, 'handle_delete_offer' ) );
	}

	/**
	 * Add plugin menu.
	 */
	public function add_menu(): void {
		if ( isset( $GLOBALS['admin_page_hooks']['wbcomplugins'] ) ) {
			add_submenu_page(
				'wbcomplugins',
				__( 'Smart Upsell', 'wbcom-smart-upsell-order-bump' ),
				__( 'Smart Upsell', 'wbcom-smart-upsell-order-bump' ),
				'manage_woocommerce',
				'wbcom-smart-upsell',
				array( $this, 'render_page' )
			);
			return;
		}

		add_menu_page(
			__( 'Wbcom', 'wbcom-smart-upsell-order-bump' ),
			__( 'Wbcom', 'wbcom-smart-upsell-order-bump' ),
			'manage_woocommerce',
			'wbcom-smart-upsell-root',
			array( $this, 'render_page' ),
			'dashicons-chart-line',
			56
		);

		add_submenu_page(
			'wbcom-smart-upsell-root',
			__( 'Smart Upsell', 'wbcom-smart-upsell-order-bump' ),
			__( 'Smart Upsell', 'wbcom-smart-upsell-order-bump' ),
			'manage_woocommerce',
			'wbcom-smart-upsell',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		register_setting(
			'wbcom_suo_settings_group',
			'wbcom_suo_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize module settings.
	 *
	 * @param array $settings Submitted settings.
	 */
	public function sanitize_settings( $settings ): array {
		$clean                       = get_option( 'wbcom_suo_settings', array() );
		$clean                       = is_array( $clean ) ? $clean : array();
		$clean['enable_order_bumps'] = empty( $settings['enable_order_bumps'] ) ? 0 : 1;
		$clean['enable_post_purchase_upsell'] = empty( $settings['enable_post_purchase_upsell'] ) ? 0 : 1;
		$clean['enable_cart_bumps']  = empty( $settings['enable_cart_bumps'] ) ? 0 : 1;
		$clean['enable_analytics']   = empty( $settings['enable_analytics'] ) ? 0 : 1;
		$clean['disable_reports']    = empty( $settings['disable_reports'] ) ? 0 : 1;
		$clean['lazy_load_scripts']  = empty( $settings['lazy_load_scripts'] ) ? 0 : 1;
		$clean['cache_mode']         = empty( $settings['cache_mode'] ) ? 0 : 1;
		$clean['animation']          = empty( $settings['animation'] ) ? 0 : 1;
		$clean['template_style']     = in_array( $settings['template_style'] ?? 'minimal', array( 'minimal', 'modern', 'highlight', 'banner' ), true ) ? $settings['template_style'] : 'minimal';

		return $clean;
	}

	/**
	 * Save offer request handler.
	 */
	public function handle_save_offer(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wbcom-smart-upsell-order-bump' ) );
		}

		check_admin_referer( 'wbcom_suo_save_offer' );

		$offer_id = absint( $_POST['offer_id'] ?? 0 );
		$payload  = array(
			'name'                     => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'offer_type'               => sanitize_key( wp_unslash( $_POST['offer_type'] ?? 'checkout' ) ),
			'status'                   => sanitize_key( wp_unslash( $_POST['status'] ?? 'draft' ) ),
			'priority'                 => absint( $_POST['priority'] ?? 10 ),
			'product_id'               => absint( $_POST['product_id'] ?? 0 ),
			'discount_type'            => sanitize_key( wp_unslash( $_POST['discount_type'] ?? 'fixed' ) ),
			'discount_value'           => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['discount_value'] ?? '0' ) ), 2 ),
			'title'                    => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description'              => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'display_type'             => sanitize_key( wp_unslash( $_POST['display_type'] ?? 'checkbox' ) ),
			'position'                 => sanitize_key( wp_unslash( $_POST['position'] ?? 'before_payment' ) ),
			'trigger_product_ids'      => sanitize_text_field( wp_unslash( $_POST['trigger_product_ids'] ?? '' ) ),
			'trigger_category_ids'     => sanitize_text_field( wp_unslash( $_POST['trigger_category_ids'] ?? '' ) ),
			'min_cart_total'           => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['min_cart_total'] ?? '0' ) ), 2 ),
			'max_cart_total'           => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['max_cart_total'] ?? '0' ) ), 2 ),
			'quantity_threshold'       => absint( $_POST['quantity_threshold'] ?? 0 ),
			'user_roles'               => sanitize_text_field( wp_unslash( $_POST['user_roles'] ?? '' ) ),
			'customer_email'           => sanitize_email( wp_unslash( $_POST['customer_email'] ?? '' ) ),
			'lifetime_spend_threshold' => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['lifetime_spend_threshold'] ?? '0' ) ), 2 ),
			'purchase_frequency_min'   => absint( $_POST['purchase_frequency_min'] ?? 0 ),
			'country_codes'            => sanitize_text_field( wp_unslash( $_POST['country_codes'] ?? '' ) ),
			'device_target'            => sanitize_key( wp_unslash( $_POST['device_target'] ?? 'all' ) ),
			'first_time_only'          => empty( $_POST['first_time_only'] ) ? 0 : 1,
			'returning_only'           => empty( $_POST['returning_only'] ) ? 0 : 1,
			'purchased_product_ids'    => sanitize_text_field( wp_unslash( $_POST['purchased_product_ids'] ?? '' ) ),
			'purchased_category_ids'   => sanitize_text_field( wp_unslash( $_POST['purchased_category_ids'] ?? '' ) ),
			'schedule_start'           => sanitize_text_field( wp_unslash( $_POST['schedule_start'] ?? '' ) ),
			'schedule_end'             => sanitize_text_field( wp_unslash( $_POST['schedule_end'] ?? '' ) ),
			'weekdays'                 => sanitize_text_field( wp_unslash( $_POST['weekdays'] ?? '' ) ),
			'start_time'               => sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) ),
			'end_time'                 => sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) ),
			'skip_if_in_cart'          => empty( $_POST['skip_if_in_cart'] ) ? 0 : 1,
			'skip_if_purchased'        => empty( $_POST['skip_if_purchased'] ) ? 0 : 1,
			'dismiss_limit'            => absint( $_POST['dismiss_limit'] ?? 3 ),
			'thank_you_message'        => sanitize_textarea_field( wp_unslash( $_POST['thank_you_message'] ?? '' ) ),
			'skip_label'               => sanitize_text_field( wp_unslash( $_POST['skip_label'] ?? '' ) ),
			'show_image'               => empty( $_POST['show_image'] ) ? 0 : 1,
			'auto_charge'              => empty( $_POST['auto_charge'] ) ? 0 : 1,
			'abandoned_enabled'        => empty( $_POST['abandoned_enabled'] ) ? 0 : 1,
			'exit_intent_enabled'      => empty( $_POST['exit_intent_enabled'] ) ? 0 : 1,
			'abandoned_delay_seconds'  => absint( $_POST['abandoned_delay_seconds'] ?? 0 ),
			'coupon_code'              => sanitize_text_field( wp_unslash( $_POST['coupon_code'] ?? '' ) ),
			'coupon_auto_apply'        => empty( $_POST['coupon_auto_apply'] ) ? 0 : 1,
			'coupon_usage_limit'       => absint( $_POST['coupon_usage_limit'] ?? 0 ),
			'countdown_mode'           => sanitize_key( wp_unslash( $_POST['countdown_mode'] ?? 'none' ) ),
			'countdown_end'            => sanitize_text_field( wp_unslash( $_POST['countdown_end'] ?? '' ) ),
			'countdown_minutes'        => absint( $_POST['countdown_minutes'] ?? 0 ),
			'ab_testing_enabled'       => empty( $_POST['ab_testing_enabled'] ) ? 0 : 1,
			'ab_split_percentage'      => absint( $_POST['ab_split_percentage'] ?? 50 ),
			'ab_auto_winner'           => empty( $_POST['ab_auto_winner'] ) ? 0 : 1,
			'ab_min_views'             => absint( $_POST['ab_min_views'] ?? 100 ),
			'ab_variant_b_title'       => sanitize_text_field( wp_unslash( $_POST['ab_variant_b_title'] ?? '' ) ),
			'ab_variant_b_description' => sanitize_textarea_field( wp_unslash( $_POST['ab_variant_b_description'] ?? '' ) ),
			'ab_variant_b_discount_type' => sanitize_key( wp_unslash( $_POST['ab_variant_b_discount_type'] ?? '' ) ),
			'ab_variant_b_discount_value' => wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['ab_variant_b_discount_value'] ?? '0' ) ), 2 ),
			'bundle_mode'              => sanitize_key( wp_unslash( $_POST['bundle_mode'] ?? 'none' ) ),
			'bundle_product_ids'       => sanitize_text_field( wp_unslash( $_POST['bundle_product_ids'] ?? '' ) ),
			'bundle_limit'             => absint( $_POST['bundle_limit'] ?? 3 ),
		);

		$this->offers->save_offer( $payload, $offer_id > 0 ? $offer_id : null );

		$redirect = add_query_arg(
			array(
				'page'    => 'wbcom-smart-upsell',
				'tab'     => $this->tab_for_type( $payload['offer_type'] ),
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Delete offer request handler.
	 */
	public function handle_delete_offer(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wbcom-smart-upsell-order-bump' ) );
		}

		check_admin_referer( 'wbcom_suo_delete_offer' );

		$offer_id = absint( $_GET['offer_id'] ?? 0 );
		$type     = sanitize_key( wp_unslash( $_GET['offer_type'] ?? 'checkout' ) );
		if ( $offer_id > 0 ) {
			$this->offers->delete_offer( $offer_id );
		}

		$redirect = add_query_arg(
			array(
				'page'    => 'wbcom-smart-upsell',
				'tab'     => $this->tab_for_type( $type ),
				'deleted' => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Enqueue admin styles.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'wbcom-smart-upsell' ) ) {
			return;
		}

		wp_enqueue_style( 'wbcom-suo-admin', WBCOM_SUO_PLUGIN_URL . 'assets/css/admin.css', array(), WBCOM_SUO_VERSION );
		wp_enqueue_script( 'wbcom-suo-admin', WBCOM_SUO_PLUGIN_URL . 'assets/js/admin.js', array(), WBCOM_SUO_VERSION, true );
	}

	/**
	 * Render admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings   = get_option( 'wbcom_suo_settings', array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing for admin page render.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
		$tabs       = array(
			'dashboard'   => __( 'Dashboard', 'wbcom-smart-upsell-order-bump' ),
			'order-bumps' => __( 'Order Bumps', 'wbcom-smart-upsell-order-bump' ),
			'upsells'     => __( 'Upsells', 'wbcom-smart-upsell-order-bump' ),
			'funnels'     => __( 'Funnels', 'wbcom-smart-upsell-order-bump' ),
			'analytics'   => __( 'Analytics', 'wbcom-smart-upsell-order-bump' ),
			'settings'    => __( 'Settings', 'wbcom-smart-upsell-order-bump' ),
		);
		?>
		<div class="wrap wbcom-suo-admin">
			<h1><?php esc_html_e( 'Wbcom Smart Upsell & Order Bump', 'wbcom-smart-upsell-order-bump' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a class="nav-tab <?php echo esc_attr( $active_tab === $tab_key ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=wbcom-smart-upsell&tab=' . $tab_key ) ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'dashboard' === $active_tab ) : ?>
				<?php $this->render_dashboard(); ?>
			<?php elseif ( 'analytics' === $active_tab ) : ?>
				<?php $this->render_analytics(); ?>
			<?php elseif ( in_array( $active_tab, array( 'order-bumps', 'upsells', 'funnels' ), true ) ) : ?>
				<?php $this->render_offer_management( $active_tab ); ?>
			<?php else : ?>
				<?php $this->render_settings_form( $settings ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render dashboard.
	 */
	private function render_dashboard(): void {
		$stats = ( new AnalyticsRepository() )->get_summary();
		?>
		<div class="wbcom-suo-cards">
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Offer Views', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo esc_html( (string) $stats['views'] ); ?></span></div>
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Conversions', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo esc_html( (string) $stats['conversions'] ); ?></span></div>
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Conversion Rate', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo esc_html( (string) $stats['conversion_rate'] ); ?>%</span></div>
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Revenue', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo wp_kses_post( wc_price( (float) $stats['revenue'] ) ); ?></span></div>
		</div>
		<?php
	}

	/**
	 * Render analytics list.
	 */
	private function render_analytics(): void {
		$filters = array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics filtering in admin.
			'offer_type' => sanitize_key( wp_unslash( $_GET['filter_offer_type'] ?? '' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics filtering in admin.
			'offer_id'   => absint( $_GET['filter_offer_id'] ?? 0 ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics filtering in admin.
			'product_id' => absint( $_GET['filter_product_id'] ?? 0 ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics filtering in admin.
			'start_date' => sanitize_text_field( wp_unslash( $_GET['filter_start'] ?? '' ) ),
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analytics filtering in admin.
			'end_date'   => sanitize_text_field( wp_unslash( $_GET['filter_end'] ?? '' ) ),
		);
		$repo = new AnalyticsRepository();
		$rows = $repo->get_top_offers( 20, $filters );
		$stats = $repo->get_summary( $filters );
		$products = $this->get_offer_products_for_filter();
		$offers_index = $this->get_offer_index_by_id();
		?>
		<form method="get" class="wbcom-suo-analytics-filters">
			<input type="hidden" name="page" value="wbcom-smart-upsell">
			<input type="hidden" name="tab" value="analytics">
			<label><?php esc_html_e( 'Offer Type', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="filter_offer_type">
					<option value=""><?php esc_html_e( 'All', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="checkout" <?php selected( $filters['offer_type'], 'checkout' ); ?>><?php esc_html_e( 'Checkout', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="cart" <?php selected( $filters['offer_type'], 'cart' ); ?>><?php esc_html_e( 'Cart', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="post_purchase" <?php selected( $filters['offer_type'], 'post_purchase' ); ?>><?php esc_html_e( 'Post Purchase', 'wbcom-smart-upsell-order-bump' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Offer ID', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="0" name="filter_offer_id" value="<?php echo esc_attr( (string) $filters['offer_id'] ); ?>"></label>
			<label><?php esc_html_e( 'Product', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="filter_product_id">
					<option value="0"><?php esc_html_e( 'All', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<?php foreach ( $products as $product_id => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $product_id ); ?>" <?php selected( $filters['product_id'], $product_id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><?php esc_html_e( 'Start Date', 'wbcom-smart-upsell-order-bump' ); ?> <input type="date" name="filter_start" value="<?php echo esc_attr( $filters['start_date'] ); ?>"></label>
			<label><?php esc_html_e( 'End Date', 'wbcom-smart-upsell-order-bump' ); ?> <input type="date" name="filter_end" value="<?php echo esc_attr( $filters['end_date'] ); ?>"></label>
			<?php submit_button( __( 'Apply Filters', 'wbcom-smart-upsell-order-bump' ), 'secondary', '', false ); ?>
		</form>

		<div class="wbcom-suo-cards">
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Views', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo esc_html( (string) $stats['views'] ); ?></span></div>
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Conversions', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo esc_html( (string) $stats['conversions'] ); ?></span></div>
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Conversion Rate', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo esc_html( (string) $stats['conversion_rate'] ); ?>%</span></div>
			<div class="wbcom-suo-card"><strong><?php esc_html_e( 'Revenue', 'wbcom-smart-upsell-order-bump' ); ?></strong><span><?php echo wp_kses_post( wc_price( (float) $stats['revenue'] ) ); ?></span></div>
		</div>

		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Offer Type', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Offer ID', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Product', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Views', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Conversions', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Revenue', 'wbcom-smart-upsell-order-bump' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No analytics data yet.', 'wbcom-smart-upsell-order-bump' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$offer_id = (int) $row->offer_id;
					$product_text = '-';
					if ( isset( $offers_index[ $offer_id ] ) ) {
						$product_id = (int) ( $offers_index[ $offer_id ]['product_id'] ?? 0 );
						$product = $product_id > 0 ? wc_get_product( $product_id ) : false;
						if ( $product ) {
							$product_text = $product->get_name() . ' (#' . $product_id . ')';
						} elseif ( $product_id > 0 ) {
							$product_text = '#' . $product_id;
						}
					}
					?>
					<tr>
						<td><?php echo esc_html( $row->offer_type ); ?></td>
						<td><?php echo esc_html( (string) $offer_id ); ?></td>
						<td><?php echo esc_html( $product_text ); ?></td>
						<td><?php echo esc_html( (string) $row->views ); ?></td>
						<td><?php echo esc_html( (string) $row->conversions ); ?></td>
						<td><?php echo wp_kses_post( wc_price( (float) $row->revenue ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render offer CRUD screen.
	 */
	private function render_offer_management( string $tab ): void {
		$offer_type = $this->type_for_tab( $tab );
		$offers     = $this->offers->get_offers_by_type( $offer_type );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit context in admin.
		$edit_id    = absint( $_GET['edit_offer'] ?? 0 );
		$editing    = $edit_id > 0 ? $this->offers->get_offer( $edit_id ) : array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice in admin.
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Offer saved.', 'wbcom-smart-upsell-order-bump' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status notice in admin.
		if ( ! empty( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Offer deleted.', 'wbcom-smart-upsell-order-bump' ) . '</p></div>';
		}
		?>
		<h2><?php echo esc_html( ucfirst( str_replace( '-', ' ', $tab ) ) ); ?></h2>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Name', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Product', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Status', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Priority', 'wbcom-smart-upsell-order-bump' ); ?></th><th><?php esc_html_e( 'Actions', 'wbcom-smart-upsell-order-bump' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $offers ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No offers created yet.', 'wbcom-smart-upsell-order-bump' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $offers as $offer ) : ?>
					<tr>
						<td><?php echo esc_html( $offer['name'] ?: $offer['title'] ); ?></td>
						<td><?php echo esc_html( (string) $offer['product_id'] ); ?></td>
						<td><?php echo esc_html( ucfirst( $offer['status'] ) ); ?></td>
						<td><?php echo esc_html( (string) $offer['priority'] ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'wbcom-smart-upsell', 'tab' => $tab, 'edit_offer' => $offer['id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'wbcom-smart-upsell-order-bump' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wbcom_suo_delete_offer', 'offer_id' => $offer['id'], 'offer_type' => $offer_type ), admin_url( 'admin-post.php' ) ), 'wbcom_suo_delete_offer' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this offer?', 'wbcom-smart-upsell-order-bump' ) ); ?>');"><?php esc_html_e( 'Delete', 'wbcom-smart-upsell-order-bump' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>

		<h3><?php echo esc_html( $editing ? __( 'Edit Offer', 'wbcom-smart-upsell-order-bump' ) : __( 'Create Offer', 'wbcom-smart-upsell-order-bump' ) ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wbcom-suo-form">
			<?php wp_nonce_field( 'wbcom_suo_save_offer' ); ?>
			<input type="hidden" name="action" value="wbcom_suo_save_offer">
			<input type="hidden" name="offer_id" value="<?php echo esc_attr( (string) ( $editing['id'] ?? 0 ) ); ?>">
			<input type="hidden" name="offer_type" value="<?php echo esc_attr( $offer_type ); ?>">
			<p><label><?php esc_html_e( 'Internal Name', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Status', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="status"><option value="active" <?php selected( $editing['status'] ?? 'active', 'active' ); ?>><?php esc_html_e( 'Active', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="draft" <?php selected( $editing['status'] ?? 'active', 'draft' ); ?>><?php esc_html_e( 'Draft', 'wbcom-smart-upsell-order-bump' ); ?></option></select>
			</label>
			<label><?php esc_html_e( 'Priority', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" name="priority" min="0" value="<?php echo esc_attr( (string) ( $editing['priority'] ?? 10 ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Product ID', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" name="product_id" min="1" required value="<?php echo esc_attr( (string) ( $editing['product_id'] ?? '' ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Title', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="title" class="regular-text" value="<?php echo esc_attr( $editing['title'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Description', 'wbcom-smart-upsell-order-bump' ); ?> <textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $editing['description'] ?? '' ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Discount Type', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="discount_type"><option value="fixed" <?php selected( $editing['discount_type'] ?? 'fixed', 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="percent" <?php selected( $editing['discount_type'] ?? 'fixed', 'percent' ); ?>><?php esc_html_e( 'Percent', 'wbcom-smart-upsell-order-bump' ); ?></option></select>
			</label>
			<label><?php esc_html_e( 'Discount Value', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" step="0.01" min="0" name="discount_value" value="<?php echo esc_attr( (string) ( $editing['discount_value'] ?? 0 ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Display Type', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="display_type"><option value="checkbox" <?php selected( $editing['display_type'] ?? 'checkbox', 'checkbox' ); ?>><?php esc_html_e( 'Checkbox', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="highlight" <?php selected( $editing['display_type'] ?? 'checkbox', 'highlight' ); ?>><?php esc_html_e( 'Highlight', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="inline" <?php selected( $editing['display_type'] ?? 'checkbox', 'inline' ); ?>><?php esc_html_e( 'Inline', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="popup" <?php selected( $editing['display_type'] ?? 'checkbox', 'popup' ); ?>><?php esc_html_e( 'Popup', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="grid" <?php selected( $editing['display_type'] ?? 'checkbox', 'grid' ); ?>><?php esc_html_e( 'Grid', 'wbcom-smart-upsell-order-bump' ); ?></option></select>
			</label>
			<label><?php esc_html_e( 'Position', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="position"><option value="before_payment" <?php selected( $editing['position'] ?? 'before_payment', 'before_payment' ); ?>><?php esc_html_e( 'Before Payment', 'wbcom-smart-upsell-order-bump' ); ?></option><option value="after_order_summary" <?php selected( $editing['position'] ?? 'before_payment', 'after_order_summary' ); ?>><?php esc_html_e( 'After Order Summary', 'wbcom-smart-upsell-order-bump' ); ?></option></select>
			</label></p>
			<p><label><input type="checkbox" name="show_image" value="1" <?php checked( ! empty( $editing['show_image'] ) ); ?>> <?php esc_html_e( 'Show product image in offer', 'wbcom-smart-upsell-order-bump' ); ?></label></p>
			<p><label><?php esc_html_e( 'Trigger Product IDs (comma-separated)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="trigger_product_ids" class="regular-text" value="<?php echo esc_attr( $editing['trigger_product_ids'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Trigger Category IDs (comma-separated)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="trigger_category_ids" class="regular-text" value="<?php echo esc_attr( $editing['trigger_category_ids'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Min Cart Total', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" step="0.01" min="0" name="min_cart_total" value="<?php echo esc_attr( (string) ( $editing['min_cart_total'] ?? '' ) ); ?>"></label>
			<label><?php esc_html_e( 'Max Cart Total', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" step="0.01" min="0" name="max_cart_total" value="<?php echo esc_attr( (string) ( $editing['max_cart_total'] ?? '' ) ); ?>"></label>
			<label><?php esc_html_e( 'Min Quantity', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="0" name="quantity_threshold" value="<?php echo esc_attr( (string) ( $editing['quantity_threshold'] ?? 0 ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'User Roles (comma-separated)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="user_roles" class="regular-text" value="<?php echo esc_attr( $editing['user_roles'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Specific Customer Email', 'wbcom-smart-upsell-order-bump' ); ?> <input type="email" name="customer_email" class="regular-text" value="<?php echo esc_attr( $editing['customer_email'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Lifetime Spend Threshold', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" step="0.01" min="0" name="lifetime_spend_threshold" value="<?php echo esc_attr( (string) ( $editing['lifetime_spend_threshold'] ?? '' ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Purchase Frequency Min Orders', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="0" name="purchase_frequency_min" value="<?php echo esc_attr( (string) ( $editing['purchase_frequency_min'] ?? 0 ) ); ?>"></label>
			<label><?php esc_html_e( 'Country Codes (US,CA,...)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="country_codes" class="regular-text" value="<?php echo esc_attr( $editing['country_codes'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Device Target', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="device_target">
					<option value="all" <?php selected( $editing['device_target'] ?? 'all', 'all' ); ?>><?php esc_html_e( 'All', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="mobile" <?php selected( $editing['device_target'] ?? 'all', 'mobile' ); ?>><?php esc_html_e( 'Mobile', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="desktop" <?php selected( $editing['device_target'] ?? 'all', 'desktop' ); ?>><?php esc_html_e( 'Desktop', 'wbcom-smart-upsell-order-bump' ); ?></option>
				</select>
			</label></p>
			<p><label><input type="checkbox" name="first_time_only" value="1" <?php checked( ! empty( $editing['first_time_only'] ) ); ?>> <?php esc_html_e( 'First-time buyers only', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><input type="checkbox" name="returning_only" value="1" <?php checked( ! empty( $editing['returning_only'] ) ); ?>> <?php esc_html_e( 'Returning customers only', 'wbcom-smart-upsell-order-bump' ); ?></label></p>
			<p><label><?php esc_html_e( 'Purchased Product IDs Before', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="purchased_product_ids" class="regular-text" value="<?php echo esc_attr( $editing['purchased_product_ids'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Purchased Category IDs Before', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="purchased_category_ids" class="regular-text" value="<?php echo esc_attr( $editing['purchased_category_ids'] ?? '' ); ?>"></label></p>
			<p><label><input type="checkbox" name="skip_if_in_cart" value="1" <?php checked( ! empty( $editing['skip_if_in_cart'] ) ); ?>> <?php esc_html_e( 'Skip if product already in cart', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><input type="checkbox" name="skip_if_purchased" value="1" <?php checked( ! empty( $editing['skip_if_purchased'] ) ); ?>> <?php esc_html_e( 'Skip if purchased before', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><input type="checkbox" name="auto_charge" value="1" <?php checked( ! empty( $editing['auto_charge'] ) ); ?>> <?php esc_html_e( 'Enable one-click auto charge (if supported)', 'wbcom-smart-upsell-order-bump' ); ?></label></p>
			<p><label><?php esc_html_e( 'Schedule Start', 'wbcom-smart-upsell-order-bump' ); ?> <input type="date" name="schedule_start" value="<?php echo esc_attr( $editing['schedule_start'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Schedule End', 'wbcom-smart-upsell-order-bump' ); ?> <input type="date" name="schedule_end" value="<?php echo esc_attr( $editing['schedule_end'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Weekdays (mon,tue,...)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="weekdays" value="<?php echo esc_attr( $editing['weekdays'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Start Time (HH:MM)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="time" name="start_time" value="<?php echo esc_attr( $editing['start_time'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'End Time (HH:MM)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="time" name="end_time" value="<?php echo esc_attr( $editing['end_time'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Thank You Message', 'wbcom-smart-upsell-order-bump' ); ?> <textarea name="thank_you_message" rows="2" class="large-text"><?php echo esc_textarea( $editing['thank_you_message'] ?? '' ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Skip Label', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="skip_label" value="<?php echo esc_attr( $editing['skip_label'] ?? '' ); ?>"></label></p>
			<hr>
			<h4><?php esc_html_e( 'Abandoned Cart / Exit Intent', 'wbcom-smart-upsell-order-bump' ); ?></h4>
			<p><label><input type="checkbox" name="abandoned_enabled" value="1" <?php checked( ! empty( $editing['abandoned_enabled'] ) ); ?>> <?php esc_html_e( 'Enable abandoned cart bump mode', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><input type="checkbox" name="exit_intent_enabled" value="1" <?php checked( ! empty( $editing['exit_intent_enabled'] ) ); ?>> <?php esc_html_e( 'Show on exit intent', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><?php esc_html_e( 'Delay Seconds', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="0" name="abandoned_delay_seconds" value="<?php echo esc_attr( (string) ( $editing['abandoned_delay_seconds'] ?? 0 ) ); ?>"></label></p>
			<hr>
			<h4><?php esc_html_e( 'Coupon Offer', 'wbcom-smart-upsell-order-bump' ); ?></h4>
			<p><label><?php esc_html_e( 'Coupon Code', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="coupon_code" value="<?php echo esc_attr( $editing['coupon_code'] ?? '' ); ?>"></label>
			<label><input type="checkbox" name="coupon_auto_apply" value="1" <?php checked( ! empty( $editing['coupon_auto_apply'] ) ); ?>> <?php esc_html_e( 'Auto apply coupon', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><?php esc_html_e( 'Coupon Usage Limit', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="0" name="coupon_usage_limit" value="<?php echo esc_attr( (string) ( $editing['coupon_usage_limit'] ?? 0 ) ); ?>"></label></p>
			<hr>
			<h4><?php esc_html_e( 'Countdown Timer', 'wbcom-smart-upsell-order-bump' ); ?></h4>
			<p><label><?php esc_html_e( 'Mode', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="countdown_mode">
					<option value="none" <?php selected( $editing['countdown_mode'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="fixed" <?php selected( $editing['countdown_mode'] ?? 'none', 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="evergreen" <?php selected( $editing['countdown_mode'] ?? 'none', 'evergreen' ); ?>><?php esc_html_e( 'Evergreen', 'wbcom-smart-upsell-order-bump' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Fixed End Datetime (UTC)', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="countdown_end" placeholder="2026-12-31 23:59:59" value="<?php echo esc_attr( $editing['countdown_end'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Evergreen Minutes', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="1" name="countdown_minutes" value="<?php echo esc_attr( (string) ( $editing['countdown_minutes'] ?? 15 ) ); ?>"></label></p>
			<hr>
			<h4><?php esc_html_e( 'A/B Testing', 'wbcom-smart-upsell-order-bump' ); ?></h4>
			<p><label><input type="checkbox" name="ab_testing_enabled" value="1" <?php checked( ! empty( $editing['ab_testing_enabled'] ) ); ?>> <?php esc_html_e( 'Enable A/B testing', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><?php esc_html_e( 'Variation A Traffic %', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="1" max="99" name="ab_split_percentage" value="<?php echo esc_attr( (string) ( $editing['ab_split_percentage'] ?? 50 ) ); ?>"></label>
			<label><input type="checkbox" name="ab_auto_winner" value="1" <?php checked( ! empty( $editing['ab_auto_winner'] ) ); ?>> <?php esc_html_e( 'Auto winner', 'wbcom-smart-upsell-order-bump' ); ?></label>
			<label><?php esc_html_e( 'Min views per variant', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="10" name="ab_min_views" value="<?php echo esc_attr( (string) ( $editing['ab_min_views'] ?? 100 ) ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Variation B Title', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="ab_variant_b_title" class="regular-text" value="<?php echo esc_attr( $editing['ab_variant_b_title'] ?? '' ); ?>"></label></p>
			<p><label><?php esc_html_e( 'Variation B Description', 'wbcom-smart-upsell-order-bump' ); ?> <textarea name="ab_variant_b_description" rows="2" class="large-text"><?php echo esc_textarea( $editing['ab_variant_b_description'] ?? '' ); ?></textarea></label></p>
			<p><label><?php esc_html_e( 'Variation B Discount Type', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="ab_variant_b_discount_type">
					<option value="" <?php selected( $editing['ab_variant_b_discount_type'] ?? '', '' ); ?>><?php esc_html_e( 'Same as A', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="fixed" <?php selected( $editing['ab_variant_b_discount_type'] ?? '', 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="percent" <?php selected( $editing['ab_variant_b_discount_type'] ?? '', 'percent' ); ?>><?php esc_html_e( 'Percent', 'wbcom-smart-upsell-order-bump' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Variation B Discount Value', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" step="0.01" min="0" name="ab_variant_b_discount_value" value="<?php echo esc_attr( (string) ( $editing['ab_variant_b_discount_value'] ?? 0 ) ); ?>"></label></p>
			<hr>
			<h4><?php esc_html_e( 'Smart Bundles', 'wbcom-smart-upsell-order-bump' ); ?></h4>
			<p><label><?php esc_html_e( 'Bundle Mode', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="bundle_mode">
					<option value="none" <?php selected( $editing['bundle_mode'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="fbt" <?php selected( $editing['bundle_mode'] ?? 'none', 'fbt' ); ?>><?php esc_html_e( 'Frequently Bought Together', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="same_category" <?php selected( $editing['bundle_mode'] ?? 'none', 'same_category' ); ?>><?php esc_html_e( 'Same Category', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="tag_match" <?php selected( $editing['bundle_mode'] ?? 'none', 'tag_match' ); ?>><?php esc_html_e( 'Tag Match', 'wbcom-smart-upsell-order-bump' ); ?></option>
					<option value="manual" <?php selected( $editing['bundle_mode'] ?? 'none', 'manual' ); ?>><?php esc_html_e( 'Manual Product IDs', 'wbcom-smart-upsell-order-bump' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Bundle Product IDs', 'wbcom-smart-upsell-order-bump' ); ?> <input type="text" name="bundle_product_ids" value="<?php echo esc_attr( $editing['bundle_product_ids'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Bundle Limit', 'wbcom-smart-upsell-order-bump' ); ?> <input type="number" min="1" max="8" name="bundle_limit" value="<?php echo esc_attr( (string) ( $editing['bundle_limit'] ?? 3 ) ); ?>"></label></p>
			<?php submit_button( $editing ? __( 'Update Offer', 'wbcom-smart-upsell-order-bump' ) : __( 'Create Offer', 'wbcom-smart-upsell-order-bump' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Render settings screen.
	 */
	private function render_settings_form( array $settings ): void {
		?>
		<form method="post" action="options.php" class="wbcom-suo-form">
			<?php settings_fields( 'wbcom_suo_settings_group' ); ?>
			<h2><?php esc_html_e( 'General Settings', 'wbcom-smart-upsell-order-bump' ); ?></h2>
			<label><input type="checkbox" name="wbcom_suo_settings[enable_order_bumps]" value="1" <?php checked( ! empty( $settings['enable_order_bumps'] ) ); ?>> <?php esc_html_e( 'Enable checkout order bumps', 'wbcom-smart-upsell-order-bump' ); ?></label><br>
			<label><input type="checkbox" name="wbcom_suo_settings[enable_post_purchase_upsell]" value="1" <?php checked( ! empty( $settings['enable_post_purchase_upsell'] ) ); ?>> <?php esc_html_e( 'Enable post-purchase upsells', 'wbcom-smart-upsell-order-bump' ); ?></label><br>
			<label><input type="checkbox" name="wbcom_suo_settings[enable_cart_bumps]" value="1" <?php checked( ! empty( $settings['enable_cart_bumps'] ) ); ?>> <?php esc_html_e( 'Enable cart bumps', 'wbcom-smart-upsell-order-bump' ); ?></label><br>
			<label><input type="checkbox" name="wbcom_suo_settings[enable_analytics]" value="1" <?php checked( ! empty( $settings['enable_analytics'] ) ); ?>> <?php esc_html_e( 'Enable analytics tracking', 'wbcom-smart-upsell-order-bump' ); ?></label><br>
			<label><input type="checkbox" name="wbcom_suo_settings[lazy_load_scripts]" value="1" <?php checked( ! empty( $settings['lazy_load_scripts'] ) ); ?>> <?php esc_html_e( 'Lazy load scripts', 'wbcom-smart-upsell-order-bump' ); ?></label><br>
			<label><input type="checkbox" name="wbcom_suo_settings[disable_reports]" value="1" <?php checked( ! empty( $settings['disable_reports'] ) ); ?>> <?php esc_html_e( 'Disable reports', 'wbcom-smart-upsell-order-bump' ); ?></label><br>
			<label><?php esc_html_e( 'Default Template', 'wbcom-smart-upsell-order-bump' ); ?>
				<select name="wbcom_suo_settings[template_style]" id="wbcom-suo-template-style">
					<?php foreach ( array( 'minimal', 'modern', 'highlight', 'banner' ) as $style ) : ?>
						<option value="<?php echo esc_attr( $style ); ?>" <?php selected( $settings['template_style'] ?? 'minimal', $style ); ?>><?php echo esc_html( ucfirst( $style ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<div class="wbcom-suo-template-preview-wrap">
				<p><strong><?php esc_html_e( 'Live Preview', 'wbcom-smart-upsell-order-bump' ); ?></strong></p>
				<div id="wbcom-suo-template-preview" class="wbcom-suo-offer wbcom-suo-template-<?php echo esc_attr( $settings['template_style'] ?? 'minimal' ); ?>">
					<label><input type="checkbox" checked disabled> <strong><?php esc_html_e( 'Sample Offer Title', 'wbcom-smart-upsell-order-bump' ); ?></strong></label>
					<p><?php esc_html_e( 'This is how your default template style will look on the storefront.', 'wbcom-smart-upsell-order-bump' ); ?></p>
					<p class="wbcom-suo-price"><?php echo wp_kses_post( wc_price( 9.99 ) ); ?></p>
				</div>
			</div>
			<?php submit_button( __( 'Save Settings', 'wbcom-smart-upsell-order-bump' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Build product options from configured offers for analytics filtering.
	 *
	 * @return array<int,string>
	 */
	private function get_offer_products_for_filter(): array {
		$index = $this->get_offer_index_by_id();
		$products = array();

		foreach ( $index as $offer ) {
			$product_id = (int) ( $offer['product_id'] ?? 0 );
			if ( $product_id <= 0 || isset( $products[ $product_id ] ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			$products[ $product_id ] = $product ? $product->get_name() . ' (#' . $product_id . ')' : '#' . $product_id;
		}

		asort( $products );
		return $products;
	}

	/**
	 * Index all offers by ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_offer_index_by_id(): array {
		$all = array_merge(
			$this->offers->get_offers_by_type( 'checkout' ),
			$this->offers->get_offers_by_type( 'cart' ),
			$this->offers->get_offers_by_type( 'post_purchase' )
		);
		$index = array();

		foreach ( $all as $offer ) {
			$index[ (int) $offer['id'] ] = $offer;
		}

		return $index;
	}

	/**
	 * Convert offer type to tab name.
	 */
	private function tab_for_type( string $type ): string {
		if ( 'post_purchase' === $type ) {
			return 'upsells';
		}
		if ( 'cart' === $type ) {
			return 'funnels';
		}
		return 'order-bumps';
	}

	/**
	 * Convert tab name to offer type.
	 */
	private function type_for_tab( string $tab ): string {
		if ( 'upsells' === $tab ) {
			return 'post_purchase';
		}
		if ( 'funnels' === $tab ) {
			return 'cart';
		}
		return 'checkout';
	}
}
