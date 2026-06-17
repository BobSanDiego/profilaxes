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
      'Core Terms',
      'Core Terms',
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

    wp_safe_redirect(self::edit_url($framework_id) . '&cfm_import_preview=1#cfm-import-preview');
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
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_replace_not_confirmed#cfm-import');
      exit;
    }

    $preview = get_transient(self::import_preview_transient_key($framework_id));

    if (!is_array($preview) || empty($preview['is_valid']) || empty($preview['tree']) || !is_array($preview['tree'])) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_preview_expired#cfm-import');
      exit;
    }

    $import_tree = $preview['tree'];
    self::normalize_tree_children($import_tree);

    $current_tree = self::get_framework_tree($framework);
    self::normalize_tree_children($current_tree);

    $current_snapshot_id = CFM_Framework_Repository::create_version($framework_id, $current_tree, 'pre_import_snapshot');

    if ($current_snapshot_id <= 0) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_snapshot_failed#cfm-import');
      exit;
    }

    $compile_result = self::save_active_tree_and_compile($framework_id, $import_tree);

    delete_transient(self::import_preview_transient_key($framework_id));

    if (empty($compile_result['success'])) {
      wp_safe_redirect(self::edit_url($framework_id) . '&cfm_error=import_compile_failed' . $compile_result['query_arg'] . '#cfm-import');
      exit;
    }

    wp_safe_redirect(
      self::edit_url($framework_id)
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

  private static function handle_install_example_pack(): void
  {
    check_admin_referer('cfm_install_example_pack', 'cfm_nonce');

    $framework_id = absint($_POST['framework_id'] ?? 0);
    $pack = sanitize_key(wp_unslash($_POST['example_pack'] ?? ''));

    if ($framework_id <= 0 || !class_exists('CFM_Seeder') || !CFM_Seeder::is_valid_pack($pack)) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=invalid_example_pack'
            . '#cfm-example-packs'
        )
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
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_example_pack_installed=' . rawurlencode($pack)
          . '&cfm_example_created=' . $created
          . '&cfm_example_skipped=' . $skipped
          . ($compile_result['query_arg'] ?? '')
          . '#cfm-example-packs'
      )
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
      $term_description = $term_label;
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
          'short_label' => $label,
          'kind' => 'term',
          'description' => $label,
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

    foreach ($terms_to_add as $term) {
      if (self::has_child_slug_conflict($tree, $parent_uuid, (string) $term['slug'])) {
        self::redirect_batch_error(
          $framework_id,
          $is_top_level ? '__top_level__' : $parent_uuid,
          'A batch term conflicts with an existing sibling slug: ' . (string) $term['slug'] . '. No terms were added.',
          $batch_input
        );
      }
    }

    foreach ($terms_to_add as $term) {
      if (!self::append_child_to_node_by_uuid($tree, $parent_uuid, $term)) {
        wp_die('Parent not found.');
      }
    }

    self::bump_order_revision($framework_id, $parent_uuid);

    $compile_result = self::save_active_tree_and_compile($framework_id, $tree);

    $parent_label = $is_top_level ? 'top level' : (string) ($parent_info['node']['label'] ?? 'selected parent');
    $transient_key = self::batch_added_terms_transient_key($framework_id);

    set_transient($transient_key, [
      'parent_uuid' => $is_top_level ? '__top_level__' : $parent_uuid,
      'parent_label' => $parent_label,
      'terms' => $terms_to_add,
    ], 5 * MINUTE_IN_SECONDS);

    wp_safe_redirect(
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_terms_batch_added=1'
          . '&cfm_parent_uuid=' . rawurlencode($is_top_level ? '__top_level__' : $parent_uuid)
          . $compile_result['query_arg']
          . '#cfm-batch-added'
      )
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
      $description = $label;
    }

    if ($framework_id <= 0 || $label === '' || $slug === '' || count($includes) < 2) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=missing_meta_group_fields'
            . '#cfm-meta-groups'
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
              . '&action=edit'
              . '&framework_id=' . $framework_id
              . '&cfm_error=invalid_meta_group_includes'
              . '#cfm-meta-groups'
          )
        );
        exit;
      }
    }

    if (self::has_child_slug_conflict($tree, (string) ($tree['uuid'] ?? ''), $slug)) {
      wp_safe_redirect(
        admin_url(
          'admin.php?page=cfm-frameworks'
            . '&action=edit'
            . '&framework_id=' . $framework_id
            . '&cfm_error=duplicate_meta_group_slug'
            . '#cfm-meta-groups'
        )
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
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_meta_group_added=1'
          . $compile_result['query_arg']
          . '#cfm-meta-groups'
      )
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
      $description = $label;
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
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_meta_group_updated=1'
          . $compile_result['query_arg']
          . '#cfm-meta-groups'
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
    $term_slug_input = wp_unslash($_POST['term_slug'] ?? '');
    $term_slug = self::normalize_slug($term_slug_input !== '' ? (string) $term_slug_input : $term_label);
    $term_short_label = sanitize_text_field(wp_unslash($_POST['term_short_label'] ?? ''));
    $term_description = sanitize_textarea_field(wp_unslash($_POST['term_description'] ?? ''));

    if ($term_short_label === '') {
      $term_short_label = $term_label;
    }

    if ($term_description === '') {
      $term_description = $term_label;
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
      wp_die('Core Terms definition not found.');
    }

    $tree = self::get_framework_tree($framework);
    $term_info = self::find_node_with_parent($tree, $term_uuid);
    $new_parent_info = self::find_node_with_parent($tree, $new_parent_uuid);

    if (!$term_info || empty($term_info['node']) || !is_array($term_info['node'])) {
      wp_die('Term not found.');
    }

    if (self::node_kind($term_info['node']) !== 'term') {
      wp_die('Only terms can be moved. Meta-Groups and system roots cannot be moved here.');
    }

    if (!$new_parent_info || empty($new_parent_info['node']) || !is_array($new_parent_info['node'])) {
      wp_die('New parent not found.');
    }

    if (!in_array(self::node_kind($new_parent_info['node']), ['framework', 'root', 'term'], true)) {
      wp_die('New parent must be the taxonomy root or another profile term.');
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

    do_action('cfm_term_moved', $framework_id, $term_uuid, $current_parent_uuid, $new_parent_uuid, $removed_term, $compile_result);

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

    if ($uuid !== '' && !in_array($kind, ['framework', 'root', 'meta'], true) && count($children) > 1) {
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
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . (int) $framework->id
          . '&cfm_recovery_snapshot_restored=1'
          . '&cfm_pre_restore_snapshot_id=' . (int) $pre_restore_snapshot_id
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
        echo '<button type="button" class="button-link cfm-meta-term-toggle" aria-expanded="true" style="width:18px; text-decoration:none;">▾</button>';
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
        echo '<div class="cfm-meta-term-children" style="margin-left:18px;">';
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
      admin_url(
        'admin.php?page=cfm-frameworks'
          . '&action=edit'
          . '&framework_id=' . $framework_id
          . '&cfm_batch_error=1'
          . '&cfm_parent_uuid=' . rawurlencode($parent_uuid)
          . '#cfm-add-term'
      )
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
            <th>Missing Descriptions</th>
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
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=edit'
                        . '&framework_id=' . (int) $framework->id
                    )
                  ); ?>">
          ← Back to Core Terms
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
          <th>Descriptions changed</th>
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
    <?php self::render_field_change_samples('Description change samples', $comparison['description_change_samples'] ?? []); ?>
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
        <a href="<?php echo esc_url(
                    admin_url(
                      'admin.php?page=cfm-frameworks'
                        . '&action=edit'
                        . '&framework_id=' . (int) $framework->id
                    )
                  ); ?>">Back to Core Terms</a>
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

        var fillTarget = function(labelInput, target, detached) {
          if (detached[target.name]) {
            return;
          }

          var type = target.getAttribute('data-cfm-autofill-type');
          target.value = type === 'slug' ? normalizeSlug(labelInput.value) : labelInput.value;
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
            <th scope="row"><label for="term_description">Description</label></th>
            <td>
              <textarea name="term_description" id="term_description" class="large-text" rows="3" data-cfm-autofill-target="edit-term" data-cfm-autofill-type="copy"><?php echo esc_textarea(self::display_description_for_node($term)); ?></textarea>
              <p class="description">Plain-text explanation for hover/help text and richer display contexts. Leave blank to use the term label.</p>
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
      <h1>Edit Meta-Group: <?php echo esc_html($meta_group['label'] ?? ''); ?></h1>

      <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=cfm-frameworks&action=edit&framework_id=' . (int) $framework->id . '#cfm-meta-groups')); ?>">← Back to Meta-Groups</a>
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

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="meta_group_label">Meta-Group Label</label></th>
            <td>
              <input name="meta_group_label" id="meta_group_label" type="text" class="regular-text" value="<?php echo esc_attr($meta_group['label'] ?? ''); ?>" data-cfm-autofill-label="edit-meta-group" required>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="meta_group_slug">Meta-Group Slug</label></th>
            <td>
              <input name="meta_group_slug" id="meta_group_slug" type="text" class="regular-text" value="<?php echo esc_attr($meta_group['slug'] ?? ''); ?>" data-cfm-autofill-target="edit-meta-group" data-cfm-autofill-type="slug">
              <p class="description">Keep this stable unless you intentionally need to change API-facing references.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="meta_group_short_label">Short Label</label></th>
            <td>
              <input name="meta_group_short_label" id="meta_group_short_label" type="text" class="regular-text" value="<?php echo esc_attr(self::display_short_label_for_node($meta_group)); ?>" data-cfm-autofill-target="edit-meta-group" data-cfm-autofill-type="copy">
              <p class="description">Compact display text. Leave blank to use the Meta-Group label.</p>
            </td>
          </tr>

          <tr>
            <th scope="row"><label for="meta_group_description">Description</label></th>
            <td>
              <textarea name="meta_group_description" id="meta_group_description" class="large-text" rows="3" data-cfm-autofill-target="edit-meta-group" data-cfm-autofill-type="copy"><?php echo esc_textarea(self::display_description_for_node($meta_group)); ?></textarea>
              <p class="description">Plain-text explanation. Leave blank to use the Meta-Group label.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">Included Terms</th>
            <td>
              <?php if (count($available_terms) < 2) : ?>
                <p>Create at least two terms before editing Meta-Group includes.</p>
              <?php else : ?>
                <div data-cfm-meta-term-selector="1">
                  <p class="description" style="margin-top:0;">Parent checkboxes select or clear all descendant terms. Child changes update parent checkbox state.</p>
                  <div style="display:flex; justify-content:space-between; max-width:760px; margin:0 0 8px;">
                    <span>
                      <a href="#" data-cfm-meta-expand="1">Expand all</a>
                      <span aria-hidden="true"> | </span>
                      <a href="#" data-cfm-meta-expand="0">Collapse all</a>
                    </span>
                    <span id="cfm-meta-selected-count" class="description">0 terms selected</span>
                  </div>
                  <fieldset style="max-height: 340px; overflow: auto; border: 1px solid #ccd0d4; background: #fff; padding: 10px; max-width:760px;">
                    <legend class="screen-reader-text">Included Terms</legend>
                    <?php self::render_meta_group_term_checklist(self::root_terms($tree), 0, $selected_uuids); ?>
                  </fieldset>
                </div>
                <p class="description">Meta-Groups remain non-assignable. This selector only changes the referenced terms.</p>
              <?php endif; ?>
            </td>
          </tr>
        </table>

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
    $meta_groups = self::root_meta_groups($tree);
    $available_terms = self::collect_assignable_term_nodes($tree);
    $terms_by_uuid = [];

    foreach ($available_terms as $available_term) {
      $available_uuid = (string) ($available_term['uuid'] ?? '');

      if ($available_uuid !== '') {
        $terms_by_uuid[$available_uuid] = $available_term;
      }
    }
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

  ?>
    <div class="wrap">
      <h1>Core Terms</h1>

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

      <?php if (isset($_GET['cfm_axis_added'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Top-level term added.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_term_added'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term added.</p>
        </div>
      <?php endif; ?>

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

      <?php if (isset($_GET['cfm_compiled'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Runtime tables rebuilt.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_autocompiled'])) : ?>
        <div class="notice notice-success is-dismissible">
          <p>Term changes saved and runtime tables rebuilt automatically.</p>
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
              <a href="<?php echo esc_url(self::version_snapshot_url((int) $framework->id, absint($_GET['cfm_import_snapshot_id']))); ?>">View recovery snapshot</a>.
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_axis_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Top-level term label is required.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'missing_term_fields') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Parent, term label, and term slug are required.</p>
        </div>
      <?php endif; ?>

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
          <p>Meta-Group includes must reference existing terms only.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'duplicate_meta_group_slug') : ?>
        <div class="notice notice-error is-dismissible">
          <p>That Meta-Group slug already exists at the top level. Choose a different slug.</p>
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
          <p>Term changes could not be saved.</p>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['cfm_error']) && $_GET['cfm_error'] === 'compile_failed') : ?>
        <div class="notice notice-error is-dismissible">
          <p>Runtime rebuild failed. The saved profile tree may not match the query tables. Check PHP error logs, then retry compile.</p>
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

      <h2>History</h2>
      <p class="description">
        History records active taxonomy versions and automatic recovery snapshots, including snapshots created before replacement imports.
      </p>

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
                  <a href="<?php echo esc_url(self::version_snapshot_url((int) $framework->id, (int) $version_row->id)); ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <p>
        <a class="button" href="<?php echo esc_url(self::versions_url((int) $framework->id)); ?>">
          View Full History
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
          <?php submit_button('Rebuild Core Terms', 'secondary', 'submit', false); ?>
          <a class="button" href="<?php echo esc_url(self::compiled_debug_url((int) $framework->id)); ?>" style="margin-left: 8px;">Open Compiled Query Debug</a>
        </form>
      <?php endif; ?>

      <hr>

      <h2>Export</h2>
      <p class="description">
        Download the canonical editable Core Terms definition tree as JSON. This export preserves UUIDs, hierarchy, order, archive state when present, and active version metadata. Runtime compiler tables are intentionally not exported because they can be rebuilt.
      </p>
      <p>
        <a class="button" href="<?php echo esc_url(self::export_taxonomy_url((int) $framework->id)); ?>">
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
        <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">

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
            <h3>Geography — US States</h3>
            <form method="post">
              <?php wp_nonce_field('cfm_install_example_pack', 'cfm_nonce'); ?>
              <input type="hidden" name="cfm_action" value="install_example_pack">
              <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">
              <input type="hidden" name="example_pack" value="<?php echo esc_attr(CFM_Seeder::PACK_GEOGRAPHY_US_STATES); ?>">
              <?php submit_button('Install US States', 'secondary', 'submit', false); ?>
            </form>
            <p>Creates <strong>Region → United States</strong>, then adds the 50 states and District of Columbia beneath United States.</p>
          </div>

          <div class="card" style="max-width: 420px;">
            <h3>Geography — Countries Lite</h3>
            <form method="post">
              <?php wp_nonce_field('cfm_install_example_pack', 'cfm_nonce'); ?>
              <input type="hidden" name="cfm_action" value="install_example_pack">
              <input type="hidden" name="framework_id" value="<?php echo esc_attr((string) $framework->id); ?>">
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

      <h2 id="cfm-meta-groups">Meta-Groups</h2>
      <div class="notice notice-info inline">
        <p><strong>Meta-Groups are audience-only collections.</strong> They collect existing terms for future audience and extension use without changing the term tree.</p>
        <p>Users are assigned terms, not Meta-Groups. A Meta-Group can include terms from different branches without moving, copying, or replacing those terms.</p>
      </div>

      <?php self::render_meta_groups_table($meta_groups, $terms_by_uuid, (int) $framework->id); ?>

      <h3>Create Meta-Group</h3>

      <?php if (count($available_terms) < 2) : ?>
        <p>Create at least two terms before adding a Meta-Group.</p>
      <?php else : ?>
        <form method="post">
          <?php wp_nonce_field('cfm_add_meta_group', 'cfm_nonce'); ?>

          <input type="hidden" name="cfm_action" value="add_meta_group">
          <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">

          <table class="form-table" role="presentation">
            <tr>
              <th scope="row">
                <label for="meta_group_label">Meta-Group Label</label>
              </th>
              <td>
                <input name="meta_group_label" id="meta_group_label" type="text" class="regular-text" data-cfm-autofill-label="add-meta-group" required>
                <p class="description">Example: STEM, New Teachers, K–5 Science</p>
              </td>
            </tr>

            <tr>
              <th scope="row">
                <label for="meta_group_slug">Meta-Group Slug</label>
              </th>
              <td>
                <input name="meta_group_slug" id="meta_group_slug" type="text" class="regular-text" data-cfm-autofill-target="add-meta-group" data-cfm-autofill-type="slug">
                <p class="description">Example: stem, new-teachers, k-5-science</p>
              </td>
            </tr>

            <tr>
              <th scope="row">
                <label for="meta_group_short_label">Short Label</label>
              </th>
              <td>
                <input name="meta_group_short_label" id="meta_group_short_label" type="text" class="regular-text" data-cfm-autofill-target="add-meta-group" data-cfm-autofill-type="copy">
                <p class="description">Compact display text. Leave blank to use the Meta-Group label.</p>
              </td>
            </tr>

            <tr>
              <th scope="row">
                <label for="meta_group_description">Description</label>
              </th>
              <td>
                <textarea name="meta_group_description" id="meta_group_description" class="large-text" rows="3" data-cfm-autofill-target="add-meta-group" data-cfm-autofill-type="copy"></textarea>
                <p class="description">Plain-text explanation. Leave blank to use the Meta-Group label.</p>
              </td>
            </tr>

            <tr>
              <th scope="row">Included Terms</th>
              <td>
                <div data-cfm-meta-term-selector="1">
                  <p class="description" style="margin-top:0;">Select existing terms only. Parent checkboxes select or clear all descendant terms.</p>
                  <div style="display:flex; justify-content:space-between; max-width:760px; margin:0 0 8px;">
                    <span>
                      <a href="#" data-cfm-meta-expand="1">Expand all</a>
                      <span aria-hidden="true"> | </span>
                      <a href="#" data-cfm-meta-expand="0">Collapse all</a>
                    </span>
                    <span id="cfm-meta-selected-count" class="description">0 terms selected</span>
                  </div>
                  <fieldset style="max-height: 340px; overflow: auto; border: 1px solid #ccd0d4; background: #fff; padding: 10px; max-width:760px;">
                    <legend class="screen-reader-text">Included Terms</legend>
                    <?php self::render_meta_group_term_checklist(self::root_terms($tree)); ?>
                  </fieldset>
                </div>
                <p class="description">Meta-Groups do not create new terms, move terms in the tree, or become directly assignable user values.</p>
              </td>
            </tr>
          </table>

          <?php submit_button('Add Meta-Group'); ?>
        </form>
        <?php self::render_meta_group_term_checklist_script(); ?>
      <?php endif; ?>

      <hr>

      <h2 id="cfm-add-term">Add Term</h2>

      <form method="post">
        <?php wp_nonce_field('cfm_add_term', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="add_term">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">
              <label for="parent_uuid">Parent Term</label>
            </th>
            <td>
              <?php $selected_parent_uuid = sanitize_text_field($_GET['cfm_parent_uuid'] ?? '__top_level__'); ?>
              <select name="parent_uuid" id="parent_uuid">
                <option value="" <?php selected($selected_parent_uuid, '__top_level__'); ?>>Add as Top-Level Term</option>
                <?php self::render_parent_options($axes, $selected_parent_uuid); ?>
              </select>
              <p class="description">Leave unchanged to create a top-level term, or select an existing term to create a child term.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="term_label">Term Label</label>
            </th>
            <td>
              <input name="term_label" id="term_label" type="text" class="regular-text" data-cfm-autofill-label="add-term" required>
              <p class="description">Example: Grade Level, Grade 1, Curriculum, Algebra, Region, California</p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="term_slug">Term Slug</label>
            </th>
            <td>
              <input name="term_slug" id="term_slug" type="text" class="regular-text" data-cfm-autofill-target="add-term" data-cfm-autofill-type="slug">
              <p class="description">Example: grade-level, grade-1, curriculum, algebra, region, california</p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="term_short_label">Short Label</label>
            </th>
            <td>
              <input name="term_short_label" id="term_short_label" type="text" class="regular-text" data-cfm-autofill-target="add-term" data-cfm-autofill-type="copy">
              <p class="description">Compact display text. Leave blank to use the term label.</p>
            </td>
          </tr>

          <tr>
            <th scope="row">
              <label for="term_description">Description</label>
            </th>
            <td>
              <textarea name="term_description" id="term_description" class="large-text" rows="3" data-cfm-autofill-target="add-term" data-cfm-autofill-type="copy"></textarea>
              <p class="description">Plain-text explanation. Leave blank to use the term label.</p>
            </td>
          </tr>
        </table>

        <?php submit_button('Add Term'); ?>
      </form>

      <h3>Quick Add Terms</h3>
      <p class="description">Create multiple sibling terms at once. One term per line.</p>
      <form method="post">
        <?php wp_nonce_field('cfm_add_terms_batch', 'cfm_nonce'); ?>

        <input type="hidden" name="cfm_action" value="add_terms_batch">
        <input type="hidden" name="framework_id" value="<?php echo esc_attr($framework->id); ?>">

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
              <p class="description">One term label per line. Slug, short label, and description are generated from each label. The whole batch is rejected if any row conflicts.</p>
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

      <?php self::render_term_metadata_autofill_script(); ?>
    </div>
<?php
  }
}
