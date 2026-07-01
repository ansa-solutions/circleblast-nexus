<?php
/**
 * Plugin Name: CircleBlast Nexus
 * Description: CircleBlast Nexus WordPress plugin (CircleBlast project).
 * Version: 1.0.0
 * Author: CircleBlast
 * Text Domain: circleblast-nexus
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

defined('ABSPATH') || exit;

if (!defined('CBNEXUS_VERSION')) {
	define('CBNEXUS_VERSION', '1.4.0');
}
if (!defined('CBNEXUS_PLUGIN_FILE')) {
	define('CBNEXUS_PLUGIN_FILE', __FILE__);
}
if (!defined('CBNEXUS_PLUGIN_DIR')) {
	define('CBNEXUS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('CBNEXUS_PLUGIN_URL')) {
	define('CBNEXUS_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('CBNEXUS_OPTION_MIGRATIONS')) {
	define('CBNEXUS_OPTION_MIGRATIONS', 'cbnexus_migrations');
}
if (!defined('CBNEXUS_MIN_PHP')) {
	define('CBNEXUS_MIN_PHP', '7.4');
}
if (!defined('CBNEXUS_MIN_WP')) {
	define('CBNEXUS_MIN_WP', '5.9');
}

/**
 * Compatibility check — runs early, before any class loading.
 *
 * @return bool True if environment meets requirements.
 */
function cbnexus_meets_requirements(): bool {
	if (version_compare(PHP_VERSION, CBNEXUS_MIN_PHP, '<')) {
		return false;
	}
	if (version_compare(get_bloginfo('version'), CBNEXUS_MIN_WP, '<')) {
		return false;
	}
	return true;
}

/**
 * Show admin notice if requirements are not met.
 */
function cbnexus_requirements_notice(): void {
	$message = sprintf(
		/* translators: 1: required PHP version, 2: required WP version, 3: current PHP version, 4: current WP version */
		__('CircleBlast Nexus requires PHP %1$s+ and WordPress %2$s+. You are running PHP %3$s and WordPress %4$s. The plugin has been deactivated.', 'circleblast-nexus'),
		CBNEXUS_MIN_PHP,
		CBNEXUS_MIN_WP,
		PHP_VERSION,
		get_bloginfo('version')
	);
	printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
}

// Bail early if requirements are not met.
if (!cbnexus_meets_requirements()) {
	add_action('admin_notices', 'cbnexus_requirements_notice');
	return;
}

/**
 * ITER-0003: Autoloader replaces explicit require_once calls.
 * The autoloader itself is the only file we require manually.
 */
require_once CBNEXUS_PLUGIN_DIR . 'includes/class-autoloader.php';
CBNexus_Autoloader::register();

/**
 * CRITICAL: Register custom 'monthly' cron schedule.
 * WordPress does not include a 'monthly' schedule by default —
 * without this filter, wp_schedule_event() with 'monthly' silently fails.
 */
add_filter('cron_schedules', function (array $schedules): array {
	if (!isset($schedules['monthly'])) {
		$schedules['monthly'] = [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __('Once Monthly', 'circleblast-nexus'),
		];
	}
	return $schedules;
});

/**
 * Register cron hooks.
 */
add_action('cbnexus_log_cleanup', ['CBNexus_Log_Retention', 'cleanup']);
add_action('cbnexus_meeting_reminders', ['CBNexus_Meeting_Service', 'send_reminders']);
add_action('cbnexus_suggestion_cycle', ['CBNexus_Suggestion_Generator', 'cron_run']);
add_action('cbnexus_suggestion_reminders', ['CBNexus_Suggestion_Generator', 'send_follow_up_reminders']);
add_action('cbnexus_ai_extraction', ['CBNexus_AI_Extractor', 'process_pending']);
add_action('cbnexus_analytics_snapshot', ['CBNexus_Portal_Club', 'take_snapshot']);
add_action('cbnexus_monthly_report', ['CBNexus_Admin_Analytics', 'send_monthly_report']);
add_action('cbnexus_event_reminders', ['CBNexus_Event_Service', 'send_reminders']);
add_action('cbnexus_events_digest', ['CBNexus_Event_Service', 'send_digest']);
add_action('cbnexus_token_cleanup', ['CBNexus_Token_Service', 'cleanup']);
add_action('cbnexus_recruitment_focus_rotate', ['CBNexus_Recruitment_Coverage_Service', 'cron_rotate_focus']);

/**
 * Self-healing migrations: activation runs them, but deploys that only sync
 * files (git pull / FTP / `wp plugin update`) never fire the activation hook.
 * Run any pending migrations on the first admin request after such a deploy so
 * the schema never lags behind the code. Priority 1 so tables/columns exist
 * before other admin_init handlers touch them.
 */
add_action('admin_init', function (): void {
	if (class_exists('CBNexus_Migration_Runner') && CBNexus_Migration_Runner::has_pending()) {
		CBNexus_Migration_Runner::run();
	}
}, 1);

/**
 * Initialize admin features when in admin context.
 */
if (is_admin()) {
	CBNexus_Admin_Logs::init();
	CBNexus_Admin_Members::init();
	CBNexus_Admin_Member_Form::init();
	CBNexus_Admin_Matching::init();
	CBNexus_Admin_Archivist::init();
	CBNexus_Admin_Analytics::init();
	CBNexus_Admin_Recruitment::init();
	CBNexus_Admin_Events::init();
	CBNexus_Admin_Email_Templates::init();
	CBNexus_Admin_Recruitment_Categories::init();
}

/**
 * ITER-0006: Initialize public portal (shortcode, access control, profile form).
 */
CBNexus_Portal_Router::init();
CBNexus_Portal_Profile::init();
CBNexus_Directory::init();
CBNexus_Portal_Meetings::init();
CBNexus_Suggestion_Generator::init();
CBNexus_Fireflies_Webhook::init();
CBNexus_Portal_CircleUp::init();
CBNexus_Portal_Club::init();
CBNexus_Portal_Events::init();
CBNexus_Portal_Journal::init();
CBNexus_Portal_Admin::init();
CBNexus_Token_Router::init();
CBNexus_Referral_Form::init();
CBNexus_Feedback_Form::init();
CBNexus_Members_API::init();
CBNexus_Events_ICS_API::init();

/**
 * Redirect members to the portal after login instead of wp-admin.
 */
add_filter('login_redirect', function ($redirect_to, $requested, $user) {
	if (!$user instanceof WP_User || !user_can($user, 'cbnexus_access_portal')) {
		return $redirect_to;
	}
	$portal_url = CBNexus_Portal_Router::get_portal_url();
	return $portal_url ? $portal_url : $redirect_to;
}, 10, 3);

/**
 * Track last-login timestamp for portal members.
 */
add_action('wp_login', function ($user_login, $user) {
	if ($user instanceof WP_User && user_can($user, 'cbnexus_access_portal')) {
		update_user_meta($user->ID, 'cb_last_login', current_time('mysql'));
	}
}, 10, 2);

/**
 * Activation: run migrations and schedule cron (activation-only policy, approved).
 */
function cbnexus_activate(): void {
	// Re-check requirements on activation.
	if (!cbnexus_meets_requirements()) {
		deactivate_plugins(plugin_basename(CBNEXUS_PLUGIN_FILE));
		wp_die(
			esc_html(sprintf(
				__('CircleBlast Nexus requires PHP %1$s+ and WordPress %2$s+.', 'circleblast-nexus'),
				CBNEXUS_MIN_PHP,
				CBNEXUS_MIN_WP
			)),
			__('Plugin Activation Error', 'circleblast-nexus'),
			['back_link' => true]
		);
	}

	if (class_exists('CBNexus_Migration_Runner')) {
		CBNexus_Migration_Runner::run();
	}

	if (class_exists('CBNexus_Log_Retention')) {
		CBNexus_Log_Retention::schedule();
	}

	// Schedule cron jobs — respects saved frequencies and disabled states.
	// NOTE: cbnexus_suggestion_cycle runs monthly by default so suggestions
	// appear in the portal. cbnexus_suggestion_reminders is disabled by
	// default — no emails are sent until the admin enables them.
	// Suggestion notification emails are separately gated by the
	// cbnexus_suggestion_emails site option (default: disabled).
	$cron_overrides = get_option('cbnexus_cron_schedules', []);
	$cron_defaults  = [
		'cbnexus_meeting_reminders'        => 'daily',
		'cbnexus_suggestion_cycle'         => 'monthly',
		'cbnexus_suggestion_reminders'     => 'disabled',   // Enabled manually when ready.
		'cbnexus_ai_extraction'            => 'daily',
		'cbnexus_analytics_snapshot'       => 'daily',
		'cbnexus_monthly_report'           => 'monthly',
		'cbnexus_event_reminders'          => 'daily',
		'cbnexus_events_digest'            => 'weekly',
		'cbnexus_token_cleanup'            => 'daily',
		'cbnexus_recruitment_focus_rotate' => 'monthly',
	];
	foreach ($cron_defaults as $hook => $default_freq) {
		$freq = $cron_overrides[$hook] ?? $default_freq;
		if ($freq === 'disabled') { continue; }
		if (!wp_next_scheduled($hook)) {
			wp_schedule_event(time(), $freq, $hook);
		}
	}
}

register_activation_hook(__FILE__, 'cbnexus_activate');

/**
 * Deactivation: unschedule cron jobs.
 */
function cbnexus_deactivate(): void {
	if (class_exists('CBNexus_Log_Retention')) {
		CBNexus_Log_Retention::unschedule();
	}
	wp_clear_scheduled_hook('cbnexus_meeting_reminders');
	wp_clear_scheduled_hook('cbnexus_suggestion_cycle');
	wp_clear_scheduled_hook('cbnexus_suggestion_reminders');
	wp_clear_scheduled_hook('cbnexus_ai_extraction');
	wp_clear_scheduled_hook('cbnexus_analytics_snapshot');
	wp_clear_scheduled_hook('cbnexus_monthly_report');
	wp_clear_scheduled_hook('cbnexus_event_reminders');
	wp_clear_scheduled_hook('cbnexus_events_digest');
	wp_clear_scheduled_hook('cbnexus_token_cleanup');
	wp_clear_scheduled_hook('cbnexus_recruitment_blast');
	wp_clear_scheduled_hook('cbnexus_recruitment_focus_rotate');
}

register_deactivation_hook(__FILE__, 'cbnexus_deactivate');
