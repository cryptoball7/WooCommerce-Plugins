<?php
/**
 * Plugin Name: Agent Commerce
 * Description: AI-native commerce API for WordPress.
 * Version: 0.1.0
 * Author: Cryptoball cryptoball7@gmail.com
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
*/

define('AGENT_COMMERCE_VERSION', '0.1.0');
define('AGENT_COMMERCE_PATH', plugin_dir_path(__FILE__));
define('AGENT_COMMERCE_URL', plugin_dir_url(__FILE__));
define('AGENT_COMMERCE_SCHEMA_PATH', AGENT_COMMERCE_PATH . 'schemas');

/*
|--------------------------------------------------------------------------
| Composer Autoload
|--------------------------------------------------------------------------
*/

$autoload = AGENT_COMMERCE_PATH . 'vendor/autoload.php';

if (!file_exists($autoload)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo 'Agent Commerce requires Composer dependencies. Run <code>composer install</code>.';
        echo '</p></div>';
    });
    return;
}

require_once $autoload;

/*
|--------------------------------------------------------------------------
| Boot Core
|--------------------------------------------------------------------------
*/

add_action('plugins_loaded', function () {
    AgentCommerce\Core\Bootstrap::init();
});