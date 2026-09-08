<?php

if (!defined('ABSPATH')) {
  exit;
}

/** Stable read contract for product consumers; consumers do not access tables. */
class CFM_Views_Service
{
  public static function get_current(int $view_id)
  {
    return CFM_Views_Repository::resolve_current_view($view_id);
  }

  public static function get_published_version(int $version_id)
  {
    $version = CFM_Views_Repository::get_version($version_id);
    if (!$version || (string) $version->status !== 'published') {
      return new WP_Error('cfm_views_not_published', 'Only a published View version is available to consumers.');
    }
    return CFM_Views_Repository::resolve_version($version->id);
  }

  /**
   * Additive published List contract for future consumers such as Community 3.
   * Consumers receive canonical identity/order and do not infer structure from
   * the underlying View tables.
   */
  public static function get_published_list(int $version_id)
  {
    $version = CFM_Views_Repository::get_version($version_id);
    if (!$version || (string) $version->status !== 'published') {
      return new WP_Error('cfm_views_not_published', 'Only a published List version is available to consumers.');
    }
    $view = CFM_Views_Repository::get_view((int) $version->view_id);
    if (!$view || CFM_Views_Repository::structure_for_view($view) !== CFM_Views_Repository::STRUCTURE_LIST) {
      return new WP_Error('cfm_views_not_list', 'The requested published version is not a List.');
    }
    $resolved = CFM_Views_Repository::resolve_version($version->id);
    if (is_wp_error($resolved)) {
      return $resolved;
    }
    $parent_label = '';
    if ((string) $version->parent_framework !== '' && (string) $version->parent_ref_key !== '') {
      $terms = CFM::get_terms((string) $version->parent_framework);
      foreach ($terms as $term) {
        if ((string) ($term->term_uuid ?? '') === (string) $version->parent_ref_key) {
          $parent_label = (string) ($term->label ?? $term->name ?? $version->parent_ref_key);
          break;
        }
      }
    }
    return [
      'view' => [
        'id' => (int) $view->id,
        'uuid' => (string) $view->view_uuid,
        'name' => (string) $view->name,
      ],
      'version' => [
        'id' => (int) $version->id,
        'uuid' => (string) $version->version_uuid,
        'number' => (int) $version->version_number,
        'published_at' => $version->published_at,
        'published_by' => $version->published_by !== null ? (int) $version->published_by : null,
      ],
      'structure_type' => CFM_Views_Repository::STRUCTURE_LIST,
      'role' => $version->role_key ?: null,
      'parent' => [
        'type' => (string) $version->parent_ref_type,
        'key' => (string) $version->parent_ref_key,
        'framework' => (string) $version->parent_framework,
        'label' => $parent_label,
      ],
      'members' => array_values(array_map(static function (array $entry): array {
        return [
          'term_uuid' => (string) $entry['term_uuid'],
          'framework' => (string) $entry['framework'],
          'label' => (string) $entry['label'],
          'display_order' => (int) $entry['display_order'],
        ];
      }, (array) ($resolved['entries'] ?? []))),
    ];
  }

  public static function preview(int $version_id)
  {
    return CFM_Views_Repository::preview_version($version_id);
  }
}
