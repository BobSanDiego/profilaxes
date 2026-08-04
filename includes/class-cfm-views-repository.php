<?php

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Minimal persistence boundary for Durable Views.
 *
 * Lifecycle transitions, resolution, validation, and administration are
 * intentionally deferred to later tickets. Published content is not mutable
 * through this boundary.
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
    $version = self::get_version($version_id);
    if (!$version || !in_array((string) $version->status, ['published', 'deprecated'], true)) {
      return new WP_Error('cfm_views_invalid_source', 'Only published or deprecated versions can seed a new draft.');
    }

    return self::create_draft_version((int) $version->view_id, [
      'based_on_version_id' => (int) $version->id,
      'lineage_uuid' => (string) $version->lineage_uuid,
    ]);
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
