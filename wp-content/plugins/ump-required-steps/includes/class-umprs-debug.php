<?php
/**
 * Rastro de los últimos envíos de la modal, guardado en user_meta.
 *
 * Existe porque en muchos entornos WP_DEBUG_LOG está desactivado y error_log() no llega
 * a ningún sitio visible. Solo guarda nombres de campos y estados, nunca valores.
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_Debug {

    const META = 'umprs_events';
    const KEEP = 24;

    public static function init() {
        add_action('wp_ajax_umprs_client_log', array(__CLASS__, 'ajax_client_log'));
    }

    /**
     * Eventos que envía modal.js desde el navegador (solo usuarios logueados).
     */
    public static function ajax_client_log() {
        check_ajax_referer('umprs_client_log', 'nonce');
        $event  = isset($_POST['event']) ? sanitize_key(wp_unslash($_POST['event'])) : '';
        $detail = isset($_POST['detail']) ? sanitize_text_field(wp_unslash($_POST['detail'])) : '';
        $allowed = array('submit', 'ajax_check', 'js_error', 'beforeunload', 'forzar_envio', 'version_js');
        if (in_array($event, $allowed, true)) {
            self::note(get_current_user_id(), 'navegador:' . $event, array('detalle' => mb_substr($detail, 0, 300)));
        }
        wp_send_json_success();
    }

    /**
     * @param int    $uid
     * @param string $event
     * @param array  $data
     */
    public static function note($uid, $event, array $data = array()) {
        $uid = (int) $uid;
        if ($uid <= 0) {
            return;
        }
        $events   = self::get($uid);
        $events[] = array('time' => time(), 'event' => $event, 'data' => $data);
        update_user_meta($uid, self::META, array_slice($events, -self::KEEP));
    }

    /**
     * @param int $uid
     * @return array
     */
    public static function get($uid) {
        $events = get_user_meta((int) $uid, self::META, true);
        return is_array($events) ? $events : array();
    }

    /**
     * Una línea legible por evento, del más reciente al más antiguo.
     *
     * @param int $uid
     * @return string[]
     */
    public static function describe($uid) {
        $lines = array();
        foreach (array_reverse(self::get($uid)) as $e) {
            $lines[] = wp_date('Y-m-d H:i:s', (int) $e['time']) . ' — ' . $e['event'] . ' ' . wp_json_encode($e['data']);
        }
        return $lines;
    }
}
