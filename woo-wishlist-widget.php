<?php
/**
 * Plugin Name: WooCommerce Wishlist Widget - Favorite Products And More
 * Description: A WooCommerce add-on that allows customers to wishlist products via a widget.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * Author: Connor Bryant
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-wishlist-widget
 */

/*
 * This plugin, all included libraries, and any other included assets
 * are licensed as GPL or under a GPL-compatible license.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register activation and deactivation callbacks
 *
 * These must remain outside wishlist_init()
 */
register_activation_hook( __FILE__, 'wishlist_activate' );
register_deactivation_hook( __FILE__, 'wishlist_deactivate' );

/**
 * Declare HPOS compatibility.
 */
add_action( 'before_woocommerce_init', 'wishlist_declare_hpos_compatibility' );

/**
 * Wait until plugins have loaded before checking for WooCommerce
 */
add_action( 'plugins_loaded', 'wishlist_init' );

/**
 * Initialize the plugin's WooCommerce functionality
 */
function wishlist_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	add_action( 'wp_enqueue_scripts', 'wishlist_enqueue_assets' );

	add_action( 'init', 'wishlist_register_endpoint' );

	add_filter( 'woocommerce_account_menu_items', 'wishlist_add_menu_item' );

	add_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_endpoint_content' );

	add_action( 'woocommerce_after_add_to_cart_form', 'wishlist_product_button' );

    add_action( 'woocommerce_before_shop_loop_item_title', 'wishlist_add_heart_to_product_card', 5 );

    add_action( 'wp_footer', 'wishlist_widget' );

    add_action( 'wp_ajax_wishlist_toggle', 'wishlist_ajax_toggle' );
    
    add_action( 'woocommerce_before_customer_login_form', 'wishlist_login_notice' );
}

/**
 * Enqueue frontend scripts and styles
 */
function wishlist_enqueue_assets() {
	$version = '1.0.0';

	wp_enqueue_style(
		'wishlist-mode-style',
		plugin_dir_url( __FILE__ ) . 'wishlist-mode.css',
		array(),
		$version
	);

    wp_enqueue_style(
        'wishlist-fontawesome',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css',
        array(),
        '7.0.1'
    );

	wp_enqueue_script(
		'wishlist-mode-script',
		plugin_dir_url( __FILE__ ) . 'wishlist-mode.js',
		array( 'jquery' ),
		$version,
		true
	);

    wp_localize_script(
    	'wishlist-mode-script',
    	'wishlistData',
    	array(
    		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
    		'nonce'        => wp_create_nonce( 'wishlist_nonce' ),
    		'isLoggedIn'   => is_user_logged_in(),
    		'wishlistUrl'  => wc_get_account_endpoint_url( 'wishlist' ),
    	)
    );
}

/**
 * Register the Wishlist account endpoint
 */
function wishlist_register_endpoint() {
	add_rewrite_endpoint(
		'wishlist',
		EP_ROOT | EP_PAGES
	);
}

/**
 * Add Wishlist to the My Account navigation
 */
function wishlist_add_menu_item( $items ) {
	$items['wishlist'] = __(
		'Wishlist',
		'woocommerce-wishlist-widget'
	);

	return $items;
}

/**
 * Display the Wishlist endpoint content.
 */
function wishlist_endpoint_content() {

	/*
	 * Show a login/account prompt when the visitor is logged out.
	 */
	if ( ! is_user_logged_in() ) {
		?>
		<div class="wishlist-login-message">
			<h2>
				<?php
				esc_html_e(
					'Save Your Favorite Products',
					'woocommerce-wishlist-widget'
				);
				?>
			</h2>

			<p>
				<?php
				esc_html_e(
					'Log in or create an account to save products to your wishlist and access them later.',
					'woocommerce-wishlist-widget'
				);
				?>
			</p>

			<a
				class="button"
				href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"
			>
				<?php
				esc_html_e(
					'Log In or Create an Account',
					'woocommerce-wishlist-widget'
				);
				?>
			</a>
		</div>
		<?php

		return;
	}

	$wishlist = wishlist_get_user_wishlist();

	?>
	<h2>
		<?php
		esc_html_e(
			'My Wishlist',
			'woocommerce-wishlist-widget'
		);
		?>
	</h2>
	<?php

	/*
	 * Display an empty-wishlist message.
	 */
	if ( empty( $wishlist ) ) {
		?>
		<div class="wishlist-empty">
			<p class="wishlist-empty-message">
				<?php
				esc_html_e(
					'Your wishlist is currently empty.',
					'woocommerce-wishlist-widget'
				);
				?>
			</p>

			<a
				class="button"
				href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
			>
				<?php
				esc_html_e(
					'Continue Shopping',
					'woocommerce-wishlist-widget'
				);
				?>
			</a>
		</div>
		<?php

		return;
	}

	/*
	 * Display the saved products.
	 */
	?>
	<div class="wishlist-products">
		<?php
		foreach ( $wishlist as $product_id ) {
			$product = wc_get_product( $product_id );

			/*
			 * Skip products that were deleted or are no longer visible.
			 */
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}

			$product_url = $product->get_permalink();
			?>
			<div
				class="wishlist-product"
				data-product-id="<?php echo esc_attr( $product_id ); ?>"
			>
				<button
					type="button"
					class="wishlist-heart-btn wishlist-remove-button favorited"
					data-product-id="<?php echo esc_attr( $product_id ); ?>"
					aria-pressed="true"
					aria-label="<?php esc_attr_e(
						'Remove from wishlist',
						'woocommerce-wishlist-widget'
					); ?>"
				>
					<i
						class="fa-solid fa-xmark"
						aria-hidden="true"
					></i>
				</button>

				<a
					class="wishlist-product-image"
					href="<?php echo esc_url( $product_url ); ?>"
				>
					<?php echo wp_kses_post( $product->get_image() ); ?>
				</a>

				<div class="wishlist-product-information">
					<a href="<?php echo esc_url( $product_url ); ?>">
						<strong>
							<?php echo esc_html( $product->get_name() ); ?>
						</strong>
					</a>

					<?php if ( $product->get_sku() ) : ?>
						<span class="wishlist-product-sku">
							<?php
							printf(
								/* translators: %s is the product SKU. */
								esc_html__(
									'SKU: %s',
									'woocommerce-wishlist-widget'
								),
								esc_html( $product->get_sku() )
							);
							?>
						</span>
					<?php endif; ?>
				</div>

				<div class="wishlist-product-price">
					<?php echo wp_kses_post( $product->get_price_html() ); ?>
				</div>

				<div class="wishlist-product-cart">
					<?php
					if (
						$product->is_purchasable() &&
						$product->is_in_stock()
					) :
						?>
						<a
							href="<?php echo esc_url(
								$product->add_to_cart_url()
							); ?>"
							class="button add_to_cart_button ajax_add_to_cart"
							data-product_id="<?php echo esc_attr( $product_id ); ?>"
							data-product_sku="<?php echo esc_attr( $product->get_sku() ); ?>"
							data-quantity="1"
							aria-label="<?php echo esc_attr(
								$product->add_to_cart_description()
							); ?>"
							rel="nofollow"
						>
							<?php
							echo esc_html(
								$product->add_to_cart_text()
							);
							?>
						</a>
					<?php else : ?>
						<span class="wishlist-product-unavailable">
							<?php
							esc_html_e(
								'Currently unavailable',
								'woocommerce-wishlist-widget'
							);
							?>
						</span>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
		?>
	</div>

	<div class="wishlist-actions">
		<a
			class="button wishlist-continue-shopping"
			href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>"
		>
			<?php
			esc_html_e(
				'Continue Shopping',
				'woocommerce-wishlist-widget'
			);
			?>
		</a>
	</div>
	<?php
}

/**
 * Display the wishlist button on product pages
 *
 * Placeholder until storage functionality is added
 */
function wishlist_product_button() {
	?>
	<button type="button" class="button wishlist-product-button">
		<?php
		esc_html_e(
			'Add to Wishlist',
			'woocommerce-wishlist-widget'
		);
		?>
	</button>
	<?php
}

/**
 * Display the sticky wishlist widget (vertical tab)
 */
function wishlist_widget() {
    $count = is_user_logged_in()
		? count( wishlist_get_user_wishlist() )
		: 0;
    ?>
    <a
        href="<?php echo esc_url( wc_get_account_endpoint_url( 'wishlist' ) ); ?>"
        class="wishlist-sticky-tab"
        aria-label="<?php esc_attr_e( 'View wishlist', 'woocommerce-wishlist-widget' ); ?>"
    >
        <i class="fa-solid fa-heart wishlist-sticky-icon"></i>
        
        <span
            class="wishlist-count<?php echo 0 === $count ? ' is-empty' : ''; ?>"
            aria-live="polite"
        >
            <?php echo esc_html( $count ); ?>
        </span>

        <!--<span class="wishlist-sticky-text">Wishlist</span>-->
    </a>
    <?php
}

/**
 * Get user wishlist as an array of IDs
 */
function wishlist_get_user_wishlist(){
    if ( ! is_user_logged_in() ){
        return [];
    }
    $user_id = get_current_user_id();
    $wishlist = get_user_meta($user_id, '_wishlist_products', true);
    return is_array($wishlist) ? $wishlist : [];
}

/**
 * Save wishlist
 */
function wishlist_save_user_wishlist($wishlist){
    if ( ! is_user_logged_in() ){
        return false;
    }
    $user_id = get_current_user_id();
    return update_user_meta($user_id, '_wishlist_products', array_unique(array_map('intval', $wishlist)));
}

/**
 * AJAX Toggle Handler
 */
function wishlist_ajax_toggle(){
    check_ajax_referer('wishlist_nonce', 'nonce');

    if ( ! is_user_logged_in() ){
        wp_send_json_error('Must be logged in');
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if ( ! $product_id || ! wc_get_product($product_id)){
        wp_send_json_error('Invalid product');
    }

    $wishlist = wishlist_get_user_wishlist();
    $is_favorited = in_array($product_id, $wishlist);

    if ($is_favorited){
        $wishlist = array_diff($wishlist, [$product_id]);
        $action = 'removed';
    } else {
        $wishlist[] = $product_id;
        $action = 'added';
    }

    wishlist_save_user_wishlist($wishlist);

    wp_send_json_success([
        'action' => $action,
        'count' => count($wishlist),
        'product_id' => $product_id,
        'is_favorited' => ! $is_favorited
    ]);
}

/**
 * Add the wishlist heart to WooCommerce product cards.
 */
function wishlist_add_heart_to_product_card() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$product_id  = $product->get_id();
	$wishlist     = is_user_logged_in()
    	? wishlist_get_user_wishlist()
    	: array();
    
    $is_favorited = in_array(
    	$product->get_id(),
    	$wishlist,
    	true
    );
	?>
	<div class="wishlist-heart-wrapper">
		<button
			type="button"
			class="wishlist-heart-btn<?php echo $is_favorited ? ' favorited' : ''; ?>"
			data-product-id="<?php echo esc_attr( $product_id ); ?>"
			aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>"
			aria-label="<?php echo esc_attr(
				$is_favorited
					? __( 'Remove from wishlist', 'woocommerce-wishlist-widget' )
					: __( 'Add to wishlist', 'woocommerce-wishlist-widget' )
			); ?>"
		>
			<span class="wishlist-heart-icon wishlist-heart-empty">
				<i class="fa-regular fa-heart" aria-hidden="true"></i>
			</span>

			<span class="wishlist-heart-icon wishlist-heart-filled">
				<i class="fa-solid fa-heart" aria-hidden="true"></i>
			</span>

			<span class="wishlist-heart-label">
				<?php esc_html_e( 'Favorites', 'woocommerce-wishlist-widget' ); ?>
			</span>
		</button>
	</div>
	<?php
}

/**
 * Show a wishlist notice above the WooCommerce login/register form.
 */
function wishlist_login_notice() {
	if ( is_user_logged_in() ) {
		return;
	}

	global $wp;

	$is_wishlist_endpoint = isset( $wp->query_vars['wishlist'] );

	if ( ! $is_wishlist_endpoint && isset( $_SERVER['REQUEST_URI'] ) ) {
		$request_path  = wp_parse_url(
			wp_unslash( $_SERVER['REQUEST_URI'] ),
			PHP_URL_PATH
		);
		$wishlist_path = wp_parse_url(
			wc_get_account_endpoint_url( 'wishlist' ),
			PHP_URL_PATH
		);

		$is_wishlist_endpoint =
			untrailingslashit( $request_path ) ===
			untrailingslashit( $wishlist_path );
	}

	if ( ! $is_wishlist_endpoint ) {
		return;
	}
	?>
	<div class="woocommerce-info wishlist-login-notice">
		<strong>
			<?php
			esc_html_e(
				'Want to save your favorite products?',
				'woocommerce-wishlist-widget'
			);
			?>
		</strong>

		<p>
			<?php
			esc_html_e(
				'Log in or create an account below to save products to your wishlist and access them later.',
				'woocommerce-wishlist-widget'
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Register the endpoint and flush rewrite rules on activation.
 */
function wishlist_activate() {
	wishlist_register_endpoint();
	flush_rewrite_rules();
}

/**
 * Flush rewrite rules on deactivation.
 */
function wishlist_deactivate() {
	flush_rewrite_rules();
}

/**
 * Declare compatibility with WooCommerce HPOS.
 */
function wishlist_declare_hpos_compatibility() {
	if (
		class_exists(
			\Automattic\WooCommerce\Utilities\FeaturesUtil::class
		)
	) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
}