<?php
/**
 * Estado por usuario de cada paso (user_meta), independiente del navegador y de la
 * sesión: es lo que hace que la modal reaparezca al volver a entrar.
 *
 * Estructura de la meta 'umprs_state': [step_id => ['status' => 'pending|done', 'since' => ts, 'done_at' => ts]]
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_State {

    const META = 'umprs_state';

    /**
     * @param int $uid
     * @return array
     */
    public static function get($uid) {
        $state = get_user_meta((int) $uid, self::META, true);
        return is_array($state) ? $state : array();
    }

    /**
     * Marca un paso como pendiente. No toca pasos ya pendientes o completados,
     * salvo con $force (p. ej. para volver a pedir un formulario).
     *
     * @return bool true si se ha marcado.
     */
    public static function mark_pending($uid, $step_id, $force = false) {
        $state = self::get($uid);
        if (isset($state[$step_id]) && !$force) {
            return false;
        }
        $state[$step_id] = array('status' => 'pending', 'since' => time());
        update_user_meta((int) $uid, self::META, $state);
        do_action('umprs_step_pending', (int) $uid, $step_id);
        return true;
    }

    /**
     * @return bool
     */
    public static function mark_complete($uid, $step_id) {
        $state = self::get($uid);
        $since = isset($state[$step_id]['since']) ? $state[$step_id]['since'] : time();
        $state[$step_id] = array('status' => 'done', 'since' => $since, 'done_at' => time());
        update_user_meta((int) $uid, self::META, $state);
        do_action('umprs_step_completed', (int) $uid, $step_id);
        return true;
    }

    /**
     * @return bool
     */
    public static function is_pending($uid, $step_id) {
        $state = self::get($uid);
        return isset($state[$step_id]['status']) && $state[$step_id]['status'] === 'pending';
    }

    /**
     * Primer paso pendiente del usuario según el orden del registro.
     *
     * @return array|null
     */
    public static function next_pending($uid) {
        $state = self::get($uid);
        if (empty($state)) {
            return null;
        }
        foreach (UMPRS_Registry::get_steps() as $id => $step) {
            if (isset($state[$id]['status']) && $state[$id]['status'] === 'pending') {
                return $step;
            }
        }
        return null;
    }
}
