# Core Terms Fresh Install Validation

Version validated: v0.5.5 working checkpoint
Document purpose: capture clean-install observations before deciding whether Core Terms is ready for v0.6.0 stabilization.

## Context

A clean validation pass was performed after uninstalling the plugin from WordPress and manually dropping existing `wp_cfm_*` tables in the DDEV database.

This exposed one important operational finding:

- WordPress plugin uninstall removed plugin files from the plugin directory.
- Plugin data tables were not automatically removed.
- Fresh validation required manually dropping `wp_cfm_*` tables.
- Git recovery was required because the plugin repository lived inside the plugin directory.

## Database reset performed

The following tables were removed manually before reinstall / activation:

- `wp_cfm_user_terms`
- `wp_cfm_term_relationships`
- `wp_cfm_terms_compiled`
- `wp_cfm_meta_groups`
- `wp_cfm_frameworks`
- `wp_cfm_term_closure`
- `wp_cfm_framework_versions`

After removal:

- `SHOW TABLES LIKE 'wp_cfm%';` returned no tables.

## Reinstall / activation

Plugin was restored from GitHub and activated successfully.

Observed result:

- Core Terms plugin activated.
- Core Terms admin menu was available.
- Fresh framework/profile-taxonomy object was created successfully.
- Geography example packs appeared in the admin UI.
- Geography — US States pack was installable.
- Generated terms appeared in downstream statistics/Labs views.

## Validation observations

### Passed

- Fresh activation path works after database reset.
- Plugin tables are recreated after activation.
- Example Term Packs appear in the View/Edit Terms admin surface.
- Geography — US States pack creates `Region → United States → states/DC`.
- Geography terms appear in Term Statistics / Labs-style views.
- Core compile behavior is automatic enough that users should not be asked to manually compile terms in normal workflow.
- Geography example packs are useful and should remain.
- Avoiding timezone metadata for v0.5.x remains the right call.
- Geography terms should remain normal user-created-style terms after installation.
- No Jobs functionality was introduced during Core Terms stabilization.

### Needs correction before v0.6.0

#### Vocabulary cleanup still incomplete

Some UI still leaks older language:

- “Profile Taxonomy”
- “Profile Assignments”
- “Sandbox Profiles”
- “profile selections”
- “profile terms”

Preferred language:

- “Core Terms”
- “Terms”
- “User Assignments”
- “term selections”
- “assigned terms”

#### Assignment screen needs usability improvements

Observed assignment screen issue:

- Section currently reads like “Profile Assignments.”
- It should read “User Assignments.”
- Assignment tree should support collapse/expand behavior similar to included-term/meta-group screens.
- Parent/child behavior needs explicit rules.

#### Top-level ordering issue

Observed ordering issue:

- Top-level terms are not currently reorderable.
- Top-level axes such as `Region` should be reorderable.
- Ordering should remain sibling-scoped, but top-level terms are siblings and should participate.

#### Example pack install-state awareness

Current geography pack buttons remain actionable after installation.

Deferred UX improvement:

- If no pack terms exist: `Install`
- If some pack terms exist: `Add Missing Terms`
- If all expected pack terms exist: disabled `Installed`
- No uninstall/reset behavior for now.

#### Future authoring UX

Deferred UX concept:

- Inline tree authoring.
- Add child from a `+` affordance.
- Add sibling / add another flow.
- Quick bulk insert remains useful as near-term authoring support.

## Assignment logic discussion item

Location-style terms create a parent/child selection issue.

Example:

- `United States`
- `California`

Desired behavior:

- User may be assigned `United States` alone if state is unknown.
- Selecting `California` should imply/select `United States`.
- Need decision on whether parent can be unchecked while child remains selected.

Recommended next decision:

- If any child is selected, parent should remain checked/locked or auto-rechecked.
- To remove the parent, user must first remove child selections.
- This preserves hierarchy logic and avoids contradictory assignment states.

Open question:

- Should users be allowed multiple states?
- Core Terms should likely allow multi-select because it is generic infrastructure.
- A future consumer may restrict state/location assignment to one value when appropriate.

## Deferred timezone metadata

Timezone remains important for future Jobs/email alert scheduling.

Decision for now:

- Do not add term metadata in v0.5.x.
- Geography packs should not include timezone metadata yet.
- Future Jobs/Alerts work may justify a `term_meta` layer using IANA timezone identifiers.

Likely future shape:

- `term_id`
- `meta_key`
- `meta_value`

Example:

- California → `timezone = America/Los_Angeles`

## Current readiness assessment

Core Terms is close to v0.6.0, but not ready to tag yet.

Reason:

- Clean install works.
- Geography packs work.
- Labs/statistics surfaces work.
- Remaining issues are mostly vocabulary and assignment UI/logic.

## Recommended next revision

### v0.5.6 — Assignment UX + Final Vocabulary Cleanup

Scope:

- Rename “Profile Assignments” to “User Assignments.”
- Remove remaining profile/taxonomy vocabulary from normal user-facing flow.
- Make top-level terms reorderable.
- Add collapse/expand behavior to assignment tree.
- Clarify parent/child assignment rules.
- Do not add Jobs code.
- Do not add schema changes.
- Do not add timezone metadata.

Exit condition:

- Fresh install feels coherent as Core Terms.
- User can install example geography terms.
- User can assign terms without confusing profile/taxonomy language.
- v0.6.0 stabilization tag becomes reasonable.
