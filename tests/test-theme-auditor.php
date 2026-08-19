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
        add_filter('static_filter', array(Registrar::class, 'ajax'));
        register_post_type('book');
        register_rest_route('fixture/v1', '/books');
        wp_schedule_event(1700000000, 'hourly', 'fixture_cron');
        register_sidebar(array('name' => 'Primary'));
        $fake->add_action('not_a_hook', 'not_a_callback');
        Fake::register_post_type('not-a-post-type');
        require 'literal.php';
        include $dynamic_path;
    }
    public function boot() {}
    public static function ajax() {}
}
PHP
);
file_put_contents( $child . '/broken.php', '<?php function unfinished(' );
file_put_contents( $child . '/declaration.php', '<?php function &add_action() { static $value; return $value; }' );
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
check( in_array( 'widget_area', $categories, true ), 'sidebar registration found' );
check( in_array( 'descriptive_signal', $categories, true ), 'monolithic functions.php described' );
check( in_array( 'literal_include', $categories, true ), 'literal and dynamic includes inventoried' );
check( count( array_filter( $report['findings'], static function ( $finding ) { return 'Fixture\\Registrar::ajax' === $finding['callback'] && false !== strpos( $finding['resolution'], 'linked declaration at src/Services.php' ); } ) ) >= 2, 'quoted and ::class static callbacks link to fully-qualified declaration' );
check( 0 === count( array_filter( $report['findings'], static function ( $finding ) { return in_array( $finding['name'], array( 'not_a_hook', 'not-a-post-type' ), true ); } ) ), 'object and static method calls are not API findings' );
check( 0 === count( array_filter( $report['findings'], static function ( $finding ) { return 'declaration.php' === $finding['file'] && 'hook' === $finding['category']; } ) ), 'function declarations named like APIs are not findings' );
check( 1 === count( array_filter( $report['findings'], static function ( $finding ) { return 'cron' === $finding['category'] && 'fixture_cron' === $finding['name'] && '' === $finding['callback']; } ) ), 'cron records hook argument without fake callback' );
check( 1 === count( array_filter( $report['findings'], static function ( $finding ) { return 'rest_route' === $finding['category'] && 'fixture/v1/books' === $finding['name'] && '' === $finding['callback'] && 'not_applicable' === $finding['resolution']; } ) ), 'REST combines namespace and route without fake callback' );
check( 1 === count( array_filter( $report['findings'], static function ( $finding ) { return 'post_type' === $finding['category'] && 'book' === $finding['name'] && '' === $finding['callback']; } ) ), 'post type does not treat args as callbacks' );
check( in_array( 'fixture-parent', array_column( $report['findings'], 'theme' ), true ), 'parent attribution retained' );
check( count( array_filter( $report['findings'], static function ( $finding ) { return '(dynamic expression)' === $finding['name'] && 'unresolved' === $finding['resolution']; } ) ) >= 1, 'dynamic registration remains visible' );
check( 'incomplete' === $report['status'], 'exclusions make coverage explicitly incomplete' );
check( 1 === count( array_filter( $report['coverage']['errors'], static function ( $note ) { return false !== strpos( $note, 'broken.php: PHP parse failure' ); } ) ), 'malformed PHP is reported as a coverage error' );
check( count( array_filter( $report['coverage']['skipped'], static function ( $note ) { return false !== strpos( $note, 'vendor' ) || false !== strpos( $note, 'symbolic link' ); } ) ) >= 2, 'excluded directory and symlink are reported' );
check( count( array_filter( $report['coverage']['skipped'], static function ( $note ) { return false !== strpos( $note, 'larger than 1 MiB' ); } ) ) === 1, 'oversize source is explicitly skipped' );

echo "Theme auditor fixture tests passed.\n";
