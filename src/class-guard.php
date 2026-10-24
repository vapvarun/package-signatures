<?php
/**
 * Checks registered products' update packages before WordPress installs them.
 *
 * Hooks core's upgrader_pre_download, which runs for plugin and theme updates from the dashboard, auto-updates and
 * WP-CLI alike, whatever updater announced the update. Only packages of registered products from their registered
 * hosts are touched; everything else passes straight through.
 *
 * @package PackageSignatures
 */

defined( 'ABSPATH' ) || exit;

class Package_Signatures_Guard {

	const LOG_OPTION = 'package_signatures_log';
	const LOG_SIZE   = 20;

	/**
	 * Hooks in. Called once, by the newest bundled copy.
	 */
	public static function boot() {
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 20, 4 );
	}

	/**
	 * Registered products, latest registration per plugin or theme wins.
	 *
	 * @return array
	 */
	public static function products() {
		$out = array();
		foreach ( (array) ( $GLOBALS['package_signatures_products'] ?? array() ) as $p ) {
			if ( empty( $p['product'] ) || empty( $p['keys'] ) || ( empty( $p['plugin'] ) && empty( $p['theme'] ) ) ) {
				continue;
			}
			$p['mode']  = ( 'enforce' === ( $p['mode'] ?? '' ) ) ? 'enforce' : 'warn';
			$p['hosts'] = array_map( 'strtolower', (array) ( $p['hosts'] ?? array() ) );
			$out[ ! empty( $p['plugin'] ) ? 'plugin:' . $p['plugin'] : 'theme:' . $p['theme'] ] = $p;
		}
		return $out;
	}

	/**
	 * Filter callback for upgrader_pre_download.
	 *
	 * @param false|string|WP_Error $reply      Earlier reply; anything but false means another handler decided.
	 * @param string                $package    Package URL.
	 * @param WP_Upgrader           $upgrader   Upgrader instance.
	 * @param array                 $hook_extra plugin / theme being updated.
	 * @return false|string|WP_Error
	 */
	public static function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || ! preg_match( '#^https://#i', $package ) ) {
			return $reply;
		}
		$product = self::match( (array) $hook_extra, $package );
		if ( ! $product ) {
			return $reply;
		}

		$tmp = wp_tempnam( 'package-signatures' );
		$res = wp_safe_remote_get( $package, array( 'timeout' => 300, 'stream' => true, 'filename' => $tmp ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'download_failed', __( 'The update package could not be downloaded.', 'package-signatures' ) );
		}

		$bundle = self::bundle_from( $res, $product, (array) $hook_extra );
		$result = Package_Signatures_Verifier::verify( $bundle, (array) $product['keys'], $tmp, self::expect( $product ) );
		self::record( $product, $result );

		if ( $result['ok'] || 'warn' === $product['mode'] ) {
			return $tmp; // Hand the downloaded file to the upgrader.
		}
		wp_delete_file( $tmp );
		/* translators: %s: reason code */
		return new WP_Error( 'package_signature_invalid', sprintf( __( 'Update stopped: the package signature could not be verified (%s).', 'package-signatures' ), $result['reason'] ) );
	}

	/**
	 * The registered product this update is for, if its package comes from a registered host.
	 *
	 * @param array  $hook_extra Upgrader context.
	 * @param string $package    Package URL.
	 * @return array|null
	 */
	private static function match( array $hook_extra, $package ) {
		$products = self::products();
		$key      = ! empty( $hook_extra['plugin'] ) ? 'plugin:' . $hook_extra['plugin'] : ( ! empty( $hook_extra['theme'] ) ? 'theme:' . $hook_extra['theme'] : '' );
		if ( '' === $key || empty( $products[ $key ] ) ) {
			return null;
		}
		$host = strtolower( (string) wp_parse_url( $package, PHP_URL_HOST ) );
		return in_array( $host, $products[ $key ]['hosts'], true ) ? $products[ $key ] : null;
	}

	/**
	 * Signature bundle: the X-Package-Signature header, else the product's fallback URL.
	 *
	 * @param array|WP_HTTP_Response $res     Download response.
	 * @param array                  $product Registration.
	 * @param array                  $hook_extra Upgrader context.
	 * @return string
	 */
	private static function bundle_from( $res, array $product, array $hook_extra ) {
		$header = (string) wp_remote_retrieve_header( $res, 'x-package-signature' );
		if ( '' !== $header ) {
			$decoded = base64_decode( $header, true );
			if ( false !== $decoded ) {
				return $decoded;
			}
		}
		$version = self::offered_version( $product );
		if ( empty( $product['fallback'] ) || '' === $version ) {
			return '';
		}
		$url = str_replace( array( '{product}', '{version}' ), array( rawurlencode( $product['product'] ), rawurlencode( $version ) ), (string) $product['fallback'] );
		$sig = wp_safe_remote_get( $url, array( 'timeout' => 15, 'limit_response_size' => Package_Signatures_Verifier::MAX_BUNDLE_BYTES ) );
		return ( ! is_wp_error( $sig ) && 200 === (int) wp_remote_retrieve_response_code( $sig ) ) ? (string) wp_remote_retrieve_body( $sig ) : '';
	}

	/**
	 * What the signed manifest must say: this product, the version WordPress was offered, newer than installed.
	 *
	 * @param array $product Registration.
	 * @return array
	 */
	private static function expect( array $product ) {
		return array(
			'product'   => $product['product'],
			'version'   => self::offered_version( $product ),
			'installed' => self::installed_version( $product ),
		);
	}

	/**
	 * Version in WordPress's update offer for this product.
	 *
	 * @param array $product Registration.
	 * @return string
	 */
	private static function offered_version( array $product ) {
		if ( ! empty( $product['plugin'] ) ) {
			$t = get_site_transient( 'update_plugins' );
			return isset( $t->response[ $product['plugin'] ]->new_version ) ? (string) $t->response[ $product['plugin'] ]->new_version : '';
		}
		$t = get_site_transient( 'update_themes' );
		return isset( $t->response[ $product['theme'] ]['new_version'] ) ? (string) $t->response[ $product['theme'] ]['new_version'] : '';
	}

	/**
	 * Installed version of the product.
	 *
	 * @param array $product Registration.
	 * @return string
	 */
	private static function installed_version( array $product ) {
		if ( ! empty( $product['plugin'] ) ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$all = get_plugins();
			return (string) ( $all[ $product['plugin'] ]['Version'] ?? '' );
		}
		return (string) wp_get_theme( $product['theme'] )->get( 'Version' );
	}

	/**
	 * Keeps the last few results (not autoloaded) and tells listeners, for reporting.
	 *
	 * @param array $product Registration.
	 * @param array $result  Verifier result.
	 */
	private static function record( array $product, array $result ) {
		$log   = (array) get_option( self::LOG_OPTION, array() );
		$log[] = array(
			'time'    => time(),
			'product' => $product['product'],
			'version' => (string) ( $result['manifest']['version'] ?? self::offered_version( $product ) ),
			'mode'    => $product['mode'],
			'result'  => $result['ok'] ? 'verified' : 'failed',
			'reason'  => $result['reason'],
		);
		update_option( self::LOG_OPTION, array_slice( $log, -self::LOG_SIZE ), false );
		/**
		 * Fires after a registered product's package was checked.
		 *
		 * @param array $product Registration (product, plugin/theme, mode).
		 * @param array $result  { ok, reason, manifest }.
		 */
		do_action( 'package_signatures_checked', $product, $result );
	}
}
