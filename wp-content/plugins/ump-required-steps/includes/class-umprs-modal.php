<?php
/**
 * Modal bloqueante en el front.
 *
 * Se decide en servidor en cada carga de página a partir del estado del usuario
 * (user_meta): sin cookies ni almacenamiento local, así que sobrevive a cerrar el
 * navegador o cambiar de dispositivo.
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_Modal {

    /** @var array|null|false false = aún no calculado. */
    private static $current = false;

    public static function init() {
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'), 20);
        add_action('wp_footer', array(__CLASS__, 'render'), 5);
        add_action('wp_footer', array(__CLASS__, 'render_debug'), 6);
    }

    /**
     * Por qué se muestra o no la modal en esta petición. Una línea por comprobación.
     *
     * @return array [[bool ok, string texto], ...]
     */
    public static function diagnose() {
        $lines = array();
        $uid   = get_current_user_id();
        $steps = UMPRS_Registry::get_steps();

        $lines[] = array(true, 'Página actual: ' . (is_singular() ? '#' . (int) get_queried_object_id() . ' "' . get_the_title() . '"' : 'no es una página/entrada (archivo, home, etc.)'));
        $lines[] = array(!empty($steps), 'Pasos definidos: ' . (empty($steps) ? 'ninguno (steps.php vacío o sin campos)' : implode(', ', array_keys($steps))));

        $state = UMPRS_State::get($uid);
        foreach ($steps as $id => $step) {
            $status  = isset($state[$id]['status']) ? $state[$id]['status'] : 'sin estado';
            $pending = $status === 'pending';
            $lines[] = array($pending, '[' . $id . '] estado de TU usuario (#' . $uid . '): ' . $status
                . ($pending ? '' : ' — la modal solo sale a usuarios con el paso pendiente (altas hechas con el plugin activo).'));

            $report  = UMPRS_Ump::field_report($step);
            $lines[] = array(empty($report['missing']), '[' . $id . '] campos encontrados en UMP: ' . count($report['found']) . '/' . count($step['fields'])
                . (empty($report['missing']) ? '' : ' — NO existen: ' . implode(', ', $report['missing'])));

            $target  = UMPRS_Pages::resolve($step['pages']);
            $allowed = self::is_allowed_page($step);
            $lines[] = array($allowed, '[' . $id . '] página objetivo: ' . ($target['all'] ? 'todo el sitio' : (empty($target['ids']) ? 'ninguna' : '#' . implode(', #', $target['ids'])))
                . ' → ' . ($allowed ? 'esta página coincide' : 'esta página NO coincide'));
        }

        $step = self::current_step();
        $events = UMPRS_Debug::describe($uid);
        $lines[] = array(!empty($events), 'Últimos envíos de la modal de TU usuario: ' . (empty($events) ? 'ninguno registrado (si pulsaste el botón, la petición no llegó al servidor)' : ''));
        foreach ($events as $event) {
            $lines[] = array(true, '   ' . $event);
        }
        $lines[] = array((bool) $step, $step ? 'RESULTADO: se muestra la modal del paso "' . $step['id'] . '".' : 'RESULTADO: no se muestra la modal.');
        $lines[] = array(wp_script_is('umprs-modal', 'enqueued'), 'Script de la modal encolado: ' . (wp_script_is('umprs-modal', 'enqueued') ? 'sí' : 'no'));
        return $lines;
    }

    /**
     * Caja de diagnóstico solo para administradores con ?umprs_debug=1.
     */
    public static function render_debug() {
        if (!isset($_GET['umprs_debug']) || !current_user_can('manage_options')) {
            return;
        }
        // popover="manual": entra en la capa superior del navegador, la única que queda por
        // encima de un <dialog> modal. Se abre en window.load, después de que modal.js abra la modal.
        echo '<div id="umprs-debug" popover="manual" style="position:fixed;inset:auto;left:12px;bottom:12px;margin:0;z-index:2147483647;max-width:min(560px,calc(100vw - 24px));max-height:60vh;overflow:auto;background:#1d2327;color:#f0f0f1;font:12px/1.5 monospace;padding:12px 14px;border:0;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.4);">';
        echo '<strong>UMP Required Steps — diagnóstico</strong>';
        foreach (self::diagnose() as $line) {
            echo '<div style="margin-top:4px;color:' . ($line[0] ? '#7ad03a' : '#ff8085') . ';">' . ($line[0] ? '✔ ' : '✘ ') . esc_html($line[1]) . '</div>';
        }
        echo '</div>';
        echo '<script>window.addEventListener("load",function(){var b=document.getElementById("umprs-debug");if(b&&b.showPopover){b.showPopover();}});</script>';
    }

    /**
     * Paso a mostrar en esta petición, o null.
     *
     * @return array|null
     */
    private static function current_step() {
        if (self::$current !== false) {
            return self::$current;
        }
        self::$current = null;

        if (is_admin() || !is_user_logged_in() || wp_doing_ajax() || wp_doing_cron()
            || (defined('REST_REQUEST') && REST_REQUEST) || is_feed() || is_customize_preview()) {
            return self::$current;
        }

        $step = UMPRS_State::next_pending(get_current_user_id());
        if (!$step || !self::is_allowed_page($step)) {
            return self::$current;
        }
        // Salvaguarda: si los campos del paso no existen en UMP no se bloquea al usuario.
        if (UMPRS_Ump::count_available_fields($step) === 0) {
            error_log('[ump-required-steps] El paso "' . $step['id'] . '" no tiene campos válidos en Ultimate Membership; no se muestra la modal.');
            return self::$current;
        }

        self::$current = apply_filters('umprs_current_step', $step, get_current_user_id());
        return self::$current;
    }

    /**
     * @param array $step
     * @return bool
     */
    public static function is_allowed_page($step) {
        // Nunca para visitantes sin sesión, sea cual sea el ajuste de páginas.
        if (!is_user_logged_in()) {
            return false;
        }
        $target = UMPRS_Pages::resolve($step['pages']);

        if ($target['all']) {
            // Nunca encima del cierre de sesión.
            $logout = (int) get_option('ihc_general_logout_page');
            return !($logout && is_page($logout));
        }
        if (empty($target['ids']) || !is_singular()) {
            return false;
        }

        $current   = (int) get_queried_object_id();
        $ancestors = array_map('intval', get_post_ancestors($current));
        foreach ($target['ids'] as $page_id) {
            // WPML (si está): la versión del idioma actual de la página.
            $page_id = (int) apply_filters('wpml_object_id', $page_id, 'page', true);
            if ($page_id === $current || in_array($page_id, $ancestors, true)) {
                return true;
            }
        }
        return false;
    }

    public static function enqueue() {
        if (!self::current_step()) {
            return;
        }
        // La versión incluye la fecha del archivo: PhastPress y los navegadores cachean los
        // scripts por URL, y con una versión fija se seguiría ejecutando el JS antiguo.
        $css_ver = UMPRS_VERSION . '-' . (int) @filemtime(UMPRS_PATH . 'assets/css/modal.css');
        $js_ver  = UMPRS_VERSION . '-' . (int) @filemtime(UMPRS_PATH . 'assets/js/modal.js');
        wp_enqueue_style('umprs-modal', UMPRS_URL . 'assets/css/modal.css', array(), $css_ver);
        wp_enqueue_script('umprs-modal', UMPRS_URL . 'assets/js/modal.js', array(), $js_ver, true);
        wp_localize_script('umprs-modal', 'UMPRS', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('umprs_client_log'),
        ));
    }

    public static function render() {
        $step = self::current_step();
        if (!$step) {
            return;
        }

        $form   = UMPRS_Ump::render_form($step);
        $orphan = UMPRS_Ump::orphan_errors($step);
        ?>
        <dialog id="umprs-modal" class="umprs" open aria-labelledby="umprs-title" aria-describedby="umprs-intro" data-step="<?php echo esc_attr($step['id']); ?>">
            <div class="umprs__panel">
                <?php if ($step['title'] !== ''): ?>
                    <h2 id="umprs-title" class="umprs__title"><?php echo esc_html($step['title']); ?></h2>
                <?php endif; ?>
                <?php if ($step['intro'] !== ''): ?>
                    <div id="umprs-intro" class="umprs__intro"><?php echo wp_kses_post($step['intro']); ?></div>
                <?php endif; ?>
                <?php if (!empty($orphan)): ?>
                    <div class="umprs__errors" role="alert">
                        <?php foreach ($orphan as $message): ?>
                            <p><?php echo esc_html($message !== '' ? $message : 'Une erreur est survenue. Veuillez réessayer.'); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="umprs__body"><?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML generado por UMP ?></div>
            </div>
        </dialog>
        <?php
    }
}
