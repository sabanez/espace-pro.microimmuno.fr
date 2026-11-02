<?php
/**
 * Integración con Ultimate Membership Pro.
 *
 *  - Alta: marca los pasos pendientes (ihc_action_create_user_register / _review_request).
 *  - Campos: UMP guarda los campos del perfil en una única opción global ('ihc_user_fields')
 *    y no permite subconjuntos por formulario. Se acota esa opción con el filtro nativo
 *    'option_ihc_user_fields' SOLO mientras se pinta o se procesa el formulario de un paso,
 *    de modo que la modal muestre y valide únicamente sus campos.
 *  - Completado: ihc_action_update_user solo se dispara cuando UMP ha validado el envío
 *    (campos obligatorios incluidos); entonces se marca el paso como hecho.
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_Ump {

    /** @var string|null Paso forzado durante el pintado de la modal. */
    private static $render_step = null;

    /** @var string|null Paso detectado en el POST actual (cache). */
    private static $post_step = null;

    /** @var array|null Rastro del envío de la modal en esta petición (ver UMPRS_Debug). */
    private static $trace = null;

    public static function init() {
        add_action('ihc_action_create_user_register', array(__CLASS__, 'on_register'), 20, 2);
        add_action('ihc_action_create_user_review_request', array(__CLASS__, 'on_register'), 20, 2);
        add_filter('option_ihc_user_fields', array(__CLASS__, 'filter_fields'), 20);
        add_action('ihc_action_update_user', array(__CLASS__, 'on_profile_updated'), 20);
        // Antes de que UMP (init 50) y cualquier integración lean el POST.
        add_action('init', array(__CLASS__, 'force_user_email'), 1);
        // Primera línea de ProfileForm::doUpdate(): confirma que UMP llegó a procesar el envío.
        add_filter('ump_before_update_user', array(__CLASS__, 'trace_before_update'), 1);
    }

    /**
     * @param mixed $fields
     * @return mixed
     */
    public static function trace_before_update($fields) {
        if (self::$trace !== null) {
            self::$trace['ump_update_called'] = true;
            self::$trace['ump_fields']        = is_array($fields) ? count($fields) : 0;
        }
        return $fields;
    }

    /**
     * Guarda en user_meta qué pasó con el envío de la modal (se ejecuta al terminar la petición,
     * también cuando se redirige con exit).
     */
    public static function finish_trace() {
        if (self::$trace === null) {
            return;
        }
        $errors = array();
        if (class_exists('\\Indeed\\Ihc\\ProfileForm')) {
            $form   = new \Indeed\Ihc\ProfileForm();
            $errors = array_keys((array) $form->getErrors());
        }
        self::$trace['ump_error_fields'] = $errors;
        UMPRS_Debug::note(get_current_user_id(), 'envio_modal', self::$trace);
    }

    /**
     * El formulario lleva siempre el email del cliente en un campo oculto 'user_email'
     * (p. ej. para enviarlo a HubSpot). Un campo oculto lo puede editar el usuario, así que
     * en el servidor se sustituye por el email real de la sesión antes de que nadie lo use.
     */
    public static function force_user_email() {
        $id = self::active_step_id();

        if (!empty($_POST['umprs_step']) && !empty($_POST['umprs_nonce'])) {
            self::$trace = array(
                'paso_enviado'      => sanitize_key(wp_unslash($_POST['umprs_step'])),
                'paso_valido'       => $id !== '',
                'ihcaction'         => isset($_POST['ihcaction']) ? sanitize_key(wp_unslash($_POST['ihcaction'])) : '(ausente)',
                'nonce_ump'         => !empty($_POST['ihc_user_add_edit_nonce']),
                'campos_post'       => array_keys($_POST),
                'ump_update_called' => false,
                'completado'        => false,
            );
            register_shutdown_function(array(__CLASS__, 'finish_trace'));
            if ($id === '') {
                error_log('[ump-required-steps] Envío de la modal descartado: nonce no válido o paso no pendiente.');
            }
        }

        if ($id === '') {
            return;
        }
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return;
        }
        $_POST['user_email']    = wp_slash($user->user_email);
        $_REQUEST['user_email'] = wp_slash($user->user_email);
    }

    /**
     * @param int $uid
     * @param int $lid Membresía elegida en el alta.
     */
    public static function on_register($uid, $lid = 0) {
        $lid = (int) $lid;
        foreach (UMPRS_Registry::get_steps() as $id => $step) {
            if (!$step['on_register']) {
                continue;
            }
            if (!empty($step['register_levels']) && !in_array($lid, $step['register_levels'], true)) {
                continue;
            }
            UMPRS_State::mark_pending($uid, $id);
        }
    }

    /**
     * Pinta el formulario de perfil de UMP limitado a los campos del paso y con los
     * campos ocultos de control (paso + nonce) y el email del cliente.
     *
     * @param array $step
     * @return string
     */
    public static function render_form($step) {
        self::$render_step = $step['id'];

        $email = wp_get_current_user()->user_email;

        $inject = function ($form) use ($step, $email) {
            $hidden = '<input type="hidden" name="umprs_step" value="' . esc_attr($step['id']) . '">'
                    . '<input type="hidden" name="umprs_nonce" value="' . esc_attr(wp_create_nonce('umprs_step_' . $step['id'])) . '">'
                    . '<input type="hidden" name="user_email" value="' . esc_attr($email) . '">';
            $form = self::set_form_action((string) $form, self::current_url());
            $pos  = strripos($form, '</form>');
            return $pos === false ? $form : substr_replace($form, $hidden, $pos, 0);
        };
        add_filter('ihc_update_profile_form_html', $inject);

        $html = do_shortcode('[ihc-edit-profile-form]');

        remove_filter('ihc_update_profile_form_html', $inject);
        self::$render_step = null;

        return $html;
    }

    /**
     * URL de la petición actual, sin el parámetro de diagnóstico.
     *
     * @return string
     */
    private static function current_url() {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset($_SERVER['HTTP_HOST']) ? wp_unslash($_SERVER['HTTP_HOST']) : '';
        $uri    = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/';
        return remove_query_arg('umprs_debug', esc_url_raw($scheme . $host . $uri));
    }

    /**
     * La plantilla de UMP pinta `action=""`. Se fija la URL actual de forma explícita para
     * que el envío no dependa de cómo el navegador resuelva un action vacío.
     *
     * @param string $form HTML del formulario.
     * @param string $url
     * @return string
     */
    public static function set_form_action($form, $url) {
        return preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($url) {
            $tag    = $m[0];
            $attr   = ' action="' . esc_url($url) . '"';
            $has    = '/\saction\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i';
            return preg_match($has, $tag) ? preg_replace($has, $attr, $tag, 1) : substr($tag, 0, -1) . $attr . '>';
        }, $form, 1);
    }

    /**
     * Número de campos del paso que existen en UMP. 0 => el paso no se puede mostrar.
     *
     * @param array $step
     * @return int
     */
    public static function count_available_fields($step) {
        self::$render_step = $step['id'];
        $fields = get_option('ihc_user_fields');
        self::$render_step = null;
        return is_array($fields) ? count($fields) : 0;
    }

    /**
     * Errores de UMP del último envío que no corresponden a ningún campo visible del
     * paso (nonce, error general, campos ocultos...). La plantilla de UMP solo pinta los
     * errores junto a su campo, así que sin esto el usuario vería la modal reaparecer
     * sin ningún mensaje. Todos los errores se registran en el log de PHP.
     *
     * @param array $step
     * @return array [clave => mensaje]
     */
    public static function orphan_errors($step) {
        if (!class_exists('\\Indeed\\Ihc\\ProfileForm')) {
            return array();
        }
        $form   = new \Indeed\Ihc\ProfileForm();
        $errors = $form->getErrors();
        if (empty($errors) || !is_array($errors)) {
            return array();
        }
        error_log('[ump-required-steps] Errores de UMP al guardar el paso "' . $step['id'] . '": ' . wp_json_encode($errors));

        $shown  = array_map('strtolower', $step['fields']);
        $orphan = array();
        foreach ($errors as $key => $message) {
            if (!in_array(strtolower((string) $key), $shown, true)) {
                $orphan[$key] = (string) $message;
            }
        }
        return $orphan;
    }

    /**
     * Qué campos del paso existen en UMP y cuáles no (sin distinguir mayúsculas).
     *
     * @param array $step
     * @return array{found: string[], missing: string[], available: string[]}
     */
    public static function field_report($step) {
        $fields    = get_option('ihc_user_fields');
        $available = array();
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (!empty($field['name'])) {
                    $available[] = (string) $field['name'];
                }
            }
        }
        $lower   = array_map('strtolower', $available);
        $found   = array();
        $missing = array();
        foreach ($step['fields'] as $name) {
            if (in_array(strtolower($name), $lower, true)) {
                $found[] = $name;
            } else {
                $missing[] = $name;
            }
        }
        return array('found' => $found, 'missing' => $missing, 'available' => $available);
    }

    /**
     * Filtro de la opción global de campos de UMP.
     *
     * @param mixed $fields
     * @return mixed
     */
    public static function filter_fields($fields) {
        $id = self::active_step_id();
        if ($id === '' || !is_array($fields)) {
            return $fields;
        }
        $step = UMPRS_Registry::get_step($id);
        if (!$step) {
            return $fields;
        }

        $wanted = array_map('strtolower', $step['fields']);
        $kept   = array();
        foreach ($fields as $key => $field) {
            if (isset($field['name']) && in_array(strtolower($field['name']), $wanted, true)) {
                // En la modal el campo se muestra aunque en el perfil normal esté oculto.
                $field['display_public_ap'] = 1;
                $kept[$key] = $field;
            }
        }
        return $kept;
    }

    /**
     * UMP ha validado y guardado el formulario de perfil.
     *
     * @param int $uid
     */
    public static function on_profile_updated($uid) {
        $id = self::active_step_id();
        if ($id === '' || self::$render_step !== null) {
            return;
        }
        if ((int) $uid !== get_current_user_id()) {
            return;
        }

        UMPRS_State::mark_complete($uid, $id);
        if (self::$trace !== null) {
            self::$trace['completado'] = true;
        }

        // Post/Redirect/Get: evita reenviar el formulario al refrescar y cierra la modal.
        $redirect = apply_filters('umprs_after_complete_redirect', self::current_url(), $id, $uid);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Paso activo: el que se está pintando o el que llega validado en el POST.
     *
     * @return string '' si no hay.
     */
    private static function active_step_id() {
        if (self::$render_step !== null) {
            return self::$render_step;
        }
        if (self::$post_step === null) {
            $resolved = self::resolve_post_step();
            // No cachear antes de 'init': todavía no se conoce al usuario.
            if (did_action('init')) {
                self::$post_step = $resolved;
            }
            return $resolved;
        }
        return self::$post_step;
    }

    /**
     * @return string
     */
    private static function resolve_post_step() {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return '';
        }
        if (empty($_POST['umprs_step']) || empty($_POST['umprs_nonce']) || !did_action('init')) {
            return '';
        }
        $id = sanitize_key(wp_unslash($_POST['umprs_step']));
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['umprs_nonce'])), 'umprs_step_' . $id)) {
            return '';
        }
        if (!is_user_logged_in() || !UMPRS_State::is_pending(get_current_user_id(), $id)) {
            return '';
        }
        return UMPRS_Registry::get_step($id) ? $id : '';
    }
}
