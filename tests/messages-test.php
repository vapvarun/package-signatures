<?php
/**
 * Messages test, no WordPress needed: php tests/messages-test.php
 *
 * A product's own translated messages are used; English when it passes none (1.0.2).
 *
 * @package PackageSignatures
 */

define( 'ABSPATH', __DIR__ );
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
}
require dirname( __DIR__ ) . '/src/class-verifier.php';
require dirname( __DIR__ ) . '/src/class-guard.php';

$fail = 0;
$check = function ( $label, $actual, $expected ) use ( &$fail ) {
	if ( $actual !== $expected ) {
		$fail = 1;
		echo "FAIL {$label}: got '{$actual}'\n";
	}
};

$check( 'english default', Package_Signatures_Guard::refusal( 'bad_hash' )->message, 'Update stopped: the package signature could not be verified (bad_hash).' );
$own = array( 'messages' => array( 'signature_invalid' => 'Mise à jour arrêtée (%s).', 'download_failed' => 'Téléchargement impossible.' ) );
$check( 'product message', Package_Signatures_Guard::refusal( 'bad_hash', $own )->message, 'Mise à jour arrêtée (bad_hash).' );
$check( 'download message', Package_Signatures_Guard::message( $own, 'download_failed', 'x' ), 'Téléchargement impossible.' );
$check( 'empty falls back', Package_Signatures_Guard::message( array( 'messages' => array( 'download_failed' => '' ) ), 'download_failed', 'English' ), 'English' );

echo $fail ? "messages-test: FAIL\n" : "messages-test: ok\n";
exit( $fail );
