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
      $term_parts = explode('|', sanitize_text_field(wp_unslash($_POST['term_uuid'] ?? '')), 2);
      $framework = sanitize_key((string) ($term_parts[0] ?? ''));
      $term_uuid = sanitize_text_field((string) ($term_parts[1] ?? ''));
      CFM_Views_Repository::save_entry($version_id, ['term_uuid' => $term_uuid, 'core_terms_framework' => $framework, 'group_id' => absint($_POST['group_id'] ?? 0), 'inclusion' => sanitize_key(wp_unslash($_POST['inclusion'] ?? 'include')), 'display_label' => wp_unslash($_POST['display_label'] ?? ''), 'display_order' => absint($_POST['display_order'] ?? 0), 'include_descendants' => !empty($_POST['include_descendants'])]);
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'add_selected') {
      $version_id = absint($_POST['version_id'] ?? 0);
      $result = CFM_Views_Repository::add_selected_entries($version_id, (array) ($_POST['term_uuids'] ?? []));
      if (!is_wp_error($result)) {
        $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id . '&batch_added=' . absint($result['added']) . '&batch_skipped=' . absint($result['skipped']));
      }
    } elseif ($action === 'save_group') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::save_group($version_id, ['group_key' => wp_unslash($_POST['group_key'] ?? ''), 'label' => wp_unslash($_POST['label'] ?? ''), 'description' => wp_unslash($_POST['description'] ?? ''), 'display_order' => absint($_POST['display_order'] ?? 0)]);
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'delete_entry') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::delete_entry($version_id, absint($_POST['entry_id'] ?? 0));
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'move_entry') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::move_entry($version_id, absint($_POST['entry_id'] ?? 0), sanitize_key(wp_unslash($_POST['direction'] ?? '')));
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'reorder_entry') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::reorder_entry($version_id, absint($_POST['entry_id'] ?? 0), absint($_POST['target_index'] ?? 0));
      $redirect_url = admin_url('admin.php?page=cfm-views&version_id=' . $version_id);
    } elseif ($action === 'reorder_group') {
      $version_id = absint($_POST['version_id'] ?? 0);
      CFM_Views_Repository::reorder_group($version_id, absint($_POST['group_id'] ?? 0), absint($_POST['target_index'] ?? 0));
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
    $version_id = absint($_GET['version_id'] ?? 0);
    echo '<div class="wrap"><h1>Durable Views</h1><p>Platform-owned presentation models referencing canonical Core Terms UUIDs.</p>';
    if ($version_id) {
      $editing_version = CFM_Views_Repository::get_version($version_id);
      $editing_view = $editing_version ? CFM_Views_Repository::get_view((int) $editing_version->view_id) : null;
      echo '<div class="cfm-views-editing-context"><div><span class="description">Editing current View</span><h2>' . esc_html($editing_view ? $editing_view->name : 'View') . '</h2><p>Draft version ' . esc_html((string) ($editing_version->version_number ?? '')) . ' · Status: ' . esc_html((string) ($editing_version->status ?? '')) . '</p></div><a class="button" href="' . esc_url(admin_url('admin.php?page=cfm-views')) . '">Back to Views</a></div>';
    } else {
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
    }
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
    $represented_terms = [];
    foreach ((array) $entries as $entry) {
      $represented_terms[(string) $entry->core_terms_framework . '|' . (string) $entry->term_uuid] = true;
    }
    $validation = CFM_Views_Repository::validate_version($version_id);
    echo '<hr><h2>Current View</h2><p class="cfm-views-workflow">Browse → Select → Add → Organize → Preview → Publish</p><p>Draft-only workspace. Core Terms Library entries are referenced by UUID; they are not copied or edited here.</p>';
    if (isset($_GET['batch_added'])) { echo '<div class="notice notice-success"><p>Added ' . esc_html((string) absint($_GET['batch_added'])) . ' selected term' . ((int) $_GET['batch_added'] === 1 ? '' : 's') . ' to the draft.' . ((int) ($_GET['batch_skipped'] ?? 0) > 0 ? ' ' . esc_html((string) absint($_GET['batch_skipped'])) . ' duplicate or invalid selection(s) were skipped.' : '') . '</p></div>'; }
    echo '<div class="notice ' . ($validation['state'] === 'invalid' ? 'notice-error' : ($validation['state'] === 'warning' ? 'notice-warning' : 'notice-success')) . '"><p><strong>Validation: ' . esc_html(ucfirst($validation['state'])) . '</strong> — ' . esc_html((string) ($validation['entry_count'] ?? 0)) . ' entries.</p>';
    foreach (array_merge((array) ($validation['errors'] ?? []), (array) ($validation['warnings'] ?? [])) as $message) { echo '<p>' . esc_html($message) . '</p>'; }
    echo '</div><p><a class="button" href="' . esc_url(admin_url('admin.php?page=cfm-views&version_id=' . $version_id . '&preview=1')) . '">Preview draft</a></p>';
    if (!empty($_GET['preview'])) { self::render_preview($version_id); }
    echo '<div class="cfm-views-workbench" data-cfm-views-workbench><section class="cfm-views-source" aria-labelledby="cfm-views-source-title"><h3 id="cfm-views-source-title">Core Terms Library <span class="description">(Read-only)</span></h3><p class="description">Source taxonomy owned by Core Terms. Select terms here to reference them in the Current View; this library is never edited by Views.</p>';
    self::render_canonical_browser($frameworks, $terms_by_framework, $represented_terms);
    echo '</section><section class="cfm-views-composition" aria-labelledby="cfm-views-composition-title"><h3 id="cfm-views-composition-title">Current View <span class="description">(Editable draft)</span></h3><div class="cfm-views-current-tree" role="tree" aria-label="Current View presentation tree">';
    echo '<div class="cfm-views-tree-toolbar" role="toolbar" aria-label="Current View tree controls"><button type="button" class="button button-small" disabled>Expand All</button><button type="button" class="button button-small" disabled>Collapse All</button><button type="button" class="button button-small" disabled>Select All</button><button type="button" class="button button-small" disabled>Clear Selection</button><button type="button" class="button button-small" disabled>Remove Selected</button><span class="description">Advanced tree actions are deferred.</span></div><div class="cfm-views-canvas-intro"><strong>Browse → Select → Add → Organize → Preview → Publish</strong><p class="description">Presentation Containers are the user-facing organization for entries. Published versions remain immutable.</p></div><h4>Presentation Containers <span class="description">(current flat group implementation)</span></h4><form method="post"><input type="hidden" name="cfm_views_action" value="save_group"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '">';
    wp_nonce_field('cfm_views_save_group', 'cfm_views_nonce');
    echo '<p><label>Key <input name="group_key" type="text" required pattern="[A-Za-z0-9_-]+"></label> <label>Label <input name="label" type="text" required></label> <label>Description <input name="description" type="text"></label> <label>Order <input name="display_order" type="number" min="0" value="0"></label> <button class="button">Add group</button></p></form>';
    $entry_groups = [];
    foreach ((array) $entries as $entry) { $entry_groups[(int) ($entry->group_id ?: 0)][] = $entry; }
    $group_map = [];
    foreach ((array) $groups as $group) { $group_map[(int) $group->id] = $group; }
    $rendered_group_ids = [];
    foreach (array_merge(array_keys($group_map), [0]) as $group_position => $group_id) {
      if ($group_id !== 0 && !isset($group_map[$group_id])) { continue; }
      $group = $group_id ? $group_map[$group_id] : null;
      $rendered_group_ids[] = $group_id;
      $group_entries = $entry_groups[$group_id] ?? [];
      echo '<article class="cfm-views-group-card" role="treeitem" aria-level="1" data-cfm-views-group data-group-id="' . esc_attr((string) $group_id) . '" draggable="' . ($group ? 'true' : 'false') . '"><header><div>' . ($group ? '<span class="cfm-views-drag-handle" title="Drag presentation container" aria-hidden="true">⠿</span>' : '') . '<h5>' . esc_html($group ? $group->label : 'Ungrouped entries') . '</h5>' . ($group && $group->description ? '<p>' . esc_html($group->description) . '</p>' : '') . '</div><button type="button" class="button-link cfm-views-container-toggle" data-cfm-views-container-toggle aria-expanded="false" aria-label="Expand or collapse presentation container">+</button><span class="description">' . esc_html((string) count($group_entries)) . ' entr' . (count($group_entries) === 1 ? 'y' : 'ies') . '</span>' . ($group ? '<form method="post" class="cfm-views-reorder-form" data-cfm-views-reorder-group><input type="hidden" name="cfm_views_action" value="reorder_group"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '"><input type="hidden" name="group_id" value="' . esc_attr((string) $group_id) . '"><input type="hidden" name="target_index" value="0" data-cfm-views-target-index>' . wp_nonce_field('cfm_views_reorder_group', 'cfm_views_nonce', true, false) . '</form>' : '') . '</header><div class="cfm-views-group-entries" role="group" hidden>';
      if (!$group_entries) { echo '<p class="cfm-views-empty">No entries here yet. Use <strong>Add to Draft</strong> in the canonical browser, then choose this group before saving.</p>'; }
      foreach ($group_entries as $entry) {
        $canonical_label = (string) $entry->term_uuid;
        foreach ((array) ($terms_by_framework[$entry->core_terms_framework] ?? []) as $term) { if ((string) ($term->term_uuid ?? '') === (string) $entry->term_uuid) { $canonical_label = (string) ($term->label ?? $canonical_label); break; } }
        echo '<article class="cfm-views-composition-item" role="treeitem" aria-level="2" data-cfm-views-entry data-entry-id="' . esc_attr((string) $entry->id) . '" draggable="true"><div class="cfm-views-item-heading"><div><span class="cfm-views-drag-handle" title="Drag entry" aria-hidden="true">⠿</span><strong>' . esc_html($canonical_label) . '</strong><span class="description">' . esc_html((string) $entry->core_terms_framework) . '</span></div><span class="cfm-views-state cfm-views-state-' . esc_attr($entry->inclusion) . '">' . esc_html(ucfirst((string) $entry->inclusion)) . '</span></div><form method="post" class="cfm-views-item-form">';
        wp_nonce_field('cfm_views_save_entry', 'cfm_views_nonce');
        echo '<input type="hidden" name="cfm_views_action" value="save_entry"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '"><input type="hidden" name="entry_id" value="' . esc_attr((string) $entry->id) . '"><input type="hidden" name="term_uuid" value="' . esc_attr((string) $entry->core_terms_framework . '|' . (string) $entry->term_uuid) . '"><input type="hidden" name="group_id" value="' . esc_attr((string) ($entry->group_id ?: 0)) . '"><label>Display label <input name="display_label" type="text" value="' . esc_attr((string) $entry->display_label) . '" placeholder="Canonical label"></label><label>Inclusion <select name="inclusion"><option value="include"' . selected($entry->inclusion, 'include', false) . '>Include</option><option value="exclude"' . selected($entry->inclusion, 'exclude', false) . '>Exclude</option></select></label><label class="cfm-views-check"><input name="include_descendants" type="checkbox" value="1"' . checked(!empty($entry->include_descendants), true, false) . '> Include descendants</label><button class="button button-primary">Save changes</button></form><div class="cfm-views-item-actions"><span class="description">Order ' . esc_html((string) $entry->display_order) . '</span>';
        foreach (['up' => 'Move entry earlier', 'down' => 'Move entry later'] as $direction => $label) { echo '<form method="post" class="cfm-views-inline-form cfm-views-keyboard-ordering"><input type="hidden" name="cfm_views_action" value="move_entry"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '"><input type="hidden" name="entry_id" value="' . esc_attr((string) $entry->id) . '"><input type="hidden" name="direction" value="' . esc_attr($direction) . '">' . wp_nonce_field('cfm_views_move_entry', 'cfm_views_nonce', true, false) . '<button class="button button-small" aria-label="' . esc_attr($label) . '">' . esc_html($label) . '</button></form>'; }
        echo '<form method="post" class="cfm-views-reorder-form" data-cfm-views-reorder-entry><input type="hidden" name="cfm_views_action" value="reorder_entry"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '"><input type="hidden" name="entry_id" value="' . esc_attr((string) $entry->id) . '"><input type="hidden" name="target_index" value="0" data-cfm-views-target-index>' . wp_nonce_field('cfm_views_reorder_entry', 'cfm_views_nonce', true, false) . '</form>';
        echo '<form method="post" class="cfm-views-inline-form"><input type="hidden" name="cfm_views_action" value="delete_entry"><input type="hidden" name="version_id" value="' . esc_attr((string) $version_id) . '"><input type="hidden" name="entry_id" value="' . esc_attr((string) $entry->id) . '">' . wp_nonce_field('cfm_views_delete_entry', 'cfm_views_nonce', true, false) . '<button class="button-link-delete">Remove</button></form></div></article>';
      }
    echo '</div></article>';
    }
    echo '</div><details id="cfm-views-add-entry" class="cfm-views-add-target"><summary>Alternate manual entry path</summary><p class="description">Use this compatibility path when you already know the canonical term. The Core Terms Library above is the recommended workflow.</p><form method="post" class="cfm-views-entry-form">';
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
        echo '<option value="' . esc_attr($slug . '|' . $uuid) . '">' . esc_html(str_repeat('— ', $depth) . $label) . '</option>';
      }
      echo '</optgroup>';
    }
    echo '</select></td></tr><tr><th><label for="cfm-view-group">Group</label></th><td><select id="cfm-view-group" name="group_id"><option value="0">Ungrouped</option>'; foreach ((array) $groups as $group) { echo '<option value="' . esc_attr((string) $group->id) . '">' . esc_html($group->label) . '</option>'; } echo '</select></td></tr><tr><th><label for="cfm-view-inclusion">Inclusion</label></th><td><select id="cfm-view-inclusion" name="inclusion"><option value="include">Include</option><option value="exclude">Exclude</option></select></td></tr><tr><th><label for="cfm-view-label">Display label</label></th><td><input id="cfm-view-label" name="display_label" type="text" class="regular-text"><p class="description">Optional presentation label; blank keeps the canonical term label.</p></td></tr><tr><th><label for="cfm-view-order">Display order</label></th><td><input id="cfm-view-order" name="display_order" type="number" min="0" value="0"></td></tr><tr><th>Descendants</th><td><label><input name="include_descendants" type="checkbox" value="1"> Include descendant terms</label></td></tr></table><p><button class="button button-primary">Save term to draft</button></p></form></details></section></div>';
    self::render_workbench_assets();
  }

  private static function render_canonical_browser(array $frameworks, array $terms_by_framework, array $represented_terms = []): void
  {
    $selected_slug = sanitize_key(wp_unslash($_GET['cfm_framework'] ?? ''));
    if ($selected_slug === '' && !empty($frameworks[0]->slug)) {
      $selected_slug = sanitize_key((string) $frameworks[0]->slug);
    }
    echo '<div class="cfm-views-discovery-controls"><label for="cfm-views-framework">Framework</label><select id="cfm-views-framework" data-cfm-views-framework>'; 
    foreach ($frameworks as $framework) {
      $slug = sanitize_key((string) ($framework->slug ?? ''));
      if ($slug === '') { continue; }
      echo '<option value="' . esc_attr($slug) . '"' . selected($selected_slug, $slug, false) . '>' . esc_html((string) ($framework->name ?? $slug)) . '</option>';
    }
    echo '</select><label for="cfm-views-term-search">Search terms</label><input id="cfm-views-term-search" type="search" placeholder="Search canonical terms" data-cfm-views-search><span class="description" data-cfm-views-result-count aria-live="polite"></span><span class="description" data-cfm-views-selected-count aria-live="polite">0 selected</span><button type="button" class="button button-small" data-cfm-views-select-visible>Select All Visible</button><button type="button" class="button button-small" data-cfm-views-clear-selection>Clear Selection</button></div><form method="post" class="cfm-views-batch-form">';
    wp_nonce_field('cfm_views_add_selected', 'cfm_views_nonce');
    echo '<input type="hidden" name="cfm_views_action" value="add_selected"><input type="hidden" name="version_id" value="' . esc_attr((string) absint($_GET['version_id'] ?? 0)) . '">';
    foreach ($terms_by_framework as $slug => $terms) {
      echo '<div class="cfm-views-term-tree" data-cfm-views-tree data-framework="' . esc_attr($slug) . '"' . ($slug === $selected_slug ? '' : ' hidden') . ' role="tree">';
      foreach ((array) $terms as $term) {
        $uuid = sanitize_text_field((string) ($term->term_uuid ?? ''));
        $parent = sanitize_text_field((string) ($term->parent_uuid ?? ''));
        $label = (string) ($term->label ?? $term->name ?? $uuid);
        $depth = max(0, (int) ($term->depth ?? 0));
        $has_children = false;
        foreach ((array) $terms as $candidate) {
          if ((string) ($candidate->parent_uuid ?? '') === $uuid) { $has_children = true; break; }
        }
        $represented = isset($represented_terms[$slug . '|' . $uuid]);
        echo '<div class="cfm-views-term-row' . ($represented ? ' cfm-views-term-represented' : '') . '" data-cfm-views-term data-cfm-views-hidden="' . ($depth > 0 ? 'true' : 'false') . '" data-represented="' . ($represented ? 'true' : 'false') . '" data-label="' . esc_attr(strtolower($label)) . '" data-uuid="' . esc_attr($uuid) . '" data-parent="' . esc_attr($parent) . '" data-depth="' . esc_attr((string) $depth) . '" role="treeitem" aria-level="' . esc_attr((string) ($depth + 1)) . '" aria-label="' . esc_attr($label) . '">';
        if ($has_children) {
          echo '<button type="button" class="button-link cfm-views-toggle" data-cfm-views-toggle aria-expanded="false" aria-label="Expand or collapse ' . esc_attr($label) . '">+</button>';
        } else { echo '<span class="cfm-views-toggle-spacer" aria-hidden="true"></span>'; }
        echo '<input type="checkbox" name="term_uuids[]" value="' . esc_attr($slug . '|' . $uuid) . '" data-cfm-views-select aria-label="Select ' . esc_attr($label) . '"><span class="cfm-views-term-label" style="--cfm-views-depth:' . esc_attr((string) $depth) . '">' . esc_html($label) . '</span><span class="description cfm-views-term-context">' . esc_html((string) ($term->short_label ?? '')) . '</span><span class="cfm-views-representation-state" title="' . esc_attr($represented ? 'Represented in this View' : 'Not represented in this View') . '">' . ($represented ? 'Represented' : 'Not represented') . '</span><button type="button" class="button button-small" data-cfm-views-add="' . esc_attr($slug . '|' . $uuid) . '">Add to Draft</button></div>';
      }
      echo '</div>';
    }
    echo '<p><button class="button button-primary" type="submit">Add Selected to Draft</button></p></form>';
    if (!$terms_by_framework) { echo '<p class="notice notice-warning">No active canonical terms are available.</p>'; }
  }

  private static function render_workbench_assets(): void
  {
    echo '<style id="cfm-views-ia-styles">.cfm-views-editing-context{position:relative;top:auto;z-index:auto;display:flex;justify-content:space-between;gap:16px;align-items:center;background:#f6f7f7;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin:16px 0}.cfm-views-editing-context h2{margin:2px 0}.cfm-views-editing-context p{margin:0}.cfm-views-add-target summary{cursor:pointer;font-weight:600}</style>';
    echo '<style id="cfm-views-interaction-styles">.cfm-views-workbench{grid-template-columns:minmax(260px,35fr) minmax(460px,65fr)}.cfm-views-composition-item,.cfm-views-group-card[draggable="true"]{transition:box-shadow .15s ease,transform .15s ease,background-color .15s ease}.cfm-views-composition-item:hover,.cfm-views-group-card[draggable="true"]:hover{box-shadow:0 2px 8px rgba(0,0,0,.12)}.cfm-views-drag-handle{display:inline-block;margin-right:6px;color:#646970;font-size:18px;cursor:grab}.cfm-views-dragging{opacity:.55;transform:scale(.99)}.cfm-views-drop-target{outline:2px dashed #2271b1;outline-offset:3px;background:#f0f6fc}.cfm-views-keyboard-ordering{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%);white-space:nowrap}.cfm-views-composition-item{position:relative}.cfm-views-reorder-form{display:none}@media (max-width:900px){.cfm-views-workbench{grid-template-columns:1fr}}</style>';
    echo '<style id="cfm-views-workbench-styles">.cfm-views-workbench{display:grid;grid-template-columns:minmax(260px,1fr) minmax(420px,2fr);gap:24px;align-items:start;margin-top:18px}.cfm-views-source,.cfm-views-composition{background:#fff;border:1px solid #dcdcde;padding:16px;min-width:0}.cfm-views-discovery-controls{display:grid;gap:6px;margin-bottom:12px}.cfm-views-discovery-controls select,.cfm-views-discovery-controls input{max-width:none}.cfm-views-term-tree{border:1px solid #dcdcde;max-height:520px;overflow:auto;padding:8px}.cfm-views-term-row{display:grid;grid-template-columns:24px 18px minmax(0,1fr) auto auto;gap:8px;align-items:center;min-height:36px;padding:4px 0;border-bottom:1px solid #f0f0f1}.cfm-views-term-row:last-child{border-bottom:0}.cfm-views-term-label{padding-left:calc(var(--cfm-views-depth) * 18px);font-weight:500;min-width:0}.cfm-views-term-context{white-space:nowrap}.cfm-views-toggle{font-size:18px;text-decoration:none}.cfm-views-toggle-spacer{width:24px}.cfm-views-term-row[data-cfm-views-hidden="true"]{display:none}.cfm-views-term-row[data-cfm-views-match="false"]{display:none}.cfm-views-canvas-intro{background:#f6f7f7;border-left:4px solid #2271b1;padding:12px 14px;margin:12px 0}.cfm-views-group-card{border:1px solid #c3c4c7;border-radius:4px;margin:14px 0;background:#fff}.cfm-views-group-card>header{display:flex;justify-content:space-between;gap:12px;align-items:start;background:#f6f7f7;padding:12px 14px;border-bottom:1px solid #dcdcde}.cfm-views-group-card h5,.cfm-views-add-target h5{font-size:15px;margin:0}.cfm-views-group-card header p{margin:4px 0 0}.cfm-views-group-entries{padding:10px}.cfm-views-composition-item{border:1px solid #dcdcde;border-radius:4px;padding:12px;margin:8px 0}.cfm-views-item-heading{display:flex;justify-content:space-between;gap:12px;align-items:start}.cfm-views-item-heading .description{display:block;margin-top:3px}.cfm-views-state{font-size:11px;text-transform:uppercase;font-weight:600}.cfm-views-state-exclude{color:#b32d2e}.cfm-views-state-include{color:#008a20}.cfm-views-item-form{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-top:10px}.cfm-views-item-form label{display:grid;gap:4px}.cfm-views-item-form input[type=text],.cfm-views-item-form select{min-width:150px}.cfm-views-check{display:flex!important;align-items:center;gap:4px}.cfm-views-item-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;padding-top:8px;border-top:1px solid #f0f0f1}.cfm-views-inline-form{display:inline}.cfm-views-empty{padding:12px;background:#f6f7f7;margin:0}.cfm-views-add-target{border:1px dashed #8c8f94;padding:14px;margin-top:16px}.cfm-views-add-target .form-table{margin-top:0}@media (max-width:900px){.cfm-views-workbench{grid-template-columns:1fr}.cfm-views-term-tree{max-height:360px}.cfm-views-term-row{grid-template-columns:24px 18px minmax(0,1fr) auto}.cfm-views-term-context{display:none}}@media (max-width:480px){.cfm-views-term-row{grid-template-columns:24px 1fr}.cfm-views-term-row [data-cfm-views-add]{grid-column:2;justify-self:start}.cfm-views-item-form{display:grid;grid-template-columns:1fr}.cfm-views-item-form input[type=text],.cfm-views-item-form select{width:100%}}</style>';
    echo '<style id="cfm-views-dual-tree-styles">.cfm-views-tree-toolbar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;margin:8px 0 12px}.cfm-views-tree-toolbar .description{flex-basis:100%}.cfm-views-term-row{grid-template-columns:24px 18px minmax(80px,1fr) auto auto auto}.cfm-views-representation-state{font-size:11px;color:#646970;white-space:nowrap}.cfm-views-term-represented{background:#f0f6fc;box-shadow:inset 3px 0 #2271b1}.cfm-views-term-represented .cfm-views-representation-state{color:#135e96;font-weight:600}.cfm-views-current-tree>.cfm-views-group-card{margin-top:10px}.cfm-views-container-toggle{font-size:18px;text-decoration:none;line-height:1;border:1px solid #8c8f94;border-radius:3px;padding:0 6px}.cfm-views-group-entries[hidden]{display:none}@media (max-width:1440px){.cfm-views-term-context{display:none}}@media (max-width:1200px){.cfm-views-term-row{grid-template-columns:24px 18px minmax(0,1fr) auto}.cfm-views-representation-state{grid-column:3;white-space:normal}.cfm-views-term-row [data-cfm-views-add]{grid-column:3;justify-self:start}}@media (max-width:900px){.cfm-views-representation-state{display:none}}</style>';
    echo '<script id="cfm-views-workbench-script">(function(){"use strict";var root=document.querySelector("[data-cfm-views-workbench]");if(!root){return;}var framework=root.querySelector("[data-cfm-views-framework]"),search=root.querySelector("[data-cfm-views-search]"),count=root.querySelector("[data-cfm-views-result-count]"),selectedCount=root.querySelector("[data-cfm-views-selected-count]"),trees=[].slice.call(root.querySelectorAll("[data-cfm-views-tree]"));function activeTree(){return trees.find(function(tree){return tree.dataset.framework===framework.value;});}function refreshSelection(){var selected=root.querySelectorAll("[data-cfm-views-select]:checked").length;if(selectedCount){selectedCount.textContent=selected+" selected";}}function refresh(){var tree=activeTree(),needle=(search.value||"").toLowerCase().trim(),visible=0;if(!tree){return;}trees.forEach(function(item){item.hidden=item!==tree;});[].slice.call(tree.querySelectorAll("[data-cfm-views-term]")).forEach(function(row){var match=!needle||row.dataset.label.indexOf(needle)!==-1;row.dataset.cfmViewsMatch=match?"true":"false";if(match){visible++;}if(!row.hasAttribute("data-cfm-views-hidden")){row.dataset.cfmViewsHidden="false";}});if(count){count.textContent=visible+" canonical term"+(visible===1?"":"s");}}framework.addEventListener("change",function(){search.value="";refresh();});search.addEventListener("input",refresh);root.addEventListener("change",function(event){if(event.target.matches("[data-cfm-views-select]")){refreshSelection();}});root.querySelector("[data-cfm-views-select-visible]").addEventListener("click",function(){var tree=activeTree();if(tree){tree.querySelectorAll("[data-cfm-views-term]").forEach(function(row){if(row.dataset.cfmViewsHidden!=="true"&&row.dataset.cfmViewsMatch!=="false"){var box=row.querySelector("[data-cfm-views-select]");if(box){box.checked=true;}}});refreshSelection();}});root.querySelector("[data-cfm-views-clear-selection]").addEventListener("click",function(){root.querySelectorAll("[data-cfm-views-select]").forEach(function(box){box.checked=false;});refreshSelection();});root.addEventListener("click",function(event){var add=event.target.closest("[data-cfm-views-add]");if(add){var select=document.querySelector("#cfm-view-term");if(select){select.value=add.dataset.cfmViewsAdd;select.dispatchEvent(new Event("change",{bubbles:true}));select.focus();}}var toggle=event.target.closest("[data-cfm-views-toggle]");if(toggle){var row=toggle.closest("[data-cfm-views-term]"),tree=row.closest("[data-cfm-views-tree]"),collapsed=toggle.getAttribute("aria-expanded")==="true";toggle.setAttribute("aria-expanded",collapsed?"false":"true");toggle.textContent=collapsed?"+":"−";var hide=collapsed;[].slice.call(tree.querySelectorAll("[data-cfm-views-term]")).forEach(function(candidate){if(candidate===row){return;}var parent=candidate.dataset.parent;var ancestor=row.dataset.uuid;while(parent){if(parent===ancestor){candidate.dataset.cfmViewsHidden=hide?"true":"false";if(hide){return;}ancestor=parent;var parentRow=tree.querySelector("[data-uuid=\""+CSS.escape(parent)+"\"]");parent=parentRow?parentRow.dataset.parent:"";break;}var parentRow=tree.querySelector("[data-uuid=\""+CSS.escape(parent)+"\"]");parent=parentRow?parentRow.dataset.parent:"";}});}});refresh();refreshSelection();})();</script>';
    echo '<script id="cfm-views-drag-script">(function(){"use strict";var root=document.querySelector("[data-cfm-views-workbench]");if(!root){return;}var dragged=null;function siblings(item,selector){return [].filter.call(item.parentElement.querySelectorAll(selector),function(candidate){return candidate.parentElement===item.parentElement;});}function clear(){root.querySelectorAll(".cfm-views-drop-target,.cfm-views-dragging").forEach(function(item){item.classList.remove("cfm-views-drop-target","cfm-views-dragging");});}root.addEventListener("dragstart",function(event){var item=event.target.closest("[data-cfm-views-entry],[data-cfm-views-group]");if(!item||item.dataset.groupId==="0"){return;}dragged=item;item.classList.add("cfm-views-dragging");event.dataTransfer.effectAllowed="move";event.dataTransfer.setData("text/plain",item.dataset.entryId||item.dataset.groupId);});root.addEventListener("dragover",function(event){var target=event.target.closest("[data-cfm-views-entry],[data-cfm-views-group]");if(!dragged||!target||target===dragged){return;}if(dragged.matches("[data-cfm-views-entry]")!==target.matches("[data-cfm-views-entry]")){return;}if(dragged.matches("[data-cfm-views-entry]")&&dragged.closest("[data-cfm-views-group]")!==target.closest("[data-cfm-views-group]")){return;}event.preventDefault();clear();target.classList.add("cfm-views-drop-target");});root.addEventListener("drop",function(event){var target=event.target.closest("[data-cfm-views-entry],[data-cfm-views-group]");if(!dragged||!target||target===dragged){return;}event.preventDefault();var selector=dragged.matches("[data-cfm-views-entry]")?"[data-cfm-views-entry]":"[data-cfm-views-group]",items=siblings(target,selector),index=items.indexOf(target),box=dragged.querySelector("[data-cfm-views-target-index]"),form=dragged.querySelector("[data-cfm-views-reorder-entry], [data-cfm-views-reorder-group]");if(box&&form){box.value=index;form.submit();}clear();dragged=null;});root.addEventListener("dragend",function(){clear();dragged=null;});})();</script>';
    echo '<script id="cfm-views-container-script">(function(){"use strict";var root=document.querySelector("[data-cfm-views-workbench]");if(!root){return;}root.addEventListener("click",function(event){var toggle=event.target.closest("[data-cfm-views-container-toggle]");if(!toggle){return;}var group=toggle.closest("[data-cfm-views-group]"),entries=group&&group.querySelector(".cfm-views-group-entries"),expanded=toggle.getAttribute("aria-expanded")==="true";if(!entries){return;}toggle.setAttribute("aria-expanded",expanded?"false":"true");toggle.textContent=expanded?"+":"−";entries.hidden=expanded;});})();</script>';
  }

  private static function render_preview(int $version_id): void
  {
    $preview = CFM_Views_Repository::preview_version($version_id);
    if (is_wp_error($preview)) { echo '<div class="notice notice-error"><p>' . esc_html($preview->get_error_message()) . '</p></div>'; return; }
    echo '<h3>Resolved draft preview</h3><ol>'; foreach ((array) ($preview['entries'] ?? []) as $entry) { echo '<li>' . esc_html($entry['label']) . ' <code>' . esc_html($entry['framework'] . ':' . $entry['term_uuid']) . '</code></li>'; } if (empty($preview['entries'])) { echo '<li>No included entries resolve for this draft.</li>'; } echo '</ol>';
  }
}
