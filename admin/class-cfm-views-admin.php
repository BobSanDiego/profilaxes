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
    if ($action === 'create_view') {
      $view_id = CFM_Views_Repository::create_view(['name' => wp_unslash($_POST['name'] ?? ''), 'description' => wp_unslash($_POST['description'] ?? '')]);
      if (!is_wp_error($view_id)) {
        CFM_Views_Repository::create_draft_version($view_id);
      }
    } elseif ($action === 'save_entry') {
      CFM_Views_Repository::save_entry(absint($_POST['version_id'] ?? 0), ['term_uuid' => wp_unslash($_POST['term_uuid'] ?? ''), 'core_terms_framework' => wp_unslash($_POST['framework'] ?? ''), 'display_label' => wp_unslash($_POST['display_label'] ?? ''), 'display_order' => absint($_POST['display_order'] ?? 0), 'include_descendants' => !empty($_POST['include_descendants'])]);
    } elseif ($action === 'publish') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::validate_version($version_id);
      CFM_Views_Repository::publish_version($version_id);
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=cfm-views'));
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
        echo '<form method="post" style="display:inline">';
        wp_nonce_field('cfm_views_publish', 'cfm_views_nonce');
        echo '<input type="hidden" name="cfm_views_action" value="publish"><input type="hidden" name="version_id" value="' . esc_attr((string) $draft->id) . '"><button class="button">Validate / publish draft</button></form>';
      }
      echo '</td></tr>';
    }
    if (!$views) { echo '<tr><td colspan="4">No Views exist yet.</td></tr>'; }
    echo '</tbody></table></div>';
  }
}
