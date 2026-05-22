<?php
/**
 * Referral Form – AJAX handler + modal HTML renderer.
 *
 * Provides a lightweight "Refer Someone" form that any logged-in member
 * can use from the Dashboard or Directory. Inserts directly into the
 * cb_candidates table with stage = 'referral'.
 *
 * @since 1.2.0
 */

defined('ABSPATH') || exit;

final class CBNexus_Referral_Form {

	/**
	 * Register AJAX hooks and enqueue scripts.
	 */
	public static function init(): void {
		add_action('wp_ajax_cbnexus_submit_referral', [__CLASS__, 'ajax_submit']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
	}

	/**
	 * Enqueue referral JS on portal pages.
	 */
	public static function enqueue_scripts(): void {
		global $post;
		if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'cbnexus_portal')) {
			return;
		}

		wp_enqueue_script(
			'cbnexus-referral',
			CBNEXUS_PLUGIN_URL . 'assets/js/referral.js',
			[],
			CBNEXUS_VERSION,
			true
		);

		wp_localize_script('cbnexus-referral', 'cbnexusReferral', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('cbnexus_submit_referral'),
		]);
	}

	// ─── AJAX Handler ──────────────────────────────────────────────────

	/**
	 * Handle the AJAX referral submission.
	 */
	public static function ajax_submit(): void {
		check_ajax_referer('cbnexus_submit_referral');

		if (!is_user_logged_in()) {
			wp_send_json_error('You must be logged in.', 403);
		}

		$name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
		if (empty($name)) {
			wp_send_json_error('Name is required.');
		}

		global $wpdb;
		$now = gmdate('Y-m-d H:i:s');

		$data = [
			'name'        => $name,
			'email'       => sanitize_email($_POST['email'] ?? ''),
			'company'     => sanitize_text_field(wp_unslash($_POST['company'] ?? '')),
			'industry'    => sanitize_text_field(wp_unslash($_POST['industry'] ?? '')),
			'category_id' => absint($_POST['category_id'] ?? 0) ?: null,
			'referrer_id' => get_current_user_id(),
			'stage'       => 'referral',
			'notes'       => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		$formats = ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'];
		// If category_id is null, adjust format.
		if ($data['category_id'] === null) {
			$formats[4] = '%s'; // Will be NULL.
		}

		$inserted = $wpdb->insert($wpdb->prefix . 'cb_candidates', $data, $formats);

		if ($inserted) {
			// Log the referral.
			if (class_exists('CBNexus_Logger')) {
				CBNexus_Logger::info('Referral submitted', [
					'candidate_name' => $name,
					'referrer_id'    => get_current_user_id(),
					'category_id'    => $data['category_id'],
				]);
			}
			wp_send_json_success(['message' => 'Referral submitted!']);
		} else {
			wp_send_json_error('Could not save the referral. Please try again.');
		}
	}

	// ─── Modal HTML ────────────────────────────────────────────────────

	/**
	 * Render the referral modal HTML. Should be called once per portal page load,
	 * typically at the end of the portal container.
	 */
	public static function render_modal(): void {
		if (!is_user_logged_in()) {
			return;
		}

		// Fetch recruitment categories for the dropdown.
		global $wpdb;
		$cat_table  = $wpdb->prefix . 'cb_recruitment_categories';
		$categories = $wpdb->get_results("SELECT id, title FROM {$cat_table} ORDER BY title ASC") ?: [];
		?>
		<!-- Referral Modal Overlay -->
		<div id="cbnexus-referral-overlay" class="cbnexus-referral-overlay"></div>

		<!-- Referral Modal -->
		<div id="cbnexus-referral-modal" class="cbnexus-referral-modal">
			<div class="cbnexus-referral-header">
				<h3><?php esc_html_e('Refer Someone', 'circleblast-nexus'); ?></h3>
				<button type="button" class="cbnexus-referral-close" data-referral-close aria-label="<?php esc_attr_e('Close', 'circleblast-nexus'); ?>">&times;</button>
			</div>
			<p class="cbnexus-referral-subtitle"><?php esc_html_e('Know someone who\'d be a great fit for the group? Just a name is enough — we\'ll handle the rest.', 'circleblast-nexus'); ?></p>

			<span id="cbnexus-referral-cat-context" class="cbnexus-referral-cat-context" style="display:none;"></span>

			<div id="cbnexus-referral-msg" class="cbnexus-referral-msg" style="display:none;"></div>

			<form id="cbnexus-referral-form" class="cbnexus-referral-form" autocomplete="off">
				<div class="cbnexus-referral-field">
					<label><?php esc_html_e('Name', 'circleblast-nexus'); ?> <span class="cbnexus-required">*</span></label>
					<input type="text" name="name" required placeholder="<?php esc_attr_e('Their full name', 'circleblast-nexus'); ?>" />
				</div>

				<div class="cbnexus-referral-row">
					<div class="cbnexus-referral-field">
						<label><?php esc_html_e('Email', 'circleblast-nexus'); ?></label>
						<input type="email" name="email" placeholder="<?php esc_attr_e('Optional', 'circleblast-nexus'); ?>" />
					</div>
					<div class="cbnexus-referral-field">
						<label><?php esc_html_e('Company', 'circleblast-nexus'); ?></label>
						<input type="text" name="company" placeholder="<?php esc_attr_e('Optional', 'circleblast-nexus'); ?>" />
					</div>
				</div>

				<div class="cbnexus-referral-row">
					<div class="cbnexus-referral-field">
						<label><?php esc_html_e('Industry', 'circleblast-nexus'); ?></label>
						<input type="text" name="industry" placeholder="<?php esc_attr_e('Optional', 'circleblast-nexus'); ?>" />
					</div>
					<div class="cbnexus-referral-field">
						<label><?php esc_html_e('Category', 'circleblast-nexus'); ?></label>
						<select name="category_id">
							<option value="0"><?php esc_html_e('Not sure — council will figure it out', 'circleblast-nexus'); ?></option>
							<?php foreach ($categories as $cat) : ?>
								<option value="<?php echo esc_attr($cat->id); ?>"><?php echo esc_html($cat->title); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="cbnexus-referral-field">
					<label><?php esc_html_e('Anything we should know?', 'circleblast-nexus'); ?></label>
					<textarea name="notes" rows="2" placeholder="<?php esc_attr_e('How do you know them, why they\'d be a fit, etc.', 'circleblast-nexus'); ?>"></textarea>
				</div>

				<div class="cbnexus-referral-submit">
					<button type="submit" class="cbnexus-btn cbnexus-btn-primary"><?php esc_html_e('Submit Referral', 'circleblast-nexus'); ?></button>
					<button type="button" class="cbnexus-btn cbnexus-btn-outline" data-referral-close><?php esc_html_e('Cancel', 'circleblast-nexus'); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	// ─── Floating Button ───────────────────────────────────────────

	/**
	 * Render the floating "Know Someone?" button.
	 * Visible on every portal page for logged-in members.
	 */
	public static function render_floating_button(): void {
		if (!is_user_logged_in()) {
			return;
		}
		?>
		<button type="button"
			class="cbnexus-referral-fab"
			data-referral-open
			aria-label="<?php esc_attr_e('Refer someone', 'circleblast-nexus'); ?>"
			title="<?php esc_attr_e('Know someone?', 'circleblast-nexus'); ?>">
			<span class="cbnexus-referral-fab-icon" aria-hidden="true">👋</span>
			<span class="cbnexus-referral-fab-label"><?php esc_html_e('Know Someone?', 'circleblast-nexus'); ?></span>
		</button>
		<?php
	}
}
