<?php
/**
 * Plugin Name: HubSpot Mapper
 * Description: Envío validado de formularios a HubSpot (CRM Contacts) desde el hook de éxito server-side del plugin de formularios. Bloquea LeadIn; solo envía envíos confirmados. No requiere crear un formulario en HubSpot.
 * Version: 2.7.0
 * Text Domain: hubspot-mapper
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class MGMIT_HubSpot_Mapper {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    private function define_constants() {
        define('MGMIT_MAPPER_PATH', plugin_dir_path(__FILE__));
        define('MGMIT_MAPPER_URL', plugin_dir_url(__FILE__));
        define('MGMIT_MAPPER_VERSION', '2.7.0');
        define('MGMIT_MAPPER_OPTION', 'mgmit_mapper_config');
    }

    private function init_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'), 20);
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));

        require_once MGMIT_MAPPER_PATH . 'includes/class-mgmit-hs-contacts.php';
        require_once MGMIT_MAPPER_PATH . 'includes/class-mgmit-ghost-relay.php';
        require_once MGMIT_MAPPER_PATH . 'includes/class-mgmit-hs-forms.php';
        require_once MGMIT_MAPPER_PATH . 'includes/class-mgmit-adapters.php';
        MGMIT_Mapper_Adapters::init();

        if (is_admin()) {
            require_once MGMIT_MAPPER_PATH . 'includes/class-mgmit-mapper-admin-ui.php';
            new MGMIT_Mapper_Admin_UI();
        }
    }

    public function activate_plugin() {
        if (!get_option(MGMIT_MAPPER_OPTION)) {
            update_option(MGMIT_MAPPER_OPTION, array());
        }
    }

    public function enqueue_scripts() {
        $map_js = 'assets/js/hubspot_map.js';

        if (file_exists(MGMIT_MAPPER_PATH . $map_js)) {
            wp_enqueue_script(
                'mgmit-mapper-frontend',
                MGMIT_MAPPER_URL . $map_js,
                array(),
                filemtime(MGMIT_MAPPER_PATH . $map_js),
                true
            );

            $config = get_option(MGMIT_MAPPER_OPTION, array());
            $config = apply_filters('mgmit_mapper_config', $config);

            wp_localize_script('mgmit-mapper-frontend', 'MGMIT_MAPPER_CONFIG', $config);
            wp_localize_script('mgmit-mapper-frontend', 'MGMIT_MAPPER_RELAY', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
            ));
        }
    }
}

add_action('plugins_loaded', function() {
    MGMIT_HubSpot_Mapper::get_instance();
});
