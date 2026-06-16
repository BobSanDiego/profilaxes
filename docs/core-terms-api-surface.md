# Core Terms API Surface

Revision: v0.5.3-r1
Status: Stabilization note
Codebase: Profilaxes
Working name: Core Terms

## Purpose

This document defines the current Core Terms public surface for stabilization.

It exists to prevent future development from confusing stable Core Terms infrastructure with Labs experiments or legacy compatibility layers.

Core Terms owns structure.
Consumers own experiences.

---

## Stability Categories

### Stable Core Terms Contract

These surfaces are part of the Core Terms stabilization target.

They may evolve internally, but consumers should be able to rely on their purpose and behavior after the v0.6.0 stabilization milestone.

Includes:

- term hierarchy
- term lookup
- term assignment
- effective assignment resolution
- compiled relationships
- stable term IDs / UUIDs
- compile and rebuild entrypoints
- extension hooks

### Labs / Experimental

These surfaces are useful and preserved, but they are not part of the frozen Core Terms contract.

They may remain in the plugin as diagnostics, incubator tools, or future consumer candidates.

They may later graduate into:

- Jobs
- Profiles / Onboarding
- Tracking & Intelligence
- Audience Tools
- Recruiter Tools

They should not drive Core Terms architecture.

### Legacy Compatibility

These names, classes, tables, or concepts exist because the project evolved from Community Framework Manager into Profilaxes / Core Terms.

They may remain temporarily for compatibility and migration safety.

They should not be expanded as new architecture.

---

## Stable Core Terms Contract

### Term Tree / Hierarchy

Core Terms must expose term structure in a way consumers can safely read.

Expected capabilities:

- list terms
- read parent / child relationships
- read ancestors
- read descendants
- read siblings
- identify axes / top-level groups

Representative methods:

- `get_terms()`
- `get_term_by_slug()`
- `get_descendants()`
- `get_ancestors()`
- `get_siblings()`

### Assignment

Core Terms must own assignment of users or entities to terms.

Expected capabilities:

- assign terms
- replace assignments
- retrieve direct assignments
- retrieve effective assignments
- retrieve assigned term UUIDs

Representative methods:

- `get_user_terms()`
- `get_user_effective_terms()`
- `get_user_term_uuids()`
- `set_user_terms()`
- `user_has_term()`

### Compilation / Rebuild

Core Terms may store compiled relationships for runtime efficiency, but compiled data must remain rebuildable from source data.

Expected capabilities:

- compile term hierarchy
- rebuild relationships
- preserve recovery confidence
- expose compile lifecycle hooks

Representative hooks:

- `cfm_before_compile`
- `cfm_after_compile`

### Term Lifecycle Hooks

Consumers may observe term changes without modifying Core Terms internals.

Representative hooks:

- `cfm_term_created`
- `cfm_term_updated`
- `cfm_term_moved`
- `cfm_term_deleted`

### Assignment Hooks

Consumers may observe assignment changes without modifying Core Terms internals.

Representative hooks:

- `cfm_before_user_terms_save`
- `cfm_after_user_terms_save`

---

## Labs / Experimental Surface

Labs preserves useful exploratory tools without making them permanent Core Terms obligations.

Labs surfaces may be used for development, diagnostics, proving concepts, or future product discovery.

They are not part of the v1 Core Terms public contract.

### Audience Resolution

These helpers answer questions like:

- Which users match these terms?
- Does this user match this target term recipe?
- Can this term profile define a useful audience?

Representative methods:

- `resolve_users()`
- `matches()`
- `find_users()`

Current status:

- preserved
- useful
- not deleted
- not stable v1 contract
- eligible for future graduation

Possible future homes:

- Audience Tools
- Tracking & Intelligence
- Jobs targeting
- Newsletter targeting
- Recruiter Tools

### Labs Admin Screens

These screens help inspect, test, or visualize term behavior.

Representative tools:

- Labs: Audience Explorer
- Labs: Inspect User Profile
- Labs: Profile Statistics
- Segmentation / smoke-test tools

Current status:

- useful for development
- not core infrastructure
- not part of Core Terms beta acceptance

---

## Legacy Compatibility Surface

The codebase still includes names from the original Community Framework Manager era.

Examples:

- `CFM`
- `CFM_Framework_Repository`
- `framework` terminology
- framework-related table names or fields

Current status:

- tolerated for compatibility
- should not expand
- may be renamed only after stabilization and migration safety review

Rule:

Do not perform large renames during Core Terms stabilization unless required for correctness.

---

## Consumer Boundary

Consumers may use Core Terms to understand structure and assignments.

Consumers must own their own product behavior.

Examples:

### Jobs owns

- jobs
- employers
- job_terms relationships
- browse
- publish
- recruiter workflows
- paid placement
- job analytics

### Profiles / Onboarding owns

- profile text fields
- onboarding screens
- preferences
- visibility rules

### Tracking & Intelligence owns

- views
- clicks
- activity scores
- reporting
- insight generation

Core Terms must not absorb these responsibilities.

---

## Gate for v0.6.0

Core Terms may reach v0.6.0 when:

- clean install works
- terms compile
- assignments work
- stable APIs are documented
- hooks exist
- Labs are clearly separated
- future consumers can integrate without redesigning Core Terms

Core Terms does not need to be:

- beautiful
- feature complete
- permanently named
- a full audience platform
- a Jobs plugin

---

## Development Rule

When adding new work, classify it before coding:

1. Core Terms stable contract
2. Labs / Experimental
3. Legacy compatibility
4. Consumer-owned feature

If the work is consumer-owned, do not add it to Core Terms unless it is only an integration hook or neutral infrastructure seam.
