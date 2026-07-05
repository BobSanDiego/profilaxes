<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM
{
  /**
   * Core Terms stable surface marker.
   *
   * Methods marked with this value are intended to become part of the
   * Core Terms beta contract after the v0.6.0 stabilization pass.
   */
  public const SURFACE_CORE_TERMS = 'core_terms';

  /**
   * Labs surface marker.
   *
   * Methods marked with this value are preserved diagnostic/incubator tools.
   * They are useful, but are not part of the frozen Core Terms v1 contract.
   */
  public const SURFACE_LABS = 'labs';

  public static function init(): void
  {
    add_action('init', [__CLASS__, 'maybe_upgrade_schema']);
    add_action('show_user_profile', [__CLASS__, 'render_user_profile_terms']);
    add_action('edit_user_profile', [__CLASS__, 'render_user_profile_terms']);
    add_action('admin_menu', [__CLASS__, 'register_assignment_admin_page']);
  }

  public static function maybe_upgrade_schema(): void
  {
    $required_schema_flags = [
      'cfm_schema_term_metadata_v1',
      'cfm_schema_meta_groups_v1',
      'cfm_schema_term_archives_v1',
    ];

    foreach ($required_schema_flags as $flag) {
      if (get_option($flag) !== '1') {
        CFM_Schema::install();
        update_option($flag, '1');
      }
    }
  }

  public static function get_framework(string $framework_slug): ?object
  {
    return CFM_Framework_Repository::get_framework_by_slug($framework_slug);
  }

  public static function get_terms(string $framework_slug): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    return CFM_Framework_Repository::get_compiled_terms((int) $framework->id);
  }

  public static function get_term_by_slug(string $framework_slug, string $term_slug): ?object
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return null;
    }

    return CFM_Framework_Repository::get_term_by_slug((int) $framework->id, $term_slug);
  }

  public static function get_descendants(string $framework_slug, string $term_slug, bool $include_self = false): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    $term = CFM_Framework_Repository::get_term_by_slug((int) $framework->id, $term_slug);

    if (!$term) {
      return [];
    }

    $uuids = CFM_Framework_Repository::get_descendant_uuids(
      (int) $framework->id,
      (string) $term->term_uuid,
      null,
      $include_self
    );

    return CFM_Framework_Repository::get_terms_by_uuids((int) $framework->id, $uuids);
  }

  public static function get_ancestors(string $framework_slug, string $term_slug, bool $include_self = false): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    $term = CFM_Framework_Repository::get_term_by_slug((int) $framework->id, $term_slug);

    if (!$term) {
      return [];
    }

    $uuids = CFM_Framework_Repository::get_ancestor_uuids(
      (int) $framework->id,
      (string) $term->term_uuid,
      null,
      $include_self
    );

    return CFM_Framework_Repository::get_terms_by_uuids((int) $framework->id, $uuids);
  }

  public static function get_siblings(string $framework_slug, string $term_slug, bool $include_self = false): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    $term = CFM_Framework_Repository::get_term_by_slug((int) $framework->id, $term_slug);

    if (!$term) {
      return [];
    }

    return CFM_Framework_Repository::get_sibling_terms(
      (int) $framework->id,
      (string) $term->term_uuid,
      null,
      $include_self
    );
  }

  public static function get_user_terms(int $user_id, string $framework_slug, string $context = 'profile'): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    return CFM_Framework_Repository::get_user_terms($user_id, (int) $framework->id, $context);
  }

  public static function get_user_effective_terms(int $user_id, string $framework_slug, string $context = 'profile'): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    $assigned_uuids = CFM_Framework_Repository::get_user_term_uuids($user_id, (int) $framework->id, $context);

    if (empty($assigned_uuids)) {
      return [];
    }

    $effective_uuids = [];

    foreach ($assigned_uuids as $assigned_uuid) {
      $assigned_uuid = (string) $assigned_uuid;

      if ($assigned_uuid === '') {
        continue;
      }

      $effective_uuids[] = $assigned_uuid;

      $ancestor_uuids = CFM_Framework_Repository::get_ancestor_uuids(
        (int) $framework->id,
        $assigned_uuid,
        null,
        false
      );

      foreach ($ancestor_uuids as $ancestor_uuid) {
        $effective_uuids[] = (string) $ancestor_uuid;
      }
    }

    $effective_uuids = array_values(array_unique(array_filter($effective_uuids)));

    return self::order_terms_as_tree(
      CFM_Framework_Repository::get_terms_by_uuids((int) $framework->id, $effective_uuids)
    );
  }

  public static function get_user_term_uuids(int $user_id, string $framework_slug, string $context = 'profile'): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    return CFM_Framework_Repository::get_user_term_uuids($user_id, (int) $framework->id, $context);
  }

  public static function set_user_terms(int $user_id, string $framework_slug, array $term_uuids, string $context = 'profile'): bool
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return false;
    }

    return CFM_Framework_Repository::set_user_terms($user_id, (int) $framework->id, $term_uuids, $context);
  }

  public static function user_has_term(int $user_id, string $framework_slug, string $term_slug_or_uuid, string $context = 'profile', bool $effective = true): bool
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return false;
    }

    $term_uuid = self::resolve_term_uuid((int) $framework->id, $term_slug_or_uuid);

    if ($term_uuid === '') {
      return false;
    }

    if (!$effective) {
      return CFM_Framework_Repository::user_has_term($user_id, (int) $framework->id, $term_uuid, $context);
    }

    $effective_terms = self::get_user_effective_terms_by_framework_id($user_id, (int) $framework->id, $context);

    foreach ($effective_terms as $effective_term) {
      if ((string) $effective_term->term_uuid === $term_uuid) {
        return true;
      }
    }

    return false;
  }

  public static function count_users(string $framework_slug, string $term_slug_or_uuid, string $context = 'profile', bool $include_descendants = true): int
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return 0;
    }

    $term_uuid = self::resolve_term_uuid((int) $framework->id, $term_slug_or_uuid);

    if ($term_uuid === '') {
      return 0;
    }

    return CFM_Framework_Repository::count_users_for_term(
      (int) $framework->id,
      $term_uuid,
      $context,
      $include_descendants
    );
  }


  /**
   * Return user IDs assigned to a Term.
   *
   * Public extension helper for Jobs, Chatboards, Lessons, and future modules.
   * Example extension guard:
   *   if (class_exists('CFM')) { $user_ids = CFM::get_users_assigned_to_term('profiles', 'math'); }
   *
   * Inputs:
   * - $framework_slug: framework slug, usually 'profiles'.
   * - $term_slug_or_uuid: assignable Term slug or UUID. Meta-Groups are intentionally ignored.
   * - $context: assignment context; default is 'profile'.
   * - $include_descendants: when true, users assigned to descendant Terms are included.
   *
   * Returns:
   * - int[] sorted user IDs.
   * - [] when framework, Term, or assignments are missing.
   */
  public static function get_users_assigned_to_term(string $framework_slug, string $term_slug_or_uuid, string $context = 'profile', bool $include_descendants = true): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    $term_uuid = self::resolve_assignable_term_uuid((int) $framework->id, $term_slug_or_uuid);

    if ($term_uuid === '') {
      return [];
    }

    return CFM_Framework_Repository::get_user_ids_for_term_uuids(
      (int) $framework->id,
      [$term_uuid],
      $context,
      'OR',
      $include_descendants
    );
  }

  /**
   * Return one audience-only Meta-Group by slug or UUID.
   *
   * Meta-Groups are tree-stored compiled nodes with kind=meta. They are collections of
   * existing Terms and are not directly assignable to users.
   *
   * Returns:
   * - stdClass|null compiled Meta-Group row.
   * - null when framework or Meta-Group is missing.
   */
  public static function get_meta_group(string $framework_slug, string $meta_group_slug_or_uuid): ?object
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return null;
    }

    return CFM_Framework_Repository::get_meta_group_by_slug_or_uuid((int) $framework->id, $meta_group_slug_or_uuid);
  }

  /**
   * Check whether an audience-only Meta-Group exists.
   */
  public static function meta_group_exists(string $framework_slug, string $meta_group_slug_or_uuid): bool
  {
    return self::get_meta_group($framework_slug, $meta_group_slug_or_uuid) !== null;
  }

  /**
   * Return assignable Term UUIDs included by a Meta-Group.
   *
   * Returns:
   * - string[] Term UUIDs in stored relationship order.
   * - [] when framework or Meta-Group is missing, or when no valid Terms are included.
   */
  public static function get_meta_group_included_term_uuids(string $framework_slug, string $meta_group_slug_or_uuid): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    return CFM_Framework_Repository::get_meta_group_included_term_uuids(
      (int) $framework->id,
      $meta_group_slug_or_uuid
    );
  }

  /**
   * Return users matching ANY Term included by a Meta-Group.
   *
   * Returns int[] sorted user IDs. Missing/empty Meta-Groups return [].
   */
  public static function get_users_matching_meta_group_any(string $framework_slug, string $meta_group_slug_or_uuid, string $context = 'profile', bool $include_descendants = true): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    return CFM_Framework_Repository::get_user_ids_for_meta_group(
      (int) $framework->id,
      $meta_group_slug_or_uuid,
      $context,
      'OR',
      $include_descendants
    );
  }

  /**
   * Return users matching ALL Terms included by a Meta-Group.
   *
   * Each included Term is treated as one required match target. When descendant matching
   * is enabled, assigning a descendant satisfies that Term target.
   * Returns int[] sorted user IDs. Missing/empty Meta-Groups return [].
   */
  public static function get_users_matching_meta_group_all(string $framework_slug, string $meta_group_slug_or_uuid, string $context = 'profile', bool $include_descendants = true): array
  {
    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    return CFM_Framework_Repository::get_user_ids_for_meta_group(
      (int) $framework->id,
      $meta_group_slug_or_uuid,
      $context,
      'AND',
      $include_descendants
    );
  }


  /**
   * LABS / EXPERIMENTAL: resolve a generic audience to matching user IDs.
   *
   * This is a preserved incubator utility. It is useful for diagnostics,
   * future Jobs targeting, newsletters, discovery tools, and other consumers,
   * but it is not part of the frozen Core Terms v1 public contract yet.
   *
   * Consumers may experiment with this method, but should not treat its
   * argument shape or query semantics as permanent until it graduates from Labs.
   *
   * Current behavior:
   * - Consumers pass an audience definition, not consumer-specific state.
   * - Profilaxes resolves users only; consumers own jobs, posts, listings, boards, messages, and notifications.
   * - Terms and Meta-Groups are combined into one target set.
   * - Match mode is ANY/ALL through operator values OR/AND.
   * - Missing frameworks, empty audiences, missing Meta-Groups, and stale UUIDs return [].
   *
   * Expected audience shape:
   * [
   *   'framework' => 'teachers-net',
   *   'terms' => ['math', 'grade-3-5'],
   *   'meta_groups' => ['common-core-states'],
   *   'operator' => 'AND', // or 'OR', 'ALL', 'ANY'
   *   'context' => 'profile',
   *   'include_descendants' => true,
   *   'limit' => 0,
   *   'offset' => 0,
   * ]
   *
   * Extension usage:
   * if (class_exists('CFM')) {
   *   $user_ids = CFM::resolve_users($audience);
   * }
   *
   * @param array $audience Audience definition owned by the consumer plugin.
   * @return int[] Sorted matching WordPress user IDs.
   */
  public static function resolve_users(array $audience): array
  {
    $framework_slug = isset($audience['framework'])
      ? sanitize_title((string) $audience['framework'])
      : 'teachers-net';

    $framework = self::get_framework($framework_slug);

    if (!$framework) {
      return [];
    }

    $term_inputs = isset($audience['terms']) && is_array($audience['terms'])
      ? $audience['terms']
      : [];
    $meta_group_inputs = isset($audience['meta_groups']) && is_array($audience['meta_groups'])
      ? $audience['meta_groups']
      : [];

    $term_uuids = self::resolve_term_uuids((int) $framework->id, $term_inputs);

    foreach ($meta_group_inputs as $meta_group_slug_or_uuid) {
      $included_term_uuids = CFM_Framework_Repository::get_meta_group_included_term_uuids(
        (int) $framework->id,
        (string) $meta_group_slug_or_uuid
      );

      if (!empty($included_term_uuids)) {
        $term_uuids = array_merge($term_uuids, $included_term_uuids);
      }
    }

    $term_uuids = array_values(array_unique(array_filter(array_map('strval', $term_uuids))));

    if (empty($term_uuids)) {
      return [];
    }

    $operator = isset($audience['operator']) ? strtoupper((string) $audience['operator']) : 'AND';

    if ($operator === 'ANY') {
      $operator = 'OR';
    } elseif ($operator === 'ALL') {
      $operator = 'AND';
    }

    if (!in_array($operator, ['AND', 'OR'], true)) {
      $operator = 'AND';
    }

    $context = isset($audience['context']) ? sanitize_key((string) $audience['context']) : 'profile';

    if ($context === '') {
      return [];
    }

    return self::find_users([
      'framework' => $framework_slug,
      'terms' => $term_uuids,
      'operator' => $operator,
      'context' => $context,
      'include_descendants' => array_key_exists('include_descendants', $audience)
        ? (bool) $audience['include_descendants']
        : true,
      'fields' => 'ids',
      'limit' => isset($audience['limit']) ? max(0, (int) $audience['limit']) : 0,
      'offset' => isset($audience['offset']) ? max(0, (int) $audience['offset']) : 0,
    ]);
  }

  /**
   * LABS / EXPERIMENTAL: match assigned/effective terms against target terms.
   *
   * Preserved for diagnostics and future consumer experiments. This overloaded
   * helper is intentionally not frozen as part of the Core Terms v1 contract.
   */
  public static function matches(...$args): bool
  {
    // Backward-compatible v0.1.x signature:
    // CFM::matches($user_id, $framework_slug, $term_slugs_or_uuids, $context, $include_descendants)
    if (isset($args[0]) && is_int($args[0])) {
      $user_id = (int) $args[0];
      $framework_slug = isset($args[1]) ? (string) $args[1] : '';
      $term_slugs_or_uuids = isset($args[2]) && is_array($args[2]) ? $args[2] : [];
      $context = isset($args[3]) ? (string) $args[3] : 'profile';
      $include_descendants = isset($args[4]) ? (bool) $args[4] : true;

      $framework = self::get_framework($framework_slug);

      if (!$framework) {
        return false;
      }

      $term_uuids = self::resolve_term_uuids((int) $framework->id, $term_slugs_or_uuids);

      return CFM_Framework_Repository::user_matches_any_term(
        $user_id,
        (int) $framework->id,
        $term_uuids,
        $context,
        $include_descendants
      );
    }

    // v0.2.0 audience matcher signature:
    // CFM::matches($user_terms, $target_terms, $operator = 'AND')
    $user_terms = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
    $target_terms = isset($args[1]) && is_array($args[1]) ? $args[1] : [];
    $operator = isset($args[2]) ? strtoupper((string) $args[2]) : 'AND';

    return self::term_arrays_match($user_terms, $target_terms, $operator);
  }

  /**
   * LABS / EXPERIMENTAL: find users by assigned/effective Core Terms.
   *
   * This is a valuable query engine for audience diagnostics and future
   * consumers. It remains available, but its exact query shape is not frozen
   * until a consumer validates the contract.
   */
  public static function find_users($args = [], string $operator = 'AND'): array
  {
    global $wpdb;

    $defaults = [
      'framework' => 'teachers-net',
      'terms' => [],
      'operator' => $operator,
      'context' => 'profile',
      'include_descendants' => true,
      'fields' => 'ids',
      'limit' => 0,
      'offset' => 0,
    ];

    if (is_array($args) && isset($args['terms'])) {
      $query = array_merge($defaults, $args);
    } elseif (is_array($args)) {
      $query = array_merge($defaults, ['terms' => $args]);
    } else {
      $query = $defaults;
    }

    $framework_slug = sanitize_title((string) $query['framework']);
    $context = sanitize_key((string) $query['context']);
    $match_operator = strtoupper((string) $query['operator']);
    $include_descendants = (bool) $query['include_descendants'];
    $fields = (string) $query['fields'];
    $limit = max(0, (int) $query['limit']);
    $offset = max(0, (int) $query['offset']);

    if (!in_array($match_operator, ['AND', 'OR'], true)) {
      $match_operator = 'AND';
    }

    $framework = self::get_framework($framework_slug);

    if (!$framework || $context === '') {
      return [];
    }

    $target_uuids = self::resolve_term_uuids((int) $framework->id, (array) $query['terms']);

    if (empty($target_uuids)) {
      return [];
    }

    $candidate_uuids = [];

    foreach ($target_uuids as $target_uuid) {
      $group = [$target_uuid];

      if ($include_descendants) {
        $group = CFM_Framework_Repository::get_descendant_uuids(
          (int) $framework->id,
          $target_uuid,
          null,
          true
        );
      }

      $group = array_values(array_unique(array_filter(array_map('strval', $group))));

      if (!empty($group)) {
        $candidate_uuids[$target_uuid] = $group;
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
    $params = array_merge([(int) $framework->id, $context], $all_matchable_uuids);

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

      if (($match_operator === 'OR' && $matched_count > 0) || ($match_operator === 'AND' && $matched_count === $required_count)) {
        $matched_user_ids[] = (int) $user_id;
      }
    }

    sort($matched_user_ids, SORT_NUMERIC);

    if ($offset > 0 || $limit > 0) {
      $matched_user_ids = array_slice($matched_user_ids, $offset, $limit > 0 ? $limit : null);
    }

    if ($fields === 'users') {
      return array_values(array_filter(array_map('get_userdata', $matched_user_ids)));
    }

    return $matched_user_ids;
  }

  public static function register_assignment_admin_page(): void
  {
    add_users_page(
      'Assign Terms',
      'Assign Terms',
      'list_users',
      'cfm-framework-assignments',
      [__CLASS__, 'render_assignment_admin_page']
    );

    add_users_page(
      'Labs: Inspect User Terms',
      'Labs: Inspect User Terms',
      'list_users',
      'cfm-segmentation-tests',
      [__CLASS__, 'render_segmentation_tests_admin_page']
    );

    add_users_page(
      'Labs: Audience Explorer',
      'Labs: Audience Explorer',
      'list_users',
      'cfm-audience-engine',
      [__CLASS__, 'render_audience_engine_admin_page']
    );

    add_users_page(
      'Labs: Term Statistics',
      'Labs: Term Statistics',
      'list_users',
      'cfm-profile-statistics',
      [__CLASS__, 'render_profile_statistics_admin_page']
    );
  }

  public static function render_audience_engine_admin_page(): void
  {
    if (!current_user_can('list_users')) {
      wp_die('You do not have permission to find audiences.');
    }

    $frameworks = self::get_profile_frameworks();

    echo '<div class="wrap">';
    echo '<h1>Labs: Audience Explorer</h1>';
    echo '<p><strong>Labs tool:</strong> Experimental tools for diagnostics and future extensions.</p>';
    echo '<p>Find users who match one or more terms, including inherited parent/child meaning.</p>';

    if (empty($frameworks)) {
      echo '<div class="notice notice-warning inline"><p>No terms available yet. Create or install terms and they will become available automatically.</p></div>';
      echo '</div>';
      return;
    }

    $selected_framework_id = isset($_REQUEST['framework_id']) ? absint($_REQUEST['framework_id']) : (int) $frameworks[0]->id;

    if (!self::framework_id_exists($frameworks, $selected_framework_id)) {
      $selected_framework_id = (int) $frameworks[0]->id;
    }

    $selected_framework = CFM_Framework_Repository::get_framework($selected_framework_id);
    $framework_slug = $selected_framework ? (string) $selected_framework->slug : '';
    $terms_query_raw = isset($_REQUEST['terms']) ? sanitize_text_field(wp_unslash($_REQUEST['terms'])) : '';
    $operator = isset($_REQUEST['operator']) ? strtoupper(sanitize_key(wp_unslash($_REQUEST['operator']))) : 'AND';
    $limit = isset($_REQUEST['limit']) ? max(1, min(200, absint($_REQUEST['limit']))) : 50;

    if (!in_array($operator, ['AND', 'OR'], true)) {
      $operator = 'AND';
    }

    $target_terms = self::parse_term_query_list($terms_query_raw);

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="cfm-audience-engine" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<input type="hidden" name="framework_id" value="' . esc_attr((string) $selected_framework_id) . '" />';

    echo '<tr><th scope="row"><label for="cfm_audience_terms">Audience terms</label></th><td>';
    echo '<input type="text" id="cfm_audience_terms" name="terms" value="' . esc_attr($terms_query_raw) . '" class="regular-text" placeholder="Example: elementary, math" />';
    echo '<p class="description">Enter term names or slugs separated by commas or spaces. Example: <code>elementary, math</code>. Matching includes descendants, so <code>elementary</code> includes users assigned child terms such as <code>grade-1</code>.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="cfm_audience_operator">Operator</label></th><td>';
    echo '<select id="cfm_audience_operator" name="operator">';
    echo '<option value="AND" ' . selected($operator, 'AND', false) . '>AND — user must match every term</option>';
    echo '<option value="OR" ' . selected($operator, 'OR', false) . '>OR — user may match any term</option>';
    echo '</select></td></tr>';

    echo '<tr><th scope="row"><label for="cfm_audience_limit">Limit</label></th><td>';
    echo '<input type="number" id="cfm_audience_limit" name="limit" min="1" max="200" value="' . esc_attr((string) $limit) . '" class="small-text" />';
    echo '<p class="description">Maximum users to display. Results are limited for readability.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';
    submit_button('Find Users', 'primary', '', false);
    echo ' <a class="button" href="' . esc_url(admin_url('users.php?page=cfm-audience-engine')) . '">Clear</a>';
    echo '</form>';

    echo '<hr />';
    echo '<h2>Matching Preview</h2>';
    echo '<pre style="max-width:760px;background:#fff;border:1px solid #ccd0d4;padding:12px;overflow:auto;">' . esc_html(self::format_audience_query_example($framework_slug, $target_terms, $operator, $limit)) . '</pre>';

    if (!$selected_framework) {
      echo '<div class="notice notice-error inline"><p>Invalid Core Terms definition.</p></div>';
      echo '</div>';
      return;
    }

    if (empty($target_terms)) {
      echo '<p>Enter one or more audience terms above, then run the query.</p>';
      echo '</div>';
      return;
    }

    $resolved_terms = [];
    $missing_terms = [];

    foreach ($target_terms as $target_term) {
      $term_uuid = self::resolve_term_uuid((int) $selected_framework->id, $target_term);

      if ($term_uuid === '') {
        $missing_terms[] = $target_term;
        continue;
      }

      $term = CFM_Framework_Repository::get_term_by_uuid((int) $selected_framework->id, $term_uuid);

      if ($term) {
        $resolved_terms[] = $term;
      }
    }

    if (!empty($missing_terms)) {
      echo '<div class="notice notice-warning inline"><p>Unresolved term(s): <code>' . esc_html(implode('</code>, <code>', $missing_terms)) . '</code>. These terms are ignored by the helper.</p></div>';
    }

    echo '<h2>Term-level Matches</h2>';

    if (empty($resolved_terms)) {
      echo '<p>No valid terms were resolved.</p>';
      echo '</div>';
      return;
    }

    echo '<table class="widefat striped" style="max-width:960px;"><thead><tr><th>Label</th><th>Slug</th><th>UUID</th><th>Users Matching This Term</th></tr></thead><tbody>';

    foreach ($resolved_terms as $term) {
      $matched_count = self::count_users($framework_slug, (string) $term->slug, 'profile', true);
      echo '<tr>';
      echo '<td>' . esc_html((string) $term->label) . '</td>';
      echo '<td><code>' . esc_html((string) $term->slug) . '</code></td>';
      echo '<td><code>' . esc_html((string) $term->term_uuid) . '</code></td>';
      echo '<td>' . esc_html((string) $matched_count) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';

    $matched_user_ids = self::find_users([
      'framework' => $framework_slug,
      'terms' => $target_terms,
      'operator' => $operator,
      'context' => 'profile',
      'include_descendants' => true,
      'fields' => 'ids',
      'limit' => $limit,
    ]);

    echo '<h2>Final Audience Match</h2>';
    echo '<p><strong>Users matching the complete audience definition:</strong> ' . esc_html((string) count($matched_user_ids)) . '</p>';

    if (empty($matched_user_ids)) {
      echo '<p>No users matched this audience definition.</p>';
      echo '</div>';
      return;
    }

    $all_terms = self::order_terms_as_tree(CFM_Framework_Repository::get_compiled_terms((int) $selected_framework->id));
    $terms_by_uuid = self::index_terms_by_uuid($all_terms);

    echo '<table class="widefat striped" style="max-width:960px;"><thead><tr><th>User</th><th>Email</th><th>Assigned Terms</th><th>Inherited Audience Terms</th></tr></thead><tbody>';

    foreach ($matched_user_ids as $user_id) {
      $user = get_userdata((int) $user_id);

      if (!$user) {
        continue;
      }

      $assigned_terms = self::get_user_terms((int) $user_id, $framework_slug);
      $effective_terms = self::get_user_effective_terms((int) $user_id, $framework_slug);
      $edit_url = get_edit_user_link((int) $user_id);
      $user_label = $user->display_name ?: $user->user_login;

      echo '<tr>';
      echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html($user_label) . '</a><br /><span class="description">ID: ' . esc_html((string) $user_id) . ' / ' . esc_html($user->user_login) . '</span></td>';
      echo '<td>' . esc_html($user->user_email) . '</td>';
      echo '<td>' . esc_html(self::format_term_breadcrumbs($assigned_terms, $terms_by_uuid)) . '</td>';
      echo '<td>' . esc_html(self::format_term_breadcrumbs($effective_terms, $terms_by_uuid)) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p class="description">Read-only audience computation. Assignment edits remain under Users → Assign Terms.</p>';
    echo '</div>';
  }

  public static function render_user_profile_terms(object $user): void
  {
    if (!current_user_can('edit_user', (int) $user->ID)) {
      return;
    }

    $frameworks = self::get_profile_frameworks();

    if (empty($frameworks)) {
      return;
    }

    echo '<h2>Assigned Terms</h2>';
    echo '<table class="form-table" role="presentation">';

    foreach ($frameworks as $framework) {
      $assigned_terms = CFM_Framework_Repository::get_user_terms((int) $user->ID, (int) $framework->id);
      $effective_terms = self::get_user_effective_terms_by_framework_id((int) $user->ID, (int) $framework->id);
      $all_terms = self::order_terms_as_tree(CFM_Framework_Repository::get_compiled_terms((int) $framework->id));
      $terms_by_uuid = self::index_terms_by_uuid($all_terms);
      $manage_url = self::get_assignment_admin_url((int) $user->ID, (int) $framework->id);

      echo '<tr>';
      echo '<th><label>' . esc_html($framework->name) . '</label></th>';
      echo '<td>';

      echo '<p><strong>Assigned directly:</strong> ' . esc_html(self::format_term_breadcrumbs($assigned_terms, $terms_by_uuid)) . '</p>';
      echo '<p><strong>Inherited terms:</strong> ' . esc_html(self::format_term_breadcrumbs($effective_terms, $terms_by_uuid)) . '</p>';

      if (current_user_can('list_users')) {
        echo '<p><a class="button" href="' . esc_url($manage_url) . '">Manage term assignments</a></p>';
      }

      echo '<p class="description">Assignments are stored by stable term UUID and resolved through the compiled Core Terms.</p>';
      echo '</td>';
      echo '</tr>';
    }

    echo '</table>';
  }

  public static function render_segmentation_tests_admin_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die('You do not have permission to run segmentation tests.');
    }

    $frameworks = self::get_profile_frameworks();

    echo '<div class="wrap">';
    echo '<h1>Labs: Inspect User Terms</h1>';
    echo '<p><strong>Labs tool:</strong> Experimental tools for diagnostics and future extensions.</p>';
    echo '<p>Read-only view of one user’s assigned terms and inherited effective terms.</p>';

    if (empty($frameworks)) {
      echo '<div class="notice notice-warning inline"><p>No terms available yet. Create or install terms and they will become available automatically.</p></div>';
      echo '</div>';
      return;
    }

    $selected_framework_id = isset($_REQUEST['framework_id']) ? absint($_REQUEST['framework_id']) : (int) $frameworks[0]->id;
    $selected_user_id = isset($_REQUEST['user_id']) ? absint($_REQUEST['user_id']) : 0;
    $term_query = isset($_REQUEST['term_query']) ? sanitize_title(wp_unslash($_REQUEST['term_query'])) : '';
    $user_search = isset($_REQUEST['user_search']) ? sanitize_text_field(wp_unslash($_REQUEST['user_search'])) : '';

    if (!self::framework_id_exists($frameworks, $selected_framework_id)) {
      $selected_framework_id = (int) $frameworks[0]->id;
    }

    $selected_framework = CFM_Framework_Repository::get_framework($selected_framework_id);
    $selected_user = $selected_user_id > 0 ? get_userdata($selected_user_id) : false;
    $framework_slug = $selected_framework ? (string) $selected_framework->slug : '';
    $terms = $selected_framework ? self::order_terms_as_tree(CFM_Framework_Repository::get_compiled_terms($selected_framework_id)) : [];
    $candidate_users = self::get_assignment_candidate_users($user_search, $selected_user_id);
    $prechecked_user_id = $selected_user_id;

    if ($prechecked_user_id <= 0 && count($candidate_users['users']) === 1 && !$candidate_users['too_many'] && !$candidate_users['too_short']) {
      $prechecked_user_id = (int) $candidate_users['users'][0]->ID;
    }

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="cfm-segmentation-tests" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="cfm_seg_user_search">Find user</label></th><td>';
    echo '<input type="search" id="cfm_seg_user_search" name="user_search" value="' . esc_attr($user_search) . '" class="regular-text" placeholder="At least 3 characters, or exact email" /> ';
    submit_button('Search Users', 'secondary', '', false);
    echo ' <a class="button" href="' . esc_url(admin_url('users.php?page=cfm-segmentation-tests')) . '">Clear</a>';
    echo '<p class="description">Select a user to inspect assigned and effective terms.</p>';
    echo '</td></tr>';
    echo '<tr><th scope="row">User</th><td>';

    if ($candidate_users['too_short']) {
      echo '<p class="description">Enter at least 3 characters, or search for an exact email address.</p>';
    } elseif ($candidate_users['too_many']) {
      echo '<div class="notice notice-warning inline"><p>More than 25 users matched. Refine the search.</p></div>';
    } elseif (empty($candidate_users['users'])) {
      echo '<p>No matching users selected.</p>';
    } else {
      echo '<fieldset style="max-width:760px;">';
      echo '<legend class="screen-reader-text">Select user</legend>';

      foreach ($candidate_users['users'] as $user) {
        $label = sprintf('%s (%s)', $user->display_name ?: $user->user_login, $user->user_login);
        echo '<label style="display:block;margin:8px 0;padding:10px 12px;background:#fff;border:1px solid #ccd0d4;">';
        echo '<input type="radio" name="user_id" value="' . esc_attr((string) $user->ID) . '" ' . checked($prechecked_user_id, (int) $user->ID, false) . ' /> ';
        echo '<strong>' . esc_html($label) . '</strong><br />';
        echo '<span class="description">' . esc_html($user->user_email) . ' &nbsp; ID: ' . esc_html((string) $user->ID) . '</span>';
        echo '</label>';
      }

      echo '</fieldset>';
    }

    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="cfm_seg_term_query">Check term</label></th><td>';
    echo '<input type="text" id="cfm_seg_term_query" name="term_query" value="' . esc_attr($term_query) . '" class="regular-text" placeholder="Example: elementary, grade-1, math" />';
    echo '<p class="description">Enter a term slug to inspect user matching and user counts.</p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    submit_button('Inspect User', 'primary', '', false);
    echo '</form>';

    if (!$selected_framework) {
      echo '<div class="notice notice-error inline"><p>Invalid Core Terms definition.</p></div>';
      echo '</div>';
      return;
    }

    echo '<hr />';
    echo '<h2>Core Terms</h2>';
    echo '<p><strong>' . esc_html((string) $selected_framework->name) . '</strong> <code>' . esc_html($framework_slug) . '</code></p>';

    echo '<p class="description">Use Labs: Term Statistics for population counts and distribution reports. Use Labs: Audience Explorer to locate matching users.</p>';

    echo '<h2>User Term Resolution</h2>';
    if ($selected_user_id <= 0 || !$selected_user) {
      echo '<p>Select a user above, then run tests to inspect assignments.</p>';
    } else {
      $assigned_terms = self::get_user_terms($selected_user_id, $framework_slug);
      $effective_terms = self::get_user_effective_terms($selected_user_id, $framework_slug);
      $terms_by_uuid = self::index_terms_by_uuid($terms);
      $user_label = sprintf('%s (%s) — %s', $selected_user->display_name ?: $selected_user->user_login, $selected_user->user_login, $selected_user->user_email);

      echo '<p><strong>User:</strong> ' . esc_html($user_label) . '</p>';
      echo '<p><strong>Assigned directly:</strong> ' . esc_html(self::format_term_breadcrumbs($assigned_terms, $terms_by_uuid)) . '</p>';
      echo '<p><strong>Inherited terms:</strong> ' . esc_html(self::format_term_breadcrumbs($effective_terms, $terms_by_uuid)) . '</p>';

      if ($term_query !== '') {
        $custom_term = self::get_term_by_slug($framework_slug, $term_query);
        $custom_user_has = self::user_has_term($selected_user_id, $framework_slug, $term_query);
        $custom_explicit_count = self::count_users($framework_slug, $term_query, 'profile', false);
        $custom_effective_count = self::count_users($framework_slug, $term_query, 'profile', true);

        echo '<h2>Custom Test Term Results</h2>';
        echo '<p><strong>Check term:</strong> <code>' . esc_html($term_query) . '</code></p>';

        if (!$custom_term) {
          echo '<div class="notice notice-warning inline"><p>No term with this slug exists in the selected Core Terms definition. Counts and matching should be treated as invalid/zero.</p></div>';
        }

        echo '<table class="widefat striped" style="max-width:760px;"><thead><tr><th>Question</th><th>Result</th></tr></thead><tbody>';
        echo '<tr><td>Selected user has this term? <code>user_has_term(' . esc_html((string) $selected_user_id) . ', ' . esc_html($framework_slug) . ', ' . esc_html($term_query) . ')</code></td><td><strong>' . esc_html($custom_user_has ? 'true' : 'false') . '</strong></td></tr>';
        echo '<tr><td>Explicit user count</td><td><strong>' . esc_html((string) $custom_explicit_count) . '</strong></td></tr>';
        echo '<tr><td>Descendant-aware user count</td><td><strong>' . esc_html((string) $custom_effective_count) . '</strong></td></tr>';
        echo '</tbody></table>';
      }

      echo '<h2>Baseline Matching Checks</h2>';
      echo '<table class="widefat striped" style="max-width:960px;"><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';

      $checks = [];
      foreach (['grade-1', 'elementary', 'math'] as $slug) {
        if (self::get_term_by_slug($framework_slug, $slug)) {
          $checks[] = [
            'label' => 'user_has_term(' . $selected_user_id . ', ' . $framework_slug . ', ' . $slug . ')',
            'result' => self::user_has_term($selected_user_id, $framework_slug, $slug) ? 'true' : 'false',
          ];
        }
      }

      if (empty($checks)) {
        echo '<tr><td colspan="2">No matching checks available yet.</td></tr>';
      } else {
        foreach ($checks as $check) {
          $is_true = $check['result'] === 'true' || (is_numeric($check['result']) && (int) $check['result'] > 0);
          echo '<tr>';
          echo '<td><code>' . esc_html($check['label']) . '</code></td>';
          echo '<td><strong>' . esc_html($check['result']) . '</strong> ' . ($is_true ? '<span style="color:#008a20;">PASS</span>' : '<span style="color:#8a1f11;">NONE/FALSE</span>') . '</td>';
          echo '</tr>';
        }
      }

      echo '</tbody></table>';
    }

    echo '<p class="description">Read-only inspection page. It reads compiled tables and helper APIs only; it does not modify assignments.</p>';
    echo '</div>';
  }

  public static function render_profile_statistics_admin_page(): void
  {
    if (!current_user_can('list_users')) {
      wp_die('You do not have permission to view profile statistics.');
    }

    $frameworks = self::get_profile_frameworks();

    echo '<div class="wrap">';
    echo '<h1>Labs: Term Statistics</h1>';
    echo '<p><strong>Labs tool:</strong> Experimental tools for diagnostics and future extensions.</p>';
    echo '<p>This page summarizes user term composition across available terms.</p>';

    if (empty($frameworks)) {
      echo '<div class="notice notice-warning inline"><p>No terms available yet. Create or install terms and they will become available automatically.</p></div>';
      echo '</div>';
      return;
    }

    $selected_framework_id = isset($_GET['framework_id']) ? absint($_GET['framework_id']) : (int) $frameworks[0]->id;
    $term_query = isset($_GET['term_query']) ? sanitize_title(wp_unslash($_GET['term_query'])) : '';
    $sort = isset($_GET['sort']) ? sanitize_key(wp_unslash($_GET['sort'])) : 'tree';
    $dir = isset($_GET['dir']) ? strtolower(sanitize_key(wp_unslash($_GET['dir']))) : 'asc';

    if (!in_array($sort, ['tree', 'slug', 'users'], true)) {
      $sort = 'tree';
    }

    if (!in_array($dir, ['asc', 'desc'], true)) {
      $dir = 'asc';
    }

    if (!self::framework_id_exists($frameworks, $selected_framework_id)) {
      $selected_framework_id = (int) $frameworks[0]->id;
    }

    $selected_framework = CFM_Framework_Repository::get_framework($selected_framework_id);

    if (!$selected_framework) {
      echo '<div class="notice notice-error inline"><p>Invalid Core Terms definition.</p></div>';
      echo '</div>';
      return;
    }

    $framework_slug = (string) $selected_framework->slug;
    $terms = self::order_terms_as_tree(CFM_Framework_Repository::get_compiled_terms($selected_framework_id));

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="cfm-profile-statistics" />';
    echo '<input type="hidden" name="sort" value="' . esc_attr($sort) . '" />';
    echo '<input type="hidden" name="dir" value="' . esc_attr($dir) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="cfm_stats_term_query">Search term</label></th><td>';
    echo '<input type="text" id="cfm_stats_term_query" name="term_query" value="' . esc_attr($term_query) . '" class="regular-text" placeholder="Example: elementary, grade-1, math" />';
    echo '<p class="description">Optional. Enter a term slug to inspect audience size.</p>';
    echo '</td></tr>';
    echo '</tbody></table>';
    submit_button('Refresh Statistics', 'primary', '', false);
    echo '</form>';

    echo '<hr />';
    echo '<h2>Term Distribution</h2>';


    if (empty($terms)) {
      echo '<p>No terms available yet.</p>';
      echo '</div>';
      return;
    }

    global $wpdb;
    $users_table = $wpdb->users;
    $user_terms_table = $wpdb->prefix . 'cfm_user_terms';

    $total_users = (int) $wpdb->get_var("SELECT COUNT(ID) FROM {$users_table}");
    $profiled_users = (int) $wpdb->get_var(
      $wpdb->prepare(
        "SELECT COUNT(DISTINCT user_id) FROM {$user_terms_table} WHERE framework_id = %d AND context = %s",
        $selected_framework_id,
        'profile'
      )
    );

    $rows = [];
    $axis_rows = [];

    foreach ($terms as $term) {
      $slug = (string) $term->slug;
      $users_count = self::count_users($framework_slug, $slug, 'profile', true);
      $pct = $profiled_users > 0 ? round(($users_count / $profiled_users) * 100, 1) : 0;

      $row = [
        'term' => $term,
        'users_count' => $users_count,
        'pct' => $pct,
      ];

      $rows[] = $row;

      if ((int) $term->depth === 0) {
        $axis_rows[] = $row;
      }
    }


    $search_row = null;
    $search_term = null;

    if ($term_query !== '') {
      $search_term = self::get_term_by_slug($framework_slug, $term_query);

      if ($search_term) {
        $users_count = self::count_users($framework_slug, $term_query, 'profile', true);
        $pct = $profiled_users > 0 ? round(($users_count / $profiled_users) * 100, 1) : 0;
        $search_row = [
          'term' => $search_term,
          'users_count' => $users_count,
          'pct' => $pct,
        ];
      }
    }

    $display_rows = $rows;

    if ($sort === 'slug') {
      usort($display_rows, static function ($a, $b) use ($dir) {
        $result = strcasecmp((string) $a['term']->slug, (string) $b['term']->slug);
        return $dir === 'desc' ? -$result : $result;
      });
    } elseif ($sort === 'users') {
      usort($display_rows, static function ($a, $b) use ($dir) {
        $result = ((int) $a['users_count']) <=> ((int) $b['users_count']);
        if ($result === 0) {
          $result = strcasecmp((string) $a['term']->path, (string) $b['term']->path);
        }
        return $dir === 'desc' ? -$result : $result;
      });
    }

    $base_args = [
      'page' => 'cfm-profile-statistics',
      'framework_id' => $selected_framework_id,
    ];

    if ($term_query !== '') {
      $base_args['term_query'] = $term_query;
    }

    $tree_url = esc_url(add_query_arg($base_args, admin_url('users.php')));
    $slug_dir = ($sort === 'slug' && $dir === 'asc') ? 'desc' : 'asc';
    $users_dir = ($sort === 'users' && $dir === 'desc') ? 'asc' : 'desc';
    $slug_url = esc_url(add_query_arg(array_merge($base_args, ['sort' => 'slug', 'dir' => $slug_dir]), admin_url('users.php')));
    $users_url = esc_url(add_query_arg(array_merge($base_args, ['sort' => 'users', 'dir' => $users_dir]), admin_url('users.php')));

    echo '<p class="description">User counts include inherited matches. Example: a user assigned to Grade 1 also counts under Elementary and Grade Level.</p>';

    if ($term_query !== '' && !$search_term) {
      echo '<div class="notice notice-warning inline"><p>No term with slug <code>' . esc_html($term_query) . '</code> exists in this Core Terms definition.</p></div>';
    }

    echo '<div style="max-height:620px;overflow:auto;border:1px solid #ccd0d4;background:#fff;max-width:1050px;">';
    echo '<table class="widefat striped" style="border:0;">';
    echo '<thead><tr>';
    $profile_term_count = count($rows);

    echo '<th><a href="' . $tree_url . '">Terms (' . esc_html((string) $profile_term_count) . ')</a></th>';
    echo '<th><a href="' . $slug_url . '">Slug</a></th>';
    echo '<th><a href="' . $users_url . '">Users (' . esc_html((string) $total_users) . ')</a></th>';
    echo '<th>%</th>';
    echo '</tr></thead><tbody>';

    if ($search_row) {
      $term = $search_row['term'];
      $indent = str_repeat('&mdash; ', max(0, (int) $term->depth));
      echo '<tr style="background:#eaf3ff;font-weight:600;">';
      echo '<td>Search: ' . wp_kses_post($indent) . esc_html((string) $term->label) . '</td>';
      echo '<td><code>' . esc_html((string) $term->slug) . '</code></td>';
      echo '<td>' . esc_html((string) $search_row['users_count']) . '</td>';
      echo '<td>' . esc_html((string) $search_row['pct']) . '%</td>';
      echo '</tr>';
    }

    if ($search_row) {
      echo '<tr><td colspan="4" style="height:10px;background:#fff;border-top:1px solid #ccd0d4;border-bottom:1px solid #ccd0d4;"></td></tr>';
    }

    foreach ($display_rows as $row) {
      $term = $row['term'];
      $depth = (int) $term->depth;
      $indent = $sort === 'tree' ? str_repeat('&mdash; ', max(0, $depth)) : '';
      $is_axis = $depth === 0;

      echo '<tr' . ($is_axis && $sort === 'tree' ? ' style="font-weight:600;"' : '') . '>';
      echo '<td>' . wp_kses_post($indent) . esc_html((string) $term->label) . '</td>';
      echo '<td><code>' . esc_html((string) $term->slug) . '</code></td>';
      echo '<td>' . esc_html((string) $row['users_count']) . '</td>';
      echo '<td>' . esc_html((string) $row['pct']) . '%</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '<p class="description">Click Terms to restore tree order. Slug sorts alphabetically. Users sorts by audience size. The highlighted search row stays pinned above the table.</p>';
    echo '<p class="description">This page is analytics/read-only. Assignment changes happen under Users → Assign Terms.</p>';
    echo '</div>';
  }

  public static function render_assignment_admin_page(): void
  {
    if (!current_user_can('list_users')) {
      wp_die('You do not have permission to manage term assignments.');
    }

    $frameworks = self::get_profile_frameworks();

    if (empty($frameworks)) {
      echo '<div class="wrap"><h1>Assign Terms</h1><p>No terms available yet. Create or install terms and they will become available automatically.</p></div>';
      return;
    }

    $selected_framework_id = isset($_REQUEST['framework_id']) ? absint($_REQUEST['framework_id']) : (int) $frameworks[0]->id;
    $selected_user_id = isset($_REQUEST['user_id']) ? absint($_REQUEST['user_id']) : 0;

    if (!self::framework_id_exists($frameworks, $selected_framework_id)) {
      $selected_framework_id = (int) $frameworks[0]->id;
    }

    $notice = '';
    $notice_type = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cfm_assignment_action']) && $_POST['cfm_assignment_action'] === 'save') {
      $nonce = isset($_POST['cfm_assignment_nonce']) ? sanitize_text_field(wp_unslash($_POST['cfm_assignment_nonce'])) : '';

      if (!wp_verify_nonce($nonce, 'cfm_save_assignments')) {
        $notice = 'Assignment save failed: invalid security token.';
        $notice_type = 'error';
      } elseif ($selected_user_id <= 0 || !get_userdata($selected_user_id) || !current_user_can('edit_user', $selected_user_id)) {
        $notice = 'Assignment save failed: invalid or inaccessible user.';
        $notice_type = 'error';
      } elseif (!self::framework_id_exists($frameworks, $selected_framework_id)) {
        $notice = 'Assignment save failed: invalid Core Terms definition.';
        $notice_type = 'error';
      } else {
        $posted_terms = isset($_POST['cfm_user_terms']) && is_array($_POST['cfm_user_terms'])
          ? array_map('sanitize_text_field', wp_unslash($_POST['cfm_user_terms']))
          : [];

        $saved = CFM_Framework_Repository::set_user_terms($selected_user_id, $selected_framework_id, $posted_terms);

        if ($saved) {
          $saved_user = get_userdata($selected_user_id);
          $saved_framework = CFM_Framework_Repository::get_framework($selected_framework_id);
          $saved_user_label = $saved_user ? (($saved_user->display_name ?: $saved_user->user_login) . ' / ' . $saved_user->user_email) : ('User ID ' . $selected_user_id);
          $saved_framework_label = $saved_framework ? (string) $saved_framework->name : ('Core Terms ID ' . $selected_framework_id);
          $notice = 'Term assignments saved for ' . $saved_user_label . ' in ' . $saved_framework_label . '.';
        } else {
          $notice = 'Assignment save failed.';
          $notice_type = 'error';
        }
      }
    }

    $user_search = isset($_REQUEST['user_search']) ? sanitize_text_field(wp_unslash($_REQUEST['user_search'])) : '';
    $user_search_result = self::get_assignment_candidate_users($user_search, $selected_user_id);
    $users = $user_search_result['users'];
    $user_search_too_many = (bool) $user_search_result['too_many'];
    $user_search_too_short = (bool) $user_search_result['too_short'];

    $matched_user_ids = array_map('intval', wp_list_pluck($users, 'ID'));

    if ($user_search !== '' && !$user_search_too_many && !$user_search_too_short) {
      if (count($users) === 1) {
        $selected_user_id = (int) $users[0]->ID;
      } elseif ($selected_user_id > 0 && !in_array($selected_user_id, $matched_user_ids, true)) {
        $selected_user_id = 0;
      }
    }

    $prechecked_user_id = $selected_user_id;

    $selected_framework = CFM_Framework_Repository::get_framework($selected_framework_id);
    $selected_user = $selected_user_id > 0 ? get_userdata($selected_user_id) : false;
    $terms = $selected_framework ? self::order_terms_as_tree(CFM_Framework_Repository::get_compiled_terms($selected_framework_id)) : [];
    $terms_by_uuid = self::index_terms_by_uuid($terms);
    $assigned = $selected_user_id > 0 ? CFM_Framework_Repository::get_user_term_uuids($selected_user_id, $selected_framework_id) : [];

    echo '<div class="wrap">';
    echo '<h1>Assign Terms</h1>';

    if ($notice !== '') {
      echo '<div class="notice notice-' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="cfm-framework-assignments" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="cfm_user_search">Find user</label></th><td>';
    echo '<input type="search" id="cfm_user_search" name="user_search" value="' . esc_attr($user_search) . '" class="regular-text" placeholder="At least 3 characters, or exact email" /> ';
    submit_button('Search', 'secondary', '', false);
    echo ' <a class="button" href="' . esc_url(admin_url('users.php?page=cfm-framework-assignments')) . '">Clear / New Search</a>';
    echo '<p class="description">Search by login, email, or display name. Results are limited to 25 users. A single match loads automatically; multiple matches require selection.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">User</th><td>';

    if ($user_search_too_short) {
      echo '<p class="description">Enter at least 3 characters, or search for an exact email address.</p>';
    } elseif ($user_search_too_many) {
      echo '<div class="notice notice-warning inline"><p>More than 25 users matched. Refine the search.</p></div>';
    } elseif (empty($users)) {
      echo '<p>No matching users.</p>';
    } else {
      echo '<fieldset style="max-width:760px;">';
      echo '<legend class="screen-reader-text">Select user</legend>';

      foreach ($users as $user) {
        $label = sprintf('%s (%s)', $user->display_name ?: $user->user_login, $user->user_login);
        echo '<label style="display:block;margin:8px 0;padding:10px 12px;background:#fff;border:1px solid #ccd0d4;">';
        echo '<input type="radio" name="user_id" value="' . esc_attr((string) $user->ID) . '" ' . checked($prechecked_user_id, (int) $user->ID, false) . ' /> ';
        echo '<strong>' . esc_html($label) . '</strong><br />';
        echo '<span class="description">' . esc_html($user->user_email) . ' &nbsp; ID: ' . esc_html((string) $user->ID) . '</span>';
        echo '</label>';
      }

      echo '</fieldset>';
    }

    echo '</td></tr>';
    echo '<input type="hidden" name="framework_id" value="' . esc_attr((string) $selected_framework_id) . '" />';
    echo '</tbody></table>';
    echo '</form>';

    if ($selected_user_id <= 0) {
      echo '<p>Select a user from the search results, then click Load Selected User to edit saved term selections.</p>';
      echo '</div>';
      return;
    }

    if (!$selected_user || !$selected_framework) {
      echo '<div class="notice notice-error inline"><p>Cannot load assignments: invalid user or Core Terms definition.</p></div>';
      echo '</div>';
      return;
    }

    $selected_user_display = $selected_user->display_name ?: $selected_user->user_login;

    echo '<div class="notice notice-info inline"><p>';
    echo 'User: <strong>' . esc_html($selected_user->user_login) . '</strong> ';
    echo '<span class="description">(' . esc_html($selected_user_display) . ' / ' . esc_html($selected_user->user_email) . ')</span>';
    echo '</p></div>';
    echo '<hr />';
    echo '<form method="post" action="' . esc_url(admin_url('users.php?page=cfm-framework-assignments')) . '">';
    wp_nonce_field('cfm_save_assignments', 'cfm_assignment_nonce');
    echo '<input type="hidden" name="cfm_assignment_action" value="save" />';
    echo '<input type="hidden" name="user_id" value="' . esc_attr((string) $selected_user_id) . '" />';
    echo '<input type="hidden" name="framework_id" value="' . esc_attr((string) $selected_framework_id) . '" />';

    echo '<h2>User Term Assignments</h2>';
    echo '<p class="description">Select terms assigned directly to this user. Parent terms implied by child selections are shown checked and dimmed, but only direct selections are saved.</p>';

    if (empty($terms)) {
      echo '<p>No terms are available for this Core Terms definition yet.</p>';
    } else {
      $effective_terms_for_ui = $selected_user_id > 0
        ? self::get_user_effective_terms_by_framework_id($selected_user_id, $selected_framework_id)
        : [];
      $effective_uuids = [];

      foreach ($effective_terms_for_ui as $effective_term_for_ui) {
        if (is_object($effective_term_for_ui) && !empty($effective_term_for_ui->term_uuid)) {
          $effective_uuids[] = (string) $effective_term_for_ui->term_uuid;
        }
      }

      $effective_uuids = array_values(array_unique($effective_uuids));

      echo '<div data-cfm-user-term-assignment-tree="1" style="max-width:860px;background:#fff;border:1px solid #ccd0d4;padding:12px 16px;">';
      echo '<div style="display:flex; justify-content:space-between; max-width:820px; margin:0 0 8px;">';
      echo '<span><a href="#" data-cfm-assignment-expand="1">Expand all</a><span aria-hidden="true"> | </span><a href="#" data-cfm-assignment-expand="0">Collapse all</a></span>';
      echo '<span class="description">Direct selections are saved. Dimmed parents are implied.</span>';
      echo '</div>';
      self::render_user_assignment_term_tree($terms, $assigned, $effective_uuids, $terms_by_uuid);
      echo '</div>';

      $assigned_terms = $selected_user_id > 0
        ? CFM_Framework_Repository::get_user_terms($selected_user_id, $selected_framework_id)
        : [];
      $effective_terms = $effective_terms_for_ui;

      echo '<div style="max-width:760px;margin-top:12px;">';
      echo '<p><strong>Assigned directly:</strong> ' . esc_html(self::format_term_breadcrumbs($assigned_terms, $terms_by_uuid)) . '</p>';
      echo '<p><strong>Inherited terms:</strong> ' . esc_html(self::format_term_breadcrumbs($effective_terms, $terms_by_uuid)) . '</p>';
      echo '</div>';

      echo '<p class="description">Only direct user selections are stored. Parent terms are inherited at query time through compiled closure tables.</p>';
      echo '<p class="cfm-assignment-actions" style="margin-top:12px;">';
      echo '<span class="cfm-save-assignments-wrap" style="display:none;">';
      submit_button('Save Assignments', 'primary', 'submit', false);
      echo '</span>';
      echo ' <a class="button" href="' . esc_url(admin_url('users.php?page=cfm-framework-assignments')) . '">Clear / New Search</a>';
      echo ' <span class="description cfm-assignment-dirty-note" style="display:none;margin-left:8px;">Unsaved assignment changes.</span>';
      echo '</p>';
      echo '<script>';
      echo '(function(){';
      echo 'var form=document.currentScript.closest("form");';
      echo 'if(!form){return;}';
      echo 'var tree=form.querySelector("[data-cfm-user-term-assignment-tree]");';
      echo 'var checkboxes=form.querySelectorAll("input[name=\\"cfm_user_terms[]\\"]");';
      echo 'var allBoxes=tree?tree.querySelectorAll(".cfm-user-term-checkbox"):checkboxes;';
      echo 'var saveWrap=form.querySelector(".cfm-save-assignments-wrap");';
      echo 'var dirtyNote=form.querySelector(".cfm-assignment-dirty-note");';
      echo 'if(!saveWrap||!allBoxes.length){return;}';
      echo 'var byUuid={};';
      echo 'allBoxes.forEach(function(cb){';
      echo 'var uuid=cb.getAttribute("data-term-uuid")||"";';
      echo 'if(!uuid){return;}';
      echo 'byUuid[uuid]=cb;';
      echo 'cb.dataset.initialDirect=cb.getAttribute("data-direct") === "1" ? "1" : "0";';
      echo 'cb.dataset.userDirect=cb.dataset.initialDirect;';
      echo '});';
      echo 'function clearImplied(){';
      echo 'allBoxes.forEach(function(cb){';
      echo 'var direct=cb.dataset.userDirect === "1";';
      echo 'cb.disabled=false;';
      echo 'cb.classList.remove("cfm-term-implied");';
      echo 'var label=cb.closest("label");';
      echo 'if(label){label.classList.remove("cfm-term-implied-label");}';
      echo 'var badge=label?label.querySelector(".cfm-implied-badge"):null;';
      echo 'if(badge){badge.remove();}';
      echo 'cb.checked=direct;';
      echo '});';
      echo '}';
      echo 'function markImplied(cb){';
      echo 'if(!cb || cb.dataset.userDirect === "1"){return;}';
      echo 'cb.checked=true;';
      echo 'cb.disabled=true;';
      echo 'cb.classList.add("cfm-term-implied");';
      echo 'var label=cb.closest("label");';
      echo 'if(label){';
      echo 'label.classList.add("cfm-term-implied-label");';
      echo 'if(!label.querySelector(".cfm-implied-badge")){';
      echo 'var badge=document.createElement("span");';
      echo 'badge.className="description cfm-implied-badge";';
      echo 'badge.textContent=" implied";';
      echo 'label.appendChild(badge);';
      echo '}';
      echo '}';
      echo '}';
      echo 'function applyImplied(){';
      echo 'clearImplied();';
      echo 'allBoxes.forEach(function(cb){';
      echo 'if(cb.dataset.userDirect !== "1"){return;}';
      echo 'var parentUuid=cb.getAttribute("data-parent-uuid")||"";';
      echo 'while(parentUuid && byUuid[parentUuid]){';
      echo 'markImplied(byUuid[parentUuid]);';
      echo 'parentUuid=byUuid[parentUuid].getAttribute("data-parent-uuid")||"";';
      echo '}';
      echo '});';
      echo '}';
      echo 'function updateDirty(){';
      echo 'var dirty=false;';
      echo 'allBoxes.forEach(function(cb){if(cb.dataset.userDirect!==cb.dataset.initialDirect){dirty=true;}});';
      echo 'saveWrap.style.display=dirty?"inline-block":"none";';
      echo 'if(dirtyNote){dirtyNote.style.display=dirty?"inline":"none";}';
      echo '}';
      echo 'allBoxes.forEach(function(cb){';
      echo 'cb.addEventListener("change",function(){';
      echo 'cb.dataset.userDirect=cb.checked?"1":"0";';
      echo 'applyImplied();';
      echo 'updateDirty();';
      echo '});';
      echo '});';
      echo 'if(tree){';
      echo 'tree.addEventListener("click",function(event){';
      echo 'var toggle=event.target.closest("[data-cfm-assignment-toggle]");';
      echo 'if(!toggle){return;}';
      echo 'event.preventDefault();';
      echo 'var node=toggle.closest(".cfm-assignment-term-node");';
      echo 'if(!node){return;}';
      echo 'var children=node.querySelector(":scope > .cfm-assignment-children");';
      echo 'if(!children){return;}';
      echo 'var collapsed=children.style.display==="none";';
      echo 'children.style.display=collapsed?"":"none";';
      echo 'toggle.textContent=collapsed?"▾":"▸";';
      echo '});';
      echo '}';
      echo 'document.querySelectorAll("[data-cfm-assignment-expand]").forEach(function(link){';
      echo 'link.addEventListener("click",function(event){';
      echo 'event.preventDefault();';
      echo 'var expand=link.getAttribute("data-cfm-assignment-expand")==="1";';
      echo 'document.querySelectorAll(".cfm-assignment-children").forEach(function(children){children.style.display=expand?"":"none";});';
      echo 'document.querySelectorAll("[data-cfm-assignment-toggle]").forEach(function(toggle){toggle.textContent=expand?"▾":"▸";});';
      echo '});';
      echo '});';
      echo 'applyImplied();';
      echo 'updateDirty();';
      echo '})();';
      echo '</script>';
    }

    echo '</form>';
    echo '</div>';
  }


  private static function render_user_assignment_term_tree(array $terms, array $assigned_uuids, array $effective_uuids, array $terms_by_uuid): void
  {
    $children_by_parent = [];

    foreach ($terms as $term) {
      if (!is_object($term) || empty($term->term_uuid)) {
        continue;
      }

      $parent_uuid = isset($term->parent_uuid) ? (string) $term->parent_uuid : '';

      if ($parent_uuid === '') {
        $parent_uuid = '__root__';
      }

      if (!isset($children_by_parent[$parent_uuid])) {
        $children_by_parent[$parent_uuid] = [];
      }

      $children_by_parent[$parent_uuid][] = $term;
    }

    if (empty($children_by_parent['__root__'])) {
      echo '<p>No terms are available for assignment.</p>';
      return;
    }

    echo '<div class="cfm-user-assignment-tree">';
    self::render_user_assignment_term_nodes($children_by_parent['__root__'], $children_by_parent, $assigned_uuids, $effective_uuids, $terms_by_uuid, 0);
    echo '</div>';
  }

  private static function render_user_assignment_term_nodes(array $nodes, array $children_by_parent, array $assigned_uuids, array $effective_uuids, array $terms_by_uuid, int $depth): void
  {
    foreach ($nodes as $term) {
      if (!is_object($term) || empty($term->term_uuid)) {
        continue;
      }

      $uuid = (string) $term->term_uuid;
      $parent_uuid = isset($term->parent_uuid) ? (string) $term->parent_uuid : '';
      $children = $children_by_parent[$uuid] ?? [];
      $has_children = !empty($children);
      $direct = in_array($uuid, $assigned_uuids, true);
      $implied = (!$direct && in_array($uuid, $effective_uuids, true));
      $checked = ($direct || $implied) ? ' checked' : '';
      $disabled = $implied ? ' disabled' : '';
      $margin = max(0, $depth) * 18;
      $breadcrumb = self::format_term_breadcrumb($term, $terms_by_uuid);

      echo '<div class="cfm-assignment-term-node" style="margin:4px 0 4px ' . esc_attr((string) $margin) . 'px;">';
      echo '<div class="cfm-assignment-term-row" style="display:flex;align-items:center;gap:6px;">';

      if ($has_children) {
        echo '<button type="button" class="button-link" data-cfm-assignment-toggle="1" aria-label="Expand or collapse children" style="width:18px;text-align:center;text-decoration:none;">▾</button>';
      } else {
        echo '<span aria-hidden="true" style="display:inline-block;width:18px;"></span>';
      }

      echo '<label' . ($implied ? ' class="cfm-term-implied-label"' : '') . ' title="' . esc_attr($breadcrumb) . '">';
      echo '<input class="cfm-user-term-checkbox" type="checkbox" name="cfm_user_terms[]" value="' . esc_attr($uuid) . '" data-term-uuid="' . esc_attr($uuid) . '" data-parent-uuid="' . esc_attr($parent_uuid) . '" data-direct="' . esc_attr($direct ? '1' : '0') . '"' . $checked . $disabled . '> ';
      echo esc_html((string) $term->label) . ' <code>' . esc_html((string) $term->slug) . '</code>';

      if ($implied) {
        echo ' <span class="description cfm-implied-badge">implied</span>';
      }

      if ($breadcrumb !== (string) $term->label) {
        echo ' <span class="description">' . esc_html($breadcrumb) . '</span>';
      }

      echo '</label>';
      echo '</div>';

      if ($has_children) {
        echo '<div class="cfm-assignment-children">';
        self::render_user_assignment_term_nodes($children, $children_by_parent, $assigned_uuids, $effective_uuids, $terms_by_uuid, $depth + 1);
        echo '</div>';
      }

      echo '</div>';
    }
  }

  private static function parse_term_query_list(string $raw): array
  {
    $raw = trim($raw);

    if ($raw === '') {
      return [];
    }

    $parts = preg_split('/[\s,]+/', $raw);

    if (!is_array($parts)) {
      return [];
    }

    $terms = [];

    foreach ($parts as $part) {
      $part = trim((string) $part);

      if ($part === '') {
        continue;
      }

      $terms[] = wp_is_uuid($part) ? $part : sanitize_title($part);
    }

    return array_values(array_unique(array_filter($terms)));
  }

  private static function format_audience_query_example(string $framework_slug, array $target_terms, string $operator, int $limit): string
  {
    $terms = array_values(array_filter(array_map('strval', $target_terms)));

    return "CFM::find_users([\n"
      . "    'framework' => '" . $framework_slug . "',\n"
      . "    'terms' => " . var_export($terms, true) . ",\n"
      . "    'operator' => '" . $operator . "',\n"
      . "    'limit' => " . $limit . ",\n"
      . "]);";
  }

  private static function get_assignment_candidate_users(string $search, int $selected_user_id = 0): array
  {
    $search = trim($search);
    $users_by_id = [];
    $too_many = false;
    $too_short = false;

    if ($search !== '') {
      $is_exact_email = is_email($search);

      if (!$is_exact_email && mb_strlen($search) < 3) {
        $too_short = true;
      } else {
        $query_args = [
          'number' => 26,
          'orderby' => 'display_name',
          'order' => 'ASC',
          'fields' => ['ID', 'display_name', 'user_login', 'user_email'],
        ];

        if ($is_exact_email) {
          $query_args['search'] = $search;
          $query_args['search_columns'] = ['user_email'];
        } else {
          $query_args['search'] = '*' . $search . '*';
          $query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $query = new WP_User_Query($query_args);
        $results = $query->get_results();

        if (count($results) > 25) {
          $too_many = true;
          $results = array_slice($results, 0, 25);
        }

        foreach ($results as $user) {
          $users_by_id[(int) $user->ID] = $user;
        }
      }
    }

    // Preserve the currently loaded user only when there is no active search.
    // When a new search is active, do not inject the previously selected user
    // into the result set; otherwise stale users appear and may remain loaded.
    if ($search === '' && $selected_user_id > 0) {
      $selected_user = get_userdata($selected_user_id);

      if ($selected_user) {
        $users_by_id[(int) $selected_user->ID] = (object) [
          'ID' => (int) $selected_user->ID,
          'display_name' => (string) $selected_user->display_name,
          'user_login' => (string) $selected_user->user_login,
          'user_email' => (string) $selected_user->user_email,
        ];
      }
    }

    $users = array_values($users_by_id);

    usort($users, static function ($a, $b): int {
      $a_label = (string) ($a->display_name ?: $a->user_login);
      $b_label = (string) ($b->display_name ?: $b->user_login);

      $label_compare = strcasecmp($a_label, $b_label);

      if ($label_compare !== 0) {
        return $label_compare;
      }

      return ((int) $a->ID) <=> ((int) $b->ID);
    });

    return [
      'users' => $users,
      'too_many' => $too_many,
      'too_short' => $too_short,
    ];
  }

  private static function get_user_effective_terms_by_framework_id(int $user_id, int $framework_id, string $context = 'profile'): array
  {
    $assigned_uuids = CFM_Framework_Repository::get_user_term_uuids($user_id, $framework_id, $context);

    if (empty($assigned_uuids)) {
      return [];
    }

    $effective_uuids = [];

    foreach ($assigned_uuids as $assigned_uuid) {
      $assigned_uuid = (string) $assigned_uuid;

      if ($assigned_uuid === '') {
        continue;
      }

      $effective_uuids[] = $assigned_uuid;

      $ancestor_uuids = CFM_Framework_Repository::get_ancestor_uuids(
        $framework_id,
        $assigned_uuid,
        null,
        false
      );

      foreach ($ancestor_uuids as $ancestor_uuid) {
        $effective_uuids[] = (string) $ancestor_uuid;
      }
    }

    $effective_uuids = array_values(array_unique(array_filter($effective_uuids)));

    return self::order_terms_as_tree(
      CFM_Framework_Repository::get_terms_by_uuids($framework_id, $effective_uuids)
    );
  }

  private static function index_terms_by_uuid(array $terms): array
  {
    $indexed = [];

    foreach ($terms as $term) {
      if (!is_object($term) || empty($term->term_uuid)) {
        continue;
      }

      $indexed[(string) $term->term_uuid] = $term;
    }

    return $indexed;
  }

  private static function format_term_breadcrumb(object $term, array $terms_by_uuid = []): string
  {
    $parts = [(string) $term->label];
    $parent_uuid = isset($term->parent_uuid) && $term->parent_uuid !== null
      ? (string) $term->parent_uuid
      : '';
    $guard = 0;

    while ($parent_uuid !== '' && isset($terms_by_uuid[$parent_uuid]) && $guard < 25) {
      $parent = $terms_by_uuid[$parent_uuid];
      array_unshift($parts, (string) $parent->label);
      $parent_uuid = isset($parent->parent_uuid) && $parent->parent_uuid !== null
        ? (string) $parent->parent_uuid
        : '';
      $guard++;
    }

    return implode(' › ', array_values(array_filter($parts)));
  }

  private static function format_term_breadcrumbs(array $terms, array $terms_by_uuid = []): string
  {
    if (empty($terms)) {
      return 'None.';
    }

    $labels = array_map(
      static fn($term): string => is_object($term)
        ? self::format_term_breadcrumb($term, $terms_by_uuid)
        : '',
      $terms
    );

    return implode(', ', array_values(array_unique(array_filter($labels))));
  }

  private static function format_term_labels(array $terms): string
  {
    if (empty($terms)) {
      return 'None.';
    }

    $labels = array_map(static fn($term): string => (string) $term->label, $terms);

    return implode(', ', $labels);
  }

  private static function get_assignment_admin_url(int $user_id, int $framework_id): string
  {
    return add_query_arg(
      [
        'page' => 'cfm-framework-assignments',
        'user_id' => $user_id,
        'framework_id' => $framework_id,
      ],
      admin_url('users.php')
    );
  }

  private static function framework_id_exists(array $frameworks, int $framework_id): bool
  {
    foreach ($frameworks as $framework) {
      if ((int) $framework->id === $framework_id) {
        return true;
      }
    }

    return false;
  }

  private static function order_terms_as_tree(array $terms): array
  {
    if (empty($terms)) {
      return [];
    }

    $children_by_parent = [];

    foreach ($terms as $term) {
      $parent_uuid = isset($term->parent_uuid) && $term->parent_uuid !== null
        ? (string) $term->parent_uuid
        : '';

      if (!isset($children_by_parent[$parent_uuid])) {
        $children_by_parent[$parent_uuid] = [];
      }

      $children_by_parent[$parent_uuid][] = $term;
    }

    foreach ($children_by_parent as &$siblings) {
      usort($siblings, static function ($a, $b): int {
        $a_sort = isset($a->sort_order) ? (int) $a->sort_order : 0;
        $b_sort = isset($b->sort_order) ? (int) $b->sort_order : 0;

        if ($a_sort !== $b_sort) {
          return $a_sort <=> $b_sort;
        }

        $label_compare = strcasecmp((string) $a->label, (string) $b->label);

        if ($label_compare !== 0) {
          return $label_compare;
        }

        return strcmp((string) $a->term_uuid, (string) $b->term_uuid);
      });
    }
    unset($siblings);

    $ordered = [];

    $walk = static function (string $parent_uuid) use (&$walk, &$ordered, $children_by_parent): void {
      if (empty($children_by_parent[$parent_uuid])) {
        return;
      }

      foreach ($children_by_parent[$parent_uuid] as $term) {
        $ordered[] = $term;
        $walk((string) $term->term_uuid);
      }
    };

    $walk('');

    return $ordered;
  }


  private static function resolve_assignable_term_uuid(int $framework_id, string $term_slug_or_uuid): string
  {
    $term_uuid = self::resolve_term_uuid($framework_id, $term_slug_or_uuid);

    if ($term_uuid === '') {
      return '';
    }

    $term = CFM_Framework_Repository::get_term_by_uuid($framework_id, $term_uuid);

    if (!$term || (string) $term->kind !== 'term') {
      return '';
    }

    return $term_uuid;
  }

  private static function resolve_term_uuids(int $framework_id, array $term_slugs_or_uuids): array
  {
    $term_uuids = [];

    foreach ($term_slugs_or_uuids as $term_slug_or_uuid) {
      $term_uuid = self::resolve_term_uuid($framework_id, (string) $term_slug_or_uuid);

      if ($term_uuid !== '') {
        $term_uuids[] = $term_uuid;
      }
    }

    return array_values(array_unique(array_filter($term_uuids)));
  }

  private static function term_arrays_match(array $user_terms, array $target_terms, string $operator = 'AND'): bool
  {
    $user_terms = array_values(array_unique(array_filter(array_map('strval', $user_terms))));
    $target_terms = array_values(array_unique(array_filter(array_map('strval', $target_terms))));
    $operator = strtoupper($operator);

    if (!in_array($operator, ['AND', 'OR'], true)) {
      $operator = 'AND';
    }

    if (empty($user_terms) || empty($target_terms)) {
      return false;
    }

    $matches = array_intersect($target_terms, $user_terms);

    if ($operator === 'OR') {
      return !empty($matches);
    }

    return count($matches) === count($target_terms);
  }

  private static function resolve_term_uuid(int $framework_id, string $term_slug_or_uuid): string
  {
    $term_slug_or_uuid = trim($term_slug_or_uuid);

    if ($term_slug_or_uuid === '') {
      return '';
    }

    if (wp_is_uuid($term_slug_or_uuid)) {
      $term = CFM_Framework_Repository::get_term_by_uuid($framework_id, $term_slug_or_uuid);
    } else {
      $term = CFM_Framework_Repository::get_term_by_slug($framework_id, sanitize_title($term_slug_or_uuid));
    }

    return $term ? (string) $term->term_uuid : '';
  }

  private static function get_profile_frameworks(): array
  {
    return array_values(array_filter(
      CFM_Framework_Repository::get_frameworks(),
      static function ($framework): bool {
        return !empty($framework->active_version_id)
          && !empty(CFM_Framework_Repository::get_compiled_terms((int) $framework->id));
      }
    ));
  }
}
