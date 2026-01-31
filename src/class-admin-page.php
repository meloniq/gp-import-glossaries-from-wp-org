<?php
/**
 * Admin Page.
 *
 * @package GlotCore\ImportGlossaries
 */

namespace GlotCore\ImportGlossaries;

/**
 * Admin Page class.
 */
class Admin_Page {

	/**
	 * Transient prefix for caching.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'gc_ig_glossary_';

	/**
	 * Cache expiry in seconds (24 hours).
	 *
	 * @var int
	 */
	const CACHE_EXPIRY = DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 10 );
		add_action( 'admin_post_gc_ig_import_glossary', array( $this, 'handle_glossary_import' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'GlotCore Import Glossaries', 'glotcore-import-glossaries' ),
			__( 'GlotCore Import Glossaries', 'glotcore-import-glossaries' ),
			'manage_options',
			'glotcore-import-glossaries',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GlotCore Import Glossaries from wordpress.org', 'glotcore-import-glossaries' ); ?></h1>
			<?php $this->render_glossary_import_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the glossary import section.
	 *
	 * @return void
	 */
	protected function render_glossary_import_section(): void {
		$available_locales = Importer::get_supported_locales();
		?>
		<h2><?php esc_html_e( 'Import Glossary from WordPress.org', 'glotcore-import-glossaries' ); ?></h2>
		<p><?php esc_html_e( 'Import glossary entries from translate.wordpress.org for a specific locale.', 'glotcore-import-glossaries' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gc_ig_import_glossary">
			<?php wp_nonce_field( 'gc_ig_import_glossary', 'gc_ig_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="import_locale"><?php esc_html_e( 'Locale', 'glotcore-import-glossaries' ); ?></label>
					</th>
					<td>
						<select name="import_locale" id="import_locale">
							<?php foreach ( $available_locales as $locale_slug => $locale_name ) { ?>
								<option value="<?php echo esc_attr( $locale_slug ); ?>"><?php echo esc_html( $locale_name ); ?></option>
							<?php } ?>
						</select>
						<?php
						$import_times = get_option( 'gc_ig_glossary_import_times', array() );
						if ( ! empty( $import_times ) ) {
							echo '<p class="description">';
							esc_html_e( 'Last imports:', 'glotcore-import-glossaries' );
							echo '<br>';
							foreach ( $import_times as $locale => $timestamp ) {
								$locale_name = $available_locales[ $locale ] ?? $locale;
								echo esc_html( $locale_name ) . ': ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ) . '<br>';
							}
							echo '</p>';
						}
						?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Import Glossary', 'glotcore-import-glossaries' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Handle glossary import action.
	 *
	 * @return void
	 */
	public function handle_glossary_import(): void {
		// Verify nonce.
		if ( ! isset( $_POST['gc_ig_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gc_ig_nonce'] ) ), 'gc_ig_import_glossary' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'glotcore-import-glossaries' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'glotcore-import-glossaries' ) );
		}

		$locale = isset( $_POST['import_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['import_locale'] ) ) : '';

		if ( empty( $locale ) ) {
			wp_safe_redirect( add_query_arg( 'gc_ig_error', 'missing_locale', admin_url( 'options-general.php?page=glotcore-import-glossaries' ) ) );
			exit;
		}

		$imported = Importer::import_from_wporg( $locale );

		if ( -1 === $imported ) {
			wp_safe_redirect( add_query_arg( 'gc_ig_error', 'import_failed', admin_url( 'options-general.php?page=glotcore-import-glossaries' ) ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'gc_ig_success'  => 'glossary_imported',
					'gc_ig_imported' => $imported,
				),
				admin_url( 'options-general.php?page=glotcore-import-glossaries' )
			)
		);
		exit;
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_glotcore-import-glossaries' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gc_ig_success'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$success = sanitize_text_field( wp_unslash( $_GET['gc_ig_success'] ) );

			if ( 'glossary_imported' === $success ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$imported = isset( $_GET['gc_ig_imported'] ) ? absint( $_GET['gc_ig_imported'] ) : 0;
				// translators: %d: number of entries imported.
				$message = sprintf( __( 'Glossary imported successfully. %d entries imported.', 'glotcore-import-glossaries' ), $imported );
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
				<?php
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gc_ig_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error = sanitize_text_field( wp_unslash( $_GET['gc_ig_error'] ) );

			$messages = array(
				'missing_locale' => __( 'Please select a locale.', 'glotcore-import-glossaries' ),
				'import_failed'  => __( 'Glossary import failed. GlotPress glossary classes may not be available.', 'glotcore-import-glossaries' ),
			);

			$message = $messages[ $error ] ?? __( 'An error occurred.', 'glotcore-import-glossaries' );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	}
}
