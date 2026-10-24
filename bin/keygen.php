<?php
/**
 * Creates an Ed25519 key pair. Prints the PUBLIC key on stdout and writes the SECRET key to the file given, mode 600.
 *
 *   php bin/keygen.php ~/secure/package-signing-wbcom-2026-1.key
 *
 * The public key goes into each product's registration; the secret key goes into the signing workflow's secret and
 * an offline backup, never into a repository.
 *
 * @package PackageSignatures
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
if ( empty( $argv[1] ) ) {
	fwrite( STDERR, "usage: php bin/keygen.php <secret-key-file>\n" );
	exit( 2 );
}
if ( file_exists( $argv[1] ) ) {
	fwrite( STDERR, "refusing to overwrite {$argv[1]}\n" );
	exit( 2 );
}
$pair = sodium_crypto_sign_keypair();
$old  = umask( 0077 );
file_put_contents( $argv[1], base64_encode( sodium_crypto_sign_secretkey( $pair ) ) . "\n" );
umask( $old );
chmod( $argv[1], 0600 );
echo base64_encode( sodium_crypto_sign_publickey( $pair ) ), "\n";
sodium_memzero( $pair );
