<?php
/**
 * Portal Router
 *
 * ITER-0006 / UX Refresh: Shortcode-based routing for the member portal.
 * Updated layout: horizontal pill navigation (no sidebar), plum & gold
 * branded header, stacked content area.
 *
 * Usage: Add [cbnexus_portal] shortcode to a WordPress page.
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Router {

	/**
	 * Portal sections and their render callbacks.
	 *
	 * @var array<string, array{label: string, icon: string, callback: callable}>
	 */
	private static $sections = [];

	/**
	 * Initialize the portal.
	 */
	public static function init(): void {
		add_shortcode('cbnexus_portal', [__CLASS__, 'render_shortcode']);
		add_action('template_redirect', [__CLASS__, 'access_control']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

		// Register default sections (visible to all members).
		self::$sections = [
			'dashboard' => [
				'label'    => __('Home', 'circleblast-nexus'),
				'icon'     => '🏠',
				'callback' => ['CBNexus_Portal_Dashboard', 'render'],
			],
			'directory' => [
				'label'    => __('Directory', 'circleblast-nexus'),
				'icon'     => '👥',
				'callback' => ['CBNexus_Directory', 'render'],
			],
			'meetings' => [
				'label'    => __('Meetings', 'circleblast-nexus'),
				'icon'     => '🤝',
				'callback' => ['CBNexus_Portal_Meetings', 'render'],
			],
			'journal' => [
				'label'    => __('Journal', 'circleblast-nexus'),
				'icon'     => '📓',
				'callback' => ['CBNexus_Portal_Journal', 'render'],
			],
			'circleup' => [
				'label'    => __('CircleUp', 'circleblast-nexus'),
				'icon'     => '📢',
				'callback' => ['CBNexus_Portal_CircleUp', 'render'],
			],
			'actions' => [
				'label'    => __('My Actions', 'circleblast-nexus'),
				'icon'     => '✅',
				'callback' => ['CBNexus_Portal_CircleUp', 'render_actions_page'],
			],
			'events' => [
				'label'    => __('Events', 'circleblast-nexus'),
				'icon'     => '📅',
				'callback' => ['CBNexus_Portal_Events', 'render'],
			],
			'club' => [
				'label'    => __('Club', 'circleblast-nexus'),
				'icon'     => '📊',
				'callback' => ['CBNexus_Portal_Club', 'render'],
			],
		];

		// Role-gated sections: added dynamically on render (see build_sections).
	}

	/**
	 * Enqueue portal CSS on pages that contain the shortcode.
	 */
	public static function enqueue_assets(): void {
		global $post;

		if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'cbnexus_portal')) {
			return;
		}

		wp_enqueue_style(
			'cbnexus-portal',
			CBNEXUS_PLUGIN_URL . 'assets/css/portal.css',
			[],
			CBNEXUS_VERSION
		);

		// Inject active color scheme overrides as inline CSS.
		$scheme_css = CBNexus_Color_Scheme::get_css_overrides();
		wp_add_inline_style('cbnexus-portal', $scheme_css);

		// WordPress dashicons still used in some sub-components.
		wp_enqueue_style('dashicons');

		// Events calendar styles.
		wp_enqueue_style(
			'cbnexus-portal-events',
			CBNEXUS_PLUGIN_URL . 'assets/css/portal-events.css',
			['cbnexus-portal'],
			CBNEXUS_VERSION
		);

		// Events JS (RSVP).
		wp_enqueue_script(
			'cbnexus-events',
			CBNEXUS_PLUGIN_URL . 'assets/js/events.js',
			[],
			CBNEXUS_VERSION,
			true
		);
		wp_localize_script('cbnexus-events', 'cbnexus_ajax_url', admin_url('admin-ajax.php'));
		wp_add_inline_script('cbnexus-events', 'window.cbnexus_events_nonce = "' . wp_create_nonce('cbnexus_events') . '";', 'before');

		// Help drawer toggle.
		wp_enqueue_script(
			'cbnexus-help',
			CBNEXUS_PLUGIN_URL . 'assets/js/help.js',
			[],
			CBNEXUS_VERSION,
			true
		);
	}

	/**
	 * Access control: redirect non-members away from portal pages.
	 */
	public static function access_control(): void {
		global $post;

		if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'cbnexus_portal')) {
			return;
		}

		// Not logged in → redirect to login.
		if (!is_user_logged_in()) {
			wp_safe_redirect(wp_login_url(get_permalink()));
			exit;
		}

		// Logged in but not a CB member → redirect to home with notice.
		if (!CBNexus_Member_Repository::is_member(get_current_user_id())) {
			wp_safe_redirect(home_url('?cbnexus_access=denied'));
			exit;
		}
	}

	/**
	 * Render the portal shortcode.
	 *
	 * @return string HTML output.
	 */
	public static function render_shortcode(): string {
		if (!is_user_logged_in()) {
			return '<p>' . esc_html__('Please log in to access the member portal.', 'circleblast-nexus') . '</p>';
		}

		$user_id = get_current_user_id();
		if (!CBNexus_Member_Repository::is_member($user_id)) {
			return '<p>' . esc_html__('You do not have access to the member portal.', 'circleblast-nexus') . '</p>';
		}

		$current_section = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'dashboard';

		// Build role-gated sections for this user.
		self::build_role_sections($user_id);

		if (!isset(self::$sections[$current_section])) {
			$current_section = 'dashboard';
		}

		$profile = CBNexus_Member_Repository::get_profile($user_id);

		ob_start();
		?>
		<div class="cbnexus-portal">
			<?php self::render_header($profile); ?>

			<div class="cbnexus-portal-layout">
				<?php self::render_nav($current_section); ?>

				<main class="cbnexus-portal-content">
					<?php self::render_section($current_section, $profile); ?>
				</main>
			</div>

			<?php CBNexus_Portal_Help::render_drawer(); ?>
			<?php CBNexus_Referral_Form::render_modal(); ?>
			<?php if (class_exists('CBNexus_Portal_Journal')) { CBNexus_Portal_Journal::render_quick_modal(); } ?>
			<?php CBNexus_Feedback_Form::render_modal(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add role-gated sections for the current user.
	 * Single "Manage" section for cb_admin + cb_super_admin.
	 * Super admins see additional tabs within the same section.
	 */
	private static function build_role_sections(int $user_id): void {
		$user = get_userdata($user_id);
		if (!$user) { return; }

		// Profile is always routable but not in nav (accessed via avatar).
		self::$sections['profile'] = [
			'label'    => __('Profile', 'circleblast-nexus'),
			'icon'     => '👤',
			'callback' => ['CBNexus_Portal_Profile', 'render'],
			'hidden'   => true, // Excluded from nav rendering.
		];

		$is_admin = in_array('cb_admin', $user->roles, true) || in_array('cb_super_admin', $user->roles, true);

		if ($is_admin) {
			self::$sections['manage'] = [
				'label'    => __('Manage', 'circleblast-nexus'),
				'icon'     => '🛡️',
				'callback' => ['CBNexus_Portal_Admin', 'render'],
			];
		}
	}

	/**
	 * Render the portal header — branded with plum & gold dot.
	 * Admin-role links rendered next to avatar.
	 */
	private static function render_header(array $profile): void {
		$initials = self::get_initials($profile);
		$base_url = get_permalink();
		$current  = isset($_GET['section']) ? sanitize_key($_GET['section']) : '';

		$user  = wp_get_current_user();
		$is_admin = in_array('cb_admin', $user->roles, true) || in_array('cb_super_admin', $user->roles, true);
		?>
		<header class="cbnexus-portal-header">
			<div>
				<div class="cbnexus-portal-brand">
					<span class="cbnexus-portal-brand-name"><?php esc_html_e('The Circle', 'circleblast-nexus'); ?></span>
				</div>
				<h1 class="cbnexus-portal-subtitle"><?php esc_html_e('Member Portal', 'circleblast-nexus'); ?></h1>
			</div>
			<div class="cbnexus-portal-header-right">
				<?php if (class_exists('CBNexus_Portal_Journal')) { CBNexus_Portal_Journal::render_header_button(); } ?>
				<button type="button" class="cbnexus-header-link cbnexus-referral-header-btn" data-referral-open aria-label="<?php esc_attr_e('Refer someone', 'circleblast-nexus'); ?>" title="<?php esc_attr_e('Know someone?', 'circleblast-nexus'); ?>">
					<span class="cbnexus-header-link-icon">👋</span> <?php esc_html_e('Know Someone?', 'circleblast-nexus'); ?>
				</button>
				<button type="button" id="cbnexus-help-toggle" class="cbnexus-help-toggle" aria-label="<?php esc_attr_e('Help for this page', 'circleblast-nexus'); ?>" title="<?php esc_attr_e('Help for this page', 'circleblast-nexus'); ?>">?</button>
				<button type="button" class="cbnexus-feedback-toggle" data-feedback-open aria-label="<?php esc_attr_e('Send Feedback', 'circleblast-nexus'); ?>" title="<?php esc_attr_e('Send Feedback', 'circleblast-nexus'); ?>">✉</button>
				<?php if ($is_admin) : ?>
					<a href="<?php echo esc_url(add_query_arg('section', 'manage', $base_url)); ?>" class="cbnexus-header-link <?php echo $current === 'manage' ? 'active' : ''; ?>">
						<span class="cbnexus-header-link-icon">🛡️</span> Manage
					</a>
				<?php endif; ?>
				<a href="<?php echo esc_url(add_query_arg('section', 'profile', $base_url)); ?>" class="cbnexus-portal-avatar" title="<?php esc_attr_e('My Profile', 'circleblast-nexus'); ?>">
					<span class="cbnexus-portal-avatar-initials"><?php echo esc_html($initials); ?></span>
				</a>
			</div>
		</header>
		<?php
	}

	/**
	 * Render horizontal pill navigation.
	 */
	private static function render_nav(string $current): void {
		$base_url = get_permalink();
		$action_count = 0;
		if (is_user_logged_in()) {
			$action_count = CBNexus_CircleUp_Repository::count_open_actions(get_current_user_id());
		}
		?>
		<nav class="cbnexus-portal-nav">
			<ul>
				<?php foreach (self::$sections as $slug => $section) :
					// Manage section renders in the header, not the nav bar.
					if ($slug === 'manage') { continue; }
					// Hidden sections (like profile) are routable but not shown in nav.
					if (!empty($section['hidden'])) { continue; }
				?>
					<li class="<?php echo $slug === $current ? 'cbnexus-nav-active' : ''; ?>">
						<a href="<?php echo esc_url(add_query_arg('section', $slug, $base_url)); ?>" title="<?php echo esc_attr($section['label']); ?>">
							<span class="cbnexus-nav-icon"><?php echo esc_html($section['icon']); ?></span>
							<span class="cbnexus-nav-label"><?php echo esc_html($section['label']); ?></span>
							<?php if ($slug === 'actions' && $action_count > 0) : ?>
								<span class="cbnexus-nav-badge"><?php echo esc_html($action_count); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
	}

	/**
	 * Render the active section content.
	 */
	private static function render_section(string $section, array $profile): void {
		$callback = self::$sections[$section]['callback'] ?? null;

		if (is_callable($callback)) {
			call_user_func($callback, $profile);
		} else {
			self::render_section_placeholder($profile);
		}
	}

	/**
	 * Generic placeholder for sections not yet built.
	 */
	public static function render_section_placeholder(array $profile): void {
		?>
		<div class="cbnexus-card">
			<h2><?php esc_html_e('Coming Soon', 'circleblast-nexus'); ?></h2>
			<p class="cbnexus-text-muted"><?php esc_html_e('This section is under development and will be available in a future update.', 'circleblast-nexus'); ?></p>
		</div>
		<?php
	}

	/**
	 * Get the portal page URL (finds the page containing the shortcode).
	 *
	 * @return string Portal page URL or home URL as fallback.
	 */
	public static function get_portal_url(): string {
		static $cached_url = null;
		if ($cached_url !== null) {
			return $cached_url;
		}

		global $wpdb;

		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_content LIKE '%[cbnexus_portal]%'
			 AND post_status = 'publish'
			 AND post_type = 'page'
			 LIMIT 1"
		);

		$cached_url = $page_id ? get_permalink($page_id) : home_url();
		return $cached_url;
	}

	/**
	 * Get initials from a member profile.
	 */
	private static function get_initials(array $m): string {
		$first = $m['first_name'] ?? '';
		$last  = $m['last_name'] ?? '';
		if ($first && $last) {
			return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
		}
		$display = $m['display_name'] ?? '?';
		return strtoupper(mb_substr($display, 0, 2));
	}
}
