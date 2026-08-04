<?php

/**
 * Plugin Name: Core Terms
 * Description: Core Terms infrastructure for community term hierarchy, assignment, compilation, and Labs diagnostics.
 * Version: 0.6.1
 * Author: Teachers.Net
 * Text Domain: profilaxes
 */

if (!defined('ABSPATH')) {
  exit;
}

define('CFM_VERSION', '0.6.1');
define('CFM_PLUGIN_FILE', __FILE__);
define('CFM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CFM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once CFM_PLUGIN_DIR . 'includes/class-cfm-schema.php';
require_once CFM_PLUGIN_DIR . 'includes/class-cfm-framework-repository.php';
require_once CFM_PLUGIN_DIR . 'includes/class-cfm-views-repository.php';
require_once CFM_PLUGIN_DIR . 'includes/class-cfm.php';
require_once CFM_PLUGIN_DIR . 'includes/class-cfm-seeder.php';
require_once CFM_PLUGIN_DIR . 'includes/class-cfm-compiler.php';
require_once CFM_PLUGIN_DIR . 'includes/class-cfm-activator.php';
require_once CFM_PLUGIN_DIR . 'admin/class-cfm-admin.php';

register_activation_hook(__FILE__, ['CFM_Activator', 'activate']);
CFM::init();
if (is_admin()) {
  CFM_Admin::init();
}
