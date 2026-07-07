# Core Terms Integration Contract

Status: Current consumer integration contract
Audience: Teachers.Net plugin developers
Plugin: Core Terms / Profilaxes

## 1. Philosophy

Core Terms owns the authoritative Teachers.Net taxonomy. Consumer plugins own their own data, workflows, rendering, and business rules.

Consumer plugins should reference Core Terms by stable term UUIDs and public APIs. They should never edit Core Terms source taxonomy data directly, write directly to Core Terms tables, or duplicate taxonomy management logic inside their own code.

## 2. Public APIs

Consumer plugins should treat Core Terms as the shared classification platform.

High-level API areas include:

- Term lookup and hierarchy traversal.
- User membership assignment and retrieval.
- Effective user term resolution.
- Meta-Group lookup and included-term resolution.
- Audience/user resolution by terms or Meta-Groups.
- Active Connections provider registration.

Public APIs should be used through the `CFM` class and documented extension points. Internal helpers, repository internals, admin handlers, and table implementation details are not part of the consumer contract.

## 3. Active Connections

Active Connections let consumer plugins report where archived Core Terms branches are still used without Core Terms directly querying subscriber plugin data.

Provider registry filter:

```php
cfm_term_connection_sources
```

Provider context:

- `framework_id`
- `archive_key`
- `archive_id`
- `root_term_uuid`
- `branch_term_uuids`
- `branch_label`

Expected provider return structure:

```php
[
  [
    'label' => 'Job Listings',
    'count' => 12,
  ],
]
```

Rules:

- Providers should return counts only.
- Providers should count records owned by their own plugin.
- Providers should use `branch_term_uuids` to identify matching Core Terms references.
- Providers should omit unavailable sources rather than returning placeholder future systems.
- Providers should not mutate Core Terms data.

## 4. Consumer Responsibilities

### Jobs

Jobs owns job listings, employer workflows, job search, job alerts, and listing lifecycle behavior.

Jobs may reference Core Terms UUIDs for classification and may report active job-listing usage through the Active Connections provider model.

### Chatboards

Chatboards owns discussion data, routing, moderation, display, and notification behavior.

Chatboards may reference Core Terms UUIDs for classification when integration is implemented.

### Lesson Bank

Lesson Bank owns lesson data, lesson workflows, search, display, and editorial behavior.

Lesson Bank may reference Core Terms UUIDs for classification when integration is implemented.

### Future Plugins

Future Teachers.Net modules should follow the same model:

- Store their own records.
- Reference Core Terms UUIDs where classification is needed.
- Use Core Terms public APIs for lookup/resolution.
- Register Active Connections providers when archive usage reporting is needed.
- Avoid writing directly to Core Terms taxonomy data.

## 5. Compatibility Rules

The following names are compatibility surfaces and must not be renamed casually:

- `CFM`
- `cfm_`
- `wp_cfm_*`
- public Core Terms APIs

These names may remain even when the visible product name is Core Terms. Any rename or migration must be intentional, versioned, and compatibility-reviewed.

## 6. Versioning

Public Core Terms APIs should be treated as stable unless they are intentionally versioned.

Consumer plugins should avoid depending on undocumented internals. If a consumer needs behavior that is not exposed through a public API or documented extension point, the integration should be paused and Core Terms should add a deliberate public surface rather than encouraging direct access to internals.
