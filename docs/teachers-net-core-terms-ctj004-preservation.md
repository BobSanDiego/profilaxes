# Teachers.Net Core Terms CTJ004 Preservation

Status: preserved taxonomy refinement
Ticket: CTJ004
Framework: `teachers-net`
Export: `teachers-net-core-terms-ctj004-taxonomy-export.json`

## Purpose

CTJ004 refined the active Teachers.Net Core Terms taxonomy after CTJ002 review.
The change formalizes the approved Grade Level hierarchy and keeps canonical
subject labels aligned with the Jobs seed dataset without adding alias logic to
the Jobs plugin.

## Storage And Versioning

Core Terms stores the source taxonomy in `wp_cfm_framework_versions.tree_json`.
Runtime tables such as `wp_cfm_terms_compiled`, `wp_cfm_term_closure`, and
`wp_cfm_term_relationships` are rebuildable from that source tree.

The local database contains `pre_ctj004_snapshot` framework versions created
before the CTJ004 refinement was applied. Those snapshots are useful as local
rollback points. The JSON export in this directory is the portable preservation
artifact.

## Grade Hierarchy

The preserved Grade Level hierarchy is:

- Grade Level
  - Early Childhood
    - Early Learners
    - Pre-K
    - Transitional Kindergarten
    - Kindergarten
  - Elementary
    - Grade 1
    - Grade 2
    - Grade 3
    - Grade 4
    - Grade 5
  - Middle School
    - Grade 6
    - Grade 7
    - Grade 8
  - High School
    - Grade 9
    - Grade 10
    - Grade 11
    - Grade 12
  - Adult Education
  - Higher Education

## Renamed Term

| Previous label | Preserved label | Slug | Short label | UUID behavior |
| --- | --- | --- | --- | --- |
| Early Learning | Early Learners | `early-learners` | Early Learners | Existing UUID preserved |

## Canonical Labels Retained

| Term | Slug | Short label |
| --- | --- | --- |
| Elementary | `elementary` | Elementary |
| Mathematics | `mathematics` | Math |
| English Language Arts | `english-language-arts` | ELA |
| English Learners / ESL | `english-learners-esl` | ESL/ELL |
| Physical Education / Health | `physical-education-health` | PE/Health |
| Career Technical Education | `career-technical-education` | CTE |
| Transitional Kindergarten | `transitional-kindergarten` | TK |
| Reading / Literacy | `reading-literacy` | Reading/Lit |

## Subject Hierarchy Retained

- World Languages
  - Spanish
- Social Studies
  - History
- Science
  - Biology
- Technology
  - Computer Science

First-class subject terms retained:

- Counseling
- Reading / Literacy
- Library / Media

## Restoration Path

Use the existing Core Terms Data workflow:

1. Open Core Terms > Data.
2. Import `teachers-net-core-terms-ctj004-taxonomy-export.json`.
3. Preview the import before replacing the active tree.
4. Replace the active `teachers-net` taxonomy only after confirming the target
   framework and tree.
5. Rebuild/compile runtime tables if the import workflow does not do so
   automatically.
6. Rerun the Jobs seed importer.
7. Confirm the Jobs seed dataset maps with zero missing Grade and zero missing
   Subject assignments.

Do not restore this snapshot by editing compiled runtime tables directly.

## Future Work

Alias or synonym support remains future Core Terms work. Jobs should continue
to consume Core Terms terminology rather than hardcoding label substitutions.

## Verification Baseline

After CTJ004 preservation:

- Core Terms compiles successfully.
- Jobs seed importer maps 250 of 250 seed jobs to Grade terms.
- Jobs seed importer maps 250 of 250 seed jobs to Subject terms.
- Grade and Subject filters continue to return matching Jobs results.
- The Jobs plugin and seed JSON remain unchanged by CTJ004.
