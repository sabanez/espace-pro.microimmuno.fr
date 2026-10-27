<?php
/**
 * Ghost Form Relay — envío a HubSpot via LeadIn tras éxito confirmado.
 *
 * Flujo:
 *   1. El adaptador llama a MGMIT_Ghost_Relay::queue() tras éxito server-side.
 *   2. Se guarda un transient con los datos + se emite una cookie de sesión corta.
 *   3a. Formularios con redirect (SWPM, Woo, UM):
 *       En la siguiente carga de página PHP detecta la cookie, encola el script
 *       de tracking de HubSpot si no está, y emite el ghost form inline en wp_footer.
 *   3b. Formularios AJAX (CF7):
 *       JS escucha el evento de éxito, llama al endpoint mgmit_mapper_get_relay,
 *       recibe los datos y dispara el ghost form en la misma página.
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MGMIT_Ghost_Relay {

    const COOKIE      = 'mgmit_hs_relay';
    const T_PREFIX    = 'mgmit_relay_';
    const TTL         = 120;

    /** @var array|null Datos del relay pendiente en la solicitud actual. */
    private static $pending = null;

    public static function init() {
        add_action('template_redirect',  array(__CLASS__, 'check_relay'), 1);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_hs'), 5);
        add_action('wp_footer',          array(__CLASS__, 'maybe_emit_ghost'), 99);
        add_action('wp_ajax_nopriv_mgmit_mapper_get_relay', array(__CLASS__, 'ajax_get_relay'));
        add_action('wp_ajax_mgmit_mapper_get_relay',        array(__CLASS__, 'ajax_get_relay'));
    }

    // -------------------------------------------------------------------------
    // API pública — llamada por los adaptadores
    // -------------------------------------------------------------------------

    /**
     * Encola los datos del ghost form para el envío pendiente.
     *
     * @param array $relay_data {
     *     @type string $form_name  id/name del ghost form (= hubspotFormName del mapeo).
     *     @type array  $fields     [hs_prop => valor] campos HubSpot a enviar.
     *     @type string $portal_id  Portal ID de HubSpot (para cargar el tracking script).
     * }
     * @return void
     */
    public static function queue($relay_data) {
        $key = wp_generate_password(20, false, false);
        set_transient(self::T_PREFIX . $key, $relay_data, self::TTL);

        if (!headers_sent()) {
            setcookie(
                self::COOKIE,
                $key,
                time() + self::TTL,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                false
            );
            $_COOKIE[self::COOKIE] = $key;
        }

        self::$pending = $relay_data;
    }

    // -------------------------------------------------------------------------
    // Flujo redirect — detección en la siguiente página
    // -------------------------------------------------------------------------

    /** Carga el transient si la cookie está presente (solicitud nueva tras redirect). */
    public static function check_relay() {
        if (self::$pending !== null) {
            return;
        }
        $key = self::get_cookie_key();
        if ($key === '') {
            return;
        }
        $data = get_transient(self::T_PREFIX . $key);
        if (is_array($data)) {
            self::$pending = $data;
        }
    }

    /** Encola el script de tracking de HubSpot si hay relay pendiente. */
    public static function maybe_enqueue_hs() {
        if (self::$pending === null) {
            return;
        }
        $portal_id = isset(self::$pending['portal_id']) ? (string) self::$pending['portal_id'] : '';
        if ($portal_id === '') {
            return;
        }
        if (wp_script_is('hs-tracking-relay', 'enqueued') || wp_script_is('hs-tracking-relay', 'done')) {
            return;
        }
        // Cargar solo si el tracking script nativo de HubSpot no está ya encolado.
        $already = wp_script_is('leadin', 'enqueued') || wp_script_is('leadin', 'done')
                || wp_script_is('hs-script', 'enqueued') || wp_script_is('hs-script', 'done');
        if (!$already) {
            wp_enqueue_script(
                'hs-tracking-relay',
                '//js.hs-scripts.com/' . intval($portal_id) . '.js',
                array(),
                null,
                false
            );
        }
    }

    /**
     * Emite el inline script del ghost form en el footer de la página destino.
     * Borra el transient y expira la cookie tras emitirlos.
     */
    public static function maybe_emit_ghost() {
        if (self::$pending === null) {
            return;
        }
        $data = self::$pending;
        self::clear_relay();
        self::emit_inline_script($data);
    }

    // -------------------------------------------------------------------------
    // Flujo AJAX — endpoint para CF7 / UM en la misma página
    // -------------------------------------------------------------------------

    /** Endpoint AJAX: devuelve los datos del relay y limpia el transient. */
    public static function ajax_get_relay() {
        $key = self::get_cookie_key();
        if ($key === '') {
            wp_send_json_error('no_relay');
        }
        $data = get_transient(self::T_PREFIX . $key);
        if (!is_array($data)) {
            wp_send_json_error('no_data');
        }
        self::clear_relay();
        wp_send_json_success($data);
    }

    // -------------------------------------------------------------------------
    // Utilidades de credenciales
    // -------------------------------------------------------------------------

    /**
     * Devuelve el Portal ID de HubSpot resolviendo en cascada.
     *
     * Cascada: opción propia > opción bridge > opción bridge config.
     *
     * @return string Portal ID o cadena vacía.
     */
    public static function get_portal_id() {
        $own = get_option('mgmit_mapper_credentials', array());
        if (is_array($own) && !empty($own['portal_id'])) {
            return (string) $own['portal_id'];
        }
        $bridge_creds = get_option('mgmit_hubspot_credentials', array());
        if (is_array($bridge_creds) && !empty($bridge_creds['portal_id'])) {
            return (string) $bridge_creds['portal_id'];
        }
        $bridge_cfg = get_option('mgmit_hubspot_config', array());
        if (is_array($bridge_cfg) && !empty($bridge_cfg['portal_id'])) {
            return (string) $bridge_cfg['portal_id'];
        }
        // Fallback: plugin oficial LeadIn/HubSpot.
        $leadin = get_option('leadin_portalId', '');
        if (!empty($leadin)) {
            return (string) $leadin;
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // Métodos privados
    // -------------------------------------------------------------------------

    private static function get_cookie_key() {
        if (!isset($_COOKIE[self::COOKIE])) {
            return '';
        }
        return sanitize_key($_COOKIE[self::COOKIE]);
    }

    private static function clear_relay() {
        $key = self::get_cookie_key();
        if ($key !== '') {
            delete_transient(self::T_PREFIX . $key);
        }
        if (!headers_sent()) {
            setcookie(
                self::COOKIE,
                '',
                time() - 3600,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                false
            );
        }
        self::$pending = null;
    }

    private static function emit_inline_script($data) {
        $json = wp_json_encode($data);
        ?>
<script id="mgmit-hs-ghost-relay">
(function () {
    var d = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput ?>;
    if (typeof window.__mgmitFireGhost === 'function') {
        window.__mgmitFireGhost(d);
    } else {
        window.__mgmitGhostData = d;
    }
}());
</script>
        <?php
    }
}
