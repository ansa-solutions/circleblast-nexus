<?php
/**
 * Member Directory
 *
 * Searchable, filterable member directory. Card design matches the
 * Manage → Members admin view for visual consistency. Content is
 * permission-based: admins see status + admin actions, members see
 * contact icons + "Request 1:1".
 *
 * Ghost cards for open recruitment needs and AJAX filtering are preserved.
 */

defined('ABSPATH') || exit;

final class CBNexus_Directory {

	public static function init(): void {
		add_action('wp_ajax_cbnexus_directory_filter', [__CLASS__, 'ajax_filter']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
	}

	public static function enqueue_scripts(): void {
		global $post;
		if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'cbnexus_portal')) {
			return;
		}

		wp_enqueue_script(
			'cbnexus-directory',
			CBNEXUS_PLUGIN_URL . 'assets/js/directory.js',
			[],
			CBNEXUS_VERSION,
			true
		);

		wp_localize_script('cbnexus-directory', 'cbnexusDir', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('cbnexus_directory'),
		]);
	}

	// =====================================================================
	//  MAIN RENDER
	// =====================================================================

	public static function render(array $profile): void {
		if (isset($_GET['member_id']) && absint($_GET['member_id']) > 0) {
			self::render_member_profile(absint($_GET['member_id']), $profile);
			return;
		}

		$industries = CBNexus_Member_Service::get_industries();

		// Recruitment categories for filter dropdown.
		global $wpdb;
		$cat_table = $wpdb->prefix . 'cb_recruitment_categories';
		$recruit_cats = $wpdb->get_results("SELECT id, title FROM {$cat_table} ORDER BY title ASC") ?: [];

		// Ghost cards for focus categories (monthly rotation).
		$gaps = class_exists('CBNexus_Recruitment_Coverage_Service')
			? CBNexus_Recruitment_Coverage_Service::get_focus_categories(10)
			: [];
		$admin_email = get_option('admin_email', '');
		$p_border_colors = ['high' => '#dc2626', 'medium' => '#d97706', 'low' => '#059669'];
		?>
		<div class="cbnexus-directory" id="cbnexus-directory">

			<?php if (!empty($gaps)) : ?>
			<!-- Ghost Cards: Open Recruitment Needs -->
			<div class="cbnexus-dir-ghost-banner">
				<div class="cbnexus-dir-ghost-scroll">
					<?php foreach ($gaps as $gap) : ?>
						<div class="cbnexus-ghost-card" style="border-color:<?php echo esc_attr($p_border_colors[$gap->priority] ?? '#d97706'); ?>;">
							<div class="cbnexus-ghost-title"><?php echo esc_html($gap->title); ?></div>
							<?php if ($gap->industry) : ?>
								<span class="cbnexus-ghost-industry"><?php echo esc_html($gap->industry); ?></span>
							<?php endif; ?>
							<div class="cbnexus-ghost-cta"><?php esc_html_e("We're looking — know someone?", 'circleblast-nexus'); ?></div>
							<?php if ($admin_email) : ?>
								<a href="#" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm cbnexus-ghost-refer-btn" data-referral-open data-referral-category="<?php echo esc_attr($gap->id); ?>" data-referral-category-title="<?php echo esc_attr($gap->title); ?>"><?php esc_html_e('Refer Someone', 'circleblast-nexus'); ?></a>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="cbnexus-card">
				<div class="cbnexus-admin-header-row">
					<h2>Directory (<span id="cbnexus-dir-count"><?php
						$members = CBNexus_Member_Repository::get_all_members('active');
						echo esc_html(count($members));
					?></span>)</h2>
					<div class="cbnexus-members-header-actions">
						<div class="cbnexus-dir-view-toggle">
							<button type="button" class="cbnexus-view-btn active" data-view="grid" title="<?php esc_attr_e('Grid View', 'circleblast-nexus'); ?>">▦</button>
							<button type="button" class="cbnexus-view-btn" data-view="list" title="<?php esc_attr_e('List View', 'circleblast-nexus'); ?>">☰</button>
						</div>
					</div>
				</div>

				<!-- Filters -->
				<div class="cbnexus-admin-filters">
					<select id="cbnexus-dir-industry" class="cbnexus-dir-filter-select">
						<option value=""><?php esc_html_e('All Industries', 'circleblast-nexus'); ?></option>
						<?php foreach ($industries as $ind) : ?>
							<option value="<?php echo esc_attr($ind); ?>"><?php echo esc_html($ind); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if (!empty($recruit_cats)) : ?>
					<select id="cbnexus-dir-category" class="cbnexus-dir-filter-select">
						<option value=""><?php esc_html_e('All Categories', 'circleblast-nexus'); ?></option>
						<?php foreach ($recruit_cats as $rc) : ?>
							<option value="<?php echo esc_attr($rc->id); ?>"><?php echo esc_html($rc->title); ?></option>
						<?php endforeach; ?>
					</select>
					<?php endif; ?>
				</div>

				<!-- Search -->
				<div class="cbnexus-admin-search">
					<input type="search" id="cbnexus-dir-search" placeholder="<?php esc_attr_e('Search by name, company, or keyword…', 'circleblast-nexus'); ?>" />
				</div>

				<div class="cbnexus-dir-loading" id="cbnexus-dir-loading" style="display:none;">
					<p><?php esc_html_e('Loading members...', 'circleblast-nexus'); ?></p>
				</div>

				<div class="cbnexus-dir-results cbnexus-dir-grid" id="cbnexus-dir-results">
					<?php echo self::render_cards($members); ?>
				</div>
			</div>

			<!-- Quick Preview Modal -->
			<div id="cbnexus-preview-overlay" class="cbnexus-preview-overlay" style="display:none;"></div>
			<div id="cbnexus-preview-modal" class="cbnexus-preview-modal" style="display:none;">
				<div class="cbnexus-preview-header">
					<h3 id="cbnexus-preview-name"></h3>
					<button type="button" class="cbnexus-preview-close" id="cbnexus-preview-close">&times;</button>
				</div>
				<p id="cbnexus-preview-title" class="cbnexus-text-muted" style="margin-bottom:12px;"></p>
				<div id="cbnexus-preview-body"></div>
				<div class="cbnexus-preview-footer">
					<a id="cbnexus-preview-profile-link" href="#" class="cbnexus-btn cbnexus-btn-primary cbnexus-btn-sm">View Full Profile</a>
				</div>
			</div>
		</div>
		<?php
	}

	// =====================================================================
	//  AJAX FILTER
	// =====================================================================

	public static function ajax_filter(): void {
		check_ajax_referer('cbnexus_directory', 'nonce');

		if (!is_user_logged_in() || !CBNexus_Member_Repository::is_member(get_current_user_id())) {
			wp_send_json_error('Access denied.', 403);
		}

		$search   = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
		$industry = isset($_POST['industry']) ? sanitize_text_field(wp_unslash($_POST['industry'])) : '';
		$category = isset($_POST['category']) ? absint($_POST['category']) : 0;
		$status   = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'active';

		if ($search !== '') {
			$members = CBNexus_Member_Repository::search($search, $status);
		} else {
			$members = CBNexus_Member_Repository::get_all_members($status);
		}

		if ($industry !== '') {
			$members = array_filter($members, function ($m) use ($industry) {
				return ($m['cb_industry'] ?? '') === $industry;
			});
			$members = array_values($members);
		}

		// Filter by recruitment category.
		if ($category > 0) {
			$members = array_filter($members, function ($m) use ($category) {
				$cats = $m['cb_member_categories'] ?? [];
				if (!is_array($cats)) { $cats = json_decode($cats, true) ?: []; }
				return in_array($category, array_map('intval', $cats));
			});
			$members = array_values($members);
		}

		$html  = self::render_cards($members);
		$count = count($members);

		// If filtering by an unfilled category, show friendly empty state.
		if ($count === 0 && $category > 0) {
			$html = '<div class="cbnexus-dir-empty"><p>'
				. esc_html__("No members fill this role yet — help us find the right person.", 'circleblast-nexus')
				. '</p></div>';
		}

		wp_send_json_success([
			'html'  => $html,
			'count' => $count,
			'label' => sprintf(_n('%d member', '%d members', $count, 'circleblast-nexus'), $count),
		]);
	}

	// =====================================================================
	//  CARD RENDERING — Shared mcard design, permission-based content
	// =====================================================================

	private static function render_cards(array $members): string {
		if (empty($members)) {
			return '<div class="cbnexus-dir-empty"><p>' . esc_html__('No members found matching your criteria.', 'circleblast-nexus') . '</p></div>';
		}

		$cat_map    = self::get_category_map();
		$portal_url = CBNexus_Portal_Router::get_portal_url();
		$is_admin   = current_user_can('cbnexus_manage_members');
		$viewer_id  = get_current_user_id();
		$html       = '';

		foreach ($members as $m) {
			$uid         = $m['user_id'];
			$profile_url = add_query_arg(['section' => 'directory', 'member_id' => $uid], $portal_url);
			$initials    = self::get_initials($m);
			$color       = self::get_avatar_color($uid);
			$photo       = !empty($m['cb_photo_url']) ? $m['cb_photo_url'] : '';
			$status      = $m['cb_member_status'] ?? 'active';
			$expertise   = is_array($m['cb_expertise']) ? $m['cb_expertise'] : [];
			$member_cats = $m['cb_member_categories'] ?? [];
			if (!is_array($member_cats)) { $member_cats = json_decode($member_cats, true) ?: []; }
			$cat_name = self::resolve_category_name($member_cats, $cat_map);

			$html .= '<div class="cbnexus-admin-mcard" data-industry="' . esc_attr($m['cb_industry'] ?? '') . '">';

			// ── Header: avatar + identity + status (admin only) ──
			$html .= '<div class="cbnexus-admin-mcard-header">';

			$html .= '<div class="cbnexus-admin-mcard-avatar" style="background:' . esc_attr($color) . '14;">';
			if ($photo) {
				$html .= '<img src="' . esc_url($photo) . '" alt="' . esc_attr($m['display_name']) . '" />';
			} else {
				$html .= '<span class="cbnexus-admin-mcard-initials" style="color:' . esc_attr($color) . ';">' . esc_html($initials) . '</span>';
			}
			$html .= '</div>';

			$html .= '<a href="' . esc_url($profile_url) . '" class="cbnexus-admin-mcard-identity cbnexus-mcard-link">';
			$html .= '<h3 class="cbnexus-admin-mcard-name">' . esc_html($m['display_name']) . '</h3>';
			$title_line = ($m['cb_title'] ?? '') . ($m['cb_company'] ? ' · ' . $m['cb_company'] : '');
			$html .= '<p class="cbnexus-admin-mcard-title">' . esc_html($title_line) . '</p>';
			$html .= '</a>';

			if ($is_admin) {
				$html .= '<span class="cbnexus-status-pill cbnexus-status-' . esc_attr(self::status_color($status)) . '">' . esc_html(ucfirst($status)) . '</span>';
			}

			$html .= '</div>'; // end header

			// ── Details ──
			$html .= '<div class="cbnexus-admin-mcard-details">';

			if ($cat_name !== '—') {
				$html .= '<div class="cbnexus-admin-mcard-detail">';
				$html .= '<span class="cbnexus-admin-mcard-detail-label">Category</span>';
				$html .= '<span class="cbnexus-tag cbnexus-tag-category">' . esc_html($cat_name) . '</span>';
				$html .= '</div>';
			}
			if (!empty($m['cb_industry'])) {
				$html .= '<div class="cbnexus-admin-mcard-detail">';
				$html .= '<span class="cbnexus-admin-mcard-detail-label">Industry</span>';
				$html .= '<span class="cbnexus-admin-mcard-industry">' . esc_html($m['cb_industry']) . '</span>';
				$html .= '</div>';
			}

			// Expertise tags (directory-specific — truncate long tags to 30 chars).
			if (!empty($expertise)) {
				$html .= '<div class="cbnexus-admin-mcard-detail cbnexus-admin-mcard-detail-tags">';
				$html .= '<span class="cbnexus-admin-mcard-detail-label">Skills</span>';
				$html .= '<span class="cbnexus-admin-mcard-tags-wrap">';
				$show = array_slice($expertise, 0, 3);
				foreach ($show as $tag) {
					$display = mb_strlen($tag) > 30 ? mb_substr($tag, 0, 27) . '...' : $tag;
					$html .= '<span class="cbnexus-tag" title="' . esc_attr($tag) . '">' . esc_html($display) . '</span>';
				}
				if (count($expertise) > 3) {
					$html .= '<span class="cbnexus-tag cbnexus-tag-more">+' . (count($expertise) - 3) . '</span>';
				}
				$html .= '</span>';
				$html .= '</div>';
			}

			// Admin-only: email, join date, last login.
			if ($is_admin) {
				$html .= '<div class="cbnexus-admin-mcard-detail">';
				$html .= '<span class="cbnexus-admin-mcard-detail-label">Email</span>';
				$html .= '<span class="cbnexus-admin-mcard-value">' . esc_html($m['user_email']) . '</span>';
				$html .= '</div>';
				if (!empty($m['cb_join_date'])) {
					$html .= '<div class="cbnexus-admin-mcard-detail">';
					$html .= '<span class="cbnexus-admin-mcard-detail-label">Joined</span>';
					$html .= '<span class="cbnexus-admin-mcard-value">' . esc_html($m['cb_join_date']) . '</span>';
					$html .= '</div>';
				}
				$last_login = get_user_meta((int) $uid, 'cb_last_login', true);
				$html .= '<div class="cbnexus-admin-mcard-detail">';
				$html .= '<span class="cbnexus-admin-mcard-detail-label">Last Login</span>';
				if ($last_login) {
					$html .= '<span class="cbnexus-admin-mcard-value">' . esc_html(date_i18n('M j, Y', strtotime($last_login))) . '</span>';
				} else {
					$html .= '<span class="cbnexus-admin-mcard-value cbnexus-text-muted">Never</span>';
				}
				$html .= '</div>';
			}

			$html .= '</div>'; // end details

			// ── Actions (permission-based) ──
			$html .= '<div class="cbnexus-admin-mcard-actions">';

			if ($is_admin) {
				// Admin actions: Edit + status transitions.
				$edit_url = class_exists('CBNexus_Portal_Admin')
					? CBNexus_Portal_Admin::admin_url('members', ['edit_member' => $uid])
					: '#';
				$html .= '<a href="' . esc_url($edit_url) . '" class="cbnexus-btn cbnexus-btn-sm">Edit</a>';
				if ($status !== 'active') {
					$html .= '<a href="' . esc_url(self::member_action_url('activate', $uid)) . '" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-success-outline">Activate</a>';
				}
				if ($status !== 'inactive') {
					$html .= '<a href="' . esc_url(self::member_action_url('deactivate', $uid)) . '" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-danger-outline">Deactivate</a>';
				}
				if ($status !== 'alumni') {
					$html .= '<a href="' . esc_url(self::member_action_url('alumni', $uid)) . '" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-muted-outline">Alumni</a>';
				}
			} else {
				// Member actions: quick contact icons.
				$html .= '<div class="cbnexus-quick-contact">';
				if (!empty($m['user_email'])) {
					$html .= '<a href="mailto:' . esc_attr($m['user_email']) . '" class="cbnexus-contact-btn" title="' . esc_attr($m['user_email']) . '"><svg viewBox="0 0 24 24"><path d="M2 6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v.217l-10 6.118L2 6.217V6zm0 2.383V18a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8.383l-9.515 5.822a1 1 0 0 1-1.04-.03L2 8.383z"/></svg></a>';
				}
				if (!empty($m['cb_phone'])) {
					$html .= '<a href="tel:' . esc_attr($m['cb_phone']) . '" class="cbnexus-contact-btn" title="' . esc_attr($m['cb_phone']) . '"><svg viewBox="0 0 24 24"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24 11.72 11.72 0 0 0 3.67.59 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.72 11.72 0 0 0 .59 3.67 1 1 0 0 1-.24 1.02l-2.23 2.1z"/></svg></a>';
				}
				if (!empty($m['cb_linkedin'])) {
					$html .= '<a href="' . esc_url($m['cb_linkedin']) . '" target="_blank" rel="noopener" class="cbnexus-contact-btn" title="LinkedIn"><svg viewBox="0 0 24 24"><path d="M20.5 2h-17A1.5 1.5 0 002 3.5v17A1.5 1.5 0 003.5 22h17a1.5 1.5 0 001.5-1.5v-17A1.5 1.5 0 0020.5 2zM8 19H5v-9h3zM6.5 8.25A1.75 1.75 0 118.3 6.5a1.78 1.78 0 01-1.8 1.75zM19 19h-3v-4.74c0-1.42-.6-1.93-1.38-1.93A1.74 1.74 0 0013 14.19V19h-3v-9h2.9v1.3a3.11 3.11 0 012.7-1.4c1.55 0 3.36.86 3.36 3.66z"/></svg></a>';
				}
				$html .= '</div>';
			}
			// Quick Preview button.
			$html .= '<button type="button" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-outline cbnexus-preview-btn"'
				. ' data-member-id="' . esc_attr($uid) . '"'
				. ' data-name="' . esc_attr($m['display_name']) . '"'
				. ' data-title="' . esc_attr($m['cb_title'] ?? '') . '"'
				. ' data-company="' . esc_attr($m['cb_company'] ?? '') . '"'
				. ' data-industry="' . esc_attr($m['cb_industry'] ?? '') . '"'
				. ' data-bio="' . esc_attr($m['cb_bio'] ?? '') . '"'
				. ' data-expertise="' . esc_attr(wp_json_encode($expertise)) . '"'
				. ' data-looking="' . esc_attr(wp_json_encode(is_array($m['cb_looking_for'] ?? null) ? $m['cb_looking_for'] : [])) . '"'
				. ' data-help="' . esc_attr(wp_json_encode(is_array($m['cb_can_help_with'] ?? null) ? $m['cb_can_help_with'] : [])) . '"'
				. ' data-email="' . esc_attr($m['user_email']) . '"'
				. ' data-phone="' . esc_attr($m['cb_phone'] ?? '') . '"'
				. ' data-linkedin="' . esc_attr($m['cb_linkedin'] ?? '') . '"'
				. ' data-profile-url="' . esc_url($profile_url) . '"'
				. '>' . esc_html__('Quick Preview', 'circleblast-nexus') . '</button>';

			// Request 1:1 button — available to all members (including admins).
			if ((int) $uid !== $viewer_id) {
				$html .= '<button type="button" class="cbnexus-btn cbnexus-btn-primary cbnexus-btn-sm cbnexus-request-meeting-btn" data-member-id="' . esc_attr($uid) . '">' . esc_html__('Request 1:1', 'circleblast-nexus') . '</button>';
			}

			$html .= '</div>'; // end actions
			$html .= '</div>'; // end card
		}

		return $html;
	}

	// =====================================================================
	//  PROFILE PAGE (unchanged)
	// =====================================================================

	private static function render_member_profile(int $member_id, array $viewer_profile): void {
		$member = CBNexus_Member_Repository::get_profile($member_id);

		if (!$member) {
			echo '<div class="cbnexus-card"><p>' . esc_html__('Member not found.', 'circleblast-nexus') . '</p></div>';
			return;
		}

		$portal_url  = CBNexus_Portal_Router::get_portal_url();
		$back_url    = add_query_arg('section', 'directory', $portal_url);
		$photo       = !empty($member['cb_photo_url']) ? $member['cb_photo_url'] : '';
		$initials    = self::get_initials($member);
		$color       = self::get_avatar_color($member_id);
		$expertise   = is_array($member['cb_expertise']) ? $member['cb_expertise'] : [];
		$looking_for = is_array($member['cb_looking_for']) ? $member['cb_looking_for'] : [];
		$can_help    = is_array($member['cb_can_help_with']) ? $member['cb_can_help_with'] : [];
		$is_self     = ($member_id === ($viewer_profile['user_id'] ?? 0));
		$member_cats = $member['cb_member_categories'] ?? [];
		if (!is_array($member_cats)) { $member_cats = json_decode($member_cats, true) ?: []; }
		$cat_map     = self::get_category_map();
		?>
		<div class="cbnexus-profile-page">
			<a href="<?php echo esc_url($back_url); ?>" class="cbnexus-back-link">
				&larr; <?php esc_html_e('Back to Directory', 'circleblast-nexus'); ?>
			</a>

			<div class="cbnexus-card" style="padding:26px;">
				<div class="cbnexus-profile-header" style="border:none;padding:0;margin:0;box-shadow:none;">
					<div class="cbnexus-profile-avatar-lg" style="background:<?php echo esc_attr($color); ?>14;">
						<?php if ($photo) : ?>
							<img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($member['display_name']); ?>" />
						<?php else : ?>
							<span class="cbnexus-member-initials-lg" style="color:<?php echo esc_attr($color); ?>;"><?php echo esc_html($initials); ?></span>
						<?php endif; ?>
					</div>
					<div class="cbnexus-profile-header-info">
						<h2><?php echo esc_html($member['display_name']); ?></h2>
						<p class="cbnexus-profile-jobtitle"><?php echo esc_html($member['cb_title'] ?? ''); ?><?php if (!empty($member['cb_company'])) : ?> at <?php echo esc_html($member['cb_company']); ?><?php endif; ?></p>
						<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
							<?php if (!empty($member['cb_industry'])) : ?>
								<span class="cbnexus-tag"><?php echo esc_html($member['cb_industry']); ?></span>
							<?php endif; ?>
							<?php if (!empty($member_cats) && !empty($cat_map)) :
								foreach ($member_cats as $cat_id) :
									$cat_id = (int) $cat_id;
									if (isset($cat_map[$cat_id])) : ?>
										<span class="cbnexus-tag cbnexus-tag-category"><?php echo esc_html($cat_map[$cat_id]); ?></span>
									<?php endif;
								endforeach;
							endif; ?>
						</div>
						<p class="cbnexus-profile-since"><?php printf(esc_html__('Member since %s', 'circleblast-nexus'), esc_html($member['cb_join_date'] ?? '—')); ?></p>
					</div>
					<div class="cbnexus-profile-header-actions">
						<?php if (!$is_self) : ?>
							<button type="button" class="cbnexus-btn cbnexus-btn-primary cbnexus-btn-sm cbnexus-request-meeting-btn" data-member-id="<?php echo esc_attr($member_id); ?>">
								<?php esc_html_e('Request 1:1', 'circleblast-nexus'); ?>
							</button>
						<?php endif; ?>
						<?php if (!empty($member['cb_linkedin'])) : ?>
							<a href="<?php echo esc_url($member['cb_linkedin']); ?>" target="_blank" rel="noopener" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm"><?php esc_html_e('LinkedIn', 'circleblast-nexus'); ?></a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="cbnexus-profile-columns">
				<?php if (!empty($member['cb_bio'])) : ?>
					<div class="cbnexus-card">
						<h3><?php esc_html_e('About', 'circleblast-nexus'); ?></h3>
						<p style="margin:0;font-size:14px;color:var(--cb-text-sec);line-height:1.7;"><?php echo nl2br(esc_html($member['cb_bio'])); ?></p>
					</div>
				<?php endif; ?>

				<div class="cbnexus-card">
					<?php if (!empty($expertise)) : ?>
						<h3><?php esc_html_e('Expertise', 'circleblast-nexus'); ?></h3>
						<div class="cbnexus-tag-list" style="margin-bottom:14px;">
							<?php foreach ($expertise as $tag) : ?>
								<span class="cbnexus-tag"><?php echo esc_html($tag); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if (!empty($looking_for)) : ?>
						<h3><?php esc_html_e('Looking For', 'circleblast-nexus'); ?></h3>
						<div class="cbnexus-tag-list" style="margin-bottom:14px;">
							<?php foreach ($looking_for as $tag) : ?>
								<span class="cbnexus-tag cbnexus-tag-looking"><?php echo esc_html($tag); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if (!empty($can_help)) : ?>
						<h3><?php esc_html_e('Can Help With', 'circleblast-nexus'); ?></h3>
						<div class="cbnexus-tag-list">
							<?php foreach ($can_help as $tag) : ?>
								<span class="cbnexus-tag cbnexus-tag-help"><?php echo esc_html($tag); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	// =====================================================================
	//  HELPERS
	// =====================================================================

	private static function get_category_map(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_recruitment_categories';

		if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
			return [];
		}

		$rows = $wpdb->get_results("SELECT id, title FROM {$table} ORDER BY title ASC");
		$map  = [];
		if ($rows) {
			foreach ($rows as $r) {
				$map[(int) $r->id] = $r->title;
			}
		}
		return $map;
	}

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

	private static function status_color(string $status): string {
		$map = [
			'active' => 'green', 'inactive' => 'red', 'alumni' => 'muted',
			'pending' => 'gold', 'draft' => 'gold',
		];
		return $map[$status] ?? 'muted';
	}

	/**
	 * Build a nonce-protected member action URL (for admin actions in directory cards).
	 */
	private static function member_action_url(string $action, int $uid): string {
		$base = class_exists('CBNexus_Portal_Admin')
			? CBNexus_Portal_Admin::admin_url('members')
			: '';
		$url = add_query_arg([
			'cbnexus_portal_member_action' => $action,
			'uid' => $uid,
		], $base);
		return wp_nonce_url($url, 'cbnexus_pa_member_' . $uid, '_panonce');
	}
}