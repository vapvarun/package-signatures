<?php
/**
 * Verifier tests, no WordPress needed: php tests/verifier-test.php
 *
 * @package PackageSignatures
 */

define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/src/class-verifier.php';

$failures = 0;
function check( $label, $got, $want ) {
	global $failures;
	$ok = $got === $want;
	$failures += $ok ? 0 : 1;
	echo ( $ok ? 'ok   ' : 'FAIL ' ), $label, $ok ? '' : "  (got $got, want $want)", "\n";
}

$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey( $pair );
$keys   = array( 'k1' => base64_encode( sodium_crypto_sign_publickey( $pair ) ) );
$other  = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );

$zip = tempnam( sys_get_temp_dir(), 'ps' );
file_put_contents( $zip, random_bytes( 5000 ) );

function bundle( array $over, $secret, $zip ) {
	$m = json_encode( array_merge( array( 'v' => 1, 'product' => 'jetonomy', 'version' => '2.0.5', 'file' => 'jetonomy-2.0.5.zip', 'size' => filesize( $zip ), 'sha256' => hash_file( 'sha256', $zip ), 'key_id' => 'k1', 'signed_at' => '2026-10-24T00:00:00Z' ), $over ) );
	return json_encode( array( 'manifest' => base64_encode( $m ), 'signature' => base64_encode( sodium_crypto_sign_detached( $m, $secret ) ) ) );
}
$expect = array( 'product' => 'jetonomy', 'version' => '2.0.5', 'installed' => '2.0.4' );
$v      = function ( $b, $e = null, $f = null ) use ( $keys, $zip, $expect ) {
	return Package_Signatures_Verifier::verify( $b, $keys, $f ?? $zip, $e ?? $expect )['reason'];
};

check( 'genuine package verifies', $v( bundle( array(), $secret, $zip ) ), 'verified' );
check( 'no signature', $v( '' ), 'no_signature' );
check( 'garbage bundle', $v( 'not json' ), 'bad_bundle' );
check( 'signed by another key', $v( bundle( array(), $other, $zip ) ), 'bad_signature' );
check( 'unknown key id', $v( bundle( array( 'key_id' => 'k9' ), $secret, $zip ) ), 'unknown_key' );
check( 'another product signed', $v( bundle( array( 'product' => 'learnomy' ), $secret, $zip ) ), 'wrong_product' );
check( 'version differs from the offer', $v( bundle( array( 'version' => '2.0.6' ), $secret, $zip ) ), 'wrong_version' );
check( 'downgrade to an older genuine build', $v( bundle( array( 'version' => '2.0.3' ), $secret, $zip ), array( 'product' => 'jetonomy', 'installed' => '2.0.4' ) ), 'not_newer' );
check( 'same version reinstalled is refused', $v( bundle( array( 'version' => '2.0.4' ), $secret, $zip ), array( 'product' => 'jetonomy', 'installed' => '2.0.4' ) ), 'not_newer' );

$b       = bundle( array(), $secret, $zip );
$swapped = tempnam( sys_get_temp_dir(), 'ps' );
file_put_contents( $swapped, random_bytes( 5000 ) );
check( 'zip swapped, same size', $v( $b, null, $swapped ), 'wrong_hash' );
file_put_contents( $swapped, random_bytes( 4000 ) );
check( 'zip swapped, other size', $v( $b, null, $swapped ), 'wrong_size' );

$parts             = json_decode( $b, true );
$m                 = json_decode( base64_decode( $parts['manifest'] ), true );
$m['sha256']       = hash_file( 'sha256', $swapped );
$parts['manifest'] = base64_encode( json_encode( $m ) );
check( 'manifest edited after signing', $v( json_encode( $parts ) ), 'bad_signature' );
check( 'oversized bundle', $v( str_repeat( 'a', 9000 ) ), 'signature_too_large' );
check( 'bad manifest version', $v( bundle( array( 'v' => 2 ), $secret, $zip ) ), 'bad_manifest' );

// The bin/sign.php output is what the verifier accepts.
$env = 'PACKAGE_SIGNATURES_SECRET_KEY=' . escapeshellarg( base64_encode( $secret ) );
$out = shell_exec( $env . ' ' . escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/sign.php' ) . ' --product=jetonomy --version=2.0.5 --key-id=k1 --file=' . escapeshellarg( $zip ) );
check( 'bin/sign.php output verifies', $v( trim( (string) $out ) ), 'verified' );

unlink( $zip );
unlink( $swapped );
echo $failures ? "$failures FAILED\n" : "all passed\n";
exit( $failures ? 1 : 0 );
