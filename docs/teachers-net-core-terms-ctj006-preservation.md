# Teachers.Net Core Terms CTJ006 Preservation

Status: preserved compact display-label refinement
Ticket: CTJ006
Framework: `teachers-net`
Export: `teachers-net-core-terms-ctj006-taxonomy-export.json`

## Purpose

CTJ006 applies Engineering Director-approved compact display labels to the
active Teachers.Net Core Terms taxonomy. Only Core Terms `short_label` values
were changed.

Canonical labels, slugs, UUIDs, hierarchy, Jobs plugin code, Jobs importer code,
and `jobs-seed.json` were not changed.

## Storage And Versioning

Core Terms stores the source taxonomy in `wp_cfm_framework_versions.tree_json`.
Runtime tables such as `wp_cfm_terms_compiled`, `wp_cfm_term_closure`, and
`wp_cfm_term_relationships` are rebuildable from that source tree.

The local database contains `pre_ctj006_snapshot` framework versions created
before the CTJ006 short-label refinement was applied. Those snapshots are local
rollback points. The JSON export in this directory is the portable preservation
artifact.

## Approved Compact Labels Applied

| Canonical label | Compact display label |
| --- | --- |
| Grade Level | Grades |
| Subject Area | Subject |
| Higher Education | Higher Ed |
| Special Education | SPED |
| Elementary | Elem |
| Middle School | MS |
| High School | HS |
| Computer Science | Comp Sci |
| Technology | Tech |
| World Languages | Lang |

## State And District Labels Applied

All represented U.S. state terms and District of Columbia now use USPS postal
abbreviations as compact display labels:

| Canonical label | Compact display label |
| --- | --- |
| Alabama | AL |
| Alaska | AK |
| Arizona | AZ |
| Arkansas | AR |
| California | CA |
| Colorado | CO |
| Connecticut | CT |
| Delaware | DE |
| District of Columbia | DC |
| Florida | FL |
| Georgia | GA |
| Hawaii | HI |
| Idaho | ID |
| Illinois | IL |
| Indiana | IN |
| Iowa | IA |
| Kansas | KS |
| Kentucky | KY |
| Louisiana | LA |
| Maine | ME |
| Maryland | MD |
| Massachusetts | MA |
| Michigan | MI |
| Minnesota | MN |
| Mississippi | MS |
| Missouri | MO |
| Montana | MT |
| Nebraska | NE |
| Nevada | NV |
| New Hampshire | NH |
| New Jersey | NJ |
| New Mexico | NM |
| New York | NY |
| North Carolina | NC |
| North Dakota | ND |
| Ohio | OH |
| Oklahoma | OK |
| Oregon | OR |
| Pennsylvania | PA |
| Rhode Island | RI |
| South Carolina | SC |
| South Dakota | SD |
| Tennessee | TN |
| Texas | TX |
| Utah | UT |
| Vermont | VT |
| Virginia | VA |
| Washington | WA |
| West Virginia | WV |
| Wisconsin | WI |
| Wyoming | WY |

## Restoration Path

Use the existing Core Terms Data workflow:

1. Open Core Terms > Data.
2. Import `teachers-net-core-terms-ctj006-taxonomy-export.json`.
3. Preview the import before replacing the active tree.
4. Replace the active `teachers-net` taxonomy only after confirming the target
   framework and tree.
5. Rebuild/compile runtime tables if the import workflow does not do so
   automatically.
6. Rerun the Jobs seed importer.
7. Confirm the Jobs seed dataset maps with zero missing Grade and zero missing
   Subject assignments.

Do not restore this snapshot by editing compiled runtime tables directly.

## Verification Baseline

After CTJ006 preservation:

- Core Terms compiles successfully.
- Jobs seed importer maps 250 of 250 seed jobs to Grade terms.
- Jobs seed importer maps 250 of 250 seed jobs to Subject terms.
- Grade and Subject filters continue to return matching Jobs results.
- Jobs search continues to return matching Jobs results.
- The Jobs plugin and seed JSON remain unchanged by CTJ006.
