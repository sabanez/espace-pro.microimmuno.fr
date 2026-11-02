<?php
/**
 * Plugin Name: UMP Required Steps
 * Description: Pasos obligatorios para los miembros de Ultimate Membership: tras el alta se muestra en el área privada una modal bloqueante con un formulario (campos de UMP) que reaparece en cada visita hasta que se completa.
 * Version: 1.4.7
 * Text Domain: ump-required-steps
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('UMPRS_PATH', plugin_dir_path(__FILE__));
define('UMPRS_URL', plugin_dir_url(__FILE__));
define('UMPRS_VERSION', '1.4.7');

require_once UMPRS_PATH . 'includes/class-umprs-pages.php';
require_once UMPRS_PATH . 'includes/class-umprs-registry.php';
require_once UMPRS_PATH . 'includes/class-umprs-state.php';
require_once UMPRS_PATH . 'includes/class-umprs-debug.php';
require_once UMPRS_PATH . 'includes/class-umprs-ump.php';
require_once UMPRS_PATH . 'includes/class-umprs-modal.php';
require_once UMPRS_PATH . 'includes/class-umprs-admin.php';

add_action('plugins_loaded', function () {
    // Sin Ultimate Membership no hay nada que enganchar.
    if (!defined('IHC_PATH')) {
        return;
    }
    UMPRS_Debug::init();
    UMPRS_Ump::init();
    UMPRS_Modal::init();
    if (is_admin()) {
        UMPRS_Admin::init();
    }
});
