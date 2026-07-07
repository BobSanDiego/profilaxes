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
    add_action('wp_ajax_cfm_move_branch', [__CLASS__, 'handle_ajax_move_branch']);
  }

  public static function register_menu(): void
  {
    add_menu_page(
      'Core Terms',
      'Core Terms',
      'manage_options',
      'cfm-frameworks',
      [__CLASS__, 'render_frameworks_page'],
      'dashicons-groups',
      58
    );

    add_submenu_page(
      'cfm-frameworks',
      'Core Terms Editor',
      'Editor',
      'manage_options',
      'cfm-frameworks&action=editor',
      [__CLASS__, 'render_frameworks_page']
    );

    add_submenu_page(
      'cfm-frameworks',
      'Core Terms Archives',
      'Archives',
      'manage_options',
      'cfm-frameworks&action=archived_terms',
      [__CLASS__, 'render_frameworks_page']
    );

    add_submenu_page(
      'cfm-frameworks',
      'Core Terms Data',
      'Data',
      'manage_options',
      'cfm-frameworks&action=data',
      [__CLASS__, 'render_frameworks_page']
    );

    add_submenu_page(
      'cfm-frameworks',
      'Core Terms Meta-Groups',
      'Meta-Groups',
      'manage_options',
      'cfm-frameworks&action=meta_groups',
      [__CLASS__, 'render_frameworks_page']
    );

    add_submenu_page(
      'cfm-frameworks',
      'Core Terms Maintenance',
      'Maintenance',
      'manage_options',
      'cfm-frameworks&action=maintenance',
      [__CLASS__, 'render_frameworks_page']
    );

    global $submenu;

    if (isset($submenu['cfm-frameworks'][0][0])) {
      $submenu['cfm-frameworks'][0][0] = 'Dashboard';
    }
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

    if ($action === 'import_taxonomy_replace') {
      self::handle_import_taxonomy_replace();
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

    if ($action === 'add_terms_batch') {
      self::handle_add_terms_batch();
      return;
    }

    if ($action === 'install_example_pack') {
      self::handle_install_example_pack();
      return;
    }

    if ($action === 'add_meta_group') {
      self::handle_add_meta_group();
      return;
    }

    if ($action === 'update_meta_group') {
      self::handle_update_meta_group();
      return;
    }

    if ($action === 'update_term') {
      self::handle_update_term();
      return;
    }

    if ($action === 'core_terms_editor_save') {
      self::handle_core_terms_editor_save();
      return;
    }

    if ($action === 'core_terms_editor_archive') {
      self::handle_core_terms_editor_archive();
      return;
    }

    if ($action === 'core_terms_editor_undo_archive') {
      self::handle_core_terms_editor_undo_archive();
      return;
    }

    if ($action === 'core_terms_archive_restore') {
      self::handle_core_terms_archive_restore();
      return;
    }

    if ($action === 'core_terms_archive_delete') {
      self::handle_core_terms_archive_delete();
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
      wp_die('You do not have permission to export this Core Terms definition.');
    }

    $framework_id = isset($_GET['framework_id']) ? absint($_GET['framework_id']) : 0;

    if ($framework_id <= 0) {
      wp_die('Missing Core Terms definition ID.');
    }

    check_admin_referer('cfm_export_taxonomy_' . $framework_id);

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $active_version = CFM_Framework_Repository::get_active_version($framework_id);
    $tree = self::get_framework_tree($framework);
    self::normalize_tree_children($tree);

    $export = [
      'export_type' => 'profilaxes_profile_taxonomy',
      'export_schema_version' => 2,
      'exported_at' => current_time('mysql'),
      'site_url' => site_url(),
      'plugin' => [
        'name' => 'Core Terms',
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
          'cfm_term_relationships',
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
      wp_die('You do not have permission to import this Core Terms definition.');
    }

    check_admin_referer('cfm_import_taxonomy_preview', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);

    if ($framework_id <= 0) {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&cfm_error=missing_import_framework'));
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    if (empty($_FILES['taxonomy_import_file']) || !is_array($_FILES['taxonomy_import_file'])) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=missing_import_file#cfm-import');
      exit;
    }

    $file = $_FILES['taxonomy_import_file'];
    $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

    if ($upload_error !== UPLOAD_ERR_OK) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_upload_failed#cfm-import');
      exit;
    }

    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    $size = isset($file['size']) ? (int) $file['size'] : 0;

    if ($tmp_name === '' || !is_uploaded_file($tmp_name) || $size <= 0) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=missing_import_file#cfm-import');
      exit;
    }

    if ($size > 2 * 1024 * 1024) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_file_too_large#cfm-import');
      exit;
    }

    $raw_json = file_get_contents($tmp_name);

    if (!is_string($raw_json) || trim($raw_json) === '') {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_file_empty#cfm-import');
      exit;
    }

    $decoded = json_decode($raw_json, true);

    if (!is_array($decoded)) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_invalid_json#cfm-import');
      exit;
    }

    $current_tree = self::get_framework_tree($framework);
    self::normalize_tree_children($current_tree);

    $preview = self::build_taxonomy_import_preview($decoded, $current_tree);
    set_transient(self::import_preview_transient_key($framework_id), $preview, 10 * MINUTE_IN_SECONDS);

    wp_safe_redirect(self::data_url($framework_id) . '&cfm_import_preview=1#cfm-import-preview');
    exit;
  }


  private static function handle_import_taxonomy_replace(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to import this Core Terms definition.');
    }

    check_admin_referer('cfm_import_taxonomy_replace', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);

    if ($framework_id <= 0) {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&cfm_error=missing_import_framework'));
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $confirmed = !empty($_POST['confirm_replace_taxonomy']) && (string) $_POST['confirm_replace_taxonomy'] === '1';

    if (!$confirmed) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_replace_not_confirmed#cfm-import');
      exit;
    }

    $preview = get_transient(self::import_preview_transient_key($framework_id));

    if (!is_array($preview) || empty($preview['is_valid']) || empty($preview['tree']) || !is_array($preview['tree'])) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_preview_expired#cfm-import');
      exit;
    }

    $import_tree = $preview['tree'];
    self::normalize_tree_children($import_tree);

    $current_tree = self::get_framework_tree($framework);
    self::normalize_tree_children($current_tree);

    $current_snapshot_id = CFM_Framework_Repository::create_version($framework_id, $current_tree, 'pre_import_snapshot');

    if ($current_snapshot_id <= 0) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_snapshot_failed#cfm-import');
      exit;
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $import_tree);

    delete_transient(self::import_preview_transient_key($framework_id));

    if (empty($compile_result['success'])) {
      wp_safe_redirect(self::data_url($framework_id) . '&cfm_error=import_compile_failed' . $compile_result['query_arg'] . '#cfm-import');
      exit;
    }

    wp_safe_redirect(
      self::data_url($framework_id)
        . '&cfm_import_replaced=1'
        . '&cfm_import_snapshot_id=' . (int) $current_snapshot_id
        . $compile_result['query_arg']

    );
    exit;
  }


  private static function handle_compile_active_version(): void
  {
    check_admin_referer('cfm_compile_active_version', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);

    if ($framework_id <= 0) {
      wp_die('Missing Core Terms definition ID.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $active_version = CFM_Framework_Repository::get_active_version($framework_id);

    if (!$active_version) {
      wp_safe_redirect(
        self::maintenance_url($framework_id)
          . '&cfm_error=no_active_version'
      );
      exit;
    }

    $result = CFM_Compiler::compile_version($framework_id, (int) $active_version->id);

    $query_arg = !empty($result['success'])
      ? '&cfm_compiled=1'
      : '&cfm_error=compile_failed';

    wp_safe_redirect(
      self::maintenance_url($framework_id)
        . $query_arg
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

  private static function handle_install_example_pack(): void
  {
    check_admin_referer('cfm_install_example_pack', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $pack = sanitize_key(wp_unslash($_POST['example_pack'] ?? ''));

    if ($framework_id <= 0 || !class_exists('CFM_Seeder') || !CFM_Seeder::is_valid_pack($pack)) {
      wp_safe_redirect(
        self::data_url($framework_id)
            . '&cfm_error=invalid_example_pack'
            . '#cfm-example-packs'
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $result = CFM_Seeder::apply_pack($tree, $pack);
    $created = (int) ($result['created'] ?? 0);
    $skipped = (int) ($result['skipped'] ?? 0);

    if ($created > 0) {
      self::bump_order_revision($framework_id, (string) (($result['tree']['uuid'] ?? '') ?: ($tree['uuid'] ?? '')));
      $compile_result = self::save_active_tree_and_compile($framework_id, $result['tree']);
    } else {
      $compile_result = [
        'query_arg' => '',
      ];
    }

    wp_safe_redirect(
      self::data_url($framework_id)
          . '&cfm_example_pack_installed=' . rawurlencode($pack)
          . '&cfm_example_created=' . $created
          . '&cfm_example_skipped=' . $skipped
          . ($compile_result['query_arg'] ?? '')
          . '#cfm-example-packs'
    );
    exit;
  }

  private static function handle_add_axis(): void
  {
    check_admin_referer('cfm_add_axis', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $axis_label = sanitize_text_field(wp_unslash($_POST['axis_label'] ?? ''));
    $axis_slug_input = wp_unslash($_POST['axis_slug'] ?? '');
    $axis_slug = self::normalize_slug($axis_slug_input !== '' ? (string) $axis_slug_input : $axis_label);
    $axis_short_label = sanitize_text_field(wp_unslash($_POST['axis_short_label'] ?? ''));
    $axis_description = sanitize_textarea_field(wp_unslash($_POST['axis_description'] ?? ''));

    if ($axis_short_label === '') {
      $axis_short_label = $axis_label;
    }

    if ($axis_description === '') {
      $axis_description = $axis_label;
    }

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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $axis = [
      'uuid' => wp_generate_uuid4(),
      'label' => $axis_label,
      'slug' => $axis_slug,
      'short_label' => $axis_short_label,
      'kind' => 'term',
      'description' => $axis_description,
      'children' => [],
    ];

    $tree['children'][] = $axis;

    self::bump_order_revision($framework_id, (string) ($tree['uuid'] ?? ''));

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    do_action('cfm_term_created', $framework_id, (string) $axis['uuid'], $axis, null, $compile_result);

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
    $term_slug_input = wp_unslash($_POST['term_slug'] ?? '');
    $term_slug = self::normalize_slug($term_slug_input !== '' ? (string) $term_slug_input : $term_label);
    $term_short_label = sanitize_text_field(wp_unslash($_POST['term_short_label'] ?? ''));
    $term_description = sanitize_textarea_field(wp_unslash($_POST['term_description'] ?? ''));

    if ($term_short_label === '') {
      $term_short_label = $term_label;
    }

    if ($term_description === '') {
      $term_description = self::default_community_for_label($term_label);
    }

    if ($framework_id <= 0 || $term_label === '' || $term_slug === '') {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_term_fields'
            . '#cfm-add-term'
        )
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $tree_uuid = (string) ($tree['uuid'] ?? '');
    $is_top_level = ($parent_uuid === '' || $parent_uuid === '__top_level__');

    if ($is_top_level) {
      $parent_uuid = $tree_uuid;
    }

    if ($parent_uuid === '') {
      wp_die('Top-level parent not available.');
    }

    if (self::has_child_slug_conflict($tree, $parent_uuid, $term_slug)) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=duplicate_sibling_slug'
            . '&cfm_parent_uuid=' . rawurlencode($is_top_level ? '__top_level__' : $parent_uuid)
            . '#cfm-add-term'
        )
      );
      exit;
    }

    $term = [
      'uuid' => wp_generate_uuid4(),
      'label' => $term_label,
      'slug' => $term_slug,
      'short_label' => $term_short_label,
      'kind' => 'term',
      'description' => $term_description,
      'children' => [],
    ];

    $term_added = self::append_child_to_node_by_uuid($tree, $parent_uuid, $term);

    if (!$term_added) {
      wp_die('Parent not found.');
    }

    self::bump_order_revision($framework_id, $parent_uuid);

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    do_action('cfm_term_created', $framework_id, (string) $term['uuid'], $term, $parent_uuid, $compile_result);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_term_added=1'
          . '&cfm_parent_uuid=' . rawurlencode($is_top_level ? '__top_level__' : $parent_uuid)
          . $compile_result['query_arg']
          . '#cfm-add-term'
      )
    );
    exit;
  }

  private static function handle_add_terms_batch(): void
  {
    check_admin_referer('cfm_add_terms_batch', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $parent_uuid = sanitize_text_field(wp_unslash($_POST['parent_uuid'] ?? ''));
    $batch_input = (string) wp_unslash($_POST['batch_term_labels'] ?? '');

    $raw_labels = preg_split('/\r\n|\r|\n/', $batch_input);
    $terms_to_add = [];
    $seen_slugs = [];

    if (is_array($raw_labels)) {
      foreach ($raw_labels as $raw_label) {
        $label = sanitize_text_field($raw_label);

        if ($label === '') {
          continue;
        }

        $slug = self::normalize_slug($label);

        if ($slug === '') {
          self::redirect_batch_error(
            $framework_id,
            $parent_uuid,
            'One of the batch labels could not produce a valid slug. No terms were added.',
            $batch_input
          );
        }

        if (isset($seen_slugs[$slug])) {
          self::redirect_batch_error(
            $framework_id,
            $parent_uuid,
            'The batch contains duplicate term slugs. No terms were added.',
            $batch_input
          );
        }

        $seen_slugs[$slug] = true;
        $terms_to_add[] = [
          'uuid' => wp_generate_uuid4(),
          'label' => $label,
          'slug' => $slug,
          'short_label' => self::normalize_short_label($label),
          'kind' => 'term',
          'description' => self::default_community_for_label($label),
          'children' => [],
        ];
      }
    }

    if ($framework_id <= 0 || empty($terms_to_add)) {
      self::redirect_batch_error(
        $framework_id,
        $parent_uuid,
        'Enter at least one term label for batch creation. No terms were added.',
        $batch_input
      );
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $tree_uuid = (string) ($tree['uuid'] ?? '');
    $is_top_level = ($parent_uuid === '' || $parent_uuid === '__top_level__');

    if ($is_top_level) {
      $parent_uuid = $tree_uuid;
    }

    if ($parent_uuid === '') {
      wp_die('Top-level parent not available.');
    }

    $parent_info = self::find_node_with_parent($tree, $parent_uuid);

    if (!$parent_info || empty($parent_info['node']) || !is_array($parent_info['node'])) {
      wp_die('Parent not found.');
    }

    $existing_sibling_slugs = self::child_slug_lookup($tree, $parent_uuid);
    $skipped_existing = [];
    $terms_to_create = [];

    foreach ($terms_to_add as $term) {
      $slug = (string) $term['slug'];

      if (isset($existing_sibling_slugs[$slug])) {
        $skipped_existing[] = [
          'label' => (string) $term['label'],
          'slug' => $slug,
        ];
        continue;
      }

      $existing_sibling_slugs[$slug] = true;
      $terms_to_create[] = $term;
    }

    foreach ($terms_to_create as $term) {
      if (!self::append_child_to_node_by_uuid($tree, $parent_uuid, $term)) {
        wp_die('Parent not found.');
      }
    }

    if (!empty($terms_to_create)) {
      self::bump_order_revision($framework_id, $parent_uuid);
      $compile_result = self::save_active_tree_and_compile($framework_id, $tree);
    } else {
      $compile_result = [
        'query_arg' => '',
      ];
    }

    $parent_label = $is_top_level ? 'top level' : (string) ($parent_info['node']['label'] ?? 'selected parent');
    $transient_key = self::batch_added_terms_transient_key($framework_id);

    set_transient($transient_key, [
      'parent_uuid' => $is_top_level ? '__top_level__' : $parent_uuid,
      'parent_label' => $parent_label,
      'terms' => $terms_to_create,
      'created_count' => count($terms_to_create),
      'skipped_existing_count' => count($skipped_existing),
      'errors_count' => 0,
      'skipped_existing' => $skipped_existing,
    ], 5 * MINUTE_IN_SECONDS);

    wp_safe_redirect(
      self::data_url($framework_id)
          . '&cfm_terms_batch_added=1'
          . '&cfm_parent_uuid=' . rawurlencode($is_top_level ? '__top_level__' : $parent_uuid)
          . $compile_result['query_arg']
          . '#cfm-batch-added'
    );
    exit;
  }

  private static function handle_add_meta_group(): void
  {
    check_admin_referer('cfm_add_meta_group', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $label = sanitize_text_field(wp_unslash($_POST['meta_group_label'] ?? ''));
    $slug_input = wp_unslash($_POST['meta_group_slug'] ?? '');
    $slug = self::normalize_slug($slug_input !== '' ? (string) $slug_input : $label);
    $short_label = sanitize_text_field(wp_unslash($_POST['meta_group_short_label'] ?? ''));
    $description = sanitize_textarea_field(wp_unslash($_POST['meta_group_description'] ?? ''));
    $raw_includes = isset($_POST['meta_group_includes']) && is_array($_POST['meta_group_includes'])
      ? wp_unslash($_POST['meta_group_includes'])
      : [];

    $includes = [];

    foreach ($raw_includes as $include_uuid) {
      $include_uuid = sanitize_text_field((string) $include_uuid);

      if ($include_uuid !== '') {
        $includes[] = $include_uuid;
      }
    }

    $includes = array_values(array_unique($includes));

    if ($short_label === '') {
      $short_label = $label;
    }

    if ($description === '') {
      $description = self::default_community_for_label($label);
    }

    if ($framework_id <= 0 || $label === '' || $slug === '' || count($includes) < 2) {
      wp_safe_redirect(
        self::meta_groups_url($framework_id)
            . '&cfm_error=missing_meta_group_fields'
            . '#cfm-meta-groups'
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);

    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $available_terms = self::collect_assignable_term_nodes($tree);
    $available_uuids = [];

    foreach ($available_terms as $term) {
      $uuid = (string) ($term['uuid'] ?? '');

      if ($uuid !== '') {
        $available_uuids[$uuid] = true;
      }
    }

    foreach ($includes as $include_uuid) {
      if (!isset($available_uuids[$include_uuid])) {
        wp_safe_redirect(
          self::meta_groups_url($framework_id)
              . '&cfm_error=invalid_meta_group_includes'
              . '#cfm-meta-groups'
        );
        exit;
      }
    }

    if (self::has_child_slug_conflict($tree, (string) ($tree['uuid'] ?? ''), $slug)) {
      wp_safe_redirect(
        self::meta_groups_url($framework_id)
            . '&cfm_error=duplicate_meta_group_slug'
            . '#cfm-meta-groups'
      );
      exit;
    }

    $tree['children'][] = [
      'uuid' => wp_generate_uuid4(),
      'label' => $label,
      'slug' => $slug,
      'short_label' => $short_label,
      'kind' => 'meta',
      'description' => $description,
      'includes' => $includes,
      'children' => [],
    ];

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(
      self::meta_groups_url($framework_id)
          . '&cfm_meta_group_added=1'
          . $compile_result['query_arg']
          . '#cfm-meta-groups'
    );
    exit;
  }

  private static function handle_update_meta_group(): void
  {
    check_admin_referer('cfm_update_meta_group', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $meta_group_uuid = sanitize_text_field(wp_unslash($_POST['meta_group_uuid'] ?? ''));
    $label = sanitize_text_field(wp_unslash($_POST['meta_group_label'] ?? ''));
    $slug_input = wp_unslash($_POST['meta_group_slug'] ?? '');
    $slug = self::normalize_slug($slug_input !== '' ? (string) $slug_input : $label);
    $short_label = sanitize_text_field(wp_unslash($_POST['meta_group_short_label'] ?? ''));
    $description = sanitize_textarea_field(wp_unslash($_POST['meta_group_description'] ?? ''));
    $raw_includes = isset($_POST['meta_group_includes']) && is_array($_POST['meta_group_includes'])
      ? wp_unslash($_POST['meta_group_includes'])
      : [];

    $includes = [];

    foreach ($raw_includes as $include_uuid) {
      $include_uuid = sanitize_text_field((string) $include_uuid);

      if ($include_uuid !== '') {
        $includes[] = $include_uuid;
      }
    }

    $includes = array_values(array_unique($includes));

    if ($short_label === '') {
      $short_label = $label;
    }

    if ($description === '') {
      $description = self::default_community_for_label($label);
    }

    if ($framework_id <= 0 || $meta_group_uuid === '' || $label === '' || $slug === '' || count($includes) < 2) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit_meta_group'
            . '&framework_id=' . $framework_id
            . '&meta_group_uuid=' . rawurlencode($meta_group_uuid)
            . '&cfm_error=missing_meta_group_fields'
        )
      );
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $meta_group_info = self::find_node_with_parent($tree, $meta_group_uuid);

    if (!$meta_group_info || empty($meta_group_info['node']) || !is_array($meta_group_info['node'])) {
      wp_die('Meta-Group not found.');
    }

    if (self::node_kind($meta_group_info['node']) !== 'meta') {
      wp_die('Only Meta-Groups can be edited here.');
    }

    $available_terms = self::collect_assignable_term_nodes($tree);
    $available_uuids = [];

    foreach ($available_terms as $term) {
      $uuid = (string) ($term['uuid'] ?? '');

      if ($uuid !== '') {
        $available_uuids[$uuid] = true;
      }
    }

    foreach ($includes as $include_uuid) {
      if (!isset($available_uuids[$include_uuid])) {
        wp_safe_redirect(
          admin_url(
            'admin.php?page=cfm-frameworks'
              . '&action=edit_meta_group'
              . '&framework_id=' . $framework_id
              . '&meta_group_uuid=' . rawurlencode($meta_group_uuid)
              . '&cfm_error=invalid_meta_group_includes'
          )
        );
        exit;
      }
    }

    $root_uuid = (string) ($tree['uuid'] ?? '');

    if (self::has_child_slug_conflict($tree, $root_uuid, $slug, $meta_group_uuid)) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit_meta_group'
            . '&framework_id=' . $framework_id
            . '&meta_group_uuid=' . rawurlencode($meta_group_uuid)
            . '&cfm_error=duplicate_meta_group_slug'
        )
      );
      exit;
    }

    if (!self::update_meta_group_by_uuid($tree, $meta_group_uuid, $label, $slug, $short_label, $description, $includes)) {
      wp_die('Meta-Group could not be updated.');
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    wp_safe_redirect(
      self::meta_groups_url($framework_id)
          . '&cfm_meta_group_updated=1'
          . $compile_result['query_arg']
          . '#cfm-meta-groups'
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
    $term_slug_input = wp_unslash($_POST['term_slug'] ?? '');
    $term_slug = self::normalize_slug($term_slug_input !== '' ? (string) $term_slug_input : $term_label);
    $term_short_label = sanitize_text_field(wp_unslash($_POST['term_short_label'] ?? ''));
    $term_description = sanitize_textarea_field(wp_unslash($_POST['term_description'] ?? ''));

    if ($term_short_label === '') {
      $term_short_label = $term_label;
    }

    if ($term_description === '') {
      $term_description = self::default_community_for_label($term_label);
    }

    if ($framework_id <= 0 || $term_uuid === '' || $parent_uuid === '' || $term_label === '' || $term_slug === '') {
      wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&action=edit_term&framework_id=' . $framework_id . '&term_uuid=' . rawurlencode($term_uuid) . '&cfm_error=missing_edit_fields'));
      exit;
    }

    if ($term_uuid === $parent_uuid) {
      wp_die('A term cannot be assigned as its own parent.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);
    $parent_info = self::find_node_with_parent($tree, $parent_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (self::node_kind($term_info['node']) !== 'term') {
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
      $updated = self::update_node_metadata_by_uuid($tree, $term_uuid, $term_label, $term_slug, $term_short_label, $term_description);

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
      $removed_term['short_label'] = $term_short_label;
      $removed_term['description'] = $term_description;

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

    $updated_term = self::find_node_with_parent($tree, $term_uuid);
    do_action(
      'cfm_term_updated',
      $framework_id,
      $term_uuid,
      $updated_term && isset($updated_term['node']) && is_array($updated_term['node']) ? $updated_term['node'] : [],
      $current_parent_uuid,
      $parent_uuid,
      $compile_result
    );

    if ($current_parent_uuid !== $parent_uuid) {
      do_action(
        'cfm_term_moved',
        $framework_id,
        $term_uuid,
        $current_parent_uuid,
        $parent_uuid,
        $updated_term && isset($updated_term['node']) && is_array($updated_term['node']) ? $updated_term['node'] : [],
        $compile_result
      );
    }

    wp_safe_redirect(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . $framework_id . '&cfm_term_updated=1&cfm_parent_uuid=' . rawurlencode($parent_uuid) . $compile_result['query_arg'] . '#cfm-existing-terms'));
    exit;
  }

  private static function handle_core_terms_editor_save(): void
  {
    check_admin_referer('cfm_core_terms_editor_save', 'cfm_nonce');

    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to edit Core Terms.');
    }

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $changes_json = (string) wp_unslash($_POST['cfm_editor_changes'] ?? '');

    if ($framework_id <= 0) {
      wp_die('Core Terms definition not found.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $changes = json_decode($changes_json, true);

    if (!is_array($changes)) {
      $changes = [];
    }

    $tree = self::get_framework_tree($framework);
    $prepared = self::prepare_core_terms_editor_changes($tree, $changes);

    if (!empty($prepared['errors'])) {
      set_transient(self::core_terms_editor_error_transient_key($framework_id), $prepared['errors'], MINUTE_IN_SECONDS);
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_error=1');
      exit;
    }

    if (empty($prepared['changes']) && empty($prepared['new_terms'])) {
      set_transient(self::core_terms_editor_saved_transient_key($framework_id), 0, MINUTE_IN_SECONDS);
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_saved=0');
      exit;
    }

    foreach ($prepared['changes'] as $change) {
      self::update_node_metadata_by_uuid(
        $tree,
        (string) $change['uuid'],
        (string) $change['label'],
        (string) $change['slug'],
        (string) $change['short_label'],
        (string) $change['description']
      );
    }

    $created_terms = [];
    $touched_parent_uuids = [];

    foreach ($prepared['new_terms'] as $new_term) {
      $term = [
        'uuid' => wp_generate_uuid4(),
        'label' => (string) $new_term['label'],
        'slug' => (string) $new_term['slug'],
        'short_label' => (string) $new_term['short_label'],
        'kind' => 'term',
        'description' => (string) $new_term['description'],
        'children' => [],
      ];

      $added = false;

      if ((string) ($new_term['insert_before_uuid'] ?? '') !== '') {
        $added = self::insert_child_before_uuid(
          $tree,
          (string) $new_term['parent_uuid'],
          (string) $new_term['insert_before_uuid'],
          $term
        );
      } elseif ((string) $new_term['insert_after_uuid'] !== '') {
        $added = self::insert_child_after_uuid(
          $tree,
          (string) $new_term['parent_uuid'],
          (string) $new_term['insert_after_uuid'],
          $term
        );
      } elseif ((string) ($new_term['insert_mode'] ?? '') === 'add_child_prepend') {
        $added = self::prepend_child_to_node_by_uuid($tree, (string) $new_term['parent_uuid'], $term);
      } else {
        $added = self::append_child_to_node_by_uuid($tree, (string) $new_term['parent_uuid'], $term);
      }

      if (!$added) {
        set_transient(
          self::core_terms_editor_error_transient_key($framework_id),
          ['One draft Core Term could not be inserted because its parent or sibling no longer exists.'],
          MINUTE_IN_SECONDS
        );
        wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_error=1');
        exit;
      }

      $parent_uuid = (string) $new_term['parent_uuid'];
      $created_terms[] = [
        'uuid' => (string) $term['uuid'],
        'parent_uuid' => $parent_uuid,
        'term' => $term,
      ];

      if ($parent_uuid !== '') {
        $touched_parent_uuids[$parent_uuid] = true;
      }
    }

    foreach (array_keys($touched_parent_uuids) as $parent_uuid) {
      self::bump_order_revision($framework_id, (string) $parent_uuid);
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    if (empty($compile_result['success'])) {
      set_transient(
        self::core_terms_editor_error_transient_key($framework_id),
        ['Core Terms changes could not be fully saved and rebuilt. Review the runtime rebuild status before retrying.'],
        MINUTE_IN_SECONDS
      );
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_error=1' . ($compile_result['query_arg'] ?? ''));
      exit;
    }

    foreach ($prepared['changes'] as $change) {
      $updated_term = self::find_node_with_parent($tree, (string) $change['uuid']);
      do_action(
        'cfm_term_updated',
        $framework_id,
        (string) $change['uuid'],
        $updated_term && isset($updated_term['node']) && is_array($updated_term['node']) ? $updated_term['node'] : [],
        (string) $change['parent_uuid'],
        (string) $change['parent_uuid'],
        $compile_result
      );
    }

    foreach ($created_terms as $new_term) {
      do_action(
        'cfm_term_created',
        $framework_id,
        (string) ($new_term['uuid'] ?? ''),
        isset($new_term['term']) && is_array($new_term['term']) ? $new_term['term'] : [],
        (string) $new_term['parent_uuid'],
        $compile_result
      );
    }

    $saved_count = count($prepared['changes']) + count($prepared['new_terms']);
    set_transient(self::core_terms_editor_saved_transient_key($framework_id), $saved_count, MINUTE_IN_SECONDS);

    wp_safe_redirect(
      self::editor_url($framework_id)
        . '&cfm_editor_saved=' . $saved_count
        . ($compile_result['query_arg'] ?? '')
    );
    exit;
  }

  private static function handle_core_terms_editor_archive(): void
  {
    check_admin_referer('cfm_core_terms_editor_archive', 'cfm_nonce');

    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to archive Core Terms.');
    }

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $term_uuid = sanitize_text_field((string) wp_unslash($_POST['term_uuid'] ?? ''));

    if ($framework_id <= 0 || $term_uuid === '') {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_error=1');
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (self::node_kind($term_info['node']) !== 'term') {
      wp_die('Only Core Terms can be archived in this editor.');
    }

    $archive_uuids = self::collect_node_uuids($term_info['node']);
    $assignment_count = self::count_user_term_assignments($archive_uuids);

    if ($assignment_count > 0) {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_archive_blocked=1&cfm_assignment_count=' . (int) $assignment_count);
      exit;
    }

    $parent_uuid = '';
    $insert_after_uuid = '';

    if (!empty($term_info['parent']) && is_array($term_info['parent'])) {
      $parent_uuid = (string) ($term_info['parent']['uuid'] ?? '');
      $insert_after_uuid = self::previous_child_uuid((array) $term_info['parent'], $term_uuid);
    }

    $removed_term = null;

    if (!self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term) || !is_array($removed_term)) {
      wp_die('Unable to archive term.');
    }

    if ($parent_uuid !== '') {
      self::bump_order_revision($framework_id, $parent_uuid);
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    if (empty($compile_result['success'])) {
      wp_die('Core Term branch was removed but runtime tables could not be rebuilt.');
    }

    $undo_key = wp_generate_password(20, false, false);
    $archived_at = current_time('mysql');
    $archive_id = CFM_Framework_Repository::create_term_archive([
      'archive_key' => $undo_key,
      'framework_id' => $framework_id,
      'root_term_uuid' => $term_uuid,
      'parent_uuid' => $parent_uuid,
      'insert_after_uuid' => $insert_after_uuid,
      'branch' => $removed_term,
      'archived_at' => $archived_at,
    ]);

    if ($archive_id <= 0) {
      wp_die('Core Term branch was archived but durable archive storage could not be written.');
    }

    set_transient(self::core_terms_editor_archive_transient_key($framework_id, $undo_key), [
      'archive_key' => $undo_key,
      'archive_id' => $archive_id,
      'framework_id' => $framework_id,
      'parent_uuid' => $parent_uuid,
      'insert_after_uuid' => $insert_after_uuid,
      'term' => $removed_term,
      'archived_at' => $archived_at,
    ], 30 * MINUTE_IN_SECONDS);

    do_action('cfm_term_deleted', $framework_id, $term_uuid, $removed_term, $parent_uuid, $compile_result);

    wp_safe_redirect(
      self::editor_url($framework_id)
        . '&cfm_editor_archived=1'
        . '&cfm_undo_archive=' . rawurlencode($undo_key)
        . ($compile_result['query_arg'] ?? '')
    );
    exit;
  }

  private static function handle_core_terms_editor_undo_archive(): void
  {
    check_admin_referer('cfm_core_terms_editor_undo_archive', 'cfm_nonce');

    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to restore Core Terms.');
    }

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $undo_key = sanitize_text_field((string) wp_unslash($_POST['undo_key'] ?? ''));

    if ($framework_id <= 0 || $undo_key === '') {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_error=1');
      exit;
    }

    $transient_key = self::core_terms_editor_archive_transient_key($framework_id, $undo_key);
    $archive = get_transient($transient_key);
    $durable_archive = CFM_Framework_Repository::get_term_archive_by_key($undo_key);

    if ((!is_array($archive) || empty($archive['term']) || !is_array($archive['term'])) && !$durable_archive) {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_undo_expired=1');
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);

    if ($durable_archive) {
      $branch = json_decode((string) $durable_archive->branch_json, true);
      $term = is_array($branch) ? $branch : [];
      $parent_uuid = (string) ($durable_archive->parent_uuid ?? '');
      $insert_after_uuid = (string) ($durable_archive->insert_after_uuid ?? '');
    } else {
      $term = (array) $archive['term'];
      $parent_uuid = (string) ($archive['parent_uuid'] ?? '');
      $insert_after_uuid = (string) ($archive['insert_after_uuid'] ?? '');
    }

    $term_uuid = (string) ($term['uuid'] ?? '');

    if ($term_uuid === '' || self::find_node_with_parent($tree, $term_uuid)) {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_undo_conflict=1');
      exit;
    }

    $slug = self::normalize_slug((string) ($term['slug'] ?? ''));

    if ($slug === '' || self::has_child_slug_conflict($tree, $parent_uuid, $slug, $term_uuid)) {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_undo_conflict=1');
      exit;
    }

    $restored = false;

    if ($insert_after_uuid !== '' && self::find_node_with_parent($tree, $insert_after_uuid)) {
      $restored = self::insert_child_after_uuid($tree, $parent_uuid, $insert_after_uuid, $term);
    }

    if (!$restored) {
      $restored = self::prepend_child_to_node_by_uuid($tree, $parent_uuid, $term);
    }

    if (!$restored) {
      wp_safe_redirect(self::editor_url($framework_id) . '&cfm_editor_undo_conflict=1');
      exit;
    }

    if ($parent_uuid !== '') {
      self::bump_order_revision($framework_id, $parent_uuid);
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    if (empty($compile_result['success'])) {
      wp_die('Core Term branch was restored but runtime tables could not be rebuilt.');
    }

    delete_transient($transient_key);
    CFM_Framework_Repository::mark_term_archive_restored($undo_key);

    do_action('cfm_term_created', $framework_id, $term_uuid, $term, $parent_uuid, $compile_result);

    wp_safe_redirect(
      self::editor_url($framework_id)
        . '&cfm_editor_unarchived=1'
        . ($compile_result['query_arg'] ?? '')
    );
    exit;
  }

  private static function handle_core_terms_archive_restore(): void
  {
    check_admin_referer('cfm_core_terms_archive_restore', 'cfm_nonce');

    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to restore Core Terms.');
    }

    $archive_key = sanitize_text_field((string) wp_unslash($_POST['archive_key'] ?? ''));
    $archive = CFM_Framework_Repository::get_term_archive_by_key($archive_key);

    if (!$archive) {
      wp_safe_redirect(self::archived_terms_url() . '&cfm_archive_restore_error=missing');
      exit;
    }

    $framework_id = (int) ($archive->framework_id ?? 0);
    $restore_url = self::archived_terms_url($framework_id);

    if (!empty($archive->deleted_at)) {
      wp_safe_redirect($restore_url . '&cfm_archive_restore_error=deleted');
      exit;
    }

    if (!empty($archive->restored_at)) {
      wp_safe_redirect($restore_url . '&cfm_archive_restore_error=restored');
      exit;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $branch = json_decode((string) ($archive->branch_json ?? ''), true);
    $term = is_array($branch) ? $branch : [];
    $term_uuid = (string) ($term['uuid'] ?? '');
    $parent_uuid = (string) ($archive->parent_uuid ?? '');
    $insert_after_uuid = (string) ($archive->insert_after_uuid ?? '');
    $tree = self::get_framework_tree($framework);

    if ($term_uuid === '' || self::find_node_with_parent($tree, $term_uuid)) {
      wp_safe_redirect($restore_url . '&cfm_archive_restore_error=conflict');
      exit;
    }

    $slug = self::normalize_slug((string) ($term['slug'] ?? ''));

    if ($slug === '' || self::has_child_slug_conflict($tree, $parent_uuid, $slug, $term_uuid)) {
      wp_safe_redirect($restore_url . '&cfm_archive_restore_error=conflict');
      exit;
    }

    $restored = false;

    if ($insert_after_uuid !== '' && self::find_node_with_parent($tree, $insert_after_uuid)) {
      $restored = self::insert_child_after_uuid($tree, $parent_uuid, $insert_after_uuid, $term);
    }

    if (!$restored) {
      $restored = self::prepend_child_to_node_by_uuid($tree, $parent_uuid, $term);
    }

    if (!$restored) {
      wp_safe_redirect($restore_url . '&cfm_archive_restore_error=conflict');
      exit;
    }

    if ($parent_uuid !== '') {
      self::bump_order_revision($framework_id, $parent_uuid);
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    if (empty($compile_result['success'])) {
      wp_die('Core Term branch was restored but runtime tables could not be rebuilt.');
    }

    CFM_Framework_Repository::mark_term_archive_restored($archive_key);

    do_action('cfm_term_created', $framework_id, $term_uuid, $term, $parent_uuid, $compile_result);

    wp_safe_redirect($restore_url . '&cfm_archive_restored=1' . ($compile_result['query_arg'] ?? ''));
    exit;
  }

  private static function handle_core_terms_archive_delete(): void
  {
    check_admin_referer('cfm_core_terms_archive_delete', 'cfm_nonce');

    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to delete Core Terms archives.');
    }

    $archive_key = sanitize_text_field((string) wp_unslash($_POST['archive_key'] ?? ''));
    $archive = CFM_Framework_Repository::get_term_archive_by_key($archive_key);

    if (!$archive) {
      wp_safe_redirect(self::archived_terms_url() . '&cfm_archive_delete_error=missing');
      exit;
    }

    $framework_id = (int) ($archive->framework_id ?? 0);
    $delete_url = self::archived_terms_url($framework_id);

    if (!empty($archive->restored_at)) {
      wp_safe_redirect($delete_url . '&cfm_archive_delete_error=restored');
      exit;
    }

    if (!empty($archive->deleted_at)) {
      wp_safe_redirect($delete_url . '&cfm_archive_delete_error=deleted');
      exit;
    }

    if (!CFM_Framework_Repository::mark_term_archive_deleted($archive_key)) {
      wp_safe_redirect($delete_url . '&cfm_archive_delete_error=failed');
      exit;
    }

    wp_safe_redirect($delete_url . '&cfm_archive_deleted=1');
    exit;
  }

  private static function prepare_core_terms_editor_changes(array $tree, array $raw_changes): array
  {
    $errors = [];
    $changes = [];
    $new_terms = [];
    $seen_uuids = [];
    $final_sibling_slugs = self::collect_core_terms_editor_sibling_slugs($tree);

    foreach ($raw_changes as $index => $raw_change) {
      if (!is_array($raw_change)) {
        $errors[] = 'One submitted row was malformed.';
        continue;
      }

      $uuid = sanitize_text_field((string) ($raw_change['uuid'] ?? ''));
      $is_new = !empty($raw_change['is_new']);

      if ($uuid === '') {
        $errors[] = 'One edited row is missing its term identifier.';
        continue;
      }

      if ($is_new) {
        $new_term = self::prepare_core_terms_editor_new_term($tree, $raw_change, $index, $final_sibling_slugs);

        if (!empty($new_term['errors'])) {
          $errors = array_merge($errors, $new_term['errors']);
          continue;
        }

        if (!empty($new_term['term'])) {
          $new_terms[] = $new_term['term'];
        }

        continue;
      }

      if (isset($seen_uuids[$uuid])) {
        $errors[] = 'A term was submitted more than once.';
        continue;
      }

      $seen_uuids[$uuid] = true;
      $node_info = self::find_node_with_parent($tree, $uuid);

      if (!$node_info || empty($node_info['node']) || !is_array($node_info['node'])) {
        $errors[] = 'One edited term no longer exists.';
        continue;
      }

      if (self::node_kind($node_info['node']) !== 'term') {
        $errors[] = 'Only Core Terms can be edited in this editor.';
        continue;
      }

      $label = sanitize_text_field((string) ($raw_change['label'] ?? ''));
      $slug = self::normalize_slug((string) ($raw_change['slug'] ?? ''));
      $short_label = self::normalize_short_label(sanitize_text_field((string) ($raw_change['short_label'] ?? '')));
      $description = sanitize_text_field((string) ($raw_change['description'] ?? ''));

      if ($label === '') {
        $errors[] = 'Label is required for edited row ' . ((int) $index + 1) . '.';
      }

      if ($slug === '') {
        $errors[] = 'Slug is required for edited row ' . ((int) $index + 1) . '.';
      }

      if ($short_label === '') {
        $errors[] = 'Short Label is required for edited row ' . ((int) $index + 1) . '.';
      }

      if ($description === '') {
        $errors[] = 'Community is required for edited row ' . ((int) $index + 1) . '.';
      }

      $parent_uuid = '';

      if (!empty($node_info['parent']) && is_array($node_info['parent'])) {
        $parent_uuid = (string) ($node_info['parent']['uuid'] ?? '');
      }

      $slug_parent_key = $parent_uuid !== '' ? $parent_uuid : '__root__';

      if ($slug !== '') {
        if (!isset($final_sibling_slugs[$slug_parent_key])) {
          $final_sibling_slugs[$slug_parent_key] = [];
        }

        $current_slug = self::normalize_slug((string) ($node_info['node']['slug'] ?? ''));

        if ($current_slug !== '' && isset($final_sibling_slugs[$slug_parent_key][$current_slug]) && $final_sibling_slugs[$slug_parent_key][$current_slug] === $uuid) {
          unset($final_sibling_slugs[$slug_parent_key][$current_slug]);
        }

        if (isset($final_sibling_slugs[$slug_parent_key][$slug]) && $final_sibling_slugs[$slug_parent_key][$slug] !== $uuid) {
          $errors[] = 'The slug "' . $slug . '" already exists under the same parent.';
        } else {
          $final_sibling_slugs[$slug_parent_key][$slug] = $uuid;
        }
      }

      $changes[] = [
        'uuid' => $uuid,
        'parent_uuid' => $parent_uuid,
        'label' => $label,
        'slug' => $slug,
        'short_label' => $short_label,
        'description' => $description,
      ];
    }

    return [
      'changes' => $changes,
      'new_terms' => $new_terms,
      'errors' => array_values(array_unique($errors)),
    ];
  }

  private static function prepare_core_terms_editor_new_term(array $tree, array $raw_change, int $index, array &$final_sibling_slugs): array
  {
    $label = sanitize_text_field((string) ($raw_change['label'] ?? ''));
    $slug = self::normalize_slug((string) ($raw_change['slug'] ?? ''));
    $short_label = self::normalize_short_label(sanitize_text_field((string) ($raw_change['short_label'] ?? '')));
    $description = sanitize_text_field((string) ($raw_change['description'] ?? ''));

    if ($label === '' && $slug === '' && $short_label === '' && $description === '') {
      return [
        'term' => null,
        'errors' => [],
      ];
    }

    $errors = [];
    $row_number = (int) $index + 1;

    if ($label === '') {
      $errors[] = 'Label is required for draft row ' . $row_number . '.';
    }

    if ($slug === '') {
      $errors[] = 'Slug is required for draft row ' . $row_number . '.';
    }

    if ($short_label === '') {
      $errors[] = 'Short Label is required for draft row ' . $row_number . '.';
    }

    if ($description === '') {
      $errors[] = 'Community is required for draft row ' . $row_number . '.';
    }

    $insert_mode = sanitize_key((string) ($raw_change['insert_mode'] ?? ''));
    $parent_uuid = sanitize_text_field((string) ($raw_change['parent_uuid'] ?? ''));
    $insert_after_uuid = sanitize_text_field((string) ($raw_change['insert_after_uuid'] ?? ''));
    $insert_before_uuid = sanitize_text_field((string) ($raw_change['insert_before_uuid'] ?? ''));

    if ($insert_mode === 'insert_sibling' || $insert_mode === 'insert_sibling_after') {
      $sibling_info = self::find_node_with_parent($tree, $insert_after_uuid);

      if (!$sibling_info || empty($sibling_info['node']) || !is_array($sibling_info['node']) || self::node_kind($sibling_info['node']) !== 'term') {
        $errors[] = 'One draft sibling no longer has a valid insertion point.';
      } else {
        $parent_uuid = '';

        if (!empty($sibling_info['parent']) && is_array($sibling_info['parent'])) {
          $parent_uuid = (string) ($sibling_info['parent']['uuid'] ?? '');
        }
      }
    } elseif ($insert_mode === 'insert_sibling_before') {
      $sibling_info = self::find_node_with_parent($tree, $insert_before_uuid);

      if (!$sibling_info || empty($sibling_info['node']) || !is_array($sibling_info['node']) || self::node_kind($sibling_info['node']) !== 'term') {
        $errors[] = 'One draft sibling no longer has a valid insertion point.';
      } else {
        $parent_uuid = '';

        if (!empty($sibling_info['parent']) && is_array($sibling_info['parent'])) {
          $parent_uuid = (string) ($sibling_info['parent']['uuid'] ?? '');
        }
      }
    } elseif ($insert_mode === 'add_child' || $insert_mode === 'add_child_append' || $insert_mode === 'add_child_prepend') {
      $parent_info = self::find_node_with_parent($tree, $parent_uuid);

      if (!$parent_info || empty($parent_info['node']) || !is_array($parent_info['node']) || self::node_kind($parent_info['node']) !== 'term') {
        $errors[] = 'One draft child no longer has a valid parent.';
      }

      $insert_after_uuid = '';
      $insert_before_uuid = '';
    } else {
      $errors[] = 'One draft row has an unsupported insertion mode.';
    }

    $slug_parent_key = $parent_uuid !== '' ? $parent_uuid : '__root__';

    if ($slug !== '') {
      if (!isset($final_sibling_slugs[$slug_parent_key])) {
        $final_sibling_slugs[$slug_parent_key] = [];
      }

      if (isset($final_sibling_slugs[$slug_parent_key][$slug])) {
        $errors[] = 'The slug "' . $slug . '" already exists under the same parent.';
      } else {
        $final_sibling_slugs[$slug_parent_key][$slug] = (string) ($raw_change['uuid'] ?? '');
      }
    }

    if (!empty($errors)) {
      return [
        'term' => null,
        'errors' => $errors,
      ];
    }

    return [
      'term' => [
        'draft_uuid' => sanitize_text_field((string) ($raw_change['uuid'] ?? '')),
        'parent_uuid' => $parent_uuid,
        'insert_after_uuid' => $insert_after_uuid,
        'insert_before_uuid' => $insert_before_uuid,
        'insert_mode' => $insert_mode,
        'label' => $label,
        'slug' => $slug,
        'short_label' => $short_label,
        'description' => $description,
      ],
      'errors' => [],
    ];
  }

  private static function collect_core_terms_editor_sibling_slugs(array $node, string $fallback_parent_key = '__root__'): array
  {
    $groups = [];
    $uuid = (string) ($node['uuid'] ?? '');
    $children = $node['children'] ?? [];
    $parent_key = $uuid !== '' ? $uuid : $fallback_parent_key;

    if (is_array($children)) {
      $groups[$parent_key] = [];

      foreach ($children as $child) {
        if (!is_array($child) || self::node_kind($child) !== 'term') {
          continue;
        }

        $child_uuid = (string) ($child['uuid'] ?? '');
        $slug = self::normalize_slug((string) ($child['slug'] ?? $child['label'] ?? ''));

        if ($child_uuid !== '' && $slug !== '') {
          $groups[$parent_key][$slug] = $child_uuid;
        }
      }

      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $groups = array_replace($groups, self::collect_core_terms_editor_sibling_slugs($child, $parent_key));
      }
    }

    return $groups;
  }

  private static function core_terms_editor_error_transient_key(int $framework_id): string
  {
    return 'cfm_core_terms_editor_errors_' . $framework_id . '_' . get_current_user_id();
  }

  private static function core_terms_editor_saved_transient_key(int $framework_id): string
  {
    return 'cfm_core_terms_editor_saved_' . $framework_id . '_' . get_current_user_id();
  }

  private static function core_terms_editor_archive_transient_key(int $framework_id, string $undo_key): string
  {
    return 'cfm_core_terms_editor_archive_' . $framework_id . '_' . get_current_user_id() . '_' . sanitize_key($undo_key);
  }

  private static function previous_child_uuid(array $parent_node, string $child_uuid): string
  {
    $previous_uuid = '';
    $children = $parent_node['children'] ?? [];

    if (empty($children) || !is_array($children)) {
      return '';
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      if ((string) ($child['uuid'] ?? '') === $child_uuid) {
        return $previous_uuid;
      }

      $previous_uuid = (string) ($child['uuid'] ?? '');
    }

    return '';
  }

  private static function next_child_uuid(array $parent_node, string $child_uuid): string
  {
    $children = $parent_node['children'] ?? [];
    $found = false;

    if (empty($children) || !is_array($children)) {
      return '';
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      $uuid = (string) ($child['uuid'] ?? '');

      if ($found) {
        return $uuid;
      }

      if ($uuid === $child_uuid) {
        $found = true;
      }
    }

    return '';
  }

  private static function last_child_uuid(array $parent_node): string
  {
    $children = $parent_node['children'] ?? [];
    $last_uuid = '';

    if (empty($children) || !is_array($children)) {
      return '';
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      $uuid = (string) ($child['uuid'] ?? '');

      if ($uuid !== '') {
        $last_uuid = $uuid;
      }
    }

    return $last_uuid;
  }

  private static function handle_move_term(): void
  {
    check_admin_referer('cfm_move_term', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $term_uuid = sanitize_text_field(wp_unslash($_POST['term_uuid'] ?? ''));
    $new_parent_uuid = sanitize_text_field(wp_unslash($_POST['new_parent_uuid'] ?? ''));
    $return_to_editor = sanitize_key((string) wp_unslash($_POST['cfm_return'] ?? '')) === 'editor';
    $editor_redirect = static function (int $redirect_framework_id, string $code, array $args = []): void {
      $url = self::editor_url($redirect_framework_id) . '&cfm_editor_move_error=' . rawurlencode($code);

      foreach ($args as $key => $value) {
        $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
      }

      wp_safe_redirect($url);
      exit;
    };

    if ($framework_id <= 0 || $term_uuid === '' || $new_parent_uuid === '') {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'missing_fields');
      }

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
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'self');
      }

      wp_die('A term cannot be moved under itself.');
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);
    $new_parent_info = self::find_node_with_parent($tree, $new_parent_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'missing_term');
      }

      wp_die('Term not found.');
    }

    if (self::node_kind($term_info['node']) !== 'term') {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'invalid_term');
      }

      wp_die('Only terms can be moved. Meta-Groups and system roots cannot be moved here.');
    }

    if (!$new_parent_info || empty($new_parent_info['node']) || !is_array($new_parent_info['node'])) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'missing_parent');
      }

      wp_die('New parent not found.');
    }

    $new_parent_kind = self::node_kind($new_parent_info['node']);

    if (!in_array($new_parent_kind, $return_to_editor ? ['term'] : ['framework', 'root', 'term'], true)) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'invalid_parent');
      }

      wp_die('New parent must be the taxonomy root or another profile term.');
    }

    if (self::node_contains_uuid($term_info['node'], $new_parent_uuid)) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'descendant');
      }

      wp_die('A term cannot be moved under one of its own descendants.');
    }

    $moving_slug = sanitize_title((string) ($term_info['node']['slug'] ?? ''));

    if ($moving_slug === '') {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'missing_slug');
      }

      wp_die('Term slug is missing. Move aborted.');
    }

    if (self::has_child_slug_conflict($tree, $new_parent_uuid, $moving_slug, $term_uuid)) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'duplicate_sibling_slug');
      }

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

    if ($return_to_editor && $current_parent_uuid === $new_parent_uuid) {
      $editor_redirect($framework_id, 'same_parent');
    }

    if ($return_to_editor) {
      $assignment_count = self::count_user_term_assignments(self::collect_node_uuids($term_info['node']));
      $confirm_assignments = !empty($_POST['cfm_confirm_assignments']);

      if ($assignment_count > 0 && !$confirm_assignments) {
        $editor_redirect($framework_id, 'assignment_warning', ['cfm_assignment_count' => $assignment_count]);
      }

      $term_axis_uuid = self::term_axis_uuid($tree, $term_uuid);
      $new_parent_axis_uuid = self::term_axis_uuid($tree, $new_parent_uuid);
      $confirm_axis_change = !empty($_POST['cfm_confirm_axis_change']);

      if ($term_axis_uuid !== '' && $new_parent_axis_uuid !== '' && $term_axis_uuid !== $new_parent_axis_uuid && !$confirm_axis_change) {
        $editor_redirect($framework_id, 'axis_warning');
      }
    }

    $removed_term = null;
    $removed = self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term);

    if (!$removed || !is_array($removed_term)) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'remove_failed');
      }

      wp_die('Unable to remove term from current parent.');
    }

    $added = self::append_child_to_node_by_uuid($tree, $new_parent_uuid, $removed_term);

    if (!$added) {
      if ($return_to_editor) {
        $editor_redirect($framework_id, 'add_failed');
      }

      wp_die('Unable to add term to new parent.');
    }

    if ($current_parent_uuid !== '') {
      self::bump_order_revision($framework_id, $current_parent_uuid);
    }
    self::bump_order_revision($framework_id, $new_parent_uuid);

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    do_action('cfm_term_moved', $framework_id, $term_uuid, $current_parent_uuid, $new_parent_uuid, $removed_term, $compile_result);

    if ($return_to_editor) {
      wp_safe_redirect(
        self::editor_url($framework_id)
          . '&cfm_editor_moved=1'
          . ($compile_result['query_arg'] ?? '')
      );
      exit;
    }

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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (self::node_kind($term_info['node']) !== 'term') {
      wp_die('Only terms can be archived. Meta-Groups and system roots cannot be archived here.');
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

    do_action('cfm_term_deleted', $framework_id, $term_uuid, $removed_term, $parent_uuid, $compile_result);

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

  private static function update_node_metadata_by_uuid(array &$node, string $uuid, string $label, string $slug, string $short_label, string $description): bool
  {
    if (($node['uuid'] ?? '') === $uuid) {
      $node['label'] = $label;
      $node['slug'] = $slug;
      $node['short_label'] = $short_label !== '' ? $short_label : $label;
      $node['description'] = $description !== '' ? $description : $label;
      return true;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$child) {
      if (!is_array($child)) {
        continue;
      }

      if (self::update_node_metadata_by_uuid($child, $uuid, $label, $slug, $short_label, $description)) {
        unset($child);
        return true;
      }
    }

    unset($child);
    return false;
  }

  private static function update_meta_group_by_uuid(array &$node, string $uuid, string $label, string $slug, string $short_label, string $description, array $includes): bool
  {
    if (($node['uuid'] ?? '') === $uuid) {
      if (self::node_kind($node) !== 'meta') {
        return false;
      }

      $node['label'] = $label;
      $node['slug'] = $slug;
      $node['short_label'] = $short_label !== '' ? $short_label : $label;
      $node['description'] = $description !== '' ? $description : $label;
      $node['includes'] = array_values(array_unique(array_filter(array_map('strval', $includes))));
      $node['kind'] = 'meta';

      if (!isset($node['children']) || !is_array($node['children'])) {
        $node['children'] = [];
      }

      return true;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$child) {
      if (!is_array($child)) {
        continue;
      }

      if (self::update_meta_group_by_uuid($child, $uuid, $label, $slug, $short_label, $description, $includes)) {
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

  private static function prepend_child_to_node_by_uuid(array &$node, string $parent_uuid, array $child): bool
  {
    $node_uuid = (string) ($node['uuid'] ?? '');

    if ($node_uuid === $parent_uuid || ($parent_uuid === '' && $node_uuid === '')) {
      if (!isset($node['children']) || !is_array($node['children'])) {
        $node['children'] = [];
      }

      array_unshift($node['children'], $child);
      return true;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$candidate) {
      if (!is_array($candidate)) {
        continue;
      }

      if (self::prepend_child_to_node_by_uuid($candidate, $parent_uuid, $child)) {
        unset($candidate);
        return true;
      }
    }

    unset($candidate);
    return false;
  }

  private static function insert_child_after_uuid(array &$node, string $parent_uuid, string $after_uuid, array $child): bool
  {
    $node_uuid = (string) ($node['uuid'] ?? '');

    if ($node_uuid === $parent_uuid || ($parent_uuid === '' && $node_uuid === '')) {
      if (!isset($node['children']) || !is_array($node['children'])) {
        $node['children'] = [];
      }

      foreach ($node['children'] as $index => $candidate) {
        if (!is_array($candidate)) {
          continue;
        }

        if ((string) ($candidate['uuid'] ?? '') === $after_uuid) {
          array_splice($node['children'], $index + 1, 0, [$child]);
          return true;
        }
      }

      return false;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$candidate) {
      if (!is_array($candidate)) {
        continue;
      }

      if (self::insert_child_after_uuid($candidate, $parent_uuid, $after_uuid, $child)) {
        unset($candidate);
        return true;
      }
    }

    unset($candidate);
    return false;
  }

  private static function insert_child_before_uuid(array &$node, string $parent_uuid, string $before_uuid, array $child): bool
  {
    $node_uuid = (string) ($node['uuid'] ?? '');

    if ($node_uuid === $parent_uuid || ($parent_uuid === '' && $node_uuid === '')) {
      if (!isset($node['children']) || !is_array($node['children'])) {
        $node['children'] = [];
      }

      foreach ($node['children'] as $index => $candidate) {
        if (!is_array($candidate)) {
          continue;
        }

        if ((string) ($candidate['uuid'] ?? '') === $before_uuid) {
          array_splice($node['children'], $index, 0, [$child]);
          return true;
        }
      }

      return false;
    }

    if (empty($node['children']) || !is_array($node['children'])) {
      return false;
    }

    foreach ($node['children'] as &$candidate) {
      if (!is_array($candidate)) {
        continue;
      }

      if (self::insert_child_before_uuid($candidate, $parent_uuid, $before_uuid, $child)) {
        unset($candidate);
        return true;
      }
    }

    unset($candidate);
    return false;
  }

  private static function normalize_slug(string $value): string
  {
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace("/[\'’`]/u", '', $value);

    if (function_exists('remove_accents')) {
      $value = remove_accents($value);
    }

    if (function_exists('mb_strtolower')) {
      $value = mb_strtolower($value, 'UTF-8');
    } else {
      $value = strtolower($value);
    }

    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = preg_replace('/-+/', '-', (string) $value);
    $value = trim((string) $value, '-');

    return $value;
  }

  private static function normalize_short_label(string $value): string
  {
    $value = sanitize_text_field($value);
    $value = preg_replace('/\s*\/\s*/', '/', $value);

    return trim((string) $value);
  }

  private static function default_community_for_label(string $label): string
  {
    $label = trim($label);

    if ($label === '') {
      return '';
    }

    return ucwords(strtolower($label)) . ' Teachers';
  }

  private static function has_child_slug_conflict(array $tree, string $parent_uuid, string $slug, string $exclude_uuid = ''): bool
  {
    $slug = self::normalize_slug($slug);

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

      if (self::normalize_slug((string) ($child['slug'] ?? '')) === $slug) {
        return true;
      }
    }

    return false;
  }

  private static function child_slug_lookup(array $tree, string $parent_uuid): array
  {
    $slugs = [];

    if ($parent_uuid === '') {
      return $slugs;
    }

    $parent_info = self::find_node_with_parent($tree, $parent_uuid);

    if (!$parent_info || empty($parent_info['node']) || !is_array($parent_info['node'])) {
      return $slugs;
    }

    $children = $parent_info['node']['children'] ?? [];

    if (empty($children) || !is_array($children)) {
      return $slugs;
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      $slug = self::normalize_slug((string) ($child['slug'] ?? $child['label'] ?? ''));

      if ($slug !== '') {
        $slugs[$slug] = true;
      }
    }

    return $slugs;
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

  private static function term_axis_uuid(array $tree, string $term_uuid): string
  {
    $terms = self::root_terms($tree);

    foreach ($terms as $term) {
      if (!is_array($term)) {
        continue;
      }

      $axis_uuid = (string) ($term['uuid'] ?? '');

      if ($axis_uuid === '') {
        continue;
      }

      if ($axis_uuid === $term_uuid || self::node_contains_uuid($term, $term_uuid)) {
        return $axis_uuid;
      }
    }

    return '';
  }

  private static function collect_core_terms_editor_move_options(array $terms, string $axis_uuid = '', string $path = ''): array
  {
    $options = [];

    foreach ($terms as $term) {
      if (!is_array($term) || self::node_kind($term) !== 'term') {
        continue;
      }

      $uuid = (string) ($term['uuid'] ?? '');
      $label = trim((string) ($term['label'] ?? ''));

      if ($uuid === '' || $label === '') {
        continue;
      }

      $current_axis_uuid = $axis_uuid !== '' ? $axis_uuid : $uuid;
      $current_path = $path !== '' ? $path . ' › ' . $label : $label;

      $options[] = [
        'uuid' => $uuid,
        'label' => $label,
        'path' => $current_path,
        'axis_uuid' => $current_axis_uuid,
      ];

      $children = isset($term['children']) && is_array($term['children']) ? $term['children'] : [];

      if (!empty($children)) {
        $options = array_merge($options, self::collect_core_terms_editor_move_options($children, $current_axis_uuid, $current_path));
      }
    }

    return $options;
  }

  private static function direct_user_assignment_counts(array $term_uuids): array
  {
    global $wpdb;

    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if (empty($term_uuids)) {
      return [];
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';
    $placeholders = implode(',', array_fill(0, count($term_uuids), '%s'));
    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT term_uuid, COUNT(*) AS assignment_count FROM {$user_terms_table} WHERE term_uuid IN ({$placeholders}) GROUP BY term_uuid",
        ...$term_uuids
      ),
      ARRAY_A
    );

    $counts = [];

    foreach ((array) $rows as $row) {
      $uuid = (string) ($row['term_uuid'] ?? '');

      if ($uuid !== '') {
        $counts[$uuid] = (int) ($row['assignment_count'] ?? 0);
      }
    }

    return $counts;
  }

  private static function collect_branch_assignment_counts(array $terms): array
  {
    $all_uuids = [];

    foreach ($terms as $term) {
      if (is_array($term)) {
        $all_uuids = array_merge($all_uuids, self::collect_node_uuids($term));
      }
    }

    $direct_counts = self::direct_user_assignment_counts($all_uuids);
    $branch_counts = [];

    foreach ($terms as $term) {
      if (is_array($term)) {
        self::branch_assignment_count_for_node($term, $direct_counts, $branch_counts);
      }
    }

    return $branch_counts;
  }

  private static function branch_assignment_count_for_node(array $node, array $direct_counts, array &$branch_counts): int
  {
    $uuid = (string) ($node['uuid'] ?? '');
    $count = $uuid !== '' ? (int) ($direct_counts[$uuid] ?? 0) : 0;
    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];

    foreach ($children as $child) {
      if (is_array($child)) {
        $count += self::branch_assignment_count_for_node($child, $direct_counts, $branch_counts);
      }
    }

    if ($uuid !== '') {
      $branch_counts[$uuid] = $count;
    }

    return $count;
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

  public static function handle_ajax_move_branch(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error([
        'message' => 'You do not have permission to move Core Terms.',
      ], 403);
    }

    check_ajax_referer('cfm_move_branch', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $term_uuid = sanitize_text_field(wp_unslash($_POST['term_uuid'] ?? ''));
    $target_uuid = sanitize_text_field(wp_unslash($_POST['target_uuid'] ?? ''));
    $placement = sanitize_key((string) wp_unslash($_POST['placement'] ?? ''));

    $result = self::process_branch_move($framework_id, $term_uuid, $target_uuid, $placement, [
      'confirm_assignments' => !empty($_POST['cfm_confirm_assignments']),
      'confirm_axis_change' => !empty($_POST['cfm_confirm_axis_change']),
    ]);

    if (empty($result['success'])) {
      wp_send_json_error([
        'message' => (string) ($result['message'] ?? 'Branch could not be moved.'),
        'code' => (string) ($result['code'] ?? 'move_failed'),
        'assignment_count' => (int) ($result['assignment_count'] ?? 0),
        'current_source_revision' => (int) ($result['current_source_revision'] ?? 0),
        'current_target_revision' => (int) ($result['current_target_revision'] ?? 0),
      ], (int) ($result['status'] ?? 400));
    }

    wp_send_json_success([
      'message' => '✓ Moved',
      'revisions' => (array) ($result['revisions'] ?? []),
      'move' => (array) ($result['move'] ?? []),
      'undo_move' => (array) ($result['undo_move'] ?? []),
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
        'message' => 'Core Terms definition not found.',
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

  private static function process_branch_move(int $framework_id, string $term_uuid, string $target_uuid, string $placement, array $options = []): array
  {
    $placement = self::normalize_branch_move_placement($placement);

    if ($framework_id <= 0 || $term_uuid === '' || $target_uuid === '' || $placement === '') {
      return [
        'success' => false,
        'code' => 'missing_move_fields',
        'message' => 'Move request was incomplete.',
        'status' => 400,
      ];
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      return [
        'success' => false,
        'code' => 'profile_taxonomy_not_found',
        'message' => 'Core Terms definition not found.',
        'status' => 404,
      ];
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);
    $target_info = self::find_node_with_parent($tree, $target_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      return [
        'success' => false,
        'code' => 'missing_term',
        'message' => 'Moved branch was not found.',
        'status' => 404,
      ];
    }

    if (!$target_info || empty($target_info['node']) || !is_array($target_info['node'])) {
      return [
        'success' => false,
        'code' => 'missing_target',
        'message' => 'Move target was not found.',
        'status' => 404,
      ];
    }

    if (self::node_kind($term_info['node']) !== 'term' || self::node_kind($target_info['node']) !== 'term') {
      return [
        'success' => false,
        'code' => 'invalid_term',
        'message' => 'Only Core Term branches can be moved here.',
        'status' => 400,
      ];
    }

    if ($term_uuid === $target_uuid || self::node_contains_uuid($term_info['node'], $target_uuid)) {
      return [
        'success' => false,
        'code' => 'invalid_descendant_target',
        'message' => 'A branch cannot move onto itself or one of its descendants.',
        'status' => 400,
      ];
    }

    $original_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : [];
    $original_parent_uuid = (string) ($original_parent['uuid'] ?? '');
    $original_insert_after_uuid = $original_parent ? self::previous_child_uuid($original_parent, $term_uuid) : '';
    $original_insert_before_uuid = $original_parent ? self::next_child_uuid($original_parent, $term_uuid) : '';
    $new_parent_uuid = '';
    $new_insert_after_uuid = '';
    $new_insert_before_uuid = '';
    $new_placement = $placement;

    if ($placement === 'before' || $placement === 'after') {
      $target_parent = (!empty($target_info['parent']) && is_array($target_info['parent'])) ? $target_info['parent'] : [];
      $new_parent_uuid = (string) ($target_parent['uuid'] ?? '');

      if ($new_parent_uuid === '') {
        return [
          'success' => false,
          'code' => 'invalid_target_parent',
          'message' => 'Move target does not have a valid parent.',
          'status' => 400,
        ];
      }

      if ($placement === 'before') {
        $new_insert_after_uuid = self::previous_child_uuid($target_parent, $target_uuid);
        $new_insert_before_uuid = $target_uuid;
      } else {
        $new_insert_after_uuid = $target_uuid;
        $new_insert_before_uuid = self::next_child_uuid($target_parent, $target_uuid);
      }
    } else {
      $new_parent_uuid = $target_uuid;
      $new_insert_after_uuid = self::last_child_uuid($target_info['node']);
      $new_insert_before_uuid = '';
      $new_placement = 'child_append';
    }

    $moving_slug = self::normalize_slug((string) ($term_info['node']['slug'] ?? ''));

    if ($moving_slug === '') {
      return [
        'success' => false,
        'code' => 'missing_slug',
        'message' => 'Moved branch is missing a slug.',
        'status' => 400,
      ];
    }

    if (self::has_child_slug_conflict($tree, $new_parent_uuid, $moving_slug, $term_uuid)) {
      return [
        'success' => false,
        'code' => 'duplicate_sibling_slug',
        'message' => 'A sibling under the target parent already uses this slug.',
        'status' => 409,
      ];
    }

    $assignment_count = self::count_user_term_assignments(self::collect_node_uuids($term_info['node']));

    if ($assignment_count > 0 && empty($options['confirm_assignments'])) {
      return [
        'success' => false,
        'code' => 'assignment_warning',
        'message' => 'This branch has active user assignments. Confirm before moving it.',
        'status' => 409,
        'assignment_count' => $assignment_count,
      ];
    }

    $term_axis_uuid = self::term_axis_uuid($tree, $term_uuid);
    $target_axis_uuid = self::term_axis_uuid($tree, $new_parent_uuid);

    if ($target_axis_uuid === '' && ($placement === 'before' || $placement === 'after')) {
      $target_axis_uuid = self::term_axis_uuid($tree, $target_uuid);
    }

    if ($term_axis_uuid !== '' && $target_axis_uuid !== '' && $term_axis_uuid !== $target_axis_uuid && empty($options['confirm_axis_change'])) {
      return [
        'success' => false,
        'code' => 'axis_warning',
        'message' => 'This move changes the branch axis/top-level context. Confirm before moving it.',
        'status' => 409,
      ];
    }

    $removed_term = null;

    if (!self::remove_child_node_by_uuid($tree, $term_uuid, $removed_term) || !is_array($removed_term)) {
      return [
        'success' => false,
        'code' => 'remove_failed',
        'message' => 'Unable to remove branch from its current parent.',
        'status' => 500,
      ];
    }

    if ($placement === 'before') {
      $added = self::insert_child_before_uuid($tree, $new_parent_uuid, $target_uuid, $removed_term);
    } elseif ($placement === 'after') {
      $added = self::insert_child_after_uuid($tree, $new_parent_uuid, $target_uuid, $removed_term);
    } else {
      $added = self::append_child_to_node_by_uuid($tree, $new_parent_uuid, $removed_term);
    }

    if (!$added) {
      return [
        'success' => false,
        'code' => 'add_failed',
        'message' => 'Unable to add branch to its new placement.',
        'status' => 500,
      ];
    }

    if ($original_parent_uuid !== '') {
      self::bump_order_revision($framework_id, $original_parent_uuid);
    }

    if ($new_parent_uuid !== '' && $new_parent_uuid !== $original_parent_uuid) {
      self::bump_order_revision($framework_id, $new_parent_uuid);
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    if (empty($compile_result['success'])) {
      return [
        'success' => false,
        'code' => 'compile_failed',
        'message' => 'Branch was moved, but runtime tables could not be rebuilt.',
        'status' => 500,
      ];
    }

    do_action('cfm_term_moved', $framework_id, $term_uuid, $original_parent_uuid, $new_parent_uuid, $removed_term, $compile_result);

    $revisions = [];

    if ($original_parent_uuid !== '') {
      $revisions[$original_parent_uuid] = self::get_order_revision($framework_id, $original_parent_uuid);
    }

    if ($new_parent_uuid !== '') {
      $revisions[$new_parent_uuid] = self::get_order_revision($framework_id, $new_parent_uuid);
    }

    $undo_payload = [
      'moved_term_uuid' => $term_uuid,
      'original_parent_uuid' => $original_parent_uuid,
      'original_placement' => [
        'insert_after_uuid' => $original_insert_after_uuid,
        'insert_before_uuid' => $original_insert_before_uuid,
      ],
      'new_parent_uuid' => $new_parent_uuid,
      'new_placement' => [
        'placement' => $new_placement,
        'target_uuid' => $target_uuid,
        'insert_after_uuid' => $new_insert_after_uuid,
        'insert_before_uuid' => $new_insert_before_uuid,
      ],
      'invalidate_on' => [
        'add_child',
        'insert_sibling',
        'inline_edit',
        'archive',
        'restore_archive',
        'delete_archive',
        'reorder',
        'move',
      ],
    ];

    return [
      'success' => true,
      'revisions' => $revisions,
      'move' => [
        'moved_term_uuid' => $term_uuid,
        'original_parent_uuid' => $original_parent_uuid,
        'new_parent_uuid' => $new_parent_uuid,
        'placement' => $new_placement,
      ],
      'undo_move' => $undo_payload,
    ];
  }

  private static function normalize_branch_move_placement(string $placement): string
  {
    $placement = sanitize_key($placement);

    if (in_array($placement, ['before', 'after'], true)) {
      return $placement;
    }

    if (in_array($placement, ['child', 'child_append', 'append_child', 'into'], true)) {
      return 'child';
    }

    return '';
  }

  private static function normalize_tree_children(array &$node): void
  {
    self::normalize_node_kind($node);
    self::normalize_node_display_metadata($node);

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

  private static function normalize_node_kind(array &$node): void
  {
    $kind = self::node_kind($node);

    if ($kind !== '') {
      $node['kind'] = $kind;
    }

    if (array_key_exists('type', $node) && in_array((string) $node['type'], ['axis', 'term', 'framework', 'root'], true)) {
      unset($node['type']);
    }
  }

  private static function node_kind(array $node): string
  {
    $kind = trim((string) ($node['kind'] ?? ''));

    if (in_array($kind, ['term', 'meta', 'framework', 'root'], true)) {
      return $kind;
    }

    $type = trim((string) ($node['type'] ?? ''));

    if (in_array($type, ['axis', 'term'], true)) {
      return 'term';
    }

    if (in_array($type, ['framework', 'root'], true)) {
      return $type;
    }

    return '';
  }

  private static function normalize_node_display_metadata(array &$node): void
  {
    $kind = self::node_kind($node);

    if (!in_array($kind, ['term', 'meta', 'framework'], true)) {
      return;
    }

    $label = trim((string) ($node['label'] ?? ''));

    if (!array_key_exists('short_label', $node) || trim((string) $node['short_label']) === '') {
      $node['short_label'] = $label;
    } else {
      $node['short_label'] = sanitize_text_field((string) $node['short_label']);
    }

    if (!array_key_exists('description', $node) || trim((string) $node['description']) === '') {
      $node['description'] = $label;
    } else {
      $node['description'] = sanitize_textarea_field((string) $node['description']);
    }
  }

  private static function display_short_label_for_node(array $node): string
  {
    $short_label = trim((string) ($node['short_label'] ?? ''));

    if ($short_label !== '') {
      return $short_label;
    }

    return (string) ($node['label'] ?? '');
  }

  private static function display_description_for_node(array $node): string
  {
    $description = trim((string) ($node['description'] ?? ''));

    if ($description !== '') {
      return $description;
    }

    return (string) ($node['label'] ?? '');
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
      echo '<p class="description">Drag direct children into the desired order. Changes save automatically on drop.</p>';
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
    $kind = self::node_kind($node);
    $uuid = (string) ($node['uuid'] ?? '');
    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];

    if ($path === '' && in_array($kind, ['framework', 'root'], true)) {
      $current_path = '';
    } elseif ($path === '') {
      $current_path = $label;
    } else {
      $current_path = $path . ' › ' . $label;
    }

    if ($uuid !== '' && in_array($kind, ['framework', 'root'], true) && count($children) > 1) {
      $groups[] = [
        'parent_uuid' => $uuid,
        'label' => 'Top-level terms',
        'children' => $children,
      ];
    } elseif ($uuid !== '' && !in_array($kind, ['framework', 'root', 'meta'], true) && count($children) > 1) {
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
    $confirmed = !empty($_POST['confirm_restore_snapshot']);

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
      wp_die('Core Terms definition not found.');
    }

    if (!$confirmed) {
      wp_safe_redirect(self::restore_version_url((int) $framework->id, $version_id) . '&cfm_error=restore_not_confirmed');
      exit;
    }

    $version = CFM_Framework_Repository::get_version((int) $framework->id, $version_id);

    if (!$version) {
      wp_die('Version not found.');
    }

    if ((string) $version->status !== 'pre_import_snapshot') {
      wp_die('Only automatic recovery snapshots may be restored from this workflow.');
    }

    $tree = json_decode((string) $version->tree_json, true);

    if (!is_array($tree)) {
      wp_die('Stored tree JSON could not be decoded. Restore aborted.');
    }

    $current_tree = self::get_framework_tree($framework);
    $pre_restore_snapshot_id = CFM_Framework_Repository::create_version((int) $framework->id, $current_tree, 'pre_restore_snapshot');

    if ($pre_restore_snapshot_id <= 0) {
      wp_safe_redirect(self::restore_version_url((int) $framework->id, $version_id) . '&cfm_error=restore_snapshot_failed');
      exit;
    }

    self::bump_all_order_revisions((int) $framework->id, $tree);

    $compile_result = self::save_active_tree_and_compile((int) $framework->id, $tree);

    wp_safe_redirect(
      self::data_url((int) $framework->id)
          . '&cfm_recovery_snapshot_restored=1'
          . '&cfm_pre_restore_snapshot_id=' . (int) $pre_restore_snapshot_id
          . $compile_result['query_arg']
    );
    exit;
  }

  private static function get_framework_tree(object $framework): array
  {
    $version = CFM_Framework_Repository::get_active_version((int) $framework->id);

    if ($version) {
      $tree = json_decode($version->tree_json, true);

      if (is_array($tree)) {
        if (empty($tree['uuid'])) {
          $tree['uuid'] = $framework->framework_uuid;
        }

        if (empty($tree['label'])) {
          $tree['label'] = $framework->name;
        }

        if (empty($tree['slug'])) {
          $tree['slug'] = $framework->slug;
        }

        if (empty($tree['kind'])) {
          $tree['kind'] = 'framework';
        }

        return $tree;
      }
    }

    return [
      'uuid' => $framework->framework_uuid,
      'label' => $framework->name,
      'slug' => $framework->slug,
      'short_label' => $framework->name,
      'kind' => 'framework',
      'description' => $framework->description ?: $framework->name,
      'children' => [],
    ];
  }

  private static function root_terms(array $tree): array
  {
    $children = isset($tree['children']) && is_array($tree['children']) ? $tree['children'] : [];

    return array_values(array_filter($children, static function ($node): bool {
      return is_array($node) && self::node_kind($node) === 'term';
    }));
  }

  /**
   * Canonical Meta-Group model for v0.3.3.
   *
   * Meta-Groups are stored as root-level tree nodes with kind=meta and includes=[term UUIDs].
   * They are audience/collection helpers only; users are assigned terms, not Meta-Groups.
   * The older cfm_meta_groups table/repository path is dormant and must not be used as the
   * source of truth unless a future migration explicitly promotes it.
   */
  private static function root_meta_groups(array $tree): array
  {
    $children = isset($tree['children']) && is_array($tree['children']) ? $tree['children'] : [];

    return array_values(array_filter($children, static function ($node): bool {
      return is_array($node) && self::node_kind($node) === 'meta';
    }));
  }

  private static function collect_assignable_term_nodes(array $tree): array
  {
    $terms = [];
    self::collect_assignable_term_nodes_recursive($tree, $terms, '');

    return $terms;
  }

  private static function collect_assignable_term_nodes_recursive(array $node, array &$terms, string $path): void
  {
    $kind = self::node_kind($node);
    $label = trim((string) ($node['label'] ?? ''));
    $uuid = (string) ($node['uuid'] ?? '');

    if ($label !== '' && $path !== '') {
      $current_path = $path . ' › ' . $label;
    } elseif ($label !== '' && !in_array($kind, ['framework', 'root'], true)) {
      $current_path = $label;
    } else {
      $current_path = $path;
    }

    if ($kind === 'term' && $uuid !== '') {
      $terms[] = [
        'uuid' => $uuid,
        'label' => $label,
        'slug' => (string) ($node['slug'] ?? ''),
        'path' => $current_path,
      ];
    }

    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];

    foreach ($children as $child) {
      if (is_array($child)) {
        self::collect_assignable_term_nodes_recursive($child, $terms, $current_path);
      }
    }
  }

  private static function meta_group_include_details(array $meta_group, array $terms_by_uuid): array
  {
    $includes = isset($meta_group['includes']) && is_array($meta_group['includes']) ? $meta_group['includes'] : [];
    $details = [];

    foreach ($includes as $uuid) {
      $uuid = (string) $uuid;

      if ($uuid === '') {
        continue;
      }

      if (isset($terms_by_uuid[$uuid])) {
        $term = $terms_by_uuid[$uuid];
        $details[] = [
          'status' => 'found',
          'uuid' => $uuid,
          'label' => (string) ($term['path'] ?? $term['label'] ?? $uuid),
          'slug' => (string) ($term['slug'] ?? ''),
        ];
      } else {
        $details[] = [
          'status' => 'missing',
          'uuid' => $uuid,
          'label' => 'Missing term',
          'slug' => '',
        ];
      }
    }

    return $details;
  }

  private static function render_meta_groups_table(array $meta_groups, array $terms_by_uuid, int $framework_id): void
  {
    if (empty($meta_groups)) {
      echo '<p>No Meta-Groups created yet. Meta-Groups are optional audience helpers and are not required for basic profile assignments.</p>';
      return;
    }

    echo '<table class="widefat striped" style="max-width: 1100px;">';
    echo '<thead><tr><th>Meta-Group</th><th>Role</th><th>Slug</th><th>Included Terms</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    foreach ($meta_groups as $meta_group) {
      if (!is_array($meta_group)) {
        continue;
      }

      $include_details = self::meta_group_include_details($meta_group, $terms_by_uuid);
      $include_count = count($include_details);
      $missing_count = count(array_filter($include_details, static function ($detail): bool {
        return is_array($detail) && ($detail['status'] ?? '') === 'missing';
      }));

      echo '<tr>';
      echo '<td><strong>' . esc_html((string) ($meta_group['label'] ?? '')) . '</strong>';
      $description = self::display_description_for_node($meta_group);
      if ($description !== '' && $description !== (string) ($meta_group['label'] ?? '')) {
        echo '<br><span class="description">' . esc_html($description) . '</span>';
      }
      echo '<br><details style="margin-top:6px;"><summary>Inspect Meta-Group</summary>';
      echo '<p class="description" style="margin:6px 0 0;">Meta-Group UUID: <code>' . esc_html((string) ($meta_group['uuid'] ?? '')) . '</code></p>';
      echo '<p class="description" style="margin:4px 0 0;">This Meta-Group is not assignable. It stores references to existing terms for future audience and extension use.</p>';
      echo '</details>';
      echo '</td>';
      echo '<td><strong>Audience-only collection</strong><br><span class="description">Not directly assignable to users. User profiles still receive terms.</span></td>';
      echo '<td><code>' . esc_html((string) ($meta_group['slug'] ?? '')) . '</code></td>';
      echo '<td>';
      echo '<p style="margin:0 0 6px;"><strong>' . esc_html((string) $include_count) . '</strong> included term' . ($include_count === 1 ? '' : 's') . '</p>';

      if ($missing_count > 0) {
        echo '<p style="margin:0 0 6px; color:#8a1f11;"><strong>Warning:</strong> ' . esc_html((string) $missing_count) . ' included UUID' . ($missing_count === 1 ? '' : 's') . ' no longer resolve to a current term.</p>';
      }

      if (empty($include_details)) {
        echo '<em>No included terms.</em>';
      } else {
        echo '<ul style="margin:0; padding-left:18px;">';
        foreach ($include_details as $detail) {
          if (!is_array($detail)) {
            continue;
          }

          $status = (string) ($detail['status'] ?? '');
          $label = (string) ($detail['label'] ?? '');
          $slug = (string) ($detail['slug'] ?? '');
          $uuid = (string) ($detail['uuid'] ?? '');

          if ($status === 'missing') {
            echo '<li><strong>Missing term</strong><br><span class="description">Stored UUID: <code>' . esc_html($uuid) . '</code></span></li>';
          } else {
            echo '<li>' . esc_html($label);
            echo '<br><span class="description">Slug: <code>' . esc_html($slug) . '</code> · UUID: <code>' . esc_html($uuid) . '</code></span>';
            echo '</li>';
          }
        }
        echo '</ul>';
      }

      echo '</td>';
      echo '<td><a href="' . esc_url(self::edit_meta_group_url($framework_id, (string) ($meta_group['uuid'] ?? ''))) . '">Edit</a></td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
  }

  private static function render_meta_group_admin_styles(): void
  {
  ?>
    <style>
      .cfm-meta-group-reference {
        display: grid;
        gap: 1px;
        margin: 14px 0 12px;
        max-width: 980px;
      }

      .cfm-meta-group-reference-title {
        color: #1d2327;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 2px;
      }

      .cfm-meta-group-reference-grid,
      .cfm-meta-group-field-row {
        align-items: start;
        display: grid;
        gap: 14px;
        grid-template-columns: minmax(180px, 1.15fr) minmax(160px, 1fr) minmax(140px, 0.85fr) minmax(180px, 1.15fr);
      }

      .cfm-meta-group-reference-labels {
        color: #1d2327;
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.02em;
        text-transform: uppercase;
      }

      .cfm-meta-group-reference-labels span[title] {
        cursor: help;
        outline-offset: 2px;
      }

      .cfm-meta-group-reference-labels span[title]:focus {
        box-shadow: 0 0 0 2px #2271b1;
      }

      .cfm-meta-group-reference-example span {
        color: #50575e;
        font-style: italic;
        min-height: 24px;
        padding: 2px 0;
      }

      .cfm-meta-group-reference-divider {
        border: 0;
        border-top: 1px solid #c3c4c7;
        margin: 4px 0 14px;
        max-width: 980px;
      }

      .cfm-meta-group-field-row {
        margin: 8px 0 18px;
        max-width: 980px;
      }

      .cfm-meta-group-field label {
        display: block;
        font-weight: 600;
        margin: 0 0 4px;
      }

      .cfm-meta-group-field input[type="text"] {
        box-sizing: border-box;
        max-width: 100%;
        width: 100%;
      }

      .cfm-meta-group-term-selector {
        margin-top: 14px;
        max-width: 980px;
      }

      .cfm-meta-group-term-toolbar {
        align-items: center;
        display: flex;
        justify-content: space-between;
        margin: 0 0 8px;
        max-width: 760px;
      }

      .cfm-meta-group-term-tree {
        border: 0;
        margin: 0;
        max-width: 760px;
        padding: 0;
      }

      @media (max-width: 960px) {
        .cfm-meta-group-reference-grid,
        .cfm-meta-group-field-row {
          grid-template-columns: repeat(2, minmax(180px, 1fr));
        }
      }

      @media (max-width: 640px) {
        .cfm-meta-group-reference-grid,
        .cfm-meta-group-field-row {
          grid-template-columns: 1fr;
        }
      }
    </style>
  <?php
  }

  private static function render_meta_group_reference_guide(): void
  {
  ?>
    <div class="cfm-meta-group-reference" aria-label="Meta-Group field reference">
      <div class="cfm-meta-group-reference-title">Meta-Group Format</div>
      <div class="cfm-meta-group-reference-grid cfm-meta-group-reference-labels">
        <span title="The administrator-facing name for this Meta-Group." tabindex="0">Label</span>
        <span title="The stable API-friendly identifier. Use lowercase letters, numbers, and hyphens." tabindex="0">Slug</span>
        <span title="Compact display text used where the full label is too long." tabindex="0">Short Label</span>
        <span title="The community-facing description or audience label stored in the existing Community field." tabindex="0">Community</span>
      </div>
      <div class="cfm-meta-group-reference-grid cfm-meta-group-reference-example">
        <span>STEM Teachers</span>
        <span>stem-teachers</span>
        <span>STEM</span>
        <span>STEM Educators</span>
      </div>
    </div>
    <hr class="cfm-meta-group-reference-divider">
  <?php
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

      if ($show_actions && $framework_id && $term_uuid !== '' && self::node_kind($term) === 'term') {
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

  private static function meta_group_term_count(array $node): int
  {
    if (self::node_kind($node) !== 'term') {
      return 0;
    }

    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
    $child_count = 0;

    foreach ($children as $child) {
      if (is_array($child)) {
        $child_count += self::meta_group_term_count($child);
      }
    }

    return $child_count > 0 ? $child_count : 1;
  }

  private static function render_meta_group_term_checklist(array $terms, int $depth = 0, array $selected_uuids = []): void
  {
    if (empty($terms)) {
      return;
    }

    echo '<ul class="cfm-meta-term-list cfm-meta-term-depth-' . esc_attr((string) $depth) . '" style="margin:0; padding-left:' . esc_attr((string) ($depth === 0 ? 0 : 22)) . 'px; list-style:none;">';

    foreach ($terms as $term) {
      if (!is_array($term) || self::node_kind($term) !== 'term') {
        continue;
      }

      $uuid = (string) ($term['uuid'] ?? '');
      $label = (string) ($term['label'] ?? '');
      $children = isset($term['children']) && is_array($term['children']) ? $term['children'] : [];
      $has_children = !empty($children);
      $count = self::meta_group_term_count($term);

      if ($uuid === '' || $label === '') {
        continue;
      }

      echo '<li class="cfm-meta-term-node" data-cfm-meta-node="1" style="margin:4px 0;">';
      echo '<div class="cfm-meta-term-row" style="display:flex; align-items:center; gap:6px; min-height:24px;">';

      if ($has_children) {
        echo '<button type="button" class="button-link cfm-meta-term-toggle" aria-expanded="false" style="width:18px; text-decoration:none;">▸</button>';
      } else {
        echo '<span style="display:inline-block; width:18px;"></span>';
      }

      echo '<label style="display:flex; align-items:center; gap:6px; margin:0;">';
      $checked = in_array($uuid, $selected_uuids, true) ? ' checked' : '';
      echo '<input class="cfm-meta-term-checkbox" type="checkbox" name="meta_group_includes[]" value="' . esc_attr($uuid) . '"' . $checked . '>';
      echo '<span>' . esc_html($label) . '</span>';
      echo '<span class="cfm-meta-term-count" title="Included selectable term count" style="display:inline-block; min-width:18px; padding:0 6px; border-radius:10px; background:#f0f0f1; color:#50575e; font-size:11px; line-height:18px; text-align:center;">' . esc_html((string) $count) . '</span>';
      echo '</label>';
      echo '</div>';

      if ($has_children) {
        echo '<div class="cfm-meta-term-children" style="display:none; margin-left:18px;">';
        self::render_meta_group_term_checklist($children, $depth + 1, $selected_uuids);
        echo '</div>';
      }

      echo '</li>';
    }

    echo '</ul>';
  }

  private static function render_meta_group_term_checklist_script(): void
  {
  ?>
    <script>
      (function() {
        var root = document.querySelector('[data-cfm-meta-term-selector="1"]');
        var selectedCount = document.getElementById('cfm-meta-selected-count');

        if (!root || root.dataset.cfmMetaSelectorLoaded === '1') {
          return;
        }

        root.dataset.cfmMetaSelectorLoaded = '1';

        var closestNode = function(element) {
          while (element && element !== root) {
            if (element.getAttribute && element.getAttribute('data-cfm-meta-node') === '1') {
              return element;
            }
            element = element.parentNode;
          }
          return null;
        };

        var childNodes = function(node) {
          var childrenWrapper = node.querySelector(':scope > .cfm-meta-term-children');
          if (!childrenWrapper) {
            return [];
          }
          return Array.prototype.slice.call(childrenWrapper.querySelectorAll(':scope > .cfm-meta-term-list > .cfm-meta-term-node'));
        };

        var childCheckboxes = function(node) {
          var childrenWrapper = node.querySelector(':scope > .cfm-meta-term-children');
          if (!childrenWrapper) {
            return [];
          }
          return Array.prototype.slice.call(childrenWrapper.querySelectorAll('.cfm-meta-term-checkbox'));
        };

        var updateSelectedCount = function() {
          if (!selectedCount) {
            return;
          }

          var count = root.querySelectorAll('.cfm-meta-term-checkbox:checked').length;
          selectedCount.textContent = count + ' term' + (count === 1 ? '' : 's') + ' selected';
        };

        var updateAncestorStates = function(startNode) {
          var node = startNode;

          while (node) {
            var parent = closestNode(node.parentNode);
            if (!parent) {
              break;
            }

            var parentCheckbox = parent.querySelector(':scope > .cfm-meta-term-row .cfm-meta-term-checkbox');
            var descendants = childCheckboxes(parent);

            if (parentCheckbox && descendants.length) {
              var checkedCount = descendants.filter(function(input) {
                return input.checked;
              }).length;

              parentCheckbox.checked = checkedCount === descendants.length;
              parentCheckbox.indeterminate = checkedCount > 0 && checkedCount < descendants.length;
            }

            node = parent;
          }
        };

        root.addEventListener('click', function(event) {
          var toggle = event.target.closest ? event.target.closest('.cfm-meta-term-toggle') : null;

          if (!toggle || !root.contains(toggle)) {
            return;
          }

          var node = closestNode(toggle);
          var children = node ? node.querySelector(':scope > .cfm-meta-term-children') : null;

          if (!children) {
            return;
          }

          var expanded = toggle.getAttribute('aria-expanded') !== 'false';
          toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
          toggle.textContent = expanded ? '▸' : '▾';
          children.style.display = expanded ? 'none' : '';
        });

        root.addEventListener('change', function(event) {
          var checkbox = event.target;

          if (!checkbox.classList || !checkbox.classList.contains('cfm-meta-term-checkbox')) {
            return;
          }

          var node = closestNode(checkbox);

          if (!node) {
            return;
          }

          childCheckboxes(node).forEach(function(childCheckbox) {
            childCheckbox.checked = checkbox.checked;
            childCheckbox.indeterminate = false;
          });

          checkbox.indeterminate = false;
          updateAncestorStates(node);
          updateSelectedCount();
        });

        var collapseLinks = Array.prototype.slice.call(document.querySelectorAll('[data-cfm-meta-expand]'));
        collapseLinks.forEach(function(link) {
          link.addEventListener('click', function(event) {
            event.preventDefault();
            var expand = link.getAttribute('data-cfm-meta-expand') === '1';
            Array.prototype.slice.call(root.querySelectorAll('.cfm-meta-term-toggle')).forEach(function(toggle) {
              var node = closestNode(toggle);
              var children = node ? node.querySelector(':scope > .cfm-meta-term-children') : null;
              if (!children) {
                return;
              }
              toggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
              toggle.textContent = expand ? '▾' : '▸';
              children.style.display = expand ? '' : 'none';
            });
          });
        });

        Array.prototype.slice.call(root.querySelectorAll('.cfm-meta-term-node')).reverse().forEach(function(node) {
          var checkbox = node.querySelector(':scope > .cfm-meta-term-row .cfm-meta-term-checkbox');
          var descendants = childCheckboxes(node);

          if (checkbox && descendants.length) {
            var checkedCount = descendants.filter(function(input) {
              return input.checked;
            }).length;
            checkbox.checked = checkedCount === descendants.length;
            checkbox.indeterminate = checkedCount > 0 && checkedCount < descendants.length;
          }
        });

        updateSelectedCount();
      }());
    </script>
  <?php
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
      $kind = self::node_kind($node);

      if ($uuid !== '' && self::node_contains_uuid($moving_node, $uuid)) {
        continue;
      }

      if ($uuid !== '' && $label !== '' && $kind === 'term') {
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

      if (self::node_kind($child) === 'term') {
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

    if ($action === 'editor') {
      self::render_core_terms_editor_page();
      return;
    }

    if ($action === 'archived_terms') {
      self::render_archived_terms_page();
      return;
    }

    if ($action === 'data') {
      self::render_data_page();
      return;
    }

    if ($action === 'meta_groups') {
      self::render_meta_groups_page();
      return;
    }

    if ($action === 'maintenance') {
      self::render_maintenance_page();
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

    if ($action === 'edit_meta_group') {
      self::render_edit_meta_group_page();
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
      self::render_core_terms_editor_page();
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
      <h1>Core Terms</h1>

      <?php if (isset($_GET['cfm_created'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Core Terms definition created.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Name and slug are required.</p>
        </div>
      <?php endif; ?>

      <p>
        Define reusable terms for hierarchy, assignment, compilation, and future consumers.
      </p>

      <?php if (!$framework) : ?>
        <div class="card" style="max-width: 760px;">
          <h2>Core Terms</h2>
          <p>
            This site does not have a Core Terms definition yet. Create the primary site Core Terms definition before adding top-level terms or child terms.
          </p>

          <form method="post">
            <?php wp_nonce_field('cfm_create_framework', 'cfm_nonce'); ?>
            <input type="hidden" name="cfm_action" value="create_framework">
            <input type="hidden" name="cfm_name" value="Primary Core Terms">
            <input type="hidden" name="cfm_slug" value="primary">
            <input type="hidden" name="cfm_description" value="Primary Core Terms definition.">

            <?php submit_button('Create Core Terms'); ?>
          </form>
        </div>
      <?php else : ?>
        <div class="card" style="max-width: 760px;">
          <h2>Current Core Terms</h2>

          <table class="widefat striped" style="max-width: 680px;">
            <tbody>
              <tr>
                <th scope="row" style="width: 180px;">Name</th>
                <td><?php echo esc_html($framework->name); ?></td>
              </tr>
              <tr>
                <th scope="row">Top-Level Terms</th>
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
              Manage Core Terms
            </a>

            <?php if (!empty($active_version)) : ?>
              <form method="post" style="display: inline-block; margin-left: 8px;">
                <?php wp_nonce_field('cfm_compile_active_version', 'cfm_nonce'); ?>
                <input type="hidden" name="cfm_action" value="compile_active_version">
                <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">
                <?php submit_button('Rebuild Terms', 'secondary', 'submit', false); ?>
              </form>
            <?php endif; ?>
          </div>

          <?php if ($framework_count > 1) : ?>
            <p class="description">
              Maintenance note: additional internal Core Terms definition records exist. The normal admin flow uses the primary Core Terms definition only.
            </p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php
  }

  private static function batch_added_terms_transient_key(int $framework_id): string
  {
    return 'cfm_batch_added_terms_' . $framework_id . '_' . get_current_user_id();
  }

  private static function batch_error_transient_key(int $framework_id): string
  {
    return 'cfm_batch_error_' . $framework_id . '_' . get_current_user_id();
  }

  private static function redirect_batch_error(int $framework_id, string $parent_uuid, string $message, string $batch_input): void
  {
    if ($framework_id > 0) {
      set_transient(self::batch_error_transient_key($framework_id), [
        'message' => $message,
        'parent_uuid' => $parent_uuid,
        'batch_input' => $batch_input,
      ], 5 * MINUTE_IN_SECONDS);
    }

    wp_safe_redirect(
      self::data_url($framework_id)
          . '&cfm_batch_error=1'
          . '&cfm_parent_uuid=' . rawurlencode($parent_uuid)
          . '#cfm-quick-add'
    );
    exit;
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

    if ($export_type !== 'profilaxes_profile_taxonomy') {
      $errors[] = 'Export type must be profilaxes_profile_taxonomy.';
    }

    $schema_version = isset($import['export_schema_version']) ? (int) $import['export_schema_version'] : 0;

    if (!in_array($schema_version, [1, 2], true)) {
      $errors[] = 'Export schema version must be 1 or 2 for this validator.';
    }

    if (empty($import['exported_at']) || !is_string($import['exported_at'])) {
      $warnings[] = 'Export date is missing or unreadable.';
    }

    if (empty($import['source_of_truth']) || !is_array($import['source_of_truth'])) {
      $warnings[] = 'Source-of-truth metadata is missing. Confirm this file was produced by the Profilaxes exporter.';
    }

    $import_tree = [];

    if (!empty($import['tree']) && is_array($import['tree'])) {
      $import_tree = $import['tree'];
    } elseif (!empty($import['taxonomy_tree']) && is_array($import['taxonomy_tree'])) {
      $import_tree = $import['taxonomy_tree'];
      $warnings[] = 'Legacy taxonomy_tree key detected. Current exports should use tree.';
    } else {
      $errors[] = 'No taxonomy tree was found in the uploaded JSON.';
    }

    $shape_errors = [];
    $shape_warnings = [];
    $duplicate_sibling_slug_paths = [];
    $missing_uuid_count = 0;
    $missing_label_count = 0;
    $missing_slug_count = 0;
    $missing_short_label_count = 0;
    $missing_description_count = 0;
    $invalid_type_count = 0;
    $invalid_includes_count = 0;
    $missing_include_reference_count = 0;
    $duplicate_include_count = 0;
    $self_include_count = 0;
    $non_array_child_count = 0;

    if (!empty($import_tree)) {
      self::normalize_tree_children($import_tree);
      $shape = self::validate_taxonomy_import_tree_shape($import_tree);
      $shape_errors = $shape['errors'];
      $shape_warnings = $shape['warnings'];
      $duplicate_sibling_slug_paths = $shape['duplicate_sibling_slug_paths'];
      $missing_uuid_count = $shape['missing_uuid_count'];
      $missing_label_count = $shape['missing_label_count'];
      $missing_slug_count = $shape['missing_slug_count'];
      $missing_short_label_count = $shape['missing_short_label_count'];
      $missing_description_count = $shape['missing_description_count'];
      $invalid_type_count = $shape['invalid_type_count'];
      $invalid_includes_count = $shape['invalid_includes_count'];
      $missing_include_reference_count = $shape['missing_include_reference_count'];
      $duplicate_include_count = $shape['duplicate_include_count'];
      $self_include_count = $shape['self_include_count'];
      $non_array_child_count = $shape['non_array_child_count'];
    }

    $errors = array_merge($errors, $shape_errors);
    $warnings = array_merge($warnings, $shape_warnings);

    $import_counts = !empty($import_tree)
      ? self::count_profile_tree_nodes($import_tree)
      : ['axes' => 0, 'terms' => 0, 'meta_terms' => 0];

    if (!empty($import_tree) && (int) $import_counts['terms'] <= 0) {
      $warnings[] = 'Uploaded taxonomy does not contain any terms.';
    }

    $current_counts = self::count_profile_tree_nodes($current_tree);
    $import_uuids = !empty($import_tree) ? self::collect_node_uuids_including_duplicates($import_tree) : [];
    $current_uuids = self::collect_node_uuids($current_tree);
    $duplicate_import_uuids = self::find_duplicate_values($import_uuids);
    $uuid_collisions = array_values(array_intersect(array_unique($import_uuids), array_unique($current_uuids)));
    $archived_count = !empty($import_tree) ? self::count_archived_nodes($import_tree) : 0;

    if (!empty($duplicate_import_uuids)) {
      $errors[] = 'The uploaded taxonomy contains duplicate UUIDs.';
    }

    if (!empty($duplicate_sibling_slug_paths)) {
      $errors[] = 'The uploaded taxonomy contains duplicate slugs under the same parent.';
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
    } else {
      $warnings[] = 'Framework metadata is missing.';
    }

    $active_version = [];

    if (!empty($import['active_version']) && is_array($import['active_version'])) {
      $active_version = $import['active_version'];
    }

    return [
      'is_valid' => empty($errors),
      'errors' => array_values(array_unique($errors)),
      'warnings' => array_values(array_unique($warnings)),
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
      'uuid_total' => count(array_filter($import_uuids)),
      'uuid_collision_count' => count($uuid_collisions),
      'uuid_collision_samples' => array_slice($uuid_collisions, 0, 8),
      'duplicate_uuid_count' => count($duplicate_import_uuids),
      'duplicate_uuid_samples' => array_slice($duplicate_import_uuids, 0, 8),
      'duplicate_sibling_slug_count' => count($duplicate_sibling_slug_paths),
      'duplicate_sibling_slug_samples' => array_slice($duplicate_sibling_slug_paths, 0, 8),
      'missing_uuid_count' => $missing_uuid_count,
      'missing_label_count' => $missing_label_count,
      'missing_slug_count' => $missing_slug_count,
      'missing_short_label_count' => $missing_short_label_count,
      'missing_description_count' => $missing_description_count,
      'invalid_type_count' => $invalid_type_count,
      'invalid_includes_count' => $invalid_includes_count,
      'missing_include_reference_count' => $missing_include_reference_count,
      'duplicate_include_count' => $duplicate_include_count,
      'self_include_count' => $self_include_count,
      'non_array_child_count' => $non_array_child_count,
      'tree' => !empty($import_tree) ? $import_tree : [],
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

  private static function collect_node_uuids_including_duplicates(array $node): array
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

        $uuids = array_merge($uuids, self::collect_node_uuids_including_duplicates($child));
      }
    }

    return $uuids;
  }

  private static function validate_taxonomy_import_tree_shape(array $tree): array
  {
    $result = [
      'errors' => [],
      'warnings' => [],
      'duplicate_sibling_slug_paths' => [],
      'missing_uuid_count' => 0,
      'missing_label_count' => 0,
      'missing_slug_count' => 0,
      'missing_short_label_count' => 0,
      'missing_description_count' => 0,
      'invalid_type_count' => 0,
      'invalid_includes_count' => 0,
      'missing_include_reference_count' => 0,
      'duplicate_include_count' => 0,
      'self_include_count' => 0,
      'non_array_child_count' => 0,
    ];

    self::validate_taxonomy_import_node($tree, 'root', true, $result);
    self::validate_taxonomy_import_meta_includes($tree, $result);

    if ($result['missing_uuid_count'] > 0) {
      $result['errors'][] = 'One or more uploaded taxonomy nodes are missing UUIDs.';
    }

    if ($result['missing_label_count'] > 0) {
      $result['errors'][] = 'One or more uploaded taxonomy nodes are missing labels.';
    }

    if ($result['missing_slug_count'] > 0) {
      $result['errors'][] = 'One or more uploaded taxonomy nodes are missing slugs.';
    }

    if ($result['invalid_type_count'] > 0) {
      $result['errors'][] = 'One or more uploaded taxonomy nodes have invalid type/kind values.';
    }

    if ($result['invalid_includes_count'] > 0) {
      $result['errors'][] = 'One or more uploaded meta terms have malformed includes values.';
    }

    if ($result['missing_include_reference_count'] > 0) {
      $result['errors'][] = 'One or more uploaded meta terms include UUIDs that do not exist in the uploaded taxonomy.';
    }

    if ($result['duplicate_include_count'] > 0) {
      $result['errors'][] = 'One or more uploaded meta terms include the same UUID more than once.';
    }

    if ($result['self_include_count'] > 0) {
      $result['errors'][] = 'One or more uploaded meta terms include themselves.';
    }

    if ($result['non_array_child_count'] > 0) {
      $result['errors'][] = 'One or more uploaded taxonomy children are malformed.';
    }

    return $result;
  }

  private static function validate_taxonomy_import_meta_includes(array $tree, array &$result): void
  {
    $known_uuids = self::collect_node_uuid_map($tree);
    self::validate_taxonomy_import_meta_includes_node($tree, $known_uuids, $result);
  }

  private static function collect_node_uuid_map(array $node): array
  {
    $uuids = [];
    $uuid = trim((string) ($node['uuid'] ?? ''));

    if ($uuid !== '') {
      $uuids[$uuid] = true;
    }

    $children = $node['children'] ?? [];

    if (is_array($children)) {
      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $uuids = array_merge($uuids, self::collect_node_uuid_map($child));
      }
    }

    return $uuids;
  }

  private static function validate_taxonomy_import_meta_includes_node(array $node, array $known_uuids, array &$result): void
  {
    $kind = self::node_kind($node);
    $uuid = trim((string) ($node['uuid'] ?? ''));

    if ($kind === 'meta') {
      $includes = $node['includes'] ?? [];

      if (!is_array($includes)) {
        $result['invalid_includes_count']++;
      } else {
        $seen = [];

        foreach ($includes as $included_uuid) {
          if (!is_scalar($included_uuid)) {
            $result['invalid_includes_count']++;
            continue;
          }

          $included_uuid = trim((string) $included_uuid);

          if ($included_uuid === '') {
            $result['invalid_includes_count']++;
            continue;
          }

          if ($uuid !== '' && $included_uuid === $uuid) {
            $result['self_include_count']++;
          }

          if (isset($seen[$included_uuid])) {
            $result['duplicate_include_count']++;
          }

          if (!isset($known_uuids[$included_uuid])) {
            $result['missing_include_reference_count']++;
          }

          $seen[$included_uuid] = true;
        }
      }
    }

    $children = $node['children'] ?? [];

    if (!is_array($children)) {
      return;
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      self::validate_taxonomy_import_meta_includes_node($child, $known_uuids, $result);
    }
  }

  private static function validate_taxonomy_import_node(array $node, string $path, bool $is_root, array &$result): void
  {
    $kind = self::node_kind($node);
    $type = $kind;
    $uuid = trim((string) ($node['uuid'] ?? ''));
    $label = trim((string) ($node['label'] ?? ''));
    $slug = self::normalize_slug((string) ($node['slug'] ?? ''));
    $short_label = trim((string) ($node['short_label'] ?? ''));
    $description = trim((string) ($node['description'] ?? ''));

    if ($uuid === '') {
      $result['missing_uuid_count']++;
    }

    if (!$is_root && $label === '') {
      $result['missing_label_count']++;
    }

    if (!$is_root && $slug === '') {
      $result['missing_slug_count']++;
    }

    if (!$is_root && $short_label === '') {
      $result['missing_short_label_count']++;
    }

    if (!$is_root && $description === '') {
      $result['missing_description_count']++;
    }

    if ($is_root) {
      if ($kind !== '' && !in_array($kind, ['root', 'framework'], true)) {
        $result['warnings'][] = 'Root node kind is not root/framework. Review before using a future write-capable import.';
      }
    } elseif (!in_array($kind, ['term', 'meta'], true)) {
      $result['invalid_type_count']++;
    }

    $children = $node['children'] ?? [];

    if (!is_array($children)) {
      $result['non_array_child_count']++;
      return;
    }

    $sibling_slugs = [];

    foreach ($children as $index => $child) {
      if (!is_array($child)) {
        $result['non_array_child_count']++;
        continue;
      }

      $child_label = trim((string) ($child['label'] ?? ''));
      $child_slug = self::normalize_slug((string) ($child['slug'] ?? ''));
      $child_path = $path . ' > ' . ($child_label !== '' ? $child_label : 'child-' . ((int) $index + 1));

      if ($child_slug !== '') {
        if (isset($sibling_slugs[$child_slug])) {
          $result['duplicate_sibling_slug_paths'][] = $path . ' / ' . $child_slug;
        } else {
          $sibling_slugs[$child_slug] = true;
        }
      }

      self::validate_taxonomy_import_node($child, $child_path, false, $result);
    }
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
    <div id="cfm-import-preview" class="notice <?php echo !empty($preview['is_valid']) ? 'notice-info' : 'notice-error'; ?>" style="padding: 12px 16px; margin-top: 12px;">
      <h3 style="margin-top: 0;">Import Preview</h3>

      <?php if (empty($preview['is_valid'])) : ?>
        <p><strong>This file is not ready for import.</strong></p>
      <?php else : ?>
        <p><strong>Validated.</strong> No database rows were changed by preview. You may now replace the current Core Terms with the uploaded tree.</p>
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
            <th>Uploaded Terms</th>
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
            <th>Current Terms</th>
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
          <tr>
            <th>Duplicate Sibling Slugs</th>
            <td><?php echo esc_html((string) ($preview['duplicate_sibling_slug_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Missing UUIDs</th>
            <td><?php echo esc_html((string) ($preview['missing_uuid_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Missing Labels</th>
            <td><?php echo esc_html((string) ($preview['missing_label_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Missing Slugs</th>
            <td><?php echo esc_html((string) ($preview['missing_slug_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Missing Short Labels</th>
            <td><?php echo esc_html((string) ($preview['missing_short_label_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Missing Communities</th>
            <td><?php echo esc_html((string) ($preview['missing_description_count'] ?? 0)); ?></td>
          </tr>
          <tr>
            <th>Invalid Types</th>
            <td><?php echo esc_html((string) ($preview['invalid_type_count'] ?? 0)); ?></td>
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

      <?php if (!empty($preview['duplicate_sibling_slug_samples']) && is_array($preview['duplicate_sibling_slug_samples'])) : ?>
        <p><strong>Duplicate sibling slug samples:</strong></p>
        <ul>
          <?php foreach ($preview['duplicate_sibling_slug_samples'] as $slug_path) : ?>
            <li><code><?php echo esc_html((string) $slug_path); ?></code></li>
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
      </p>

      <?php if (!empty($preview['is_valid'])) : ?>
        <form method="post" style="margin-top: 12px; padding: 12px; border: 1px solid #d63638; background: #fff; max-width: 900px;">
          <?php wp_nonce_field('cfm_import_taxonomy_replace', 'cfm_nonce'); ?>
          <input type="hidden" name="cfm_action" value="import_taxonomy_replace">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) ($_GET['framework_id'] ?? '')); ?>">

          <p><strong>Danger zone:</strong> this will replace the current canonical editable Core Terms tree, automatically save the current tree as a recovery snapshot, and rebuild runtime tables.</p>

          <label>
            <input type="checkbox" name="confirm_replace_taxonomy" value="1" required>
            I understand this will replace the current Core Terms.
          </label>

          <p style="margin-top: 12px;">
            <?php submit_button('Import as Replacement', 'primary', 'submit', false); ?>
          </p>
        </form>
      <?php else : ?>
        <p><button type="button" class="button button-primary" disabled>Import unavailable — validation failed</button></p>
      <?php endif; ?>
    </div>
  <?php
  }


  private static function count_profile_tree_nodes(array $node, int $depth = 0): array
  {
    $counts = [
      'axes' => 0,
      'terms' => 0,
      'meta_terms' => 0,
    ];

    $kind = self::node_kind($node);

    if ($depth === 1 && $kind === 'term') {
      $counts['axes']++;
    }

    if ($kind === 'term') {
      $counts['terms']++;
    } elseif ($kind === 'meta') {
      $counts['meta_terms']++;
    }

    $children = $node['children'] ?? [];

    if (is_array($children)) {
      foreach ($children as $child) {
        if (!is_array($child)) {
          continue;
        }

        $child_counts = self::count_profile_tree_nodes($child, $depth + 1);

        $counts['axes'] += $child_counts['axes'];
        $counts['terms'] += $child_counts['terms'];
        $counts['meta_terms'] += $child_counts['meta_terms'];
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

  private static function editor_url(int $framework_id): string
  {
    return admin_url(
      'admin.php?page=cfm-frameworks'
        . '&action=editor'
        . '&framework_id=' . $framework_id
    );
  }

  private static function archived_terms_url(int $framework_id = 0): string
  {
    $url = admin_url('admin.php?page=cfm-frameworks&action=archived_terms');

    if ($framework_id > 0) {
      $url = add_query_arg('framework_id', $framework_id, $url);
    }

    return $url;
  }

  private static function data_url(int $framework_id = 0): string
  {
    $url = admin_url('admin.php?page=cfm-frameworks&action=data');

    if ($framework_id > 0) {
      $url = add_query_arg('framework_id', $framework_id, $url);
    }

    return $url;
  }

  private static function meta_groups_url(int $framework_id = 0): string
  {
    $url = admin_url('admin.php?page=cfm-frameworks&action=meta_groups');

    if ($framework_id > 0) {
      $url = add_query_arg('framework_id', $framework_id, $url);
    }

    return $url;
  }

  private static function maintenance_url(int $framework_id = 0): string
  {
    $url = admin_url('admin.php?page=cfm-frameworks&action=maintenance');

    if ($framework_id > 0) {
      $url = add_query_arg('framework_id', $framework_id, $url);
    }

    return $url;
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

  private static function edit_meta_group_url(int $framework_id, string $meta_group_uuid): string
  {
    return add_query_arg([
      'page' => 'cfm-frameworks',
      'action' => 'edit_meta_group',
      'framework_id' => $framework_id,
      'meta_group_uuid' => $meta_group_uuid,
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

  private static function primary_framework_for_admin(): ?object
  {
    $frameworks = CFM_Framework_Repository::get_frameworks();

    if (empty($frameworks)) {
      return null;
    }

    foreach ($frameworks as $framework) {
      if ((string) ($framework->slug ?? '') === 'primary') {
        return $framework;
      }
    }

    return $frameworks[0];
  }

  private static function render_admin_placeholder_page(string $title, string $description, array $links = []): void
  {
  ?>
    <div class="wrap">
      <h1><?php echo esc_html($title); ?></h1>
      <p><?php echo esc_html($description); ?></p>

      <?php if (!empty($links)) : ?>
        <p>
          <?php foreach ($links as $link) : ?>
            <a class="button button-secondary" href="<?php echo esc_url((string) ($link['url'] ?? '')); ?>">
              <?php echo esc_html((string) ($link['label'] ?? 'Open')); ?>
            </a>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>
    </div>
  <?php
  }

  public static function render_data_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $framework = $framework_id > 0
      ? CFM_Framework_Repository::get_framework($framework_id)
      : self::primary_framework_for_admin();

    if (!$framework) {
      self::render_admin_placeholder_page(
        'Core Terms Data',
        'Create the primary Core Terms definition before using import, export, quick add, examples, or version history tools.',
        [
          [
            'label' => 'Open Dashboard',
            'url' => admin_url('admin.php?page=cfm-frameworks'),
          ],
        ]
      );
      return;
    }

    $framework_id = (int) $framework->id;
    $tree = self::get_framework_tree($framework);
    $axes = self::root_terms($tree);

    $import_preview = null;

    if (isset($_GET['cfm_import_preview'])) {
      $maybe_preview = get_transient(self::import_preview_transient_key($framework_id));

      if (is_array($maybe_preview)) {
        $import_preview = $maybe_preview;
      }
    }

    $batch_added_terms = null;

    if (isset($_GET['cfm_terms_batch_added'])) {
      $maybe_batch_added_terms = get_transient(self::batch_added_terms_transient_key($framework_id));

      if (is_array($maybe_batch_added_terms)) {
        $batch_added_terms = $maybe_batch_added_terms;
        delete_transient(self::batch_added_terms_transient_key($framework_id));
      }
    }

    $batch_error = null;

    if (isset($_GET['cfm_batch_error'])) {
      $maybe_batch_error = get_transient(self::batch_error_transient_key($framework_id));

      if (is_array($maybe_batch_error)) {
        $batch_error = $maybe_batch_error;
        delete_transient(self::batch_error_transient_key($framework_id));
      }
    }

    $version_count = CFM_Framework_Repository::count_versions($framework_id);
    $recent_versions = CFM_Framework_Repository::get_versions($framework_id, 3, 0);
    $selected_parent_uuid = sanitize_text_field(wp_unslash($_GET['cfm_parent_uuid'] ?? '__top_level__'));

  ?>
    <div class="wrap">
      <h1>Core Terms Data</h1>

      <p>
        <a class="button button-secondary" href="<?php echo esc_url(self::editor_url($framework_id)); ?>">Core Terms Editor</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::edit_url($framework_id)); ?>">Legacy Maintenance View</a>
      </p>

      <?php if (is_array($batch_error)) : ?>
        <style>
          .cfm-modal-overlay {
            align-items: center;
            background: rgba(0, 0, 0, 0.45);
            bottom: 0;
            display: flex;
            justify-content: center;
            left: 0;
            position: fixed;
            right: 0;
            top: 0;
            z-index: 100000;
          }

          .cfm-modal-card {
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            max-width: 520px;
            padding: 24px;
            width: calc(100% - 48px);
          }

          .cfm-modal-card h2 {
            margin-top: 0;
          }
        </style>
        <div id="cfm-batch-error-modal" class="cfm-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="cfm-batch-error-title">
          <div class="cfm-modal-card">
            <h2 id="cfm-batch-error-title">Unable to add terms</h2>
            <p><?php echo esc_html((string) ($batch_error['message'] ?? 'No terms were added.')); ?></p>
            <p><strong>No terms were added.</strong></p>
            <p>Review the batch and submit again.</p>
            <p>
              <button type="button" class="button button-primary" id="cfm-batch-error-review">Review Batch</button>
            </p>
          </div>
        </div>
      <?php endif; ?>

      <?php if (is_array($batch_added_terms)) : ?>
        <?php
        $batch_created_count = isset($batch_added_terms['created_count'])
          ? absint($batch_added_terms['created_count'])
          : count((array) ($batch_added_terms['terms'] ?? []));
        $batch_skipped_existing_count = absint($batch_added_terms['skipped_existing_count'] ?? 0);
        $batch_errors_count = absint($batch_added_terms['errors_count'] ?? 0);
        $batch_skipped_existing = isset($batch_added_terms['skipped_existing']) && is_array($batch_added_terms['skipped_existing'])
          ? $batch_added_terms['skipped_existing']
          : [];
        ?>
        <div id="cfm-batch-added" class="notice notice-success is-dismissible">
          <p>
            <strong>Batch processed.</strong>
            Created: <?php echo esc_html((string) $batch_created_count); ?>.
            Skipped existing: <?php echo esc_html((string) $batch_skipped_existing_count); ?>.
            Errors: <?php echo esc_html((string) $batch_errors_count); ?>.
          </p>
          <?php if (!empty($batch_skipped_existing)) : ?>
            <p>
              <strong>Skipped labels:</strong>
              <?php
              $skipped_labels = array_map(
                static function ($term) {
                  return is_array($term) ? (string) ($term['label'] ?? '') : '';
                },
                $batch_skipped_existing
              );
              echo esc_html(implode(', ', array_filter($skipped_labels)));
              ?>
            </p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_example_pack_installed'])) : ?>
        <?php
        $example_pack = sanitize_key(wp_unslash($_GET['cfm_example_pack_installed']));
        $example_pack_label = class_exists('CFM_Seeder') ? CFM_Seeder::get_pack_label($example_pack) : 'Example Terms';
        $example_created = absint($_GET['cfm_example_created'] ?? 0);
        $example_skipped = absint($_GET['cfm_example_skipped'] ?? 0);
        ?>
        <div class="notice notice-success is-dismissible">
          <p>
            <?php echo esc_html($example_pack_label); ?> checked.
            Created: <strong><?php echo esc_html((string) $example_created); ?></strong>.
            Skipped existing: <strong><?php echo esc_html((string) $example_skipped); ?></strong>.
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_import_replaced'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>
            Terms imported as a replacement and runtime tables rebuilt.
            A recovery snapshot was saved automatically before the import.
            <?php if (!empty($_GET['cfm_import_snapshot_id'])) : ?>
              <a href="<?php echo esc_url(self::version_snapshot_url($framework_id, absint($_GET['cfm_import_snapshot_id']))); ?>">View recovery snapshot</a>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_recovery_snapshot_restored'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>
            Recovery snapshot restored and runtime tables rebuilt.
            A pre-restore snapshot was saved automatically before the restore.
            <?php if (!empty($_GET['cfm_pre_restore_snapshot_id'])) : ?>
              <a href="<?php echo esc_url(self::version_snapshot_url($framework_id, absint($_GET['cfm_pre_restore_snapshot_id']))); ?>">View pre-restore snapshot</a>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'invalid_example_pack') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Example term pack could not be installed. Choose a valid pack and try again.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && in_array($_GET['cfm_error'], ['missing_import_framework', 'missing_import_file', 'import_upload_failed', 'import_file_too_large', 'import_file_empty', 'import_invalid_json'], true)) : ?>
        <div class="notice notice-error is-dismissible">
          <p>Import preview could not be generated. Confirm you selected a valid Core Terms JSON export and try again.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && in_array($_GET['cfm_error'], ['import_replace_not_confirmed', 'import_preview_expired', 'import_snapshot_failed', 'import_compile_failed'], true)) : ?>
        <div class="notice notice-error is-dismissible">
          <p>Import replacement could not be completed. Preview the export again, confirm replacement, and retry. No replacement was completed if snapshot creation failed.</p>
        </div>
      <?php endif; ?>

      <h2>History</h2>
      <p class="description">
        History records active taxonomy versions and automatic recovery snapshots, including snapshots created before replacement imports.
      </p>

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
              <th>Recent Item</th>
              <th>Status</th>
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
                    <span style="color: #008a20; margin-left: 6px;">Current</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((string) $version_row->status === 'pre_import_snapshot') : ?>
                    Recovery snapshot
                  <?php else : ?>
                    <?php echo esc_html(ucwords(str_replace('_', ' ', (string) $version_row->status))); ?>
                  <?php endif; ?>
                </td>
                <td><?php echo esc_html($version_row->created_at); ?></td>
                <td><?php echo esc_html((string) strlen((string) $version_row->tree_json)); ?> bytes</td>
                <td>
                  <a href="<?php echo esc_url(self::version_snapshot_url($framework_id, (int) $version_row->id)); ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p>
        <a class="button" href="<?php echo esc_url(self::versions_url($framework_id)); ?>">
          View Full History
        </a>
      </p>

      <hr>

      <h2>Export</h2>
      <p class="description">
        Download the canonical editable Core Terms definition tree as JSON. This export preserves UUIDs, hierarchy, order, archive state when present, and active version metadata. Runtime compiler tables are intentionally not exported because they can be rebuilt.
      </p>
      <p>
        <a class="button" href="<?php echo esc_url(self::export_taxonomy_url($framework_id)); ?>">
          Export Terms JSON
        </a>
      </p>

      <hr>

      <h2 id="cfm-import">Import</h2>
      <p class="description">
        Upload a Core Terms JSON export to validate it and preview what it contains. After a valid preview, you may import it as a full replacement. Replacement automatically saves the current tree as a recovery snapshot and rebuilds runtime tables.
      </p>

      <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('cfm_import_taxonomy_preview', 'cfm_nonce'); ?>
        <input type="hidden" name="cfm_action" value="import_taxonomy_preview">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework_id); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="taxonomy_import_file">Import Terms JSON</label>
            </th>
            <td>
              <input name="taxonomy_import_file" id="taxonomy_import_file" type="file" accept="application/json,.json" required>
              <p class="description">Preview only. No taxonomy rows, compiled rows, or user assignments are changed.</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Preview Import', 'secondary', 'submit', false, ['id' => 'cfm-preview-import-button', 'disabled' => 'disabled']); ?>
      </form>

      <script>
        (function() {
          var fileInput = document.getElementById('taxonomy_import_file');
          var previewButton = document.getElementById('cfm-preview-import-button');

          if (!fileInput || !previewButton) {
            return;
          }

          var refreshPreviewButton = function() {
            previewButton.disabled = !fileInput.files || fileInput.files.length === 0;
          };

          fileInput.addEventListener('change', refreshPreviewButton);
          refreshPreviewButton();
        }());
      </script>

      <?php if (is_array($import_preview)) : ?>
        <?php self::render_taxonomy_import_preview($import_preview); ?>
      <?php endif; ?>

      <hr>

      <h2 id="cfm-example-packs">Example Term Packs</h2>
      <p class="description">
        Install optional starter location terms. Packs are additive and safe to rerun: existing sibling slugs are skipped, and existing UUIDs and assignments are preserved.
      </p>

      <?php if (class_exists('CFM_Seeder')) : ?>
        <div style="display:flex; gap:12px; flex-wrap:wrap; max-width: 1000px;">
          <div class="card" style="max-width: 420px;">
            <h3>Geography - US States</h3>
            <form method="post">
              <?php wp_nonce_field('cfm_install_example_pack', 'cfm_nonce'); ?>
              <input type="hidden" name="cfm_action" value="install_example_pack">
              <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework_id); ?>">
              <input type="hidden" name="example_pack" value="<?php echo esc_attr(CFM_Seeder::PACK_GEOGRAPHY_US_STATES); ?>">
              <?php submit_button('Install US States', 'secondary', 'submit', false); ?>
            </form>
            <p>Creates <strong>Region -> United States</strong>, then adds the 50 states and District of Columbia beneath United States.</p>
          </div>

          <div class="card" style="max-width: 420px;">
            <h3>Geography - Countries Lite</h3>
            <form method="post">
              <?php wp_nonce_field('cfm_install_example_pack', 'cfm_nonce'); ?>
              <input type="hidden" name="cfm_action" value="install_example_pack">
              <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework_id); ?>">
              <input type="hidden" name="example_pack" value="<?php echo esc_attr(CFM_Seeder::PACK_GEOGRAPHY_COUNTRIES_LITE); ?>">
              <?php submit_button('Install Countries Lite', 'secondary', 'submit', false); ?>
            </form>
            <p>Adds a small set of broadly useful country/global terms under <strong>Region</strong>. It does not replace or move United States or state terms.</p>
          </div>
        </div>
      <?php else : ?>
        <p>Example term packs are unavailable because the seeder class is not loaded.</p>
      <?php endif; ?>

      <hr>

      <h2 id="cfm-quick-add">Quick Add / Bulk Add</h2>
      <p class="description">Create multiple sibling terms at once. One term per line.</p>
      <form method="post">
        <?php wp_nonce_field('cfm_add_terms_batch', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="add_terms_batch">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework_id); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="batch_parent_uuid">Parent Term</label>
            </th>
            <td>
              <select name="parent_uuid" id="batch_parent_uuid">
                <option value="" <?php selected($selected_parent_uuid, '__top_level__'); ?>>Add as Top-Level Terms</option>
                <?php self::render_parent_options($axes, $selected_parent_uuid); ?>
              </select>
              <p class="description">Leave unchanged to create top-level sibling terms, or select a parent to create child sibling terms under it.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="batch_term_labels">Term Labels</label>
            </th>
            <td>
              <textarea name="batch_term_labels" id="batch_term_labels" class="large-text" rows="6" placeholder="Grade 4&#10;Grade 5&#10;Grade 6"><?php echo esc_textarea(is_array($batch_error) ? (string) ($batch_error['batch_input'] ?? '') : ''); ?></textarea>
              <p class="description">One term label per line. Slug, short label, and Community are generated from each label. Existing sibling terms are skipped and reported.</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Create Terms'); ?>
      </form>

      <?php if (is_array($batch_error)) : ?>
        <script>
          (function() {
            var review = document.getElementById('cfm-batch-error-review');
            var modal = document.getElementById('cfm-batch-error-modal');
            var textarea = document.getElementById('batch_term_labels');

            function returnToBatch() {
              if (modal) {
                modal.style.display = 'none';
              }
              if (textarea) {
                textarea.focus();
              }
            }

            if (review) {
              review.addEventListener('click', returnToBatch);
              review.focus();
            }

            if (modal) {
              modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                  returnToBatch();
                }
              });
            }

            document.addEventListener('keydown', function(event) {
              if (event.key === 'Escape' && modal && modal.style.display !== 'none') {
                returnToBatch();
              }
            });
          })();
        </script>
      <?php endif; ?>
    </div>
  <?php
  }

  public static function render_meta_groups_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $framework = $framework_id > 0
      ? CFM_Framework_Repository::get_framework($framework_id)
      : self::primary_framework_for_admin();

    if (!$framework) {
      self::render_admin_placeholder_page(
        'Core Terms Meta-Groups',
        'Create the primary Core Terms definition before managing Meta-Groups.',
        [
          [
            'label' => 'Open Dashboard',
            'url' => admin_url('admin.php?page=cfm-frameworks'),
          ],
        ]
      );
      return;
    }

    $framework_id = (int) $framework->id;
    $tree = self::get_framework_tree($framework);
    $meta_groups = self::root_meta_groups($tree);
    $available_terms = self::collect_assignable_term_nodes($tree);
    $terms_by_uuid = [];

    foreach ($available_terms as $available_term) {
      $available_uuid = (string) ($available_term['uuid'] ?? '');

      if ($available_uuid !== '') {
        $terms_by_uuid[$available_uuid] = $available_term;
      }
    }

  ?>
    <div class="wrap">
      <?php self::render_meta_group_admin_styles(); ?>

      <h1>Core Terms Meta-Groups</h1>

      <p>
        <a class="button button-secondary" href="<?php echo esc_url(self::editor_url($framework_id)); ?>">Core Terms Editor</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::edit_url($framework_id)); ?>">Legacy Maintenance View</a>
      </p>

      <?php if (isset($_GET['cfm_meta_group_added'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Meta-Group added.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_meta_group_updated'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Meta-Group updated.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_meta_group_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Meta-Group label, slug, and at least two included terms are required.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'invalid_meta_group_includes') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Meta-Group includes must reference existing terms only.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'duplicate_meta_group_slug') : ?>
        <div class="notice notice-error is-dismissible">
          <p>That Meta-Group slug already exists at the top level. Choose a different slug.</p>
        </div>
      <?php endif; ?>

      <h2 id="cfm-meta-groups">Meta-Groups</h2>
      <div class="notice notice-info inline">
        <p><strong>Meta-Groups are audience-only collections.</strong> They collect existing terms for future audience and extension use without changing the term tree.</p>
        <p>Users are assigned terms, not Meta-Groups. A Meta-Group can include terms from different branches without moving, copying, or replacing those terms.</p>
      </div>

      <?php self::render_meta_groups_table($meta_groups, $terms_by_uuid, $framework_id); ?>

      <h3>Create Meta-Group</h3>

      <?php if (count($available_terms) < 2) : ?>
        <p>Create at least two terms before adding a Meta-Group.</p>
      <?php else : ?>
        <form method="post">
          <?php wp_nonce_field('cfm_add_meta_group', 'cfm_nonce'); ?>

          <input type="hidden" name="cfm_action" value="add_meta_group">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework_id); ?>">

          <?php self::render_meta_group_reference_guide(); ?>

          <div class="cfm-meta-group-field-row">
            <div class="cfm-meta-group-field">
              <label for="meta_group_label">Meta-Group Label</label>
              <input name="meta_group_label" id="meta_group_label" type="text" data-cfm-autofill-label="add-meta-group" required>
              <p class="description">Example: STEM, New Teachers, K-5 Science</p>
            </div>
            <div class="cfm-meta-group-field">
              <label for="meta_group_slug">Slug</label>
              <input name="meta_group_slug" id="meta_group_slug" type="text" data-cfm-autofill-target="add-meta-group" data-cfm-autofill-type="slug">
              <p class="description">Example: stem, new-teachers, k-5-science</p>
            </div>
            <div class="cfm-meta-group-field">
              <label for="meta_group_short_label">Short Label</label>
              <input name="meta_group_short_label" id="meta_group_short_label" type="text" data-cfm-autofill-target="add-meta-group" data-cfm-autofill-type="copy">
              <p class="description">Compact display text.</p>
            </div>
            <div class="cfm-meta-group-field">
              <label for="meta_group_description">Community</label>
              <input name="meta_group_description" id="meta_group_description" type="text" data-cfm-autofill-target="add-meta-group" data-cfm-autofill-type="copy">
              <p class="description">Community-facing context.</p>
            </div>
          </div>

          <div class="cfm-meta-group-term-selector" data-cfm-meta-term-selector="1">
            <h4>Included Terms</h4>
            <p class="description" style="margin-top:0;">Select existing terms only. Parent checkboxes select or clear all descendant terms.</p>
            <div class="cfm-meta-group-term-toolbar">
              <span>
                <a href="#" data-cfm-meta-expand="1">Expand all</a>
                <span aria-hidden="true"> | </span>
                <a href="#" data-cfm-meta-expand="0">Collapse all</a>
              </span>
              <span id="cfm-meta-selected-count" class="description">0 terms selected</span>
            </div>
            <fieldset class="cfm-meta-group-term-tree">
              <legend class="screen-reader-text">Included Terms</legend>
              <?php self::render_meta_group_term_checklist(self::root_terms($tree)); ?>
            </fieldset>
            <p class="description">Meta-Groups do not create new terms, move terms in the tree, or become directly assignable user values.</p>
          </div>

          <?php submit_button('Add Meta-Group'); ?>
        </form>
        <?php self::render_meta_group_term_checklist_script(); ?>
      <?php endif; ?>

      <?php self::render_term_metadata_autofill_script(); ?>
    </div>
  <?php
  }

  public static function render_maintenance_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    $framework = $framework_id > 0
      ? CFM_Framework_Repository::get_framework($framework_id)
      : self::primary_framework_for_admin();

    if (!$framework) {
      self::render_admin_placeholder_page(
        'Core Terms Maintenance',
        'Create the primary Core Terms definition before using rebuild and diagnostic tools.',
        [
          [
            'label' => 'Open Dashboard',
            'url' => admin_url('admin.php?page=cfm-frameworks'),
          ],
        ]
      );
      return;
    }

    $framework_id = (int) $framework->id;
    $active_version = CFM_Framework_Repository::get_active_version($framework_id);
    $compiled_counts = $active_version
      ? CFM_Framework_Repository::get_compiled_counts($framework_id, (int) $active_version->id)
      : ['terms' => 0, 'closure' => 0];

  ?>
    <div class="wrap">
      <h1>Core Terms Maintenance</h1>

      <p>
        <a class="button button-secondary" href="<?php echo esc_url(self::editor_url($framework_id)); ?>">Core Terms Editor</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::edit_url($framework_id)); ?>">Legacy Maintenance View</a>
      </p>

      <?php if (isset($_GET['cfm_compiled'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'no_active_version') : ?>
        <div class="notice notice-error is-dismissible">
          <p>No active version exists to compile.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'compile_failed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Runtime rebuild failed. The saved profile tree may not match the query tables. Check PHP error logs, then retry compile.</p>
        </div>
      <?php endif; ?>

      <h2>Compiler</h2>
      <p class="description">
        Rebuild the active runtime tables from the canonical Core Terms tree. Use this only when compiled query data needs to be refreshed or inspected.
      </p>

      <?php if (!$active_version) : ?>
        <p>No active version exists to compile.</p>
      <?php else : ?>
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
          <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework_id); ?>">
          <?php submit_button('Rebuild Core Terms', 'secondary', 'submit', false); ?>
          <a class="button" href="<?php echo esc_url(self::compiled_debug_url($framework_id)); ?>" style="margin-left: 8px;">Open Compiled Query Debug</a>
        </form>
      <?php endif; ?>

      <hr>

      <h2>Diagnostics</h2>
      <p class="description">
        Compiled Query Debug contains the existing read-only diagnostics and smoke-test forms for compiled terms, Audience API helpers, and audience contract checks.
      </p>
      <p>
        <a class="button button-secondary" href="<?php echo esc_url(self::compiled_debug_url($framework_id)); ?>">Open Compiled Query Debug</a>
      </p>
    </div>
  <?php
  }

  public static function render_archived_terms_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = absint($_GET['framework_id'] ?? 0);
    $framework = $framework_id > 0
      ? CFM_Framework_Repository::get_framework($framework_id)
      : null;

    if ($framework_id > 0 && !$framework) {
      wp_die('Core Terms definition not found.');
    }

    $archives = CFM_Framework_Repository::get_term_archives($framework_id, false, false);
    $now = current_time('timestamp');

    ?>
    <div class="wrap">
      <h1>Archived Terms</h1>

      <p>
        <?php if ($framework) : ?>
          <a class="button button-secondary" href="<?php echo esc_url(self::editor_url((int) $framework->id)); ?>">Back to Core Terms Editor</a>
          <a class="button button-secondary" href="<?php echo esc_url(self::edit_url((int) $framework->id)); ?>">Back to Core Terms</a>
        <?php else : ?>
          <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks')); ?>">Back to Core Terms</a>
        <?php endif; ?>
      </p>

      <?php if (isset($_GET['cfm_archive_restored'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Archived Core Terms branch restored.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_archive_deleted'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Archived Core Terms branch deleted from restore eligibility.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_archive_restore_error'])) :
        $error = sanitize_key(wp_unslash($_GET['cfm_archive_restore_error']));
        $message = 'Archived Core Terms branch could not be restored.';

        if ($error === 'missing') {
          $message = 'Archived Core Terms branch could not be found.';
        } elseif ($error === 'restored') {
          $message = 'Archived Core Terms branch has already been restored.';
        } elseif ($error === 'deleted') {
          $message = 'Deleted Core Terms archives cannot be restored from this screen.';
        } elseif ($error === 'conflict') {
          $message = 'Archived Core Terms branch could not be restored because it conflicts with the active tree.';
        }
      ?>
        <div class="notice notice-error is-dismissible">
          <p><?php echo esc_html($message); ?></p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_archive_delete_error'])) :
        $error = sanitize_key(wp_unslash($_GET['cfm_archive_delete_error']));
        $message = 'Archived Core Terms branch could not be deleted.';

        if ($error === 'missing') {
          $message = 'Archived Core Terms branch could not be found.';
        } elseif ($error === 'restored') {
          $message = 'Restored Core Terms archives cannot be deleted from this screen.';
        } elseif ($error === 'deleted') {
          $message = 'Archived Core Terms branch has already been deleted.';
        }
      ?>
        <div class="notice notice-error is-dismissible">
          <p><?php echo esc_html($message); ?></p>
        </div>
      <?php endif; ?>

      <?php if (empty($archives)) : ?>
        <p>No archived branches are currently available to restore.</p>
      <?php else : ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th scope="col">Branch</th>
              <th scope="col">Archived Date</th>
              <th scope="col">Days Archived</th>
              <th scope="col">Archived By</th>
              <th scope="col">Active Connections</th>
              <th scope="col">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($archives as $archive) :
              $archive_framework_id = (int) ($archive->framework_id ?? 0);
              $branch = json_decode((string) ($archive->branch_json ?? ''), true);
              $branch_label = is_array($branch) && !empty($branch['label'])
                ? (string) $branch['label']
                : '(Unknown branch)';
              $branch_term_uuids = is_array($branch) ? CFM::collect_branch_term_uuids($branch) : [];
              $descendant_count = max(0, count($branch_term_uuids) - 1);
              $descendant_suffix = '';
              $action_noun = $descendant_count > 0 ? 'Branch' : 'Term';

              if ($descendant_count > 0) {
                $descendant_label = $descendant_count === 1 ? 'descendant' : 'descendants';
                $descendant_suffix = ' (+' . number_format_i18n($descendant_count) . ' ' . $descendant_label . ')';
              }

              $connection_sources = CFM::get_term_connection_sources([
                'framework_id' => $archive_framework_id,
                'archive_id' => (int) ($archive->id ?? 0),
                'archive_key' => (string) ($archive->archive_key ?? ''),
                'root_term_uuid' => (string) ($archive->root_term_uuid ?? ''),
                'branch_term_uuids' => $branch_term_uuids,
                'branch_label' => $branch_label,
              ]);
              $archived_at = (string) ($archive->archived_at ?? '');
              $archived_timestamp = $archived_at !== '' ? strtotime($archived_at) : false;
              $days_archived = $archived_timestamp
                ? max(0, (int) floor(($now - $archived_timestamp) / DAY_IN_SECONDS))
                : 0;
              $archived_by = (int) ($archive->archived_by ?? 0);
              $archived_user = $archived_by > 0 ? get_userdata($archived_by) : false;
              $restored_at = (string) ($archive->restored_at ?? '');
              $deleted_at = (string) ($archive->deleted_at ?? '');
              $can_restore = $restored_at === '' && $deleted_at === '';
              $can_delete = $restored_at === '' && $deleted_at === '';
              $recent_archive = $days_archived < 7;
              $delete_confirmation = $recent_archive
                ? sprintf(
                  "Delete the archived branch record for \"%s\"?\n\nThis archive was created recently. If you delete it now, this branch cannot be restored from Archived Terms. The active Core Terms tree will not be changed.",
                  $branch_label
                )
                : sprintf(
                  "Delete the archived branch record for \"%s\"?\n\nThis branch will no longer be restorable from Archived Terms. The active Core Terms tree will not be changed.",
                  $branch_label
                );
            ?>
              <tr>
                <td style="white-space:nowrap;">
                  <strong><?php echo esc_html($branch_label); ?></strong><?php echo esc_html($descendant_suffix); ?>
                </td>
                <td style="white-space:nowrap;"><?php echo esc_html($archived_at !== '' ? $archived_at : 'Unknown'); ?></td>
                <td style="white-space:nowrap;"><?php echo esc_html((string) $days_archived); ?></td>
                <td style="white-space:nowrap;">
                  <?php echo esc_html($archived_user ? $archived_user->display_name : ($archived_by > 0 ? 'User #' . $archived_by : 'Unknown')); ?>
                </td>
                <td>
                  <?php if (!empty($connection_sources)) : ?>
                    <ul style="margin:0;">
                      <?php foreach ($connection_sources as $source) : ?>
                        <li>
                          <?php echo esc_html((string) $source['label']); ?>:
                          <?php echo esc_html(number_format_i18n((int) $source['count'])); ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else : ?>
                    <span class="description">None detected</span>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                  <?php if ($can_restore) : ?>
                    <form method="post" style="display:inline;">
                      <?php wp_nonce_field('cfm_core_terms_archive_restore', 'cfm_nonce'); ?>
                      <input type="hidden" name="cfm_action" value="core_terms_archive_restore">
                      <input type="hidden" name="archive_key" value="<?php echo esc_attr((string) $archive->archive_key); ?>">
                      <button type="submit" class="button-link">Restore <?php echo esc_html($action_noun); ?></button>
                    </form>
                  <?php endif; ?>

                  <?php if ($can_delete) : ?>
                    <?php if ($can_restore) : ?>
                      <span aria-hidden="true"> | </span>
                    <?php endif; ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm(this.getAttribute('data-confirm-message'));" data-confirm-message="<?php echo esc_attr($delete_confirmation); ?>">
                      <?php wp_nonce_field('cfm_core_terms_archive_delete', 'cfm_nonce'); ?>
                      <input type="hidden" name="cfm_action" value="core_terms_archive_delete">
                      <input type="hidden" name="archive_key" value="<?php echo esc_attr((string) $archive->archive_key); ?>">
                      <button type="submit" class="button-link button-link-delete">Delete <?php echo esc_html($action_noun); ?></button>
                    </form>
                  <?php endif; ?>

                  <?php if (!$can_restore && !$can_delete) : ?>
                    <span aria-hidden="true">&mdash;</span>
                    <span class="screen-reader-text">No action available</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
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
      wp_die('Core Terms definition not found.');
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
        <a href="<?php echo esc_url(self::data_url((int) $framework->id)); ?>">
          ← Back to Core Terms Data
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


  private static function compare_taxonomy_trees(array $snapshot_tree, array $current_tree): array
  {
    $snapshot_nodes = self::flatten_taxonomy_tree_for_comparison($snapshot_tree);
    $current_nodes = self::flatten_taxonomy_tree_for_comparison($current_tree);

    $snapshot_uuids = array_keys($snapshot_nodes);
    $current_uuids = array_keys($current_nodes);

    $added_uuids = array_values(array_diff($current_uuids, $snapshot_uuids));
    $removed_uuids = array_values(array_diff($snapshot_uuids, $current_uuids));
    $shared_uuids = array_values(array_intersect($snapshot_uuids, $current_uuids));

    $label_changes = [];
    $slug_changes = [];
    $short_label_changes = [];
    $description_changes = [];
    $parent_changes = [];
    $archive_changes = [];
    $kind_changes = [];
    $includes_changes = [];

    foreach ($shared_uuids as $uuid) {
      $snapshot = $snapshot_nodes[$uuid];
      $current = $current_nodes[$uuid];

      if ((string) ($snapshot['kind'] ?? '') !== (string) ($current['kind'] ?? '')) {
        $kind_changes[] = [
          'uuid' => $uuid,
          'label' => (string) $current['label'],
          'before' => (string) ($snapshot['kind'] ?? ''),
          'after' => (string) ($current['kind'] ?? ''),
        ];
      }

      if ((string) ($snapshot['includes_key'] ?? '') !== (string) ($current['includes_key'] ?? '')) {
        $includes_changes[] = [
          'uuid' => $uuid,
          'label' => (string) $current['label'],
          'before' => (string) ($snapshot['includes_label'] ?? ''),
          'after' => (string) ($current['includes_label'] ?? ''),
        ];
      }

      if ((string) $snapshot['label'] !== (string) $current['label']) {
        $label_changes[] = [
          'uuid' => $uuid,
          'before' => (string) $snapshot['label'],
          'after' => (string) $current['label'],
        ];
      }

      if ((string) $snapshot['slug'] !== (string) $current['slug']) {
        $slug_changes[] = [
          'uuid' => $uuid,
          'before' => (string) $snapshot['slug'],
          'after' => (string) $current['slug'],
        ];
      }

      if ((string) $snapshot['short_label'] !== (string) $current['short_label']) {
        $short_label_changes[] = [
          'uuid' => $uuid,
          'before' => (string) $snapshot['short_label'],
          'after' => (string) $current['short_label'],
        ];
      }

      if ((string) $snapshot['description'] !== (string) $current['description']) {
        $description_changes[] = [
          'uuid' => $uuid,
          'before' => (string) $snapshot['description'],
          'after' => (string) $current['description'],
        ];
      }

      if ((string) $snapshot['parent_uuid'] !== (string) $current['parent_uuid']) {
        $parent_changes[] = [
          'uuid' => $uuid,
          'label' => (string) $current['label'],
          'before' => (string) $snapshot['parent_label'],
          'after' => (string) $current['parent_label'],
        ];
      }

      if ((bool) $snapshot['archived'] !== (bool) $current['archived']) {
        $archive_changes[] = [
          'uuid' => $uuid,
          'label' => (string) $current['label'],
          'before' => !empty($snapshot['archived']) ? 'Archived' : 'Active',
          'after' => !empty($current['archived']) ? 'Archived' : 'Active',
        ];
      }
    }

    return [
      'snapshot_total' => count($snapshot_nodes),
      'current_total' => count($current_nodes),
      'added' => count($added_uuids),
      'removed' => count($removed_uuids),
      'kind_changed' => count($kind_changes),
      'includes_changed' => count($includes_changes),
      'label_changed' => count($label_changes),
      'slug_changed' => count($slug_changes),
      'short_label_changed' => count($short_label_changes),
      'description_changed' => count($description_changes),
      'parent_changed' => count($parent_changes),
      'archive_changed' => count($archive_changes),
      'added_samples' => self::sample_comparison_nodes($added_uuids, $current_nodes),
      'removed_samples' => self::sample_comparison_nodes($removed_uuids, $snapshot_nodes),
      'kind_change_samples' => array_slice($kind_changes, 0, 8),
      'includes_change_samples' => array_slice($includes_changes, 0, 8),
      'label_change_samples' => array_slice($label_changes, 0, 8),
      'slug_change_samples' => array_slice($slug_changes, 0, 8),
      'short_label_change_samples' => array_slice($short_label_changes, 0, 8),
      'description_change_samples' => array_slice($description_changes, 0, 8),
      'parent_change_samples' => array_slice($parent_changes, 0, 8),
      'archive_change_samples' => array_slice($archive_changes, 0, 8),
    ];
  }

  private static function flatten_taxonomy_tree_for_comparison(array $node, string $parent_uuid = '', string $parent_label = ''): array
  {
    $nodes = [];
    $uuid = trim((string) ($node['uuid'] ?? ''));
    $kind = self::node_kind($node);
    $type = $kind;
    $status = (string) ($node['status'] ?? '');

    if ($uuid !== '') {
      $nodes[$uuid] = [
        'uuid' => $uuid,
        'type' => $type,
        'kind' => $kind,
        'includes_key' => self::comparison_includes_key($node),
        'includes_label' => self::comparison_includes_label($node),
        'label' => (string) ($node['label'] ?? ''),
        'slug' => (string) ($node['slug'] ?? ''),
        'short_label' => self::display_short_label_for_node($node),
        'description' => self::display_description_for_node($node),
        'parent_uuid' => $parent_uuid,
        'parent_label' => $parent_label,
        'archived' => (!empty($node['archived']) || !empty($node['archived_at']) || $status === 'archived'),
      ];
    }

    $children = $node['children'] ?? [];

    if (!is_array($children)) {
      return $nodes;
    }

    foreach ($children as $child) {
      if (!is_array($child)) {
        continue;
      }

      $child_nodes = self::flatten_taxonomy_tree_for_comparison(
        $child,
        $uuid,
        (string) ($node['label'] ?? '')
      );

      foreach ($child_nodes as $child_uuid => $child_node) {
        $nodes[$child_uuid] = $child_node;
      }
    }

    return $nodes;
  }

  private static function comparison_includes_key(array $node): string
  {
    $includes = self::normalized_includes_for_comparison($node);
    sort($includes, SORT_STRING);

    return implode('|', $includes);
  }

  private static function comparison_includes_label(array $node): string
  {
    $includes = self::normalized_includes_for_comparison($node);
    sort($includes, SORT_STRING);

    return implode(', ', $includes);
  }

  private static function normalized_includes_for_comparison(array $node): array
  {
    $includes = $node['includes'] ?? [];

    if (!is_array($includes)) {
      return [];
    }

    $normalized = [];

    foreach ($includes as $uuid) {
      if (!is_scalar($uuid)) {
        continue;
      }

      $uuid = trim((string) $uuid);

      if ($uuid === '') {
        continue;
      }

      $normalized[] = $uuid;
    }

    return array_values(array_unique($normalized));
  }

  private static function sample_comparison_nodes(array $uuids, array $nodes): array
  {
    $samples = [];

    foreach (array_slice($uuids, 0, 8) as $uuid) {
      if (!isset($nodes[$uuid])) {
        continue;
      }

      $samples[] = [
        'uuid' => (string) $uuid,
        'label' => (string) ($nodes[$uuid]['label'] ?? ''),
        'slug' => (string) ($nodes[$uuid]['slug'] ?? ''),
      ];
    }

    return $samples;
  }

  private static function render_taxonomy_comparison_summary(array $comparison): void
  {
  ?>
    <h2>Compare Snapshot to Current Taxonomy</h2>

    <p>This read-only comparison shows how the current Core Terms differs from this snapshot.</p>

    <?php
    $total_differences = (int) ($comparison['added'] ?? 0)
      + (int) ($comparison['removed'] ?? 0)
      + (int) ($comparison['kind_changed'] ?? 0)
      + (int) ($comparison['includes_changed'] ?? 0)
      + (int) ($comparison['label_changed'] ?? 0)
      + (int) ($comparison['slug_changed'] ?? 0)
      + (int) ($comparison['short_label_changed'] ?? 0)
      + (int) ($comparison['description_changed'] ?? 0)
      + (int) ($comparison['parent_changed'] ?? 0)
      + (int) ($comparison['archive_changed'] ?? 0);
    ?>

    <?php if ($total_differences === 0) : ?>
      <div class="notice notice-success inline" style="max-width: 900px;">
        <p>No differences between this snapshot and the current Core Terms.</p>
      </div>
    <?php endif; ?>

    <table class="widefat striped" style="max-width: 900px;">
      <tbody>
        <tr>
          <th style="width: 260px;">Snapshot terms</th>
          <td><?php echo esc_html((string) ($comparison['snapshot_total'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Current terms</th>
          <td><?php echo esc_html((string) ($comparison['current_total'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Terms added since snapshot</th>
          <td><?php echo esc_html((string) ($comparison['added'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Terms removed since snapshot</th>
          <td><?php echo esc_html((string) ($comparison['removed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Kind changes</th>
          <td><?php echo esc_html((string) ($comparison['kind_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Meta includes changes</th>
          <td><?php echo esc_html((string) ($comparison['includes_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Labels changed</th>
          <td><?php echo esc_html((string) ($comparison['label_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Slugs changed</th>
          <td><?php echo esc_html((string) ($comparison['slug_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Short labels changed</th>
          <td><?php echo esc_html((string) ($comparison['short_label_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Communities changed</th>
          <td><?php echo esc_html((string) ($comparison['description_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Parent changes</th>
          <td><?php echo esc_html((string) ($comparison['parent_changed'] ?? 0)); ?></td>
        </tr>
        <tr>
          <th>Archive status changes</th>
          <td><?php echo esc_html((string) ($comparison['archive_changed'] ?? 0)); ?></td>
        </tr>
      </tbody>
    </table>

    <?php self::render_comparison_samples('Added term samples', $comparison['added_samples'] ?? []); ?>
    <?php self::render_comparison_samples('Removed term samples', $comparison['removed_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Kind change samples', $comparison['kind_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Meta includes change samples', $comparison['includes_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Label change samples', $comparison['label_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Slug change samples', $comparison['slug_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Short label change samples', $comparison['short_label_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Community change samples', $comparison['description_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Parent change samples', $comparison['parent_change_samples'] ?? []); ?>
    <?php self::render_field_change_samples('Archive status change samples', $comparison['archive_change_samples'] ?? []); ?>
  <?php
  }

  private static function render_comparison_samples(string $heading, array $samples): void
  {
    if (empty($samples)) {
      return;
    }

  ?>
    <h3><?php echo esc_html($heading); ?></h3>
    <ul>
      <?php foreach ($samples as $sample) : ?>
        <li>
          <?php echo esc_html((string) ($sample['label'] ?? '')); ?>
          <code><?php echo esc_html((string) ($sample['slug'] ?? '')); ?></code>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php
  }

  private static function render_field_change_samples(string $heading, array $samples): void
  {
    if (empty($samples)) {
      return;
    }

  ?>
    <h3><?php echo esc_html($heading); ?></h3>
    <ul>
      <?php foreach ($samples as $sample) : ?>
        <li>
          <?php if (!empty($sample['label'])) : ?>
            <?php echo esc_html((string) $sample['label']); ?>:
          <?php endif; ?>
          <code><?php echo esc_html((string) ($sample['before'] ?? '')); ?></code>
          →
          <code><?php echo esc_html((string) ($sample['after'] ?? '')); ?></code>
        </li>
      <?php endforeach; ?>
    </ul>
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
      wp_die('Core Terms definition not found.');
    }

    $version = CFM_Framework_Repository::get_version((int) $framework->id, $version_id);

    if (!$version) {
      wp_die('Version not found.');
    }

    $tree = json_decode((string) $version->tree_json, true);
    $is_active_version = ((int) $framework->active_version_id === (int) $version->id);
    $pretty_json = wp_json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $comparison = [];

    if (is_array($tree)) {
      $current_tree = self::get_framework_tree($framework);
      $comparison = self::compare_taxonomy_trees($tree, $current_tree);
    }

  ?>
    <div class="wrap">
      <h1>
        Version Snapshot: <?php echo esc_html($framework->name); ?>
        v<?php echo esc_html((string) $version->version_number); ?>
      </h1>

      <p>
        <a href="<?php echo esc_url(self::versions_url((int) $framework->id)); ?>">← Back to Version History</a>
        ·
        <a href="<?php echo esc_url(self::data_url((int) $framework->id)); ?>">Back to Core Terms Data</a>
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

      <?php if (!$is_active_version && is_array($tree) && (string) $version->status === 'pre_import_snapshot') : ?>
        <p style="margin-top: 16px;">
          <a class="button button-primary" href="<?php echo esc_url(self::restore_version_url((int) $framework->id, (int) $version->id)); ?>">
            Restore This Recovery Snapshot
          </a>
        </p>
      <?php endif; ?>

      <?php if (is_array($tree) && !empty($comparison)) : ?>
        <hr>
        <?php self::render_taxonomy_comparison_summary($comparison); ?>
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
      wp_die('Core Terms definition not found.');
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

      <?php if ((string) $version->status !== 'pre_import_snapshot') : ?>
        <div class="notice notice-error">
          <p>Only automatic recovery snapshots may be restored from this workflow.</p>
        </div>
      <?php elseif ($is_active_version) : ?>
        <div class="notice notice-info">
          <p>This version is already active. No restore is needed.</p>
        </div>
      <?php else : ?>
        <div class="notice notice-warning">
          <p>
            This will restore the Core Terms from recovery snapshot v<?php echo esc_html((string) $version->version_number); ?>,
            automatically save the current active tree as a pre-restore snapshot, and rebuild runtime tables.
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'restore_not_confirmed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Please confirm that you understand this restore will replace the current Core Terms.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'restore_snapshot_failed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Restore aborted because the current taxonomy could not be saved as a pre-restore snapshot.</p>
        </div>
      <?php endif; ?>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Core Terms</th>
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

      <?php if (!$is_active_version && (string) $version->status === 'pre_import_snapshot') : ?>
        <form method="post" style="margin-top: 20px;">
          <?php wp_nonce_field('cfm_restore_version', 'cfm_nonce'); ?>

          <input type="hidden" name="cfm_action" value="restore_version">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
          <input type="hidden" name="version_id" value="<?php echo esc_attr($version->id); ?>">

          <p>
            <label>
              <input type="checkbox" name="confirm_restore_snapshot" value="1" required>
              I understand this will replace the current Core Terms with this recovery snapshot.
            </label>
          </p>

          <?php submit_button('Restore This Recovery Snapshot', 'primary'); ?>
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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    $term = $term_info['node'];
    $current_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : null;

    if (self::node_kind($term) !== 'term') {
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
                  ); ?>">← Back to Core Terms</a>
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
            <th style="width: 180px;">Core Terms</th>
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

  private static function render_term_metadata_autofill_script(): void
  {
  ?>
    <script>
      (function() {
        if (window.cfmTermMetadataAutofillLoaded) {
          return;
        }
        window.cfmTermMetadataAutofillLoaded = true;

        var normalizeSlug = function(value) {
          value = String(value || '');
          value = value.replace(/&/g, ' and ');
          value = value.replace(/[\'’`]/g, '');
          value = value.toLowerCase();
          value = value.replace(/[^a-z0-9]+/g, '-');
          value = value.replace(/-+/g, '-');
          value = value.replace(/^-+|-+$/g, '');
          return value;
        };

        var defaultCommunity = function(value) {
          value = String(value || '').trim();

          if (!value) {
            return '';
          }

          value = value.toLowerCase().replace(/\b[\w’'-]+/g, function(word) {
            return word.charAt(0).toUpperCase() + word.slice(1);
          });

          return value + ' Teachers';
        };

        var normalizeShortLabel = function(value) {
          return String(value || '').replace(/\s*\/\s*/g, '/').trim();
        };

        var fillTarget = function(labelInput, target, detached) {
          if (detached[target.name]) {
            return;
          }

          var type = target.getAttribute('data-cfm-autofill-type');
          target.value = type === 'slug'
            ? normalizeSlug(labelInput.value)
            : (target.name === 'term_description' || target.name === 'meta_group_description')
              ? defaultCommunity(labelInput.value)
              : normalizeShortLabel(labelInput.value);
        };

        var wireGroup = function(groupName) {
          var labelInput = document.querySelector('[data-cfm-autofill-label="' + groupName + '"]');
          var targets = Array.prototype.slice.call(document.querySelectorAll('[data-cfm-autofill-target="' + groupName + '"]'));
          var detached = {};

          if (!labelInput || targets.length === 0) {
            return;
          }

          targets.forEach(function(target) {
            detached[target.name] = target.value.trim() !== '';

            target.addEventListener('input', function() {
              detached[target.name] = target.value.trim() !== '';
              if (!detached[target.name]) {
                fillTarget(labelInput, target, detached);
              }
            });
          });

          labelInput.addEventListener('input', function() {
            targets.forEach(function(target) {
              fillTarget(labelInput, target, detached);
            });
          });
        };

        wireGroup('add-axis');
        wireGroup('add-term');
        wireGroup('add-meta-group');
        wireGroup('edit-term');
        wireGroup('edit-meta-group');

        if (window.location.hash === '#cfm-add-term') {
          var addTermLabel = document.querySelector('[data-cfm-autofill-label="add-term"]');
          if (addTermLabel) {
            addTermLabel.focus();
          }
        }
      }());
    </script>
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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = self::root_terms($tree);
    $meta_groups = self::root_meta_groups($tree);
    $available_terms = self::collect_assignable_term_nodes($tree);
    $terms_by_uuid = [];

    foreach ($available_terms as $available_term) {
      $available_uuid = (string) ($available_term['uuid'] ?? '');

      if ($available_uuid !== '') {
        $terms_by_uuid[$available_uuid] = $available_term;
      }
    }
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    $term = $term_info['node'];
    $current_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : null;

    if (self::node_kind($term) !== 'term') {
      wp_die('Only terms can be edited here.');
    }

    $current_parent_uuid = $current_parent['uuid'] ?? '';

  ?>
    <div class="wrap">
      <h1>Edit Term: <?php echo esc_html($term['label'] ?? ''); ?></h1>

      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . (int) $framework->id)); ?>">← Back to Core Terms</a>
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
              <input name="term_label" id="term_label" type="text" class="regular-text" value="<?php echo esc_attr($term['label'] ?? ''); ?>" data-cfm-autofill-label="edit-term" required>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="term_slug">Term Slug</label></th>
            <td>
              <input name="term_slug" id="term_slug" type="text" class="regular-text" value="<?php echo esc_attr($term['slug'] ?? ''); ?>" data-cfm-autofill-target="edit-term" data-cfm-autofill-type="slug">
              <p class="description">Keep this stable unless you intentionally need to change API-facing references.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="term_short_label">Short Label</label></th>
            <td>
              <input name="term_short_label" id="term_short_label" type="text" class="regular-text" value="<?php echo esc_attr(self::display_short_label_for_node($term)); ?>" data-cfm-autofill-target="edit-term" data-cfm-autofill-type="copy">
              <p class="description">Compact display text for narrow UI placements. Leave blank to use the term label.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="term_description">Community</label></th>
            <td>
              <input name="term_description" id="term_description" type="text" class="regular-text" value="<?php echo esc_attr(self::display_description_for_node($term)); ?>" data-cfm-autofill-target="edit-term" data-cfm-autofill-type="copy">
              <p class="description">Community-facing context. Leave blank to use the term label.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="parent_uuid">Parent Term</label></th>
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

      <?php self::render_term_metadata_autofill_script(); ?>
    </div>
  <?php
  }

  public static function render_edit_meta_group_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id']) ? absint($_GET['framework_id']) : 0;
    $meta_group_uuid = isset($_GET['meta_group_uuid']) ? sanitize_text_field(wp_unslash($_GET['meta_group_uuid'])) : '';

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $meta_group_info = self::find_node_with_parent($tree, $meta_group_uuid);

    if (!$meta_group_info || empty($meta_group_info['node']) || !is_array($meta_group_info['node'])) {
      wp_die('Meta-Group not found.');
    }

    $meta_group = $meta_group_info['node'];

    if (self::node_kind($meta_group) !== 'meta') {
      wp_die('Only Meta-Groups can be edited here.');
    }

    $available_terms = self::collect_assignable_term_nodes($tree);
    $selected_uuids = isset($meta_group['includes']) && is_array($meta_group['includes'])
      ? array_values(array_unique(array_filter(array_map('strval', $meta_group['includes']))))
      : [];

  ?>
    <div class="wrap">
      <?php self::render_meta_group_admin_styles(); ?>

      <h1>Edit Meta-Group: <?php echo esc_html($meta_group['label'] ?? ''); ?></h1>

      <p>
        <a href="<?php echo esc_url(self::meta_groups_url((int) $framework->id) . '#cfm-meta-groups'); ?>">← Back to Meta-Groups</a>
      </p>

      <div class="notice notice-info inline">
        <p><strong>Meta-Groups are audience-only collections.</strong> Editing this Meta-Group changes which existing terms it references. It does not move terms, create terms, or assign users.</p>
      </div>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_meta_group_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Meta-Group label, slug, and at least two included terms are required.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'invalid_example_pack') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Example term pack could not be installed. Choose a valid pack and try again.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'invalid_meta_group_includes') : ?>
        <div class="notice notice-error is-dismissible">
          <p>One or more selected terms are no longer available. Review the included terms and save again.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'duplicate_meta_group_slug') : ?>
        <div class="notice notice-error is-dismissible">
          <p>That slug is already used by another top-level term or Meta-Group.</p>
        </div>
      <?php endif; ?>

      <form method="post">
        <?php wp_nonce_field('cfm_update_meta_group', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="update_meta_group">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
        <input type="hidden" name="meta_group_uuid" value="<?php echo esc_attr($meta_group['uuid'] ?? ''); ?>">

        <?php self::render_meta_group_reference_guide(); ?>

        <div class="cfm-meta-group-field-row">
          <div class="cfm-meta-group-field">
            <label for="meta_group_label">Meta-Group Label</label>
            <input name="meta_group_label" id="meta_group_label" type="text" value="<?php echo esc_attr($meta_group['label'] ?? ''); ?>" data-cfm-autofill-label="edit-meta-group" required>
          </div>
          <div class="cfm-meta-group-field">
            <label for="meta_group_slug">Slug</label>
            <input name="meta_group_slug" id="meta_group_slug" type="text" value="<?php echo esc_attr($meta_group['slug'] ?? ''); ?>" data-cfm-autofill-target="edit-meta-group" data-cfm-autofill-type="slug">
            <p class="description">Keep stable unless API-facing references should change.</p>
          </div>
          <div class="cfm-meta-group-field">
            <label for="meta_group_short_label">Short Label</label>
            <input name="meta_group_short_label" id="meta_group_short_label" type="text" value="<?php echo esc_attr(self::display_short_label_for_node($meta_group)); ?>" data-cfm-autofill-target="edit-meta-group" data-cfm-autofill-type="copy">
          </div>
          <div class="cfm-meta-group-field">
            <label for="meta_group_description">Community</label>
            <input name="meta_group_description" id="meta_group_description" type="text" value="<?php echo esc_attr(self::display_description_for_node($meta_group)); ?>" data-cfm-autofill-target="edit-meta-group" data-cfm-autofill-type="copy">
          </div>
        </div>

        <?php if (count($available_terms) < 2) : ?>
          <p>Create at least two terms before editing Meta-Group includes.</p>
        <?php else : ?>
          <div class="cfm-meta-group-term-selector" data-cfm-meta-term-selector="1">
            <h4>Included Terms</h4>
            <p class="description" style="margin-top:0;">Parent checkboxes select or clear all descendant terms. Child changes update parent checkbox state.</p>
            <div class="cfm-meta-group-term-toolbar">
              <span>
                <a href="#" data-cfm-meta-expand="1">Expand all</a>
                <span aria-hidden="true"> | </span>
                <a href="#" data-cfm-meta-expand="0">Collapse all</a>
              </span>
              <span id="cfm-meta-selected-count" class="description">0 terms selected</span>
            </div>
            <fieldset class="cfm-meta-group-term-tree">
              <legend class="screen-reader-text">Included Terms</legend>
              <?php self::render_meta_group_term_checklist(self::root_terms($tree), 0, $selected_uuids); ?>
            </fieldset>
            <p class="description">Meta-Groups remain non-assignable. This selector only changes the referenced terms.</p>
          </div>
        <?php endif; ?>

        <?php submit_button('Save Meta-Group'); ?>
      </form>

      <?php self::render_meta_group_term_checklist_script(); ?>
      <?php self::render_term_metadata_autofill_script(); ?>
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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = self::root_terms($tree);
    $meta_groups = self::root_meta_groups($tree);
    $available_terms = self::collect_assignable_term_nodes($tree);
    $terms_by_uuid = [];

    foreach ($available_terms as $available_term) {
      $available_uuid = (string) ($available_term['uuid'] ?? '');

      if ($available_uuid !== '') {
        $terms_by_uuid[$available_uuid] = $available_term;
      }
    }
    $term_info = self::find_node_with_parent($tree, $term_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    $term = $term_info['node'];
    $current_parent = (!empty($term_info['parent']) && is_array($term_info['parent'])) ? $term_info['parent'] : null;

    if (self::node_kind($term) !== 'term') {
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
                  ); ?>">← Back to Core Terms</a>
      </p>

      <table class="widefat striped" style="max-width: 900px;">
        <tbody>
          <tr>
            <th style="width: 180px;">Core Terms</th>
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
                A profile term can move to the top level or under another profile term. It cannot move under itself or its descendants.
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
      wp_die('Core Terms definition not found.');
    }

    $active_version = CFM_Framework_Repository::get_active_version((int) $framework->id);
    $terms = $active_version
      ? CFM_Framework_Repository::get_compiled_terms((int) $framework->id, (int) $active_version->id)
      : [];

    $meta_group_key = isset($_GET['meta_group'])
      ? sanitize_text_field(wp_unslash($_GET['meta_group']))
      : '';

    $meta_groups = [];

    foreach ($terms as $term) {
      if (isset($term->kind) && (string) $term->kind === 'meta') {
        $meta_groups[] = $term;
      }
    }

    $selectable_terms = [];

    foreach ($terms as $term) {
      if (!isset($term->kind) || (string) $term->kind !== 'meta') {
        $selectable_terms[] = $term;
      }
    }

    $selected_meta_group = null;
    $selected_meta_group_included_uuids = [];
    $selected_meta_group_included_terms = [];
    $selected_meta_group_any_user_ids = [];
    $selected_meta_group_all_user_ids = [];

    if ($meta_group_key !== '') {
      $selected_meta_group = CFM::get_meta_group((string) $framework->slug, $meta_group_key);

      if ($selected_meta_group) {
        $selected_meta_group_included_uuids = CFM::get_meta_group_included_term_uuids((string) $framework->slug, $meta_group_key);
        $selected_meta_group_included_terms = CFM_Framework_Repository::get_terms_by_uuids(
          (int) $framework->id,
          $selected_meta_group_included_uuids,
          $active_version ? (int) $active_version->id : null
        );
        $selected_meta_group_any_user_ids = CFM::get_users_matching_meta_group_any((string) $framework->slug, $meta_group_key);
        $selected_meta_group_all_user_ids = CFM::get_users_matching_meta_group_all((string) $framework->slug, $meta_group_key);
      }
    }

    $audience_contract_term_inputs = isset($_GET['audience_terms']) && is_array($_GET['audience_terms'])
      ? array_values(array_unique(array_filter(array_map('sanitize_text_field', wp_unslash($_GET['audience_terms'])))))
      : [];
    $audience_contract_meta_group_inputs = isset($_GET['audience_meta_groups']) && is_array($_GET['audience_meta_groups'])
      ? array_values(array_unique(array_filter(array_map('sanitize_text_field', wp_unslash($_GET['audience_meta_groups'])))))
      : [];
    $audience_contract_operator = isset($_GET['audience_operator'])
      ? strtoupper(sanitize_text_field(wp_unslash($_GET['audience_operator'])))
      : 'OR';

    if (!in_array($audience_contract_operator, ['OR', 'AND'], true)) {
      $audience_contract_operator = 'OR';
    }

    $run_audience_contract = isset($_GET['run_audience_contract']);
    $audience_contract_user_ids = [];
    $audience_contract_invalid_terms = [];
    $audience_contract_invalid_meta_groups = [];
    $audience_contract_expanded_term_uuids = [];

    if ($run_audience_contract) {
      $term_lookup = [];
      foreach ($selectable_terms as $term) {
        if (isset($term->term_uuid)) {
          $term_lookup[(string) $term->term_uuid] = true;
        }
      }

      $meta_group_lookup = [];
      foreach ($meta_groups as $meta_group) {
        if (isset($meta_group->term_uuid)) {
          $meta_group_lookup[(string) $meta_group->term_uuid] = $meta_group;
        }
      }

      foreach ($audience_contract_term_inputs as $audience_term_input) {
        if (!isset($term_lookup[(string) $audience_term_input])) {
          $audience_contract_invalid_terms[] = (string) $audience_term_input;
        } else {
          $audience_contract_expanded_term_uuids[] = (string) $audience_term_input;
        }
      }

      foreach ($audience_contract_meta_group_inputs as $audience_meta_group_input) {
        if (!isset($meta_group_lookup[(string) $audience_meta_group_input])) {
          $audience_contract_invalid_meta_groups[] = (string) $audience_meta_group_input;
          continue;
        }

        $audience_contract_expanded_term_uuids = array_merge(
          $audience_contract_expanded_term_uuids,
          CFM::get_meta_group_included_term_uuids((string) $framework->slug, (string) $audience_meta_group_input)
        );
      }

      $audience_contract_expanded_term_uuids = array_values(array_unique(array_filter(array_map('strval', $audience_contract_expanded_term_uuids))));

      $audience_contract_user_ids = CFM::resolve_users([
        'framework' => (string) $framework->slug,
        'terms' => $audience_contract_term_inputs,
        'meta_groups' => $audience_contract_meta_group_inputs,
        'operator' => $audience_contract_operator,
        'context' => 'profile',
        'include_descendants' => true,
      ]);
    }

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
          ← Back to Core Terms
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
              <th style="width: 180px;">Core Terms Slug</th>
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

      <h2>Audience API Smoke Test</h2>
      <p class="description">Developer diagnostic only. This confirms read-only Audience API helper behavior against the active compiled taxonomy and stored user assignments.</p>

      <?php if (empty($meta_groups)) : ?>
        <p><em>No compiled Meta-Groups found.</em></p>
      <?php else : ?>
        <form method="get" style="margin-bottom: 14px;">
          <input type="hidden" name="page" value="cfm-frameworks">
          <input type="hidden" name="action" value="compiled_debug">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
          <?php if ($term_uuid !== '') : ?>
            <input type="hidden" name="term_uuid" value="<?php echo esc_attr($term_uuid); ?>">
          <?php endif; ?>

          <select name="meta_group" style="min-width: 360px;">
            <option value="">Select a Meta-Group</option>
            <?php foreach ($meta_groups as $meta_group) : ?>
              <option value="<?php echo esc_attr($meta_group->slug); ?>" <?php selected($meta_group_key, $meta_group->slug); ?>>
                <?php echo esc_html($meta_group->label . ' (' . $meta_group->slug . ')'); ?>
              </option>
            <?php endforeach; ?>
          </select>

          <?php submit_button('Run Audience API Smoke Test', 'secondary', 'submit', false); ?>
        </form>

        <?php if ($meta_group_key !== '' && !$selected_meta_group) : ?>
          <div class="notice notice-error">
            <p>Selected Meta-Group was not found in the active compiled taxonomy.</p>
          </div>
        <?php endif; ?>

        <?php if ($selected_meta_group) : ?>
          <?php self::render_audience_api_smoke_test_panel(
            (string) $framework->slug,
            $selected_meta_group,
            $selected_meta_group_included_uuids,
            $selected_meta_group_included_terms,
            $selected_meta_group_any_user_ids,
            $selected_meta_group_all_user_ids
          ); ?>
        <?php endif; ?>
      <?php endif; ?>

      <hr>

      <h2>Audience Contract Smoke Test</h2>
      <p class="description">Developer diagnostic for the consumer-neutral Audience v1 contract. This tests <code>CFM::resolve_users($audience)</code>, which future plugins can use without duplicating Core Terms logic.</p>

      <form method="get" style="max-width: 1100px; margin-bottom: 14px;">
        <input type="hidden" name="page" value="cfm-frameworks">
        <input type="hidden" name="action" value="compiled_debug">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">
        <?php if ($term_uuid !== '') : ?>
          <input type="hidden" name="term_uuid" value="<?php echo esc_attr($term_uuid); ?>">
        <?php endif; ?>
        <?php if ($meta_group_key !== '') : ?>
          <input type="hidden" name="meta_group" value="<?php echo esc_attr($meta_group_key); ?>">
        <?php endif; ?>

        <table class="form-table" role="presentation">
          <tbody>
            <tr>
              <th scope="row"><label for="audience_operator">Match Mode</label></th>
              <td>
                <select id="audience_operator" name="audience_operator">
                  <option value="OR" <?php selected($audience_contract_operator, 'OR'); ?>>ANY / OR</option>
                  <option value="AND" <?php selected($audience_contract_operator, 'AND'); ?>>ALL / AND</option>
                </select>
                <p class="description">ANY returns users matching at least one resolved Term. ALL returns users matching every resolved Term.</p>
              </td>
            </tr>
            <tr>
              <th scope="row">Terms</th>
              <td>
                <?php if (empty($selectable_terms)) : ?>
                  <p><em>No compiled Terms available.</em></p>
                <?php else : ?>
                  <select name="audience_terms[]" multiple size="10" style="min-width: 520px; max-width: 100%;">
                    <?php foreach ($selectable_terms as $term) : ?>
                      <option value="<?php echo esc_attr((string) $term->term_uuid); ?>" <?php selected(in_array((string) $term->term_uuid, $audience_contract_term_inputs, true)); ?>>
                        <?php echo esc_html(str_repeat('— ', max(0, (int) $term->depth)) . $term->label . ' (' . $term->slug . ')'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description">Hold Ctrl/Cmd to select multiple Terms. Consumer plugins will usually store UUIDs, not labels.</p>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <th scope="row">Meta-Groups</th>
              <td>
                <?php if (empty($meta_groups)) : ?>
                  <p><em>No compiled Meta-Groups available.</em></p>
                <?php else : ?>
                  <select name="audience_meta_groups[]" multiple size="6" style="min-width: 520px; max-width: 100%;">
                    <?php foreach ($meta_groups as $meta_group) : ?>
                      <option value="<?php echo esc_attr((string) $meta_group->term_uuid); ?>" <?php selected(in_array((string) $meta_group->term_uuid, $audience_contract_meta_group_inputs, true)); ?>>
                        <?php echo esc_html($meta_group->label . ' (' . $meta_group->slug . ')'); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <p class="description">Meta-Groups expand into included Terms before user matching.</p>
                <?php endif; ?>
              </td>
            </tr>
          </tbody>
        </table>

        <?php submit_button('Run Audience Contract Smoke Test', 'secondary', 'run_audience_contract', false); ?>
      </form>

      <?php if ($run_audience_contract) : ?>
        <?php self::render_audience_contract_smoke_test_panel(
          (string) $framework->slug,
          $audience_contract_term_inputs,
          $audience_contract_meta_group_inputs,
          $audience_contract_operator,
          $audience_contract_expanded_term_uuids,
          $audience_contract_user_ids,
          $audience_contract_invalid_terms,
          $audience_contract_invalid_meta_groups
        ); ?>
      <?php endif; ?>

      <hr>

      <h2>All Compiled Terms</h2>
      <?php self::render_compiled_terms_table($terms); ?>
    </div>
  <?php
  }

  private static function render_audience_api_smoke_test_panel(string $framework_slug, object $meta_group, array $included_uuids, array $included_terms, array $any_user_ids, array $all_user_ids): void
  {
    $included_terms_by_uuid = [];

    foreach ($included_terms as $included_term) {
      if (isset($included_term->term_uuid)) {
        $included_terms_by_uuid[(string) $included_term->term_uuid] = $included_term;
      }
    }

    $missing_uuids = [];

    foreach ($included_uuids as $included_uuid) {
      $included_uuid = (string) $included_uuid;

      if ($included_uuid !== '' && !isset($included_terms_by_uuid[$included_uuid])) {
        $missing_uuids[] = $included_uuid;
      }
    }

  ?>
    <table class="widefat striped" style="max-width: 760px; margin-bottom: 14px;">
      <tbody>
        <tr>
          <th style="width: 220px;">Meta-Group</th>
          <td><?php echo esc_html((string) ($meta_group->label ?? '')); ?> <code><?php echo esc_html((string) ($meta_group->slug ?? '')); ?></code></td>
        </tr>
        <tr>
          <th>Included Terms</th>
          <td><?php echo esc_html((string) count($included_uuids)); ?></td>
        </tr>
        <tr>
          <th>ANY Matched Users</th>
          <td><?php echo esc_html((string) count($any_user_ids)); ?></td>
        </tr>
        <tr>
          <th>ALL Matched Users</th>
          <td><?php echo esc_html((string) count($all_user_ids)); ?></td>
        </tr>
      </tbody>
    </table>

    <?php if (!empty($missing_uuids)) : ?>
      <div class="notice notice-warning inline" style="max-width: 760px;">
        <p>This Meta-Group references <?php echo esc_html((string) count($missing_uuids)); ?> term UUID(s) that were not found in compiled terms.</p>
      </div>
    <?php endif; ?>

    <h3>Included Terms</h3>
    <?php if (empty($included_terms_by_uuid)) : ?>
      <p><em>No included compiled terms found.</em></p>
    <?php else : ?>
      <table class="widefat striped" style="max-width: 1100px;">
        <thead>
          <tr>
            <th>Label / Path</th>
            <th>Slug</th>
            <th>UUID</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($included_uuids as $included_uuid) : ?>
            <?php
            $included_uuid = (string) $included_uuid;
            $included_term = $included_terms_by_uuid[$included_uuid] ?? null;
            ?>
            <tr>
              <?php if ($included_term) : ?>
                <td>
                  <?php echo esc_html((string) $included_term->label); ?>
                  <br><code><?php echo esc_html((string) $included_term->path); ?></code>
                </td>
                <td><code><?php echo esc_html((string) $included_term->slug); ?></code></td>
                <td><code><?php echo esc_html((string) $included_term->term_uuid); ?></code></td>
              <?php else : ?>
                <td><strong>Missing compiled term</strong></td>
                <td><em>n/a</em></td>
                <td><code><?php echo esc_html($included_uuid); ?></code></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <h3>ANY Match Sample</h3>
    <?php self::render_audience_api_user_sample($any_user_ids); ?>

    <h3>ALL Match Sample</h3>
    <?php self::render_audience_api_user_sample($all_user_ids); ?>

    <h3>Example Audience API Calls</h3>
    <pre style="background:#fff; border:1px solid #ccd0d4; padding:12px; max-width:900px; overflow:auto;"><code>CFM::get_meta_group('<?php echo esc_html((string) $framework_slug); ?>', '<?php echo esc_html((string) ($meta_group->slug ?? '')); ?>');
CFM::get_meta_group_included_term_uuids('<?php echo esc_html((string) $framework_slug); ?>', '<?php echo esc_html((string) ($meta_group->slug ?? '')); ?>');
CFM::get_users_matching_meta_group_any('<?php echo esc_html((string) $framework_slug); ?>', '<?php echo esc_html((string) ($meta_group->slug ?? '')); ?>');
CFM::get_users_matching_meta_group_all('<?php echo esc_html((string) $framework_slug); ?>', '<?php echo esc_html((string) ($meta_group->slug ?? '')); ?>');</code></pre>
  <?php
  }

  private static function render_audience_api_user_sample(array $user_ids): void
  {
    $user_ids = array_values(array_unique(array_filter(array_map('absint', $user_ids))));

    if (empty($user_ids)) {
      echo '<p><em>No matching users.</em></p>';
      return;
    }

    $sample_user_ids = array_slice($user_ids, 0, 20);
    $users = get_users([
      'include' => $sample_user_ids,
      'orderby' => 'include',
      'fields' => ['ID', 'user_login', 'user_email', 'display_name'],
    ]);

    if (empty($users)) {
      echo '<p><em>No readable user records found.</em></p>';
      return;
    }

  ?>
    <p class="description">Showing first <?php echo esc_html((string) count($users)); ?> of <?php echo esc_html((string) count($user_ids)); ?> matched user ID(s).</p>
    <table class="widefat striped" style="max-width: 900px;">
      <thead>
        <tr>
          <th>User ID</th>
          <th>Login</th>
          <th>Email</th>
          <th>Display Name</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user) : ?>
          <tr>
            <td><?php echo esc_html((string) $user->ID); ?></td>
            <td><code><?php echo esc_html((string) $user->user_login); ?></code></td>
            <td><?php echo esc_html((string) $user->user_email); ?></td>
            <td><?php echo esc_html((string) $user->display_name); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php
  }

  private static function render_audience_contract_smoke_test_panel(string $framework_slug, array $term_inputs, array $meta_group_inputs, string $operator, array $expanded_term_uuids, array $user_ids, array $invalid_terms, array $invalid_meta_groups): void
  {
    $audience = [
      'framework' => $framework_slug,
      'terms' => array_values($term_inputs),
      'meta_groups' => array_values($meta_group_inputs),
      'operator' => $operator,
      'context' => 'profile',
      'include_descendants' => true,
    ];

  ?>
    <table class="widefat striped" style="max-width: 760px; margin-bottom: 14px;">
      <tbody>
        <tr>
          <th style="width: 240px;">Framework</th>
          <td><code><?php echo esc_html($framework_slug); ?></code></td>
        </tr>
        <tr>
          <th>Match Mode</th>
          <td><code><?php echo esc_html($operator); ?></code></td>
        </tr>
        <tr>
          <th>Selected Terms</th>
          <td><?php echo esc_html((string) count($term_inputs)); ?></td>
        </tr>
        <tr>
          <th>Selected Meta-Groups</th>
          <td><?php echo esc_html((string) count($meta_group_inputs)); ?></td>
        </tr>
        <tr>
          <th>Resolved Terms</th>
          <td><?php echo esc_html((string) count($expanded_term_uuids)); ?></td>
        </tr>
        <tr>
          <th>Resolved Users</th>
          <td><?php echo esc_html((string) count($user_ids)); ?></td>
        </tr>
      </tbody>
    </table>

    <?php if (!empty($invalid_terms) || !empty($invalid_meta_groups)) : ?>
      <div class="notice notice-warning inline" style="max-width: 900px;">
        <p>One or more selected Audience inputs were not found in the active compiled taxonomy.</p>
        <?php if (!empty($invalid_terms)) : ?>
          <p><strong>Invalid/stale Terms:</strong> <code><?php echo esc_html(implode(', ', $invalid_terms)); ?></code></p>
        <?php endif; ?>
        <?php if (!empty($invalid_meta_groups)) : ?>
          <p><strong>Invalid/stale Meta-Groups:</strong> <code><?php echo esc_html(implode(', ', $invalid_meta_groups)); ?></code></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <h3>Audience v1 Structure</h3>
    <pre style="background:#fff; border:1px solid #ccd0d4; padding:12px; max-width:900px; overflow:auto;"><code><?php echo esc_html(var_export($audience, true)); ?></code></pre>

    <h3>Resolved Term UUIDs</h3>
    <?php if (empty($expanded_term_uuids)) : ?>
      <p><em>No Terms resolved from this Audience.</em></p>
    <?php else : ?>
      <pre style="background:#fff; border:1px solid #ccd0d4; padding:12px; max-width:900px; overflow:auto;"><code><?php echo esc_html(implode("
", $expanded_term_uuids)); ?></code></pre>
    <?php endif; ?>

    <h3>Resolved User Sample</h3>
    <?php self::render_audience_api_user_sample($user_ids); ?>

    <h3>Example Consumer Call</h3>
    <pre style="background:#fff; border:1px solid #ccd0d4; padding:12px; max-width:900px; overflow:auto;"><code>$audience = <?php echo esc_html(var_export($audience, true)); ?>;
$user_ids = CFM::resolve_users($audience);</code></pre>
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
          <th>Top-Level Term UUID</th>
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

  public static function render_core_terms_editor_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to access this page.');
    }

    $framework_id = isset($_GET['framework_id'])
      ? absint($_GET['framework_id'])
      : 0;

    if ($framework_id <= 0) {
      $primary_framework = self::primary_framework_for_admin();
      $framework_id = $primary_framework ? (int) $primary_framework->id : 0;
    }

    $framework = CFM_Framework_Repository::get_framework($framework_id);

    if (!$framework) {
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $terms = self::root_terms($tree);
    $editor_errors = [];
    $ordering_groups = [];
    $order_revisions = [];
    $move_parent_options = self::collect_core_terms_editor_move_options($terms);
    $branch_assignment_counts = self::collect_branch_assignment_counts($terms);

    self::collect_ordering_groups($tree, $ordering_groups);

    foreach ($ordering_groups as $ordering_group) {
      $parent_uuid = (string) ($ordering_group['parent_uuid'] ?? '');

      if ($parent_uuid !== '') {
        $order_revisions[$parent_uuid] = self::get_order_revision((int) $framework->id, $parent_uuid);
      }
    }

    if (isset($_GET['cfm_editor_error'])) {
      $maybe_editor_errors = get_transient(self::core_terms_editor_error_transient_key((int) $framework->id));

      if (is_array($maybe_editor_errors)) {
        $editor_errors = array_values(array_filter(array_map('strval', $maybe_editor_errors)));
        delete_transient(self::core_terms_editor_error_transient_key((int) $framework->id));
      }
    }
  ?>
    <div class="wrap">
      <h1>Core Terms Editor</h1>
      <p>
        <a href="<?php echo esc_url(self::edit_url((int) $framework->id)); ?>">← Back to Core Terms</a>
      </p>
      <p class="description">
        Select a row to edit existing Core Term fields inline, insert a sibling draft, add a child draft, archive a branch, or reorder siblings.
      </p>

      <?php
      $saved_count = false;

      if (isset($_GET['cfm_editor_saved'])) {
        $saved_count = get_transient(self::core_terms_editor_saved_transient_key((int) $framework->id));

        if ($saved_count !== false) {
          delete_transient(self::core_terms_editor_saved_transient_key((int) $framework->id));
        }
      }
      ?>

      <?php if ($saved_count !== false) : ?>
        <div class="notice notice-success is-dismissible">
          <p><?php echo absint($saved_count); ?> Core Term row(s) saved and runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_editor_archived'])) : ?>
        <div class="notice notice-warning">
          <p>
            Core Term branch archived from the active tree and runtime tables rebuilt.
            <?php if (!empty($_GET['cfm_undo_archive'])) : ?>
              You can restore it now.
            <?php endif; ?>
          </p>
          <?php if (!empty($_GET['cfm_undo_archive'])) : ?>
            <form method="post" style="margin: 8px 0 0;">
              <?php wp_nonce_field('cfm_core_terms_editor_undo_archive', 'cfm_nonce'); ?>
              <input type="hidden" name="cfm_action" value="core_terms_editor_undo_archive">
              <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) (int) $framework->id); ?>">
              <input type="hidden" name="undo_key" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['cfm_undo_archive']))); ?>">
              <button type="submit" class="button button-secondary">Undo Archive</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_editor_unarchived'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Archived Core Term branch restored and runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_editor_moved'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Core Term branch moved and runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (!empty($_GET['cfm_editor_move_error'])) :
        $move_error = sanitize_key((string) wp_unslash($_GET['cfm_editor_move_error']));
        $move_error_messages = [
          'missing_fields' => 'Move request was incomplete.',
          'self' => 'A term cannot be moved under itself.',
          'missing_term' => 'The term being moved could not be found.',
          'invalid_term' => 'Only Core Terms can be moved from this editor.',
          'missing_parent' => 'The selected destination parent could not be found.',
          'invalid_parent' => 'Move To can only move a branch under another Core Term.',
          'descendant' => 'A term cannot be moved under one of its own descendants.',
          'missing_slug' => 'The term slug is missing. Move aborted.',
          'duplicate_sibling_slug' => 'Move blocked because the destination already has a child with the same slug.',
          'same_parent' => 'Choose a different parent before moving this branch.',
          'assignment_warning' => 'This branch has active user assignments. Confirm the assignment warning before moving it.',
          'axis_warning' => 'This move changes the branch axis. Confirm the axis warning before moving it.',
          'remove_failed' => 'The branch could not be removed from its current parent.',
          'add_failed' => 'The branch could not be added to the selected parent.',
        ];
      ?>
        <div class="notice notice-error is-dismissible">
          <p>
            <?php echo esc_html($move_error_messages[$move_error] ?? 'Core Term branch could not be moved.'); ?>
            <?php if (!empty($_GET['cfm_assignment_count'])) : ?>
              Assignment count: <?php echo esc_html((string) absint($_GET['cfm_assignment_count'])); ?>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_editor_archive_blocked'])) : ?>
        <div class="notice notice-error">
          <p>
            This branch cannot be archived because it or one of its descendants has active user assignments.
            Move or reassign users first.
            <?php if (!empty($_GET['cfm_assignment_count'])) : ?>
              Assignment count: <?php echo esc_html((string) absint($_GET['cfm_assignment_count'])); ?>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_editor_undo_expired'])) : ?>
        <div class="notice notice-error is-dismissible">
          <p>The archive undo window has expired. Use version history if you need to recover that branch.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_editor_undo_conflict'])) : ?>
        <div class="notice notice-error is-dismissible">
          <p>The archived branch could not be restored because its original location or slug is no longer available.</p>
        </div>
      <?php endif; ?>

      <?php if (!empty($editor_errors)) : ?>
        <div class="notice notice-error is-dismissible">
          <p>Core Terms Editor changes were not saved.</p>
          <ul>
            <?php foreach ($editor_errors as $editor_error) : ?>
              <li><?php echo esc_html($editor_error); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <style>
        .cfm-core-terms-editor {
          --cfm-core-terms-depth-offset: 0px;
          --cfm-core-terms-label-width: 300px;
          display: grid;
          gap: 8px;
          max-width: 1200px;
        }

        .cfm-core-terms-editor-form {
          max-width: 1200px;
        }

        .cfm-core-terms-editor-toolbar {
          align-items: center;
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
          margin: 12px 0 14px;
        }

        .cfm-core-terms-editor-toolbar[hidden] {
          display: none;
        }

        .cfm-core-terms-editor-dirty-count {
          color: #646970;
          font-size: 13px;
          margin-left: 4px;
        }

        .cfm-core-terms-editor-row {
          align-items: center;
          display: grid;
          gap: 12px;
          grid-template-columns: 64px minmax(120px, calc(var(--cfm-core-terms-label-width) - var(--cfm-core-terms-depth-offset))) max-content 92px 1fr;
        }

        .cfm-core-terms-editor-reference {
          display: grid;
          gap: 1px;
          margin-bottom: 6px;
          padding: 4px 0 0 11px;
        }

        .cfm-core-terms-editor-reference-title {
          color: #1d2327;
          font-size: 13px;
          font-weight: 600;
          margin-bottom: 2px;
        }

        .cfm-core-terms-editor-reference-row {
          align-items: center;
          display: grid;
          gap: 12px;
          grid-template-columns: 64px var(--cfm-core-terms-label-width) max-content 92px 1fr;
        }

        .cfm-core-terms-editor-reference-metadata {
          display: grid;
          gap: 0;
          grid-template-columns: 150px 130px 190px;
          justify-content: start;
        }

        .cfm-core-terms-editor-reference-metadata span {
          text-align: left;
        }

        .cfm-core-terms-editor-reference-labels {
          color: #1d2327;
          font-size: 12px;
          font-weight: 600;
          letter-spacing: 0.02em;
          text-transform: uppercase;
        }

        .cfm-core-terms-editor-reference-labels span[title] {
          cursor: help;
          outline-offset: 2px;
        }

        .cfm-core-terms-editor-reference-labels span[title]:focus {
          box-shadow: 0 0 0 2px #2271b1;
        }

        .cfm-core-terms-editor-reference-example span {
          color: #50575e;
          font-style: italic;
          min-height: 24px;
          padding: 2px 0;
        }

        .cfm-core-terms-editor-reference-spacer,
        .cfm-core-terms-editor-reference-actions {
          min-height: 1px;
        }

        .cfm-core-terms-editor-divider {
          border: 0;
          border-top: 1px solid #c3c4c7;
          margin: 0 0 8px;
        }

        .cfm-core-terms-editor-rail {
          align-items: center;
          box-sizing: border-box;
          display: grid;
          gap: 8px;
          grid-template-columns: 28px 20px;
          justify-content: start;
          min-height: 32px;
        }

        .cfm-core-terms-editor-caret-slot {
          align-items: center;
          display: flex;
          height: 28px;
          justify-content: center;
          width: 28px;
        }

        .cfm-core-terms-editor-caret {
          border-bottom: 4px solid transparent;
          border-left: 5px solid #787c82;
          border-top: 4px solid transparent;
          display: inline-block;
          height: 0;
          transition: transform 120ms ease;
          width: 0;
        }

        .cfm-core-terms-editor-term details[open] > summary .cfm-core-terms-editor-caret {
          transform: rotate(90deg);
        }

        .cfm-core-terms-editor-handle {
          color: #a7aaad;
          cursor: grab;
          font-size: 16px;
          line-height: 1;
          text-align: center;
        }

        .cfm-core-terms-editor-handle:focus-visible {
          box-shadow: 0 0 0 2px #2271b1;
          outline: 2px solid transparent;
        }

        .cfm-core-terms-editor.is-reorder-disabled .cfm-core-terms-editor-handle,
        .cfm-core-terms-editor-row.is-draft .cfm-core-terms-editor-handle {
          cursor: default;
        }

        .cfm-core-terms-editor-field,
        .cfm-core-terms-editor-actions {
          border: 1px solid transparent;
          border-bottom-color: #edf0f2;
          box-sizing: border-box;
          color: #1d2327;
          display: block;
          min-height: 32px;
          overflow-wrap: anywhere;
          padding: 5px 4px;
        }

        .cfm-core-terms-editor-actions {
          align-items: center;
          display: flex;
          flex-wrap: nowrap;
          gap: 4px;
          justify-content: flex-start;
          overflow: visible;
          position: relative;
        }

        .cfm-core-terms-editor-display {
          display: inline;
        }

        .cfm-core-terms-editor-edit {
          display: none;
        }

        .cfm-core-terms-editor-row.is-selected .cfm-core-terms-editor-display {
          display: none;
        }

        .cfm-core-terms-editor-row.is-selected .cfm-core-terms-editor-edit {
          display: inline-flex;
        }

        .cfm-core-terms-editor-input {
          background: transparent;
          border: 1px solid transparent;
          border-bottom-color: #c3c4c7;
          border-radius: 0;
          box-shadow: none;
          box-sizing: border-box;
          color: inherit;
          font: inherit;
          line-height: inherit;
          margin: -2px 0;
          min-height: 24px;
          padding: 1px 2px;
          width: 100%;
        }

        .cfm-core-terms-editor .cfm-core-terms-editor-input[type="text"] {
          box-sizing: border-box;
          min-height: 28px;
          padding: 2px 6px;
        }

        .cfm-core-terms-editor-input:focus {
          background: #fff;
          border-color: #2271b1;
          box-shadow: 0 0 0 1px #2271b1;
          outline: none;
        }

        .cfm-core-terms-editor-input-label {
          font-size: 16px;
          max-width: 100%;
        }

        .cfm-core-terms-editor-field-label {
          color: #1d2327;
        }

        .cfm-core-terms-editor-meta {
          align-items: center;
          color: #646970;
          display: inline-flex;
          flex-wrap: wrap;
          font-size: 12px;
          font-style: normal;
          gap: 0;
          line-height: 1.4;
        }

        .cfm-core-terms-editor-meta-edit {
          align-items: center;
          display: none;
          gap: 0;
          width: 100%;
        }

        .cfm-core-terms-editor-row.is-selected .cfm-core-terms-editor-meta-edit {
          display: inline-flex;
        }

        .cfm-core-terms-editor-input-slug,
        .cfm-core-terms-editor-input-short-label,
        .cfm-core-terms-editor-input-community {
          color: #646970;
          font-size: 12px;
          min-width: 0;
        }

        .cfm-core-terms-editor-input-slug {
          width: 31%;
        }

        .cfm-core-terms-editor-input-short-label {
          width: 24%;
        }

        .cfm-core-terms-editor-input-community {
          width: 39%;
        }

        .cfm-core-terms-editor-meta-part,
        .cfm-core-terms-editor-meta-separator {
          color: #646970;
          font-size: 12px;
          font-style: normal;
        }

        .cfm-core-terms-editor-meta-separator {
          padding: 0 6px;
        }

        .cfm-core-terms-editor-term {
          border-left: 1px solid #edf0f2;
          padding-left: 10px;
        }

        .cfm-core-terms-editor-term + .cfm-core-terms-editor-term {
          margin-top: 3px;
        }

        .cfm-core-terms-editor-term details {
          margin: 0;
        }

        .cfm-core-terms-editor-term summary {
          cursor: pointer;
          display: block;
          list-style: none;
          outline: none;
        }

        .cfm-core-terms-editor-term summary::-webkit-details-marker {
          display: none;
        }

        .cfm-core-terms-editor-term summary .cfm-core-terms-editor-row {
          border-radius: 4px;
          width: 100%;
        }

        .cfm-core-terms-editor-row.is-selected {
          background: #f6f7f7;
        }

        .cfm-core-terms-editor-row.is-dirty {
          background: #fff8e5;
        }

        .cfm-core-terms-editor-row.is-selected.is-dirty {
          background: #fff4ce;
        }

        .cfm-core-terms-editor-row.is-reorder-source {
          background: #e7f3ff;
          opacity: 0.72;
        }

        .cfm-core-terms-editor-row.is-reorder-target-before,
        .cfm-core-terms-editor-row.is-branch-drop-before {
          box-shadow: inset 0 2px 0 #2271b1;
        }

        .cfm-core-terms-editor-row.is-reorder-target-after,
        .cfm-core-terms-editor-row.is-branch-drop-after {
          box-shadow: inset 0 -2px 0 #2271b1;
        }

        .cfm-core-terms-editor-row.is-branch-drop-child {
          background: #eef6fc;
          box-shadow: inset 4px 0 0 #2271b1;
        }

        .cfm-core-terms-editor-row.is-branch-drop-child::after {
          color: #2271b1;
          content: "↳ child";
          font-size: 11px;
          font-weight: 600;
          justify-self: start;
          text-transform: uppercase;
        }

        .cfm-core-terms-editor-row.has-error {
          background: #fcf0f1;
        }

        .cfm-core-terms-editor-row.has-error .cfm-core-terms-editor-input {
          border-bottom-color: #d63638;
        }

        .cfm-core-terms-editor-status {
          color: #8a6d00;
          display: none;
          font-size: 12px;
          line-height: 1.3;
          padding-top: 7px;
        }

        .cfm-core-terms-editor-row.is-dirty .cfm-core-terms-editor-status {
          display: none;
        }

        .cfm-core-terms-editor-row-save,
        .cfm-core-terms-editor-cancel-draft {
          display: none !important;
          min-height: 26px;
          padding: 0 8px;
        }

        .cfm-core-terms-editor-row.is-selected.is-dirty .cfm-core-terms-editor-row-save:not([hidden]) {
          display: inline-flex !important;
          align-items: center;
        }

        .cfm-core-terms-editor-row.is-selected.is-draft .cfm-core-terms-editor-cancel-draft {
          align-items: center;
          display: inline-flex !important;
        }

        .cfm-core-terms-editor-row-action {
          align-items: center;
          background: transparent;
          border: 0;
          border-radius: 4px;
          box-shadow: none;
          color: #50575e;
          cursor: pointer;
          display: inline-flex;
          height: 28px;
          justify-content: center;
          line-height: 1;
          margin: 0;
          opacity: 0;
          padding: 0;
          pointer-events: none;
          transition: background-color 120ms ease, color 120ms ease;
          visibility: hidden;
          width: 28px;
        }

        .cfm-core-terms-editor-row-action svg {
          display: block;
          height: 16px;
          fill: none;
          stroke: currentColor;
          stroke-linecap: round;
          stroke-linejoin: round;
          stroke-width: 1.8;
          width: 16px;
        }

        .cfm-core-terms-editor-action-menu {
          background: #fff;
          border: 1px solid #c3c4c7;
          border-radius: 6px;
          box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
          display: grid;
          gap: 2px;
          left: 0;
          min-width: 160px;
          padding: 5px;
          position: absolute;
          top: calc(100% + 2px);
          z-index: 20;
        }

        .cfm-core-terms-editor-action-menu[hidden] {
          display: none;
        }

        .cfm-core-terms-editor-action-menu button {
          align-items: center;
          background: transparent;
          border: 0;
          border-radius: 4px;
          color: #1d2327;
          cursor: pointer;
          display: flex;
          font-size: 12px;
          gap: 8px;
          line-height: 1.3;
          padding: 6px 8px;
          text-align: left;
          width: 100%;
        }

        .cfm-core-terms-editor-action-menu button[hidden] {
          display: none;
        }

        .cfm-core-terms-editor-action-menu-label {
          color: #1d2327;
          display: block;
          font-size: 12px;
          line-height: 1.3;
          padding: 6px 8px;
          white-space: nowrap;
        }

        .cfm-core-terms-editor-action-menu button:hover,
        .cfm-core-terms-editor-action-menu button:focus-visible {
          background: #f0f6fc;
          color: #135e96;
          outline: none;
        }

        .cfm-core-terms-editor-action-menu button:focus-visible {
          box-shadow: 0 0 0 2px #2271b1;
        }

        .cfm-core-terms-editor-row:not(.is-draft):hover .cfm-core-terms-editor-row-action {
          opacity: 1;
          pointer-events: auto;
          visibility: visible;
        }

        .cfm-core-terms-editor.is-edit-interaction-locked .cfm-core-terms-editor-row-action {
          opacity: 0 !important;
          pointer-events: none !important;
          visibility: hidden !important;
        }

        .cfm-core-terms-editor-row-action:hover,
        .cfm-core-terms-editor-row-action:focus-visible {
          background: #f0f6fc;
          color: #135e96;
        }

        .cfm-core-terms-editor-row-action:disabled {
          color: #c3c4c7;
          cursor: default;
        }

        .cfm-core-terms-editor-row-action:disabled:hover {
          background: transparent;
          color: #c3c4c7;
        }

        .cfm-core-terms-editor-row-action:focus-visible {
          box-shadow: 0 0 0 2px #2271b1;
          outline: 2px solid transparent;
        }

        .cfm-core-terms-editor-archive-branch:hover,
        .cfm-core-terms-editor-archive-branch:focus-visible {
          color: #b32d2e;
        }

        .cfm-core-terms-editor-move-modal[hidden] {
          display: none;
        }

        .cfm-core-terms-editor-move-modal {
          align-items: center;
          bottom: 0;
          display: flex;
          justify-content: center;
          left: 0;
          position: fixed;
          right: 0;
          top: 0;
          z-index: 100000;
        }

        .cfm-core-terms-editor-move-backdrop {
          background: rgba(0, 0, 0, 0.35);
          bottom: 0;
          left: 0;
          position: absolute;
          right: 0;
          top: 0;
        }

        .cfm-core-terms-editor-move-panel {
          background: #fff;
          border: 1px solid #c3c4c7;
          border-radius: 6px;
          box-shadow: 0 16px 42px rgba(0, 0, 0, 0.22);
          box-sizing: border-box;
          display: grid;
          gap: 10px;
          max-width: min(520px, calc(100vw - 40px));
          padding: 20px;
          position: relative;
          width: 520px;
        }

        .cfm-core-terms-editor-move-panel h2 {
          margin: 0;
        }

        .cfm-core-terms-editor-move-panel select {
          max-width: 100%;
          width: 100%;
        }

        .cfm-core-terms-editor-move-warning {
          background: #fff8e5;
          border-left: 4px solid #dba617;
          padding: 8px 10px;
        }

        .cfm-core-terms-editor-move-error {
          color: #b32d2e;
          margin: 0;
        }

        .cfm-core-terms-editor-move-actions {
          display: flex;
          gap: 8px;
          justify-content: flex-start;
          margin-top: 4px;
        }

        .cfm-core-terms-editor-row.has-error .cfm-core-terms-editor-status {
          color: #b32d2e;
          display: inline;
        }

        .cfm-core-terms-editor-term summary:focus {
          box-shadow: none;
        }

        .cfm-core-terms-editor-term summary:focus .cfm-core-terms-editor-row {
          background: #f6f7f7;
        }

        .cfm-core-terms-editor-children {
          display: grid;
          gap: 3px;
          margin: 5px 0 0 26px;
        }

        .cfm-core-terms-editor-reorder-status {
          margin: 0 0 4px 0;
        }

        .cfm-core-terms-editor-status-rail {
          align-items: center;
          display: flex;
          gap: 8px;
          justify-content: flex-start;
          min-height: 22px;
        }

        .cfm-core-terms-editor-move-notice {
          align-items: center;
          color: #50575e;
          display: flex;
          gap: 6px;
        }

        .cfm-core-terms-editor-move-notice[hidden] {
          display: none;
        }

        .cfm-core-terms-editor-status-separator {
          color: #8c8f94;
        }

        .cfm-core-terms-editor-drag-preview {
          background: #1d2327;
          border-radius: 6px;
          box-shadow: 0 8px 18px rgba(0, 0, 0, 0.2);
          color: #fff;
          font-size: 12px;
          left: 0;
          line-height: 1.35;
          max-width: 220px;
          padding: 8px 10px;
          pointer-events: none;
          position: fixed;
          top: 0;
          transform: translate(-9999px, -9999px);
          z-index: 100001;
        }

        .cfm-core-terms-editor-drag-preview strong {
          display: block;
          font-size: 13px;
        }

        .cfm-core-terms-editor-collapse-all {
          color: #2271b1;
          font-size: 12px;
          line-height: 1.4;
          text-decoration: underline;
        }

        .cfm-core-terms-editor-depth-1 > details > summary > .cfm-core-terms-editor-row,
        .cfm-core-terms-editor-depth-1 > .cfm-core-terms-editor-row {
          --cfm-core-terms-depth-offset: 37px;
        }

        .cfm-core-terms-editor-depth-2 > details > summary > .cfm-core-terms-editor-row,
        .cfm-core-terms-editor-depth-2 > .cfm-core-terms-editor-row {
          --cfm-core-terms-depth-offset: 74px;
        }

        .cfm-core-terms-editor-depth-3 > details > summary > .cfm-core-terms-editor-row,
        .cfm-core-terms-editor-depth-3 > .cfm-core-terms-editor-row {
          --cfm-core-terms-depth-offset: 111px;
        }

        .cfm-core-terms-editor-depth-4 > details > summary > .cfm-core-terms-editor-row,
        .cfm-core-terms-editor-depth-4 > .cfm-core-terms-editor-row {
          --cfm-core-terms-depth-offset: 148px;
        }

        @media (max-width: 900px) {
          .cfm-core-terms-editor-row {
            gap: 8px;
            grid-template-columns: 48px 1fr;
          }

          .cfm-core-terms-editor-reference-row {
            grid-template-columns: 1fr;
          }

          .cfm-core-terms-editor-reference-spacer,
          .cfm-core-terms-editor-reference-actions {
            display: none;
          }

          .cfm-core-terms-editor-field,
          .cfm-core-terms-editor-meta,
          .cfm-core-terms-editor-actions {
            grid-column: 2;
          }

          .cfm-core-terms-editor-meta {
            display: flex;
          }

          .cfm-core-terms-editor-actions {
            min-height: 0;
          }
        }
      </style>

      <form class="cfm-core-terms-editor-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=editor&framework_id=' . (int) $framework->id)); ?>">
        <?php wp_nonce_field('cfm_core_terms_editor_save', 'cfm_nonce'); ?>
        <input type="hidden" name="cfm_action" value="core_terms_editor_save">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) (int) $framework->id); ?>">
        <input type="hidden" name="cfm_editor_changes" value="">

        <div class="cfm-core-terms-editor-toolbar" aria-label="Core Terms Editor actions" hidden>
          <button type="submit" class="button button-primary cfm-core-terms-editor-save" disabled>Save Changes</button>
          <button type="button" class="button cfm-core-terms-editor-reset" disabled>Reset Changes</button>
          <span class="cfm-core-terms-editor-dirty-count" aria-live="polite">No unsaved changes.</span>
        </div>

        <section
          class="cfm-core-terms-editor"
          aria-label="Core Terms Editor"
          data-framework-id="<?php echo esc_attr((string) (int) $framework->id); ?>"
          data-root-parent-uuid="<?php echo esc_attr((string) ($tree['uuid'] ?? '')); ?>"
          data-reorder-nonce="<?php echo esc_attr(wp_create_nonce('cfm_reorder_terms')); ?>"
          data-move-branch-nonce="<?php echo esc_attr(wp_create_nonce('cfm_move_branch')); ?>"
          data-order-revisions="<?php echo esc_attr(wp_json_encode($order_revisions)); ?>"
        >
          <?php self::render_core_terms_editor_reference_row(); ?>
          <hr class="cfm-core-terms-editor-divider">
          <div class="cfm-core-terms-editor-status-rail" aria-label="Core Terms Editor status">
            <a href="#" class="cfm-core-terms-editor-collapse-all" hidden>Collapse all</a>
            <span class="cfm-core-terms-editor-status-separator" hidden>|</span>
            <span class="cfm-core-terms-editor-move-notice" role="status" aria-live="polite" hidden>
              <span>Branch moved</span>
              <span aria-hidden="true">·</span>
              <a href="#" class="cfm-core-terms-editor-undo-move">Undo</a>
            </span>
          </div>
          <p class="cfm-core-terms-editor-reorder-status description" aria-live="polite"></p>

          <?php if (empty($terms)) : ?>
            <p>No Core Terms found.</p>
          <?php else : ?>
            <div class="cfm-core-terms-editor-tree">
              <?php self::render_core_terms_editor_nodes($terms, 0, $branch_assignment_counts); ?>
            </div>
          <?php endif; ?>
        </section>
      </form>
      <form class="cfm-core-terms-editor-move-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=editor&framework_id=' . (int) $framework->id)); ?>" hidden>
        <?php wp_nonce_field('cfm_move_term', 'cfm_nonce'); ?>
        <input type="hidden" name="cfm_action" value="move_term">
        <input type="hidden" name="cfm_return" value="editor">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) (int) $framework->id); ?>">
        <input type="hidden" name="term_uuid" value="">
        <input type="hidden" name="new_parent_uuid" value="">
        <input type="hidden" name="cfm_confirm_assignments" value="">
        <input type="hidden" name="cfm_confirm_axis_change" value="">
      </form>
      <div class="cfm-core-terms-editor-move-modal" role="dialog" aria-modal="true" aria-labelledby="cfm-core-terms-editor-move-title" hidden>
        <div class="cfm-core-terms-editor-move-backdrop" data-cfm-move-cancel="1"></div>
        <div class="cfm-core-terms-editor-move-panel">
          <h2 id="cfm-core-terms-editor-move-title">Move Branch</h2>
          <p class="description cfm-core-terms-editor-move-summary"></p>
          <label for="cfm-core-terms-editor-new-parent">New Parent</label>
          <select id="cfm-core-terms-editor-new-parent" class="cfm-core-terms-editor-new-parent">
            <option value="">Select a new parent</option>
            <?php foreach ($move_parent_options as $move_parent_option) : ?>
              <option
                value="<?php echo esc_attr((string) $move_parent_option['uuid']); ?>"
                data-axis-uuid="<?php echo esc_attr((string) $move_parent_option['axis_uuid']); ?>"
              >
                <?php echo esc_html((string) $move_parent_option['path']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="description">The moved branch will be appended as the last child of the selected parent.</p>
          <div class="cfm-core-terms-editor-move-warning cfm-core-terms-editor-move-assignment-warning" hidden>
            <label>
              <input type="checkbox" class="cfm-core-terms-editor-confirm-assignments">
              This branch has <span class="cfm-core-terms-editor-assignment-count">0</span> active user assignment(s). I understand moving it may change the assignment context.
            </label>
          </div>
          <div class="cfm-core-terms-editor-move-warning cfm-core-terms-editor-move-axis-warning" hidden>
            <label>
              <input type="checkbox" class="cfm-core-terms-editor-confirm-axis">
              This move changes the branch axis/top-level context. I understand this can affect how consuming plugins group or display the branch.
            </label>
          </div>
          <p class="cfm-core-terms-editor-move-error" role="alert" hidden></p>
          <div class="cfm-core-terms-editor-move-actions">
            <button type="button" class="button button-primary cfm-core-terms-editor-move-submit" disabled>Move Branch</button>
            <button type="button" class="button cfm-core-terms-editor-move-cancel" data-cfm-move-cancel="1">Cancel</button>
          </div>
        </div>
      </div>
      <form class="cfm-core-terms-editor-archive-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=editor&framework_id=' . (int) $framework->id)); ?>" hidden>
        <?php wp_nonce_field('cfm_core_terms_editor_archive', 'cfm_nonce'); ?>
        <input type="hidden" name="cfm_action" value="core_terms_editor_archive">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) (int) $framework->id); ?>">
        <input type="hidden" name="term_uuid" value="">
      </form>
      <script>
        (function() {
          var form = null;
          var archiveForm = null;
          var archiveTermInput = null;
          var selectedRow = null;
          var dirtyRows = new Set();
          var saveButton = null;
          var resetButton = null;
          var dirtyCount = null;
          var toolbar = null;
          var changesInput = null;
          var editor = null;
          var editorTree = null;
          var reorderStatus = null;
          var moveNotice = null;
          var undoMoveButton = null;
          var statusRailSeparator = null;
          var reorderNonce = '';
          var moveBranchNonce = '';
          var rootParentUuid = '';
          var frameworkId = '';
          var orderRevisions = {};
          var pendingScrollRestore = null;
          var dragState = null;
          var branchDragState = null;
          var branchAutoExpandTimer = null;
          var lastUndoMove = null;
          var formSubmitting = false;
          var rowSaveRequested = null;
          var restoringDrafts = false;
          var draftCounter = 0;
          var autofillingDraft = false;
          var activeActionState = null;
          var actionMenuCloseTimer = null;
          var moveForm = null;
          var moveModal = null;
          var moveSelect = null;
          var moveSubmit = null;
          var moveSummary = null;
          var moveError = null;
          var moveAssignmentWarning = null;
          var moveAxisWarning = null;
          var moveAssignmentCheckbox = null;
          var moveAxisCheckbox = null;
          var moveAssignmentCount = null;
          var moveActiveRow = null;
          var collapseAllButton = null;

          function normalizeSlug(value) {
            return String(value || '')
              .toLowerCase()
              .replace(/&/g, ' and ')
              .replace(/['’`]/g, '')
              .replace(/[^a-z0-9]+/g, '-')
              .replace(/-+/g, '-')
              .replace(/^-|-$/g, '');
          }

          function normalizeShortLabel(value) {
            return String(value || '').replace(/\s*\/\s*/g, '/').trim();
          }

          function rowValues(row) {
            var values = {
              uuid: row.getAttribute('data-term-uuid') || '',
              label: inputValue(row, '.cfm-core-terms-editor-input-label'),
              slug: normalizeSlug(inputValue(row, '.cfm-core-terms-editor-input-slug')),
              short_label: normalizeShortLabel(inputValue(row, '.cfm-core-terms-editor-input-short-label')),
              description: inputValue(row, '.cfm-core-terms-editor-input-community')
            };

            if (isDraftRow(row)) {
              values.is_new = true;
              values.parent_uuid = row.getAttribute('data-draft-parent-uuid') || '';
              values.insert_after_uuid = row.getAttribute('data-draft-insert-after-uuid') || '';
              values.insert_before_uuid = row.getAttribute('data-draft-insert-before-uuid') || '';
              values.insert_mode = row.getAttribute('data-draft-mode') || '';
            }

            return values;
          }

          function storageKey(name) {
            var frameworkInput = form ? form.querySelector('input[name="framework_id"]') : null;
            var frameworkId = frameworkInput ? frameworkInput.value : '0';

            return 'cfmCoreTermsEditor.' + frameworkId + '.' + name;
          }

          function readStoredJson(name, fallback) {
            try {
              var stored = window.localStorage.getItem(storageKey(name));
              return stored ? JSON.parse(stored) : fallback;
            } catch (error) {
              return fallback;
            }
          }

          function writeStoredJson(name, value) {
            try {
              window.localStorage.setItem(storageKey(name), JSON.stringify(value));
            } catch (error) {
              return;
            }
          }

          function removeStored(name) {
            try {
              window.localStorage.removeItem(storageKey(name));
            } catch (error) {
              return;
            }
          }

          function escapeAttributeValue(value) {
            return String(value || '').replace(/\\/g, '\\\\').replace(/"/g, '\\"');
          }

          function rowByUuid(uuid) {
            if (!uuid) {
              return null;
            }

            return document.querySelector('.cfm-core-terms-editor-row[data-term-uuid="' + escapeAttributeValue(uuid) + '"]');
          }

          function originalValues(row) {
            if (isDraftRow(row)) {
              return {
                uuid: row.getAttribute('data-term-uuid') || '',
                label: '',
                slug: '',
                short_label: '',
                description: ''
              };
            }

            return {
              uuid: row.getAttribute('data-term-uuid') || '',
              label: row.getAttribute('data-original-label') || '',
              slug: normalizeSlug(row.getAttribute('data-original-slug') || ''),
              short_label: row.getAttribute('data-original-short-label') || '',
              description: row.getAttribute('data-original-community') || ''
            };
          }

          function isDraftRow(row) {
            return Boolean(row && row.getAttribute('data-draft-row') === '1');
          }

          function isEmptyDraftRow(row) {
            var values = rowValues(row);

            return isDraftRow(row) &&
              !values.label &&
              !values.slug &&
              !values.short_label &&
              !values.description;
          }

          function defaultCommunityForLabel(value) {
            value = String(value || '').trim().replace(/\s+/g, ' ');

            if (!value) {
              return '';
            }

            return value + ' Teachers';
          }

          function inputValue(row, selector) {
            var input = row.querySelector(selector);
            return input ? input.value.trim() : '';
          }

          function setInputValue(row, selector, value) {
            var input = row.querySelector(selector);

            if (input) {
              input.value = value;
            }
          }

          function setText(row, selector, value) {
            var target = row.querySelector(selector);

            if (target) {
              target.textContent = value;
            }
          }

          function syncRowDisplay(row) {
            var values = rowValues(row);

            setText(row, '.cfm-core-terms-editor-label-display', values.label);
            setText(row, '.cfm-core-terms-editor-meta-slug', values.slug);
            setText(row, '.cfm-core-terms-editor-meta-short-label', values.short_label);
            setText(row, '.cfm-core-terms-editor-meta-community', values.description);
          }

          function rowsEqual(a, b) {
            return a.label === b.label &&
              a.slug === b.slug &&
              a.short_label === b.short_label &&
              a.description === b.description;
          }

          function updateRowSaveVisibility(row) {
            var button = row ? row.querySelector('.cfm-core-terms-editor-row-save') : null;

            if (!button) {
              return;
            }

            button.hidden = !(row.classList.contains('is-selected') && row.classList.contains('is-dirty'));
          }

          function updateAllRowSaveVisibility() {
            document
              .querySelectorAll('.cfm-core-terms-editor-row[data-term-uuid]')
              .forEach(updateRowSaveVisibility);
          }

          function updateDirtyState(row) {
            var values = rowValues(row);
            var original = originalValues(row);
            var dirty = isDraftRow(row) ? !isEmptyDraftRow(row) : !rowsEqual(values, original);

            row.classList.toggle('is-dirty', dirty);

            if (dirty) {
              dirtyRows.add(row);
            } else {
              dirtyRows.delete(row);
            }

            updateToolbar();
            updateRowSaveVisibility(row);

            if (!restoringDrafts) {
              saveDraftState();
            }
          }

          function updateToolbar() {
            var count = dirtyRows.size;
            var hasDirty = count > 0;

            if (toolbar) {
              toolbar.hidden = !hasDirty;
            }

            if (saveButton) {
              saveButton.disabled = !hasDirty;
              saveButton.hidden = !hasDirty;
            }

            if (resetButton) {
              resetButton.disabled = !hasDirty;
              resetButton.hidden = !hasDirty;
            }

            if (dirtyCount) {
              dirtyCount.hidden = !hasDirty;
              dirtyCount.textContent = hasDirty
                ? count + ' unsaved row' + (count === 1 ? '.' : 's.')
                : '';
            }

            updateReorderControls();
          }

          function hasDraftRows() {
            return Boolean(document.querySelector('.cfm-core-terms-editor-row[data-draft-row="1"]'));
          }

          function isReorderDisabled() {
            return dirtyRows.size > 0 || hasDraftRows();
          }

          function isInlineEditField(target) {
            return Boolean(target && target.closest && target.closest('.cfm-core-terms-editor-input'));
          }

          function isEditInteractionLocked() {
            return isInlineEditField(document.activeElement);
          }

          function summaryHasActiveInlineEdit(summary) {
            return Boolean(summary && isInlineEditField(document.activeElement) && summary.contains(document.activeElement));
          }

          function updateEditInteractionLock() {
            if (!editor) {
              return;
            }

            var locked = isEditInteractionLocked();
            editor.classList.toggle('is-edit-interaction-locked', locked);

            if (locked) {
              closeActionMenus();
            }
          }

          function handleInlineEditKeyEvent(event) {
            if (!isInlineEditField(event.target)) {
              return;
            }

            if (event.key === ' ' || event.key === 'Spacebar' || event.code === 'Space') {
              updateEditInteractionLock();
              event.stopImmediatePropagation();
            }
          }

          function setReorderStatus(message, isError) {
            if (!reorderStatus) {
              return;
            }

            reorderStatus.textContent = message || '';
            reorderStatus.style.color = isError ? '#b32d2e' : '';
          }

          function siblingGroupForRow(row) {
            var term = row ? row.closest('.cfm-core-terms-editor-term') : null;
            var container = term ? term.parentElement : null;

            if (!term || !container) {
              return null;
            }

            var terms = Array.from(container.children).filter(function(candidate) {
              return candidate.classList && candidate.classList.contains('cfm-core-terms-editor-term');
            });
            var parentTerm = container.closest('.cfm-core-terms-editor-term');
            var parentRow = parentTerm ? directRow(parentTerm) : null;
            var parentUuid = parentRow ? (parentRow.getAttribute('data-term-uuid') || '') : rootParentUuid;

            return {
              container: container,
              term: term,
              parentUuid: parentUuid,
              terms: terms,
              rows: terms.map(directRow).filter(Boolean)
            };
          }

          function rowIndexInGroup(row, group) {
            return group && group.rows ? group.rows.indexOf(row) : -1;
          }

          function groupOrder(group) {
            return group.rows.map(function(row) {
              return row.getAttribute('data-term-uuid') || '';
            }).filter(Boolean);
          }

          function restoreGroupOrder(group, previousOrder) {
            var termsByUuid = {};

            group.terms.forEach(function(term) {
              var row = directRow(term);
              var uuid = row ? (row.getAttribute('data-term-uuid') || '') : '';

              if (uuid) {
                termsByUuid[uuid] = term;
              }
            });

            previousOrder.forEach(function(uuid) {
              if (termsByUuid[uuid]) {
                group.container.appendChild(termsByUuid[uuid]);
              }
            });
          }

          function updateReorderControls() {
            var disabled = isReorderDisabled();

            if (editor) {
              editor.classList.toggle('is-reorder-disabled', disabled);
            }

            document
              .querySelectorAll('.cfm-core-terms-editor-row[data-term-uuid]')
              .forEach(function(row) {
                var up = row.querySelector('.cfm-core-terms-editor-move-up');
                var down = row.querySelector('.cfm-core-terms-editor-move-down');
                var group = siblingGroupForRow(row);
                var index = group ? rowIndexInGroup(row, group) : -1;
                var rowDisabled = disabled || isDraftRow(row) || !group || !group.parentUuid || group.rows.length < 2;

                if (up) {
                  up.disabled = rowDisabled || index <= 0;
                }

                if (down) {
                  down.disabled = rowDisabled || index < 0 || index >= group.rows.length - 1;
                }
              });
          }

          function submitSiblingOrder(group, previousOrder) {
            var order = groupOrder(group);
            var body = new URLSearchParams();

            body.append('action', 'cfm_reorder_terms');
            body.append('cfm_nonce', reorderNonce);
            body.append('framework_id', frameworkId);
            body.append('parent_uuid', group.parentUuid);
            body.append('order_revision', String(orderRevisions[group.parentUuid] || 0));
            order.forEach(function(uuid) {
              body.append('term_order[]', uuid);
            });

            setReorderStatus('Saving order...', false);
            updateReorderControls();

            return window.fetch(ajaxurl, {
              method: 'POST',
              credentials: 'same-origin',
              body: body
            }).then(function(response) {
              return response.json();
            }).then(function(response) {
              if (!response || !response.success || !response.data) {
                throw new Error(response && response.data && response.data.message ? response.data.message : 'Order could not be saved.');
              }

              if (typeof response.data.revision !== 'undefined') {
                orderRevisions[group.parentUuid] = parseInt(response.data.revision, 10) || 0;
              }

              setReorderStatus(response.data.message || 'Order saved.', false);
              updateReorderControls();
              return response;
            }).catch(function(error) {
              restoreGroupOrder(group, previousOrder);
              setReorderStatus(error && error.message ? error.message : 'Order could not be saved.', true);
              updateReorderControls();
              throw error;
            });
          }

          function moveRowWithinGroup(row, direction) {
            var group = siblingGroupForRow(row);
            var index = group ? rowIndexInGroup(row, group) : -1;

            if (!group || !group.parentUuid || isReorderDisabled() || isDraftRow(row) || index < 0) {
              return;
            }

            invalidateUndoMove();
            var previousOrder = groupOrder(group);
            var term = group.terms[index];

            if (direction === 'up' && index > 0) {
              group.container.insertBefore(term, group.terms[index - 1]);
            } else if (direction === 'down' && index < group.terms.length - 1) {
              group.container.insertBefore(term, group.terms[index + 1].nextSibling);
            } else {
              return;
            }

            saveOpenState();
            submitSiblingOrder(siblingGroupForRow(row), previousOrder).catch(function() {});
          }

          function invalidateUndoMove() {
            lastUndoMove = null;

            if (moveNotice) {
              moveNotice.hidden = true;
            }

            updateStatusRail();
          }

          function updateStatusRail() {
            if (!statusRailSeparator) {
              return;
            }

            statusRailSeparator.hidden = !(
              collapseAllButton &&
              !collapseAllButton.hidden &&
              moveNotice &&
              !moveNotice.hidden
            );
          }

          function clearReorderTargetClasses() {
            document
              .querySelectorAll('.is-reorder-source, .is-reorder-target-before, .is-reorder-target-after, .is-branch-drop-before, .is-branch-drop-child, .is-branch-drop-after')
              .forEach(function(row) {
                row.classList.remove('is-reorder-source', 'is-reorder-target-before', 'is-reorder-target-after', 'is-branch-drop-before', 'is-branch-drop-child', 'is-branch-drop-after');
              });
          }

          function rowFromPointer(event) {
            var element = document.elementFromPoint(event.clientX, event.clientY);

            return element ? element.closest('.cfm-core-terms-editor-row[data-term-uuid]') : null;
          }

          function descendantCountForTerm(term) {
            if (!term) {
              return 0;
            }

            return term.querySelectorAll('.cfm-core-terms-editor-row[data-term-uuid]').length - 1;
          }

          function ensureDragPreview() {
            var preview = document.querySelector('.cfm-core-terms-editor-drag-preview');

            if (!preview) {
              preview = document.createElement('div');
              preview.className = 'cfm-core-terms-editor-drag-preview';
              document.body.appendChild(preview);
            }

            return preview;
          }

          function updateDragPreview(event) {
            if (!branchDragState || !branchDragState.preview) {
              return;
            }

            branchDragState.preview.style.transform = 'translate(' + (event.clientX + 14) + 'px, ' + (event.clientY + 14) + 'px)';
          }

          function hideDragPreview() {
            var preview = branchDragState ? branchDragState.preview : document.querySelector('.cfm-core-terms-editor-drag-preview');

            if (preview) {
              preview.style.transform = 'translate(-9999px, -9999px)';
            }
          }

          function dropIntentForRow(row, event) {
            var box = row.getBoundingClientRect();
            var offset = event.clientY - box.top;
            var ratio = box.height > 0 ? offset / box.height : 0.5;

            if (ratio <= 0.2) {
              return 'before';
            }

            if (ratio >= 0.8) {
              return 'after';
            }

            return 'child';
          }

          function setBranchDropTarget(row, placement) {
            clearReorderTargetClasses();

            if (!row || !placement) {
              return;
            }

            row.classList.add('is-branch-drop-' + placement);
          }

          function scheduleBranchAutoExpand(row) {
            var term = row ? row.closest('.cfm-core-terms-editor-term') : null;
            var details = term ? term.querySelector(':scope > details') : null;

            if (!details || details.open || !rowHasChildren(row)) {
              cancelBranchAutoExpand();
              return;
            }

            if (
              branchAutoExpandTimer &&
              branchDragState &&
              branchDragState.autoExpandRow === row
            ) {
              return;
            }

            cancelBranchAutoExpand();

            if (branchDragState) {
              branchDragState.autoExpandRow = row;
            }

            branchAutoExpandTimer = window.setTimeout(function() {
              details.open = true;
              saveOpenState();
              updateCollapseAllControl();
              branchAutoExpandTimer = null;

              if (branchDragState) {
                branchDragState.autoExpandRow = null;
              }
            }, 750);
          }

          function cancelBranchAutoExpand() {
            if (branchAutoExpandTimer) {
              window.clearTimeout(branchAutoExpandTimer);
              branchAutoExpandTimer = null;
            }

            if (branchDragState) {
              branchDragState.autoExpandRow = null;
            }
          }

          function branchMoveParentUuid(row, placement) {
            if (!row) {
              return '';
            }

            if (placement === 'child') {
              return row.getAttribute('data-term-uuid') || '';
            }

            var group = siblingGroupForRow(row);

            return group ? group.parentUuid : '';
          }

          function branchMoveRequest(termUuid, targetUuid, placement, confirmations, revisions) {
            var body = new URLSearchParams();

            confirmations = confirmations || {};
            revisions = revisions || {};

            body.append('action', 'cfm_move_branch');
            body.append('cfm_nonce', moveBranchNonce);
            body.append('framework_id', frameworkId);
            body.append('term_uuid', termUuid);
            body.append('target_uuid', targetUuid);
            body.append('placement', placement);

            if (confirmations.assignments) {
              body.append('cfm_confirm_assignments', '1');
            }

            if (confirmations.axis) {
              body.append('cfm_confirm_axis_change', '1');
            }

            if (typeof revisions.source !== 'undefined') {
              body.append('source_order_revision', String(revisions.source));
            }

            if (typeof revisions.target !== 'undefined') {
              body.append('target_order_revision', String(revisions.target));
            }

            return window.fetch(ajaxurl, {
              method: 'POST',
              credentials: 'same-origin',
              body: body
            }).then(function(response) {
              return response.json();
            });
          }

          function updateReturnedRevisions(revisions) {
            if (!revisions || typeof revisions !== 'object') {
              return;
            }

            Object.keys(revisions).forEach(function(parentUuid) {
              orderRevisions[parentUuid] = parseInt(revisions[parentUuid], 10) || 0;
            });
          }

          function applyMovedBranchToDom(sourceTerm, targetRow, placement) {
            var targetTerm = targetRow ? targetRow.closest('.cfm-core-terms-editor-term') : null;

            if (!sourceTerm || !targetTerm || sourceTerm === targetTerm) {
              return false;
            }

            if (placement === 'before') {
              targetTerm.insertAdjacentElement('beforebegin', sourceTerm);
            } else if (placement === 'after') {
              targetTerm.insertAdjacentElement('afterend', sourceTerm);
            } else {
              var details = ensureTermCanHaveChildren(targetTerm);
              var children = details ? details.querySelector(':scope > .cfm-core-terms-editor-children') : null;

              if (!children) {
                return false;
              }

              children.appendChild(sourceTerm);
            }

            refreshMovedBranchMetadata(sourceTerm, targetRow, placement);
            saveOpenState();
            updateCollapseAllControl();
            updateReorderControls();
            return true;
          }

          function refreshMovedBranchMetadata(sourceTerm, targetRow, placement) {
            var rootRow = directRow(sourceTerm);
            var targetTerm = targetRow ? targetRow.closest('.cfm-core-terms-editor-term') : null;
            var parentRow = null;
            var axisUuid = targetRow ? (targetRow.getAttribute('data-axis-uuid') || '') : '';
            var depth = 0;

            if (placement === 'child') {
              parentRow = targetRow;
              depth = depthForTerm(targetTerm) + 1;
            } else {
              var parentTerm = sourceTerm.parentElement ? sourceTerm.parentElement.closest('.cfm-core-terms-editor-term') : null;
              parentRow = parentTerm ? directRow(parentTerm) : null;
              depth = depthForTerm(targetTerm);
            }

            if (rootRow) {
              rootRow.setAttribute('data-parent-uuid', parentRow ? (parentRow.getAttribute('data-term-uuid') || '') : rootParentUuid);
            }

            if (!axisUuid && parentRow) {
              axisUuid = parentRow.getAttribute('data-axis-uuid') || '';
            }

            if (axisUuid) {
              sourceTerm
                .querySelectorAll('.cfm-core-terms-editor-row[data-term-uuid]')
                .forEach(function(row) {
                  row.setAttribute('data-axis-uuid', axisUuid);
                });
            }

            setTermDepth(sourceTerm, depth);
          }

          function setTermDepth(term, depth) {
            if (!term) {
              return;
            }

            term.className = String(term.className || '').replace(/\bcfm-core-terms-editor-depth-\d+\b/g, '').trim();
            term.classList.add('cfm-core-terms-editor-depth-' + Math.max(0, depth));

            Array.from(term.children).forEach(function(child) {
              if (child.classList && child.classList.contains('cfm-core-terms-editor-term')) {
                setTermDepth(child, depth + 1);
              }
            });

            term
              .querySelectorAll(':scope > details > .cfm-core-terms-editor-children > .cfm-core-terms-editor-term')
              .forEach(function(childTerm) {
                setTermDepth(childTerm, depth + 1);
              });
          }

          function showUndoMoveNotice(undoPayload) {
            lastUndoMove = undoPayload || null;

            if (moveNotice) {
              moveNotice.hidden = !lastUndoMove;
            }

            updateStatusRail();
          }

          function undoLastMove() {
            var undo = lastUndoMove;
            var targetUuid = '';
            var placement = '';

            if (!undo || !undo.moved_term_uuid || !undo.original_parent_uuid || !undo.original_placement) {
              return;
            }

            if (undo.original_placement.insert_before_uuid) {
              targetUuid = undo.original_placement.insert_before_uuid;
              placement = 'before';
            } else if (undo.original_placement.insert_after_uuid) {
              targetUuid = undo.original_placement.insert_after_uuid;
              placement = 'after';
            } else {
              targetUuid = undo.original_parent_uuid;
              placement = 'child';
            }

            if (!targetUuid || !placement) {
              return;
            }

            setReorderStatus('Undoing move...', false);

            branchMoveRequest(undo.moved_term_uuid, targetUuid, placement, {
              assignments: true,
              axis: true
            }, {}).then(function(response) {
              var movedRow = rowByUuid(undo.moved_term_uuid);
              var targetRow = rowByUuid(targetUuid);
              var movedTerm = movedRow ? movedRow.closest('.cfm-core-terms-editor-term') : null;

              if (!response || !response.success || !response.data) {
                throw new Error(response && response.data && response.data.message ? response.data.message : 'Move could not be undone.');
              }

              updateReturnedRevisions(response.data.revisions);

              if (movedTerm && targetRow) {
                applyMovedBranchToDom(movedTerm, targetRow, placement);
              } else {
                window.location.reload();
                return;
              }

              lastUndoMove = null;

              if (moveNotice) {
                moveNotice.hidden = true;
              }

              updateStatusRail();
              setReorderStatus('Move undone.', false);
            }).catch(function(error) {
              setReorderStatus(error && error.message ? error.message : 'Move could not be undone.', true);
            });
          }

          function confirmMoveWarning(responseData, confirmations) {
            var code = responseData && responseData.code ? responseData.code : '';

            if (code === 'assignment_warning') {
              if (!window.confirm((responseData.message || 'This branch has active assignments.') + '\n\nMove anyway?')) {
                return false;
              }

              confirmations.assignments = true;
              return true;
            }

            if (code === 'axis_warning') {
              if (!window.confirm((responseData.message || 'This move changes the branch context.') + '\n\nMove anyway?')) {
                return false;
              }

              confirmations.axis = true;
              return true;
            }

            return false;
          }

          function persistBranchMove(sourceRow, targetRow, placement, sourceTerm) {
            var termUuid = sourceRow ? (sourceRow.getAttribute('data-term-uuid') || '') : '';
            var targetUuid = targetRow ? (targetRow.getAttribute('data-term-uuid') || '') : '';
            var sourceParentUuid = branchMoveParentUuid(sourceRow, 'after');
            var targetParentUuid = branchMoveParentUuid(targetRow, placement);
            var confirmations = {};
            var revisions = {};

            if (sourceParentUuid) {
              revisions.source = orderRevisions[sourceParentUuid] || 0;
            }

            if (targetParentUuid) {
              revisions.target = orderRevisions[targetParentUuid] || 0;
            }

            function attemptMove() {
              return branchMoveRequest(termUuid, targetUuid, placement, confirmations, revisions).then(function(response) {
                if (response && response.success && response.data) {
                  updateReturnedRevisions(response.data.revisions);

                  if (!applyMovedBranchToDom(sourceTerm, targetRow, placement)) {
                    window.location.reload();
                    return response;
                  }

                  showUndoMoveNotice(response.data.undo_move || null);
                  setReorderStatus('', false);
                  return response;
                }

                if (response && response.data && confirmMoveWarning(response.data, confirmations)) {
                  return attemptMove();
                }

                throw new Error(response && response.data && response.data.message ? response.data.message : 'Branch could not be moved.');
              });
            }

            invalidateUndoMove();
            setReorderStatus('Moving branch...', false);
            return attemptMove().catch(function(error) {
              setReorderStatus(error && error.message ? error.message : 'Branch could not be moved.', true);
              throw error;
            });
          }

          function updateDragTarget(event) {
            var row = rowFromPointer(event);

            clearReorderTargetClasses();

            if (!branchDragState || !row || row === branchDragState.row || isDraftRow(row)) {
              if (branchDragState) {
                branchDragState.targetRow = null;
                branchDragState.placement = '';
              }
              cancelBranchAutoExpand();
              return;
            }

            var targetTerm = row.closest('.cfm-core-terms-editor-term');

            if (!targetTerm || branchDragState.term.contains(targetTerm)) {
              branchDragState.targetRow = null;
              branchDragState.placement = '';
              cancelBranchAutoExpand();
              return;
            }

            var placement = dropIntentForRow(row, event);

            branchDragState.targetRow = row;
            branchDragState.placement = placement;
            setBranchDropTarget(row, placement);

            if (placement === 'child') {
              scheduleBranchAutoExpand(row);
            } else {
              cancelBranchAutoExpand();
            }
          }

          function finishDrag(event, cancelled) {
            if (!branchDragState) {
              return;
            }

            var state = branchDragState;
            branchDragState = null;
            document.removeEventListener('pointermove', handleDragMove);
            document.removeEventListener('pointerup', handleDragEnd);
            document.removeEventListener('pointercancel', handleDragCancel);
            clearReorderTargetClasses();
            cancelBranchAutoExpand();

            if (state.preview) {
              state.preview.style.transform = 'translate(-9999px, -9999px)';
            }

            if (state.handle && state.pointerId && state.handle.releasePointerCapture) {
              try {
                state.handle.releasePointerCapture(state.pointerId);
              } catch (error) {}
            }

            if (cancelled || !state.targetRow || !state.placement) {
              return;
            }

            var targetTerm = state.targetRow.closest('.cfm-core-terms-editor-term');

            if (!targetTerm || targetTerm === state.term || state.term.contains(targetTerm)) {
              return;
            }

            saveOpenState();
            persistBranchMove(state.row, state.targetRow, state.placement, state.term).catch(function() {});
          }

          function handleDragMove(event) {
            if (!branchDragState) {
              return;
            }

            event.preventDefault();
            updateDragPreview(event);
            updateDragTarget(event);
          }

          function handleDragEnd(event) {
            finishDrag(event, false);
          }

          function handleDragCancel(event) {
            finishDrag(event, true);
          }

          function startRowDrag(row, handle, event) {
            var group = siblingGroupForRow(row);

            if (isEditInteractionLocked()) {
              return;
            }

            if (!group || !group.parentUuid || isReorderDisabled() || isDraftRow(row)) {
              setReorderStatus('Save or reset changes before reordering.', true);
              return;
            }

            event.preventDefault();
            closeActionMenus();
            invalidateUndoMove();

            var term = row.closest('.cfm-core-terms-editor-term');
            var label = inputValue(row, '.cfm-core-terms-editor-input-label') || 'Core Term branch';
            var descendants = descendantCountForTerm(term);
            var preview = ensureDragPreview();

            preview.innerHTML = '<strong>Moving:</strong>' + label + (descendants > 0 ? '<br>(+' + descendants + ' descendants)' : '');

            branchDragState = {
              row: row,
              term: term,
              group: group,
              handle: handle,
              pointerId: event.pointerId,
              targetRow: null,
              placement: '',
              autoExpandRow: null,
              preview: preview
            };

            row.classList.add('is-reorder-source');
            updateDragPreview(event);

            if (handle && handle.setPointerCapture && event.pointerId) {
              try {
                handle.setPointerCapture(event.pointerId);
              } catch (error) {}
            }

            document.addEventListener('pointermove', handleDragMove);
            document.addEventListener('pointerup', handleDragEnd);
            document.addEventListener('pointercancel', handleDragCancel);
          }

          function clearRowError(row) {
            row.classList.remove('has-error');
            row.removeAttribute('data-error');

            var status = row.querySelector('.cfm-core-terms-editor-status');

            if (status) {
              status.textContent = row.classList.contains('is-dirty') ? 'Unsaved' : '';
            }
          }

          function setRowError(row, message) {
            row.classList.add('has-error');
            row.setAttribute('data-error', message);

            var status = row.querySelector('.cfm-core-terms-editor-status');

            if (status) {
              status.textContent = message;
            }
          }

          function directRow(term) {
            return term.querySelector(':scope > details > summary > .cfm-core-terms-editor-row, :scope > .cfm-core-terms-editor-row');
          }

          function collectOpenState() {
            var openUuids = [];

            document
              .querySelectorAll('.cfm-core-terms-editor-term > details[open]')
              .forEach(function(details) {
                var row = details.querySelector(':scope > summary > .cfm-core-terms-editor-row[data-term-uuid]');
                var uuid = row ? row.getAttribute('data-term-uuid') : '';

                if (uuid) {
                  openUuids.push(uuid);
                }
              });

            return openUuids;
          }

          function saveOpenState() {
            writeStoredJson('open', collectOpenState());
          }

          function hasOpenBranches() {
            return Boolean(document.querySelector('.cfm-core-terms-editor-term > details[open]'));
          }

          function updateCollapseAllControl() {
            if (!collapseAllButton) {
              return;
            }

            collapseAllButton.hidden = !hasOpenBranches();
            updateStatusRail();
          }

          function collapseAllBranches() {
            document
              .querySelectorAll('.cfm-core-terms-editor-term > details[open]')
              .forEach(function(details) {
                details.open = false;
              });

            saveOpenState();
            updateCollapseAllControl();
          }

          function restoreOpenState() {
            var openUuids = readStoredJson('open', []);

            if (!Array.isArray(openUuids)) {
              updateCollapseAllControl();
              return;
            }

            openUuids.forEach(function(uuid) {
              var row = rowByUuid(uuid);
              var details = row ? row.closest('details') : null;

              if (details) {
                details.open = true;
              }
            });

            updateCollapseAllControl();
          }

          function currentDrafts() {
            var drafts = {};

            dirtyRows.forEach(function(row) {
              var values = rowValues(row);

              if (values.uuid) {
                drafts[values.uuid] = values;
              }
            });

            return drafts;
          }

          function saveDraftState() {
            var drafts = currentDrafts();

            if (Object.keys(drafts).length) {
              writeStoredJson('drafts', drafts);
            } else {
              removeStored('drafts');
            }
          }

          function applyRowValues(row, values) {
            setInputValue(row, '.cfm-core-terms-editor-input-label', values.label || '');
            setInputValue(row, '.cfm-core-terms-editor-input-slug', normalizeSlug(values.slug || ''));
            setInputValue(row, '.cfm-core-terms-editor-input-short-label', normalizeShortLabel(values.short_label || ''));
            setInputValue(row, '.cfm-core-terms-editor-input-community', values.description || '');
            syncRowDisplay(row);
          }

          function depthForTerm(term) {
            var match = term ? String(term.className || '').match(/cfm-core-terms-editor-depth-(\d+)/) : null;
            return match ? parseInt(match[1], 10) : 0;
          }

          function createDraftTerm(depth, options) {
            var term = document.createElement('div');
            var row = document.createElement('div');
            var uuid = 'draft-' + Date.now() + '-' + (++draftCounter);

            term.className = 'cfm-core-terms-editor-term cfm-core-terms-editor-depth-' + depth + ' cfm-core-terms-editor-draft-term';
            row.className = 'cfm-core-terms-editor-row is-draft';
            row.setAttribute('data-term-uuid', uuid);
            row.setAttribute('data-draft-row', '1');
            row.setAttribute('data-draft-mode', options.mode || '');
            row.setAttribute('data-draft-parent-uuid', options.parentUuid || '');
            row.setAttribute('data-draft-insert-after-uuid', options.insertAfterUuid || '');
            row.setAttribute('data-draft-insert-before-uuid', options.insertBeforeUuid || '');
            row.setAttribute('data-original-label', '');
            row.setAttribute('data-original-slug', '');
            row.setAttribute('data-original-short-label', '');
            row.setAttribute('data-original-community', '');

            row.innerHTML =
              '<span class="cfm-core-terms-editor-rail">' +
                '<span class="cfm-core-terms-editor-caret-slot" aria-hidden="true"></span>' +
                '<span class="cfm-core-terms-editor-handle" role="button" tabindex="0" aria-label="Drag to reorder sibling">::</span>' +
              '</span>' +
              '<span class="cfm-core-terms-editor-field cfm-core-terms-editor-field-label">' +
                '<span class="cfm-core-terms-editor-display cfm-core-terms-editor-label-display"></span>' +
                '<input class="cfm-core-terms-editor-edit cfm-core-terms-editor-input cfm-core-terms-editor-input-label" type="text" value="" aria-label="Label">' +
              '</span>' +
              '<span class="cfm-core-terms-editor-field cfm-core-terms-editor-meta">' +
                '<span class="cfm-core-terms-editor-display cfm-core-terms-editor-meta-display">' +
                  '<span class="cfm-core-terms-editor-meta-part cfm-core-terms-editor-meta-slug"></span>' +
                  '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>' +
                  '<span class="cfm-core-terms-editor-meta-part cfm-core-terms-editor-meta-short-label"></span>' +
                  '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>' +
                  '<span class="cfm-core-terms-editor-meta-part cfm-core-terms-editor-meta-community"></span>' +
                '</span>' +
                '<span class="cfm-core-terms-editor-edit cfm-core-terms-editor-meta-edit">' +
                  '<input class="cfm-core-terms-editor-input cfm-core-terms-editor-input-slug" type="text" value="" aria-label="Slug">' +
                  '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>' +
                  '<input class="cfm-core-terms-editor-input cfm-core-terms-editor-input-short-label" type="text" value="" aria-label="Short Label">' +
                  '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>' +
                  '<input class="cfm-core-terms-editor-input cfm-core-terms-editor-input-community" type="text" value="" aria-label="Community">' +
                '</span>' +
              '</span>' +
              '<span class="cfm-core-terms-editor-actions">' +
                '<button type="button" class="button button-small cfm-core-terms-editor-row-save" hidden>Save Row</button>' +
                '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-move-up" aria-label="Move up" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="move-up"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 5l-5 5"></path><path d="M10 5l5 5"></path><path d="M10 5v10"></path></svg></button>' +
                '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-move-down" aria-label="Move down" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="move-down"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 15l-5-5"></path><path d="M10 15l5-5"></path><path d="M10 5v10"></path></svg></button>' +
                '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-insert-sibling" aria-label="Insert sibling" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="sibling"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 6h7"></path><path d="M5 14h7"></path><path d="M15 10v6"></path><path d="M12 13h6"></path></svg></button>' +
                '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-add-child" aria-label="Add child" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="child"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 4v10h6"></path><path d="M8 14h6"></path><path d="M15 11v6"></path><path d="M12 14h6"></path></svg></button>' +
                '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-move-branch" aria-label="Move branch" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="move-branch"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 10h10"></path><path d="M11 6l4 4-4 4"></path><path d="M5 5v10"></path></svg></button>' +
                '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-archive-branch" aria-label="Archive branch" aria-haspopup="true" aria-expanded="false" data-cfm-action-menu-trigger="archive"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 6h12"></path><path d="M6 6v10h8V6"></path><path d="M8 4h4l1 2H7l1-2z"></path><path d="M8 10h4"></path></svg></button>' +
                '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-sibling-menu" role="menu" data-cfm-action-menu="sibling" hidden>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-insert-sibling-before" role="menuitem">Insert sibling before</button>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-insert-sibling-after" role="menuitem">Insert sibling after</button>' +
                '</span>' +
                '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-child-menu" role="menu" data-cfm-action-menu="child" hidden>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-add-child-leaf" role="menuitem">Add child</button>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-prepend-child" role="menuitem">Prepend child</button>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-append-child" role="menuitem">Append child</button>' +
                '</span>' +
                '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-move-up-menu" role="menu" data-cfm-action-menu="move-up" hidden>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-move-up-menu-action" role="menuitem">Move up</button>' +
                '</span>' +
                '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-move-down-menu" role="menu" data-cfm-action-menu="move-down" hidden>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-move-down-menu-action" role="menuitem">Move down</button>' +
                '</span>' +
                '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-move-branch-menu" role="menu" data-cfm-action-menu="move-branch" hidden>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-move-branch-menu-action" role="menuitem">Move branch</button>' +
                '</span>' +
                '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-archive-menu" role="menu" data-cfm-action-menu="archive" hidden>' +
                  '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-archive-menu-action" role="menuitem">Archive branch</button>' +
                '</span>' +
                '<button type="button" class="button button-small cfm-core-terms-editor-cancel-draft">Cancel</button>' +
                '<span class="cfm-core-terms-editor-status" aria-live="polite">Unsaved</span>' +
              '</span>';

            term.appendChild(row);
            return term;
          }

          function ensureTermCanHaveChildren(term) {
            var details = term ? term.querySelector(':scope > details') : null;

            if (details) {
              details.open = true;
              updateCollapseAllControl();
              return details;
            }

            var row = term ? term.querySelector(':scope > .cfm-core-terms-editor-row') : null;

            if (!row) {
              return null;
            }

            details = document.createElement('details');
            var summary = document.createElement('summary');
            var children = document.createElement('div');

            children.className = 'cfm-core-terms-editor-children';
            details.open = true;

            var caretSlot = row.querySelector('.cfm-core-terms-editor-caret-slot');

            if (caretSlot) {
              caretSlot.setAttribute('role', 'button');
              caretSlot.setAttribute('tabindex', '0');
              caretSlot.setAttribute('aria-label', 'Expand or collapse term');
              caretSlot.removeAttribute('aria-hidden');
              caretSlot.innerHTML = '<span class="cfm-core-terms-editor-caret"></span>';
            }

            summary.appendChild(row);
            details.appendChild(summary);
            details.appendChild(children);
            term.appendChild(details);
            term.classList.add('cfm-core-terms-editor-has-children');
            bindSummary(summary);
            bindCaret(caretSlot);
            updateCollapseAllControl();

            return details;
          }

          function rowHasChildren(row) {
            var term = row ? row.closest('.cfm-core-terms-editor-term') : null;
            var children = term ? term.querySelector(':scope > details > .cfm-core-terms-editor-children') : null;

            return Boolean(
              term &&
              (
                term.classList.contains('cfm-core-terms-editor-has-children') ||
                (children && children.querySelector(':scope > .cfm-core-terms-editor-term'))
              )
            );
          }

          function descendantUuidsForRow(row) {
            var term = row ? row.closest('.cfm-core-terms-editor-term') : null;
            var descendants = [];

            if (!term) {
              return descendants;
            }

            term
              .querySelectorAll(':scope .cfm-core-terms-editor-term .cfm-core-terms-editor-row[data-term-uuid]')
              .forEach(function(descendantRow) {
                if (descendantRow !== row) {
                  descendants.push(descendantRow.getAttribute('data-term-uuid') || '');
                }
              });

            return descendants.filter(Boolean);
          }

          function rowLabel(row) {
            var label = row ? row.querySelector('.cfm-core-terms-editor-label-display') : null;
            return label ? label.textContent.trim() : 'this branch';
          }

          function closeMoveModal() {
            if (!moveModal) {
              return;
            }

            moveModal.hidden = true;
            moveActiveRow = null;

            if (moveSelect) {
              moveSelect.value = '';
            }

            if (moveError) {
              moveError.hidden = true;
              moveError.textContent = '';
            }
          }

          function moveWarningsSatisfied() {
            var needsAssignments = moveAssignmentWarning && !moveAssignmentWarning.hidden;
            var needsAxis = moveAxisWarning && !moveAxisWarning.hidden;

            return (!needsAssignments || (moveAssignmentCheckbox && moveAssignmentCheckbox.checked)) &&
              (!needsAxis || (moveAxisCheckbox && moveAxisCheckbox.checked));
          }

          function updateMoveModalState() {
            if (!moveActiveRow || !moveSelect || !moveSubmit) {
              return;
            }

            var selected = moveSelect.options[moveSelect.selectedIndex] || null;
            var selectedUuid = selected ? selected.value : '';
            var rowAxis = moveActiveRow.getAttribute('data-axis-uuid') || '';
            var targetAxis = selected ? (selected.getAttribute('data-axis-uuid') || '') : '';
            var assignmentCount = parseInt(moveActiveRow.getAttribute('data-assignment-count') || '0', 10) || 0;
            var validTarget = Boolean(selectedUuid && !selected.disabled);
            var axisChange = Boolean(validTarget && rowAxis && targetAxis && rowAxis !== targetAxis);

            if (moveAssignmentWarning) {
              moveAssignmentWarning.hidden = assignmentCount <= 0;
            }

            if (moveAssignmentCount) {
              moveAssignmentCount.textContent = String(assignmentCount);
            }

            if (moveAxisWarning) {
              moveAxisWarning.hidden = !axisChange;
            }

            if (moveError) {
              moveError.hidden = true;
              moveError.textContent = '';
            }

            moveSubmit.disabled = !validTarget || !moveWarningsSatisfied();
          }

          function openMoveModal(row) {
            if (!row || isDraftRow(row) || !moveModal || !moveSelect) {
              return;
            }

            closeActionMenus();

            if (isReorderDisabled()) {
              if (moveError) {
                moveError.textContent = 'Save or reset changes before moving a branch.';
                moveError.hidden = false;
              }

              window.alert('Save or reset changes before moving a branch.');
              return;
            }

            var rowUuid = row.getAttribute('data-term-uuid') || '';
            var currentParentUuid = row.getAttribute('data-parent-uuid') || '';
            var descendants = new Set(descendantUuidsForRow(row));

            Array.prototype.forEach.call(moveSelect.options, function(option) {
              var optionUuid = option.value || '';
              var disabled = !optionUuid ||
                optionUuid === rowUuid ||
                optionUuid === currentParentUuid ||
                descendants.has(optionUuid);

              option.disabled = disabled;
            });

            moveActiveRow = row;
            moveSelect.value = '';

            if (moveSummary) {
              moveSummary.textContent = 'Move "' + rowLabel(row) + '" and all child terms under a different Core Term.';
            }

            if (moveAssignmentCheckbox) {
              moveAssignmentCheckbox.checked = false;
            }

            if (moveAxisCheckbox) {
              moveAxisCheckbox.checked = false;
            }

            moveModal.hidden = false;
            updateMoveModalState();
            moveSelect.focus();
          }

          function submitMoveBranch() {
            if (!moveActiveRow || !moveForm || !moveSelect || !moveSubmit || moveSubmit.disabled) {
              return;
            }

            var termInput = moveForm.querySelector('input[name="term_uuid"]');
            var parentInput = moveForm.querySelector('input[name="new_parent_uuid"]');
            var assignmentInput = moveForm.querySelector('input[name="cfm_confirm_assignments"]');
            var axisInput = moveForm.querySelector('input[name="cfm_confirm_axis_change"]');

            if (!termInput || !parentInput || !assignmentInput || !axisInput) {
              return;
            }

            termInput.value = moveActiveRow.getAttribute('data-term-uuid') || '';
            parentInput.value = moveSelect.value || '';
            assignmentInput.value = moveAssignmentCheckbox && moveAssignmentCheckbox.checked ? '1' : '';
            axisInput.value = moveAxisCheckbox && moveAxisCheckbox.checked ? '1' : '';
            formSubmitting = true;
            moveForm.submit();
          }

          function closeActionMenus(exceptState) {
            if (actionMenuCloseTimer) {
              window.clearTimeout(actionMenuCloseTimer);
              actionMenuCloseTimer = null;
            }

            var exceptMenu = exceptState ? exceptState.menu : null;
            var exceptTrigger = exceptState ? exceptState.trigger : null;

            document
              .querySelectorAll('.cfm-core-terms-editor-action-menu')
              .forEach(function(menu) {
                if (menu === exceptMenu) {
                  return;
                }

                menu.hidden = true;
              });

            document
              .querySelectorAll('.cfm-core-terms-editor-row-action[aria-expanded]')
              .forEach(function(button) {
                button.setAttribute('aria-expanded', button === exceptTrigger ? 'true' : 'false');
              });

            if (!exceptState) {
              activeActionState = null;
            }
          }

          function actionMenuParts(row, actionType) {
            var actions = row ? row.querySelector('.cfm-core-terms-editor-actions') : null;
            var trigger = actions ? actions.querySelector('[data-cfm-action-menu-trigger="' + actionType + '"]') : null;
            var menu = actions ? actions.querySelector('[data-cfm-action-menu="' + actionType + '"]') : null;

            return {
              actions: actions,
              trigger: trigger,
              menu: menu
            };
          }

          function positionActionMenu(actions, trigger, menu) {
            if (!actions || !trigger || !menu) {
              return;
            }

            var actionsBox = actions.getBoundingClientRect();
            var triggerBox = trigger.getBoundingClientRect();

            menu.style.left = Math.max(0, Math.round(triggerBox.left - actionsBox.left)) + 'px';
            menu.style.top = Math.round(triggerBox.bottom - actionsBox.top + 2) + 'px';
          }

          function openActionMenu(row, actionType, focusFirstItem) {
            var uuid = row ? (row.getAttribute('data-term-uuid') || '') : '';
            var parts = actionMenuParts(row, actionType);
            var actions = parts.actions;
            var trigger = parts.trigger;
            var menu = parts.menu;

            if (isEditInteractionLocked()) {
              closeActionMenus();
              return;
            }

            if (!uuid || !trigger || !menu) {
              return;
            }

            if (actionType === 'child') {
              configureChildActionMenu(row, menu);
            } else {
              configureSingleActionMenu(row, actionType, menu, trigger);
            }

            if (actionMenuCloseTimer) {
              window.clearTimeout(actionMenuCloseTimer);
              actionMenuCloseTimer = null;
            }

            if (
              activeActionState &&
              activeActionState.uuid === uuid &&
              activeActionState.actionType === actionType &&
              activeActionState.trigger === trigger &&
              activeActionState.menu === menu &&
              !menu.hidden
            ) {
              positionActionMenu(actions, trigger, menu);
            } else {
              activeActionState = {
                uuid: uuid,
                actionType: actionType,
                row: row,
                actions: actions,
                trigger: trigger,
                menu: menu
              };
              closeActionMenus(activeActionState);
              menu.hidden = false;
              trigger.setAttribute('aria-expanded', 'true');
            }

            positionActionMenu(actions, trigger, menu);

            if (focusFirstItem) {
              var firstItem = menu.querySelector('button');

              if (firstItem) {
                firstItem.focus();
              }
            }
          }

          function scheduleActionMenuClose() {
            if (actionMenuCloseTimer) {
              window.clearTimeout(actionMenuCloseTimer);
            }

            actionMenuCloseTimer = window.setTimeout(function() {
              closeActionMenus();
            }, 180);
          }

          function cancelActionMenuClose() {
            if (actionMenuCloseTimer) {
              window.clearTimeout(actionMenuCloseTimer);
              actionMenuCloseTimer = null;
            }
          }

          function isInsideActiveActionRegion(target) {
            return Boolean(
              activeActionState &&
              (
                activeActionState.row.contains(target) ||
                activeActionState.actions.contains(target) ||
                activeActionState.trigger.contains(target) ||
                activeActionState.menu.contains(target)
              )
            );
          }

          function actionTriggerType(trigger) {
            return trigger ? (trigger.getAttribute('data-cfm-action-menu-trigger') || '') : '';
          }

          function configureChildActionMenu(row, menu) {
            var hasChildren = rowHasChildren(row);
            var leafItem = menu.querySelector('.cfm-core-terms-editor-add-child-leaf');
            var prependItem = menu.querySelector('.cfm-core-terms-editor-prepend-child');
            var appendItem = menu.querySelector('.cfm-core-terms-editor-append-child');

            if (leafItem) {
              leafItem.hidden = hasChildren;
            }

            if (prependItem) {
              prependItem.hidden = !hasChildren;
            }

            if (appendItem) {
              appendItem.hidden = !hasChildren;
            }
          }

          function configureSingleActionMenu(row, actionType, menu, trigger) {
            var action = menu ? menu.querySelector('button') : null;

            if (!action) {
              return;
            }

            action.disabled = Boolean(trigger && trigger.disabled);
            action.hidden = false;
          }

          function handleActionHover(target) {
            if (isEditInteractionLocked()) {
              closeActionMenus();
              return;
            }

            var trigger = target.closest('[data-cfm-action-menu-trigger]');

            if (!trigger) {
              if (target.closest('.cfm-core-terms-editor-archive-branch')) {
                closeActionMenus();
              } else if (isInsideActiveActionRegion(target)) {
                cancelActionMenuClose();
              }

              return;
            }

            var row = trigger.closest('.cfm-core-terms-editor-row[data-term-uuid]');
            var actionType = actionTriggerType(trigger);

            if (!row || isDraftRow(row)) {
              return;
            }

            if (actionType) {
              openActionMenu(row, actionType, false);
            }
          }

          function addDraftSibling(baseRow, placement) {
            var baseTerm = baseRow ? baseRow.closest('.cfm-core-terms-editor-term') : null;

            if (!baseTerm) {
              return null;
            }

            invalidateUndoMove();
            var parentTerm = baseTerm.parentElement.closest('.cfm-core-terms-editor-term');
            var parentRow = parentTerm ? directRow(parentTerm) : null;
            placement = placement === 'before' ? 'before' : 'after';
            var draftTerm = createDraftTerm(depthForTerm(baseTerm), {
              mode: placement === 'before' ? 'insert_sibling_before' : 'insert_sibling_after',
              parentUuid: parentRow ? (parentRow.getAttribute('data-term-uuid') || '') : '',
              insertAfterUuid: placement === 'after' ? (baseRow.getAttribute('data-term-uuid') || '') : '',
              insertBeforeUuid: placement === 'before' ? (baseRow.getAttribute('data-term-uuid') || '') : ''
            });

            baseTerm.insertAdjacentElement(placement === 'before' ? 'beforebegin' : 'afterend', draftTerm);
            bindRow(directRow(draftTerm));
            if (!restoringDrafts) {
              selectRow(directRow(draftTerm));
              saveDraftState();
            }
            updateReorderControls();
            return directRow(draftTerm);
          }

          function addDraftChild(baseRow, placement) {
            var baseTerm = baseRow ? baseRow.closest('.cfm-core-terms-editor-term') : null;
            var details = ensureTermCanHaveChildren(baseTerm);
            var children = details ? details.querySelector(':scope > .cfm-core-terms-editor-children') : null;

            if (!children) {
              return null;
            }

            invalidateUndoMove();
            placement = placement === 'prepend' ? 'prepend' : 'append';
            var draftTerm = createDraftTerm(depthForTerm(baseTerm) + 1, {
              mode: placement === 'prepend' ? 'add_child_prepend' : 'add_child_append',
              parentUuid: baseRow.getAttribute('data-term-uuid') || '',
              insertAfterUuid: ''
            });

            if (placement === 'prepend') {
              children.insertBefore(draftTerm, children.firstElementChild);
            } else {
              children.appendChild(draftTerm);
            }

            bindRow(directRow(draftTerm));
            if (!restoringDrafts) {
              selectRow(directRow(draftTerm));
            }
            saveOpenState();
            if (!restoringDrafts) {
              saveDraftState();
            }
            updateReorderControls();
            return directRow(draftTerm);
          }

          function removeDraftRow(row) {
            var term = row ? row.closest('.cfm-core-terms-editor-term') : null;

            if (!term || !isDraftRow(row)) {
              return;
            }

            dirtyRows.delete(row);

            if (selectedRow === row) {
              selectedRow = null;
            }

            term.remove();
            saveDraftState();
            updateToolbar();
            updateReorderControls();
          }

          function archiveBranch(row) {
            var label = row ? inputValue(row, '.cfm-core-terms-editor-input-label') : '';

            if (!row || isDraftRow(row) || !archiveForm || !archiveTermInput) {
              return;
            }

            if (dirtyRows.size && !window.confirm('Archive this branch and discard unsaved editor changes?')) {
              return;
            }

            if (!window.confirm('Archive "' + (label || 'this Core Term') + '" and its child terms?\n\nThis removes the branch from the active Core Terms tree. Jobs, themes, or other plugins that rely on this term may stop showing it as an available option.\n\nYou can restore it immediately using Undo Archive.')) {
              return;
            }

            invalidateUndoMove();
            formSubmitting = true;
            archiveTermInput.value = row.getAttribute('data-term-uuid') || '';
            archiveForm.submit();
          }

          function reconcileSavedDrafts() {
            var drafts = readStoredJson('drafts', {});
            var pending = readStoredJson('pendingSave', null);
            var saved = new URLSearchParams(window.location.search).has('cfm_editor_saved');

            if (!pending) {
              return drafts && typeof drafts === 'object' ? drafts : {};
            }

            if (saved) {
              pendingScrollRestore = pending;

              if (pending.mode === 'all') {
                drafts = {};
              } else if (Array.isArray(pending.uuids)) {
                pending.uuids.forEach(function(uuid) {
                  delete drafts[uuid];
                });
              }
            }

            removeStored('pendingSave');

            if (drafts && typeof drafts === 'object' && Object.keys(drafts).length) {
              writeStoredJson('drafts', drafts);
            } else {
              removeStored('drafts');
              drafts = {};
            }

            return drafts;
          }

          function restorePendingSavePosition() {
            var pending = pendingScrollRestore;
            pendingScrollRestore = null;

            if (!pending) {
              return;
            }

            var targetRow = null;

            if (Array.isArray(pending.uuids)) {
              pending.uuids.some(function(uuid) {
                targetRow = rowByUuid(uuid);
                return Boolean(targetRow);
              });
            }

            if (targetRow && targetRow.scrollIntoView) {
              targetRow.scrollIntoView({ block: 'center', inline: 'nearest' });
              return;
            }

            if (typeof pending.scrollY === 'number') {
              window.scrollTo(0, Math.max(0, pending.scrollY));
            }
          }

          function restoreDraftState() {
            var drafts = reconcileSavedDrafts();

            if (!drafts || typeof drafts !== 'object') {
              return;
            }

            restoringDrafts = true;

            Object.keys(drafts).forEach(function(uuid) {
              var row = rowByUuid(uuid);

              if (!row && drafts[uuid] && drafts[uuid].is_new) {
                row = restoreDraftRow(drafts[uuid]);
              }

              if (!row || !drafts[uuid]) {
                return;
              }

              if (drafts[uuid].is_new && drafts[uuid].uuid) {
                row.setAttribute('data-term-uuid', drafts[uuid].uuid);
              }

              applyRowValues(row, drafts[uuid]);
              updateDirtyState(row);
            });

            restoringDrafts = false;
            updateToolbar();
          }

          function restoreDraftRow(values) {
            var mode = values.insert_mode || '';
            var baseRow = null;

            if (mode === 'insert_sibling' || mode === 'insert_sibling_after') {
              baseRow = rowByUuid(values.insert_after_uuid || '');

              if (!baseRow) {
                return null;
              }

              return addDraftSibling(baseRow, 'after');
            } else if (mode === 'insert_sibling_before') {
              baseRow = rowByUuid(values.insert_before_uuid || '');

              if (!baseRow) {
                return null;
              }

              return addDraftSibling(baseRow, 'before');
            } else if (mode === 'add_child' || mode === 'add_child_append') {
              baseRow = rowByUuid(values.parent_uuid || '');

              if (!baseRow) {
                return null;
              }

              return addDraftChild(baseRow, 'append');
            } else if (mode === 'add_child_prepend') {
              baseRow = rowByUuid(values.parent_uuid || '');

              if (!baseRow) {
                return null;
              }

              return addDraftChild(baseRow, 'prepend');
            } else {
              return null;
            }
          }

          function selectRow(row) {
            if (!row || selectedRow === row) {
              return;
            }

            if (selectedRow) {
              selectedRow.classList.remove('is-selected');
              updateRowSaveVisibility(selectedRow);
            }

            row.classList.add('is-selected');
            selectedRow = row;
            updateRowSaveVisibility(row);

            var firstInput = row.querySelector('.cfm-core-terms-editor-input-label');

            if (firstInput) {
              window.setTimeout(function() {
                firstInput.focus();
                firstInput.select();
              }, 0);
            }
          }

          function clearSelectedRow() {
            if (!selectedRow) {
              return;
            }

            selectedRow.classList.remove('is-selected');
            updateRowSaveVisibility(selectedRow);
            selectedRow = null;
          }

          function toggleTerm(term) {
            var details = term.querySelector(':scope > details');

            if (!details) {
              return;
            }

            details.open = !details.open;
            saveOpenState();
            updateCollapseAllControl();
          }

          function isCaretClick(target) {
            return Boolean(target.closest('.cfm-core-terms-editor-caret-slot'));
          }

          function validateRows(rows) {
            var valid = true;
            var siblingSlugs = {};
            var rowsToValidate = rows.filter(function(row) {
              return !(isDraftRow(row) && isEmptyDraftRow(row));
            });

            document
              .querySelectorAll('.cfm-core-terms-editor-row[data-term-uuid]')
              .forEach(function(row) {
                clearRowError(row);
                var term = row.closest('.cfm-core-terms-editor-term');
                var parent = term ? term.parentElement.closest('.cfm-core-terms-editor-term') : null;
                var parentRow = parent ? directRow(parent) : null;
                var parentKey = parentRow ? (parentRow.getAttribute('data-term-uuid') || 'root') : 'root';
                var values = rowValues(row);

                if (!siblingSlugs[parentKey]) {
                  siblingSlugs[parentKey] = {};
                }

                if (values.slug) {
                  if (siblingSlugs[parentKey][values.slug] && siblingSlugs[parentKey][values.slug] !== values.uuid) {
                    setRowError(row, 'Duplicate slug');
                    valid = false;
                  } else {
                    siblingSlugs[parentKey][values.slug] = values.uuid;
                  }
                }
              });

            rowsToValidate.forEach(function(row) {
              var values = rowValues(row);

              if (!values.label) {
                setRowError(row, 'Label required');
                valid = false;
              } else if (!values.slug) {
                setRowError(row, 'Slug required');
                valid = false;
              } else if (!values.short_label) {
                setRowError(row, 'Short Label required');
                valid = false;
              } else if (!values.description) {
                setRowError(row, 'Community required');
                valid = false;
              }
            });

            return valid;
          }

          function resetChanges() {
            document
              .querySelectorAll('.cfm-core-terms-editor-row[data-term-uuid]')
              .forEach(function(row) {
                if (isDraftRow(row)) {
                  removeDraftRow(row);
                  return;
                }

                var original = originalValues(row);

                setInputValue(row, '.cfm-core-terms-editor-input-label', original.label);
                setInputValue(row, '.cfm-core-terms-editor-input-slug', original.slug);
                setInputValue(row, '.cfm-core-terms-editor-input-short-label', original.short_label);
                setInputValue(row, '.cfm-core-terms-editor-input-community', original.description);
                syncRowDisplay(row);
                clearRowError(row);
                row.classList.remove('is-dirty');
                updateRowSaveVisibility(row);
                dirtyRows.delete(row);
              });

            removeStored('drafts');
            removeStored('pendingSave');
            updateToolbar();
          }

          function prepareSubmit(rows, mode) {
            rows = rows.filter(function(row) {
              return !(isDraftRow(row) && isEmptyDraftRow(row));
            });

            if (!rows.length) {
              return false;
            }

            if (!validateRows(rows)) {
              return false;
            }

            changesInput.value = JSON.stringify(rows.map(rowValues));
            saveOpenState();
            saveDraftState();
            writeStoredJson('pendingSave', {
              mode: mode,
              scrollY: window.scrollY || window.pageYOffset || 0,
              uuids: rows.map(function(row) {
                return row.getAttribute('data-term-uuid') || '';
              }).filter(Boolean)
            });
            formSubmitting = true;
            return true;
          }

          function bindForm() {
            if (!form) {
              return;
            }

            form.addEventListener('input', function(event) {
              var row = event.target.closest('.cfm-core-terms-editor-row[data-term-uuid]');

              if (!row) {
                return;
              }

              invalidateUndoMove();

              if (event.target.matches('.cfm-core-terms-editor-input-slug')) {
                event.target.value = normalizeSlug(event.target.value);
              }

              if (isDraftRow(row) && !autofillingDraft) {
                if (event.target.matches('.cfm-core-terms-editor-input-slug')) {
                  row.setAttribute('data-draft-slug-manual', '1');
                } else if (event.target.matches('.cfm-core-terms-editor-input-short-label')) {
                  row.setAttribute('data-draft-short-label-manual', '1');
                } else if (event.target.matches('.cfm-core-terms-editor-input-community')) {
                  row.setAttribute('data-draft-community-manual', '1');
                } else if (event.target.matches('.cfm-core-terms-editor-input-label')) {
                  autofillDraftRow(row);
                }
              }

              syncRowDisplay(row);
              clearRowError(row);
              updateDirtyState(row);
            });

            form.addEventListener('focusin', function(event) {
              if (!isInlineEditField(event.target)) {
                return;
              }

              updateEditInteractionLock();
            });

            form.addEventListener('focusout', function(event) {
              if (!isInlineEditField(event.target)) {
                return;
              }

              window.setTimeout(updateEditInteractionLock, 0);
            });

            form.addEventListener('keydown', function(event) {
              if (!isInlineEditField(event.target)) {
                return;
              }

              if (event.key === ' ' || event.key === 'Spacebar' || event.code === 'Space') {
                event.stopImmediatePropagation();
              }
            });

            form.addEventListener('submit', function(event) {
              var rows = rowSaveRequested ? [rowSaveRequested] : Array.from(dirtyRows);
              var mode = rowSaveRequested ? 'row' : 'all';
              rowSaveRequested = null;

              if (!prepareSubmit(rows, mode)) {
                event.preventDefault();
                return;
              }
            });

            if (resetButton) {
              resetButton.addEventListener('click', function() {
                resetChanges();
              });
            }

            if (collapseAllButton) {
              collapseAllButton.addEventListener('click', function(event) {
                event.preventDefault();
                collapseAllBranches();
              });
            }

            if (undoMoveButton) {
              undoMoveButton.addEventListener('click', function(event) {
                event.preventDefault();
                undoLastMove();
              });
            }

            form.addEventListener('click', function(event) {
              var row = event.target.closest('.cfm-core-terms-editor-row[data-term-uuid]');

              if (!row) {
                return;
              }

              if (event.target.closest('.cfm-core-terms-editor-row-save')) {
                event.preventDefault();
                event.stopPropagation();

                if (!row.classList.contains('is-dirty')) {
                  return;
                }

                rowSaveRequested = row;
                submitForm();
              } else if (event.target.closest('.cfm-core-terms-editor-move-up-menu-action')) {
                event.preventDefault();
                event.stopPropagation();

                if (event.target.closest('.cfm-core-terms-editor-move-up-menu-action').disabled) {
                  return;
                }

                closeActionMenus();
                moveRowWithinGroup(row, 'up');
              } else if (event.target.closest('.cfm-core-terms-editor-move-down-menu-action')) {
                event.preventDefault();
                event.stopPropagation();

                if (event.target.closest('.cfm-core-terms-editor-move-down-menu-action').disabled) {
                  return;
                }

                closeActionMenus();
                moveRowWithinGroup(row, 'down');
              } else if (event.target.closest('.cfm-core-terms-editor-move-branch-menu-action')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                invalidateUndoMove();
                openMoveModal(row);
              } else if (event.target.closest('.cfm-core-terms-editor-move-up')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                moveRowWithinGroup(row, 'up');
              } else if (event.target.closest('.cfm-core-terms-editor-move-down')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                moveRowWithinGroup(row, 'down');
              } else if (event.target.closest('.cfm-core-terms-editor-insert-sibling-before')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                addDraftSibling(row, 'before');
              } else if (event.target.closest('.cfm-core-terms-editor-insert-sibling-after')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                addDraftSibling(row, 'after');
              } else if (event.target.closest('.cfm-core-terms-editor-prepend-child')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                addDraftChild(row, 'prepend');
              } else if (event.target.closest('.cfm-core-terms-editor-append-child')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                addDraftChild(row, 'append');
              } else if (event.target.closest('.cfm-core-terms-editor-add-child-leaf')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                addDraftChild(row, 'append');
              } else if (event.target.closest('.cfm-core-terms-editor-insert-sibling')) {
                event.preventDefault();
                event.stopPropagation();
                openActionMenu(row, 'sibling', true);
              } else if (event.target.closest('.cfm-core-terms-editor-add-child')) {
                event.preventDefault();
                event.stopPropagation();
                openActionMenu(row, 'child', true);
              } else if (event.target.closest('.cfm-core-terms-editor-move-branch')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                invalidateUndoMove();
                openMoveModal(row);
              } else if (event.target.closest('.cfm-core-terms-editor-archive-menu-action')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                archiveBranch(row);
              } else if (event.target.closest('.cfm-core-terms-editor-archive-branch')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                archiveBranch(row);
              } else if (event.target.closest('.cfm-core-terms-editor-cancel-draft')) {
                event.preventDefault();
                event.stopPropagation();
                closeActionMenus();
                removeDraftRow(row);
              }
            });

            form.addEventListener('pointerover', function(event) {
              if (isEditInteractionLocked()) {
                closeActionMenus();
                return;
              }

              handleActionHover(event.target);
            });

            form.addEventListener('pointerout', function(event) {
              if (!activeActionState || !isInsideActiveActionRegion(event.target)) {
                return;
              }

              if (event.relatedTarget && isInsideActiveActionRegion(event.relatedTarget)) {
                cancelActionMenuClose();
                return;
              }

              scheduleActionMenuClose();
            });

            document.addEventListener('click', function(event) {
              if (!event.target.closest('.cfm-core-terms-editor-actions')) {
                closeActionMenus();
              }
            });

            document.addEventListener('keydown', function(event) {
              if (event.key === 'Escape') {
                closeActionMenus();
                closeMoveModal();
              }
            });

            if (moveSelect) {
              moveSelect.addEventListener('change', updateMoveModalState);
            }

            if (moveAssignmentCheckbox) {
              moveAssignmentCheckbox.addEventListener('change', updateMoveModalState);
            }

            if (moveAxisCheckbox) {
              moveAxisCheckbox.addEventListener('change', updateMoveModalState);
            }

            if (moveSubmit) {
              moveSubmit.addEventListener('click', function(event) {
                event.preventDefault();
                submitMoveBranch();
              });
            }

            if (moveModal) {
              moveModal.addEventListener('click', function(event) {
                if (event.target.closest('[data-cfm-move-cancel]')) {
                  event.preventDefault();
                  closeMoveModal();
                }
              });
            }
          }

          function autofillDraftRow(row) {
            var label = inputValue(row, '.cfm-core-terms-editor-input-label');

            autofillingDraft = true;

            if (row.getAttribute('data-draft-slug-manual') !== '1') {
              setInputValue(row, '.cfm-core-terms-editor-input-slug', normalizeSlug(label));
            }

            if (row.getAttribute('data-draft-short-label-manual') !== '1') {
              setInputValue(row, '.cfm-core-terms-editor-input-short-label', normalizeShortLabel(label));
            }

            if (row.getAttribute('data-draft-community-manual') !== '1') {
              setInputValue(row, '.cfm-core-terms-editor-input-community', defaultCommunityForLabel(label));
            }

            autofillingDraft = false;
          }

          function submitForm() {
            if (form.requestSubmit) {
              form.requestSubmit(saveButton);
              return;
            }

            var submitEvent = new Event('submit', { bubbles: true, cancelable: true });

            if (form.dispatchEvent(submitEvent)) {
              form.submit();
            }
          }

          function handleEditorKeydown(event) {
            if (event.target.closest('.cfm-core-terms-editor-input')) {
              return;
            }

            if (event.key !== 'Enter' && event.key !== ' ') {
              return;
            }

            var caret = event.target.closest('.cfm-core-terms-editor-caret-slot');

            if (caret) {
              var term = caret.closest('.cfm-core-terms-editor-term');

              if (!term) {
                return;
              }

              event.preventDefault();
              toggleTerm(term);
              return;
            }

            var summary = event.target.closest('.cfm-core-terms-editor-term summary');

            if (summary) {
              var row = summary.querySelector('.cfm-core-terms-editor-row');

              event.preventDefault();
              selectRow(row);
            }
          }

          function bindSummary(summary) {
            if (!summary || summary.getAttribute('data-cfm-bound') === '1') {
              return;
            }

            summary.setAttribute('data-cfm-bound', '1');
            summary.addEventListener('click', function(event) {
              if (summaryHasActiveInlineEdit(summary)) {
                event.preventDefault();
                event.stopImmediatePropagation();
                updateEditInteractionLock();
                return;
              }

              if (event.target.closest('.cfm-core-terms-editor-input')) {
                return;
              }

              event.preventDefault();
            });
          }

          function bindRow(row) {
            if (!row || row.getAttribute('data-cfm-bound') === '1') {
              return;
            }

            row.setAttribute('data-cfm-bound', '1');
            row.addEventListener('click', function(event) {
              if (isCaretClick(event.target)) {
                return;
              }

              if (event.target.closest('.cfm-core-terms-editor-actions')) {
                return;
              }

              if (event.target.closest('.cfm-core-terms-editor-handle')) {
                return;
              }

              if (event.target.closest('.cfm-core-terms-editor-input')) {
                selectRow(row);
                return;
              }

              event.preventDefault();
              selectRow(row);
            });

            row.addEventListener('pointerdown', function(event) {
              var handle = event.target.closest('.cfm-core-terms-editor-handle');

              if (!handle) {
                return;
              }

              startRowDrag(row, handle, event);
            });
          }

          function bindCaret(caret) {
            if (!caret || caret.getAttribute('data-cfm-bound') === '1') {
              return;
            }

            caret.setAttribute('data-cfm-bound', '1');
            caret.addEventListener('click', function(event) {
              var term = caret.closest('.cfm-core-terms-editor-term');

              event.preventDefault();
              event.stopPropagation();
              toggleTerm(term);
            });
          }

          function bindInteractions() {
            document
              .querySelectorAll('.cfm-core-terms-editor-term summary')
              .forEach(bindSummary);

            document
              .querySelectorAll('.cfm-core-terms-editor-row')
              .forEach(bindRow);

            document
              .querySelectorAll('.cfm-core-terms-editor-caret-slot[role="button"]')
              .forEach(bindCaret);

            document.addEventListener('click', function(event) {
              if (!event.target.closest('.cfm-core-terms-editor-row')) {
                clearSelectedRow();
              }
            });

            window.addEventListener('beforeunload', function(event) {
              if (formSubmitting || !dirtyRows.size) {
                return;
              }

              event.preventDefault();
              event.returnValue = '';
            });
          }

          document.addEventListener('DOMContentLoaded', function() {
            form = document.querySelector('.cfm-core-terms-editor-form');
            archiveForm = document.querySelector('.cfm-core-terms-editor-archive-form');
            archiveTermInput = archiveForm ? archiveForm.querySelector('input[name="term_uuid"]') : null;
            moveForm = document.querySelector('.cfm-core-terms-editor-move-form');
            moveModal = document.querySelector('.cfm-core-terms-editor-move-modal');
            moveSelect = document.querySelector('.cfm-core-terms-editor-new-parent');
            moveSubmit = document.querySelector('.cfm-core-terms-editor-move-submit');
            moveSummary = document.querySelector('.cfm-core-terms-editor-move-summary');
            moveError = document.querySelector('.cfm-core-terms-editor-move-error');
            moveAssignmentWarning = document.querySelector('.cfm-core-terms-editor-move-assignment-warning');
            moveAxisWarning = document.querySelector('.cfm-core-terms-editor-move-axis-warning');
            moveAssignmentCheckbox = document.querySelector('.cfm-core-terms-editor-confirm-assignments');
            moveAxisCheckbox = document.querySelector('.cfm-core-terms-editor-confirm-axis');
            moveAssignmentCount = document.querySelector('.cfm-core-terms-editor-assignment-count');
            collapseAllButton = document.querySelector('.cfm-core-terms-editor-collapse-all');
            saveButton = document.querySelector('.cfm-core-terms-editor-save');
            resetButton = document.querySelector('.cfm-core-terms-editor-reset');
            dirtyCount = document.querySelector('.cfm-core-terms-editor-dirty-count');
            toolbar = document.querySelector('.cfm-core-terms-editor-toolbar');
            changesInput = document.querySelector('input[name="cfm_editor_changes"]');
            editor = document.querySelector('.cfm-core-terms-editor');
            editorTree = document.querySelector('.cfm-core-terms-editor-tree');
            reorderStatus = document.querySelector('.cfm-core-terms-editor-reorder-status');
            moveNotice = document.querySelector('.cfm-core-terms-editor-move-notice');
            undoMoveButton = document.querySelector('.cfm-core-terms-editor-undo-move');
            statusRailSeparator = document.querySelector('.cfm-core-terms-editor-status-separator');
            reorderNonce = editor ? (editor.getAttribute('data-reorder-nonce') || '') : '';
            moveBranchNonce = editor ? (editor.getAttribute('data-move-branch-nonce') || '') : '';
            rootParentUuid = editor ? (editor.getAttribute('data-root-parent-uuid') || '') : '';
            frameworkId = editor ? (editor.getAttribute('data-framework-id') || '') : '';

            try {
              orderRevisions = editor ? JSON.parse(editor.getAttribute('data-order-revisions') || '{}') : {};
            } catch (error) {
              orderRevisions = {};
            }

            restoreOpenState();
            bindInteractions();
            bindForm();
            restoreDraftState();
            restorePendingSavePosition();
            updateAllRowSaveVisibility();
            updateToolbar();
            updateReorderControls();
            updateCollapseAllControl();
          });
          document.addEventListener('keydown', handleInlineEditKeyEvent, true);
          document.addEventListener('keypress', handleInlineEditKeyEvent, true);
          document.addEventListener('keyup', handleInlineEditKeyEvent, true);
          document.addEventListener('keydown', handleEditorKeydown);
        }());
      </script>
    </div>
  <?php
  }

  private static function render_core_terms_editor_reference_row(): void
  {
    ?>
    <div class="cfm-core-terms-editor-reference" aria-label="Core Term Format">
      <div class="cfm-core-terms-editor-reference-title">Reference Guide</div>
      <div class="cfm-core-terms-editor-reference-row cfm-core-terms-editor-reference-labels">
        <span class="cfm-core-terms-editor-reference-spacer" aria-hidden="true"></span>
        <span title="The full canonical name for this term." tabindex="0">Label</span>
        <span class="cfm-core-terms-editor-reference-metadata">
          <span title="The stable machine-readable identifier generated from the label." tabindex="0">Slug</span>
          <span title="Compact display text for narrow UI placements." tabindex="0">Short Label</span>
          <span title="The canonical professional community associated with this term." tabindex="0">Community</span>
        </span>
        <span class="cfm-core-terms-editor-reference-actions" aria-hidden="true"></span>
      </div>
      <div class="cfm-core-terms-editor-reference-row cfm-core-terms-editor-reference-example" aria-label="Example Core Term values">
        <span class="cfm-core-terms-editor-reference-spacer" aria-hidden="true"></span>
        <span>Adult Education</span>
        <span class="cfm-core-terms-editor-reference-metadata">
          <span>adult-education</span>
          <span>Adult Ed</span>
          <span>Adult Educators</span>
        </span>
        <span class="cfm-core-terms-editor-reference-actions" aria-hidden="true"></span>
      </div>
    </div>
    <?php
  }

  private static function render_core_terms_editor_nodes(array $terms, int $depth = 0, array $branch_assignment_counts = [], string $parent_uuid = '', string $axis_uuid = ''): void
  {
    foreach ($terms as $term) {
      if (!is_array($term) || self::node_kind($term) !== 'term') {
        continue;
      }

      self::render_core_terms_editor_node($term, $depth, $branch_assignment_counts, $parent_uuid, $axis_uuid);
    }
  }

  private static function render_core_terms_editor_node(array $term, int $depth = 0, array $branch_assignment_counts = [], string $parent_uuid = '', string $axis_uuid = ''): void
  {
    $children = isset($term['children']) && is_array($term['children'])
      ? array_values(array_filter($term['children'], static function ($child): bool {
        return is_array($child) && self::node_kind($child) === 'term';
      }))
      : [];
    $term_uuid = (string) ($term['uuid'] ?? '');
    $term_axis_uuid = $axis_uuid !== '' ? $axis_uuid : $term_uuid;

    $classes = [
      'cfm-core-terms-editor-term',
      'cfm-core-terms-editor-depth-' . $depth,
    ];

    if (!empty($children)) {
      $classes[] = 'cfm-core-terms-editor-has-children';
    }

    echo '<div class="' . esc_attr(implode(' ', $classes)) . '">';

    if (!empty($children)) {
      echo '<details>';
      echo '<summary>';
      self::render_core_terms_editor_node_fields($term, true, 'span', $parent_uuid, $term_axis_uuid, (int) ($branch_assignment_counts[$term_uuid] ?? 0));
      echo '</summary>';
      echo '<div class="cfm-core-terms-editor-children">';
      self::render_core_terms_editor_nodes($children, $depth + 1, $branch_assignment_counts, $term_uuid, $term_axis_uuid);
      echo '</div>';
      echo '</details>';
    } else {
      self::render_core_terms_editor_node_fields($term, false, 'div', $parent_uuid, $term_axis_uuid, (int) ($branch_assignment_counts[$term_uuid] ?? 0));
    }

    echo '</div>';
  }

  private static function render_core_terms_editor_node_fields(array $term, bool $has_children, string $container_tag = 'div', string $parent_uuid = '', string $axis_uuid = '', int $assignment_count = 0): void
  {
    $uuid = (string) ($term['uuid'] ?? '');
    $label = (string) ($term['label'] ?? '');
    $slug = (string) ($term['slug'] ?? '');
    $short_label = self::display_short_label_for_node($term);
    $community = self::display_description_for_node($term);

    $container_tag = $container_tag === 'span' ? 'span' : 'div';

    echo '<' . esc_attr($container_tag)
      . ' class="cfm-core-terms-editor-row"'
      . ' data-term-uuid="' . esc_attr($uuid) . '"'
      . ' data-original-label="' . esc_attr($label) . '"'
      . ' data-original-slug="' . esc_attr($slug) . '"'
      . ' data-original-short-label="' . esc_attr($short_label) . '"'
      . ' data-original-community="' . esc_attr($community) . '"'
      . ' data-parent-uuid="' . esc_attr($parent_uuid) . '"'
      . ' data-axis-uuid="' . esc_attr($axis_uuid) . '"'
      . ' data-assignment-count="' . esc_attr((string) max(0, $assignment_count)) . '"'
      . '>';
    echo '<span class="cfm-core-terms-editor-rail">';

    if ($has_children) {
      echo '<span class="cfm-core-terms-editor-caret-slot" role="button" tabindex="0" aria-label="Expand or collapse term">';
      echo '<span class="cfm-core-terms-editor-caret"></span>';
      echo '</span>';
    } else {
      echo '<span class="cfm-core-terms-editor-caret-slot" aria-hidden="true"></span>';
    }

    echo '<span class="cfm-core-terms-editor-handle" role="button" tabindex="0" aria-label="Drag to reorder sibling">::</span>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-field cfm-core-terms-editor-field-label">';
    echo '<span class="cfm-core-terms-editor-display cfm-core-terms-editor-label-display">' . esc_html($label) . '</span>';
    echo '<input class="cfm-core-terms-editor-edit cfm-core-terms-editor-input cfm-core-terms-editor-input-label" type="text" value="' . esc_attr($label) . '" aria-label="Label">';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-field cfm-core-terms-editor-meta">';
    echo '<span class="cfm-core-terms-editor-display cfm-core-terms-editor-meta-display">';
    echo '<span class="cfm-core-terms-editor-meta-part cfm-core-terms-editor-meta-slug">' . esc_html($slug) . '</span>';
    echo '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>';
    echo '<span class="cfm-core-terms-editor-meta-part cfm-core-terms-editor-meta-short-label">' . esc_html($short_label) . '</span>';
    echo '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>';
    echo '<span class="cfm-core-terms-editor-meta-part cfm-core-terms-editor-meta-community">' . esc_html($community) . '</span>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-edit cfm-core-terms-editor-meta-edit">';
    echo '<input class="cfm-core-terms-editor-input cfm-core-terms-editor-input-slug" type="text" value="' . esc_attr($slug) . '" aria-label="Slug">';
    echo '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>';
    echo '<input class="cfm-core-terms-editor-input cfm-core-terms-editor-input-short-label" type="text" value="' . esc_attr($short_label) . '" aria-label="Short Label">';
    echo '<span class="cfm-core-terms-editor-meta-separator" aria-hidden="true">/</span>';
    echo '<input class="cfm-core-terms-editor-input cfm-core-terms-editor-input-community" type="text" value="' . esc_attr($community) . '" aria-label="Community">';
    echo '</span>';
    echo '</span>';

    echo '<span class="cfm-core-terms-editor-actions">';
    echo '<button type="button" class="button button-small cfm-core-terms-editor-row-save" hidden>Save Row</button>';
    echo '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-move-up" aria-label="Move up" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="move-up"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 5l-5 5"></path><path d="M10 5l5 5"></path><path d="M10 5v10"></path></svg></button>';
    echo '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-move-down" aria-label="Move down" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="move-down"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M10 15l-5-5"></path><path d="M10 15l5-5"></path><path d="M10 5v10"></path></svg></button>';
    echo '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-insert-sibling" aria-label="Insert sibling" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="sibling"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 6h7"></path><path d="M5 14h7"></path><path d="M15 10v6"></path><path d="M12 13h6"></path></svg></button>';
    echo '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-add-child" aria-label="Add child" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="child"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 4v10h6"></path><path d="M8 14h6"></path><path d="M15 11v6"></path><path d="M12 14h6"></path></svg></button>';
    echo '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-move-branch" aria-label="Move branch" aria-haspopup="menu" aria-expanded="false" data-cfm-action-menu-trigger="move-branch"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5 10h10"></path><path d="M11 6l4 4-4 4"></path><path d="M5 5v10"></path></svg></button>';
    echo '<button type="button" class="cfm-core-terms-editor-row-action cfm-core-terms-editor-archive-branch" aria-label="Archive branch" aria-haspopup="true" aria-expanded="false" data-cfm-action-menu-trigger="archive"><svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 6h12"></path><path d="M6 6v10h8V6"></path><path d="M8 4h4l1 2H7l1-2z"></path><path d="M8 10h4"></path></svg></button>';
    echo '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-sibling-menu" role="menu" data-cfm-action-menu="sibling" hidden>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-insert-sibling-before" role="menuitem">Insert sibling before</button>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-insert-sibling-after" role="menuitem">Insert sibling after</button>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-child-menu" role="menu" data-cfm-action-menu="child" hidden>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-add-child-leaf" role="menuitem">Add child</button>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-prepend-child" role="menuitem">Prepend child</button>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-append-child" role="menuitem">Append child</button>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-move-up-menu" role="menu" data-cfm-action-menu="move-up" hidden>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-move-up-menu-action" role="menuitem">Move up</button>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-move-down-menu" role="menu" data-cfm-action-menu="move-down" hidden>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-move-down-menu-action" role="menuitem">Move down</button>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-move-branch-menu" role="menu" data-cfm-action-menu="move-branch" hidden>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-move-branch-menu-action" role="menuitem">Move branch</button>';
    echo '</span>';
    echo '<span class="cfm-core-terms-editor-action-menu cfm-core-terms-editor-archive-menu" role="menu" data-cfm-action-menu="archive" hidden>';
    echo '<button type="button" class="cfm-core-terms-editor-menu-item cfm-core-terms-editor-archive-menu-action" role="menuitem">Archive branch</button>';
    echo '</span>';
    echo '<button type="button" class="button button-small cfm-core-terms-editor-cancel-draft">Cancel</button>';
    echo '<span class="cfm-core-terms-editor-status" aria-live="polite">Unsaved</span>';
    echo '</span>';
    echo '</' . esc_attr($container_tag) . '>';
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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $axes = self::root_terms($tree);

  ?>
    <div class="wrap">
      <h1>Core Terms</h1>
      <p>
        <a class="button button-secondary" href="<?php echo esc_url(self::editor_url((int) $framework->id)); ?>">Core Terms Editor</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::archived_terms_url((int) $framework->id)); ?>">Archived Terms</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::data_url((int) $framework->id)); ?>">Data</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::meta_groups_url((int) $framework->id)); ?>">Meta-Groups</a>
        <a class="button button-secondary" href="<?php echo esc_url(self::maintenance_url((int) $framework->id)); ?>">Maintenance</a>
      </p>

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
          <p>Terms restored by creating a new active version.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_recovery_snapshot_restored'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>
            Recovery snapshot restored and runtime tables rebuilt.
            A pre-restore snapshot was saved automatically before the restore.
            <?php if (!empty($_GET['cfm_pre_restore_snapshot_id'])) : ?>
              <a href="<?php echo esc_url(self::version_snapshot_url((int) $framework->id, absint($_GET['cfm_pre_restore_snapshot_id']))); ?>">View pre-restore snapshot</a>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_autocompiled'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term changes saved and runtime tables rebuilt automatically.</p>
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

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'version_save_failed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Term changes could not be saved.</p>
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

      <h2 id="cfm-existing-terms">Terms</h2>

      <?php if (empty($axes)) : ?>
        <p>No top-level terms created yet. Install an example pack or add a term below.</p>
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
                    <?php self::render_terms_recursive($terms); ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

    </div>
<?php
  }
}
