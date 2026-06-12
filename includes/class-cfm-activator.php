<?php

if (!defined('ABSPATH')) {
  exit;
}

class CFM_Activator
{
  public static function activate(): void
  {
    CFM_Schema::install();

    add_option('cfm_version', CFM_VERSION);
    add_option('cfm_installed', current_time('mysql'));
  }
}
