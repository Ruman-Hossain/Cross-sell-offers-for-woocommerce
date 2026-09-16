<?php
/**
 * Plugin Name: Cross-Sell Offers for WooCommerce
 * Plugin URI:  https://github.com/Ruman-Hossain/cross-sell-offers-for-woocommerce
 * Description: Offer a second product alongside the one already in the basket. Every pairing is chosen by hand in the settings — nothing is offered automatically. Does nothing at all until enabled.
 * Version:     1.0.0
 * Author:      Md Ruman Hossain
 * Author URI:  https://rumancsebrur.blogspot.com/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cross-sell-offers-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 11.0
 *
 * WHAT THIS PLUGIN DOES
 * ---------------------
 * You create "offers". An offer says: when THESE products are in the basket,
 * show a box inviting the customer to add THAT product too. Nothing is
 * automatic and nothing applies to a product you have not named.
 *
 * SAFETY DESIGN
 * -------------
 * 1. Master switch is OFF by default, and each offer has its own switch.
 * 2. No database tables. Offers live in one option row.
 * 3. The plugin only ever modifies a basket line it added itself.
 * 4. Once a customer changes an add-on quantity by hand, the plugin stops
 *    touching that line for the rest of the session.
 */

defined( 'ABSPATH' ) || exit;

define( 'CSO_VERSION', '1.0.0' );
define( 'CSO_FILE', __FILE__ );
define( 'CSO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSO_OPTION', 'cso_offers' );
define( 'CSO_ENABLED', 'cso_enabled' );

/* Author / project details, in one place. */
define( 'CSO_AUTHOR', 'Md Ruman Hossain' );
define( 'CSO_AUTHOR_URL', 'https://rumancsebrur.blogspot.com/' );
define( 'CSO_PROJECT_URL', 'https://github.com/Ruman-Hossain/cross-sell-offers-for-woocommerce' );
define( 'CSO_SUPPORT_URL', 'https://github.com/Ruman-Hossain/cross-sell-offers-for-woocommerce/issues' );

/** Cart item keys used to mark a line this plugin added. */
define( 'CSO_ITEM_OFFER', '_cso_offer' );
define( 'CSO_ITEM_AUTO', '_cso_auto' );

/**
 * A blank offer. Every stored offer is merged over this, so a missing key
 * can never fatal after an upgrade.
 */
function cso_default_offer() {
	return array(
		'id'                  => '',
		'enabled'             => 'no',
		'label'               => '',      // Admin-only name, so a long list stays readable.
		'trigger_products'    => array(),
		'trigger_categories'  => array(),
		'addon_products'      => array(),   // One or more. One offer can push B and C.
		'placements'          => array( 'cart' ),   // cart | checkout
		'qty_mode'            => 'match',           // match | one
		'cap_to_trigger'      => 'no',
		'remove_with_trigger' => 'yes',
		'heading'             => '',
		'text'                => '',
		'button'              => 'Add to my booking',
	);
}

/**
 * Every offer, keyed by id, each merged over the defaults.
 */
function cso_get_offers() {
	$stored = get_option( CSO_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$out = array();
	foreach ( $stored as $offer ) {
		if ( ! is_array( $offer ) || empty( $offer['id'] ) ) {
			continue;
		}
		$out[ $offer['id'] ] = wp_parse_args( $offer, cso_default_offer() );
	}

	return $out;
}

/**
 * The add-on product IDs of an offer, as a clean list of integers.
 *
 * Also understands the single 'addon_product' key used by the very first
 * build, so an offer saved before multiple add-ons existed still works.
 */
function cso_offer_addons( $offer ) {
	$ids = array();

	if ( ! empty( $offer['addon_products'] ) && is_array( $offer['addon_products'] ) ) {
		$ids = $offer['addon_products'];
	} elseif ( ! empty( $offer['addon_product'] ) ) {
		$ids = array( $offer['addon_product'] );
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

function cso_get_offer( $id ) {
	$offers = cso_get_offers();
	return isset( $offers[ $id ] ) ? $offers[ $id ] : null;
}

/**
 * Is the plugin switched on at all?
 */
function cso_is_enabled() {
	return 'yes' === get_option( CSO_ENABLED, 'no' );
}

/**
 * Offers that are switched on AND completely configured. An offer with no
 * add-on product, or no trigger at all, is ignored rather than guessed at.
 */
function cso_live_offers() {
	if ( ! cso_is_enabled() ) {
		return array();
	}

	$live = array();
	foreach ( cso_get_offers() as $id => $offer ) {
		if ( 'yes' !== $offer['enabled'] ) {
			continue;
		}
		if ( ! cso_offer_addons( $offer ) ) {
			continue;
		}
		if ( empty( $offer['trigger_products'] ) && empty( $offer['trigger_categories'] ) ) {
			continue;
		}
		$live[ $id ] = $offer;
	}

	return $live;
}

/**
 * Does this product trigger this offer?
 */
function cso_product_triggers( $offer, $product_id ) {
	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return false;
	}

	if ( ! empty( $offer['trigger_products'] )
		&& in_array( $product_id, array_map( 'absint', $offer['trigger_products'] ), true ) ) {
		return true;
	}

	if ( ! empty( $offer['trigger_categories'] ) ) {
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && $terms
			&& array_intersect( array_map( 'absint', $offer['trigger_categories'] ), array_map( 'absint', $terms ) ) ) {
			return true;
		}
	}

	return false;
}

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */

add_action( 'plugins_loaded', 'cso_boot', 20 );

function cso_boot() {

	if ( is_admin() ) {
		require_once CSO_PATH . 'includes/class-cso-settings.php';
		new CSO_Settings();
	}

	if ( ! class_exists( 'WooCommerce' ) || ! cso_is_enabled() ) {
		return; // Nothing on the front end at all.
	}

	require_once CSO_PATH . 'includes/class-cso-cart.php';
	require_once CSO_PATH . 'includes/class-cso-display.php';

	new CSO_Cart();
	new CSO_Display();
}

/**
 * Tell WooCommerce what this works with, so it is not listed as incompatible
 * purely for never having said.
 */
add_action( 'before_woocommerce_init', 'cso_declare_compatibility' );

function cso_declare_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
}

add_action( 'admin_notices', 'cso_dependency_notice' );

function cso_dependency_notice() {
	if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Cross-Sell Offers</strong> needs WooCommerce to be active. It is doing nothing at the moment.</p></div>';
}
