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
			'tools.php',
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
		<h2><?php esc_html_e( 'Import Glossaries from WordPress.org', 'glotcore-import-glossaries' ); ?></h2>
		<p><?php esc_html_e( 'Import glossaries from translate.wordpress.org for a specific locales.', 'glotcore-import-glossaries' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gc_ig_import_glossary">
			<?php wp_nonce_field( 'gc_ig_import_glossary', 'gc_ig_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="import_locales"><?php esc_html_e( 'Locales', 'glotcore-import-glossaries' ); ?></label>
					</th>
					<td class="import-locales-checkboxes">
						<hr>
						<?php foreach ( $available_locales as $locale_slug => $locale_name ) { ?>
							<label for="import_locales_<?php echo esc_attr( $locale_slug ); ?>">
								<input type="checkbox" value="<?php echo esc_attr( $locale_slug ); ?>" name="import_locales[]" id="import_locales_<?php echo esc_attr( $locale_slug ); ?>">
								<?php echo esc_html( $locale_name ); ?><span> (<?php echo esc_html( $locale_slug ); ?>)</span>
							</label>
						<?php } ?>
						<hr>
						<label for="import_locales_all">
							<input type="checkbox" id="import_locales_all">
							<?php esc_html_e( 'Select All', 'glotcore-import-glossaries' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Import Glossary', 'glotcore-import-glossaries' ), 'secondary' ); ?>
		</form>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="import_locale"><?php esc_html_e( 'Last imports', 'glotcore-import-glossaries' ); ?></label>
				</th>
				<td>
					<p class="description">
					<?php
					$import_times = get_option( 'gc_ig_glossary_import_times', array() );
					if ( ! empty( $import_times ) ) {
						foreach ( $import_times as $locale => $timestamp ) {
							$locale_name     = $available_locales[ $locale ] ?? $locale;
							$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
							echo esc_html( $locale_name ) . ': ' . esc_html( date_i18n( $datetime_format, $timestamp ) ) . '<br>';
						}
					}
					?>
					</p>
				</td>
			</tr>
		</table>
		<style>
			.import-locales-checkboxes label {
				width: 33%;
				display: inline-block;
			}
			.import-locales-checkboxes label span {
				font-size: 0.8em;
				opacity: 0.7;
			}
			@media screen and (max-width: 782px) {
				.import-locales-checkboxes label {
					line-height: 2.3;
				}
			}
		</style>
		<script>
			document.getElementById('import_locales_all').addEventListener('change', function() {
				const checkboxes = document.querySelectorAll('input[name="import_locales[]"]');
				checkboxes.forEach(checkbox => checkbox.checked = this.checked);
			});
		</script>
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

		$locales = isset( $_POST['import_locales'] ) ? wp_unslash( $_POST['import_locales'] ) : array(); // phpcs:ignore
		$locales = array_map( 'sanitize_text_field', $locales );

		if ( empty( $locales ) ) {
			wp_safe_redirect( add_query_arg( 'gc_ig_error', 'missing_locales', admin_url( 'tools.php?page=glotcore-import-glossaries' ) ) );
			exit;
		}

		$imported = Importer::import_locales( $locales );

		// Check for import error.
		// If any locale returned -1, consider the entire import as failed.
		if ( in_array( -1, $imported, true ) ) {
			wp_safe_redirect( add_query_arg( 'gc_ig_error', 'import_failed', admin_url( 'tools.php?page=glotcore-import-glossaries' ) ) );
			exit;
		}

		$import_count = array_sum( $imported );

		wp_safe_redirect(
			add_query_arg(
				array(
					'gc_ig_success'  => 'glossary_imported',
					'gc_ig_imported' => $import_count,
				),
				admin_url( 'tools.php?page=glotcore-import-glossaries' )
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
		if ( ! $screen || 'tools_page_glotcore-import-glossaries' !== $screen->id ) {
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
				'missing_locales' => __( 'Please select locales.', 'glotcore-import-glossaries' ),
				'import_failed'   => __( 'Glossary import failed. GlotPress glossary classes may not be available.', 'glotcore-import-glossaries' ),
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
