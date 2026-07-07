# Core Terms Capability Snapshot

Status: Current production capability snapshot
Plugin: Core Terms / Profilaxes

## 1. Overview

Core Terms provides the shared classification and membership foundation for Teachers.Net.

It can maintain a hierarchical Core Terms tree, assign users to terms, compile runtime lookup tables, preserve archives of removed branches, and expose high-level extension points for other plugins that need to report active term usage.

This document answers what the plugin can do today. It is not an architecture document, roadmap, implementation note, or project history.

## 2. Core Terms Editor

The Core Terms Editor is the primary admin workbench for managing the active term hierarchy.

Current capabilities:

- View the complete Core Terms hierarchy as a collapsible tree.
- Select rows independently from expand/collapse navigation.
- Inline edit existing term fields:
  - Label
  - Slug
  - Short Label
  - Community
- Track dirty rows and protect unsaved edits.
- Save all pending changes.
- Save an individual dirty row.
- Reset unsaved changes.
- Insert draft sibling rows before or after an existing term.
- Add draft child rows, including prepend/append placement for existing parents.
- Auto-generate default slug, short label, and community values from labels.
- Drag reorder siblings within the same parent.
- Drag an arbitrary branch before, after, or into another valid row.
- Move a branch through an explicit Move To workflow.
- Preserve descendants when moving or archiving a branch.
- Archive a branch from the editor.
- Restore immediately through Undo Archive when available.
- Show Undo Move after a successful branch move.
- Clear transient move undo when another structural edit begins.
- Collapse all expanded branches from the editor status rail.
- Block reorder/move actions while dirty or draft rows exist.
- Preserve expanded tree state and editor position across supported save flows.

## 3. Archives

The Archived Terms page manages branches removed from the active Core Terms tree.

Current capabilities:

- List restorable archived branches.
- Show archive age.
- Show branch name and descendant count.
- Restore an archived branch.
- Soft-delete an archived record.
- Hide restored and deleted archives from the default operational list.
- Show active connection counts from registered providers.
- Preserve archived branch data for restore/delete decisions.

## 4. Data

The Data page groups taxonomy data-management tools.

Current capabilities:

- Import taxonomy JSON.
- Export taxonomy JSON.
- Quick Add / Bulk Add terms.
- Install example packs.
- Access versions, snapshots, and restore flows.
- Preserve existing import/export and restore behavior.

## 5. Meta-Groups

Meta-Groups define reusable groupings of Core Terms.

Current capabilities:

- List Meta-Groups.
- Create Meta-Groups.
- Edit Meta-Groups.
- Maintain the four standard fields:
  - Label
  - Slug
  - Short Label
  - Community
- Select included Core Terms from a collapsible tree.
- Preserve checked/selected term state.
- Use the same field guidance pattern as the Core Terms Editor.

## 6. Maintenance

The Maintenance page contains operational tools for keeping compiled runtime data healthy.

Current capabilities:

- Rebuild / compile active runtime tables.
- Review compile status.
- Access compiled query debug tools.
- Keep maintenance workflows separate from daily editing.

## 7. Users

Core Terms supports user membership assignment and inspection.

Current capabilities:

- Assign Core Terms to WordPress users.
- Store user-term assignments by term UUID.
- Retrieve direct and effective user terms.
- Count users assigned to a term or branch.
- Resolve users by term or Meta-Group criteria.
- Display assigned Core Terms on user/profile admin surfaces where enabled.
- Preserve Labs/user inspection tools for diagnostics and future product discovery.

## 8. Extension API

Core Terms exposes a high-level extension path for other plugins.

Current capabilities:

- Allow providers to register Active Connections sources.
- Pass archive branch context to registered providers.
- Render provider counts on Archived Terms.
- Include a built-in User Members provider.
- Allow external plugins, such as Jobs, to report usage without Core Terms owning their data.

## 9. Current Production Status

Core Terms is the current production baseline for Teachers.Net classification and user membership infrastructure.

Current status:

- The Core Terms Editor is the canonical admin workbench.
- Legacy edit routes remain available for compatibility.
- Dashboard opens the editor when a framework exists.
- Archives, Data, Meta-Groups, and Maintenance each own their current workflow area.
- Runtime data remains rebuildable from source taxonomy data.
- Internal `profilaxes`, `CFM`, and `cfm_` names remain compatibility details and should not be renamed casually.

## 10. Out of Scope / Future

Current out-of-scope items:

- Full plugin rename or internal namespace migration.
- Schema/table renaming.
- Dragging across arbitrary systems outside Core Terms.
- Permanent hard deletion of active terms.
- Dependency blocking based on Active Connections.
- Subscriber-plugin-specific reporting beyond registered providers.
- Chatboards or Lesson Plans connection providers.
- Public frontend rendering.
- Jobs-specific business logic.
- Notification, recommendation, or analytics dashboards.
- Broad admin redesign beyond the current Core Terms surfaces.
