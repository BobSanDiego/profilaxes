<?php
/**
 * WP-CLI contract test for the public published-List discovery surface.
 * Run with: wp eval-file tests/contract-public-list-discovery.php
 */
defined('ABSPATH') || exit;

$lists = CFM_Views_Service::discover_published_lists('teachers-net', 'c3_rail_parent');
$expected = ['Careers & Credentials', 'Classroom Projects', 'Grade Levels', 'Hot Topics', 'Practice & Theory', 'Professional Groups', 'Social', 'Subject Areas'];
$actual = array_map(static function (array $list): string {
    return (string) ($list['view']['name'] ?? '');
}, $lists);

if ($actual !== $expected) {
    WP_CLI::error('Published List discovery order/name contract failed: ' . wp_json_encode($actual));
}

$seen = [];
foreach ($lists as $list) {
    foreach (['view', 'version', 'parent', 'members'] as $key) {
        if (!array_key_exists($key, $list)) WP_CLI::error("Published List contract is missing {$key}.");
    }
    foreach ((array) $list['members'] as $member) {
        $uuid = (string) ($member['term_uuid'] ?? '');
        if ($uuid === '' || isset($seen[$uuid])) WP_CLI::error('Published List member identity is missing or duplicated.');
        $seen[$uuid] = true;
    }
}

WP_CLI::success(wp_json_encode(['lists' => count($lists), 'unique_members' => count($seen)]));
