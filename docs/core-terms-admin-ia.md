# Core Terms Admin Information Architecture

Status: Current admin organization
Plugin: Core Terms / Profilaxes

## 1. Overview

The Core Terms admin area is organized around the workflows administrators use to maintain the Teachers.Net classification platform. The Dashboard and Editor are the daily work surfaces. Archives, Data, Meta-Groups, Maintenance, and Users separate operational tasks so administrators do not need to use legacy pages for normal work.

This document explains where administrators go to perform each workflow. It is not code documentation, project history, implementation detail, or roadmap speculation.

## 2. Admin Menu Structure

Current Core Terms admin surfaces:

- Dashboard
- Editor
- Archives
- Data
- Meta-Groups
- Maintenance
- Users
- Legacy Compatibility

## 3. Page Responsibilities

### Dashboard

Purpose:
The Dashboard is the main Core Terms landing page.

Primary workflows:

- Open the Core Terms Editor by default when a framework exists.
- Provide the primary entry point for day-to-day taxonomy management.

Related route/action:

- `admin.php?page=cfm-frameworks`

Notes:

- When no framework exists, the Dashboard preserves the empty-state / create-framework path.
- The Dashboard is the preferred starting point for administrators.

### Editor

Purpose:
The Editor is the canonical workbench for managing the active Core Terms hierarchy.

Primary workflows:

- View and expand the term tree.
- Edit existing terms.
- Insert siblings.
- Add children.
- Reorder siblings.
- Move branches.
- Archive branches.
- Undo supported recent structural moves.

Related route/action:

- `admin.php?page=cfm-frameworks&action=editor`

Notes:

- The Editor owns the primary edit, move, archive, and reorder workflows.
- Administrators should use the Editor instead of legacy term-row actions.

### Archives

Purpose:
Archives manages branches removed from the active Core Terms tree.

Primary workflows:

- Review restorable archived branches.
- Review archive age and active connection counts.
- Restore archived branches.
- Soft-delete archived archive records.

Related route/action:

- `admin.php?page=cfm-frameworks&action=archived_terms`

Notes:

- The default Archives view is operational, not historical.
- Restored and deleted archives are not part of the default restore list.

### Data

Purpose:
Data groups taxonomy import, export, quick-add, example-pack, and version workflows.

Primary workflows:

- Import taxonomy JSON.
- Export taxonomy JSON.
- Quick Add / Bulk Add terms.
- Install example packs.
- Access versions, snapshots, and restore flows.

Related route/action:

- `admin.php?page=cfm-frameworks&action=data`

Notes:

- Data owns taxonomy movement and bulk data workflows.
- Version routes remain available from this area.

### Meta-Groups

Purpose:
Meta-Groups manages reusable groupings of Core Terms.

Primary workflows:

- List Meta-Groups.
- Create Meta-Groups.
- Edit Meta-Groups.
- Select included terms.

Related route/action:

- `admin.php?page=cfm-frameworks&action=meta_groups`
- `admin.php?page=cfm-frameworks&action=edit_meta_group`

Notes:

- Meta-Groups remain separate from the main Core Terms hierarchy editor.
- Existing create/update behavior is preserved.

### Maintenance

Purpose:
Maintenance contains operational and diagnostic tools for keeping compiled runtime data healthy.

Primary workflows:

- Rebuild / compile active runtime tables.
- Review compile status.
- Access compiled query debug tools.
- Access existing diagnostics or smoke-test links where available.

Related route/action:

- `admin.php?page=cfm-frameworks&action=maintenance`
- `admin.php?page=cfm-frameworks&action=compiled_debug`

Notes:

- Maintenance is for operational support, not routine taxonomy editing.

### Users

Purpose:
Users surfaces handle user membership assignment and inspection.

Primary workflows:

- Assign Core Terms to users.
- Inspect user terms.
- Review profile statistics and Labs-style diagnostics where available.

Related route/action:

- WordPress Users admin pages registered by Core Terms.

Notes:

- User-facing admin tools remain under Users to keep membership work close to WordPress user management.

### Legacy Compatibility

Purpose:
Legacy compatibility keeps older routes available without making them primary workflows.

Primary workflows:

- Reference the older framework summary and term table when needed.
- Support direct visits to legacy routes during the compatibility period.

Related route/action:

- `admin.php?page=cfm-frameworks&action=edit`
- `admin.php?page=cfm-frameworks&action=edit_term`
- `admin.php?page=cfm-frameworks&action=move_term`
- `admin.php?page=cfm-frameworks&action=archive_term`

Notes:

- Legacy compatibility is not the normal admin workflow.
- Active editing, moving, reordering, and archiving belong in the Editor.

## 4. Legacy Compatibility

The legacy `action=edit` page remains available as a reference and compatibility surface only. It is not the primary place to add, edit, move, reorder, or archive Core Terms.

Legacy direct routes remain available for compatibility and safety, but normal administrator workflows should use the Dashboard, Editor, Archives, Data, Meta-Groups, Maintenance, and Users surfaces.

## 5. Current Status

The Core Terms admin information architecture is complete enough to support the Jobs sprint.

Current status:

- Dashboard opens the Editor when a framework exists.
- Editor owns active taxonomy management.
- Archives owns restore/delete decisions for archived branches.
- Data owns import/export, bulk add, example packs, and versions.
- Meta-Groups owns Meta-Group management.
- Maintenance owns rebuild/status/debug workflows.
- Users owns membership assignment and user-oriented inspection.
- Legacy compatibility remains available but is no longer a primary workflow.
