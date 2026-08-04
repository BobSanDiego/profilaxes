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
}
