<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Admin
{
  public static function init(): void
  {
    add_action('admin_menu', [__CLASS__, 'register_menu']);
    add_action('admin_init', [__CLASS__, 'handle_actions']);
  }

  public static function register_menu(): void
  {
    add_menu_page(
      'Profiles',
      'Profilaxes',
      'manage_options',
      'cfm-frameworks',
      [__CLASS__, 'render_frameworks_page'],
      'dashicons-groups',
      58
    );
  }

  public static function handle_actions(): void
  {
    if (!is_admin() || empty($_POST['cfm_action'])) {
      return;
    }

    $action = sanitize_key(wp_unslash($_POST['cfm_action']));

    if ($action === 'create_framework') {
      self::handle_create_framework();
      return;
    }

    if ($action === 'add_axis') {
      self::handle_add_axis();
      return;
    }

    if ($action === 'add_term') {
      self::handle_add_term();
      return;
    }

    if ($action === 'update_term') {
      self::handle_update_term();
      return;
    }


    if ($action === 'move_term') {
      self::handle_move_term();
      return;
    }

    if ($action === 'archive_term') {
      self::handle_archive_term();
      return;
    }

    if ($action === 'restore_version') {
      self::handle_restore_version();
      return;
    }

    if ($action === 'compile_active_version') {
      self::handle_compile_active_version();
      return;
    }
  }


  private static function save_active_tree_and_compile(int $framework_id, array $tree): array
  {
    $version_id = CFM_Framework_Repository::save_active_version_tree($framework_id, $tree);

    if ($version_id <= 0) {
      return [
        'success' => false,
        'version_id' => 0,
        'query_arg' => '&cfm_error=version_save_failed',
      ];
    }

    $result = CFM_Compiler::compile_version($framework_id, $version_id);

    if (empty($result['success'])) {
      return [
        'success' => false,
        'version_id' => $version_id,
        'query_arg' => '&cfm_error=compile_failed',
      ];
    }

    return [
      'success' => true,
      'version_id' => $version_id,
      'query_arg' => '&cfm_autocompiled=1',
    ];
  }


  private static function handle_compile_active_version(): void
  {
    check_admin_referer('cfm_compile_active_version', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);

    if ($framework_id <= 0) {
      wp_die('Missing framework ID.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $active_version = CFM_Framework_Repository::get_active_version($framework_id);

    if (!$active_version) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=no_active_version'
        )
      );
      exit;
    }

    $result = CFM_Compiler::compile_version($framework_id, (int) $active_version->id);

    $query_arg = !empty($result['success'])
      ? '&cfm_compiled=1'
      : '&cfm_error=compile_failed';

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . $query_arg
      )
    );
    exit;
  }

  private static function handle_create_framework(): void
  {
    check_admin_referer('cfm_create_framework', 'cfm_nonce');

    $name = sanitize_text_field(wp_unslash($_POST['cfm_name'] ?? ''));
    $slug = sanitize_title(wp_unslash($_POST['cfm_slug'] ?? ''));
    $description = sanitize_textarea_field(wp_unslash($_POST['cfm_description'] ?? ''));

    if ($name === '' || $slug === '') {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&cfm_error=missing_fields'));
      exit;
    }

    CFM_Framework_Repository::create_framework($name, $slug, $description);

    wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&cfm_created=1'));
    exit;
  }

  private static function handle_add_axis(): void
  {
    check_admin_referer('cfm_add_axis', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $axis_label = sanitize_text_field(wp_unslash($_POST['axis_label'] ?? ''));
    $axis_slug = sanitize_title(wp_unslash($_POST['axis_slug'] ?? ''));

    if ($framework_id <= 0 || $axis_label === '' || $axis_slug === '') {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_axis_fields'
        )
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $tree['children'][] = [
      'uuid' => wp_generate_uuid4(),
      'label' => $axis_label,
      'slug' => $axis_slug,
      'type' => 'axis',
      'description' => '',
      'children' => [],
    ];

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_axis_added=1'
          . $compile_result['query_arg']
      )
    );
    exit;
  }

  private static function handle_add_term(): void
  {
    check_admin_referer('cfm_add_term', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $parent_uuid = sanitize_text_field(wp_unslash($_POST['parent_uuid'] ?? ''));
    $term_label = sanitize_text_field(wp_unslash($_POST['term_label'] ?? ''));
    $term_slug = sanitize_title(wp_unslash($_POST['term_slug'] ?? ''));

    if ($framework_id <= 0 || $parent_uuid === '' || $term_label === '' || $term_slug === '') {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_term_fields'
        )
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $term = [
      'uuid' => wp_generate_uuid4(),
      'label' => $term_label,
      'slug' => $term_slug,
      'type' => 'term',
      'description' => '',
      'children' => [],
    ];

    $term_added = self::append_child_to_node_by_uuid($tree, $parent_uuid, $term);

    if (!$term_added) {
      wp_die('Parent not found.');
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_term_added=1'
          . '&cfm_parent_uuid=' . rawurlencode($parent_uuid)
          . $compile_result['query_arg']
          . '#cfm-add-term'
      )
    );
    exit;
  }

  private static function handle_update_term(): void
  {
    check_admin_referer('cfm_update_term', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $term_uuid = sanitize_text_field(wp_unslash($_POST['term_uuid'] ?? ''));
    $parent_uuid = sanitize_text_field(wp_unslash($_POST['parent_uuid'] ?? ''));
    $term_label = sanitize_text_field(wp_unslash($_POST['term_label'] ?? ''));
    $term_slug = sanitize_title(wp_unslash($_POST['term_slug'] ?? ''));

    if ($framework_id <= 0 || $term_uuid === '' || $parent_uuid === '' || $term_label === '' || $term_slug === '') {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&action=edit_term&framework_id=' . $framework_id . '&term_uuid=' . rawurlencode($term_uuid) . '&cfm_error=missing_edit_fields'));
      exit;
    }

    if ($term_uuid === $parent_uuid) {
      wp_die('A term cannot be assigned as its own parent.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);
    $parent_info = self::find_node_with_parent($tree, $parent_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (($term_info['node']['type'] ?? '') !== 'term') {
      wp_die('Only terms can be edited here.');
    }

    if (!$parent_info || empty($parent_info['node']) || !is_array($parent_info['node'])) {
      wp_die('Parent not found.');
    }

    if (self::node_contains_uuid($term_info['node'], $parent_uuid)) {
      wp_die('A term cannot be moved under itself or one of its descendants.');
    }

    $current_parent_uuid = '';
    if (!empty($term_info['parent']) && is_array($term_info['parent'])) {
      $current_parent_uuid = (string) ($term_info['parent']['uuid'] ?? '');
    }

    if ($current_parent_uuid === $parent_uuid) {
      $updated = self::update_node_label_slug_by_uuid($tree, $term_uuid, $term_label, $term_slug);

      if (!$updated) {
        wp_die('Unable to update term.');
      }
    } else {
      $removed_term = null;
      $removed = self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term);

      if (!$removed || !is_array($removed_term)) {
        wp_die('Unable to remove term from current parent.');
      }

      $removed_term['label'] = $term_label;
      $removed_term['slug'] = $term_slug;

      $added = self::append_child_to_node_by_uuid($tree, $parent_uuid, $removed_term);

      if (!$added) {
        wp_die('Unable to add term to selected parent.');
      }
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . $framework_id . '&cfm_term_updated=1&cfm_parent_uuid=' . rawurlencode($parent_uuid) . $compile_result['query_arg'] . '#cfm-existing-terms'));
    exit;
  }

  private static function handle_move_term(): void
  {
    check_admin_referer('cfm_move_term', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $term_uuid = sanitize_text_field(wp_unslash($_POST['term_uuid'] ?? ''));
    $new_parent_uuid = sanitize_text_field(wp_unslash($_POST['new_parent_uuid'] ?? ''));

    if ($framework_id <= 0 || $term_uuid === '' || $new_parent_uuid === '') {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_move_fields'
        )
      );
      exit;
    }

    if ($term_uuid === $new_parent_uuid) {
      wp_die('A term cannot be moved under itself.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);
    $new_parent_info = self::find_node_with_parent($tree, $new_parent_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (($term_info['node']['type'] ?? '') !== 'term') {
      wp_die('Only terms can be moved. Axes cannot be moved.');
    }

    if (!$new_parent_info || empty($new_parent_info['node']) || !is_array($new_parent_info['node'])) {
      wp_die('New parent not found.');
    }

    if (!in_array(($new_parent_info['node']['type'] ?? ''), ['axis', 'term'], true)) {
      wp_die('New parent must be an axis or term.');
    }

    if (self::node_contains_uuid($term_info['node'], $new_parent_uuid)) {
      wp_die('A term cannot be moved under one of its own descendants.');
    }

    $removed_term = null;
    $removed = self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term);

    if (!$removed || !is_array($removed_term)) {
      wp_die('Unable to remove term from current parent.');
    }

    $added = self::append_child_to_node_by_uuid($tree, $new_parent_uuid, $removed_term);

    if (!$added) {
      wp_die('Unable to add term to new parent.');
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_term_moved=1'
          . $compile_result['query_arg']
          . '#cfm-existing-terms'
      )
    );
    exit;
  }

  private static function handle_archive_term(): void
  {
    check_admin_referer('cfm_archive_term', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $term_uuid = sanitize_text_field(wp_unslash($_POST['term_uuid'] ?? ''));

    if ($framework_id <= 0 || $term_uuid === '') {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_archive_fields'
        )
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (($term_info['node']['type'] ?? '') !== 'term') {
      wp_die('Only terms can be archived. Axes cannot be archived.');
    }

    $removed_term = null;
    $removed = self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term);

    if (!$removed || !is_array($removed_term)) {
      wp_die('Unable to archive term.');
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_term_archived=1'
          . $compile_result['query_arg']
      )
    );
    exit;
  }

  private static function find_node_with_parent(array $node, string $uuid, ?array $parent = null): ?array
  {
    if (($node['uuid'] ?? '') === $uuid) {
      return [
        'node' => $node,
        'parent' => $parent,
      ];
    }

    $children = $node['children'] ?? [];

    if (empty($children) || !is_array($children)) {
      return null;
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      $found = self::find_node_with_parent($child, $uuid, $node);

      if ($found) {
        return $found;
      }
    }

    return null;
  }

  private static function update_node_label_slug_by_uuid(array &$node, string $uuid, string $label, string $slug): bool
  {
    if (($node['uuid'] ?? '') === $uuid) {
      $node['label'] = $label;
      $node['slug'] = $slug;
      return true;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$child) {
      if (!is_array($child)) {
        continue;
      }

      if (self::update_node_label_slug_by_uuid($child, $uuid, $label, $slug)) {
        unset($child);
        return true;
      }
    }

    unset($child);
    return false;
  }

  private static function node_contains_uuid(array $node, string $uuid): bool
  {
    if (($node['uuid'] ?? '') === $uuid) {
      return true;
    }

    $children = $node['children'] ?? [];

    if (empty($children) || !is_array($children)) {
      return false;
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      if (self::node_contains_uuid($child, $uuid)) {
        return true;
      }
    }

    return false;
  }

  private static function remove_child_node_by_uuid(array &$node, string $uuid, ?array &$removed_node = null): bool
  {
    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as $index => &$child) {
      if (!is_array($child)) {
        continue;
      }

      if (($child['uuid'] ?? '') === $uuid) {
        $removed_node = $child;
        array_splice($node['children'], (int) $index, 1);
        unset($child);
        return true;
      }

      if (self::remove_child_node_by_uuid($child, $uuid, $removed_node)) {
        unset($child);
        return true;
      }
    }

    unset($child);
    return false;
  }

  private static function append_child_to_node_by_uuid(array &$node, string $parent_uuid, array $child): bool
  {
    if (($node['uuid'] ?? '') === $parent_uuid) {
      if (!isset($node['children']) || !is_array($node['children'])) {
        $node['children'] = [];
      }

      $node['children'][] = $child;
      return true;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$candidate) {
      if (!is_array($candidate)) {
        continue;
      }

      if (self::append_child_to_node_by_uuid($candidate, $parent_uuid, $child)) {
        unset($candidate);
        return true;
      }
    }

    unset($candidate);
    return false;
  }

  private static function handle_restore_version(): void
  {
    check_admin_referer('cfm_restore_version', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $version_id = absint($_POST['version_id'] ?? 0);

    if ($framework_id <= 0 || $version_id <= 0) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=versions'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_restore_fields'
        )
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $version = CFM_Framework_Repository::get_version((int) $framework->id, $version_id);

    if (!$version) {
      wp_die('Version not found.');
    }

    $tree = json_decode((string) $version->tree_json, true);

    if (!is_array($tree)) {
      wp_die('Stored tree JSON could not be decoded. Restore aborted.');
    }

    $compile_result = self::save_active_tree_and_compile((int) $framework->id, $tree);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . (int) $framework->id
          . '&cfm_version_restored=1'
          . $compile_result['query_arg']
      )
    );
    exit;
  }

  private static function get_framework_tree(object $framework): array
  {
    $version = CFM_Framework_Repository::get_active_version((int) $framework->id);

    if ($version) {
      $tree = json_decode($version->tree_json, true);

      if (is_array($tree)) {
        return $tree;
      }
    }

    return [
      'uuid' => $framework->framework_uuid,
      'label' => $framework->name,
      'slug' => $framework->slug,
      'type' => 'framework',
      'description' => $framework->description,
      'children' => [],
    ];
  }

  private static function render_terms_recursive(array $terms, int $depth = 0, ?int $framework_id = null, bool $show_actions = false): void
  {
    if (empty($terms)) {
      return;
    }

    $margin_left = max(0, $depth * 18);

    echo '<ul style="margin: 0 0 0 ' . esc_attr((string) $margin_left) . 'px; padding-left: 18px;">';

    foreach ($terms as $term) {
      if (!is_array($term)) {
        continue;
      }

      $term_uuid = (string) ($term['uuid'] ?? '');

      echo '<li>';
      echo esc_html($term['label'] ?? '');
      echo ' <code>' . esc_html($term['slug'] ?? '') . '</code>';

      if ($show_actions && $framework_id && $term_uuid !== '' && (($term['type'] ?? '') === 'term')) {
        echo ' <span style="margin-left: 8px;">';
        echo '<a href="' . esc_url(self::edit_term_url($framework_id, $term_uuid)) . '">Edit</a>';
        echo ' | ';
        echo '<a href="' . esc_url(self::move_term_url($framework_id, $term_uuid)) . '">Move</a>';
        echo ' | ';
        echo '<a href="' . esc_url(self::archive_term_url($framework_id, $term_uuid)) . '">Archive</a>';
        echo '</span>';
      }

      $children = $term['children'] ?? [];
      if (!empty($children) && is_array($children)) {
        self::render_terms_recursive($children, $depth + 1, $framework_id, $show_actions);
      }

      echo '</li>';
    }

    echo '</ul>';
  }

  private static function render_parent_options(array $nodes, string $selected_uuid = '', int $depth = 0): void
  {
    foreach ($nodes as $node) {
      $uuid = $node['uuid'] ?? '';
      $selected = ($uuid === $selected_uuid) ? ' selected' : '';
      echo '<option value="' . esc_attr($uuid) . '"' . $selected . '>' . esc_html(str_repeat('— ', $depth) . ($node['label'] ?? '')) . '</option>';
      if (!empty($node['children']) && is_array($node['children'])) {
        self::render_parent_options($node['children'], $selected_uuid, $depth + 1);
      }
    }
  }

  private static function render_move_parent_options(array $nodes, array $moving_node, string $selected_uuid = '', int $depth = 0): void
  {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }

      $uuid = $node['uuid'] ?? '';
      $label = $node['label'] ?? '';
      $type = $node['type'] ?? '';

      if ($uuid !== '' && self::node_contains_uuid($moving_node, $uuid)) {
        continue;
      }

      if ($uuid !== '' && $label !== '' && in_array($type, ['axis', 'term'], true)) {
        $prefix = str_repeat('— ', max(0, $depth));
        echo '<option value="' . esc_attr($uuid) . '"' . selected($selected_uuid, $uuid, false) . '>';
        echo esc_html($prefix . $label);
        echo '</option>';
      }

      $children = $node['children'] ?? [];
      if (!empty($children) && is_array($children)) {
        self::render_move_parent_options($children, $moving_node, $selected_uuid, $depth + 1);
      }
    }
  }

  private static function count_descendant_terms(array $node): int
  {
    $children = $node['children'] ?? [];

    if (empty($children) || !is_array($children)) {
      return 0;
    }

    $count = 0;

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      if (($child['type'] ?? '') === 'term') {
        $count++;
      }

      $count += self::count_descendant_terms($child);
    }

    return $count;
  }

  public static function render_frameworks_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $action = isset($_GET['action'])
      ? sanitize_key(wp_unslash($_GET['action']))
      : '';

    if ($action === 'edit') {
      self::render_framework_edit_page();
      return;
    }

    if ($action === 'versions') {
      self::render_versions_page();
      return;
    }

    if ($action === 'view_version') {
      self::render_version_snapshot_page();
      return;
    }


    if ($action === 'edit_term') {
      self::render_edit_term_page();
      return;
    }

    if ($action === 'move_term') {
      self::render_move_term_page();
      return;
    }

    if ($action === 'archive_term') {
      self::render_archive_term_page();
      return;
    }

    if ($action === 'restore_version') {
      self::render_restore_version_page();
      return;
    }

    if ($action === 'compiled_debug') {
      self::render_compiled_debug_page();
      return;
    }

    global $wpdb;

    $frameworks = $wpdb->get_results(
      "SELECT *
             FROM {$wpdb->prefix}cfm_frameworks
             ORDER BY
               CASE WHEN slug = 'primary' THEN 0 ELSE 1 END,
               id ASC"
    );

    $framework = !empty($frameworks) ? $frameworks[0] : null;
    $framework_count = is_array($frameworks) ? count($frameworks) : 0;

    $axis_count = 0;
    $term_count = 0;
    $assigned_user_count = 0;
    $compiler_label = 'Not compiled';

    if ($framework) {
      $tree = self::get_framework_tree($framework);
      $counts = self::count_profile_tree_nodes($tree);
      $axis_count = $counts['axes'];
      $term_count = $counts['terms'];

      $assigned_user_count = (int) $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(DISTINCT user_id)
                 FROM {$wpdb->prefix}cfm_user_terms
                 WHERE framework_id = %d",
          (int) $framework->id
        )
      );

      $active_version = CFM_Framework_Repository::get_active_version((int) $framework->id);

      if ($active_version && !empty($active_version->compiled_at)) {
        $compiler_label = 'Current';
      } elseif ($active_version) {
        $compiler_label = 'Needs compile';
      }
    }

?>
    <div class="wrap">
      <h1>Profiles</h1>

      <?php if (isset($_GET['cfm_created'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Profile vocabulary created.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Name and slug are required.</p>
        </div>
      <?php endif; ?>

      <p>
        Define the profile vocabulary used across Teachers.Net. Other plugins and themes can consume these profile terms through Profilaxes APIs.
      </p>

      <?php if (!$framework) : ?>
        <div class="card" style="max-width: 760px;">
          <h2>Profile Vocabulary</h2>
          <p>
            This site does not have a profile vocabulary yet. Create the primary site vocabulary before adding axes or terms.
          </p>

          <form method="post">
            <?php wp_nonce_field('cfm_create_framework', 'cfm_nonce'); ?>
            <input type="hidden" name="cfm_action" value="create_framework">
            <input type="hidden" name="cfm_name" value="Sandbox Profiles">
            <input type="hidden" name="cfm_slug" value="primary">
            <input type="hidden" name="cfm_description" value="Primary site profile vocabulary.">

            <?php submit_button('Create Profile Vocabulary'); ?>
          </form>
        </div>
      <?php else : ?>
        <div class="card" style="max-width: 760px;">
          <h2>Current Vocabulary</h2>

          <table class="widefat striped" style="max-width: 680px;">
            <tbody>
              <tr>
                <th scope="row" style="width: 180px;">Name</th>
                <td><?php echo esc_html($framework->name); ?></td>
              </tr>
              <tr>
                <th scope="row">Axes</th>
                <td><?php echo esc_html((string) $axis_count); ?></td>
              </tr>
              <tr>
                <th scope="row">Terms</th>
                <td><?php echo esc_html((string) $term_count); ?></td>
              </tr>
              <tr>
                <th scope="row">Users Assigned</th>
                <td><?php echo esc_html((string) $assigned_user_count); ?></td>
              </tr>
              <tr>
                <th scope="row">Compiler</th>
                <td><?php echo esc_html($compiler_label); ?></td>
              </tr>
            </tbody>
          </table>

          <div style="margin-top: 16px;">
            <a class="button button-primary" href="<?php echo esc_url(self::edit_url((int) $framework->id)); ?>">
              Edit Profiles
            </a>

            <?php if (!empty($active_version)) : ?>
              <form method="post" style="display: inline-block; margin-left: 8px;">
                <?php wp_nonce_field('cfm_compile_active_version', 'cfm_nonce'); ?>
                <input type="hidden" name="cfm_action" value="compile_active_version">
                <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">
                <?php submit_button('Retry Compile', 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($framework_count > 1) : ?>
            <p class="description">
              Maintenance note: additional internal vocabulary records exist. The normal admin flow uses the primary vocabulary only.
            </p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php
  }

  private static function count_profile_tree_nodes(array $node): array
  {
    $counts = [
      'axes' => 0,
      'terms' => 0,
    ];

    $type = (string) ($node['type'] ?? '');

    if ($type === 'axis') {
      $counts['axes']++;
    } elseif ($type === 'term') {
      $counts['terms']++;
    }

    $children = $node['children'] ?? [];

    if (is_array($children)) {
      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $child_counts = self::count_profile_tree_nodes($child);

        $counts['axes'] += $child_counts['axes'];
        $counts['terms'] += $child_counts['terms'];
      }
    }

    return $counts;
  }

  private static function edit_url(int $framework_id): string
  {
    return admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=edit'
        . '&framework_id=' . $framework_id
    );
  }

  private static function versions_url(int $framework_id, int $paged = 1): string
  {
    $url = admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=versions'
        . '&framework_id=' . $framework_id
    );

    if ($paged > 1) {
      $url = add_query_arg('paged', $paged, $url);
    }

    return $url;
  }

  private static function version_snapshot_url(int $framework_id, int $version_id): string
  {
    return admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=view_version'
        . '&framework_id=' . $framework_id
        . '&version_id=' . $version_id
    );
  }

  private static function restore_version_url(int $framework_id, int $version_id): string
  {
    $url = admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=restore_version'
        . '&framework_id=' . $framework_id
        . '&version_id=' . $version_id
    );

    return wp_nonce_url($url, 'cfm_restore_version_' . $framework_id . '_' . $version_id);
  }

  private static function edit_term_url(int $framework_id, string $term_uuid): string
  {
    return add_query_arg([
      'page' => 'cfm-frameworks',
      'action' => 'edit_term',
      'framework_id' => $framework_id,
      'term_uuid' => $term_uuid,
    ], admin_url('admin.php'));
  }

  private static function move_term_url(int $framework_id, string $term_uuid): string
  {
    return admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=move_term'
        . '&framework_id=' . $framework_id
        . '&term_uuid=' . rawurlencode($term_uuid)
    );
  }

  private static function archive_term_url(int $framework_id, string $term_uuid): string
  {
    return admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=archive_term'
        . '&framework_id=' . $framework_id
        . '&term_uuid=' . rawurlencode($term_uuid)
    );
  }

  private static function compiled_debug_url(int $framework_id): string
  {
    return admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=compiled_debug'
        . '&framework_id=' . $framework_id
    );
  }

  public static function render_versions_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;
    $total = CFM_Framework_Repository::count_versions((int) $framework->id);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $versions = CFM_Framework_Repository::get_versions((int) $framework->id, $per_page, $offset);

  ?>
    <div class="wrap">
      <h1>Version History: <?php echo esc_html($framework->name); ?></h1>

      <p>
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=edit'
                        . '&framework_id=' . (int) $framework->id
                    )
                  ); ?>">
          ← Back to Edit Framework
        </a>
      </p>

      <p>
        Saved versions: <strong><?php echo esc_html((string) $total); ?></strong>
        · Page <strong><?php echo esc_html((string) $paged); ?></strong> of <strong><?php echo esc_html((string) $total_pages); ?></strong>
      </p>

      <?php if (empty($versions)) : ?>
        <p>No versions saved yet.</p>
      <?php else : ?>
        <table class="widefat striped" style="max-width: 1100px;">
          <thead>
            <tr>
              <th>Version</th>
              <th>Status</th>
              <th>Created</th>
              <th>Created By</th>
              <th>JSON Size</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($versions as $version_row) : ?>
              <?php $is_active_version = ((int) $framework->active_version_id === (int) $version_row->id); ?>
              <tr>
                <td>
                  <strong>v<?php echo esc_html((string) $version_row->version_number); ?></strong>
                  <?php if ($is_active_version) : ?>
                    <span style="color: #008a20; margin-left: 6px;">Active</span>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($version_row->status); ?></td>
                <td><?php echo esc_html($version_row->created_at); ?></td>
                <td><?php echo esc_html($version_row->created_by ?: 'Unknown'); ?></td>
                <td><?php echo esc_html((string) strlen((string) $version_row->tree_json)); ?> bytes</td>
                <td>
                  <a href="<?php echo esc_url(self::version_snapshot_url((int) $framework->id, (int) $version_row->id)); ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <?php if ($total_pages > 1) : ?>
        <p style="margin-top: 16px;">
          <?php if ($paged > 1) : ?>
            <a class="button" href="<?php echo esc_url(self::versions_url((int) $framework->id, $paged - 1)); ?>">← Previous</a>
          <?php endif; ?>

          <?php if ($paged < $total_pages) : ?>
            <a class="button" href="<?php echo esc_url(self::versions_url((int) $framework->id, $paged + 1)); ?>">Next →</a>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  <?php
  }

  public static function render_version_snapshot_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $version_id = isset($_GET['version_id'])
      ? absint($_GET['version_id'])
      : 0;

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $version = CFM_Framework_Repository::get_version((int) $framework->id, $version_id);

    if (!$version) {
      wp_die('Version not found.');
    }

    $tree = json_decode((string) $version->tree_json, true);
    $is_active_version = ((int) $framework->active_version_id === (int) $version->id);
    $pretty_json = wp_json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

  ?>
    <div class="wrap">
      <h1>
        Version Snapshot: <?php echo esc_html($framework->name); ?>
        v<?php echo esc_html((string) $version->version_number); ?>
      </h1>

      <p>
        <a href="<?php echo esc_url(self::versions_url((int) $framework->id)); ?>">← Back to Version History</a>
        ·
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=edit'
                        . '&framework_id=' . (int) $framework->id
                    )
                  ); ?>">Back to Edit Framework</a>
      </p>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Version</th>
            <td>
              v<?php echo esc_html((string) $version->version_number); ?>
              <?php if ($is_active_version) : ?>
                <span style="color: #008a20; margin-left: 6px;">Active</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th>Status</th>
            <td><?php echo esc_html($version->status); ?></td>
          </tr>
          <tr>
            <th>Created</th>
            <td><?php echo esc_html($version->created_at); ?></td>
          </tr>
          <tr>
            <th>Created By</th>
            <td><?php echo esc_html($version->created_by ?: 'Unknown'); ?></td>
          </tr>
          <tr>
            <th>JSON Size</th>
            <td><?php echo esc_html((string) strlen((string) $version->tree_json)); ?> bytes</td>
          </tr>
        </tbody>
      </table>

      <?php if (!$is_active_version && is_array($tree)) : ?>
        <p style="margin-top: 16px;">
          <a class="button button-primary" href="<?php echo esc_url(self::restore_version_url((int) $framework->id, (int) $version->id)); ?>">
            Restore This Version
          </a>
        </p>
      <?php endif; ?>

      <hr>

      <h2>Tree Snapshot</h2>

      <?php if (!is_array($tree)) : ?>
        <p>Stored tree JSON could not be decoded.</p>
      <?php else : ?>
        <?php self::render_terms_recursive($tree['children'] ?? []); ?>
      <?php endif; ?>

      <hr>

      <h2>Raw tree_json</h2>

      <textarea readonly class="large-text code" rows="24"><?php echo esc_textarea($pretty_json ?: (string) $version->tree_json); ?></textarea>
    </div>
  <?php
  }

  public static function render_restore_version_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $version_id = isset($_GET['version_id'])
      ? absint($_GET['version_id'])
      : 0;

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $version = CFM_Framework_Repository::get_version((int) $framework->id, $version_id);

    if (!$version) {
      wp_die('Version not found.');
    }

    $tree = json_decode((string) $version->tree_json, true);
    $is_active_version = ((int) $framework->active_version_id === (int) $version->id);

    if (!is_array($tree)) {
      wp_die('Stored tree JSON could not be decoded. Restore is unavailable for this version.');
    }

  ?>
    <div class="wrap">
      <h1>
        Restore Framework Version: <?php echo esc_html($framework->name); ?>
        v<?php echo esc_html((string) $version->version_number); ?>
      </h1>

      <p>
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=view_version'
                        . '&framework_id=' . (int) $framework->id
                        . '&version_id=' . (int) $version->id
                    )
                  ); ?>">← Back to Version Snapshot</a>
        ·
        <a href="<?php echo esc_url(self::versions_url((int) $framework->id)); ?>">Back to Version History</a>
      </p>

      <?php if ($is_active_version) : ?>
        <div class="notice notice-info">
          <p>This version is already active. No restore is needed.</p>
        </div>
      <?php else : ?>
        <div class="notice notice-warning">
          <p>
            This will create a new active version copied from v<?php echo esc_html((string) $version->version_number); ?>.
            The original version and all other historical versions will remain unchanged.
          </p>
        </div>
      <?php endif; ?>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Framework</th>
            <td><?php echo esc_html($framework->name); ?></td>
          </tr>
          <tr>
            <th>Restore From</th>
            <td>v<?php echo esc_html((string) $version->version_number); ?></td>
          </tr>
          <tr>
            <th>Created</th>
            <td><?php echo esc_html($version->created_at); ?></td>
          </tr>
          <tr>
            <th>JSON Size</th>
            <td><?php echo esc_html((string) strlen((string) $version->tree_json)); ?> bytes</td>
          </tr>
        </tbody>
      </table>

      <h2>Snapshot to Restore</h2>
      <?php self::render_terms_recursive($tree['children'] ?? []); ?>

      <?php if (!$is_active_version) : ?>
        <form method="post" style="margin-top: 20px;">
          <?php wp_nonce_field('cfm_restore_version', 'cfm_nonce'); ?>

          <input type="hidden" name="cfm_action" value="restore_version">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
          <input type="hidden" name="version_id" value="<?php echo esc_attr($version->id); ?>">

          <?php submit_button('Restore This Version', 'primary'); ?>
        </form>
      <?php endif; ?>
    </div>
  <?php
  }

  public static function render_archive_term_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $term_uuid = isset($_GET['term_uuid'])
      ? sanitize_text_field(wp_unslash($_GET['term_uuid']))
      : '';

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    $term = $term_info['node'];
    $current_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : null;

    if (($term['type'] ?? '') !== 'term') {
      wp_die('Only terms can be archived.');
    }

    $children = $term['children'] ?? [];
    $descendant_count = self::count_descendant_terms($term);

  ?>
    <div class="wrap">
      <h1>Archive Term: <?php echo esc_html($term['label'] ?? ''); ?></h1>

      <p>
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=edit'
                        . '&framework_id=' . (int) $framework->id
                    )
                  ); ?>">← Back to Edit Framework</a>
      </p>

      <div class="notice notice-warning">
        <p>
          This will remove the term from the current active tree by creating a new version.
          Historical versions will still contain the term.
        </p>
      </div>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Framework</th>
            <td><?php echo esc_html($framework->name); ?></td>
          </tr>
          <tr>
            <th>Term</th>
            <td>
              <?php echo esc_html($term['label'] ?? ''); ?>
              <code><?php echo esc_html($term['slug'] ?? ''); ?></code>
            </td>
          </tr>
          <tr>
            <th>UUID</th>
            <td><code><?php echo esc_html($term['uuid'] ?? ''); ?></code></td>
          </tr>
          <tr>
            <th>Current Parent</th>
            <td>
              <?php if ($current_parent) : ?>
                <?php echo esc_html($current_parent['label'] ?? ''); ?>
                <code><?php echo esc_html($current_parent['slug'] ?? ''); ?></code>
              <?php else : ?>
                Unknown
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th>Descendant Terms</th>
            <td><?php echo esc_html((string) $descendant_count); ?></td>
          </tr>
        </tbody>
      </table>

      <?php if (!empty($children) && is_array($children)) : ?>
        <h2>Archived Subtree</h2>
        <?php self::render_terms_recursive($children); ?>
      <?php endif; ?>

      <form method="post" style="margin-top: 20px;">
        <?php wp_nonce_field('cfm_archive_term', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="archive_term">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
        <input type="hidden" name="term_uuid" value="<?php echo esc_attr($term['uuid'] ?? ''); ?>">

        <?php submit_button('Archive Term', 'delete'); ?>
      </form>
    </div>
  <?php
  }

  public static function render_edit_term_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id']) ? absint($_GET['framework_id']) : 0;
    $term_uuid = isset($_GET['term_uuid']) ? sanitize_text_field(wp_unslash($_GET['term_uuid'])) : '';

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = $tree['children'] ?? [];
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    $term = $term_info['node'];
    $current_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : null;

    if (($term['type'] ?? '') !== 'term') {
      wp_die('Only terms can be edited here.');
    }

    $current_parent_uuid = $current_parent['uuid'] ?? '';

  ?>
    <div class="wrap">
      <h1>Edit Term: <?php echo esc_html($term['label'] ?? ''); ?></h1>

      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . (int) $framework->id)); ?>">← Back to Edit Profiles</a>
      </p>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_edit_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Parent, term label, and term slug are required.</p>
        </div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('cfm_update_term', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="update_term">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
        <input type="hidden" name="term_uuid" value="<?php echo esc_attr($term['uuid'] ?? ''); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="term_label">Term Label</label></th>
            <td>
              <input name="term_label" id="term_label" type="text" class="regular-text" value="<?php echo esc_attr($term['label'] ?? ''); ?>" required>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="term_slug">Term Slug</label></th>
            <td>
              <input name="term_slug" id="term_slug" type="text" class="regular-text" value="<?php echo esc_attr($term['slug'] ?? ''); ?>" required>
              <p class="description">Keep this stable unless you intentionally need to change API-facing references.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="parent_uuid">Parent</label></th>
            <td>
              <select name="parent_uuid" id="parent_uuid" required>
                <option value="">Select a parent</option>
                <?php self::render_move_parent_options($axes, $term, (string) $current_parent_uuid); ?>
              </select>
            </td>
          </tr>
        </table>

        <?php submit_button('Save Term'); ?>
      </form>
    </div>
  <?php
  }

  public static function render_move_term_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $term_uuid = isset($_GET['term_uuid'])
      ? sanitize_text_field(wp_unslash($_GET['term_uuid']))
      : '';

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = $tree['children'] ?? [];
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    $term = $term_info['node'];
    $current_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : null;

    if (($term['type'] ?? '') !== 'term') {
      wp_die('Only terms can be moved.');
    }

    $current_parent_uuid = $current_parent['uuid'] ?? '';

  ?>
    <div class="wrap">
      <h1>Move Term: <?php echo esc_html($term['label'] ?? ''); ?></h1>

      <p>
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=edit'
                        . '&framework_id=' . (int) $framework->id
                    )
                  ); ?>">← Back to Edit Framework</a>
      </p>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Framework</th>
            <td><?php echo esc_html($framework->name); ?></td>
          </tr>
          <tr>
            <th>Term</th>
            <td>
              <?php echo esc_html($term['label'] ?? ''); ?>
              <code><?php echo esc_html($term['slug'] ?? ''); ?></code>
            </td>
          </tr>
          <tr>
            <th>UUID</th>
            <td><code><?php echo esc_html($term['uuid'] ?? ''); ?></code></td>
          </tr>
          <tr>
            <th>Current Parent</th>
            <td>
              <?php if ($current_parent) : ?>
                <?php echo esc_html($current_parent['label'] ?? ''); ?>
                <code><?php echo esc_html($current_parent['slug'] ?? ''); ?></code>
              <?php else : ?>
                Unknown
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>

      <h2>Choose New Parent</h2>

      <form method="post">
        <?php wp_nonce_field('cfm_move_term', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="move_term">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
        <input type="hidden" name="term_uuid" value="<?php echo esc_attr($term['uuid'] ?? ''); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="new_parent_uuid">New Parent</label>
            </th>
            <td>
              <select name="new_parent_uuid" id="new_parent_uuid" required>
                <option value="">Select a new parent</option>
                <?php self::render_move_parent_options($axes, $term, (string) $current_parent_uuid); ?>
              </select>
              <p class="description">
                A term can move under an axis or another term. It cannot move under itself or its descendants.
              </p>
            </td>
          </tr>
        </table>

        <?php submit_button('Move Term'); ?>
      </form>
    </div>
  <?php
  }

  public static function render_compiled_debug_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $term_uuid = isset($_GET['term_uuid'])
      ? sanitize_text_field(wp_unslash($_GET['term_uuid']))
      : '';

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $active_version = CFM_Framework_Repository::get_active_version((int) $framework->id);
    $terms = $active_version
      ? CFM_Framework_Repository::get_compiled_terms((int) $framework->id, (int) $active_version->id)
      : [];

    $selected_term = null;
    $ancestors = [];
    $descendants = [];
    $siblings = [];

    if ($term_uuid !== '' && $active_version) {
      $selected_term = CFM_Framework_Repository::get_term_by_uuid((int) $framework->id, $term_uuid, (int) $active_version->id);

      if ($selected_term) {
        $ancestor_uuids = CFM_Framework_Repository::get_ancestor_uuids((int) $framework->id, $term_uuid, (int) $active_version->id, false);
        $descendant_uuids = CFM_Framework_Repository::get_descendant_uuids((int) $framework->id, $term_uuid, (int) $active_version->id, false);

        $ancestors = CFM_Framework_Repository::get_terms_by_uuids((int) $framework->id, $ancestor_uuids, (int) $active_version->id);
        $descendants = CFM_Framework_Repository::get_terms_by_uuids((int) $framework->id, $descendant_uuids, (int) $active_version->id);
        $siblings = CFM_Framework_Repository::get_sibling_terms((int) $framework->id, $term_uuid, (int) $active_version->id, false);
      }
    }

  ?>
    <div class="wrap">
      <h1>Compiled Query Debug: <?php echo esc_html($framework->name); ?></h1>

      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . (int) $framework->id)); ?>">
          ← Back to Edit Framework
        </a>
      </p>

      <?php if (!$active_version) : ?>
        <div class="notice notice-warning">
          <p>No active version exists.</p>
        </div>
      <?php elseif (empty($terms)) : ?>
        <div class="notice notice-warning">
          <p>No compiled terms found. Compile the active version first.</p>
        </div>
      <?php endif; ?>

      <?php if ($active_version) : ?>
        <table class="widefat striped" style="max-width: 760px;">
          <tbody>
            <tr>
              <th style="width: 180px;">Framework Slug</th>
              <td><code><?php echo esc_html($framework->slug); ?></code></td>
            </tr>
            <tr>
              <th>Active Version</th>
              <td>v<?php echo esc_html((string) $active_version->version_number); ?></td>
            </tr>
            <tr>
              <th>Compiled At</th>
              <td><?php echo esc_html($active_version->compiled_at ?: 'Not compiled'); ?></td>
            </tr>
            <tr>
              <th>Compiled Terms</th>
              <td><?php echo esc_html((string) count($terms)); ?></td>
            </tr>
          </tbody>
        </table>
      <?php endif; ?>

      <hr>

      <h2>Pick a Term</h2>

      <form method="get">
        <input type="hidden" name="page" value="cfm-frameworks">
        <input type="hidden" name="action" value="compiled_debug">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">

        <select name="term_uuid" style="min-width: 360px;">
          <option value="">Select a compiled term</option>
          <?php foreach ($terms as $term) : ?>
            <option value="<?php echo esc_attr($term->term_uuid); ?>" <?php selected($term_uuid, $term->term_uuid); ?>>
              <?php echo esc_html(str_repeat('— ', max(0, (int) $term->depth)) . $term->label . ' (' . $term->slug . ')'); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <?php submit_button('Inspect Term', 'secondary', 'submit', false); ?>
      </form>

      <?php if ($term_uuid !== '' && !$selected_term) : ?>
        <div class="notice notice-error">
          <p>Selected compiled term was not found.</p>
        </div>
      <?php endif; ?>

      <?php if ($selected_term) : ?>
        <hr>
        <h2>Selected Term</h2>
        <?php self::render_compiled_terms_table([$selected_term]); ?>

        <h2>Ancestors</h2>
        <?php self::render_compiled_terms_table($ancestors); ?>

        <h2>Descendants</h2>
        <?php self::render_compiled_terms_table($descendants); ?>

        <h2>Siblings</h2>
        <?php self::render_compiled_terms_table($siblings); ?>

        <h2>Example Public API Calls</h2>
        <pre style="background:#fff; border:1px solid #ccd0d4; padding:12px; max-width:900px; overflow:auto;"><code>CFM::get_term_by_slug('<?php echo esc_html($framework->slug); ?>', '<?php echo esc_html($selected_term->slug); ?>');
CFM::get_ancestors('<?php echo esc_html($framework->slug); ?>', '<?php echo esc_html($selected_term->slug); ?>');
CFM::get_descendants('<?php echo esc_html($framework->slug); ?>', '<?php echo esc_html($selected_term->slug); ?>');
CFM::get_siblings('<?php echo esc_html($framework->slug); ?>', '<?php echo esc_html($selected_term->slug); ?>');</code></pre>
      <?php endif; ?>

      <hr>

      <h2>All Compiled Terms</h2>
      <?php self::render_compiled_terms_table($terms); ?>
    </div>
  <?php
  }

  private static function render_compiled_terms_table(array $terms): void
  {
    if (empty($terms)) {
      echo '<p><em>None.</em></p>';
      return;
    }

  ?>
    <table class="widefat striped" style="max-width: 1100px;">
      <thead>
        <tr>
          <th>Label</th>
          <th>Slug</th>
          <th>UUID</th>
          <th>Parent UUID</th>
          <th>Axis UUID</th>
          <th>Depth</th>
          <th>Path</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($terms as $term) : ?>
          <tr>
            <td><?php echo esc_html($term->label); ?></td>
            <td><code><?php echo esc_html($term->slug); ?></code></td>
            <td><code><?php echo esc_html($term->term_uuid); ?></code></td>
            <td><code><?php echo esc_html($term->parent_uuid ?: 'NULL'); ?></code></td>
            <td><code><?php echo esc_html($term->axis_uuid ?: 'NULL'); ?></code></td>
            <td><?php echo esc_html((string) $term->depth); ?></td>
            <td><code><?php echo esc_html($term->path); ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php
  }

  public static function render_framework_edit_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Framework not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = $tree['children'] ?? [];

  ?>
    <div class="wrap">
      <h1>Edit Framework: <?php echo esc_html($framework->name); ?></h1>

      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks')); ?>">
          ← Back to Community Frameworks
        </a>
      </p>

      <?php if (isset($_GET['cfm_axis_added'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Axis added.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_term_added'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term added.</p>
        </div>
      <?php endif; ?>


      <?php if (isset($_GET['cfm_term_moved'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term moved.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_term_archived'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term archived from the active tree. Historical versions are unchanged.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_version_restored'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Framework version restored by creating a new active version.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_compiled'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Profile runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_autocompiled'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Profile changes saved and runtime tables rebuilt automatically.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_axis_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Axis label and slug are required.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_term_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Parent, term label, and term slug are required.</p>
        </div>
      <?php endif; ?>


      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_move_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Term and new parent are required.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'no_active_version') : ?>
        <div class="notice notice-error is-dismissible">
          <p>No active version exists to compile.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'version_save_failed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Profile changes could not be saved.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'compile_failed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Runtime rebuild failed. The saved profile tree may not match the query tables. Check PHP error logs, then retry compile.</p>
        </div>
      <?php endif; ?>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">ID</th>
            <td><?php echo esc_html($framework->id); ?></td>
          </tr>
          <tr>
            <th>Name</th>
            <td><?php echo esc_html($framework->name); ?></td>
          </tr>
          <tr>
            <th>Slug</th>
            <td><code><?php echo esc_html($framework->slug); ?></code></td>
          </tr>
          <tr>
            <th>Description</th>
            <td><?php echo esc_html($framework->description); ?></td>
          </tr>
          <tr>
            <th>Active Version</th>
            <td><?php echo esc_html($framework->active_version_id ?: 'None'); ?></td>
          </tr>
        </tbody>
      </table>

      <hr>

      <h2>Version History</h2>

      <?php
      $version_count = CFM_Framework_Repository::count_versions((int) $framework->id);
      $recent_versions = CFM_Framework_Repository::get_versions((int) $framework->id, 3, 0);
      ?>

      <p>
        Current active version ID:
        <strong><?php echo esc_html($framework->active_version_id ?: 'None'); ?></strong>
        · Saved versions:
        <strong><?php echo esc_html((string) $version_count); ?></strong>
      </p>

      <?php if (empty($recent_versions)) : ?>
        <p>No versions saved yet.</p>
      <?php else : ?>
        <table class="widefat striped" style="max-width: 760px;">
          <thead>
            <tr>
              <th>Recent Version</th>
              <th>Created</th>
              <th>JSON Size</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_versions as $version_row) : ?>
              <?php $is_active_version = ((int) $framework->active_version_id === (int) $version_row->id); ?>
              <tr>
                <td>
                  <strong>v<?php echo esc_html((string) $version_row->version_number); ?></strong>
                  <?php if ($is_active_version) : ?>
                    <span style="color: #008a20; margin-left: 6px;">Active</span>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($version_row->created_at); ?></td>
                <td><?php echo esc_html((string) strlen((string) $version_row->tree_json)); ?> bytes</td>
                <td>
                  <a href="<?php echo esc_url(self::version_snapshot_url((int) $framework->id, (int) $version_row->id)); ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p>
        <a class="button" href="<?php echo esc_url(self::versions_url((int) $framework->id)); ?>">
          View Full Version History
        </a>
      </p>

      <hr>

      <h2>Compiler</h2>

      <?php $active_version = CFM_Framework_Repository::get_active_version((int) $framework->id); ?>
      <?php if (!$active_version) : ?>
        <p>No active version exists to compile.</p>
      <?php else : ?>
        <?php $compiled_counts = CFM_Framework_Repository::get_compiled_counts((int) $framework->id, (int) $active_version->id); ?>
        <table class="widefat striped" style="max-width: 760px;">
          <tbody>
            <tr>
              <th style="width: 180px;">Active Version</th>
              <td>v<?php echo esc_html((string) $active_version->version_number); ?></td>
            </tr>
            <tr>
              <th>Compiled At</th>
              <td><?php echo esc_html($active_version->compiled_at ?: 'Not compiled'); ?></td>
            </tr>
            <tr>
              <th>Compiled Terms</th>
              <td><?php echo esc_html((string) $compiled_counts['terms']); ?></td>
            </tr>
            <tr>
              <th>Closure Rows</th>
              <td><?php echo esc_html((string) $compiled_counts['closure']); ?></td>
            </tr>
          </tbody>
        </table>

        <form method="post" style="margin-top: 12px;">
          <?php wp_nonce_field('cfm_compile_active_version', 'cfm_nonce'); ?>
          <input type="hidden" name="cfm_action" value="compile_active_version">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
          <?php submit_button('Retry Compile', 'secondary', 'submit', false); ?>
          <a class="button" href="<?php echo esc_url(self::compiled_debug_url((int) $framework->id)); ?>" style="margin-left: 8px;">Open Compiled Query Debug</a>
        </form>
      <?php endif; ?>

      <hr>

      <h2 id="cfm-existing-terms">Existing Axes and Terms</h2>

      <?php if (empty($axes)) : ?>
        <p>No axes created yet.</p>
      <?php else : ?>
        <table class="widefat striped" style="max-width: 1000px;">
          <thead>
            <tr>
              <th>Axis</th>
              <th>Slug</th>
              <th>UUID</th>
              <th>Terms</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($axes as $axis) : ?>
              <tr>
                <td><strong><?php echo esc_html($axis['label'] ?? ''); ?></strong></td>
                <td><code><?php echo esc_html($axis['slug'] ?? ''); ?></code></td>
                <td><code><?php echo esc_html($axis['uuid'] ?? ''); ?></code></td>
                <td>
                  <?php $terms = $axis['children'] ?? []; ?>

                  <?php if (empty($terms)) : ?>
                    <em>No terms yet.</em>
                  <?php else : ?>
                    <?php self::render_terms_recursive($terms, 0, (int) $framework->id, true); ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <hr>

      <h2>Add Axis</h2>

      <form method="post">
        <?php wp_nonce_field('cfm_add_axis', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="add_axis">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="axis_label">Axis Label</label>
            </th>
            <td>
              <input name="axis_label" id="axis_label" type="text" class="regular-text" required>
              <p class="description">Example: Grade Level, Curriculum, Region, Practice Area</p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="axis_slug">Axis Slug</label>
            </th>
            <td>
              <input name="axis_slug" id="axis_slug" type="text" class="regular-text" required>
              <p class="description">Example: grade-level, curriculum, region</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Add Axis'); ?>
      </form>

      <hr>

      <h2 id="cfm-add-term">Add Term Under Parent</h2>

      <?php if (empty($axes)) : ?>
        <p>Create an axis before adding terms.</p>
      <?php else : ?>
        <form method="post">
          <?php wp_nonce_field('cfm_add_term', 'cfm_nonce'); ?>

          <input type="hidden" name="cfm_action" value="add_term">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">

          <table class="form-table" role="presentation">
            <tr>
              <th scope="row">
                <label for="parent_uuid">Parent</label>
              </th>
              <td>
                <select name="parent_uuid" id="parent_uuid" required>
                  <option value="">Select a parent</option>
                  <?php self::render_parent_options($axes, sanitize_text_field($_GET['cfm_parent_uuid'] ?? '')); ?>
                </select>
                <p class="description">Choose an axis for a top-level term, or an existing term for a child term.</p>
              </td>
            </tr>

            <tr>
              <th scope="row">
                <label for="term_label">Term Label</label>
              </th>
              <td>
                <input name="term_label" id="term_label" type="text" class="regular-text" required>
                <p class="description">Example: Grade 1, Elementary, Algebra, California</p>
              </td>
            </tr>

            <tr>
              <th scope="row">
                <label for="term_slug">Term Slug</label>
              </th>
              <td>
                <input name="term_slug" id="term_slug" type="text" class="regular-text" required>
                <p class="description">Example: grade-1, elementary, algebra, california</p>
              </td>
            </tr>
          </table>

          <?php submit_button('Add Term'); ?>
        </form>
      <?php endif; ?>
    </div>
<?php
  }
}
