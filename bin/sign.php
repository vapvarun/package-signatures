<?php
/**
 * Signs a release zip and prints the signature bundle (JSON).
 *
 *   php bin/sign.php --product=jetonomy --version=2.0.5 --key-id=wbcom-2026-1 --file=jetonomy-2.0.5.zip
 *
 * The Ed25519 secret key (base64, 64 bytes) is read from the PACKAGE_SIGNATURES_SECRET_KEY environment variable,
 * never from an argument, so it does not land in shell history or process listings.
 *
 * @package PackageSignatures
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$o = getopt( '', array( 'product:', 'version:', 'key-id:', 'file:', 'signed-at::' ) );
foreach ( array( 'product', 'version', 'key-id', 'file' ) as $k ) {
	if ( empty( $o[ $k ] ) ) {
		fwrite( STDERR, "missing --$k\n" );
		exit( 2 );
	}
}
$secret = base64_decode( (string) getenv( 'PACKAGE_SIGNATURES_SECRET_KEY' ), true );
if ( false === $secret || SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen( $secret ) ) {
	fwrite( STDERR, "PACKAGE_SIGNATURES_SECRET_KEY is missing or not a base64 Ed25519 secret key\n" );
	exit( 2 );
}
if ( ! is_readable( $o['file'] ) ) {
	fwrite( STDERR, "cannot read {$o['file']}\n" );
	exit( 2 );
}

$manifest = json_encode(
	array(
		'v'         => 1,
		'product'   => (string) $o['product'],
		'version'   => (string) $o['version'],
		'file'      => basename( $o['file'] ),
		'size'      => filesize( $o['file'] ),
		'sha256'    => hash_file( 'sha256', $o['file'] ),
		'key_id'    => (string) $o['key-id'],
		'signed_at' => $o['signed-at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ),
	),
	JSON_UNESCAPED_SLASHES
);

echo json_encode(
	array(
		'manifest'  => base64_encode( $manifest ),
		'signature' => base64_encode( sodium_crypto_sign_detached( $manifest, $secret ) ),
	),
	JSON_UNESCAPED_SLASHES
), "\n";
sodium_memzero( $secret );
