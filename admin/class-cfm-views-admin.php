<?php

if (!defined('ABSPATH')) {
  exit;
}

/** Minimal protected administrator surface for platform-owned Views. */
class CFM_Views_Admin
{
  public static function init(): void
  {
    add_action('admin_menu', [__CLASS__, 'register_menu']);
    add_action('admin_init', [__CLASS__, 'handle_actions']);
  }

  public static function register_menu(): void
  {
    add_submenu_page('cfm-frameworks', 'Durable Views', 'Durable Views', 'manage_options', 'cfm-views', [__CLASS__, 'render_page']);
  }

  public static function handle_actions(): void
  {
    if (!is_admin() || !current_user_can('manage_options') || empty($_POST['cfm_views_action'])) {
      return;
    }
    $action = sanitize_key(wp_unslash($_POST['cfm_views_action']));
    check_admin_referer('cfm_views_' . $action, 'cfm_views_nonce');
    $redirect_url = null;
    if ($action === 'create_view') {
      $view_id = CFM_Views_Repository::create_view(['name' => wp_unslash($_POST['name'] ?? ''), 'description' => wp_unslash($_POST['description'] ?? '')]);
      if (!is_wp_error($view_id)) {
        $draft_id = CFM_Views_Repository::create_draft_version($view_id);
        if (!is_wp_error($draft_id)) {
          $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . absint($draft_id));
        }
      }
    } elseif ($action === 'save_entry') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::save_entry($version_id, ['term_uuid' => wp_unslash($_POST['term_uuid'] ?? ''), 'core_terms_framework' => wp_unslash($_POST['framework'] ?? ''), 'group_id' => absint($_POST['group_id'] ?? 0), 'inclusion' => sanitize_key(wp_unslash($_POST['inclusion'] ?? 'include')), 'display_label' => wp_unslash($_POST['display_label'] ?? ''), 'display_order' => absint($_POST['display_order'] ?? 0), 'include_descendants' => !empty($_POST['include_descendants'])]);
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'save_group') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::save_group($version_id, ['group_key' => wp_unslash($_POST['group_key'] ?? ''), 'label' => wp_unslash($_POST['label'] ?? ''), 'description' => wp_unslash($_POST['description'] ?? ''), 'display_order' => absint($_POST['display_order'] ?? 0)]);
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'delete_entry') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::delete_entry($version_id, absint($_POST['entry_id'] ?? 0));
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'publish') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::validate_version($version_id);
      CFM_Views_Repository::publish_version($version_id);
    } elseif ($action === 'retire') {
      CFM_Views_Repository::retire_view(absint($_POST['view_id'] ?? 0));
    } elseif ($action === 'restore') {
      $view_id = absint($_POST['view_id'] ?? 0);
      CFM_Views_Repository::restore_published_version($view_id, absint($_POST['version_id'] ?? 0));
    }
    wp_safe_redirect($redirect_url ?: wp_get_referer() ?: admin_url('admin.php?page=cfm-views'));
    exit;
  }

  public static function render_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You are not allowed to manage Durable Views.', 'profilaxes'));
    }
    global $wpdb;
    $views = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'cfm_views ORDER BY updated_at DESC, id DESC');
    echo '<div class="wrap"><h1>Durable Views</h1><p>Platform-owned presentation models referencing canonical Core Terms UUIDs.</p>';
    echo '<h2>Create View</h2><form method="post">';
    wp_nonce_field('cfm_views_create_view', 'cfm_views_nonce');
    echo '<input type="hidden" name="cfm_views_action" value="create_view">';
    echo '<p><label>Name <input name="name" type="text" required></label> <label>Description <textarea name="description"></textarea></label> <button class="button button-primary">Create draft</button></p></form>';
    echo '<h2>Existing Views</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Status</th><th>Current version</th><th>Actions</th></tr></thead><tbody>';
    foreach ($views as $view) {
      $draft = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_versions WHERE view_id = %d AND status IN (\'draft\', \'review\') ORDER BY version_number DESC LIMIT 1', (int) $view->id));
      echo '<tr><td>' . esc_html($view->name) . '</td><td>' . esc_html($view->status) . '</td><td>' . esc_html((string) ($view->current_version_id ?: 'Draft only')) . '</td><td>';
      if ($draft) {
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=cfm-views&version_id=' . (int) $draft->id)) . '">Edit draft</a> ';
        echo '<form method="post" style="display:inline">';
        wp_nonce_field('cfm_views_publish', 'cfm_views_nonce');
        echo '<input type="hidden" name="cfm_views_action" value="publish"><input type="hidden" name="version_id" value="' . esc_attr((string) $draft->id) . '"><button class="button">Validate / publish draft</button></form>';
      }
      if ((string) $view->status === 'published') {
        echo '<form method="post" style="display:inline;margin-left:4px">';
        wp_nonce_field('cfm_views_retire', 'cfm_views_nonce');
        echo '<input type="hidden" name="cfm_views_action" value="retire"><input type="hidden" name="view_id" value="' . esc_attr((string) $view->id) . '"><button class="button">Retire</button></form>';
      } elseif ((string) $view->status === 'retired' && $view->current_version_id) {
        echo '<form method="post" style="display:inline;margin-left:4px">';
        wp_nonce_field('cfm_views_restore', 'cfm_views_nonce');
        echo '<input type="hidden" name="cfm_views_action" value="restore"><input type="hidden" name="view_id" value="' . esc_attr((string) $view->id) . '"><input type="hidden" name="version_id" value="' . esc_attr((string) $view->current_version_id) . '"><button class="button">Restore</button></form>';
      }
      echo '</td></tr>';
    }
    if (!$views) { echo '<tr><td colspan="4">No Views exist yet.</td></tr>'; }
    echo '</tbody></table>';
    $version_id = absint($_GET['version_id'] ?? 0);
    if ($version_id) {
      self::render_draft_editor($version_id);
    }
    echo '</div>';
  }

  private static function render_draft_editor(int $version_id): void
  {
    $version = CFM_Views_Repository::get_version($version_id);
    if (!$version || (string) $version->status !== 'draft') {
      echo '<div class="notice notice-warning"><p>This workspace is available only for an editable draft version.</p></div>';
      return;
    }
    global $wpdb;
    $view = CFM_Views_Repository::get_view((int) $version->view_id);
    $frameworks = CFM_Framework_Repository::get_frameworks();
    $terms_by_framework = [];
    foreach ((array) $frameworks as $framework) {
      $slug = (string) ($framework->slug ?? '');
      if ($slug !== '') {
        $terms_by_framework[$slug] = CFM::get_terms($slug);
      }
    }
    $entries = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE version_id = %d ORDER BY display_order ASC, id ASC', $version_id));
    $groups = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_groups WHERE version_id = %d ORDER BY display_order ASC, id ASC', $version_id));
    $validation = CFM_Views_Repository::validate_version($version_id);
    echo '<hr><h2>Draft composition: ' . esc_html($view ? $view->name : 'View') . '</h2><p>Draft-only workspace. Add canonical Core Terms to this View, organize them into groups, preview the resolved presentation, then validate before publishing.</p>';
    echo '<div class="notice ' . ($validation['state'] === 'invalid' ? 'notice-error' : ($validation['state'] === 'warning' ? 'notice-warning' : 'notice-success')) . '"><p><strong>Validation: ' . esc_html(ucfirst($validation['state'])) . '</strong> — ' . esc_html((string) ($validation['entry_count'] ?? 0)) . ' entries.</p>';
    foreach (array_merge((array) ($validation['errors'] ?? []), (array) ($validation['warnings'] ?? [])) as $message) { echo '<p>' . esc_html($message) . '</p>'; }
    echo '</div><p><a class="button" href="' . esc_url(admin_url('admin.php?page=cfm-views&version_id=' . $version_id . '&preview=1')) . '">Preview draft</a></p>';
    if (!empty($_GET['preview'])) { self::render_preview($version_id); }
    echo '<h3>Groups</h3><form method="post"><input type="hidden" name="cfm_views_action" value="save_group"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '">';
    wp_nonce_field('cfm_views_save_group', 'cfm_views_nonce');
    echo '<p><label>Key <input name="group_key" type="text" required pattern="[A-Za-z0-9_-]+"></label> <label>Label <input name="label" type="text" required></label> <label>Description <input name="description" type="text"></label> <label>Order <input name="display_order" type="number" min="0" value="0"></label> <button class="button">Add group</button></p></form>';
    echo '<ul>'; foreach ((array) $groups as $group) { echo '<li><strong>' . esc_html($group->label) . '</strong> <code>' . esc_html($group->group_key) . '</code> (order ' . esc_html((string) $group->display_order) . ')</li>'; } if (!$groups) { echo '<li>No groups added; entries remain ungrouped.</li>'; } echo '</ul>';
    echo '<form method="post" class="cfm-views-entry-form">';
    wp_nonce_field('cfm_views_save_entry', 'cfm_views_nonce');
    echo '<input type="hidden" name="cfm_views_action" value="save_entry"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '">';
    echo '<table class="form-table"><tr><th><label for="cfm-view-term">Core Term</label></th><td><select id="cfm-view-term" name="term_uuid" required><option value="">Select a canonical term</option>';
    foreach ($terms_by_framework as $slug => $terms) {
      $framework = $frameworks[array_search($slug, array_map(static function ($item) { return (string) ($item->slug ?? ''); }, (array) $frameworks), true)] ?? null;
      echo '<optgroup label="' . esc_attr($framework ? ($framework->name ?? $slug) : $slug) . '">';
      foreach ((array) $terms as $term) {
        $uuid = (string) ($term->term_uuid ?? '');
        if ($uuid === '') { continue; }
        $label = (string) ($term->label ?? $term->name ?? $uuid);
        $depth = max(0, (int) ($term->depth ?? 0));
        echo '<option value="' . esc_attr($uuid) . '">' . esc_html(str_repeat('— ', $depth) . $label) . '</option>';
      }
      echo '</optgroup>';
    }
    echo '</select></td></tr><tr><th><label for="cfm-view-group">Group</label></th><td><select id="cfm-view-group" name="group_id"><option value="0">Ungrouped</option>'; foreach ((array) $groups as $group) { echo '<option value="' . esc_attr((string) $group->id) . '">' . esc_html($group->label) . '</option>'; } echo '</select></td></tr><tr><th><label for="cfm-view-inclusion">Inclusion</label></th><td><select id="cfm-view-inclusion" name="inclusion"><option value="include">Include</option><option value="exclude">Exclude</option></select></td></tr><tr><th><label for="cfm-view-label">Display label</label></th><td><input id="cfm-view-label" name="display_label" type="text" class="regular-text"><p class="description">Optional presentation label; blank keeps the canonical term label.</p></td></tr><tr><th><label for="cfm-view-order">Display order</label></th><td><input id="cfm-view-order" name="display_order" type="number" min="0" value="0"></td></tr><tr><th>Descendants</th><td><label><input name="include_descendants" type="checkbox" value="1"> Include descendant terms</label></td></tr></table><p><button class="button button-primary">Save term to draft</button></p></form>';
    echo '<h3>Draft entries</h3><table class="widefat striped"><thead><tr><th>Framework</th><th>Term UUID</th><th>Group</th><th>Inclusion</th><th>Label</th><th>Order</th><th>Descendants</th><th>Action</th></tr></thead><tbody>';
    foreach ((array) $entries as $entry) {
      $group_label = 'Ungrouped'; foreach ((array) $groups as $group) { if ((int) $group->id === (int) $entry->group_id) { $group_label = $group->label; break; } }
      echo '<tr><td>' . esc_html((string) $entry->core_terms_framework) . '</td><td><code>' . esc_html((string) $entry->term_uuid) . '</code></td><td>' . esc_html($group_label) . '</td><td>' . esc_html((string) $entry->inclusion) . '</td><td>' . esc_html((string) ($entry->display_label ?: 'Canonical label')) . '</td><td>' . esc_html((string) $entry->display_order) . '</td><td>' . (!empty($entry->include_descendants) ? 'Yes' : 'No') . '</td><td><form method="post">';
      wp_nonce_field('cfm_views_delete_entry', 'cfm_views_nonce');
      echo '<input type="hidden" name="cfm_views_action" value="delete_entry"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '"><input type="hidden" name="entry_id" value="' . esc_attr((string) $entry->id) . '"><button class="button-link-delete" type="submit">Remove</button></form></td></tr>';
    }
    if (!$entries) { echo '<tr><td colspan="8">No terms added to this draft yet.</td></tr>'; }
    echo '</tbody></table>';
  }

  private static function render_preview(int $version_id): void
  {
    $preview = CFM_Views_Repository::preview_version($version_id);
    if (is_wp_error($preview)) { echo '<div class="notice notice-error"><p>' . esc_html($preview->get_error_message()) . '</p></div>'; return; }
    echo '<h3>Resolved draft preview</h3><ol>'; foreach ((array) ($preview['entries'] ?? []) as $entry) { echo '<li>' . esc_html($entry['label']) . ' <code>' . esc_html($entry['framework'] . ':' . $entry['term_uuid']) . '</code></li>'; } if (empty($preview['entries'])) { echo '<li>No included entries resolve for this draft.</li>'; } echo '</ol>';
  }
}
