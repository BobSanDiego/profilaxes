# Profilaxes Consumer Integration Contract v1

Version: v0.4.3

## Purpose

Profilaxes is the profile/audience intelligence layer. Consumer plugins should use Profilaxes to resolve profile-based audiences without copying taxonomy, assignment, Meta-Group, or compiled relationship logic.

This contract is deliberately generic. It is not a Jobs contract, Chatboard contract, Classifieds contract, or Legal Q&A contract. It is the shared contract those plugins can consume.

## Consumer Plugins

Examples of consumers:

- Jobs
- Chatboards
- Lessons
- Classified ads
- Legal questions/resources
- Email digests
- Notifications
- Recommendation modules
- Advertising or sponsorship targeting
- Moderation and analytics tools

## Ownership Rules

| Concern | Owner |
|---|---|
| Terms | Profilaxes |
| Meta-Groups | Profilaxes |
| User assignments | Profilaxes |
| Matching logic | Profilaxes |
| Jobs/listings/posts/questions/boards | Consumer plugin |
| Display, notification, delivery, ranking | Consumer plugin |

## Audience v1 Shape

Audience v1 supports only Terms and Meta-Groups.

```php
$audience = [
  'framework' => 'teachers-net',
  'terms' => ['math', 'grade-3-5'],
  'meta_groups' => ['common-core-states'],
  'operator' => 'AND', // AND/ALL or OR/ANY
  'context' => 'profile',
  'include_descendants' => true,
  'limit' => 0,
  'offset' => 0,
];
```

## Primary Resolver

```php
$user_ids = CFM::resolve_users($audience);
```

Returns:

- `int[]` sorted WordPress user IDs.
- `[]` when the framework is missing, audience is empty, Meta-Groups are empty, UUIDs are stale, or no users match.

## Match Modes

| Mode | Meaning |
|---|---|
| `OR` / `ANY` | User matches at least one resolved target Term. |
| `AND` / `ALL` | User matches every resolved target Term. |

Meta-Groups expand to their included Term UUIDs before matching.

## Current Deliberate Limits

- Resolves users only.
- Does not resolve posts, jobs, listings, boards, or questions.
- Does not store saved audiences.
- Does not copy resolved user lists into consumer plugins.
- Does not rank matches.
- Does not add schema.

## Extension Pattern

```php
if (class_exists('CFM')) {
  $user_ids = CFM::resolve_users([
    'framework' => 'teachers-net',
    'terms' => ['math'],
    'meta_groups' => ['common-core-states'],
    'operator' => 'ANY',
  ]);
}
```

Consumer plugins should store their own targeting choices as Term UUIDs/slugs and Meta-Group UUIDs/slugs, then ask Profilaxes to resolve matching users at runtime.

## Future Expansion Candidates

Future versions may add:

- saved audience definitions
- Profile Fields
- weighted/ranked matching
- explain-match output
- count-only helpers
- richer result objects
- REST endpoints

Do not build consumer plugins against those future assumptions yet.
