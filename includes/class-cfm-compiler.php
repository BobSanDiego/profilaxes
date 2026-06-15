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
    $relationships_table = $wpdb->prefix . 'cfm_term_relationships';
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

    $wpdb->delete(
      $relationships_table,
      [
        'framework_id' => $framework_id,
        'version_id' => $version_id,
      ],
      ['%d', '%d']
    );

    $counts = [
      'terms' => 0,
      'closure' => 0,
      'relationships' => 0,
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

    $meta_terms = isset($tree['meta_terms']) && is_array($tree['meta_terms'])
      ? $tree['meta_terms']
      : [];

    foreach ($meta_terms as $sort_order => $meta_term) {
      if (!is_array($meta_term)) {
        continue;
      }

      $meta_term['kind'] = 'meta';

      self::compile_node(
        $meta_term,
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
      'relationships' => $counts['relationships'],
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
    $kind = self::normalize_node_kind($node);

    if ($uuid === '' || $label === '' || $slug === '') {
      return;
    }

    if ($kind !== 'term' && $kind !== 'meta') {
      return;
    }

    $legacy_type = isset($node['type']) ? (string) $node['type'] : '';
    $axis_uuid = ($legacy_type === 'axis') ? $uuid : $current_axis_uuid;
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
        'kind' => $kind,
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

    if ($kind === 'meta') {
      self::compile_meta_relationships(
        $node,
        $framework_id,
        $version_id,
        $uuid,
        $now,
        $counts
      );

      return;
    }

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

  private static function normalize_node_kind(array $node): string
  {
    $kind = isset($node['kind']) ? sanitize_key((string) $node['kind']) : '';

    if ($kind === 'term' || $kind === 'meta') {
      return $kind;
    }

    $legacy_type = isset($node['type']) ? sanitize_key((string) $node['type']) : '';

    if ($legacy_type === 'axis' || $legacy_type === 'term') {
      return 'term';
    }

    return 'term';
  }

  private static function compile_meta_relationships(
    array $node,
    int $framework_id,
    int $version_id,
    string $source_term_uuid,
    string $now,
    array &$counts
  ): void {
    global $wpdb;

    $relationships_table = $wpdb->prefix . 'cfm_term_relationships';
    $includes = isset($node['includes']) && is_array($node['includes'])
      ? $node['includes']
      : [];

    $seen = [];

    foreach ($includes as $sort_order => $target_term_uuid) {
      $target_term_uuid = trim((string) $target_term_uuid);

      if ($target_term_uuid === '' || $target_term_uuid === $source_term_uuid || isset($seen[$target_term_uuid])) {
        continue;
      }

      $seen[$target_term_uuid] = true;

      $wpdb->insert(
        $relationships_table,
        [
          'framework_id' => $framework_id,
          'version_id' => $version_id,
          'source_term_uuid' => $source_term_uuid,
          'target_term_uuid' => $target_term_uuid,
          'relationship_type' => 'meta_includes',
          'sort_order' => (int) $sort_order,
          'created_at' => $now,
        ],
        ['%d', '%d', '%s', '%s', '%s', '%d', '%s']
      );

      $counts['relationships']++;
    }
  }
}
