<?php
/**
 * Member Service
 *
 * ITER-0004: Business logic layer for member operations.
 * Handles validation, creation, updates, and status transitions.
 * Delegates data access to CBNexus_Member_Repository.
 */

defined('ABSPATH') || exit;

final class CBNexus_Member_Service {

	/**
	 * Create a new member with full validation.
	 *
	 * @param array $user_data    WordPress user fields (user_email, first_name, last_name).
	 * @param array $profile_data Member profile meta fields.
	 * @param string $role        Role slug. Default 'cb_member'.
	 * @return array{success: bool, user_id?: int, errors?: string[]}
	 */
	public static function create_member(array $user_data, array $profile_data, string $role = 'cb_member'): array {
		// Validate.
		$errors = self::validate_for_creation($user_data, $profile_data);
		if (!empty($errors)) {
			return ['success' => false, 'errors' => $errors];
		}

		// Sanitize profile data.
		$profile_data = self::sanitize_profile($profile_data);

		// Set defaults for admin-only fields.
		if (empty($profile_data['cb_member_status'])) {
			$profile_data['cb_member_status'] = 'active';
		}
		if (empty($profile_data['cb_join_date'])) {
			$profile_data['cb_join_date'] = gmdate('Y-m-d');
		}
		if (empty($profile_data['cb_onboarding_stage'])) {
			$profile_data['cb_onboarding_stage'] = 'access_setup';
		}

		// Create via repository.
		$user_id = CBNexus_Member_Repository::create($user_data, $profile_data, $role);

		if (is_wp_error($user_id)) {
			return ['success' => false, 'errors' => [$user_id->get_error_message()]];
		}

		// Log the creation.
		if (class_exists('CBNexus_Logger')) {
			CBNexus_Logger::info('Member created.', [
				'user_id' => $user_id,
				'email'   => $user_data['user_email'] ?? '',
				'role'    => $role,
			]);
		}

		return ['success' => true, 'user_id' => $user_id];
	}

	/**
	 * Update an existing member's profile.
	 *
	 * @param int   $user_id      WordPress user ID.
	 * @param array $profile_data Fields to update.
	 * @param bool  $is_admin     Whether the updater has admin privileges.
	 * @return array{success: bool, errors?: string[]}
	 */
	public static function update_member(int $user_id, array $profile_data, bool $is_admin = false): array {
		if (!CBNexus_Member_Repository::is_member($user_id)) {
			return ['success' => false, 'errors' => ['User is not a member of The Circle.']];
		}

		// Filter out fields the member cannot edit themselves.
		if (!$is_admin) {
			$profile_data = self::filter_member_editable($profile_data);
		}

		// Validate the fields being updated.
		$errors = self::validate_profile_data($profile_data);
		if (!empty($errors)) {
			return ['success' => false, 'errors' => $errors];
		}

		// Sanitize and save.
		$profile_data = self::sanitize_profile($profile_data);
		CBNexus_Member_Repository::update_profile($user_id, $profile_data);

		if (class_exists('CBNexus_Logger')) {
			CBNexus_Logger::info('Member profile updated.', [
				'user_id'        => $user_id,
				'fields_updated' => array_keys($profile_data),
			]);
		}

		return ['success' => true];
	}

	/**
	 * Transition a member's status with validation.
	 *
	 * Valid transitions:
	 *   active   → inactive, alumni
	 *   inactive → active, alumni
	 *   alumni   → active
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $new_status Target status.
	 * @return array{success: bool, errors?: string[]}
	 */
	public static function transition_status(int $user_id, string $new_status): array {
		$current = CBNexus_Member_Repository::get_status($user_id);

		if ($current === '') {
			return ['success' => false, 'errors' => ['User has no current status. Is this a member of The Circle?']];
		}

		$valid_transitions = [
			'active'   => ['inactive', 'alumni'],
			'inactive' => ['active', 'alumni'],
			'alumni'   => ['active'],
		];

		$allowed = $valid_transitions[$current] ?? [];

		if (!in_array($new_status, $allowed, true)) {
			return [
				'success' => false,
				'errors'  => [sprintf('Cannot transition from "%s" to "%s".', $current, $new_status)],
			];
		}

		$ok = CBNexus_Member_Repository::set_status($user_id, $new_status);

		if (!$ok) {
			return ['success' => false, 'errors' => ['Failed to update status.']];
		}

		if (class_exists('CBNexus_Logger')) {
			CBNexus_Logger::info('Member status transitioned.', [
				'user_id'    => $user_id,
				'from'       => $current,
				'to'         => $new_status,
			]);
		}

		return ['success' => true];
	}

	/**
	 * Validate data for member creation.
	 *
	 * @param array $user_data    WordPress user fields.
	 * @param array $profile_data Profile meta fields.
	 * @return string[] Array of error messages (empty = valid).
	 */
	public static function validate_for_creation(array $user_data, array $profile_data): array {
		$errors = [];

		// Email is required and must be valid.
		$email = $user_data['user_email'] ?? '';
		if (empty($email)) {
			$errors[] = 'Email address is required.';
		} elseif (!is_email($email)) {
			$errors[] = 'Invalid email address format.';
		} elseif (email_exists($email)) {
			$errors[] = 'A user with this email address already exists.';
		}

		// First and last name required.
		if (empty(trim($user_data['first_name'] ?? ''))) {
			$errors[] = 'First name is required.';
		}
		if (empty(trim($user_data['last_name'] ?? ''))) {
			$errors[] = 'Last name is required.';
		}

		// Required profile fields.
		$required_profile = ['cb_company', 'cb_title', 'cb_industry'];
		foreach ($required_profile as $key) {
			if (empty(trim($profile_data[$key] ?? ''))) {
				$errors[] = sprintf('%s is required.', self::field_label($key));
			}
		}

		// Industry is freeform text — any non-empty value is accepted. It is
		// intentionally NOT constrained to the taxonomy so candidates coming
		// through recruitment (and members editing their own profile) can enter
		// whatever describes them.

		// Validate profile-specific fields.
		$errors = array_merge($errors, self::validate_profile_data($profile_data));

		return $errors;
	}

	/**
	 * Validate profile data fields.
	 *
	 * @param array $profile_data Fields to validate.
	 * @return string[] Validation errors.
	 */
	public static function validate_profile_data(array $profile_data): array {
		$errors = [];
		$schema = get_option('cbnexus_member_meta_schema', []);

		foreach ($profile_data as $key => $value) {
			if (!isset($schema[$key])) {
				continue;
			}

			$def = $schema[$key];

			// Type-specific validation.
			switch ($def['type'] ?? 'string') {
				case 'email':
					if ($value !== '' && !is_email($value)) {
						$errors[] = sprintf('%s must be a valid email address.', $def['label']);
					}
					break;

				case 'url':
					if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
						$errors[] = sprintf('%s must be a valid URL.', $def['label']);
					}
					break;

				case 'date':
					if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
						$errors[] = sprintf('%s must be a valid date (YYYY-MM-DD).', $def['label']);
					}
					break;

				case 'select':
					if ($value !== '' && !empty($def['options'])) {
						if (!in_array($value, $def['options'], true)) {
							$errors[] = sprintf('Invalid value for %s.', $def['label']);
						}
					}
					break;

				case 'tags':
					$tags = is_array($value) ? $value : [];
					$max = $def['max_tags'] ?? 10;
					if (count($tags) > $max) {
						$errors[] = sprintf('%s cannot have more than %d items.', $def['label'], $max);
					}
					break;

				case 'category_select':
					// Accepts a single category ID or empty.
					// Validation is lightweight; existence check happens at the service layer.
					if ($value !== '' && $value !== null && $value !== '0') {
						if (!is_numeric($value) && !is_array($value)) {
							$errors[] = sprintf('Invalid value for %s.', $def['label']);
						}
					}
					break;
			}

			// Max length check for string/text fields.
			if (isset($def['max_length']) && is_string($value)) {
				if (strlen($value) > $def['max_length']) {
					$errors[] = sprintf('%s exceeds maximum length of %d characters.', $def['label'], $def['max_length']);
				}
			}
		}

		return $errors;
	}

	/**
	 * Get the industry taxonomy list.
	 *
	 * @return string[]
	 */
	public static function get_industries(): array {
		return get_option('cbnexus_industry_taxonomy', []);
	}

	/**
	 * Get the member meta schema.
	 *
	 * @return array
	 */
	public static function get_meta_schema(): array {
		return get_option('cbnexus_member_meta_schema', []);
	}

	/**
	 * Filter profile data to only include member-editable fields.
	 *
	 * @param array $profile_data Input data.
	 * @return array Filtered data.
	 */
	private static function filter_member_editable(array $profile_data): array {
		$schema = get_option('cbnexus_member_meta_schema', []);
		$filtered = [];

		foreach ($profile_data as $key => $value) {
			if (isset($schema[$key]) && !empty($schema[$key]['editable_by_member'])) {
				$filtered[$key] = $value;
			}
		}

		return $filtered;
	}

	/**
	 * Sanitize all profile data.
	 *
	 * @param array $profile_data Raw input.
	 * @return array Sanitized output.
	 */
	private static function sanitize_profile(array $profile_data): array {
		$schema = get_option('cbnexus_member_meta_schema', []);
		$sanitized = [];

		foreach ($profile_data as $key => $value) {
			$type = $schema[$key]['type'] ?? 'string';

			switch ($type) {
				case 'text':
					$sanitized[$key] = sanitize_textarea_field($value);
					break;

				case 'url':
					$sanitized[$key] = esc_url_raw($value);
					break;

				case 'email':
					$sanitized[$key] = sanitize_email($value);
					break;

				case 'tags':
					if (is_array($value)) {
						$sanitized[$key] = array_map('sanitize_text_field', $value);
					} elseif (is_string($value)) {
						// Accept comma-separated string and convert to array.
						$tags = array_map('trim', explode(',', $value));
						$sanitized[$key] = array_filter(array_map('sanitize_text_field', $tags));
					} else {
						$sanitized[$key] = [];
					}
					break;

				case 'category_select':
					// Store as JSON array with a single category ID, or empty.
					// Handle arrays, JSON strings, or plain integers.
					if (is_array($value)) {
						$cat_id = (int) ($value[0] ?? 0);
					} elseif (is_string($value) && strpos($value, '[') === 0) {
						$decoded = json_decode($value, true);
						$cat_id = is_array($decoded) ? (int) ($decoded[0] ?? 0) : 0;
					} else {
						$cat_id = (int) $value;
					}
					$sanitized[$key] = $cat_id > 0 ? wp_json_encode([$cat_id]) : '';
					break;

				case 'date':
					// Validate format, pass through if valid.
					$sanitized[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
					break;

				default:
					$sanitized[$key] = sanitize_text_field($value);
					break;
			}
		}

		return $sanitized;
	}

	/**
	 * Get human-readable label for a meta key.
	 *
	 * @param string $key Meta key.
	 * @return string Label.
	 */
	private static function field_label(string $key): string {
		$schema = get_option('cbnexus_member_meta_schema', []);
		return $schema[$key]['label'] ?? $key;
	}
}
