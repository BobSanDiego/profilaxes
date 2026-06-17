<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Seeder
{
  public const PACK_GEOGRAPHY_US_STATES = 'geography_us_states';
  public const PACK_GEOGRAPHY_COUNTRIES_LITE = 'geography_countries_lite';

  public static function get_pack_labels(): array
  {
    return [
      self::PACK_GEOGRAPHY_US_STATES => 'Geography — US States',
      self::PACK_GEOGRAPHY_COUNTRIES_LITE => 'Geography — Countries Lite',
    ];
  }

  public static function is_valid_pack(string $pack): bool
  {
    return isset(self::get_pack_labels()[$pack]);
  }

  public static function get_pack_label(string $pack): string
  {
    $labels = self::get_pack_labels();
    return $labels[$pack] ?? 'Example Terms';
  }

  public static function apply_pack(array $tree, string $pack): array
  {
    if (!isset($tree['children']) || !is_array($tree['children'])) {
      $tree['children'] = [];
    }

    $counts = [
      'created' => 0,
      'skipped' => 0,
    ];

    foreach (self::get_pack_definition($pack) as $definition) {
      self::apply_term_definition($tree, $definition, $counts);
    }

    return [
      'tree' => $tree,
      'created' => (int) $counts['created'],
      'skipped' => (int) $counts['skipped'],
      'pack_label' => self::get_pack_label($pack),
    ];
  }

  private static function apply_term_definition(array &$parent, array $definition, array &$counts): void
  {
    if (!isset($parent['children']) || !is_array($parent['children'])) {
      $parent['children'] = [];
    }

    $label = sanitize_text_field((string) ($definition['label'] ?? ''));
    $slug = self::normalize_slug((string) ($definition['slug'] ?? $label));

    if ($label === '' || $slug === '') {
      return;
    }

    $child_index = self::find_child_index_by_slug($parent['children'], $slug);

    if ($child_index === null) {
      $parent['children'][] = [
        'uuid' => wp_generate_uuid4(),
        'label' => $label,
        'slug' => $slug,
        'short_label' => sanitize_text_field((string) ($definition['short_label'] ?? $label)),
        'kind' => 'term',
        'description' => sanitize_textarea_field((string) ($definition['description'] ?? $label)),
        'children' => [],
      ];

      $child_index = count($parent['children']) - 1;
      $counts['created']++;
    } else {
      $counts['skipped']++;
    }

    $children = $definition['children'] ?? [];

    if (!empty($children) && is_array($children)) {
      foreach ($children as $child_definition) {
        if (!is_array($child_definition)) {
          continue;
        }

        self::apply_term_definition($parent['children'][$child_index], $child_definition, $counts);
      }
    }
  }

  private static function find_child_index_by_slug(array $children, string $slug): ?int
  {
    foreach ($children as $index => $child) {
      if (!is_array($child)) {
        continue;
      }

      if ((string) ($child['kind'] ?? 'term') !== 'term') {
        continue;
      }

      if (self::normalize_slug((string) ($child['slug'] ?? '')) === $slug) {
        return (int) $index;
      }
    }

    return null;
  }

  private static function normalize_slug(string $value): string
  {
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace("/[\'’`]/u", '', $value);

    if (function_exists('remove_accents')) {
      $value = remove_accents($value);
    }

    if (function_exists('mb_strtolower')) {
      $value = mb_strtolower($value, 'UTF-8');
    } else {
      $value = strtolower($value);
    }

    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = preg_replace('/-+/', '-', (string) $value);
    $value = trim((string) $value, '-');

    return $value;
  }

  private static function get_pack_definition(string $pack): array
  {
    if ($pack === self::PACK_GEOGRAPHY_US_STATES) {
      return self::geography_us_states_definition();
    }

    if ($pack === self::PACK_GEOGRAPHY_COUNTRIES_LITE) {
      return self::geography_countries_lite_definition();
    }

    return [];
  }

  private static function geography_us_states_definition(): array
  {
    $states = [
      'Alabama',
      'Alaska',
      'Arizona',
      'Arkansas',
      'California',
      'Colorado',
      'Connecticut',
      'Delaware',
      'District of Columbia',
      'Florida',
      'Georgia',
      'Hawaii',
      'Idaho',
      'Illinois',
      'Indiana',
      'Iowa',
      'Kansas',
      'Kentucky',
      'Louisiana',
      'Maine',
      'Maryland',
      'Massachusetts',
      'Michigan',
      'Minnesota',
      'Mississippi',
      'Missouri',
      'Montana',
      'Nebraska',
      'Nevada',
      'New Hampshire',
      'New Jersey',
      'New Mexico',
      'New York',
      'North Carolina',
      'North Dakota',
      'Ohio',
      'Oklahoma',
      'Oregon',
      'Pennsylvania',
      'Rhode Island',
      'South Carolina',
      'South Dakota',
      'Tennessee',
      'Texas',
      'Utah',
      'Vermont',
      'Virginia',
      'Washington',
      'West Virginia',
      'Wisconsin',
      'Wyoming',
    ];

    return [
      [
        'label' => 'Region',
        'children' => [
          [
            'label' => 'United States',
            'children' => array_map(
              static function (string $state): array {
                return ['label' => $state];
              },
              $states
            ),
          ],
        ],
      ],
    ];
  }

  private static function geography_countries_lite_definition(): array
  {
    return [
      [
        'label' => 'Region',
        'children' => [
          ['label' => 'Canada'],
          ['label' => 'United Kingdom'],
          ['label' => 'Australia'],
          ['label' => 'Germany'],
          ['label' => 'Japan'],
          ['label' => 'Mexico'],
          ['label' => 'Global'],
        ],
      ],
    ];
  }
}
