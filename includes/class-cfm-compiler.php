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
        'relationships' => 0,
        'message' => 'Version not found.',
      ];
    }

    $tree = json_decode((string) $version->tree_json, true);

    if (!is_array($tree)) {
      return [
        'success' => false,
        'terms' => 0,
        'closure' => 0,
        'relationships' => 0,
        'message' => 'Version tree_json could not be decoded.',
      ];
    }

    /**
     * Fires before a Core Terms version is compiled.
     *
     * Consumers may observe this lifecycle event, but should not mutate Core Terms state here.
     *
     * @param int   $framework_id Profile taxonomy / Core Terms framework ID.
     * @param int   $version_id   Version being compiled.
     * @param array $tree         Decoded source tree for this compile pass.
     */
    do_action('cfm_before_compile', $framework_id, $version_id, $tree);

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

    $result = [
      'success' => true,
      'terms' => $counts['terms'],
      'closure' => $counts['closure'],
      'relationships' => $counts['relationships'],
      'message' => 'Compiled successfully.',
    ];

    /**
     * Fires after a Core Terms version has been compiled successfully.
     *
     * Consumers should use this hook for cache refreshes, search index updates,
     * or other follow-on work that depends on compiled Core Terms state.
     *
     * @param int   $framework_id Profile taxonomy / Core Terms framework ID.
     * @param int   $version_id   Version compiled.
     * @param array $result       Compile result summary.
     */
    do_action('cfm_after_compile', $framework_id, $version_id, $result);

    return $result;
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
    $kind = self::normalize_node_kind($node);

    if ($short_label === '') {
      $short_label = $label;
    }

    if ($description === '') {
      $description = $label;
    }

    if ($uuid === '' || $label === '' || $slug === '') {
      return;
    }

    // Terms and Meta-Groups both compile into cfm_terms_compiled for lookup consistency.
    // Meta-Groups remain non-assignable audience helpers; their included terms are compiled
    // separately as cfm_term_relationships rows with relationship_type=meta_includes.
    if ($kind !== 'term' && $kind !== 'meta') {
      return;
    }

    $is_meta = ($kind === 'meta');
    $axis_uuid = null;

    if (!$is_meta) {
      $axis_uuid = $current_axis_uuid;

      if ($axis_uuid === null && $parent_uuid === null) {
        $axis_uuid = $uuid;
      }
    }

    $path = implode('/', array_merge($path_parts, [$slug]));

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';
    $closure_table = $wpdb->prefix . 'cfm_term_closure';

    $wpdb->insert(
      $terms_table,
      [
        'framework_id' => $framework_id,
        'version_id' => $version_id,
        'term_uuid' => $uuid,
        'parent_uuid' => $is_meta ? null : $parent_uuid,
        'axis_uuid' => $axis_uuid,
        'kind' => $kind,
        'label' => $label,
        'short_label' => $short_label,
        'slug' => $slug,
        'description' => $description,
        'sort_order' => $sort_order,
        'depth' => $is_meta ? 0 : $depth,
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

    if ($is_meta) {
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

  private static function compile_meta_relationships(
    array $node,
    int $framework_id,
    int $version_id,
    string $meta_term_uuid,
    string $now,
    array &$counts
  ): void {
    global $wpdb;

    $includes = isset($node['includes']) && is_array($node['includes'])
      ? $node['includes']
      : [];

    $relationships_table = $wpdb->prefix . 'cfm_term_relationships';
    $seen = [];

    foreach ($includes as $sort_order => $included_uuid) {
      $included_uuid = trim((string) $included_uuid);

      if ($included_uuid === '' || $included_uuid === $meta_term_uuid) {
        continue;
      }

      if (isset($seen[$included_uuid])) {
        continue;
      }

      $seen[$included_uuid] = true;

      $inserted = $wpdb->insert(
        $relationships_table,
        [
          'framework_id' => $framework_id,
          'version_id' => $version_id,
          'source_term_uuid' => $meta_term_uuid,
          'target_term_uuid' => $included_uuid,
          'relationship_type' => 'meta_includes',
          'sort_order' => (int) $sort_order,
          'created_at' => $now,
        ],
        ['%d', '%d', '%s', '%s', '%s', '%d', '%s']
      );

      if ($inserted !== false) {
        $counts['relationships']++;
      }
    }
  }

  private static function normalize_node_kind(array $node): string
  {
    $kind = isset($node['kind']) ? sanitize_key((string) $node['kind']) : '';

    if ($kind === 'term' || $kind === 'meta') {
      return $kind;
    }

    $type = isset($node['type']) ? sanitize_key((string) $node['type']) : '';

    if ($type === 'axis' || $type === 'term') {
      return 'term';
    }

    return 'term';
  }
}
