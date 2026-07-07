# Teachers.Net Core Terms CTJ002 Preservation

Status: preserved taxonomy snapshot
Ticket: CTJ002
Framework: `teachers-net`
Export: `teachers-net-core-terms-ctj002-taxonomy-export.json`

## Purpose

CTJ002 aligned the active Teachers.Net Core Terms taxonomy with the R001/J159 Jobs seed dataset so seed job Grade and Subject classifications can be mapped through Core Terms instead of hardcoded in the Jobs plugin.

This document records the taxonomy changes and points to the portable export needed to restore the same active classification state if the local database is rebuilt.

## Storage And Versioning

Core Terms stores the source taxonomy in `wp_cfm_framework_versions.tree_json`.
Runtime tables such as `wp_cfm_terms_compiled`, `wp_cfm_term_closure`, and `wp_cfm_term_relationships` are rebuildable from that source tree.

The local database also contains a `pre_ctj002_snapshot` framework version created before CTJ002 changes were applied. That snapshot is useful as a local rollback point, but it is not portable across rebuilds. The JSON export in this directory is the portable preservation artifact.

## Renamed Terms

| Previous label | Preserved label | Slug | Short label |
| --- | --- | --- | --- |
| Elementary School | Elementary | `elementary` | Elementary |
| Math | Mathematics | `mathematics` | Math |
| English / Language Arts | English Language Arts | `english-language-arts` | ELA |
| ESL / ELL | English Learners / ESL | `english-learners-esl` | ESL/ELL |
| PE / Health | Physical Education / Health | `physical-education-health` | PE/Health |
| CTE | Career Technical Education | `career-technical-education` | CTE |

## Added Terms

| Parent | Added term | Slug | Short label |
| --- | --- | --- | --- |
| Grade Level > Early Childhood | Transitional Kindergarten | `transitional-kindergarten` | TK |
| Subject Area | Counseling | `counseling` | Counseling |
| Subject Area | Reading / Literacy | `reading-literacy` | Reading/Lit |
| Subject Area | Library / Media | `library-media` | Library/Media |
| Subject Area > World Languages | Spanish | `spanish` | Spanish |
| Subject Area > Social Studies | History | `history` | History |
| Subject Area > Technology | Computer Science | `computer-science` | Computer Science |
| Subject Area > Science | Biology | `biology` | Biology |

## Restoration Path

Use the existing Core Terms Data workflow:

1. Open Core Terms > Data.
2. Import `teachers-net-core-terms-ctj002-taxonomy-export.json`.
3. Preview the import before replacing the active tree.
4. Replace the active `teachers-net` taxonomy only after confirming the target framework and tree.
5. Rebuild/compile runtime tables if the import workflow does not do so automatically.
6. Rerun the Jobs seed importer.
7. Confirm the Jobs seed dataset maps with zero missing Grade and zero missing Subject assignments.

Do not restore this snapshot by editing compiled runtime tables directly.

## Verification Baseline

After CTJ002 preservation:

- Core Terms compiles successfully.
- Jobs seed importer maps 250 of 250 seed jobs to Grade terms.
- Jobs seed importer maps 250 of 250 seed jobs to Subject terms.
- The Jobs plugin does not hardcode taxonomy aliases or display substitutions.
- `jobs-seed.json` remains unchanged by CTJ002.
