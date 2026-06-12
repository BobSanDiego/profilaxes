<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Seeder
{
  public static function seed_teachers_net(): void
  {
    $framework_id = CFM_Framework_Repository::create_framework(
      'Teachers.Net Tax Framework',
      'teachers-net',
      'Baseline onboarding/profile framework for Teachers.Net.'
    );

    $tree = [
      'label' => 'Teachers.Net Tax Framework',
      'slug' => 'teachers-net',
      'type' => 'framework',
      'children' => [
        [
          'label' => 'Grade Level',
          'slug' => 'grade-level',
          'type' => 'axis',
          'children' => [
            [
              'label' => 'Early Childhood',
              'slug' => 'early-childhood',
              'type' => 'term',
              'children' => [
                ['label' => 'Pre-K', 'slug' => 'pre-k', 'type' => 'term'],
                ['label' => 'Transitional Kindergarten', 'slug' => 'transitional-kindergarten', 'type' => 'term'],
                ['label' => 'Kindergarten', 'slug' => 'kindergarten', 'type' => 'term'],
              ],
            ],
            [
              'label' => 'Elementary',
              'slug' => 'elementary',
              'type' => 'term',
              'children' => [
                ['label' => 'Grade 1', 'slug' => 'grade-1', 'type' => 'term'],
                ['label' => 'Grade 2', 'slug' => 'grade-2', 'type' => 'term'],
                ['label' => 'Grade 3', 'slug' => 'grade-3', 'type' => 'term'],
                ['label' => 'Grade 4', 'slug' => 'grade-4', 'type' => 'term'],
                ['label' => 'Grade 5', 'slug' => 'grade-5', 'type' => 'term'],
              ],
            ],
            ['label' => 'Middle School', 'slug' => 'middle-school', 'type' => 'term'],
            ['label' => 'High School', 'slug' => 'high-school', 'type' => 'term'],
            ['label' => 'Higher Education', 'slug' => 'higher-education', 'type' => 'term'],
          ],
        ],
        [
          'label' => 'Curriculum',
          'slug' => 'curriculum',
          'type' => 'axis',
          'children' => [
            ['label' => 'English / Language Arts', 'slug' => 'english-language-arts', 'type' => 'term'],
            ['label' => 'Math', 'slug' => 'math', 'type' => 'term'],
            ['label' => 'Science', 'slug' => 'science', 'type' => 'term'],
            ['label' => 'Social Studies', 'slug' => 'social-studies', 'type' => 'term'],
            ['label' => 'Special Education', 'slug' => 'special-education', 'type' => 'term'],
            ['label' => 'ESL / ELL', 'slug' => 'esl-ell', 'type' => 'term'],
            ['label' => 'Technology', 'slug' => 'technology', 'type' => 'term'],
            ['label' => 'Career & Technical Education', 'slug' => 'career-technical-education', 'type' => 'term'],
          ],
        ],
        [
          'label' => 'Region',
          'slug' => 'region',
          'type' => 'axis',
          'children' => [
            ['label' => 'United States', 'slug' => 'united-states', 'type' => 'term'],
            ['label' => 'Canada', 'slug' => 'canada', 'type' => 'term'],
            ['label' => 'United Kingdom', 'slug' => 'united-kingdom', 'type' => 'term'],
            ['label' => 'Australia', 'slug' => 'australia', 'type' => 'term'],
          ],
        ],
      ],
    ];

    CFM_Framework_Repository::create_version($framework_id, $tree, 'active');
  }
}
