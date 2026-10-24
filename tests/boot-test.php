<?php
/**
 * Boot tests, no WordPress needed: php tests/boot-test.php
 *
 * An incomplete copy (missing src/ files, e.g. a half-extracted update) must never be chosen,
 * and when no copy is complete the module stays off instead of a fatal error.
 *
 * @package PackageSignatures
 */

define( 'ABSPATH', __DIR__ );
$GLOBALS['filters'] = array();
function add_action( $hook, $cb, $priority = 10 ) {
	$GLOBALS['filters'][ $hook ][] = $cb;
}
function add_filter( $hook, $cb, $priority = 10 ) {
	$GLOBALS['filters'][ $hook ][] = $cb;
}

$root   = dirname( __DIR__ );
$broken = sys_get_temp_dir() . '/ps-broken-' . getmypid();
@mkdir( $broken . '/src', 0777, true );

$mode = $argv[1] ?? 'run';
if ( 'none-complete' === $mode ) {
	// Only a broken copy exists: boot must return quietly.
	$GLOBALS['package_signatures_copies'] = array( '9.9.9' => $broken );
	require $root . '/package-signatures.php';
	$GLOBALS['package_signatures_copies'] = array( '9.9.9' => $broken );
	package_signatures_boot();
	echo class_exists( 'Package_Signatures_Guard', false ) ? 'loaded' : 'off', "\n";
	exit( 0 );
}
if ( 'newest-broken' === $mode ) {
	// A newer but incomplete copy from another plugin: the complete one must run.
	require $root . '/package-signatures.php';
	$GLOBALS['package_signatures_copies']['9.9.9'] = $broken;
	package_signatures_boot();
	echo class_exists( 'Package_Signatures_Guard', false ) && ! empty( $GLOBALS['filters']['upgrader_pre_download'] ) ? 'guarded' : 'not-guarded', "\n";
	exit( 0 );
}

// Each case runs in its own process, so a fatal error counts as a failure instead of stopping the run.
$failures = 0;
foreach ( array( 'none-complete' => 'off', 'newest-broken' => 'guarded' ) as $case => $want ) {
	$got = trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . $case . ' 2>&1' ) );
	$ok  = $got === $want;
	$failures += $ok ? 0 : 1;
	echo ( $ok ? 'ok   ' : 'FAIL ' ), $case, $ok ? '' : '  (got ' . substr( preg_replace( '/\s+/', ' ', $got ), 0, 160 ) . ", want $want)", "\n";
}
@rmdir( $broken . '/src' );
@rmdir( $broken );
echo $failures ? "$failures failed\n" : "boot tests: all passed\n";
exit( $failures ? 1 : 0 );
