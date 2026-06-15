<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Framework_Repository
{
  public static function create_framework(string $name, string $slug, string $description = ''): int
  {
    global $wpdb;

    $table = $wpdb->prefix . 'cfm_frameworks';
    $now   = current_time('mysql');

    $existing_id = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT id FROM {$table} WHERE slug = %s LIMIT 1",
        $slug
      )
    );

    if ($existing_id > 0) {
      return $existing_id;
    }

    $wpdb->insert(
      $table,
      [
        'framework_uuid'    => wp_generate_uuid4(),
        'name'              => $name,
        'slug'              => $slug,
        'description'       => $description,
        'active_version_id' => null,
        'created_at'        => $now,
        'updated_at'        => $now,
      ],
      [
        '%s',
        '%s',
        '%s',
        '%s',
        '%d',
        '%s',
        '%s',
      ]
    );

    return (int) $wpdb->insert_id;
  }

  public static function create_version(int $framework_id, array $tree, string $status = 'active'): int
  {
    global $wpdb;

    $versions_table   = $wpdb->prefix . 'cfm_framework_versions';
    $frameworks_table = $wpdb->prefix . 'cfm_frameworks';
    $now              = current_time('mysql');

    $next_version = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COALESCE(MAX(version_number), 0) + 1 FROM {$versions_table} WHERE framework_id = %d",
        $framework_id
      )
    );

    $wpdb->insert(
      $versions_table,
      [
        'framework_id'    => $framework_id,
        'version_number'  => $next_version,
        'tree_json'       => wp_json_encode(self::normalize_tree_for_storage($tree)),
        'status'          => $status,
        'compiled_at'     => null,
        'created_by'      => get_current_user_id() ?: null,
        'created_at'      => $now,
      ],
      [
        '%d',
        '%d',
        '%s',
        '%s',
        '%s',
        '%d',
        '%s',
      ]
    );

    $version_id = (int) $wpdb->insert_id;

    if ($status === 'active') {
      $wpdb->update(
        $frameworks_table,
        [
          'active_version_id' => $version_id,
          'updated_at'        => $now,
        ],
        ['id' => $framework_id],
        ['%d', '%s'],
        ['%d']
      );
    }

    return $version_id;
  }


  public static function save_active_version_tree(int $framework_id, array $tree): int
  {
    global $wpdb;

    $framework = self::get_framework($framework_id);

    if (!$framework) {
      return 0;
    }

    $active_version_id = !empty($framework->active_version_id) ? (int) $framework->active_version_id : 0;

    if ($active_version_id <= 0) {
      return self::create_version($framework_id, $tree, 'active');
    }

    $versions_table   = $wpdb->prefix . 'cfm_framework_versions';
    $frameworks_table = $wpdb->prefix . 'cfm_frameworks';
    $now              = current_time('mysql');

    $updated = $wpdb->update(
      $versions_table,
      [
        'tree_json'   => wp_json_encode(self::normalize_tree_for_storage($tree)),
        'status'      => 'active',
        'compiled_at' => null,
        'created_by'  => get_current_user_id() ?: null,
        'created_at'  => $now,
      ],
      [
        'id'           => $active_version_id,
        'framework_id' => $framework_id,
      ],
      [
        '%s',
        '%s',
        '%s',
        '%d',
        '%s',
      ],
      [
        '%d',
        '%d',
      ]
    );

    if ($updated === false) {
      return 0;
    }

    $wpdb->update(
      $frameworks_table,
      ['updated_at' => $now],
      ['id' => $framework_id],
      ['%s'],
      ['%d']
    );

    return $active_version_id;
  }

  public static function get_frameworks(): array
  {
    global $wpdb;

    $table = $wpdb->prefix . 'cfm_frameworks';

    $frameworks = $wpdb->get_results(
      "SELECT *
         FROM {$table}
         ORDER BY name ASC, id ASC"
    );

    return is_array($frameworks) ? $frameworks : [];
  }

  public static function get_framework(int $framework_id): ?object
  {
    global $wpdb;

    $table = $wpdb->prefix . 'cfm_frameworks';

    $framework = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
        $framework_id
      )
    );

    return $framework ?: null;
  }

  public static function get_framework_by_slug(string $slug): ?object
  {
    global $wpdb;

    if ($slug === '') {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_frameworks';

    $framework = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE slug = %s LIMIT 1",
        $slug
      )
    );

    return $framework ?: null;
  }

  public static function get_active_version(int $framework_id): ?object
  {
    global $wpdb;

    $framework = self::get_framework($framework_id);

    if (!$framework || empty($framework->active_version_id)) {
      return null;
    }

    $versions_table = $wpdb->prefix . 'cfm_framework_versions';

    $version = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT *
             FROM {$versions_table}
             WHERE id = %d
             AND framework_id = %d
             LIMIT 1",
        (int) $framework->active_version_id,
        $framework_id
      )
    );

    return $version ?: null;
  }

  public static function get_versions(int $framework_id, int $limit = 0, int $offset = 0): array
  {
    global $wpdb;

    $versions_table = $wpdb->prefix . 'cfm_framework_versions';
    $limit = max(0, $limit);
    $offset = max(0, $offset);

    if ($limit > 0) {
      $versions = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT *
             FROM {$versions_table}
             WHERE framework_id = %d
             ORDER BY version_number DESC
             LIMIT %d OFFSET %d",
          $framework_id,
          $limit,
          $offset
        )
      );
    } else {
      $versions = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT *
             FROM {$versions_table}
             WHERE framework_id = %d
             ORDER BY version_number DESC",
          $framework_id
        )
      );
    }

    return is_array($versions) ? $versions : [];
  }

  public static function count_versions(int $framework_id): int
  {
    global $wpdb;

    $versions_table = $wpdb->prefix . 'cfm_framework_versions';

    return (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*) FROM {$versions_table} WHERE framework_id = %d",
        $framework_id
      )
    );
  }

  public static function get_version(int $framework_id, int $version_id): ?object
  {
    global $wpdb;

    $versions_table = $wpdb->prefix . 'cfm_framework_versions';

    $version = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT *
             FROM {$versions_table}
             WHERE id = %d
             AND framework_id = %d
             LIMIT 1",
        $version_id,
        $framework_id
      )
    );

    return $version ?: null;
  }


  public static function mark_version_compiled(int $framework_id, int $version_id, string $compiled_at): bool
  {
    global $wpdb;

    $versions_table = $wpdb->prefix . 'cfm_framework_versions';

    $updated = $wpdb->update(
      $versions_table,
      ['compiled_at' => $compiled_at],
      [
        'id' => $version_id,
        'framework_id' => $framework_id,
      ],
      ['%s'],
      ['%d', '%d']
    );

    return $updated !== false;
  }

  public static function get_compiled_counts(int $framework_id, int $version_id): array
  {
    global $wpdb;

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';
    $closure_table = $wpdb->prefix . 'cfm_term_closure';

    return [
      'terms' => (int) $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(*) FROM {$terms_table} WHERE framework_id = %d AND version_id = %d",
          $framework_id,
          $version_id
        )
      ),
      'closure' => (int) $wpdb->get_var(
        $wpdb->prepare(
          "SELECT COUNT(*) FROM {$closure_table} WHERE framework_id = %d AND version_id = %d",
          $framework_id,
          $version_id
        )
      ),
    ];
  }


  public static function get_compiled_terms(int $framework_id, ?int $version_id = null): array
  {
    global $wpdb;

    $version_id = $version_id ?: self::get_active_version_id($framework_id);

    if ($version_id <= 0) {
      return [];
    }

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';

    $terms = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT *
           FROM {$terms_table}
           WHERE framework_id = %d
           AND version_id = %d
           ORDER BY depth ASC, path ASC",
        $framework_id,
        $version_id
      )
    );

    return is_array($terms) ? $terms : [];
  }

  public static function get_term_by_uuid(int $framework_id, string $term_uuid, ?int $version_id = null): ?object
  {
    global $wpdb;

    $version_id = $version_id ?: self::get_active_version_id($framework_id);

    if ($version_id <= 0 || $term_uuid === '') {
      return null;
    }

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';

    $term = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT *
           FROM {$terms_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND term_uuid = %s
           LIMIT 1",
        $framework_id,
        $version_id,
        $term_uuid
      )
    );

    return $term ?: null;
  }

  public static function get_term_by_slug(int $framework_id, string $slug, ?int $version_id = null): ?object
  {
    global $wpdb;

    $version_id = $version_id ?: self::get_active_version_id($framework_id);

    if ($version_id <= 0 || $slug === '') {
      return null;
    }

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';

    $term = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT *
           FROM {$terms_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND slug = %s
           LIMIT 1",
        $framework_id,
        $version_id,
        $slug
      )
    );

    return $term ?: null;
  }

  public static function get_descendant_uuids(int $framework_id, string $ancestor_uuid, ?int $version_id = null, bool $include_self = false): array
  {
    global $wpdb;

    $version_id = $version_id ?: self::get_active_version_id($framework_id);

    if ($version_id <= 0 || $ancestor_uuid === '') {
      return [];
    }

    $closure_table = $wpdb->prefix . 'cfm_term_closure';
    $depth_clause = $include_self ? '>= 0' : '> 0';

    $rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT descendant_term_uuid
           FROM {$closure_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND ancestor_term_uuid = %s
           AND depth {$depth_clause}
           ORDER BY depth ASC, descendant_term_uuid ASC",
        $framework_id,
        $version_id,
        $ancestor_uuid
      )
    );

    return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
  }

  public static function get_ancestor_uuids(int $framework_id, string $descendant_uuid, ?int $version_id = null, bool $include_self = false): array
  {
    global $wpdb;

    $version_id = $version_id ?: self::get_active_version_id($framework_id);

    if ($version_id <= 0 || $descendant_uuid === '') {
      return [];
    }

    $closure_table = $wpdb->prefix . 'cfm_term_closure';
    $depth_clause = $include_self ? '>= 0' : '> 0';

    $rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT ancestor_term_uuid
           FROM {$closure_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND descendant_term_uuid = %s
           AND depth {$depth_clause}
           ORDER BY depth DESC, ancestor_term_uuid ASC",
        $framework_id,
        $version_id,
        $descendant_uuid
      )
    );

    return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
  }

  public static function get_terms_by_uuids(int $framework_id, array $term_uuids, ?int $version_id = null): array
  {
    global $wpdb;

    $version_id = $version_id ?: self::get_active_version_id($framework_id);
    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if ($version_id <= 0 || empty($term_uuids)) {
      return [];
    }

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';
    $placeholders = implode(',', array_fill(0, count($term_uuids), '%s'));

    $params = array_merge([$framework_id, $version_id], $term_uuids);

    $terms = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT *
           FROM {$terms_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND term_uuid IN ({$placeholders})
           ORDER BY depth ASC, path ASC",
        ...$params
      )
    );

    return is_array($terms) ? $terms : [];
  }

  public static function get_sibling_terms(int $framework_id, string $term_uuid, ?int $version_id = null, bool $include_self = false): array
  {
    $term = self::get_term_by_uuid($framework_id, $term_uuid, $version_id);

    if (!$term) {
      return [];
    }

    global $wpdb;

    $version_id = $version_id ?: (int) $term->version_id;
    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';

    if ($term->parent_uuid === null || $term->parent_uuid === '') {
      $where_parent = 'parent_uuid IS NULL';
      $params = [$framework_id, $version_id];
    } else {
      $where_parent = 'parent_uuid = %s';
      $params = [$framework_id, $version_id, (string) $term->parent_uuid];
    }

    $self_clause = $include_self ? '' : 'AND term_uuid <> %s';

    if (!$include_self) {
      $params[] = $term_uuid;
    }

    $sql = "SELECT *
              FROM {$terms_table}
              WHERE framework_id = %d
              AND version_id = %d
              AND {$where_parent}
              {$self_clause}
              ORDER BY sort_order ASC, label ASC";

    $siblings = $wpdb->get_results($wpdb->prepare($sql, ...$params));

    return is_array($siblings) ? $siblings : [];
  }


  public static function get_user_term_uuids(int $user_id, int $framework_id, string $context = 'profile'): array
  {
    global $wpdb;

    $user_id = max(0, $user_id);
    $framework_id = max(0, $framework_id);
    $context = sanitize_key($context);

    if ($user_id <= 0 || $framework_id <= 0 || $context === '') {
      return [];
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';

    $rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT term_uuid
           FROM {$user_terms_table}
           WHERE user_id = %d
           AND framework_id = %d
           AND context = %s
           ORDER BY id ASC",
        $user_id,
        $framework_id,
        $context
      )
    );

    return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
  }

  public static function get_user_terms(int $user_id, int $framework_id, string $context = 'profile', ?int $version_id = null): array
  {
    $term_uuids = self::get_user_term_uuids($user_id, $framework_id, $context);

    if (empty($term_uuids)) {
      return [];
    }

    return self::get_terms_by_uuids($framework_id, $term_uuids, $version_id);
  }

  public static function set_user_terms(int $user_id, int $framework_id, array $term_uuids, string $context = 'profile'): bool
  {
    global $wpdb;

    $user_id = max(0, $user_id);
    $framework_id = max(0, $framework_id);
    $context = sanitize_key($context);

    if ($user_id <= 0 || $framework_id <= 0 || $context === '') {
      return false;
    }

    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    // Only store UUIDs that exist in the active compiled version.
    if (!empty($term_uuids)) {
      $valid_terms = self::get_terms_by_uuids($framework_id, $term_uuids);
      $term_uuids = array_values(array_unique(array_map(
        static fn($term): string => (string) $term->term_uuid,
        $valid_terms
      )));
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';
    $now = current_time('mysql');

    $wpdb->query('START TRANSACTION');

    $deleted = $wpdb->delete(
      $user_terms_table,
      [
        'user_id' => $user_id,
        'framework_id' => $framework_id,
        'context' => $context,
      ],
      ['%d', '%d', '%s']
    );

    if ($deleted === false) {
      $wpdb->query('ROLLBACK');
      return false;
    }

    foreach ($term_uuids as $term_uuid) {
      $inserted = $wpdb->insert(
        $user_terms_table,
        [
          'user_id' => $user_id,
          'framework_id' => $framework_id,
          'term_uuid' => $term_uuid,
          'context' => $context,
          'created_at' => $now,
        ],
        ['%d', '%d', '%s', '%s', '%s']
      );

      if ($inserted === false) {
        $wpdb->query('ROLLBACK');
        return false;
      }
    }

    $wpdb->query('COMMIT');

    return true;
  }

  public static function user_has_term(int $user_id, int $framework_id, string $term_uuid, string $context = 'profile'): bool
  {
    global $wpdb;

    $user_id = max(0, $user_id);
    $framework_id = max(0, $framework_id);
    $term_uuid = trim($term_uuid);
    $context = sanitize_key($context);

    if ($user_id <= 0 || $framework_id <= 0 || $term_uuid === '' || $context === '') {
      return false;
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';

    $found = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(*)
           FROM {$user_terms_table}
           WHERE user_id = %d
           AND framework_id = %d
           AND term_uuid = %s
           AND context = %s",
        $user_id,
        $framework_id,
        $term_uuid,
        $context
      )
    );

    return $found > 0;
  }

  public static function count_users_for_term(int $framework_id, string $term_uuid, string $context = 'profile', bool $include_descendants = true): int
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $term_uuid = trim($term_uuid);
    $context = sanitize_key($context);

    if ($framework_id <= 0 || $term_uuid === '' || $context === '') {
      return 0;
    }

    $term_uuids = [$term_uuid];

    if ($include_descendants) {
      $term_uuids = self::get_descendant_uuids($framework_id, $term_uuid, null, true);
    }

    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if (empty($term_uuids)) {
      return 0;
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';
    $placeholders = implode(',', array_fill(0, count($term_uuids), '%s'));

    $params = array_merge([$framework_id, $context], $term_uuids);

    return (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(DISTINCT user_id)
           FROM {$user_terms_table}
           WHERE framework_id = %d
           AND context = %s
           AND term_uuid IN ({$placeholders})",
        ...$params
      )
    );
  }

  public static function user_matches_any_term(int $user_id, int $framework_id, array $term_uuids, string $context = 'profile', bool $include_descendants = false): bool
  {
    $user_terms = self::get_user_term_uuids($user_id, $framework_id, $context);
    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if (empty($user_terms) || empty($term_uuids)) {
      return false;
    }

    if ($include_descendants) {
      $expanded = [];

      foreach ($term_uuids as $term_uuid) {
        $expanded = array_merge(
          $expanded,
          self::get_descendant_uuids($framework_id, $term_uuid, null, true)
        );
      }

      $term_uuids = array_values(array_unique(array_filter(array_map('strval', $expanded))));
    }

    return count(array_intersect($user_terms, $term_uuids)) > 0;
  }



  public static function normalize_tree_for_storage(array $tree): array
  {
    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $tree['children'] = self::normalize_tree_nodes_for_storage($tree['children']);

    if (isset($tree['meta_terms']) && is_array($tree['meta_terms'])) {
      $tree['meta_terms'] = self::normalize_tree_nodes_for_storage($tree['meta_terms']);
    }

    return $tree;
  }

  private static function normalize_tree_nodes_for_storage(array $nodes): array
  {
    $normalized = [];

    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }

      $normalized[] = self::normalize_tree_node_for_storage($node);
    }

    return $normalized;
  }

  private static function normalize_tree_node_for_storage(array $node): array
  {
    $kind = isset($node['kind']) ? sanitize_key((string) $node['kind']) : '';

    if ($kind !== 'term' && $kind !== 'meta') {
      $type = isset($node['type']) ? sanitize_key((string) $node['type']) : '';
      $kind = ($type === 'axis' || $type === 'term') ? 'term' : 'term';
    }

    unset($node['type']);

    $node['kind'] = $kind;

    if (!isset($node['children']) || !is_array($node['children'])) {
      $node['children'] = [];
    }

    if ($kind === 'meta') {
      $node['children'] = [];
      $node['includes'] = isset($node['includes']) && is_array($node['includes'])
        ? array_values(array_unique(array_filter(array_map('strval', $node['includes']))))
        : [];

      return $node;
    }

    $node['children'] = self::normalize_tree_nodes_for_storage($node['children']);

    if (isset($node['includes'])) {
      unset($node['includes']);
    }

    return $node;
  }


  private static function get_active_version_id(int $framework_id): int
  {
    $active_version = self::get_active_version($framework_id);

    return $active_version ? (int) $active_version->id : 0;
  }
}
