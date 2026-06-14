<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Compiler
{
  public static function compile_version(int $framework_id, int $version_id): array
  {
    global $wpdb;

    $version = CFM_Framework_Repository::get_version($framework_id, $version_id);

    if (!$version) {
      return [
        'success' => false,
        'terms' => 0,
        'closure' => 0,
        'message' => 'Version not found.',
      ];
    }

    $tree = json_decode((string) $version->tree_json, true);

    if (!is_array($tree)) {
      return [
        'success' => false,
        'terms' => 0,
        'closure' => 0,
        'message' => 'Version tree_json could not be decoded.',
      ];
    }

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';
    $closure_table = $wpdb->prefix . 'cfm_term_closure';
    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');

    $wpdb->delete(
      $terms_table,
      [
        'framework_id' => $framework_id,
        'version_id' => $version_id,
      ],
      ['%d', '%d']
    );

    $wpdb->delete(
      $closure_table,
      [
        'framework_id' => $framework_id,
        'version_id' => $version_id,
      ],
      ['%d', '%d']
    );

    $counts = [
      'terms' => 0,
      'closure' => 0,
    ];

    $children = isset($tree['children']) && is_array($tree['children'])
      ? $tree['children']
      : [];

    foreach ($children as $sort_order => $child) {
      self::compile_node(
        $child,
        $framework_id,
        $version_id,
        null,
        null,
        0,
        [],
        [],
        (int) $sort_order,
        $now,
        $counts
      );
    }

    CFM_Framework_Repository::mark_version_compiled($framework_id, $version_id, $now);

    $wpdb->query('COMMIT');

    return [
      'success' => true,
      'terms' => $counts['terms'],
      'closure' => $counts['closure'],
      'message' => 'Compiled successfully.',
    ];
  }

  private static function compile_node(
    array $node,
    int $framework_id,
    int $version_id,
    ?string $parent_uuid,
    ?string $current_axis_uuid,
    int $depth,
    array $ancestor_uuids,
    array $path_parts,
    int $sort_order,
    string $now,
    array &$counts
  ): void {
    global $wpdb;

    $uuid = isset($node['uuid']) ? (string) $node['uuid'] : '';
    $label = isset($node['label']) ? (string) $node['label'] : '';
    $slug = isset($node['slug']) ? sanitize_title((string) $node['slug']) : '';
    $short_label = trim((string) ($node['short_label'] ?? ''));
    $description = trim((string) ($node['description'] ?? ''));

    if ($short_label === '') {
      $short_label = $label;
    }

    if ($description === '') {
      $description = $label;
    }
    $type = isset($node['type']) ? (string) $node['type'] : 'term';

    if ($uuid === '' || $label === '' || $slug === '') {
      return;
    }

    if ($type !== 'axis' && $type !== 'term') {
      return;
    }

    $axis_uuid = ($type === 'axis') ? $uuid : $current_axis_uuid;
    $path = implode('/', array_merge($path_parts, [$slug]));

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';
    $closure_table = $wpdb->prefix . 'cfm_term_closure';

    $wpdb->insert(
      $terms_table,
      [
        'framework_id' => $framework_id,
        'version_id' => $version_id,
        'term_uuid' => $uuid,
        'parent_uuid' => $parent_uuid,
        'axis_uuid' => $axis_uuid,
        'label' => $label,
        'short_label' => $short_label,
        'slug' => $slug,
        'description' => $description,
        'sort_order' => $sort_order,
        'depth' => $depth,
        'path' => $path,
        'visibility_contexts_json' => null,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
      ],
      [
        '%d',
        '%d',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%d',
        '%d',
        '%s',
        '%s',
        '%d',
        '%s',
        '%s'
      ]
    );

    $counts['terms']++;

    $all_ancestors = array_merge($ancestor_uuids, [$uuid]);
    $total_ancestors = count($all_ancestors);

    foreach ($all_ancestors as $index => $ancestor_uuid) {
      $closure_depth = $total_ancestors - $index - 1;

      $wpdb->insert(
        $closure_table,
        [
          'framework_id' => $framework_id,
          'version_id' => $version_id,
          'ancestor_term_uuid' => $ancestor_uuid,
          'descendant_term_uuid' => $uuid,
          'depth' => $closure_depth,
        ],
        ['%d', '%d', '%s', '%s', '%d']
      );

      $counts['closure']++;
    }

    $children = isset($node['children']) && is_array($node['children'])
      ? $node['children']
      : [];

    foreach ($children as $child_sort_order => $child) {
      if (!is_array($child)) {
        continue;
      }

      self::compile_node(
        $child,
        $framework_id,
        $version_id,
        $uuid,
        $axis_uuid,
        $depth + 1,
        $all_ancestors,
        array_merge($path_parts, [$slug]),
        (int) $child_sort_order,
        $now,
        $counts
      );
    }
  }
}
