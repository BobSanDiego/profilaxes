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
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    add_action('wp_ajax_cfm_reorder_terms', [__CLASS__, 'handle_ajax_reorder_terms']);
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
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
      return;
    }

    if (!is_admin()) {
      return;
    }

    if (!empty($_GET['cfm_action'])) {
      $action = sanitize_key(wp_unslash($_GET['cfm_action']));

      if ($action === 'export_taxonomy') {
        self::handle_export_taxonomy();
        return;
      }
    }

    if (empty($_POST['cfm_action'])) {
      return;
    }

    $action = sanitize_key(wp_unslash($_POST['cfm_action']));

    if ($action === 'import_taxonomy_preview') {
      self::handle_import_taxonomy_preview();
      return;
    }

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

    if ($action === 'reorder_terms') {
      self::handle_reorder_terms();
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


  public static function enqueue_admin_assets(string $hook_suffix): void
  {
    if ($hook_suffix !== 'toplevel_page_cfm-frameworks') {
      return;
    }

    wp_enqueue_script('jquery-ui-sortable');
  }


  private static function save_active_tree_and_compile(int $framework_id, array $tree): array
  {
    self::normalize_tree_children($tree);

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


  private static function handle_export_taxonomy(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to export this profile taxonomy.');
    }

    $framework_id = isset($_GET['framework_id']) ? absint($_GET['framework_id']) : 0;

    if ($framework_id <= 0) {
      wp_die('Missing profile taxonomy ID.');
    }

    check_admin_referer('cfm_export_taxonomy_' . $framework_id);

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Profile taxonomy not found.');
    }

    $active_version = CFM_Framework_Repository::get_active_version($framework_id);
    $tree = self::get_framework_tree($framework);
    self::normalize_tree_children($tree);

    $export = [
      'export_type' => 'profilaxes_profile_taxonomy',
      'export_schema_version' => 1,
      'exported_at' => current_time('mysql'),
      'site_url' => site_url(),
      'plugin' => [
        'name' => 'Profilaxes',
        'version' => defined('CFM_VERSION') ? CFM_VERSION : '',
      ],
      'framework' => [
        'id' => (int) $framework->id,
        'uuid' => (string) $framework->framework_uuid,
        'name' => (string) $framework->name,
        'slug' => (string) $framework->slug,
        'description' => (string) $framework->description,
        'active_version_id' => !empty($framework->active_version_id) ? (int) $framework->active_version_id : null,
      ],
      'active_version' => $active_version ? [
        'id' => (int) $active_version->id,
        'version_number' => (int) $active_version->version_number,
        'status' => (string) $active_version->status,
        'compiled_at' => $active_version->compiled_at ?: null,
        'created_by' => !empty($active_version->created_by) ? (int) $active_version->created_by : null,
        'created_at' => (string) $active_version->created_at,
      ] : null,
      'source_of_truth' => [
        'canonical_data' => 'framework_versions.tree_json',
        'runtime_tables' => [
          'cfm_terms_compiled',
          'cfm_term_closure',
        ],
        'runtime_tables_rebuildable' => true,
        'assignment_storage' => 'cfm_user_terms stores user assignments by stable term UUID',
      ],
      'tree' => $tree,
    ];

    $json = wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($json) || $json === '') {
      wp_die('Profile taxonomy export could not be encoded.');
    }

    $filename_parts = array_filter([
      'profilaxes-taxonomy',
      sanitize_title((string) $framework->slug),
      gmdate('Ymd-His'),
    ]);

    $filename = implode('-', $filename_parts) . '.json';

    if (ob_get_length()) {
      ob_end_clean();
    }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));

    echo $json;
    exit;
  }


  private static function handle_import_taxonomy_preview(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to import this profile taxonomy.');
    }

    check_admin_referer('cfm_import_taxonomy_preview', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);

    if ($framework_id <= 0) {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&cfm_error=missing_import_framework'));
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Profile taxonomy not found.');
    }

    if (empty($_FILES['taxonomy_import_file']) || !is_array($_FILES['taxonomy_import_file'])) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=missing_import_file#cfm-import');
      exit;
    }

    $file = $_FILES['taxonomy_import_file'];
    $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($upload_error !== UPLOAD_ERR_OK) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_upload_failed#cfm-import');
      exit;
    }

    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    $size = isset($file['size']) ? (int) $file['size'] : 0;

    if ($tmp_name === '' || !is_uploaded_file($tmp_name) || $size <= 0) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=missing_import_file#cfm-import');
      exit;
    }

    if ($size > 2 * 1024 * 1024) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_file_too_large#cfm-import');
      exit;
    }

    $raw_json = file_get_contents($tmp_name);

    if (!is_string($raw_json) || trim($raw_json) === '') {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_file_empty#cfm-import');
      exit;
    }

    $decoded = json_decode($raw_json, true);

    if (!is_array($decoded)) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_invalid_json#cfm-import');
      exit;
    }

    $current_tree = self::get_framework_tree($framework);
    self::normalize_tree_children($current_tree);

    $preview = self::build_taxonomy_import_preview($decoded, $current_tree);
    set_transient(self::import_preview_transient_key($framework_id), $preview, 10 * MINUTE_IN_SECONDS);

    wp_safe_redirect(self::edit_url($framework_id) . '&cfm_import_preview=1#cfm-import');
    exit;
  }


  private static function handle_compile_active_version(): void
  {
    check_admin_referer('cfm_compile_active_version', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);

    if ($framework_id <= 0) {
      wp_die('Missing profile taxonomy ID.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Profile profile taxonomy not found.');
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
      wp_die('Profile profile taxonomy not found.');
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

    self::bump_order_revision($framework_id, (string) ($tree['uuid'] ?? ''));

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
      wp_die('Profile profile taxonomy not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    if (self::has_child_slug_conflict($tree, $parent_uuid, $term_slug)) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=duplicate_sibling_slug'
            . '&cfm_parent_uuid=' . rawurlencode($parent_uuid)
            . '#cfm-add-term'
        )
      );
      exit;
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

    self::bump_order_revision($framework_id, $parent_uuid);

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
      wp_die('Profile profile taxonomy not found.');
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

    if (self::has_child_slug_conflict($tree, $parent_uuid, $term_slug, $term_uuid)) {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&action=edit_term&framework_id=' . $framework_id . '&term_uuid=' . rawurlencode($term_uuid) . '&cfm_error=duplicate_sibling_slug'));
      exit;
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

      if ($current_parent_uuid !== '') {
        self::bump_order_revision($framework_id, $current_parent_uuid);
      }
      self::bump_order_revision($framework_id, $parent_uuid);
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
      wp_die('Profile profile taxonomy not found.');
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

    $moving_slug = sanitize_title((string) ($term_info['node']['slug'] ?? ''));

    if ($moving_slug === '') {
      wp_die('Term slug is missing. Move aborted.');
    }

    if (self::has_child_slug_conflict($tree, $new_parent_uuid, $moving_slug, $term_uuid)) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=move_term'
            . '&framework_id=' . $framework_id
            . '&term_uuid=' . rawurlencode($term_uuid)
            . '&cfm_error=duplicate_sibling_slug'
        )
      );
      exit;
    }

    $current_parent_uuid = '';
    if (!empty($term_info['parent']) && is_array($term_info['parent'])) {
      $current_parent_uuid = (string) ($term_info['parent']['uuid'] ?? '');
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

    if ($current_parent_uuid !== '') {
      self::bump_order_revision($framework_id, $current_parent_uuid);
    }
    self::bump_order_revision($framework_id, $new_parent_uuid);

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
      wp_die('Profile profile taxonomy not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (($term_info['node']['type'] ?? '') !== 'term') {
      wp_die('Only terms can be archived. Axes cannot be archived.');
    }

    $archive_uuids = self::collect_node_uuids($term_info['node']);
    $assignment_count = self::count_user_term_assignments($archive_uuids);

    if ($assignment_count > 0) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=archive_has_assignments'
            . '&cfm_assignment_count=' . $assignment_count
            . '#cfm-existing-terms'
        )
      );
      exit;
    }

    $parent_uuid = '';
    if (!empty($term_info['parent']) && is_array($term_info['parent'])) {
      $parent_uuid = (string) ($term_info['parent']['uuid'] ?? '');
    }

    $removed_term = null;
    $removed = self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term);

    if (!$removed || !is_array($removed_term)) {
      wp_die('Unable to archive term.');
    }

    if ($parent_uuid !== '') {
      self::bump_order_revision($framework_id, $parent_uuid);
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

  private static function has_child_slug_conflict(array $tree, string $parent_uuid, string $slug, string $exclude_uuid = ''): bool
  {
    $slug = sanitize_title($slug);

    if ($parent_uuid === '' || $slug === '') {
      return false;
    }

    $parent_info = self::find_node_with_parent($tree, $parent_uuid);

    if (!$parent_info || empty($parent_info['node']) || !is_array($parent_info['node'])) {
      return false;
    }

    $children = $parent_info['node']['children'] ?? [];

    if (empty($children) || !is_array($children)) {
      return false;
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      $child_uuid = (string) ($child['uuid'] ?? '');

      if ($exclude_uuid !== '' && $child_uuid === $exclude_uuid) {
        continue;
      }

      if (sanitize_title((string) ($child['slug'] ?? '')) === $slug) {
        return true;
      }
    }

    return false;
  }

  private static function collect_node_uuids(array $node): array
  {
    $uuids = [];
    $uuid = (string) ($node['uuid'] ?? '');

    if ($uuid !== '') {
      $uuids[] = $uuid;
    }

    $children = $node['children'] ?? [];

    if (is_array($children)) {
      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $uuids = array_merge($uuids, self::collect_node_uuids($child));
      }
    }

    return array_values(array_unique(array_filter($uuids)));
  }

  private static function count_user_term_assignments(array $term_uuids): int
  {
    global $wpdb;

    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if (empty($term_uuids)) {
      return 0;
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';
    $placeholders = implode(',', array_fill(0, count($term_uuids), '%s'));

    return (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$user_terms_table} WHERE term_uuid IN ({$placeholders})",
        ...$term_uuids
      )
    );
  }

  public static function handle_ajax_reorder_terms(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error([
        'message' => 'You do not have permission to reorder terms.',
      ], 403);
    }

    check_ajax_referer('cfm_reorder_terms', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $parent_uuid = sanitize_text_field(wp_unslash($_POST['parent_uuid'] ?? ''));
    $submitted_revision = absint($_POST['order_revision'] ?? 0);
    $submitted_order = isset($_POST['term_order']) && is_array($_POST['term_order'])
      ? array_values(array_map(static function ($uuid): string {
        return sanitize_text_field(wp_unslash($uuid));
      }, $_POST['term_order']))
      : [];

    $result = self::process_reorder_terms($framework_id, $parent_uuid, $submitted_revision, $submitted_order);

    if (empty($result['success'])) {
      wp_send_json_error([
        'message' => (string) ($result['message'] ?? 'Order could not be saved.'),
        'code' => (string) ($result['code'] ?? 'reorder_failed'),
        'current_revision' => (int) ($result['current_revision'] ?? 0),
      ], (int) ($result['status'] ?? 400));
    }

    wp_send_json_success([
      'message' => '✓ Saved',
      'revision' => (int) ($result['revision'] ?? 0),
    ]);
  }

  private static function handle_reorder_terms(): void
  {
    check_admin_referer('cfm_reorder_terms', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $parent_uuid = sanitize_text_field(wp_unslash($_POST['parent_uuid'] ?? ''));
    $submitted_revision = absint($_POST['order_revision'] ?? 0);
    $submitted_order = isset($_POST['term_order']) && is_array($_POST['term_order'])
      ? array_values(array_map(static function ($uuid): string {
        return sanitize_text_field(wp_unslash($uuid));
      }, $_POST['term_order']))
      : [];

    $result = self::process_reorder_terms($framework_id, $parent_uuid, $submitted_revision, $submitted_order);

    if (empty($result['success'])) {
      $error = (string) ($result['code'] ?? 'reorder_failed');

      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=' . rawurlencode($error)
            . '#cfm-ordering'
        )
      );
      exit;
    }

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_terms_reordered=1'
          . '#cfm-ordering'
      )
    );
    exit;
  }

  private static function process_reorder_terms(int $framework_id, string $parent_uuid, int $submitted_revision, array $submitted_order): array
  {
    if ($framework_id <= 0 || $parent_uuid === '' || empty($submitted_order)) {
      return [
        'success' => false,
        'code' => 'missing_reorder_fields',
        'message' => 'Order save request was incomplete.',
        'status' => 400,
      ];
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      return [
        'success' => false,
        'code' => 'profile_taxonomy_not_found',
        'message' => 'Profile Taxonomy not found.',
        'status' => 404,
      ];
    }

    $current_revision = self::get_order_revision($framework_id, $parent_uuid);

    if ($submitted_revision !== $current_revision) {
      return [
        'success' => false,
        'code' => 'stale_order',
        'message' => 'Order changed elsewhere.',
        'status' => 409,
        'current_revision' => $current_revision,
      ];
    }

    $tree = self::get_framework_tree($framework);
    $reordered = self::reorder_children_by_parent_uuid($tree, $parent_uuid, $submitted_order);

    if (!$reordered) {
      return [
        'success' => false,
        'code' => 'invalid_reorder',
        'message' => 'Unable to reorder this sibling group.',
        'status' => 400,
      ];
    }

    self::bump_order_revision($framework_id, $parent_uuid);

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    if (empty($compile_result['success'])) {
      return [
        'success' => false,
        'code' => 'compile_failed',
        'message' => 'Order was saved, but runtime tables could not be rebuilt.',
        'status' => 500,
        'revision' => self::get_order_revision($framework_id, $parent_uuid),
      ];
    }

    return [
      'success' => true,
      'revision' => self::get_order_revision($framework_id, $parent_uuid),
    ];
  }

  private static function normalize_tree_children(array &$node): void
  {
    if (empty($node['children']) || !is_array($node['children'])) {
      $node['children'] = [];
      return;
    }

    $normalized = [];

    foreach ($node['children'] as $child) {
      if (!is_array($child)) {
        continue;
      }

      self::normalize_tree_children($child);
      $normalized[] = $child;
    }

    $node['children'] = array_values($normalized);
  }

  private static function reorder_children_by_parent_uuid(array &$node, string $parent_uuid, array $ordered_uuids): bool
  {
    if (($node['uuid'] ?? '') === $parent_uuid) {
      if (empty($node['children']) || !is_array($node['children'])) {
        return false;
      }

      $ordered_uuids = array_values(array_unique(array_filter(array_map('strval', $ordered_uuids))));
      $children_by_uuid = [];
      $existing_uuids = [];

      foreach ($node['children'] as $child) {
        if (!is_array($child)) {
          continue;
        }

        $child_uuid = (string) ($child['uuid'] ?? '');

        if ($child_uuid === '') {
          continue;
        }

        $children_by_uuid[$child_uuid] = $child;
        $existing_uuids[] = $child_uuid;
      }

      sort($existing_uuids);
      $submitted_sorted = $ordered_uuids;
      sort($submitted_sorted);

      if ($existing_uuids !== $submitted_sorted) {
        return false;
      }

      $reordered = [];

      foreach ($ordered_uuids as $uuid) {
        $reordered[] = $children_by_uuid[$uuid];
      }

      $node['children'] = array_values($reordered);
      return true;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$child) {
      if (!is_array($child)) {
        continue;
      }

      if (self::reorder_children_by_parent_uuid($child, $parent_uuid, $ordered_uuids)) {
        unset($child);
        return true;
      }
    }

    unset($child);
    return false;
  }

  private static function get_order_revision(int $framework_id, string $parent_uuid): int
  {
    $revisions = get_option('cfm_order_revisions', []);

    if (!is_array($revisions)) {
      return 0;
    }

    $key = self::order_revision_key($framework_id, $parent_uuid);

    return isset($revisions[$key]) ? max(0, (int) $revisions[$key]) : 0;
  }

  private static function bump_order_revision(int $framework_id, string $parent_uuid): void
  {
    if ($framework_id <= 0 || $parent_uuid === '') {
      return;
    }

    $revisions = get_option('cfm_order_revisions', []);

    if (!is_array($revisions)) {
      $revisions = [];
    }

    $key = self::order_revision_key($framework_id, $parent_uuid);
    $revisions[$key] = isset($revisions[$key]) ? ((int) $revisions[$key] + 1) : 1;

    update_option('cfm_order_revisions', $revisions, false);
  }

  private static function bump_all_order_revisions(int $framework_id, array $tree): void
  {
    $groups = [];
    self::collect_ordering_groups($tree, $groups);

    foreach ($groups as $group) {
      $parent_uuid = (string) ($group['parent_uuid'] ?? '');

      if ($parent_uuid !== '') {
        self::bump_order_revision($framework_id, $parent_uuid);
      }
    }
  }

  private static function order_revision_key(int $framework_id, string $parent_uuid): string
  {
    return 'framework:' . $framework_id . '|parent:' . $parent_uuid;
  }

  private static function render_ordering_controls(int $framework_id, array $tree): void
  {
    $groups = [];
    self::collect_ordering_groups($tree, $groups);

    if (empty($groups)) {
      echo '<p>No sortable sibling groups yet.</p>';
      return;
    }

    echo '<div class="cfm-ordering-groups" style="max-width: 900px;">';

    foreach ($groups as $index => $group) {
      $parent_uuid = (string) ($group['parent_uuid'] ?? '');
      $children = isset($group['children']) && is_array($group['children']) ? $group['children'] : [];

      if ($parent_uuid === '' || count($children) < 2) {
        continue;
      }

      $revision = self::get_order_revision($framework_id, $parent_uuid);
      $list_id = 'cfm-sortable-' . $index;

      echo '<form method="post" class="cfm-order-form" style="background:#fff; border:1px solid #ccd0d4; padding:12px; margin:0 0 14px;">';
      wp_nonce_field('cfm_reorder_terms', 'cfm_nonce');
      echo '<input type="hidden" name="cfm_action" value="reorder_terms">';
      echo '<input type="hidden" name="framework_id" value="' . esc_attr((string) $framework_id) . '">';
      echo '<input type="hidden" name="parent_uuid" value="' . esc_attr($parent_uuid) . '">';
      echo '<input type="hidden" name="order_revision" value="' . esc_attr((string) $revision) . '">';
      echo '<h3 style="margin-top:0;">' . esc_html((string) ($group['label'] ?? 'Sibling Group')) . '</h3>';
      echo '<p class="description">Drag direct children into the desired order. Changes save automatically on drop; parent changes still use Move.</p>';
      echo '<ul id="' . esc_attr($list_id) . '" class="cfm-sortable-list" style="margin:0 0 10px; max-width:520px;">';

      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $child_uuid = (string) ($child['uuid'] ?? '');

        if ($child_uuid === '') {
          continue;
        }

        echo '<li style="cursor:move; background:#f6f7f7; border:1px solid #ccd0d4; padding:8px 10px; margin:0 0 6px;">';
        echo '<span aria-hidden="true" style="color:#646970; margin-right:6px;">☰</span>';
        echo '<strong>' . esc_html((string) ($child['label'] ?? '')) . '</strong> ';
        echo '<code>' . esc_html((string) ($child['slug'] ?? '')) . '</code>';
        echo '<input type="hidden" name="term_order[]" value="' . esc_attr($child_uuid) . '">';
        echo '</li>';
      }

      echo '</ul>';
      echo '<p class="cfm-order-status description" aria-live="polite">Drag to reorder. Saved automatically.</p>';
      echo '<noscript>';
      submit_button('Save Order', 'secondary', 'submit', false);
      echo '</noscript>';
      echo '</form>';
    }

    echo '</div>';
?>
    <script>
      jQuery(function($) {
        $('.cfm-sortable-list').sortable({
          axis: 'y',
          containment: 'parent',
          tolerance: 'pointer',
          update: function() {
            var $list = $(this);
            var $form = $list.closest('.cfm-order-form');
            var $status = $form.find('.cfm-order-status');
            var requestData = $.grep($form.serializeArray(), function(field) {
              return field.name !== 'cfm_action';
            });

            requestData.push({
              name: 'action',
              value: 'cfm_reorder_terms'
            });

            function reloadOrderingUrl() {
              var base = window.location.href.split('#')[0];
              var separator = base.indexOf('?') === -1 ? '?' : '&';
              return base + separator + 'cfm_order_reload=' + Date.now() + '#cfm-ordering';
            }

            function setReloadStatus(message) {
              $status.empty()
                .append(document.createTextNode(message + ' '))
                .append($('<a>', {
                  href: reloadOrderingUrl(),
                  text: 'Reload ordering'
                }));
            }

            function setFailureStatus(data, fallbackMessage) {
              var message = data && data.message ? data.message : fallbackMessage;
              var code = data && data.code ? data.code : '';

              if (code === 'stale_order') {
                $list.data('cfm-stale-order', true);
                setReloadStatus(message || 'Order changed elsewhere.');
                return;
              }

              $status.text(message);
            }

            $list.data('cfm-stale-order', false);
            $list.sortable('disable');
            $status.text('Saving...');

            $.post(ajaxurl, requestData)
              .done(function(response) {
                if (response && response.success && response.data && typeof response.data.revision !== 'undefined') {
                  $form.find('input[name="order_revision"]').val(response.data.revision);
                  $list.data('cfm-stale-order', false);
                  $status.text(response.data.message || '✓ Saved');
                  return;
                }

                setFailureStatus(response && response.data ? response.data : null, 'Order could not be saved.');
              })
              .fail(function(xhr) {
                setFailureStatus(
                  xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : null,
                  'Order could not be saved.'
                );
              })
              .always(function() {
                if (!$list.data('cfm-stale-order')) {
                  $list.sortable('enable');
                }
              });
          }
        });
      });
    </script>
  <?php
  }

  private static function collect_ordering_groups(array $node, array &$groups, string $path = ''): void
  {
    $label = (string) ($node['label'] ?? 'Root');
    $type = (string) ($node['type'] ?? '');
    $uuid = (string) ($node['uuid'] ?? '');
    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];

    if ($path === '' && $type === 'framework') {
      $current_path = '';
    } elseif ($path === '') {
      $current_path = $label;
    } else {
      $current_path = $path . ' › ' . $label;
    }

    if ($uuid !== '' && $type !== 'framework' && count($children) > 1) {
      $groups[] = [
        'parent_uuid' => $uuid,
        'label' => $current_path,
        'children' => $children,
      ];
    }

    foreach ($children as $child) {
      if (is_array($child)) {
        self::collect_ordering_groups($child, $groups, $current_path);
      }
    }
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
      wp_die('Profile profile taxonomy not found.');
    }

    $version = CFM_Framework_Repository::get_version((int) $framework->id, $version_id);

    if (!$version) {
      wp_die('Version not found.');
    }

    $tree = json_decode((string) $version->tree_json, true);

    if (!is_array($tree)) {
      wp_die('Stored tree JSON could not be decoded. Restore aborted.');
    }

    self::bump_all_order_revisions((int) $framework->id, $tree);

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

    if ($framework) {
      $_GET['framework_id'] = (int) $framework->id;
      self::render_framework_edit_page();
      return;
    }

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
          <p>Profile taxonomy created.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Name and slug are required.</p>
        </div>
      <?php endif; ?>

      <p>
        Define the profile taxonomy used across Teachers.Net. Other plugins and themes can consume these profile terms through Profilaxes APIs.
      </p>

      <?php if (!$framework) : ?>
        <div class="card" style="max-width: 760px;">
          <h2>Profile Taxonomy</h2>
          <p>
            This site does not have a profile taxonomy yet. Create the primary site profile taxonomy before adding axes or terms.
          </p>

          <form method="post">
            <?php wp_nonce_field('cfm_create_framework', 'cfm_nonce'); ?>
            <input type="hidden" name="cfm_action" value="create_framework">
            <input type="hidden" name="cfm_name" value="Sandbox Profiles">
            <input type="hidden" name="cfm_slug" value="primary">
            <input type="hidden" name="cfm_description" value="Primary site profile taxonomy.">

            <?php submit_button('Create Profile Taxonomy'); ?>
          </form>
        </div>
      <?php else : ?>
        <div class="card" style="max-width: 760px;">
          <h2>Current Profile Taxonomy</h2>

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
              Manage Profile Taxonomy
            </a>

            <?php if (!empty($active_version)) : ?>
              <form method="post" style="display: inline-block; margin-left: 8px;">
                <?php wp_nonce_field('cfm_compile_active_version', 'cfm_nonce'); ?>
                <input type="hidden" name="cfm_action" value="compile_active_version">
                <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">
                <?php submit_button('Rebuild Profile Taxonomy', 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($framework_count > 1) : ?>
            <p class="description">
              Maintenance note: additional internal profile taxonomy records exist. The normal admin flow uses the primary profile taxonomy only.
            </p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php
  }

  private static function import_preview_transient_key(int $framework_id): string
  {
    return 'cfm_import_preview_' . get_current_user_id() . '_' . $framework_id;
  }

  private static function build_taxonomy_import_preview(array $import, array $current_tree): array
  {
    $warnings = [];
    $errors = [];

    $export_type = (string) ($import['export_type'] ?? '');
    $valid_export_types = [
      'profilaxes_profile_taxonomy',
      'profilaxes_taxonomy',
    ];

    if (!in_array($export_type, $valid_export_types, true)) {
      $errors[] = 'Export type is missing or not recognized as a Profilaxes profile taxonomy export.';
    }

    $schema_version = isset($import['export_schema_version']) ? (int) $import['export_schema_version'] : 0;

    if ($schema_version !== 1) {
      $warnings[] = 'This preview was built for export schema version 1. Review carefully before a future write-capable import is used.';
    }

    $import_tree = [];

    if (!empty($import['tree']) && is_array($import['tree'])) {
      $import_tree = $import['tree'];
    } elseif (!empty($import['taxonomy_tree']) && is_array($import['taxonomy_tree'])) {
      $import_tree = $import['taxonomy_tree'];
    } else {
      $errors[] = 'No taxonomy tree was found in the uploaded JSON.';
    }

    if (!empty($import_tree)) {
      self::normalize_tree_children($import_tree);
    }

    $import_counts = !empty($import_tree)
      ? self::count_profile_tree_nodes($import_tree)
      : ['axes' => 0, 'terms' => 0];

    $current_counts = self::count_profile_tree_nodes($current_tree);
    $import_uuids = !empty($import_tree) ? self::collect_node_uuids($import_tree) : [];
    $current_uuids = self::collect_node_uuids($current_tree);
    $duplicate_import_uuids = self::find_duplicate_values($import_uuids);
    $uuid_collisions = array_values(array_intersect(array_unique($import_uuids), array_unique($current_uuids)));
    $archived_count = !empty($import_tree) ? self::count_archived_nodes($import_tree) : 0;

    if (!empty($duplicate_import_uuids)) {
      $errors[] = 'The uploaded taxonomy contains duplicate UUIDs. A write-capable import should reject this file.';
    }

    if (!empty($uuid_collisions)) {
      $warnings[] = 'Some uploaded UUIDs already exist in the current taxonomy. This is expected when previewing an export from this same site.';
    }

    if (empty($errors)) {
      $warnings[] = 'Preview only. No database rows were changed.';
    }

    $framework = [];

    if (!empty($import['framework']) && is_array($import['framework'])) {
      $framework = $import['framework'];
    }

    $active_version = [];

    if (!empty($import['active_version']) && is_array($import['active_version'])) {
      $active_version = $import['active_version'];
    }

    return [
      'is_valid' => empty($errors),
      'errors' => $errors,
      'warnings' => $warnings,
      'export_type' => $export_type,
      'export_schema_version' => $schema_version,
      'exported_at' => (string) ($import['exported_at'] ?? ''),
      'site_url' => (string) ($import['site_url'] ?? ''),
      'plugin_version' => isset($import['plugin']['version']) ? (string) $import['plugin']['version'] : '',
      'framework_name' => (string) ($framework['name'] ?? ''),
      'framework_slug' => (string) ($framework['slug'] ?? ''),
      'framework_uuid' => (string) ($framework['uuid'] ?? ''),
      'active_version_number' => isset($active_version['version_number']) ? (int) $active_version['version_number'] : null,
      'import_counts' => $import_counts,
      'current_counts' => $current_counts,
      'archived_count' => $archived_count,
      'uuid_total' => count($import_uuids),
      'uuid_collision_count' => count($uuid_collisions),
      'uuid_collision_samples' => array_slice($uuid_collisions, 0, 8),
      'duplicate_uuid_count' => count($duplicate_import_uuids),
      'duplicate_uuid_samples' => array_slice($duplicate_import_uuids, 0, 8),
    ];
  }

  private static function find_duplicate_values(array $values): array
  {
    $seen = [];
    $duplicates = [];

    foreach ($values as $value) {
      $value = (string) $value;

      if ($value === '') {
        continue;
      }

      if (isset($seen[$value])) {
        $duplicates[] = $value;
        continue;
      }

      $seen[$value] = true;
    }

    return array_values(array_unique($duplicates));
  }

  private static function count_archived_nodes(array $node): int
  {
    $count = 0;
    $status = (string) ($node['status'] ?? '');

    if (!empty($node['archived']) || !empty($node['archived_at']) || $status === 'archived') {
      $count++;
    }

    $children = $node['children'] ?? [];

    if (is_array($children)) {
      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $count += self::count_archived_nodes($child);
      }
    }

    return $count;
  }

  private static function render_taxonomy_import_preview(array $preview): void
  {
  ?>
    <div class="notice <?php echo !empty($preview['is_valid']) ? 'notice-info' : 'notice-error'; ?>" style="padding: 12px 16px; margin-top: 12px;">
      <h3 style="margin-top: 0;">Import Preview</h3>

      <?php if (empty($preview['is_valid'])) : ?>
        <p><strong>This file is not ready for import.</strong></p>
      <?php else : ?>
        <p><strong>Preview only.</strong> No database rows were changed. The import action remains disabled in this milestone.</p>
      <?php endif; ?>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 220px;">Export Type</th>
            <td><code><?php echo esc_html((string) ($preview['export_type'] ?? '')); ?></code></td>
          </tr>
          <tr>
            <th>Export Schema Version</th>
            <td><?php echo esc_html((string) ($preview['export_schema_version'] ?? '')); ?></td>
          </tr>
          <tr>
            <th>Export Date</th>
            <td><?php echo esc_html((string) ($preview['exported_at'] ?? '')); ?></td>
          </tr>
          <tr>
            <th>Source Site</th>
            <td><?php echo esc_html((string) ($preview['site_url'] ?? '')); ?></td>
          </tr>
          <tr>
            <th>Framework</th>
            <td>
              <?php echo esc_html((string) ($preview['framework_name'] ?? '')); ?>
              <?php if (!empty($preview['framework_slug'])) : ?>
                (<code><?php echo esc_html((string) $preview['framework_slug']); ?></code>)
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th>Framework UUID</th>
            <td><code><?php echo esc_html((string) ($preview['framework_uuid'] ?? '')); ?></code></td>
          </tr>
          <tr>
            <th>Active Version</th>
            <td><?php echo esc_html(isset($preview['active_version_number']) && $preview['active_version_number'] !== null ? 'v' . $preview['active_version_number'] : 'Unknown'); ?></td>
          </tr>
          <tr>
            <th>Uploaded Profile Categories</th>
            <td><?php echo esc_html((string) ($preview['import_counts']['axes'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Uploaded Terms</th>
            <td><?php echo esc_html((string) ($preview['import_counts']['terms'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Archived Terms</th>
            <td><?php echo esc_html((string) ($preview['archived_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Current Profile Categories</th>
            <td><?php echo esc_html((string) ($preview['current_counts']['axes'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Current Terms</th>
            <td><?php echo esc_html((string) ($preview['current_counts']['terms'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>UUIDs in Upload</th>
            <td><?php echo esc_html((string) ($preview['uuid_total'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>UUID Collisions with Current Tree</th>
            <td><?php echo esc_html((string) ($preview['uuid_collision_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Duplicate UUIDs in Upload</th>
            <td><?php echo esc_html((string) ($preview['duplicate_uuid_count'] ?? 0)); ?></td>
          </tr>
        </tbody>
      </table>

      <?php if (!empty($preview['uuid_collision_samples']) && is_array($preview['uuid_collision_samples'])) : ?>
        <p><strong>Collision samples:</strong></p>
        <ul>
          <?php foreach ($preview['uuid_collision_samples'] as $uuid) : ?>
            <li><code><?php echo esc_html((string) $uuid); ?></code></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($preview['errors']) && is_array($preview['errors'])) : ?>
        <p><strong>Errors:</strong></p>
        <ul>
          <?php foreach ($preview['errors'] as $error) : ?>
            <li><?php echo esc_html((string) $error); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if (!empty($preview['warnings']) && is_array($preview['warnings'])) : ?>
        <p><strong>Warnings:</strong></p>
        <ul>
          <?php foreach ($preview['warnings'] as $warning) : ?>
            <li><?php echo esc_html((string) $warning); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <p>
        <a class="button" href="<?php echo esc_url(remove_query_arg('cfm_import_preview') . '#cfm-import'); ?>">Cancel</a>
        <button type="button" class="button button-primary" disabled>Import disabled — preview only</button>
      </p>
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


  private static function export_taxonomy_url(int $framework_id): string
  {
    $url = add_query_arg([
      'page' => 'cfm-frameworks',
      'cfm_action' => 'export_taxonomy',
      'framework_id' => $framework_id,
    ], admin_url('admin.php'));

    return wp_nonce_url($url, 'cfm_export_taxonomy_' . $framework_id);
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
      wp_die('Profile profile taxonomy not found.');
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
          ← Back to Profile Taxonomy
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
      wp_die('Profile profile taxonomy not found.');
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
                  ); ?>">Back to Profile Taxonomy</a>
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
      wp_die('Profile profile taxonomy not found.');
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
        Restore Profile Version: <?php echo esc_html($framework->name); ?>
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
            <th style="width: 180px;">Profile Taxonomy</th>
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
      wp_die('Profile profile taxonomy not found.');
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
                  ); ?>">← Back to Profile Taxonomy</a>
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
            <th style="width: 180px;">Profile Taxonomy</th>
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
      wp_die('Profile profile taxonomy not found.');
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
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . (int) $framework->id)); ?>">← Back to Profile Taxonomy</a>
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
      wp_die('Profile profile taxonomy not found.');
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
                  ); ?>">← Back to Profile Taxonomy</a>
      </p>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Profile Taxonomy</th>
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
      wp_die('Profile profile taxonomy not found.');
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
      <h1>Compiled Query Debug</h1>

      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . (int) $framework->id)); ?>">
          ← Back to Profile Taxonomy
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
              <th style="width: 180px;">Profile Taxonomy Slug</th>
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
      wp_die('Profile profile taxonomy not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = $tree['children'] ?? [];
    $ordering_reload_url = admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=edit'
        . '&framework_id=' . $framework_id
        . '&cfm_order_reload=' . time()
        . '#cfm-ordering'
    );

    $import_preview = null;

    if (isset($_GET['cfm_import_preview'])) {
      $maybe_preview = get_transient(self::import_preview_transient_key($framework_id));

      if (is_array($maybe_preview)) {
        $import_preview = $maybe_preview;
      }
    }

  ?>
    <div class="wrap">
      <h1>Profile Taxonomy</h1>

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

      <?php if (isset($_GET['cfm_terms_reordered'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term order saved and runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_term_archived'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term archived from the active tree. Historical versions are unchanged.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_version_restored'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Profile version restored by creating a new active version.</p>
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

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'duplicate_sibling_slug') : ?>
        <div class="notice notice-error is-dismissible">
          <p>That slug already exists under the selected parent. Choose a different slug or move the existing term first.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'archive_has_assignments') : ?>
        <div class="notice notice-error is-dismissible">
          <p>This term cannot be archived because it or one of its descendants has active user assignments. Move or reassign users first.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_reorder_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Order save request was incomplete. <a href="<?php echo esc_url($ordering_reload_url); ?>">Reload ordering</a>.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'stale_order') : ?>
        <div class="notice notice-warning is-dismissible">
          <p>Order changed elsewhere. <a href="<?php echo esc_url($ordering_reload_url); ?>">Reload ordering</a>.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'invalid_reorder') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Unable to reorder this sibling group. <a href="<?php echo esc_url($ordering_reload_url); ?>">Reload ordering</a>.</p>
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

      <?php if (isset($_GET['cfm_error']) && in_array($_GET['cfm_error'], ['missing_import_framework', 'missing_import_file', 'import_upload_failed', 'import_file_too_large', 'import_file_empty', 'import_invalid_json'], true)) : ?>
        <div class="notice notice-error is-dismissible">
          <p>Import preview could not be generated. Confirm you selected a valid Profilaxes taxonomy JSON export and try again.</p>
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
          <?php submit_button('Rebuild Profile Taxonomy', 'secondary', 'submit', false); ?>
          <a class="button" href="<?php echo esc_url(self::compiled_debug_url((int) $framework->id)); ?>" style="margin-left: 8px;">Open Compiled Query Debug</a>
        </form>
      <?php endif; ?>

      <hr>

      <h2>Export</h2>
      <p class="description">
        Download the canonical editable profile taxonomy tree as JSON. This export preserves UUIDs, hierarchy, order, archive state when present, and active version metadata. Runtime compiler tables are intentionally not exported because they can be rebuilt.
      </p>
      <p>
        <a class="button" href="<?php echo esc_url(self::export_taxonomy_url((int) $framework->id)); ?>">
          Export Profile Taxonomy JSON
        </a>
      </p>

      <hr>

      <h2 id="cfm-import">Import</h2>
      <p class="description">
        Upload a Profilaxes taxonomy JSON export to validate it and preview what it contains. This milestone does not write imported taxonomy data.
      </p>

      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('cfm_import_taxonomy_preview', 'cfm_nonce'); ?>
        <input type="hidden" name="cfm_action" value="import_taxonomy_preview">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="taxonomy_import_file">Import Profile Taxonomy JSON</label>
            </th>
            <td>
              <input name="taxonomy_import_file" id="taxonomy_import_file" type="file" accept="application/json,.json" required>
              <p class="description">Preview only. No taxonomy rows, compiled rows, or user assignments are changed.</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Preview Import', 'secondary', 'submit', false); ?>
      </form>

      <?php if (is_array($import_preview)) : ?>
        <?php self::render_taxonomy_import_preview($import_preview); ?>
      <?php endif; ?>

      <hr>

      <h2 id="cfm-existing-terms">Profile Taxonomy Tree</h2>

      <?php if (empty($axes)) : ?>
        <p>No axes created yet.</p>
      <?php else : ?>
        <table class="widefat striped" style="max-width: 1000px;">
          <thead>
            <tr>
              <th>Term</th>
              <th>Slug</th>
              <th>Identifier</th>
              <th>Child Terms</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($axes as $axis) : ?>
              <tr>
                <td><strong><?php echo esc_html($axis['label'] ?? ''); ?></strong></td>
                <td><code><?php echo esc_html($axis['slug'] ?? ''); ?></code></td>
                <td>
                  <details>
                    <summary>UUID</summary><code><?php echo esc_html($axis['uuid'] ?? ''); ?></code>
                  </details>
                </td>
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

      <h2 id="cfm-ordering">Ordering</h2>
      <p class="description">Ordering is sibling-scoped. Dragging changes display order only; use Move to change parentage.</p>
      <?php self::render_ordering_controls((int) $framework->id, $tree); ?>

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
