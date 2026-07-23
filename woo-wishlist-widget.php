<?php
/**
 * Plugin Name: WooCommerce Wishlist Widget - Favorite Products And More
 * Description: A WooCommerce add-on that allows customers to wishlist products via a widget.
 * Version: 1.1.0
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
    
    // Public endpoint registration
    add_action( 'init', 'wishlist_register_public_endpoint' );

    add_filter( 'woocommerce_account_menu_items', 'wishlist_add_menu_item' );

    add_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_endpoint_content' );

    add_action( 'woocommerce_after_add_to_cart_button', 'wishlist_product_button' );

    // Standard WooCommerce product loops.
    // JavaScript provides the fallback for builders that do not run this hook.
    add_action( 'woocommerce_before_shop_loop_item_title', 'wishlist_add_heart_to_product_card', 5 );

    add_action( 'wp_footer', 'wishlist_widget' );

    add_action( 'wp_ajax_wishlist_toggle', 'wishlist_ajax_toggle' );
    add_action( 'wp_ajax_wishlist_resolve_product_url', 'wishlist_ajax_resolve_product_url' );
    add_action( 'wp_ajax_nopriv_wishlist_resolve_product_url', 'wishlist_ajax_resolve_product_url' );
    
    add_action( 'woocommerce_before_customer_login_form', 'wishlist_login_notice' );
    
    // AJAX action for toggling public status
    add_action( 'wp_ajax_wishlist_toggle_public', 'wishlist_ajax_toggle_public' );
    
    // Share section to the wishlist endpoint
    add_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_add_share_section_to_endpoint', 5 );
    
    // Template filter for public wishlist
    add_filter( 'template_include', 'wishlist_public_template_include', 99 );
}

/**
 * Handle public wishlist viewing using template hierarchy
 */
function wishlist_public_template_include( $template ) {
    global $wp_query;
    
    // Check if this is a public wishlist request
    if ( isset( $wp_query->query_vars['wishlist_public'] ) ) {
        $wishlist_key = $wp_query->query_vars['wishlist_public'];
        
        // Find user by wishlist key
        $user_id = wishlist_get_user_by_share_key( $wishlist_key );
        
        if ( ! $user_id ) {
            wp_die( 'Wishlist not found.', 'Wishlist Not Found', array( 'response' => 404 ) );
        }
        
        // Check if user has made their wishlist public
        $is_public = get_user_meta( $user_id, '_wishlist_public', true );
        
        if ( ! $is_public || $is_public !== 'yes' ) {
            wp_die( 'This wishlist is private.', 'Private Wishlist', array( 'response' => 403 ) );
        }
        
        // Set query var for template
        set_query_var( 'wishlist_user_id', $user_id );
        
        // Look for template: woocommerce-wishlist-widget/templates/public-wishlist.php
        
        // Use plugin template
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/public-wishlist.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
        
        // Fallback if no template found
        wp_die( 'Wishlist template not found.', 'Template Error', array( 'response' => 500 ) );
    }
    
    return $template;
}

/**
 * Enqueue frontend scripts and styles
 */
function wishlist_enqueue_assets() {
	$version = '1.1.0';

	wp_enqueue_style(
		'wishlist-mode-style',
		plugin_dir_url( __FILE__ ) . 'assets/wishlist-mode.css',
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
		plugin_dir_url( __FILE__ ) . 'assets/wishlist-mode.js',
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
			'wishlist'     => is_user_logged_in() ? wishlist_get_user_wishlist() : array(),
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
 * Display the Wishlist endpoint content
 */
function wishlist_endpoint_content() {

	/*
	 * Show a login/account prompt when the visitor is logged out
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
	 * Display an empty-wishlist message
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
	 * Display the saved products
	 */
	?>
	<div class="wishlist-products">
		<?php
		foreach ( $wishlist as $product_id ) {
			$product = wc_get_product( $product_id );

			/*
			 * Skip products that were deleted or are no longer visible
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
								/* %s is the product SKU. */
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
 */
function wishlist_product_button() {
	global $product;
	
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	
	$product_id = $product->get_id();
	$wishlist = is_user_logged_in() ? wishlist_get_user_wishlist() : array();
	$is_favorited = in_array($product_id, $wishlist, true);
	$button_text = $is_favorited ? __('Remove from Wishlist', 'woocommerce-wishlist-widget') : __('Add to Wishlist', 'woocommerce-wishlist-widget');
	?>
	<button 
		type="button" 
		style="margin-left: 0.5rem;" 
		class="single_add_to_cart_button button alt js-wishlist-product-button<?php echo $is_favorited ? ' favorited' : ''; ?>" 
		data-product-id="<?php echo esc_attr($product_id); ?>"
		aria-pressed="<?php echo $is_favorited ? 'true' : 'false'; ?>"
	>
		<?php echo esc_html($button_text); ?>
	</button>
	<?php
}

/**
 * Display the sticky wishlist widget (vertical tab)
 */
function wishlist_widget() {
    $count = 0;
    $show_share = false;
    $share_url = '';
    
    if ( is_user_logged_in() ) {
        $user_id = get_current_user_id();
        
        // Get wishlist items
        $wishlist = wishlist_get_user_wishlist();
        $count = is_array( $wishlist ) ? count( $wishlist ) : 0;
        
        // Check if wishlist is public
        $is_public = get_user_meta( $user_id, '_wishlist_public', true );
        
        // Debug: You can uncomment these to check values
        // error_log('Is public: ' . var_export($is_public, true));
        // error_log('Count: ' . $count);
        
        // Only show share button if:
        // 1. Wishlist is public (meta value is 'yes')
        // 2. User has at least one item in their wishlist
        if ( $is_public === 'yes' && $count > 0 ) {
            $share_key = wishlist_generate_share_key( $user_id );
            $share_url = home_url( '/wishlist/' . $share_key . '/' );
            $show_share = true;
        }
    }
    ?>
    <div class="wishlist-sticky-wrapper">
        <!-- Main Wishlist Button -->
        <a
            href="<?php echo esc_url( wc_get_account_endpoint_url( 'wishlist' ) ); ?>"
            class="wishlist-sticky-tab"
            aria-label="<?php esc_attr_e( 'View wishlist', 'woocommerce-wishlist-widget' ); ?>"
        >
            <span class="wishlist-sticky-icon-wrapper">
                <i class="fa-solid fa-heart wishlist-sticky-icon"></i>
                <span
                    class="wishlist-count<?php echo 0 === $count ? ' is-empty' : ''; ?>"
                    aria-live="polite"
                >
                    <?php echo esc_html( $count ); ?>
                </span>
            </span>
        </a>
        
        <!-- Share Button - Only show if public AND has items -->
        <?php if ( $show_share ) : ?>
            <button
				type="button"
				class="wishlist-sticky-share"
				data-share-url="<?php echo esc_url( $share_url ); ?>"
                aria-label="<?php esc_attr_e( 'Share wishlist', 'woocommerce-wishlist-widget' ); ?>"
                title="<?php esc_attr_e( 'Share your wishlist', 'woocommerce-wishlist-widget' ); ?>"
            >
                <i class="fa-solid fa-share-nodes"></i>
            </button>
        <?php endif; ?>
    </div>
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
    $is_favorited = in_array( $product_id, $wishlist, true );

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
        'is_favorited' => ! $is_favorited,
        'wishlist' => array_values($wishlist) // ADD THIS LINE - return full wishlist
    ]);
}


/**
 * Resolve a local product permalink to a WooCommerce product ID.
 *
 * WooLentor's Universal Product widget does not always include a product ID
 * in its card markup, so the JavaScript fallback sends the product permalink
 * here when necessary.
 */
function wishlist_ajax_resolve_product_url() {
    check_ajax_referer( 'wishlist_nonce', 'nonce' );

    $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

    if ( empty( $url ) ) {
        wp_send_json_error( 'Missing product URL.' );
    }

    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $url_host  = wp_parse_url( $url, PHP_URL_HOST );

    if ( ! $home_host || ! $url_host || strtolower( $home_host ) !== strtolower( $url_host ) ) {
        wp_send_json_error( 'Invalid product URL.' );
    }

    $product_id = url_to_postid( $url );

    /*
     * Some permalink structures or builder-generated URLs are not resolved by
     * url_to_postid(). Fall back to the final path segment as the product slug.
     */
    if ( ! $product_id ) {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        $slug = basename( $path );

        if ( $slug ) {
            $product_post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' );

            if ( $product_post instanceof WP_Post ) {
                $product_id = (int) $product_post->ID;
            }
        }
    }

    $product = $product_id ? wc_get_product( $product_id ) : false;

    if ( ! $product ) {
        wp_send_json_error( 'Product could not be resolved.' );
    }

    wp_send_json_success(
        array(
            'product_id' => $product->get_id(),
        )
    );
}

/**
 * Add the wishlist heart to WooCommerce product cards.
 * This works with both standard WooCommerce and Woolentor
 */
function wishlist_add_heart_to_product_card() {
    global $product;

    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $product_id   = $product->get_id();
    $wishlist     = is_user_logged_in() ? wishlist_get_user_wishlist() : array();
    $is_favorited = in_array( $product_id, $wishlist, true );
    ?>
    <div class="wishlist-heart-wrapper">
        <button
            type="button"
            class="wishlist-heart-btn<?php echo $is_favorited ? ' favorited' : ''; ?>"
            data-product-id="<?php echo esc_attr( $product_id ); ?>"
            data-favorited="<?php echo $is_favorited ? 'true' : 'false'; ?>"
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
 * Register public wishlist endpoint
 */
add_action( 'init', 'wishlist_register_public_endpoint' );

function wishlist_register_public_endpoint() {
    add_rewrite_rule(
        '^wishlist/([^/]+)/?$',
        'index.php?wishlist_public=$matches[1]',
        'top'
    );
    
    add_rewrite_tag( '%wishlist_public%', '([^&]+)' );
}

/**
 * Add public wishlist query var
 */
add_filter( 'query_vars', 'wishlist_public_query_vars' );

function wishlist_public_query_vars( $vars ) {
    $vars[] = 'wishlist_public';
    return $vars;
}

/**
 * Get user by wishlist share key
 */
function wishlist_get_user_by_share_key( $share_key ) {
    global $wpdb;
    
    $user_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} 
            WHERE meta_key = '_wishlist_share_key' 
            AND meta_value = %s",
            $share_key
        )
    );
    
    return $user_id ? intval( $user_id ) : false;
}

/**
 * Generate unique share key for user
 */
function wishlist_generate_share_key( $user_id ) {
    $share_key = get_user_meta( $user_id, '_wishlist_share_key', true );
    
    if ( empty( $share_key ) ) {
        $share_key = wp_generate_password( 12, false, false );
        update_user_meta( $user_id, '_wishlist_share_key', $share_key );
    }
    
    return $share_key;
}

/**
 * Display public wishlist using template
 */
function wishlist_display_public_wishlist( $user_id ) {
    // Set the query var for the template
    set_query_var( 'wishlist_user_id', $user_id );
    
    // Path to the template file
    $template_path = plugin_dir_path( __FILE__ ) . 'templates/public-wishlist.php';
    
    // Check if template exists
    if ( file_exists( $template_path ) ) {
        // Include the template
        include $template_path;
    } else {
        // Fallback if template doesn't exist
        wp_die( 'Wishlist template not found.', 'Template Error', array( 'response' => 500 ) );
    }
    
    exit;
}

/**
 * Add share functionality to My Account wishlist page
 */
function wishlist_add_share_section() {
    if ( ! is_user_logged_in() ) {
        return;
    }
    
    $user_id = get_current_user_id();
    $is_public = get_user_meta( $user_id, '_wishlist_public', true );
    $share_key = wishlist_generate_share_key( $user_id );
    $share_url = home_url( '/wishlist/' . $share_key . '/' );
    
    // Get first product image for Pinterest
    $wishlist = wishlist_get_user_wishlist();
    $first_image = '';
    if ( ! empty( $wishlist ) ) {
        $first_product = wc_get_product( $wishlist[0] );
        if ( $first_product && $first_product->get_image_id() ) {
            $image_url = wp_get_attachment_image_url( $first_product->get_image_id(), 'medium' );
            if ( $image_url ) {
                $first_image = $image_url;
            }
        }
    }
    // Fallback to site icon if no wishlist items
    if ( empty( $first_image ) ) {
        $first_image = get_site_icon_url();
    }
    ?>
    
    <div class="wishlist-share-section">
        <h3><?php esc_html_e( 'Share Your Wishlist', 'woocommerce-wishlist-widget' ); ?></h3>
        
        <div class="wishlist-share-toggle">
            <label for="wishlist_public_toggle">
                <input 
                    type="checkbox" 
                    id="wishlist_public_toggle" 
                    data-user-id="<?php echo esc_attr( $user_id ); ?>"
                    <?php checked( $is_public, 'yes' ); ?>
                >
                <?php esc_html_e( 'Make wishlist public', 'woocommerce-wishlist-widget' ); ?>
            </label>
            <span class="wishlist-share-status">
                <?php echo $is_public ? esc_html__( 'Public', 'woocommerce-wishlist-widget' ) : esc_html__( 'Private', 'woocommerce-wishlist-widget' ); ?>
            </span>
        </div>
        
        <div class="wishlist-share-url-wrapper" style="<?php echo $is_public ? 'display:block;' : 'display:none;'; ?>">
            <div class="wishlist-share-url">
                <input 
                    type="text" 
                    id="wishlist_share_url" 
                    value="<?php echo esc_url( $share_url ); ?>" 
                    readonly
                    onclick="this.select();"
                >
                <button type="button" class="button wishlist-copy-url">
                    <?php esc_html_e( 'Copy Link', 'woocommerce-wishlist-widget' ); ?>
                </button>
            </div>
            
            <div class="wishlist-social-share">
                <span class="wishlist-share-label"><?php esc_html_e( 'Share via:', 'woocommerce-wishlist-widget' ); ?></span>
                
                <a href="mailto:?subject=<?php echo rawurlencode( 'Check out my wishlist!' ); ?>&body=<?php echo rawurlencode( "Here's my wishlist: " . $share_url ); ?>" 
                   class="wishlist-share-email" 
                   target="_blank">
                    <i class="fa-solid fa-envelope"></i>
                </a>
                
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo rawurlencode( $share_url ); ?>" 
                   class="wishlist-share-facebook" 
                   target="_blank">
                    <i class="fa-brands fa-facebook"></i>
                </a>
                
                <a href="https://twitter.com/intent/tweet?text=<?php echo rawurlencode( 'Check out my wishlist!' ); ?>&url=<?php echo rawurlencode( $share_url ); ?>" 
                   class="wishlist-share-twitter" 
                   target="_blank">
                	<i class="fa-brands fa-square-x-twitter"></i>
                </a>
                
                <a href="https://pinterest.com/pin/create/button/?url=<?php echo rawurlencode( $share_url ); ?>&media=<?php echo rawurlencode( $first_image ); ?>&description=<?php echo rawurlencode( 'My Wishlist - Check out my favorite products!' ); ?>" 
                   class="wishlist-share-pinterest" 
                   target="_blank">
                    <i class="fa-brands fa-pinterest"></i>
                </a>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Add share section to wishlist endpoint
 */
add_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_add_share_section_to_endpoint', 5 );

function wishlist_add_share_section_to_endpoint() {
    if ( is_user_logged_in() ) {
        // Add before the existing content
        remove_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_endpoint_content' );
        add_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_add_share_section' );
        add_action( 'woocommerce_account_wishlist_endpoint', 'wishlist_endpoint_content' );
    }
}

/**
 * AJAX handler for toggling wishlist public status
 */
add_action( 'wp_ajax_wishlist_toggle_public', 'wishlist_ajax_toggle_public' );

function wishlist_ajax_toggle_public() {
    check_ajax_referer( 'wishlist_nonce', 'nonce' );
    
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Must be logged in' );
    }
    
    $user_id = get_current_user_id();
    $is_public = isset( $_POST['is_public'] ) ? sanitize_text_field( $_POST['is_public'] ) : 'no';
    
    update_user_meta( $user_id, '_wishlist_public', $is_public );
    
    // Generate share key if not exists
    wishlist_generate_share_key( $user_id );
    
    wp_send_json_success( array(
        'is_public' => $is_public,
        'share_url' => home_url( '/wishlist/' . get_user_meta( $user_id, '_wishlist_share_key', true ) . '/' )
    ) );
}

/**
 * Add the wishlist heart - fallback for Woolentor and other page builders
 */
function wishlist_add_heart_to_product_card_after() {
    global $product;
    
    if ( ! $product instanceof WC_Product ) {
        return;
    }
    
    // Check if heart already exists on this product
    if ( has_action( 'woocommerce_before_shop_loop_item_title', 'wishlist_add_heart_to_product_card' ) ) {
        return;
    }
    
    $product_id = $product->get_id();
    $wishlist = is_user_logged_in() ? wishlist_get_user_wishlist() : array();
    $is_favorited = in_array($product_id, $wishlist, true);
    ?>
    <div class="wishlist-heart-wrapper" style="position: absolute; top: 10px; right: 10px; z-index: 10;">
        <button
            type="button"
            class="wishlist-heart-btn<?php echo $is_favorited ? ' favorited' : ''; ?>"
            data-product-id="<?php echo esc_attr( $product_id ); ?>"
            data-favorited="<?php echo $is_favorited ? 'true' : 'false'; ?>"
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
 * Register the endpoint and flush rewrite rules on activation.
 */
function wishlist_activate() {
	wishlist_register_endpoint();
    wishlist_register_public_endpoint();
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