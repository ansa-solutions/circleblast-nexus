<?php
/**
 * Migration Runner
 *
 * Activation-only migrations (approved policy).
 */

defined('ABSPATH') || exit;

final class CBNexus_Migration_Runner {

	/**
	 * Run all pending migrations.
	 */
	public static function run(): void {
		$migrations = self::get_migrations();

		$applied = get_option(CBNEXUS_OPTION_MIGRATIONS, []);
		if (!is_array($applied)) {
			$applied = [];
		}

		foreach ($migrations as $id => $meta) {
			if (!empty($applied[$id])) {
				continue;
			}

			$ok = self::apply_one($id, $meta);

			// Record applied state only on success.
			if ($ok) {
				$applied[$id] = [
					'applied_at_gmt' => gmdate('Y-m-d H:i:s'),
					'version'        => CBNEXUS_VERSION,
				];
				update_option(CBNEXUS_OPTION_MIGRATIONS, $applied, false);
			} else {
				// Stop at first failure to avoid partial/unknown state.
				break;
			}
		}
	}

	/**
	 * Whether any registered migration has not yet been applied.
	 *
	 * Cheap enough to call on every admin request: one option read plus an
	 * array-key check per migration. Lets the plugin self-heal after deploys
	 * that update files without firing the activation hook (git pull / FTP /
	 * `wp plugin update`), for this and every future migration — no version
	 * bump required.
	 */
	public static function has_pending(): bool {
		$applied = get_option(CBNEXUS_OPTION_MIGRATIONS, []);
		if (!is_array($applied)) {
			return true;
		}
		foreach (self::get_migrations() as $id => $meta) {
			if (empty($applied[$id])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List of available migrations in execution order.
	 *
	 * @return array<string, array{file:string,class:string,method:string}>
	 */
	private static function get_migrations(): array {
		return [
			'001_create_log_table' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/001-create-log-table.php',
				'class'  => 'CBNexus_Migration_001_Create_Log_Table',
				'method' => 'up',
			],
			'002_register_roles' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/002-register-roles.php',
				'class'  => 'CBNexus_Migration_002_Register_Roles',
				'method' => 'up',
			],
			'003_register_member_meta' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/003-register-member-meta.php',
				'class'  => 'CBNexus_Migration_003_Register_Member_Meta',
				'method' => 'up',
			],
			'004_create_email_log' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/004-create-email-log.php',
				'class'  => 'CBNexus_Migration_004_Create_Email_Log',
				'method' => 'up',
			],
			'005_create_meetings' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/005-create-meetings.php',
				'class'  => 'CBNexus_Migration_005_Create_Meetings',
				'method' => 'up',
			],
			'006_create_meeting_notes' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/006-create-meeting-notes.php',
				'class'  => 'CBNexus_Migration_006_Create_Meeting_Notes',
				'method' => 'up',
			],
			'007_create_meeting_responses' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/007-create-meeting-responses.php',
				'class'  => 'CBNexus_Migration_007_Create_Meeting_Responses',
				'method' => 'up',
			],
			'008_create_matching_rules' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/008-create-matching-rules.php',
				'class'  => 'CBNexus_Migration_008_Create_Matching_Rules',
				'method' => 'up',
			],
			'009_create_circleup_meetings' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/009-create-circleup-meetings.php',
				'class'  => 'CBNexus_Migration_009_Create_CircleUp_Meetings',
				'method' => 'up',
			],
			'010_create_circleup_attendees' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/010-create-circleup-attendees.php',
				'class'  => 'CBNexus_Migration_010_Create_CircleUp_Attendees',
				'method' => 'up',
			],
			'011_create_circleup_items' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/011-create-circleup-items.php',
				'class'  => 'CBNexus_Migration_011_Create_CircleUp_Items',
				'method' => 'up',
			],
			'012_create_analytics_snapshots' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/012-create-analytics-snapshots.php',
				'class'  => 'CBNexus_Migration_012_Create_Analytics_Snapshots',
				'method' => 'up',
			],
			'013_create_candidates' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/013-create-candidates.php',
				'class'  => 'CBNexus_Migration_013_Create_Candidates',
				'method' => 'up',
			],
			'014_create_tokens' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/014-create-tokens.php',
				'class'  => 'CBNexus_Migration_014_Create_Tokens',
				'method' => 'up',
			],
			'015_create_events' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/015-create-events.php',
				'class'  => 'CBNexus_Migration_015_Create_Events',
				'method' => 'up',
			],
			'016_create_event_rsvps' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/016-create-event-rsvps.php',
				'class'  => 'CBNexus_Migration_016_Create_Event_RSVPs',
				'method' => 'up',
			],
			'017_create_recruitment_categories' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/017-create-recruitment-categories.php',
				'class'  => 'CBNexus_Migration_017_Create_Recruitment_Categories',
				'method' => 'up',
			],
			'018_add_guest_cost' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/018-add-guest-cost.php',
				'class'  => 'CBNexus_Migration_018_Add_Guest_Cost',
				'method' => 'up',
			],
			'019_add_member_categories' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/019-add-member-categories.php',
				'class'  => 'CBNexus_Migration_019_Add_Member_Categories',
				'method' => 'up',
			],
			'020_seed_recruitment_categories' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/020-seed-recruitment-categories.php',
				'class'  => 'CBNexus_Migration_020_Seed_Recruitment_Categories',
				'method' => 'up',
			],
			'021_create_feedback' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/021-create-feedback.php',
				'class'  => 'CBNexus_Migration_021_Create_Feedback',
				'method' => 'up',
			],
			'022_matching_fixes' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/022-matching-fixes.php',
				'class'  => 'CBNexus_Migration_022_Matching_Fixes',
				'method' => 'up',
			],
			'023_create_member_journal' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/023-create-member-journal.php',
				'class'  => 'CBNexus_Migration_023_Create_Member_Journal',
				'method' => 'up',
			],
			'024_add_meeting_type' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/024-add-meeting-type.php',
				'class'  => 'CBNexus_Migration_024_Add_Meeting_Type',
				'method' => 'up',
			],
			'025_referral_inbox' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/025-referral-inbox.php',
				'class'  => 'CBNexus_Migration_025_Referral_Inbox',
				'method' => 'up',
			],
			'026_add_meeting_notes_visibility' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/026-add-meeting-notes-visibility.php',
				'class'  => 'CBNexus_Migration_026_Add_Meeting_Notes_Visibility',
				'method' => 'up',
			],
			'027_create_candidate_events' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/027-create-candidate-events.php',
				'class'  => 'CBNexus_Migration_027_Create_Candidate_Events',
				'method' => 'up',
			],
			'028_add_candidate_title' => [
				'file'   => CBNEXUS_PLUGIN_DIR . 'includes/migrations/versions/028-add-candidate-title.php',
				'class'  => 'CBNexus_Migration_028_Add_Candidate_Title',
				'method' => 'up',
			],
		];
	}

	/**
	 * Apply a single migration.
	 *
	 * @param string $id
	 * @param array{file:string,class:string,method:string} $meta
	 * @return bool
	 */
	private static function apply_one(string $id, array $meta): bool {
		try {
			if (empty($meta['file']) || empty($meta['class']) || empty($meta['method'])) {
				self::log_error($id, 'Invalid migration metadata.');
				return false;
			}

			if (!file_exists($meta['file'])) {
				self::log_error($id, 'Migration file not found: ' . $meta['file']);
				return false;
			}

			require_once $meta['file'];

			$class = $meta['class'];
			$method = $meta['method'];

			if (!class_exists($class)) {
				self::log_error($id, 'Migration class not found: ' . $class);
				return false;
			}
			if (!is_callable([$class, $method])) {
				self::log_error($id, 'Migration method not callable: ' . $class . '::' . $method);
				return false;
			}

			$ok = (bool) call_user_func([$class, $method]);

			if (!$ok) {
				self::log_error($id, 'Migration returned false.');
				return false;
			}

			return true;
		} catch (Throwable $e) {
			self::log_error($id, 'Exception: ' . $e->getMessage(), [
				'exception_class' => get_class($e),
			]);
			return false;
		}
	}

	/**
	 * Migration errors should not fatal the site; log safely.
	 *
	 * @param string $migration_id
	 * @param string $message
	 * @param array<string,mixed> $context
	 */
	private static function log_error(string $migration_id, string $message, array $context = []): void {
		if (class_exists('CBNexus_Logger')) {
			CBNexus_Logger::error(
				'[migration:' . $migration_id . '] ' . $message,
				$context
			);
			return;
		}

		// Hard fallback if logger isn't available.
		error_log('[circleblast-nexus] [migration:' . $migration_id . '] ' . $message);
	}
}
