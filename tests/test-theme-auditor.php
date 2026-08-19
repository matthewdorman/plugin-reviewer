<?php
/** Fixture tests for bounded theme static analysis. */

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-themeauditor.php';

use PluginReviewer\ThemeAuditor;

function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$base   = sys_get_temp_dir() . '/plugin-reviewer-theme-' . getmypid();
$child  = $base . '/child';
$parent = $base . '/parent';
mkdir( $child . '/src', 0777, true );
mkdir( $child . '/vendor', 0777, true );
mkdir( $parent, 0777, true );

file_put_contents( $child . '/functions.php', "<?php\n" . str_repeat( "// padding\n", 1000 ) . "add_shortcode('catalog', 'render_catalog');\nadd_action(\$dynamic_hook, \$dynamic_callback);\nglobal \$wpdb; \$wpdb->get_results('SELECT 1');\n" );
file_put_contents( $child . '/src/Services.php', <<<'PHP'
<?php
namespace Fixture;
interface Contract {}
trait Shared {}
class Registrar implements Contract {
    use Shared;
    public function register() {
        add_action('init', array($this, 'boot'));
        add_action('wp_ajax_fixture', array('Fixture\\Registrar', 'ajax'));
        register_post_type('book');
        register_rest_route('fixture/v1', '/books');
        require 'literal.php';
        include $dynamic_path;
    }
    public function boot() {}
    public static function ajax() {}
}
PHP
);
file_put_contents( $child . '/broken.php', '<?php function unfinished(' );
file_put_contents( $child . '/oversize.php', '<?php ' . str_repeat( 'x', ThemeAuditor::MAX_FILE_BYTES + 1 ) );
file_put_contents( $child . '/vendor/ignored.php', "<?php add_action('ignored', 'ignored');" );
file_put_contents( $parent . '/functions.php', "<?php add_filter('parent_filter', 'parent_callback');" );
@symlink( $parent . '/functions.php', $child . '/linked.php' );

$auditor = new ThemeAuditor();
$report  = $auditor->audit_roots( array(
	array( 'slug' => 'fixture-child', 'role' => 'child', 'path' => $child ),
	array( 'slug' => 'fixture-parent', 'role' => 'parent', 'path' => $parent ),
) );

check( 2 === count( $report['themes'] ), 'parent and child are separate' );
check( $report['themes'][0]['classes'] === 1 && $report['themes'][0]['interfaces'] === 1 && $report['themes'][0]['traits'] === 1, 'class-heavy declarations indexed' );
$categories = array_column( $report['findings'], 'category' );
check( in_array( 'shortcode', $categories, true ), 'functions.php shortcode found' );
check( in_array( 'rest_route', $categories, true ) && in_array( 'post_type', $categories, true ), 'registration APIs found' );
check( in_array( 'ajax', $categories, true ) && in_array( 'database_api', $categories, true ), 'AJAX and database API found' );
check( in_array( 'descriptive_signal', $categories, true ), 'monolithic functions.php described' );
check( in_array( 'literal_include', $categories, true ), 'literal and dynamic includes inventoried' );
check( count( array_filter( $report['findings'], static function ( $finding ) { return false !== strpos( $finding['resolution'], 'linked declaration at' ); } ) ) >= 1, 'literal callback links only to a proven declaration' );
check( in_array( 'fixture-parent', array_column( $report['findings'], 'theme' ), true ), 'parent attribution retained' );
check( count( array_filter( $report['findings'], static function ( $finding ) { return '(dynamic expression)' === $finding['name'] && 'unresolved' === $finding['resolution']; } ) ) >= 1, 'dynamic registration remains visible' );
check( 'incomplete' === $report['status'], 'exclusions make coverage explicitly incomplete' );
check( count( array_filter( $report['coverage']['skipped'], static function ( $note ) { return false !== strpos( $note, 'vendor' ) || false !== strpos( $note, 'symbolic link' ); } ) ) >= 2, 'excluded directory and symlink are reported' );
check( count( array_filter( $report['coverage']['skipped'], static function ( $note ) { return false !== strpos( $note, 'larger than 1 MiB' ); } ) ) === 1, 'oversize source is explicitly skipped' );

echo "Theme auditor fixture tests passed.\n";
