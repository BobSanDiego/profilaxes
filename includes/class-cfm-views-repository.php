<?php

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Minimal persistence boundary for Durable Views.
 *
 * Lifecycle and deterministic Core Terms-backed resolution boundary for Views.
 */
class CFM_Views_Repository
{
  public static function create_view(array $data)
  {
    global $wpdb;

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
      return new WP_Error('cfm_views_invalid_data', 'View name is required.');
    }

    $now = current_time('mysql');
    $view_uuid = wp_generate_uuid4();
    $table = $wpdb->prefix . 'cfm_views';
    $inserted = $wpdb->insert($table, [
      'view_uuid' => $view_uuid,
      'schema_version' => '1.0',
      'name' => sanitize_text_field($name),
      'description' => isset($data['description']) ? sanitize_textarea_field((string) $data['description']) : null,
      'owner_type' => 'platform',
      'status' => 'draft',
      'visibility' => isset($data['visibility']) ? sanitize_key((string) $data['visibility']) : 'platform',
      'extension_metadata_json' => null,
      'created_by' => get_current_user_id() ?: null,
      'updated_by' => get_current_user_id() ?: null,
      'created_at' => $now,
      'updated_at' => $now,
    ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);

    if ($inserted === false) {
      return new WP_Error('cfm_views_insert_failed', 'Failed to create View.', ['last_error' => $wpdb->last_error]);
    }

    return (int) $wpdb->insert_id;
  }

  public static function get_view($view_id): ?object
  {
    global $wpdb;
    $view_id = absint($view_id);
    if (!$view_id) {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_views';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $view_id)) ?: null;
  }

  public static function create_draft_version($view_id, array $data = [])
  {
    global $wpdb;
    $view_id = absint($view_id);
    if (!$view_id || !self::get_view($view_id)) {
      return new WP_Error('cfm_views_not_found', 'View was not found.');
    }

    $table = $wpdb->prefix . 'cfm_view_versions';
    $next_version = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(MAX(version_number), 0) + 1 FROM {$table} WHERE view_id = %d",
      $view_id
    ));
    $now = current_time('mysql');
    $version_uuid = wp_generate_uuid4();
    $lineage_uuid = isset($data['lineage_uuid']) && trim((string) $data['lineage_uuid']) !== ''
      ? sanitize_text_field((string) $data['lineage_uuid'])
      : wp_generate_uuid4();

    $inserted = $wpdb->insert($table, [
      'view_id' => $view_id,
      'version_uuid' => $version_uuid,
      'version_number' => $next_version,
      'lineage_uuid' => $lineage_uuid,
      'based_on_version_id' => isset($data['based_on_version_id']) ? absint($data['based_on_version_id']) ?: null : null,
      'schema_version' => '1.0',
      'status' => 'draft',
      'validation_state' => 'warning',
      'created_by' => get_current_user_id() ?: null,
      'created_at' => $now,
      'updated_at' => $now,
    ], ['%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s']);

    if ($inserted === false) {
      return new WP_Error('cfm_views_version_insert_failed', 'Failed to create View draft version.', ['last_error' => $wpdb->last_error]);
    }

    return (int) $wpdb->insert_id;
  }

  public static function save_group($version_id, array $data)
  {
    global $wpdb;
    $version = self::get_version($version_id);
    $label = trim((string) ($data['label'] ?? ''));
    $key = sanitize_key((string) ($data['group_key'] ?? ''));
    if (!$version || (string) $version->status !== 'draft') {
      return new WP_Error('cfm_views_draft_required', 'Groups can only be edited on a draft version.');
    }
    if ($label === '' || $key === '') {
      return new WP_Error('cfm_views_invalid_data', 'Group key and label are required.');
    }
    $now = current_time('mysql');
    $group_id = absint($data['group_id'] ?? 0);
    $payload = [
      'group_key' => $key,
      'label' => sanitize_text_field($label),
      'description' => isset($data['description']) ? sanitize_textarea_field((string) $data['description']) : null,
      'display_order' => max(0, (int) ($data['display_order'] ?? 0)),
      'is_featured' => empty($data['is_featured']) ? 0 : 1,
      'is_hidden' => empty($data['is_hidden']) ? 0 : 1,
      'metadata_json' => wp_json_encode(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
      'updated_at' => $now,
    ];
    if ($group_id) {
      $updated = $wpdb->update($wpdb->prefix . 'cfm_view_groups', $payload, ['id' => $group_id, 'version_id' => (int) $version->id], ['%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s'], ['%d', '%d']);
      return $updated === false ? new WP_Error('cfm_views_update_failed', 'Failed to update View group.') : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_groups WHERE id = %d', $group_id));
    }
    $payload['version_id'] = (int) $version->id;
    $payload['group_uuid'] = wp_generate_uuid4();
    $payload['created_at'] = $now;
    $inserted = $wpdb->insert($wpdb->prefix . 'cfm_view_groups', $payload, ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s']);
    return $inserted === false ? new WP_Error('cfm_views_insert_failed', 'Failed to create View group.') : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_groups WHERE id = %d', $wpdb->insert_id));
  }

  public static function save_entry($version_id, array $data)
  {
    global $wpdb;
    $version = self::get_version($version_id);
    $term_uuid = sanitize_text_field((string) ($data['term_uuid'] ?? ''));
    $framework = sanitize_key((string) ($data['core_terms_framework'] ?? ''));
    $inclusion = sanitize_key((string) ($data['inclusion'] ?? 'include'));
    if (!$version || (string) $version->status !== 'draft') {
      return new WP_Error('cfm_views_draft_required', 'Entries can only be edited on a draft version.');
    }
    if ($term_uuid === '' || $framework === '' || !in_array($inclusion, ['include', 'exclude'], true)) {
      return new WP_Error('cfm_views_invalid_data', 'Entry requires a Core Terms UUID, framework, and valid inclusion.');
    }
    if (!self::term_catalog($framework)['framework'] || !isset(self::term_catalog($framework)['terms'][$term_uuid])) {
      return new WP_Error('cfm_views_invalid_term', 'Entry must reference an existing Core Terms UUID.');
    }
    $now = current_time('mysql');
    $entry_id = absint($data['entry_id'] ?? 0);
    $payload = [
      'term_uuid' => $term_uuid,
      'core_terms_framework' => $framework,
      'group_id' => absint($data['group_id'] ?? 0) ?: null,
      'inclusion' => $inclusion,
      'display_order' => max(0, (int) ($data['display_order'] ?? 0)),
      'display_label' => isset($data['display_label']) ? sanitize_text_field((string) $data['display_label']) : null,
      'is_featured' => empty($data['is_featured']) ? 0 : 1,
      'is_hidden' => empty($data['is_hidden']) ? 0 : 1,
      'include_descendants' => empty($data['include_descendants']) ? 0 : 1,
      'source' => sanitize_key((string) ($data['source'] ?? 'manual')),
      'metadata_json' => wp_json_encode(is_array($data['metadata'] ?? null) ? $data['metadata'] : []),
      'validation_state' => 'warning',
      'updated_at' => $now,
    ];
    if ($entry_id) {
      $updated = $wpdb->update($wpdb->prefix . 'cfm_view_entries', $payload, ['id' => $entry_id, 'version_id' => (int) $version->id], ['%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s'], ['%d', '%d']);
      return $updated === false ? new WP_Error('cfm_views_update_failed', 'Failed to update View entry.') : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE id = %d', $entry_id));
    }
    $payload['version_id'] = (int) $version->id;
    $payload['entry_uuid'] = wp_generate_uuid4();
    $payload['created_at'] = $now;
    $inserted = $wpdb->insert($wpdb->prefix . 'cfm_view_entries', $payload, ['%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
    return $inserted === false ? new WP_Error('cfm_views_insert_failed', 'Failed to create View entry.') : $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE id = %d', $wpdb->insert_id));
  }

  public static function delete_entry($version_id, $entry_id)
  {
    global $wpdb;
    $version = self::get_version($version_id);
    if (!$version || (string) $version->status !== 'draft') {
      return new WP_Error('cfm_views_draft_required', 'Entries can only be deleted from a draft version.');
    }
    return false !== $wpdb->delete($wpdb->prefix . 'cfm_view_entries', ['id' => absint($entry_id), 'version_id' => (int) $version->id], ['%d', '%d']);
  }

  public static function move_entry($version_id, $entry_id, $direction)
  {
    global $wpdb;
    $version = self::get_version($version_id);
    if (!$version || (string) $version->status !== 'draft') {
      return new WP_Error('cfm_views_draft_required', 'Entries can only be reordered on a draft version.');
    }
    if (!in_array($direction, ['up', 'down'], true)) {
      return new WP_Error('cfm_views_invalid_direction', 'Entry order direction is invalid.');
    }
    $entry = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE id = %d AND version_id = %d', absint($entry_id), (int) $version->id));
    if (!$entry) { return new WP_Error('cfm_views_entry_not_found', 'View entry was not found.'); }
    $siblings = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE version_id = %d ORDER BY display_order ASC, id ASC', (int) $version->id));
    $siblings = array_values(array_filter((array) $siblings, static function ($candidate) use ($entry) { return (string) ($candidate->group_id ?? '') === (string) ($entry->group_id ?? ''); }));
    $index = array_search((int) $entry->id, array_map('intval', array_column($siblings, 'id')), true);
    $neighbor_index = $direction === 'up' ? $index - 1 : $index + 1;
    $neighbor = ($index !== false && isset($siblings[$neighbor_index])) ? $siblings[$neighbor_index] : null;
    if (!$neighbor) { return true; }
    $table = $wpdb->prefix . 'cfm_view_entries';
    $wpdb->update($table, ['display_order' => (int) $neighbor->display_order, 'updated_at' => current_time('mysql')], ['id' => (int) $entry->id, 'version_id' => (int) $version->id], ['%d', '%s'], ['%d', '%d']);
    $wpdb->update($table, ['display_order' => (int) $entry->display_order, 'updated_at' => current_time('mysql')], ['id' => (int) $neighbor->id, 'version_id' => (int) $version->id], ['%d', '%s'], ['%d', '%d']);
    return true;
  }

  public static function get_version($version_id): ?object
  {
    global $wpdb;
    $version_id = absint($version_id);
    if (!$version_id) {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_view_versions';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $version_id)) ?: null;
  }

  public static function submit_for_review($version_id)
  {
    return self::transition_version($version_id, ['draft'], 'review');
  }

  public static function publish_version($version_id)
  {
    global $wpdb;

    $version = self::get_version($version_id);
    if (!$version) {
      return new WP_Error('cfm_views_not_found', 'View version was not found.');
    }

    if (!in_array((string) $version->status, ['draft', 'review'], true)) {
      return new WP_Error('cfm_views_invalid_transition', 'Only draft or review versions can be published.');
    }

    if ((string) $version->validation_state === 'invalid') {
      return new WP_Error('cfm_views_invalid_version', 'An invalid View version cannot be published.');
    }

    $now = current_time('mysql');
    $user_id = get_current_user_id() ?: null;
    $versions_table = $wpdb->prefix . 'cfm_view_versions';
    $views_table = $wpdb->prefix . 'cfm_views';

    $wpdb->query('START TRANSACTION');
    $updated = $wpdb->update(
      $versions_table,
      [
        'status' => 'published',
        'published_at' => $now,
        'published_by' => $user_id,
        'updated_at' => $now,
      ],
      ['id' => absint($version_id)],
      ['%s', '%s', '%d', '%s'],
      ['%d']
    );

    if ($updated === false) {
      $wpdb->query('ROLLBACK');
      return new WP_Error('cfm_views_update_failed', 'Failed to publish View version.', ['last_error' => $wpdb->last_error]);
    }

    $view_updated = $wpdb->update(
      $views_table,
      [
        'status' => 'published',
        'current_version_id' => absint($version_id),
        'updated_by' => $user_id,
        'updated_at' => $now,
      ],
      ['id' => absint($version->view_id)],
      ['%s', '%d', '%d', '%s'],
      ['%d']
    );

    if ($view_updated === false) {
      $wpdb->query('ROLLBACK');
      return new WP_Error('cfm_views_update_failed', 'Failed to update the published View pointer.', ['last_error' => $wpdb->last_error]);
    }

    self::audit($wpdb, 'view_version', absint($version_id), 'publish', (string) $version->status, 'published', $now, $user_id);
    $wpdb->query('COMMIT');

    return self::get_version($version_id);
  }

  public static function create_draft_from_version($version_id)
  {
    global $wpdb;
    $version = self::get_version($version_id);
    if (!$version || !in_array((string) $version->status, ['published', 'deprecated'], true)) {
      return new WP_Error('cfm_views_invalid_source', 'Only published or deprecated versions can seed a new draft.');
    }

    $draft_id = self::create_draft_version((int) $version->view_id, [
      'based_on_version_id' => (int) $version->id,
      'lineage_uuid' => (string) $version->lineage_uuid,
    ]);
    if (is_wp_error($draft_id)) {
      return $draft_id;
    }
    $now = current_time('mysql');
    $group_map = [];
    foreach (self::groups_for_version($version->id) as $group) {
      $wpdb->insert($wpdb->prefix . 'cfm_view_groups', [
        'version_id' => $draft_id, 'group_uuid' => wp_generate_uuid4(), 'group_key' => $group->group_key,
        'label' => $group->label, 'description' => $group->description, 'display_order' => (int) $group->display_order,
        'is_featured' => (int) $group->is_featured, 'is_hidden' => (int) $group->is_hidden,
        'metadata_json' => $group->metadata_json, 'created_at' => $now, 'updated_at' => $now,
      ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']);
      $group_map[(int) $group->id] = (int) $wpdb->insert_id;
    }
    foreach (self::entries_for_version($version->id) as $entry) {
      $wpdb->insert($wpdb->prefix . 'cfm_view_entries', [
        'version_id' => $draft_id, 'entry_uuid' => wp_generate_uuid4(), 'term_uuid' => $entry->term_uuid,
        'core_terms_framework' => $entry->core_terms_framework, 'group_id' => $group_map[(int) $entry->group_id] ?? null,
        'inclusion' => $entry->inclusion, 'display_order' => (int) $entry->display_order, 'display_label' => $entry->display_label,
        'is_featured' => (int) $entry->is_featured, 'is_hidden' => (int) $entry->is_hidden,
        'include_descendants' => (int) $entry->include_descendants, 'source' => $entry->source,
        'metadata_json' => $entry->metadata_json, 'term_snapshot_json' => $entry->term_snapshot_json,
        'validation_state' => 'warning', 'validation_messages_json' => null, 'created_at' => $now, 'updated_at' => $now,
      ], ['%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }
    return self::get_version($draft_id);
  }

  public static function retire_view($view_id)
  {
    global $wpdb;

    $view = self::get_view($view_id);
    if (!$view) {
      return new WP_Error('cfm_views_not_found', 'View was not found.');
    }

    if ((string) $view->status === 'retired') {
      return $view;
    }

    $now = current_time('mysql');
    $user_id = get_current_user_id() ?: null;
    $table = $wpdb->prefix . 'cfm_views';
    $updated = $wpdb->update(
      $table,
      ['status' => 'retired', 'updated_by' => $user_id, 'updated_at' => $now],
      ['id' => absint($view_id)],
      ['%s', '%d', '%s'],
      ['%d']
    );

    if ($updated === false) {
      return new WP_Error('cfm_views_update_failed', 'Failed to retire View.', ['last_error' => $wpdb->last_error]);
    }

    self::audit($wpdb, 'view', absint($view_id), 'retire', (string) $view->status, 'retired', $now, $user_id);
    return self::get_view($view_id);
  }

  public static function restore_published_version($view_id, $version_id)
  {
    global $wpdb;

    $view = self::get_view($view_id);
    $version = self::get_version($version_id);
    if (!$view || !$version || (int) $version->view_id !== (int) $view_id) {
      return new WP_Error('cfm_views_not_found', 'View or View version was not found.');
    }

    if ((string) $version->status !== 'published') {
      return new WP_Error('cfm_views_invalid_restore', 'Only a published version can become the current restored version.');
    }

    $now = current_time('mysql');
    $user_id = get_current_user_id() ?: null;
    $table = $wpdb->prefix . 'cfm_views';
    $updated = $wpdb->update(
      $table,
      [
        'status' => 'published',
        'current_version_id' => absint($version_id),
        'updated_by' => $user_id,
        'updated_at' => $now,
      ],
      ['id' => absint($view_id)],
      ['%s', '%d', '%d', '%s'],
      ['%d']
    );

    if ($updated === false) {
      return new WP_Error('cfm_views_update_failed', 'Failed to restore published View version.', ['last_error' => $wpdb->last_error]);
    }

    self::audit($wpdb, 'view', absint($view_id), 'restore', (string) $view->status, 'published', $now, $user_id);
    return self::get_view($view_id);
  }

  public static function validate_version($version_id): array
  {
    global $wpdb;
    $version = self::get_version($version_id);
    if (!$version) {
      return ['version_id' => absint($version_id), 'state' => 'invalid', 'errors' => ['View version was not found.'], 'warnings' => []];
    }

    $errors = [];
    $warnings = [];
    $seen = [];
    $groups = self::groups_for_version($version->id);
    $group_ids = array_fill_keys(array_map(static function ($group) { return (string) $group->id; }, $groups), true);
    $entries = self::entries_for_version($version->id);

    if (!$entries) {
      $warnings[] = 'View version contains no entries.';
    }
    foreach ($entries as $entry) {
      $key = (string) $entry->core_terms_framework . ':' . (string) $entry->term_uuid . ':' . (string) $entry->inclusion;
      if (isset($seen[$key])) {
        $errors[] = "Duplicate View entry scope: {$key}.";
      }
      $seen[$key] = true;
      if (!in_array((string) $entry->inclusion, ['include', 'exclude'], true)) {
        $errors[] = "Invalid inclusion for entry {$entry->entry_uuid}.";
      }
      if ($entry->group_id !== null && $entry->group_id !== '' && !isset($group_ids[(string) $entry->group_id])) {
        $errors[] = "Entry {$entry->entry_uuid} references a missing group.";
      }
      $catalog = self::term_catalog((string) $entry->core_terms_framework);
      if (!$catalog['framework']) {
        $errors[] = "Core Terms framework is unavailable: {$entry->core_terms_framework}.";
      } elseif (!isset($catalog['terms'][(string) $entry->term_uuid])) {
        $errors[] = "Core Terms UUID is unavailable: {$entry->term_uuid}.";
      }
    }
    $state = $errors ? 'invalid' : ($warnings ? 'warning' : 'valid');
    if (in_array((string) $version->status, ['draft', 'review'], true)) {
      $wpdb->update($wpdb->prefix . 'cfm_view_versions', ['validation_state' => $state, 'updated_at' => current_time('mysql')], ['id' => (int) $version->id], ['%s', '%s'], ['%d']);
      foreach ($entries as $entry) {
        $wpdb->update($wpdb->prefix . 'cfm_view_entries', ['validation_state' => $state, 'validation_messages_json' => wp_json_encode(['errors' => $errors, 'warnings' => $warnings]), 'updated_at' => current_time('mysql')], ['id' => (int) $entry->id], ['%s', '%s', '%s'], ['%d']);
      }
    }
    return ['version_id' => (int) $version->id, 'state' => $state, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'entry_count' => count($entries)];
  }

  public static function resolve_version($version_id)
  {
    $version = self::get_version($version_id);
    if (!$version) {
      return new WP_Error('cfm_views_not_found', 'View version was not found.');
    }
    $validation = self::validate_version($version->id);
    if ($validation['state'] === 'invalid') {
      return new WP_Error('cfm_views_invalid_version', 'View version failed Core Terms validation.', $validation);
    }
    $view = self::get_view($version->view_id);
    $groups = self::groups_for_version($version->id);
    $resolved_groups = [];
    foreach ($groups as $group) {
      $resolved_groups[(int) $group->id] = ['group_id' => (int) $group->id, 'group_uuid' => (string) $group->group_uuid, 'group_key' => (string) $group->group_key, 'label' => (string) $group->label, 'display_order' => (int) $group->display_order, 'is_hidden' => (bool) $group->is_hidden, 'metadata' => self::decode_json($group->metadata_json), 'entries' => []];
    }
    $ungrouped = [];
    foreach (self::entries_for_version($version->id) as $entry) {
      $catalog = self::term_catalog((string) $entry->core_terms_framework);
      $uuids = CFM_Framework_Repository::get_descendant_uuids((int) $catalog['framework']->id, (string) $entry->term_uuid, (int) $catalog['framework']->active_version_id, true);
      if (!$entry->include_descendants) { $uuids = [(string) $entry->term_uuid]; }
      foreach ($uuids as $uuid) {
        $term = $catalog['terms'][(string) $uuid] ?? null;
        if (!$term) { continue; }
        $item = ['entry_id' => (int) $entry->id, 'entry_uuid' => (string) $entry->entry_uuid, 'term_uuid' => (string) $uuid, 'framework' => (string) $entry->core_terms_framework, 'label' => $entry->display_label ?: (string) ($term->name ?? $term->label ?? $uuid), 'display_order' => (int) $entry->display_order, 'is_featured' => (bool) $entry->is_featured, 'is_hidden' => (bool) $entry->is_hidden, 'parent_uuid' => (string) ($term->parent_uuid ?? ''), 'depth' => max(0, (int) ($term->depth ?? 0)), 'path' => (string) ($term->path ?? ''), 'metadata' => self::decode_json($entry->metadata_json), 'provenance' => ['source_entry_uuid' => (string) $entry->entry_uuid, 'source_term_uuid' => (string) $entry->term_uuid, 'expanded' => (string) $uuid !== (string) $entry->term_uuid]];
        if ((string) $entry->inclusion === 'exclude') { $ungrouped['exclude:' . $entry->core_terms_framework . ':' . $uuid] = $item; continue; }
        $ungrouped['include:' . $entry->core_terms_framework . ':' . $uuid] = $item;
      }
    }
    $excluded = [];
    foreach ($ungrouped as $key => $item) { if (str_starts_with($key, 'exclude:')) { $excluded[substr($key, 8)] = true; } }
    $flat = [];
    foreach ($ungrouped as $key => $item) { if (!str_starts_with($key, 'include:') || isset($excluded[$item['framework'] . ':' . $item['term_uuid']])) { continue; } $flat[] = $item; }
    usort($flat, static function ($a, $b) { return [$a['display_order'], $a['term_uuid']] <=> [$b['display_order'], $b['term_uuid']]; });
    foreach ($flat as $item) { $group_id = self::entry_group_id($item['entry_id'], $version->id); if ($group_id && isset($resolved_groups[$group_id])) { $item['group_id'] = $group_id; $resolved_groups[$group_id]['entries'][] = $item; } }
    return ['view' => ['view_id' => (int) $view->id, 'view_uuid' => (string) $view->view_uuid, 'name' => (string) $view->name, 'status' => (string) $view->status], 'version' => ['version_id' => (int) $version->id, 'version_uuid' => (string) $version->version_uuid, 'version_number' => (int) $version->version_number, 'status' => (string) $version->status], 'validation' => $validation, 'groups' => array_values($resolved_groups), 'entries' => $flat];
  }

  public static function preview_version($version_id)
  {
    $version = self::get_version($version_id);
    if (!$version || !in_array((string) $version->status, ['draft', 'review', 'published'], true)) {
      return new WP_Error('cfm_views_preview_unavailable', 'Only draft, review, or published versions can be previewed.');
    }
    return self::resolve_version($version->id);
  }

  public static function resolve_current_view($view_id)
  {
    $view = self::get_view($view_id);
    if (!$view || empty($view->current_version_id) || (string) $view->status !== 'published') {
      return new WP_Error('cfm_views_not_published', 'View does not have a published current version.');
    }
    return self::resolve_version((int) $view->current_version_id);
  }

  private static function entries_for_version($version_id): array { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE version_id = %d ORDER BY display_order ASC, id ASC', absint($version_id))) ?: []; }
  private static function groups_for_version($version_id): array { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'cfm_view_groups WHERE version_id = %d ORDER BY display_order ASC, id ASC', absint($version_id))) ?: []; }
  private static function term_catalog($framework_slug): array { $framework = CFM::get_framework($framework_slug); $terms = $framework ? CFM::get_terms($framework_slug) : []; $indexed = []; foreach ($terms as $term) { $indexed[(string) $term->term_uuid] = $term; } return ['framework' => $framework, 'terms' => $indexed]; }
  private static function entry_group_id($entry_id, $version_id) { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT group_id FROM ' . $wpdb->prefix . 'cfm_view_entries WHERE id = %d AND version_id = %d', absint($entry_id), absint($version_id))); }
  private static function decode_json($value): array { $decoded = json_decode((string) $value, true); return is_array($decoded) ? $decoded : []; }

  private static function transition_version($version_id, array $allowed_from, $to_status)
  {
    global $wpdb;

    $version = self::get_version($version_id);
    if (!$version) {
      return new WP_Error('cfm_views_not_found', 'View version was not found.');
    }

    if (!in_array((string) $version->status, $allowed_from, true)) {
      return new WP_Error('cfm_views_invalid_transition', 'View version lifecycle transition is not allowed.');
    }

    $now = current_time('mysql');
    $user_id = get_current_user_id() ?: null;
    $table = $wpdb->prefix . 'cfm_view_versions';
    $updated = $wpdb->update(
      $table,
      ['status' => $to_status, 'updated_at' => $now],
      ['id' => absint($version_id)],
      ['%s', '%s'],
      ['%d']
    );

    if ($updated === false) {
      return new WP_Error('cfm_views_update_failed', 'Failed to update View version lifecycle.', ['last_error' => $wpdb->last_error]);
    }

    self::audit($wpdb, 'view_version', absint($version_id), 'status_change', (string) $version->status, $to_status, $now, $user_id);
    return self::get_version($version_id);
  }

  private static function audit($wpdb, $target_type, $target_id, $action, $from_status, $to_status, $now, $user_id)
  {
    $table = $wpdb->prefix . 'cfm_view_audit';
    $wpdb->insert($table, [
      'audit_uuid' => wp_generate_uuid4(),
      'target_type' => $target_type,
      'target_id' => absint($target_id),
      'action' => $action,
      'from_status' => $from_status ?: null,
      'to_status' => $to_status ?: null,
      'actor_type' => $user_id ? 'human' : 'system',
      'actor_id' => $user_id,
      'created_at' => $now,
    ], ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s']);
  }
}
