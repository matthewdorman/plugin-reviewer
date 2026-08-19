<?php
/**
 * Read-only WordPress core integrity scanner.
 *
 * @package PluginReviewer
 */

namespace PluginReviewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares core-owned files with authoritative WordPress.org checksums.
 */
class CoreIntegrityAuditor {

	/** Maximum files enumerated in core-owned directories. */
	const MAX_FILES = 20000;

	/** Maximum aggregate file bytes considered during enumeration. */
	const MAX_BYTES = 536870912;

	/** Maximum wall-clock seconds spent enumerating unexpected files. */
	const MAX_SECONDS = 20;

	/** Maximum findings retained in the report. */
	const MAX_FINDINGS = 1000;

	/**
	 * Checksum client.
	 *
	 * @var CoreChecksumClient
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param CoreChecksumClient $client Checksum client.
	 */
	public function __construct( CoreChecksumClient $client ) {
		$this->client = $client;
	}

	/**
	 * Scan the installed WordPress core.
	 *
	 * @return array<string,mixed>
	 */
	public function audit() {
		global $wp_local_package, $wp_version;

		$version = isset( $wp_version ) ? (string) $wp_version : '';
		$locale  = isset( $wp_local_package ) && '' !== $wp_local_package ? (string) $wp_local_package : 'en_US';

		if ( '' === $version || false !== strpos( $version, '-' ) ) {
			return $this->base_result( 'unsupported', $version, $locale, __( 'Development, nightly, or unidentified WordPress builds cannot be verified authoritatively.', 'plugin-reviewer' ) );
		}

		$response = $this->client->get( $version, $locale );
		if ( empty( $response['available'] ) ) {
			return $this->base_result( 'incomplete', $version, $locale, __( 'Authoritative checksums were unavailable for the installed version and package locale.', 'plugin-reviewer' ) );
		}

		return $this->analyze( ABSPATH, $version, $locale, $response['checksums'] );
	}

	/**
	 * Analyze a root against supplied checksums. Public to support isolated fixtures.
	 *
	 * @param string               $root      WordPress root.
	 * @param string               $version   WordPress version.
	 * @param string               $locale    Package locale.
	 * @param array<string,string> $checksums Expected relative paths and MD5 hashes.
	 * @return array<string,mixed>
	 */
	public function analyze( $root, $version, $locale, $checksums ) {
		$result         = $this->base_result( 'clean', $version, $locale, '' );
		$root           = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$start          = microtime( true );
		$known          = array();
		$manifest_valid = true;

		foreach ( $checksums as $relative => $expected_hash ) {
			if ( microtime( true ) - $start > self::MAX_SECONDS ) {
				$this->mark_incomplete( $result, __( 'The checksum scan reached its time limit.', 'plugin-reviewer' ) );
				$manifest_valid = false;
				break;
			}
			$relative = $this->normalize_relative_path( $relative );
			if ( '' === $relative || ! is_string( $expected_hash ) || ! preg_match( '/^[a-f0-9]{32}$/i', $expected_hash ) ) {
				$this->mark_incomplete( $result, __( 'The checksum manifest contained an unsafe or invalid entry.', 'plugin-reviewer' ) );
				$manifest_valid = false;
				continue;
			}
			if ( 0 === strpos( $relative, 'wp-content/' ) ) {
				continue;
			}

			$known[ $relative ] = true;
			$absolute           = $root . $relative;
			if ( ! file_exists( $absolute ) ) {
				$this->add_finding( $result, 'missing', $relative, $expected_hash, '', __( 'An expected core file is missing.', 'plugin-reviewer' ) );
				continue;
			}
			if ( is_link( $absolute ) || ! is_file( $absolute ) || ! is_readable( $absolute ) || ! $this->is_inside_root( $absolute, $root ) ) {
				$this->add_finding( $result, 'read_error', $relative, $expected_hash, '', __( 'The expected core file could not be read safely.', 'plugin-reviewer' ) );
				$result['coverage']['complete'] = false;
				continue;
			}

			$actual_hash = md5_file( $absolute );
			if ( false === $actual_hash ) {
				$this->add_finding( $result, 'read_error', $relative, $expected_hash, '', __( 'The expected core file could not be hashed.', 'plugin-reviewer' ) );
				$result['coverage']['complete'] = false;
			} elseif ( strtolower( (string) $expected_hash ) !== strtolower( $actual_hash ) ) {
				$this->add_finding( $result, 'modified', $relative, $expected_hash, $actual_hash, __( 'The core file does not match the official checksum.', 'plugin-reviewer' ) );
			}
		}

		if ( $manifest_valid ) {
			foreach ( array( 'wp-admin', 'wp-includes' ) as $directory ) {
				$this->enumerate_directory( $root, $directory, $known, $result, $start );
				if ( ! $result['coverage']['complete'] ) {
					break;
				}
			}
		}

		$result['has_findings'] = 0 < count( $result['findings'] );
		if ( ! $result['coverage']['complete'] ) {
			$result['status'] = 'incomplete';
		} elseif ( $result['has_findings'] ) {
			$result['status'] = 'findings';
		}
		return $result;
	}

	/**
	 * Enumerate one core-owned directory without following symlinks.
	 *
	 * @param string              $root      Normalized root.
	 * @param string              $directory Relative directory.
	 * @param array<string,bool>  $known     Expected paths.
	 * @param array<string,mixed> $result    Report result.
	 * @param float               $start     Scan start time.
	 * @return void
	 */
	private function enumerate_directory( $root, $directory, $known, &$result, $start ) {
		$absolute = $root . $directory;
		if ( ! is_dir( $absolute ) || is_link( $absolute ) || ! $this->is_inside_root( $absolute, $root ) ) {
			$this->mark_incomplete( $result, sprintf( /* translators: %s: core directory. */ __( '%s could not be enumerated safely.', 'plugin-reviewer' ), $directory ) );
			return;
		}

		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $file ) {
				if ( microtime( true ) - $start > self::MAX_SECONDS ) {
					$this->mark_incomplete( $result, __( 'The unexpected-file scan reached its time limit.', 'plugin-reviewer' ) );
					return;
				}
				if ( $file->isLink() ) {
					$this->mark_incomplete( $result, __( 'A symlink inside a core-owned directory was skipped.', 'plugin-reviewer' ) );
					return;
				}
				if ( ! $file->isFile() ) {
					continue;
				}

				++$result['coverage']['files_enumerated'];
				$result['coverage']['bytes_enumerated'] += max( 0, (int) $file->getSize() );
				if ( $result['coverage']['files_enumerated'] > self::MAX_FILES || $result['coverage']['bytes_enumerated'] > self::MAX_BYTES ) {
					$this->mark_incomplete( $result, __( 'The unexpected-file scan reached its file or byte limit.', 'plugin-reviewer' ) );
					return;
				}

				$path = str_replace( '\\', '/', $file->getPathname() );
				if ( 0 !== strpos( $path, $root ) ) {
					$this->mark_incomplete( $result, __( 'A file outside the WordPress root was skipped.', 'plugin-reviewer' ) );
					return;
				}
				$relative = substr( $path, strlen( $root ) );
				if ( ! isset( $known[ $relative ] ) ) {
					$this->add_finding( $result, 'unexpected', $relative, '', '', __( 'A file not present in the official core package exists in a core-owned directory.', 'plugin-reviewer' ) );
				}
			}
		} catch ( \RuntimeException $exception ) {
			$this->mark_incomplete( $result, __( 'A core-owned directory could not be read completely.', 'plugin-reviewer' ) );
		}
	}

	/**
	 * Normalize and validate a manifest path.
	 *
	 * @param string $path Manifest path.
	 * @return string
	 */
	private function normalize_relative_path( $path ) {
		$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '/^[a-z]:\//i', $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Confirm a path resolves inside the requested root.
	 *
	 * @param string $path Candidate path.
	 * @param string $root Normalized root.
	 * @return bool
	 */
	private function is_inside_root( $path, $root ) {
		$real_root = realpath( $root );
		$real_path = realpath( $path );
		if ( false === $real_root || false === $real_path ) {
			return false;
		}
		$real_root = rtrim( str_replace( '\\', '/', $real_root ), '/' ) . '/';
		$real_path = str_replace( '\\', '/', $real_path );
		return 0 === strpos( $real_path . ( is_dir( $real_path ) ? '/' : '' ), $real_root );
	}

	/**
	 * Add a finding while bounding retained report data.
	 *
	 * @param array<string,mixed> $result    Report result.
	 * @param string              $type      Finding type.
	 * @param string              $path      Relative path.
	 * @param string              $expected  Expected hash.
	 * @param string              $actual    Actual hash.
	 * @param string              $rationale Human-readable evidence.
	 * @return void
	 */
	private function add_finding( &$result, $type, $path, $expected, $actual, $rationale ) {
		$result['counts'][ $type ] = isset( $result['counts'][ $type ] ) ? $result['counts'][ $type ] + 1 : 1;
		if ( count( $result['findings'] ) >= self::MAX_FINDINGS ) {
			$this->mark_incomplete( $result, __( 'Additional findings were omitted after the report limit was reached.', 'plugin-reviewer' ) );
			return;
		}
		$result['findings'][] = array(
			'type'          => $type,
			'path'          => $path,
			'expected_hash' => $expected,
			'actual_hash'   => $actual,
			'rationale'     => $rationale,
		);
	}

	/**
	 * Mark coverage incomplete and retain a unique reason.
	 *
	 * @param array<string,mixed> $result Report result.
	 * @param string              $reason Coverage reason.
	 * @return void
	 */
	private function mark_incomplete( &$result, $reason ) {
		$result['coverage']['complete'] = false;
		if ( ! in_array( $reason, $result['coverage']['reasons'], true ) ) {
			$result['coverage']['reasons'][] = $reason;
		}
	}

	/**
	 * Create a consistent empty result.
	 *
	 * @param string $status  Scan status.
	 * @param string $version WordPress version.
	 * @param string $locale  Package locale.
	 * @param string $reason  Coverage reason.
	 * @return array<string,mixed>
	 */
	private function base_result( $status, $version, $locale, $reason ) {
		$reasons = '' === $reason ? array() : array( $reason );
		return array(
			'status'       => $status,
			'version'      => $version,
			'locale'       => $locale,
			'has_findings' => false,
			'counts'       => array(
				'modified'   => 0,
				'missing'    => 0,
				'unexpected' => 0,
				'read_error' => 0,
			),
			'findings'     => array(),
			'coverage'     => array(
				'complete'         => in_array( $status, array( 'clean', 'findings' ), true ),
				'files_enumerated' => 0,
				'bytes_enumerated' => 0,
				'reasons'          => $reasons,
			),
		);
	}
}
