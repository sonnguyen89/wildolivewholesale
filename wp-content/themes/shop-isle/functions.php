<?php
/**
 * Main functions file
 *
 * @package WordPress
 * @subpackage Shop Isle
 */
$vendor_file = trailingslashit( get_template_directory() ) . 'vendor/autoload.php';
if ( is_readable( $vendor_file ) ) {
	require_once $vendor_file;
}

if ( ! defined( 'WPFORMS_SHAREASALE_ID' ) ) {
	define( 'WPFORMS_SHAREASALE_ID', '848264' );
}

add_filter( 'themeisle_sdk_products', 'shopisle_load_sdk' );
/**
 * Loads products array.
 *
 * @param array $products All products.
 *
 * @return array Products array.
 */
function shopisle_load_sdk( $products ) {
	$products[] = get_template_directory() . '/style.css';

	return $products;
}
/**
 * Initialize all the things.
 */
require get_template_directory() . '/inc/init.php';

/**
 * Note: Do not add any custom code here. Please use a child theme so that your customizations aren't lost during updates.
 * http://codex.wordpress.org/Child_Themes
 */

/**
 * this function force user to login the page
 * before go to shopping
 *
 */
add_action('template_redirect', 'woo_login_redirect');
function woo_login_redirect() {

    if (!is_user_logged_in() && (is_home() || is_woocommerce() || is_cart() || is_checkout()))
    {
        wp_redirect( '/my-account/' );
        exit;
    }
}

/**
 * redirect customer to front page after login
 */
add_filter( 'woocommerce_login_redirect', 'redirect_customer', 10, 2 );
function redirect_customer( $redirect_to, $user ){

    //is there a user to check?

    if ( isset( $user->roles ) && is_array( $user->roles ) ) {

        //check for customer
        if ( in_array( 'customer', $user->roles ) ) {

            $redirect_to = get_home_url(); // front page url
        }
    }

    return $redirect_to;
}


/**
 * CUSTOMISE THE THEME
 */


function register_my_menus() {
    register_nav_menus(
        array(
            'secondary-primary-menu' => __( 'Secondary Primary Menu' ),
            'mobile-primary-menu' => __( 'Mobile Primary Menu' ),
        )
    );
}
add_action( 'init', 'register_my_menus' );


