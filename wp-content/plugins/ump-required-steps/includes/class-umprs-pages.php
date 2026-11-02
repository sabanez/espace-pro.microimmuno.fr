<?php
/**
 * Resolución de "dónde se muestra" un paso.
 *
 * Valores admitidos en 'pages':
 *   'account'           Página de cuenta de UMP (y sus hijas).
 *   'register_redirect' Página(s) de redirección tras el alta configuradas en UMP
 *                       (global + reglas por nivel). Sigue los cambios de UMP.
 *   'all'               Todo el front.
 *   array de IDs        Páginas concretas (y sus hijas).
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_Pages {

    const MODES = array('account', 'register_redirect', 'all');

    /**
     * @param string|array $pages
     * @return array{all: bool, ids: int[]}
     */
    public static function resolve($pages) {
        if ($pages === 'all') {
            return array('all' => true, 'ids' => array());
        }

        if ($pages === 'register_redirect') {
            $ids = self::register_redirect_ids();
            // Redirección externa o sin configurar: se usa la página de cuenta para no perder la modal.
            return array('all' => false, 'ids' => !empty($ids) ? $ids : self::account_ids());
        }

        if ($pages === 'account') {
            return array('all' => false, 'ids' => self::account_ids());
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $pages))));
        return array('all' => false, 'ids' => $ids);
    }

    /**
     * @return int[]
     */
    public static function account_ids() {
        $id = (int) get_option('ihc_general_user_page');
        return $id > 0 ? array($id) : array();
    }

    /**
     * Páginas a las que UMP redirige tras el alta: la global y las reglas por nivel.
     *
     * @return int[]
     */
    public static function register_redirect_ids() {
        $candidates = array(get_option('ihc_general_register_redirect'));

        if (get_option('ihc_register_redirects_by_level_enable')) {
            $rules = get_option('ihc_register_redirects_by_level_rules');
            if (is_array($rules)) {
                $candidates = array_merge($candidates, array_values($rules));
            }
        }

        $ids = array();
        foreach ($candidates as $value) {
            // Los valores no numéricos son enlaces personalizados de UMP; -1 es "sin redirección".
            if (is_numeric($value) && (int) $value > 0 && get_post_status((int) $value)) {
                $ids[] = (int) $value;
            }
        }
        return array_values(array_unique($ids));
    }
}
