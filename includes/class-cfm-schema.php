<?php

if (!defined('ABSPATH')) {
    exit;
}

class CFM_Schema
{
    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $frameworks = $wpdb->prefix . 'cfm_frameworks';
        $versions   = $wpdb->prefix . 'cfm_framework_versions';
        $terms      = $wpdb->prefix . 'cfm_terms_compiled';
        $closure       = $wpdb->prefix . 'cfm_term_closure';
        $relationships = $wpdb->prefix . 'cfm_term_relationships';
        $user_terms    = $wpdb->prefix . 'cfm_user_terms';
        $meta_groups   = $wpdb->prefix . 'cfm_meta_groups';

        dbDelta("
            CREATE TABLE {$frameworks} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                framework_uuid CHAR(36) NOT NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                description TEXT NULL,
                active_version_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY framework_uuid (framework_uuid),
                UNIQUE KEY slug (slug)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$versions} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                framework_id BIGINT UNSIGNED NOT NULL,
                version_number BIGINT UNSIGNED NOT NULL,
                tree_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                compiled_at DATETIME NULL,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY framework_id (framework_id),
                KEY status (status),
                UNIQUE KEY framework_version (framework_id, version_number)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$terms} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                framework_id BIGINT UNSIGNED NOT NULL,
                version_id BIGINT UNSIGNED NOT NULL,
                term_uuid CHAR(36) NOT NULL,
                parent_uuid CHAR(36) NULL,
                axis_uuid CHAR(36) NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'term',
                label VARCHAR(190) NOT NULL,
                short_label VARCHAR(190) NULL,
                slug VARCHAR(190) NOT NULL,
                description TEXT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                depth INT UNSIGNED NOT NULL DEFAULT 0,
                path TEXT NULL,
                visibility_contexts_json TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY framework_version (framework_id, version_id),
                KEY term_uuid (term_uuid),
                KEY parent_uuid (parent_uuid),
                KEY axis_uuid (axis_uuid),
                KEY kind (kind),
                KEY slug (slug),
                KEY depth (depth)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$closure} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                framework_id BIGINT UNSIGNED NOT NULL,
                version_id BIGINT UNSIGNED NOT NULL,
                ancestor_term_uuid CHAR(36) NOT NULL,
                descendant_term_uuid CHAR(36) NOT NULL,
                depth INT UNSIGNED NOT NULL,
                PRIMARY KEY  (id),
                KEY framework_version (framework_id, version_id),
                KEY ancestor_term_uuid (ancestor_term_uuid),
                KEY descendant_term_uuid (descendant_term_uuid),
                UNIQUE KEY closure_unique (framework_id, version_id, ancestor_term_uuid, descendant_term_uuid)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$relationships} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                framework_id BIGINT UNSIGNED NOT NULL,
                version_id BIGINT UNSIGNED NOT NULL,
                source_term_uuid CHAR(36) NOT NULL,
                target_term_uuid CHAR(36) NOT NULL,
                relationship_type VARCHAR(50) NOT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY framework_version (framework_id, version_id),
                KEY source_term_uuid (source_term_uuid),
                KEY target_term_uuid (target_term_uuid),
                KEY relationship_type (relationship_type),
                UNIQUE KEY relationship_unique (framework_id, version_id, source_term_uuid, target_term_uuid, relationship_type)
            ) {$charset_collate};
        ");

        dbDelta("
            CREATE TABLE {$user_terms} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                framework_id BIGINT UNSIGNED NOT NULL,
                term_uuid CHAR(36) NOT NULL,
                context VARCHAR(50) NOT NULL DEFAULT 'profile',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY framework_id (framework_id),
                KEY term_uuid (term_uuid),
                KEY context (context),
                UNIQUE KEY user_framework_term_context (user_id, framework_id, term_uuid, context)
            ) {$charset_collate};
        ");


        dbDelta("
            CREATE TABLE {$meta_groups} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                framework_id BIGINT UNSIGNED NOT NULL,
                meta_group_uuid CHAR(36) NOT NULL,
                label VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                description TEXT NULL,
                rules_json LONGTEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY meta_group_uuid (meta_group_uuid),
                UNIQUE KEY framework_slug (framework_id, slug),
                KEY framework_id (framework_id),
                KEY slug (slug),
                KEY status (status)
            ) {$charset_collate};
        ");

        update_option('cfm_schema_version', CFM_VERSION);
    }
}
