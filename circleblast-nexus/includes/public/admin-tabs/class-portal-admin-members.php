<?php
/**
 * Portal Admin – Members Tab
 *
 * Handles member listing, add/edit form, status transitions, and CSV export.
 *
 * Revision notes:
 *   - Card view (default) + table view with toggle
 *   - Form sections reorganized: Account → Professional & Contact → Networking & About → Admin
 *   - Category elevated above Industry in both list and form
 *   - N+1 category query eliminated with pre-fetched category map
 *   - Inline styles replaced with CSS classes
 *   - Ambassador field: raw User ID input → searchable member dropdown
 *   - Email change warning on edit
 *   - Website field added to profile form (was missing from member-facing profile)
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Admin_Members {

	// ─── Render ─────────────────────────────────────────────────────────

	public static function render(): void {
		$notice = sanitize_key($_GET['pa_notice'] ?? '');
		$filter_status = sanitize_key($_GET['status'] ?? '');
		$search = sanitize_text_field($_GET['s'] ?? '');
		$view_mode = sanitize_key($_GET['view'] ?? 'card'); // card or table

		// If editing or creating, show the member form instead of the list.
		$edit_uid = absint($_GET['edit_member'] ?? 0);
		if ($edit_uid || isset($_GET['new_member'])) {
			self::render_member_form($edit_uid);
			return;
		}

		$members = ($search !== '')
			? CBNexus_Member_Repository::search($search, $filter_status)
			: CBNexus_Member_Repository::get_all_members($filter_status);

		$counts = CBNexus_Member_Repository::count_by_status();
		$base   = CBNexus_Portal_Admin::admin_url('members');

		// Pre-fetch category names once (fixes N+1 query).
		$cat_map = self::get_category_map();
		?>
		<?php CBNexus_Portal_Admin::render_notice($notice); ?>

		<div class="cbnexus-card">
			<div class="cbnexus-admin-header-row">
				<h2>Members (<?php echo esc_html($counts['total']); ?>)</h2>
				<div class="cbnexus-members-header-actions">
					<div class="cbnexus-dir-view-toggle">
						<a href="<?php echo esc_url(add_query_arg('view', 'card', $base)); ?>" class="cbnexus-view-btn <?php echo $view_mode === 'card' ? 'active' : ''; ?>" title="Card View">▦</a>
						<a href="<?php echo esc_url(add_query_arg('view', 'table', $base)); ?>" class="cbnexus-view-btn <?php echo $view_mode === 'table' ? 'active' : ''; ?>" title="Table View">☰</a>
					</div>
					<?php if (current_user_can('cbnexus_create_members')) : ?>
						<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('members', ['new_member' => '1'])); ?>" class="cbnexus-btn cbnexus-btn-primary cbnexus-btn-sm">+ Add Member</a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Status filters -->
			<div class="cbnexus-admin-filters">
				<a href="<?php echo esc_url($base); ?>" class="<?php echo $filter_status === '' ? 'active' : ''; ?>">All (<?php echo esc_html($counts['total']); ?>)</a>
				<a href="<?php echo esc_url(add_query_arg('status', 'active', $base)); ?>" class="<?php echo $filter_status === 'active' ? 'active' : ''; ?>">Active (<?php echo esc_html($counts['active']); ?>)</a>
				<a href="<?php echo esc_url(add_query_arg('status', 'inactive', $base)); ?>" class="<?php echo $filter_status === 'inactive' ? 'active' : ''; ?>">Inactive (<?php echo esc_html($counts['inactive']); ?>)</a>
				<a href="<?php echo esc_url(add_query_arg('status', 'alumni', $base)); ?>" class="<?php echo $filter_status === 'alumni' ? 'active' : ''; ?>">Alumni (<?php echo esc_html($counts['alumni']); ?>)</a>
			</div>

			<!-- Search -->
			<form method="get" action="" class="cbnexus-admin-search">
				<input type="hidden" name="section" value="manage" />
				<input type="hidden" name="admin_tab" value="members" />
				<?php if ($filter_status) : ?><input type="hidden" name="status" value="<?php echo esc_attr($filter_status); ?>" /><?php endif; ?>
				<?php if ($view_mode !== 'card') : ?><input type="hidden" name="view" value="<?php echo esc_attr($view_mode); ?>" /><?php endif; ?>
				<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search by name or email…" />
				<button type="submit" class="cbnexus-btn">Search</button>
			</form>

			<?php if (empty($members)) : ?>
				<div class="cbnexus-admin-empty">No members found.</div>
			<?php elseif ($view_mode === 'card') : ?>
				<?php self::render_card_view($members, $cat_map); ?>
			<?php else : ?>
				<?php self::render_table_view($members, $cat_map); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Card-based member listing — the default view.
	 */
	private static function render_card_view(array $members, array $cat_map): void {
		?>
		<div class="cbnexus-admin-member-grid">
			<?php foreach ($members as $m) :
				$uid    = $m['user_id'];
				$status = $m['cb_member_status'] ?? 'active';
				$initials = self::get_initials($m);
				$color    = self::get_avatar_color($uid);
				$photo    = !empty($m['cb_photo_url']) ? $m['cb_photo_url'] : '';
				$cat_name = self::resolve_category_name($m['cb_member_categories'] ?? [], $cat_map);
			?>
				<div class="cbnexus-admin-mcard">
					<div class="cbnexus-admin-mcard-header">
						<div class="cbnexus-admin-mcard-avatar" style="background:<?php echo esc_attr($color); ?>14;">
							<?php if ($photo) : ?>
								<img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($m['display_name']); ?>" />
							<?php else : ?>
								<span class="cbnexus-admin-mcard-initials" style="color:<?php echo esc_attr($color); ?>;"><?php echo esc_html($initials); ?></span>
							<?php endif; ?>
						</div>
						<div class="cbnexus-admin-mcard-identity">
							<h3 class="cbnexus-admin-mcard-name"><?php echo esc_html($m['display_name']); ?></h3>
							<p class="cbnexus-admin-mcard-title"><?php echo esc_html(($m['cb_title'] ?? '') . ($m['cb_company'] ? ' · ' . $m['cb_company'] : '')); ?></p>
						</div>
						<?php CBNexus_Portal_Admin::status_pill($status); ?>
					</div>

					<div class="cbnexus-admin-mcard-details">
						<?php if ($cat_name !== '—') : ?>
							<div class="cbnexus-admin-mcard-detail">
								<span class="cbnexus-admin-mcard-detail-label">Category</span>
								<span class="cbnexus-tag cbnexus-tag-category"><?php echo esc_html($cat_name); ?></span>
							</div>
						<?php endif; ?>
						<?php if (!empty($m['cb_industry'])) : ?>
							<div class="cbnexus-admin-mcard-detail">
								<span class="cbnexus-admin-mcard-detail-label">Industry</span>
								<span class="cbnexus-admin-mcard-industry"><?php echo esc_html($m['cb_industry']); ?></span>
							</div>
						<?php endif; ?>
						<div class="cbnexus-admin-mcard-detail">
							<span class="cbnexus-admin-mcard-detail-label">Email</span>
							<span class="cbnexus-admin-mcard-value"><?php echo esc_html($m['user_email']); ?></span>
						</div>
						<?php if (!empty($m['cb_join_date'])) : ?>
							<div class="cbnexus-admin-mcard-detail">
								<span class="cbnexus-admin-mcard-detail-label">Joined</span>
								<span class="cbnexus-admin-mcard-value"><?php echo esc_html($m['cb_join_date']); ?></span>
							</div>
						<?php endif; ?>
					</div>

					<div class="cbnexus-admin-mcard-actions">
						<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('members', ['edit_member' => $uid])); ?>" class="cbnexus-btn cbnexus-btn-sm">Edit</a>
						<?php if ($status !== 'active') : ?>
							<a href="<?php echo esc_url(self::member_action_url('activate', $uid)); ?>" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-success-outline">Activate</a>
						<?php endif; ?>
						<?php if ($status !== 'inactive') : ?>
							<a href="<?php echo esc_url(self::member_action_url('deactivate', $uid)); ?>" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-danger-outline">Deactivate</a>
						<?php endif; ?>
						<?php if ($status !== 'alumni') : ?>
							<a href="<?php echo esc_url(self::member_action_url('alumni', $uid)); ?>" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-muted-outline">Alumni</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * List view — stacked rows, no horizontal scroll.
	 *
	 * Each member is a self-contained row-card with:
	 *   Row 1: Avatar + Name/Title/Company + Status pill
	 *   Row 2: Category · Industry · Joined · Email
	 *   Row 3: Actions
	 */
	private static function render_table_view(array $members, array $cat_map): void {
		?>
		<div class="cbnexus-admin-member-list">
			<?php foreach ($members as $m) :
				$uid      = $m['user_id'];
				$status   = $m['cb_member_status'] ?? 'active';
				$cat_name = self::resolve_category_name($m['cb_member_categories'] ?? [], $cat_map);
				$initials = self::get_initials($m);
				$color    = self::get_avatar_color($uid);
				$photo    = !empty($m['cb_photo_url']) ? $m['cb_photo_url'] : '';
			?>
				<div class="cbnexus-admin-mlist-row">
					<!-- Row 1: identity -->
					<div class="cbnexus-admin-mlist-primary">
						<div class="cbnexus-admin-mcard-avatar cbnexus-admin-mcard-avatar-sm" style="background:<?php echo esc_attr($color); ?>14;">
							<?php if ($photo) : ?>
								<img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($m['display_name']); ?>" />
							<?php else : ?>
								<span class="cbnexus-admin-mcard-initials" style="color:<?php echo esc_attr($color); ?>;"><?php echo esc_html($initials); ?></span>
							<?php endif; ?>
						</div>
						<div class="cbnexus-admin-mlist-identity">
							<strong><?php echo esc_html($m['display_name']); ?></strong>
							<span class="cbnexus-admin-meta"><?php echo esc_html(($m['cb_title'] ?? '') . ($m['cb_company'] ? ' · ' . $m['cb_company'] : '')); ?></span>
						</div>
						<?php CBNexus_Portal_Admin::status_pill($status); ?>
					</div>

					<!-- Row 2: metadata -->
					<div class="cbnexus-admin-mlist-meta">
						<?php if ($cat_name !== '—') : ?>
							<span class="cbnexus-tag cbnexus-tag-category"><?php echo esc_html($cat_name); ?></span>
						<?php endif; ?>
						<?php if (!empty($m['cb_industry'])) : ?>
							<span class="cbnexus-admin-mcard-industry"><?php echo esc_html($m['cb_industry']); ?></span>
						<?php endif; ?>
						<?php if (!empty($m['cb_join_date'])) : ?>
							<span class="cbnexus-admin-meta">Joined <?php echo esc_html($m['cb_join_date']); ?></span>
						<?php endif; ?>
						<span class="cbnexus-admin-meta"><?php echo esc_html($m['user_email']); ?></span>
					</div>

					<!-- Row 3: actions -->
					<div class="cbnexus-admin-mlist-actions">
						<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('members', ['edit_member' => $uid])); ?>" class="cbnexus-link">Edit</a>
						<?php if ($status !== 'active') : ?>
							<a href="<?php echo esc_url(self::member_action_url('activate', $uid)); ?>" class="cbnexus-link cbnexus-link-green">Activate</a>
						<?php endif; ?>
						<?php if ($status !== 'inactive') : ?>
							<a href="<?php echo esc_url(self::member_action_url('deactivate', $uid)); ?>" class="cbnexus-link cbnexus-link-red">Deactivate</a>
						<?php endif; ?>
						<?php if ($status !== 'alumni') : ?>
							<a href="<?php echo esc_url(self::member_action_url('alumni', $uid)); ?>" class="cbnexus-link cbnexus-link-muted">Alumni</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Inline member add/edit form within the portal.
	 *
	 * Sections: Account → Professional & Contact → Networking & About → Admin
	 * Category sits above Industry. Ambassador uses member dropdown.
	 */
	private static function render_member_form(int $edit_uid): void {
		$editing  = $edit_uid > 0;
		$profile  = $editing ? CBNexus_Member_Repository::get_profile($edit_uid) : null;

		if ($editing && !$profile) {
			echo '<div class="cbnexus-card"><p>Member not found.</p></div>';
			return;
		}
		if (!$editing && !current_user_can('cbnexus_create_members')) {
			echo '<div class="cbnexus-card"><p>You do not have permission to create members.</p></div>';
			return;
		}

		$flash_errors = get_transient('cbnexus_pa_member_errors') ?: null;
		$flash_data   = get_transient('cbnexus_pa_member_flash') ?: null;
		delete_transient('cbnexus_pa_member_errors');
		delete_transient('cbnexus_pa_member_flash');

		$industries = CBNexus_Member_Service::get_industries();

		$v = function (string $k) use ($flash_data, $profile): string {
			if ($flash_data && isset($flash_data[$k])) { return sanitize_text_field($flash_data[$k]); }
			return (string) ($profile[$k] ?? '');
		};
		$vt = function (string $k) use ($flash_data, $profile): string {
			if ($flash_data && isset($flash_data[$k])) { return sanitize_text_field($flash_data[$k]); }
			$t = $profile[$k] ?? [];
			return is_array($t) ? implode(', ', $t) : (string) $t;
		};
		$cur_role = 'cb_member';
		if ($profile) {
			foreach (['cb_super_admin', 'cb_admin', 'cb_member'] as $r) {
				if (in_array($r, $profile['roles'] ?? [], true)) { $cur_role = $r; break; }
			}
		}

		// Category data.
		$current_cat = 0;
		if ($profile) {
			$cat_meta = $profile['cb_member_categories'] ?? [];
			$current_cat = is_array($cat_meta) && !empty($cat_meta) ? (int) $cat_meta[0] : 0;
		}
		if ($flash_data && isset($flash_data['cb_member_categories'])) {
			$current_cat = (int) $flash_data['cb_member_categories'];
		}
		global $wpdb;
		$cat_table = $wpdb->prefix . 'cb_recruitment_categories';
		$recruit_cats = $wpdb->get_results("SELECT id, title, industry FROM {$cat_table} ORDER BY title ASC") ?: [];

		// Ambassador dropdown data.
		$active_members = CBNexus_Member_Repository::get_all_members('active');
		?>
		<div class="cbnexus-card">
			<div class="cbnexus-admin-header-row">
				<h2><?php echo $editing ? 'Edit Member' : 'Add New Member'; ?></h2>
				<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('members')); ?>" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm">← Back</a>
			</div>

			<?php if ($flash_errors) : ?>
				<div class="cbnexus-portal-notice cbnexus-notice-error">
					<?php foreach ($flash_errors as $err) : ?><p><?php echo esc_html($err); ?></p><?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" class="cbnexus-member-form">
				<?php wp_nonce_field('cbnexus_portal_save_member', '_panonce'); ?>
				<?php if ($editing) : ?><input type="hidden" name="edit_user_id" value="<?php echo esc_attr($edit_uid); ?>" /><?php endif; ?>

				<!-- ═══ Section 1: Account Information ═══ -->
				<div class="cbnexus-form-section-label">Account Information</div>
				<div class="cbnexus-form-group">
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>First Name <span class="cbnexus-required">*</span></label>
							<input type="text" name="first_name" value="<?php echo esc_attr($v('first_name')); ?>" class="cbnexus-input" required />
						</div>
						<div class="cbnexus-form-field">
							<label>Last Name <span class="cbnexus-required">*</span></label>
							<input type="text" name="last_name" value="<?php echo esc_attr($v('last_name')); ?>" class="cbnexus-input" required />
						</div>
					</div>
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>Email <span class="cbnexus-required">*</span></label>
							<input type="email" name="user_email" value="<?php echo esc_attr($v('user_email')); ?>" class="cbnexus-input" required />
							<?php if ($editing) : ?>
								<span class="cbnexus-field-hint">Changing email will affect this member's login credentials.</span>
							<?php endif; ?>
						</div>
						<div class="cbnexus-form-field cbnexus-form-field-narrow">
							<label>Role</label>
							<select name="cb_role" class="cbnexus-input">
								<option value="cb_member" <?php selected($cur_role, 'cb_member'); ?>>Member</option>
								<option value="cb_admin" <?php selected($cur_role, 'cb_admin'); ?>>Admin</option>
								<option value="cb_super_admin" <?php selected($cur_role, 'cb_super_admin'); ?>>Super Admin</option>
							</select>
						</div>
					</div>
				</div>

				<!-- ═══ Section 2: Professional & Contact ═══ -->
				<div class="cbnexus-form-section-label">Professional & Contact</div>
				<div class="cbnexus-form-group">
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>Company <span class="cbnexus-required">*</span></label>
							<input type="text" name="cb_company" value="<?php echo esc_attr($v('cb_company')); ?>" class="cbnexus-input" required />
						</div>
						<div class="cbnexus-form-field">
							<label>Job Title <span class="cbnexus-required">*</span></label>
							<input type="text" name="cb_title" value="<?php echo esc_attr($v('cb_title')); ?>" class="cbnexus-input" required />
						</div>
					</div>
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>Category</label>
							<select name="cb_member_categories" class="cbnexus-input">
								<option value="0">— None —</option>
								<?php foreach ($recruit_cats as $rc) : ?>
									<option value="<?php echo esc_attr($rc->id); ?>" <?php selected($current_cat, (int) $rc->id); ?>>
										<?php echo esc_html($rc->title); ?><?php echo $rc->industry ? ' (' . esc_html($rc->industry) . ')' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<span class="cbnexus-field-hint">Which recruitment need does this member fill?</span>
						</div>
						<div class="cbnexus-form-field">
							<label>Industry <span class="cbnexus-required">*</span></label>
							<select name="cb_industry" class="cbnexus-input" required>
								<option value="">— Select —</option>
								<?php foreach ($industries as $ind) : ?>
									<option value="<?php echo esc_attr($ind); ?>" <?php selected($v('cb_industry'), $ind); ?>><?php echo esc_html($ind); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>Phone</label>
							<input type="tel" name="cb_phone" value="<?php echo esc_attr($v('cb_phone')); ?>" class="cbnexus-input" />
						</div>
						<div class="cbnexus-form-field">
							<label>LinkedIn URL</label>
							<input type="url" name="cb_linkedin" value="<?php echo esc_attr($v('cb_linkedin')); ?>" class="cbnexus-input" />
						</div>
					</div>
					<div class="cbnexus-form-field">
						<label>Website</label>
						<input type="url" name="cb_website" value="<?php echo esc_attr($v('cb_website')); ?>" class="cbnexus-input" />
					</div>
				</div>

				<!-- ═══ Section 3: Networking & About ═══ -->
				<div class="cbnexus-form-section-label">Networking & About</div>
				<div class="cbnexus-form-group">
					<div class="cbnexus-form-field">
						<label>Expertise / Skills</label>
						<input type="text" name="cb_expertise" value="<?php echo esc_attr($vt('cb_expertise')); ?>" class="cbnexus-input" placeholder="Comma-separated tags" />
					</div>
					<div class="cbnexus-form-field">
						<label>Looking For</label>
						<input type="text" name="cb_looking_for" value="<?php echo esc_attr($vt('cb_looking_for')); ?>" class="cbnexus-input" placeholder="Comma-separated tags" />
					</div>
					<div class="cbnexus-form-field">
						<label>Can Help With</label>
						<input type="text" name="cb_can_help_with" value="<?php echo esc_attr($vt('cb_can_help_with')); ?>" class="cbnexus-input" placeholder="Comma-separated tags" />
					</div>
					<div class="cbnexus-form-field">
						<label>Bio / About</label>
						<textarea name="cb_bio" rows="3" class="cbnexus-input"><?php echo esc_textarea($v('cb_bio')); ?></textarea>
					</div>
					<div class="cbnexus-form-field">
						<label>Profile Photo URL</label>
						<input type="url" name="cb_photo_url" value="<?php echo esc_attr($v('cb_photo_url')); ?>" class="cbnexus-input" />
					</div>
				</div>

				<!-- ═══ Section 4: Admin Information ═══ -->
				<div class="cbnexus-form-section-label">Admin Information</div>
				<div class="cbnexus-form-group">
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>Referred By</label>
							<input type="text" name="cb_referred_by" value="<?php echo esc_attr($v('cb_referred_by')); ?>" class="cbnexus-input" />
						</div>
						<div class="cbnexus-form-field">
							<label>Join Date</label>
							<input type="date" name="cb_join_date" value="<?php echo esc_attr($v('cb_join_date') ?: gmdate('Y-m-d')); ?>" class="cbnexus-input" />
						</div>
					</div>
					<div class="cbnexus-form-row">
						<div class="cbnexus-form-field">
							<label>Status</label>
							<select name="cb_member_status" class="cbnexus-input">
								<option value="active" <?php selected($v('cb_member_status') ?: 'active', 'active'); ?>>Active</option>
								<option value="inactive" <?php selected($v('cb_member_status'), 'inactive'); ?>>Inactive</option>
								<option value="alumni" <?php selected($v('cb_member_status'), 'alumni'); ?>>Alumni</option>
							</select>
						</div>
						<div class="cbnexus-form-field">
							<label>Onboarding Stage</label>
							<select name="cb_onboarding_stage" class="cbnexus-input">
								<option value="access_setup" <?php selected($v('cb_onboarding_stage') ?: 'access_setup', 'access_setup'); ?>>Access Setup</option>
								<option value="walkthrough" <?php selected($v('cb_onboarding_stage'), 'walkthrough'); ?>>Walkthrough</option>
								<option value="ignite" <?php selected($v('cb_onboarding_stage'), 'ignite'); ?>>Ignite</option>
								<option value="ambassador" <?php selected($v('cb_onboarding_stage'), 'ambassador'); ?>>Ambassador</option>
								<option value="complete" <?php selected($v('cb_onboarding_stage'), 'complete'); ?>>Complete</option>
							</select>
						</div>
					</div>
					<div class="cbnexus-form-field">
						<label>Ambassador</label>
						<select name="cb_ambassador_id" class="cbnexus-input">
							<option value="">— None —</option>
							<?php
							$current_ambassador = $v('cb_ambassador_id');
							foreach ($active_members as $am) :
								if ((int) $am['user_id'] === $edit_uid) { continue; }
							?>
								<option value="<?php echo esc_attr($am['user_id']); ?>" <?php selected($current_ambassador, (string) $am['user_id']); ?>>
									<?php echo esc_html($am['display_name']); ?><?php echo $am['cb_company'] ? ' (' . esc_html($am['cb_company']) . ')' : ''; ?>
								</option>
							<?php endforeach; ?>
						</select>
						<span class="cbnexus-field-hint">The member who will guide this person through onboarding.</span>
					</div>
					<div class="cbnexus-form-field">
						<label>Admin Notes</label>
						<textarea name="cb_notes_admin" rows="2" class="cbnexus-input"><?php echo esc_textarea($v('cb_notes_admin')); ?></textarea>
					</div>
				</div>

				<div class="cbnexus-form-actions">
					<button type="submit" name="cbnexus_portal_save_member" value="1" class="cbnexus-btn cbnexus-btn-primary"><?php echo $editing ? 'Update Member' : 'Create Member'; ?></button>
					<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('members')); ?>" class="cbnexus-btn cbnexus-btn-outline">Cancel</a>
				</div>
			</form>
		</div>
		<?php
	}

	// ─── Action Handlers ────────────────────────────────────────────────

	public static function handle_save_member(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce'] ?? ''), 'cbnexus_portal_save_member')) { return; }

		$editing = isset($_POST['edit_user_id']) && absint($_POST['edit_user_id']) > 0;
		$edit_uid = $editing ? absint($_POST['edit_user_id']) : 0;

		if ($editing && !current_user_can('cbnexus_manage_members')) { return; }
		if (!$editing && !current_user_can('cbnexus_create_members')) { return; }

		$user_data = [
			'user_email'   => sanitize_email(wp_unslash($_POST['user_email'] ?? '')),
			'first_name'   => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
			'last_name'    => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
			'display_name' => trim(sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')) . ' ' . sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''))),
		];

		$meta_keys    = CBNexus_Member_Repository::get_meta_keys();
		$profile_data = [];
		foreach ($meta_keys as $k) {
			if (isset($_POST[$k])) { $profile_data[$k] = wp_unslash($_POST[$k]); }
		}

		$role = sanitize_text_field(wp_unslash($_POST['cb_role'] ?? 'cb_member'));
		if (!in_array($role, ['cb_member', 'cb_admin', 'cb_super_admin'], true)) { $role = 'cb_member'; }

		if ($editing) {
			$wp_update = [
				'ID'           => $edit_uid,
				'first_name'   => $user_data['first_name'],
				'last_name'    => $user_data['last_name'],
				'display_name' => $user_data['display_name'],
			];
			$current = get_userdata($edit_uid);
			if ($current && $current->user_email !== $user_data['user_email'] && !empty($user_data['user_email']) && !email_exists($user_data['user_email'])) {
				$wp_update['user_email'] = $user_data['user_email'];
			}
			wp_update_user($wp_update);

			$user = get_userdata($edit_uid);
			if ($user && !in_array($role, $user->roles, true)) {
				foreach (['cb_member', 'cb_admin', 'cb_super_admin'] as $r) { $user->remove_role($r); }
				$user->add_role($role);
			}

			$result = CBNexus_Member_Service::update_member($edit_uid, $profile_data, true);

			if (!$result['success']) {
				set_transient('cbnexus_pa_member_errors', $result['errors'], 60);
				wp_safe_redirect(CBNexus_Portal_Admin::admin_url('members', ['edit_member' => $edit_uid]));
				exit;
			}

			wp_safe_redirect(CBNexus_Portal_Admin::admin_url('members', ['pa_notice' => 'member_updated']));
			exit;

		} else {
			$result = CBNexus_Member_Service::create_member($user_data, $profile_data, $role);

			if (!$result['success']) {
				set_transient('cbnexus_pa_member_errors', $result['errors'], 60);
				set_transient('cbnexus_pa_member_flash', $_POST, 60);
				wp_safe_redirect(CBNexus_Portal_Admin::admin_url('members', ['new_member' => '1']));
				exit;
			}

			$new_profile = CBNexus_Member_Repository::get_profile($result['user_id']);
			if ($new_profile) {
				CBNexus_Email_Service::send_welcome($result['user_id'], $new_profile);
			}

			wp_safe_redirect(CBNexus_Portal_Admin::admin_url('members', ['pa_notice' => 'member_created']));
			exit;
		}
	}

	public static function handle_member_status(): void {
		$action = sanitize_key($_GET['cbnexus_portal_member_action']);
		$uid    = absint($_GET['uid']);

		if (!wp_verify_nonce(wp_unslash($_GET['_panonce']), 'cbnexus_pa_member_' . $uid)) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		$map = ['activate' => 'active', 'deactivate' => 'inactive', 'alumni' => 'alumni'];
		if (!isset($map[$action])) { return; }

		$result = CBNexus_Member_Service::transition_status($uid, $map[$action]);

		if ($result['success'] && $action === 'activate') {
			$user = get_userdata($uid);
			if ($user) {
				$profile = array_merge(
					CBNexus_Member_Repository::get_profile($uid),
					[
						'user_email'   => $user->user_email,
						'first_name'   => $user->first_name,
						'last_name'    => $user->last_name,
						'display_name' => $user->display_name,
					]
				);
				CBNexus_Email_Service::send_reactivation($uid, $profile);
			}
		}

		$notice = $result['success'] ? 'status_updated' : 'error';
		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('members', ['pa_notice' => $notice]));
		exit;
	}

	public static function handle_export(): void {
		if (!wp_verify_nonce(wp_unslash($_GET['_panonce'] ?? ''), 'cbnexus_portal_export')) { return; }
		if (!current_user_can('cbnexus_export_data')) { return; }

		$data = CBNexus_Admin_Analytics::compute_member_engagement();

		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename=circleblast-analytics-' . gmdate('Y-m-d') . '.csv');

		$out = fopen('php://output', 'w');
		fputcsv($out, ['Name', 'Company', 'Meetings', 'Unique Met', 'CircleUp', 'Notes %', 'Accept %', 'Engagement', 'Risk']);
		foreach ($data as $row) {
			fputcsv($out, [$row['name'], $row['company'], $row['meetings'], $row['unique_met'], $row['circleup'], $row['notes_pct'], $row['accept_pct'], $row['score'], $row['risk']]);
		}
		fclose($out);
		exit;
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	private static function member_action_url(string $action, int $uid): string {
		$base = CBNexus_Portal_Admin::admin_url('members');
		$url  = add_query_arg([
			'cbnexus_portal_member_action' => $action,
			'uid' => $uid,
		], $base);
		return wp_nonce_url($url, 'cbnexus_pa_member_' . $uid, '_panonce');
	}

	/**
	 * Pre-fetch all category ID → title pairs in one query.
	 */
	private static function get_category_map(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_recruitment_categories';
		$rows = $wpdb->get_results("SELECT id, title FROM {$table} ORDER BY title ASC");
		$map = [];
		if ($rows) {
			foreach ($rows as $r) {
				$map[(int) $r->id] = $r->title;
			}
		}
		return $map;
	}

	/**
	 * Resolve category IDs to a display name from a pre-fetched map.
	 */
	private static function resolve_category_name($cat_ids, array $cat_map): string {
		if (empty($cat_ids)) { return '—'; }
		$cat_id = is_array($cat_ids) ? (int) ($cat_ids[0] ?? 0) : 0;
		if ($cat_id > 0 && isset($cat_map[$cat_id])) {
			return $cat_map[$cat_id];
		}
		return '—';
	}

	private static function get_initials(array $m): string {
		$first = $m['first_name'] ?? '';
		$last  = $m['last_name'] ?? '';
		if ($first && $last) {
			return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
		}
		$display = $m['display_name'] ?? '?';
		return strtoupper(mb_substr($display, 0, 2));
	}

	private static function get_avatar_color(int $user_id): string {
		$colors = ['#6366f1', '#059669', '#dc2626', '#2563eb', '#d946ef', '#0891b2', '#ea580c', '#65a30d', '#8b5cf6', '#0d9488'];
		return $colors[$user_id % count($colors)];
	}
}