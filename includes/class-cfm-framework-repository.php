<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Framework_Repository
{
  /**
   * Meta-Group source-of-truth note.
   *
   * Current admin UI stores Meta-Groups inside the active tree_json as root-level nodes
   * with kind=meta and includes=[term UUIDs]. The cfm_meta_groups table helpers below are
   * retained as dormant experimental/legacy helpers only. Do not build new UI or audience
   * logic against these table methods unless a future migration deliberately changes the
   * canonical storage model.
   */
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


  /**
   * Return one compiled tree-based Meta-Group by slug or UUID.
   *
   * Returns stdClass|null. Invalid framework/version/input returns null.
   */
  public static function get_meta_group_by_slug_or_uuid(int $framework_id, string $slug_or_uuid, ?int $version_id = null): ?object
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $version_id = $version_id ?: self::get_active_version_id($framework_id);
    $slug_or_uuid = trim($slug_or_uuid);

    if ($framework_id <= 0 || $version_id <= 0 || $slug_or_uuid === '') {
      return null;
    }

    $terms_table = $wpdb->prefix . 'cfm_terms_compiled';

    if (wp_is_uuid($slug_or_uuid)) {
      $where = 'term_uuid = %s';
      $value = $slug_or_uuid;
    } else {
      $where = 'slug = %s';
      $value = sanitize_title($slug_or_uuid);
    }

    $meta_group = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT *
           FROM {$terms_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND kind = 'meta'
           AND {$where}
           LIMIT 1",
        $framework_id,
        $version_id,
        $value
      )
    );

    return $meta_group ?: null;
  }

  /**
   * Check whether a compiled tree-based Meta-Group exists.
   *
   * Repository-level helper for public CFM extension API. This intentionally reads
   * wp_cfm_terms_compiled kind=meta, not the dormant legacy cfm_meta_groups table.
   */
  public static function meta_group_exists(int $framework_id, string $slug_or_uuid, ?int $version_id = null): bool
  {
    return self::get_meta_group_by_slug_or_uuid($framework_id, $slug_or_uuid, $version_id) !== null;
  }

  /**
   * Return valid assignable Term UUIDs included by a compiled tree-based Meta-Group.
   *
   * Missing Meta-Group, stale UUIDs, and non-Term targets return/produce [].
   */
  public static function get_meta_group_included_term_uuids(int $framework_id, string $meta_group_slug_or_uuid, ?int $version_id = null): array
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $version_id = $version_id ?: self::get_active_version_id($framework_id);

    if ($framework_id <= 0 || $version_id <= 0) {
      return [];
    }

    $meta_group = self::get_meta_group_by_slug_or_uuid($framework_id, $meta_group_slug_or_uuid, $version_id);

    if (!$meta_group) {
      return [];
    }

    $relationships_table = $wpdb->prefix . 'cfm_term_relationships';

    $rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT target_term_uuid
           FROM {$relationships_table}
           WHERE framework_id = %d
           AND version_id = %d
           AND source_term_uuid = %s
           AND relationship_type = 'meta_includes'
           ORDER BY sort_order ASC, id ASC",
        $framework_id,
        $version_id,
        (string) $meta_group->term_uuid
      )
    );

    $term_uuids = is_array($rows) ? array_values(array_unique(array_filter(array_map('strval', $rows)))) : [];

    if (empty($term_uuids)) {
      return [];
    }

    // Defensive contract for extension consumers: stale relationship UUIDs are ignored.
    // Only currently compiled, assignable Terms are returned.
    $valid_term_uuids = [];

    foreach ($term_uuids as $term_uuid) {
      $term = self::get_term_by_uuid($framework_id, $term_uuid, $version_id);

      if ($term && (string) $term->kind === 'term') {
        $valid_term_uuids[] = $term_uuid;
      }
    }

    return array_values(array_unique($valid_term_uuids));
  }

  /**
   * Return user IDs matching assignable Term UUIDs.
   *
   * Inputs:
   * - $term_uuids: assignable Term UUIDs. Invalid, missing, or Meta-Group UUIDs are ignored.
   * - $operator: OR for any target, AND for every target. Unknown values fall back to OR.
   *
   * Returns int[] sorted user IDs. Never returns null.
   */
  public static function get_user_ids_for_term_uuids(int $framework_id, array $term_uuids, string $context = 'profile', string $operator = 'OR', bool $include_descendants = true): array
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $context = sanitize_key($context);
    $operator = strtoupper($operator);
    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if ($framework_id <= 0 || $context === '' || empty($term_uuids)) {
      return [];
    }

    if (!in_array($operator, ['AND', 'OR'], true)) {
      $operator = 'OR';
    }

    $candidate_uuids = [];

    foreach ($term_uuids as $term_uuid) {
      $term = self::get_term_by_uuid($framework_id, $term_uuid);

      if (!$term || (string) $term->kind !== 'term') {
        continue;
      }

      $group = [$term_uuid];

      if ($include_descendants) {
        $group = self::get_descendant_uuids($framework_id, $term_uuid, null, true);
      }

      $group = array_values(array_unique(array_filter(array_map('strval', $group))));

      if (!empty($group)) {
        $candidate_uuids[$term_uuid] = $group;
      }
    }

    if (empty($candidate_uuids)) {
      return [];
    }

    $all_matchable_uuids = array_values(array_unique(array_merge(...array_values($candidate_uuids))));

    if (empty($all_matchable_uuids)) {
      return [];
    }

    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';
    $placeholders = implode(',', array_fill(0, count($all_matchable_uuids), '%s'));
    $params = array_merge([$framework_id, $context], $all_matchable_uuids);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT DISTINCT user_id, term_uuid
           FROM {$user_terms_table}
           WHERE framework_id = %d
           AND context = %s
           AND term_uuid IN ({$placeholders})",
        ...$params
      )
    );

    if (!is_array($rows) || empty($rows)) {
      return [];
    }

    $matched_by_user = [];

    foreach ($rows as $row) {
      $user_id = (int) $row->user_id;
      $assigned_uuid = (string) $row->term_uuid;

      if ($user_id <= 0 || $assigned_uuid === '') {
        continue;
      }

      if (!isset($matched_by_user[$user_id])) {
        $matched_by_user[$user_id] = [];
      }

      foreach ($candidate_uuids as $target_uuid => $group) {
        if (in_array($assigned_uuid, $group, true)) {
          $matched_by_user[$user_id][$target_uuid] = true;
        }
      }
    }

    $required_count = count($candidate_uuids);
    $matched_user_ids = [];

    foreach ($matched_by_user as $user_id => $matched_targets) {
      $matched_count = count($matched_targets);

      if (($operator === 'OR' && $matched_count > 0) || ($operator === 'AND' && $matched_count === $required_count)) {
        $matched_user_ids[] = (int) $user_id;
      }
    }

    sort($matched_user_ids, SORT_NUMERIC);

    return $matched_user_ids;
  }

  /**
   * Return user IDs matching Terms included by a Meta-Group.
   *
   * Public API backing method. Missing/empty Meta-Groups return [].
   */
  public static function get_user_ids_for_meta_group(int $framework_id, string $meta_group_slug_or_uuid, string $context = 'profile', string $operator = 'OR', bool $include_descendants = true): array
  {
    $included_term_uuids = self::get_meta_group_included_term_uuids($framework_id, $meta_group_slug_or_uuid);

    if (empty($included_term_uuids)) {
      return [];
    }

    return self::get_user_ids_for_term_uuids($framework_id, $included_term_uuids, $context, $operator, $include_descendants);
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


  public static function create_term_archive(array $archive): int
  {
    global $wpdb;

    $framework_id = max(0, (int) ($archive['framework_id'] ?? 0));
    $archive_key = sanitize_key((string) ($archive['archive_key'] ?? ''));
    $root_term_uuid = sanitize_text_field((string) ($archive['root_term_uuid'] ?? ''));
    $parent_uuid = sanitize_text_field((string) ($archive['parent_uuid'] ?? ''));
    $insert_after_uuid = sanitize_text_field((string) ($archive['insert_after_uuid'] ?? ''));
    $branch = isset($archive['branch']) && is_array($archive['branch']) ? $archive['branch'] : [];

    if ($framework_id <= 0 || $archive_key === '' || $root_term_uuid === '' || empty($branch)) {
      return 0;
    }

    $table = $wpdb->prefix . 'cfm_term_archives';
    $archived_at = sanitize_text_field((string) ($archive['archived_at'] ?? ''));

    if ($archived_at === '') {
      $archived_at = current_time('mysql');
    }

    $inserted = $wpdb->insert(
      $table,
      [
        'archive_key' => $archive_key,
        'framework_id' => $framework_id,
        'root_term_uuid' => $root_term_uuid,
        'parent_uuid' => $parent_uuid !== '' ? $parent_uuid : null,
        'insert_after_uuid' => $insert_after_uuid !== '' ? $insert_after_uuid : null,
        'branch_json' => wp_json_encode(self::normalize_tree_for_storage($branch)),
        'archived_at' => $archived_at,
        'archived_by' => get_current_user_id() ?: null,
        'restored_at' => null,
        'restored_by' => null,
        'deleted_at' => null,
        'deleted_by' => null,
      ],
      [
        '%s',
        '%d',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%d',
        '%s',
        '%d',
        '%s',
        '%d',
      ]
    );

    return $inserted ? (int) $wpdb->insert_id : 0;
  }

  public static function get_term_archive_by_key(string $archive_key): ?object
  {
    global $wpdb;

    $archive_key = sanitize_key($archive_key);

    if ($archive_key === '') {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_term_archives';

    $archive = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE archive_key = %s LIMIT 1",
        $archive_key
      )
    );

    return $archive ?: null;
  }

  public static function get_term_archives(int $framework_id = 0, bool $include_deleted = false): array
  {
    global $wpdb;

    $table = $wpdb->prefix . 'cfm_term_archives';
    $where = [];
    $params = [];

    if ($framework_id > 0) {
      $where[] = 'framework_id = %d';
      $params[] = $framework_id;
    }

    if (!$include_deleted) {
      $where[] = 'deleted_at IS NULL';
    }

    $sql = "SELECT * FROM {$table}";

    if (!empty($where)) {
      $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY archived_at DESC, id DESC';

    $archives = !empty($params)
      ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
      : $wpdb->get_results($sql);

    return is_array($archives) ? $archives : [];
  }

  public static function mark_term_archive_restored(string $archive_key): bool
  {
    global $wpdb;

    $archive_key = sanitize_key($archive_key);

    if ($archive_key === '') {
      return false;
    }

    $table = $wpdb->prefix . 'cfm_term_archives';

    $updated = $wpdb->update(
      $table,
      [
        'restored_at' => current_time('mysql'),
        'restored_by' => get_current_user_id() ?: null,
      ],
      ['archive_key' => $archive_key],
      ['%s', '%d'],
      ['%s']
    );

    return $updated !== false;
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
    $previous_term_uuids = self::get_user_term_uuids($user_id, $framework_id, $context);

    /**
     * Fires before user term assignments are replaced.
     *
     * @param int    $user_id             WordPress user ID.
     * @param int    $framework_id        Profile taxonomy / Core Terms framework ID.
     * @param array  $term_uuids          Requested assignment UUIDs, before validation.
     * @param string $context             Assignment context.
     * @param array  $previous_term_uuids Existing assignment UUIDs.
     */
    do_action('cfm_before_user_terms_save', $user_id, $framework_id, $term_uuids, $context, $previous_term_uuids);

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

    /**
     * Fires after user term assignments have been replaced successfully.
     *
     * @param int    $user_id             WordPress user ID.
     * @param int    $framework_id        Profile taxonomy / Core Terms framework ID.
     * @param array  $term_uuids          Stored assignment UUIDs after validation.
     * @param string $context             Assignment context.
     * @param array  $previous_term_uuids Previous assignment UUIDs.
     */
    do_action('cfm_after_user_terms_save', $user_id, $framework_id, $term_uuids, $context, $previous_term_uuids);

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




  /**
   * Dormant table-based Meta-Group helper.
   *
   * Current source of truth is tree_json kind=meta nodes, not wp_cfm_meta_groups.
   */
  public static function create_meta_group(int $framework_id, string $label, string $slug, string $description = '', array $rules = [], string $status = 'active'): int
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $label = trim(wp_strip_all_tags($label));
    $slug = sanitize_title($slug);
    $description = wp_kses_post($description);
    $status = sanitize_key($status);

    if ($framework_id <= 0 || $label === '' || $slug === '') {
      return 0;
    }

    if ($status === '') {
      $status = 'active';
    }

    $existing = self::get_meta_group_by_slug($framework_id, $slug);

    if ($existing) {
      return (int) $existing->id;
    }

    $table = $wpdb->prefix . 'cfm_meta_groups';
    $now = current_time('mysql');

    $inserted = $wpdb->insert(
      $table,
      [
        'framework_id'     => $framework_id,
        'meta_group_uuid' => wp_generate_uuid4(),
        'label'           => $label,
        'slug'            => $slug,
        'description'     => $description,
        'rules_json'      => wp_json_encode(self::normalize_meta_group_rules($rules)),
        'status'          => $status,
        'created_at'      => $now,
        'updated_at'      => $now,
      ],
      [
        '%d',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
        '%s',
      ]
    );

    return $inserted === false ? 0 : (int) $wpdb->insert_id;
  }

  public static function get_meta_group(int $meta_group_id): ?object
  {
    global $wpdb;

    $meta_group_id = max(0, $meta_group_id);

    if ($meta_group_id <= 0) {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_meta_groups';

    $meta_group = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d LIMIT 1",
        $meta_group_id
      )
    );

    return $meta_group ?: null;
  }

  public static function get_meta_group_by_uuid(string $meta_group_uuid): ?object
  {
    global $wpdb;

    $meta_group_uuid = trim($meta_group_uuid);

    if ($meta_group_uuid === '') {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_meta_groups';

    $meta_group = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT * FROM {$table} WHERE meta_group_uuid = %s LIMIT 1",
        $meta_group_uuid
      )
    );

    return $meta_group ?: null;
  }

  public static function get_meta_group_by_slug(int $framework_id, string $slug): ?object
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $slug = sanitize_title($slug);

    if ($framework_id <= 0 || $slug === '') {
      return null;
    }

    $table = $wpdb->prefix . 'cfm_meta_groups';

    $meta_group = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT *
           FROM {$table}
           WHERE framework_id = %d
           AND slug = %s
           LIMIT 1",
        $framework_id,
        $slug
      )
    );

    return $meta_group ?: null;
  }

  public static function get_meta_groups(int $framework_id, string $status = 'active'): array
  {
    global $wpdb;

    $framework_id = max(0, $framework_id);
    $status = sanitize_key($status);

    if ($framework_id <= 0) {
      return [];
    }

    $table = $wpdb->prefix . 'cfm_meta_groups';

    if ($status === '' || $status === 'all') {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT *
             FROM {$table}
             WHERE framework_id = %d
             ORDER BY label ASC, id ASC",
          $framework_id
        )
      );
    } else {
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT *
             FROM {$table}
             WHERE framework_id = %d
             AND status = %s
             ORDER BY label ASC, id ASC",
          $framework_id,
          $status
        )
      );
    }

    return is_array($rows) ? $rows : [];
  }

  public static function update_meta_group(int $meta_group_id, array $data): bool
  {
    global $wpdb;

    $meta_group_id = max(0, $meta_group_id);

    if ($meta_group_id <= 0) {
      return false;
    }

    $allowed = [];
    $formats = [];

    if (array_key_exists('label', $data)) {
      $label = trim(wp_strip_all_tags((string) $data['label']));

      if ($label === '') {
        return false;
      }

      $allowed['label'] = $label;
      $formats[] = '%s';
    }

    if (array_key_exists('slug', $data)) {
      $slug = sanitize_title((string) $data['slug']);

      if ($slug === '') {
        return false;
      }

      $allowed['slug'] = $slug;
      $formats[] = '%s';
    }

    if (array_key_exists('description', $data)) {
      $allowed['description'] = wp_kses_post((string) $data['description']);
      $formats[] = '%s';
    }

    if (array_key_exists('rules', $data)) {
      $rules = is_array($data['rules']) ? $data['rules'] : [];
      $allowed['rules_json'] = wp_json_encode(self::normalize_meta_group_rules($rules));
      $formats[] = '%s';
    }

    if (array_key_exists('rules_json', $data)) {
      $decoded = json_decode((string) $data['rules_json'], true);
      $rules = is_array($decoded) ? $decoded : [];
      $allowed['rules_json'] = wp_json_encode(self::normalize_meta_group_rules($rules));
      $formats[] = '%s';
    }

    if (array_key_exists('status', $data)) {
      $status = sanitize_key((string) $data['status']);

      if ($status === '') {
        return false;
      }

      $allowed['status'] = $status;
      $formats[] = '%s';
    }

    if (empty($allowed)) {
      return false;
    }

    $allowed['updated_at'] = current_time('mysql');
    $formats[] = '%s';

    $table = $wpdb->prefix . 'cfm_meta_groups';

    $updated = $wpdb->update(
      $table,
      $allowed,
      ['id' => $meta_group_id],
      $formats,
      ['%d']
    );

    return $updated !== false;
  }

  public static function archive_meta_group(int $meta_group_id): bool
  {
    return self::update_meta_group(
      $meta_group_id,
      [
        'status' => 'archived',
      ]
    );
  }

  public static function delete_meta_group(int $meta_group_id): bool
  {
    global $wpdb;

    $meta_group_id = max(0, $meta_group_id);

    if ($meta_group_id <= 0) {
      return false;
    }

    $table = $wpdb->prefix . 'cfm_meta_groups';

    $deleted = $wpdb->delete(
      $table,
      ['id' => $meta_group_id],
      ['%d']
    );

    return $deleted !== false;
  }

  public static function normalize_meta_group_rules(array $rules): array
  {
    $logic = isset($rules['logic']) ? strtoupper(sanitize_key((string) $rules['logic'])) : 'OR';

    if ($logic !== 'AND' && $logic !== 'OR') {
      $logic = 'OR';
    }

    $normalized = [
      'version' => 1,
      'logic'   => $logic,
      'groups'  => [],
    ];

    $groups = [];

    if (isset($rules['groups']) && is_array($rules['groups'])) {
      $groups = $rules['groups'];
    } elseif (isset($rules['terms']) && is_array($rules['terms'])) {
      $groups = [
        [
          'logic' => $logic,
          'terms' => $rules['terms'],
        ],
      ];
    }

    foreach ($groups as $group) {
      if (!is_array($group)) {
        continue;
      }

      $group_logic = isset($group['logic']) ? strtoupper(sanitize_key((string) $group['logic'])) : 'OR';

      if ($group_logic !== 'AND' && $group_logic !== 'OR') {
        $group_logic = 'OR';
      }

      $terms = [];

      if (isset($group['terms']) && is_array($group['terms'])) {
        foreach ($group['terms'] as $term_rule) {
          if (is_string($term_rule)) {
            $term_rule = [
              'term_uuid' => $term_rule,
            ];
          }

          if (!is_array($term_rule)) {
            continue;
          }

          $term_uuid = isset($term_rule['term_uuid']) ? trim((string) $term_rule['term_uuid']) : '';

          if ($term_uuid === '') {
            continue;
          }

          $terms[] = [
            'term_uuid' => $term_uuid,
            'include_descendants' => array_key_exists('include_descendants', $term_rule)
              ? (bool) $term_rule['include_descendants']
              : true,
          ];
        }
      }

      if (!empty($terms)) {
        $normalized['groups'][] = [
          'logic' => $group_logic,
          'terms' => $terms,
        ];
      }
    }

    return $normalized;
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
