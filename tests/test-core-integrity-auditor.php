<?php
/**
 * Isolated fixture tests for the core integrity scanner.
 */

$fixture_root = sys_get_temp_dir() . '/plugin-reviewer-core-' . bin2hex( random_bytes( 6 ) );
mkdir( $fixture_root . '/wp-admin', 0777, true );
mkdir( $fixture_root . '/wp-includes', 0777, true );

define( 'ABSPATH', $fixture_root . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-corechecksumclient.php';
require_once dirname( __DIR__ ) . '/includes/class-coreintegrityauditor.php';

use PluginReviewer\CoreChecksumClient;
use PluginReviewer\CoreIntegrityAuditor;

class FixtureChecksumClient extends CoreChecksumClient {
	private $response;

	public function __construct( $response ) {
		$this->response = $response;
	}

	public function get( $version, $locale ) {
		return $this->response;
	}
}

$failures = array();
$assert   = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$write = static function ( $relative, $contents ) use ( $fixture_root ) {
	$path = $fixture_root . '/' . $relative;
	if ( ! is_dir( dirname( $path ) ) ) {
		mkdir( dirname( $path ), 0777, true );
	}
	file_put_contents( $path, $contents );
};

$write( 'wp-admin/admin.php', 'official admin' );
$write( 'wp-includes/load.php', 'official includes' );
$write( 'wp-content/languages/example.php', 'mutable content' );
$write( 'host-config.php', 'valid site-root extra' );

$manifest = array(
	'wp-admin/admin.php'                 => md5( 'official admin' ),
	'wp-includes/load.php'               => md5( 'official includes' ),
	'wp-content/languages/example.php'   => md5( 'different mutable content' ),
);
$auditor  = new CoreIntegrityAuditor( new FixtureChecksumClient( array() ) );
$clean    = $auditor->analyze( $fixture_root, '6.8.2', 'en_US', $manifest );
$assert( 'clean' === $clean['status'], 'A matching fixture should be clean.' );
$assert( 0 === count( $clean['findings'] ), 'wp-content and site-root extras must not create findings.' );

$write( 'wp-admin/admin.php', 'locally modified' );
unlink( $fixture_root . '/wp-includes/load.php' );
$write( 'wp-includes/unexpected.php', 'unexpected' );
$findings = $auditor->analyze( $fixture_root, '6.8.2', 'de_DE', $manifest );
$assert( 'findings' === $findings['status'], 'Completed mismatches should produce findings status.' );
$assert( 1 === $findings['counts']['modified'], 'A modified core file should be detected.' );
$assert( 1 === $findings['counts']['missing'], 'A missing core file should be detected.' );
$assert( 1 === $findings['counts']['unexpected'], 'An unexpected core-owned file should be detected.' );
$assert( 'de_DE' === $findings['locale'], 'The package locale should remain visible.' );
$assert( false === strpos( json_encode( $findings ), $fixture_root ), 'Results must not expose absolute paths.' );

$unsafe = $auditor->analyze( $fixture_root, '6.8.2', 'en_US', array( '../secret.php' => md5( 'x' ) ) );
$assert( 'incomplete' === $unsafe['status'], 'An unsafe manifest path should make coverage incomplete.' );

$GLOBALS['wp_version']       = '6.9-beta1';
$GLOBALS['wp_local_package'] = 'fr_FR';
$unsupported                 = ( new CoreIntegrityAuditor( new FixtureChecksumClient( array() ) ) )->audit();
$assert( 'unsupported' === $unsupported['status'], 'Development builds should be explicitly unsupported.' );
$assert( 'fr_FR' === $unsupported['locale'], 'Installed package locale must be used instead of the site locale.' );

$GLOBALS['wp_version']       = '6.8.2';
$GLOBALS['wp_local_package'] = 'es_ES';
$unavailable                 = ( new CoreIntegrityAuditor( new FixtureChecksumClient( array( 'available' => false, 'checksums' => array() ) ) ) )->audit();
$assert( 'incomplete' === $unavailable['status'], 'Unavailable authoritative checksums must not report clean.' );

if ( function_exists( 'symlink' ) ) {
	$outside = sys_get_temp_dir() . '/plugin-reviewer-outside-' . bin2hex( random_bytes( 4 ) );
	file_put_contents( $outside, 'outside' );
	if ( @symlink( $outside, $fixture_root . '/wp-admin/escape.php' ) ) {
		$symlink_manifest                        = $manifest;
		$symlink_manifest['wp-admin/escape.php'] = md5( 'outside' );
		$symlinked                               = $auditor->analyze( $fixture_root, '6.8.2', 'en_US', $symlink_manifest );
		$assert( 'incomplete' === $symlinked['status'], 'A symlink in a core-owned directory must make coverage incomplete.' );
		$assert( 1 === $symlinked['counts']['read_error'], 'An expected symlink must be reported as a read error without being followed.' );
		unlink( $fixture_root . '/wp-admin/escape.php' );
	}
	unlink( $outside );
}

$remove_tree = static function ( $path ) use ( &$remove_tree ) {
	foreach ( new DirectoryIterator( $path ) as $item ) {
		if ( $item->isDot() ) {
			continue;
		}
		if ( $item->isLink() || $item->isFile() ) {
			unlink( $item->getPathname() );
		} else {
			$remove_tree( $item->getPathname() );
		}
	}
	rmdir( $path );
};
$remove_tree( $fixture_root );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "Core integrity fixture tests passed.\n";
