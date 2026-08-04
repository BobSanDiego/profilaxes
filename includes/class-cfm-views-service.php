<?php

if (!defined('ABSPATH')) {
  exit;
}

/** Stable read contract for product consumers; consumers do not access tables. */
class CFM_Views_Service
{
  public static function get_current(int $view_id)
  {
    return CFM_Views_Repository::resolve_current_view($view_id);
  }

  public static function get_published_version(int $version_id)
  {
    $version = CFM_Views_Repository::get_version($version_id);
    if (!$version || (string) $version->status !== 'published') {
      return new WP_Error('cfm_views_not_published', 'Only a published View version is available to consumers.');
    }
    return CFM_Views_Repository::resolve_version($version->id);
  }

  public static function preview(int $version_id)
  {
    return CFM_Views_Repository::preview_version($version_id);
  }
}
