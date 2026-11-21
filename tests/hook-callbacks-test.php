<?php
/**
 * Static check: every array callback the theme hands to WordPress —
 * [ __CLASS__, 'x' ], [ $this, 'x' ], [ self::class, 'x' ] and the
 * array( … ) spellings — names a method that is defined in the same file.
 * No WordPress, no database.
 *
 *   php tests/hook-callbacks-test.php
 *
 * Why: a hook whose callback names a method that does not exist is a
 * fatal TypeError on PHP 8 the moment the hook fires, and nothing shows
 * until then. v1.24.0 shipped add_action( 'woocommerce_checkout_order_created',
 * [ __CLASS__, 'add_order_notes' ] ) without the method: every checkout
 * died at "Place order". The theme keeps each class in one file with no
 * inheritance between its own classes, so "defined in the same file" is
 * exact; a callback that legitimately lives in a parent goes in $allowed.
 *
 * @package FisHotel
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root    = dirname( __DIR__ );
$allowed = []; // 'relative/file.php' => [ 'method', … ]

$files = [];
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$path = $f->getPathname();
	if ( substr( $path, -4 ) !== '.php' ) {
		continue;
	}
	$rel = ltrim( str_replace( $root, '', $path ), '/' );
	if ( preg_match( '~^(vendor|tests|node_modules|\.git)/~', $rel ) ) {
		continue;
	}
	$files[ $rel ] = $path;
}
ksort( $files );

$failures = 0;
$checked  = 0;
$pattern  = '~(?:\[|array\s*\()\s*(?:__CLASS__|\$this|self::class|static::class)\s*,\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*(?:\]|\))~';

foreach ( $files as $rel => $path ) {
	$src = file_get_contents( $path );
	if ( ! preg_match_all( $pattern, $src, $m, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	$defined = [];
	if ( preg_match_all( '~\bfunction\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(~', $src, $d ) ) {
		$defined = array_change_key_case( array_flip( $d[1] ), CASE_LOWER );
	}
	foreach ( $m[2] as $hit ) {
		$method = $hit[0];
		$checked++;
		if ( isset( $defined[ strtolower( $method ) ] ) ) {
			continue;
		}
		if ( isset( $allowed[ $rel ] ) && in_array( $method, $allowed[ $rel ], true ) ) {
			continue;
		}
		$failures++;
		$line = substr_count( $src, "\n", 0, $hit[1] ) + 1;
		echo "  FAIL $rel:$line — callback names $method(), which this file does not define\n";
	}
}

echo "  " . ( $failures ? 'checked' : 'ok   checked' ) . " $checked array callbacks in " . count( $files ) . " files\n";
if ( $checked < 100 ) {
	$failures++;
	echo "  FAIL the scan found fewer than 100 callbacks — the pattern no longer matches the code\n";
}

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
