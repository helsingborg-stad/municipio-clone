<?php

/**
 * Plugin Name: Municipio Clone
 * Description: Adds a sanitized export REST endpoint and WP-CLI clone command for WordPress site cloning.
 * Version: 1.0.0
 * Author: Helsingborgs stad
 * License: MIT
 */

use MunicipioClone\Capability\CapabilityRegistrar;
use MunicipioClone\MunicipioClone;
use WpService\Implementations\NativeWpService;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$wpService = new NativeWpService();
$capabilityRegistrar = new CapabilityRegistrar($wpService);

if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, [$capabilityRegistrar, 'register']);
}

$plugin = new MunicipioClone($wpService, $capabilityRegistrar);
$plugin->boot();
