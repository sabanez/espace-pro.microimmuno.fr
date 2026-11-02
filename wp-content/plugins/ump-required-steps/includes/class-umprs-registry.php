<?php
/**
 * Registro de pasos obligatorios (steps.php + filtro 'umprs_steps').
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_Registry {

    /** @var array|null */
    private static $steps = null;

    /**
     * Pasos normalizados, ordenados por prioridad.
     *
     * @return array [id => step]
     */
    public static function get_steps() {
        if (self::$steps !== null) {
            return self::$steps;
        }

        $file = UMPRS_PATH . 'steps.php';
        $raw  = file_exists($file) ? include $file : array();
        $raw  = apply_filters('umprs_steps', is_array($raw) ? $raw : array());

        // Ajustes guardados desde el admin (Ajustes → Required Steps): mandan sobre steps.php.
        $overrides = get_option('umprs_settings', array());
        $overrides = is_array($overrides) ? $overrides : array();

        $steps = array();
        foreach ((array) $raw as $id => $step) {
            $id = sanitize_key($id);
            if ($id === '' || !is_array($step)) {
                continue;
            }
            $fields = isset($step['fields']) ? array_values(array_filter(array_map('strval', (array) $step['fields']))) : array();
            // Un paso sin campos mostraría una modal vacía imposible de cerrar: se descarta.
            if (empty($fields)) {
                continue;
            }
            $pages_source = 'code';
            if (isset($overrides[$id]['pages'])) {
                $step['pages'] = $overrides[$id]['pages'];
                $pages_source  = 'admin';
            }
            $steps[$id] = array(
                'id'              => $id,
                'title'           => isset($step['title']) ? (string) $step['title'] : '',
                'intro'           => isset($step['intro']) ? (string) $step['intro'] : '',
                'fields'          => $fields,
                'on_register'     => isset($step['on_register']) ? (bool) $step['on_register'] : true,
                'register_levels' => isset($step['register_levels']) ? array_map('intval', (array) $step['register_levels']) : array(),
                'pages'           => isset($step['pages']) ? $step['pages'] : 'account',
                'pages_source'    => $pages_source,
                'priority'        => isset($step['priority']) ? (int) $step['priority'] : 10,
            );
        }

        uasort($steps, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        self::$steps = $steps;
        return self::$steps;
    }

    /**
     * @param string $id
     * @return array|null
     */
    public static function get_step($id) {
        $steps = self::get_steps();
        return isset($steps[$id]) ? $steps[$id] : null;
    }
}
