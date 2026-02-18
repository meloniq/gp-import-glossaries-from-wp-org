<?php
/**
 * Plugin Name:       GlotCore Import Glossaries
 * Plugin URI:        https://blog.meloniq.net/gp-import-glossaries/
 *
 * Description:       Extends GlotPress by adding functionality to import glossaries from WordPress.org.
 * Tags:              glotpress, import, glossaries, wordpress.org
 *
 * Requires at least: 4.9
 * Requires PHP:      7.4
 * Version:           0.1
 *
 * Author:            MELONIQ.NET
 * Author URI:        https://meloniq.net/
 *
 * License:           GPLv2
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Text Domain:       glotcore-import-glossaries
 *
 * Requires Plugins:  glotpress
 *
 * @package GlotCore\ImportGlossaries
 */

namespace GlotCore\ImportGlossaries;

// If this file is accessed directly, then abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'GC_IG_TD', 'glotcore-import-glossaries' );
define( 'GC_IG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GC_IG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * GP Init Setup.
 *
 * @return void
 */
function gp_init() {
	global $glotcore_importglossaries;

	require_once __DIR__ . '/src/class-data.php';
	require_once __DIR__ . '/src/class-admin-page.php';
	require_once __DIR__ . '/src/class-importer.php';

	$glotcore_importglossaries['admin-page'] = new Admin_Page();
}
add_action( 'gp_init', __NAMESPACE__ . '\gp_init' );
