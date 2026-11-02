<?php
/**
 * Ajustes → Required Steps: elegir en qué página se muestra cada paso y ver dónde se
 * muestra ahora mismo.
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class UMPRS_Admin {

    const OPTION = 'umprs_settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_init', array(__CLASS__, 'register'));
        add_action('admin_post_umprs_user_state', array(__CLASS__, 'handle_user_state'));
    }

    /**
     * Marca un usuario como pendiente / completado (pruebas y soporte).
     */
    public static function handle_user_state() {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('umprs_user_state');

        $who    = isset($_POST['umprs_user']) ? sanitize_text_field(wp_unslash($_POST['umprs_user'])) : '';
        $step   = isset($_POST['umprs_admin_step']) ? sanitize_key(wp_unslash($_POST['umprs_admin_step'])) : '';
        $action = isset($_POST['umprs_do']) ? sanitize_key(wp_unslash($_POST['umprs_do'])) : '';

        $user = $who === '' ? false : (is_numeric($who) ? get_user_by('id', (int) $who) : get_user_by('email', $who));
        $msg  = 'user_not_found';
        if ($user && UMPRS_Registry::get_step($step)) {
            if ($action === 'pending') {
                UMPRS_State::mark_pending($user->ID, $step, true);
                $msg = 'pending';
            } elseif ($action === 'done') {
                UMPRS_State::mark_complete($user->ID, $step);
                $msg = 'done';
            } elseif ($action === 'view') {
                $msg = 'view';
            }
        }
        wp_safe_redirect(add_query_arg(array('page' => 'umprs', 'umprs_msg' => $msg, 'umprs_uid' => $user ? $user->ID : 0), admin_url('options-general.php')));
        exit;
    }

    public static function menu() {
        add_options_page('Required Steps', 'Required Steps', 'manage_options', 'umprs', array(__CLASS__, 'render'));
    }

    public static function register() {
        register_setting('umprs', self::OPTION, array(
            'type'              => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default'           => array(),
        ));
    }

    /**
     * @param mixed $input
     * @return array [step_id => ['pages' => modo|array de IDs]]
     */
    public static function sanitize($input) {
        $out   = array();
        $input = is_array($input) ? $input : array();

        foreach (UMPRS_Registry::get_steps() as $id => $step) {
            $mode = isset($input[$id]['mode']) ? sanitize_key($input[$id]['mode']) : '';
            if (in_array($mode, UMPRS_Pages::MODES, true)) {
                $out[$id] = array('pages' => $mode);
            } elseif ($mode === 'custom') {
                $ids = isset($input[$id]['ids']) ? array_values(array_unique(array_filter(array_map('intval', (array) $input[$id]['ids'])))) : array();
                if (!empty($ids)) {
                    $out[$id] = array('pages' => $ids);
                } else {
                    add_settings_error('umprs', 'umprs_no_pages', 'Elige al menos una página para "' . ($step['title'] !== '' ? $step['title'] : $id) . '". Se ha mantenido la configuración anterior.');
                    $prev = get_option(self::OPTION, array());
                    if (isset($prev[$id])) {
                        $out[$id] = $prev[$id];
                    }
                }
            }
        }
        return $out;
    }

    /**
     * @param int $id
     * @return string Enlace de edición con el título de la página.
     */
    private static function page_link($id) {
        $title = get_the_title($id);
        $title = $title !== '' ? $title : '(sin título)';
        return '<a href="' . esc_url(get_permalink($id)) . '" target="_blank" rel="noopener">' . esc_html($title) . '</a> <small>#' . (int) $id . '</small>';
    }

    /**
     * @param string|array $pages
     * @return string HTML
     */
    private static function describe($pages) {
        $target = UMPRS_Pages::resolve($pages);
        if ($target['all']) {
            return 'Todo el sitio (excepto la página de cierre de sesión)';
        }
        if (empty($target['ids'])) {
            return '<em>Ninguna página: la modal no se mostrará. Revisa la configuración.</em>';
        }
        return implode(', ', array_map(array(__CLASS__, 'page_link'), $target['ids'])) . '<br><small>y sus páginas hijas</small>';
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $steps     = UMPRS_Registry::get_steps();
        $settings  = get_option(self::OPTION, array());
        $settings  = is_array($settings) ? $settings : array();
        $all_pages = get_pages(array('sort_column' => 'post_title', 'post_status' => 'publish'));
        ?>
        <div class="wrap">
            <h1>Required Steps</h1>
            <?php settings_errors('umprs'); ?>

            <?php if (empty($steps)): ?>
                <p>No hay pasos definidos. Añade uno en <code>wp-content/plugins/ump-required-steps/steps.php</code>.</p>
            <?php else: ?>
            <p>Elige en qué página se muestra la modal de cada paso mientras esté pendiente. El cambio se aplica a todos los usuarios con el paso pendiente.</p>
            <form method="post" action="options.php">
                <?php settings_fields('umprs'); ?>
                <?php foreach ($steps as $id => $step):
                    $effective = $step['pages'];
                    if (is_array($effective)) {
                        $mode = 'custom';
                        $ids  = array_map('intval', $effective);
                    } else {
                        $mode = in_array($effective, UMPRS_Pages::MODES, true) ? $effective : 'account';
                        $ids  = array();
                    }
                    $redirect_ids = UMPRS_Pages::register_redirect_ids();
                    $name         = self::OPTION . '[' . esc_attr($id) . ']';
                    ?>
                    <div class="card" style="max-width:780px;margin-top:16px;">
                        <h2 style="margin-top:0;">
                            <?php echo esc_html($step['title'] !== '' ? $step['title'] : $id); ?>
                            <small style="font-weight:400;color:#646970;"><code><?php echo esc_html($id); ?></code></small>
                        </h2>
                        <p style="margin-top:0;">Campos: <code><?php echo esc_html(implode('</code>, <code>', $step['fields'])); ?></code></p>
                        <?php if ($step['pages_source'] === 'code'): ?>
                            <p class="description">Ahora mismo se usa el valor definido en <code>steps.php</code>. Al guardar aquí, este ajuste pasa a mandar.</p>
                        <?php endif; ?>

                        <fieldset>
                            <label style="display:block;margin:6px 0;">
                                <input type="radio" name="<?php echo $name; ?>[mode]" value="account" <?php checked($mode, 'account'); ?>>
                                Página de cuenta de UMP
                                <small style="color:#646970;">(<?php echo wp_kses_post(self::describe('account')); ?>)</small>
                            </label>
                            <label style="display:block;margin:6px 0;">
                                <input type="radio" name="<?php echo $name; ?>[mode]" value="register_redirect" <?php checked($mode, 'register_redirect'); ?>>
                                Página de redirección tras el alta <strong>(sigue a UMP)</strong>
                                <small style="color:#646970;">
                                    (<?php echo !empty($redirect_ids) ? wp_kses_post(implode(', ', array_map(array(__CLASS__, 'page_link'), $redirect_ids))) : 'UMP no redirige a ninguna página: se usará la de cuenta'; ?>)
                                </small>
                            </label>
                            <label style="display:block;margin:6px 0;">
                                <input type="radio" name="<?php echo $name; ?>[mode]" value="custom" <?php checked($mode, 'custom'); ?>>
                                Páginas concretas
                            </label>
                            <select name="<?php echo $name; ?>[ids][]" multiple size="8" style="min-width:320px;margin:0 0 6px 24px;">
                                <?php foreach ($all_pages as $page): ?>
                                    <option value="<?php echo (int) $page->ID; ?>" <?php selected(in_array((int) $page->ID, $ids, true)); ?>>
                                        <?php echo esc_html($page->post_title . ' (#' . $page->ID . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label style="display:block;margin:6px 0;">
                                <input type="radio" name="<?php echo $name; ?>[mode]" value="all" <?php checked($mode, 'all'); ?>>
                                Todo el sitio <small style="color:#646970;">(más intrusivo)</small>
                            </label>
                        </fieldset>

                        <p style="margin-bottom:0;padding-top:8px;border-top:1px solid #dcdcde;">
                            <strong>Se muestra ahora en:</strong> <?php echo wp_kses_post(self::describe($step['pages'])); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
                <?php submit_button('Guardar'); ?>
            </form>

            <h2 style="margin-top:32px;">Diagnóstico</h2>
            <?php
            $msgs = array(
                'pending'        => 'Usuario marcado como pendiente: verá la modal en su próxima visita a la página elegida.',
                'done'           => 'Usuario marcado como completado.',
                'view'           => 'Estado del usuario:',
                'user_not_found' => 'No se encontró el usuario (o el paso).',
            );
            if (isset($_GET['umprs_msg'], $msgs[$_GET['umprs_msg']])) {
                $cls = $_GET['umprs_msg'] === 'user_not_found' ? 'notice-error' : 'notice-success';
                echo '<div class="notice ' . esc_attr($cls) . '"><p>' . esc_html($msgs[$_GET['umprs_msg']]) . '</p></div>';
            }
            ?>
            <table class="widefat striped" style="max-width:780px;">
                <thead><tr><th>Paso</th><th>Campos en UMP</th></tr></thead>
                <tbody>
                <?php foreach ($steps as $id => $step): $report = UMPRS_Ump::field_report($step); ?>
                    <tr>
                        <td><code><?php echo esc_html($id); ?></code></td>
                        <td>
                            <?php foreach ($report['found'] as $name): ?>
                                <span style="color:#00a32a;">&#10004; <code><?php echo esc_html($name); ?></code></span><br>
                            <?php endforeach; ?>
                            <?php foreach ($report['missing'] as $name): ?>
                                <span style="color:#d63638;">&#10008; <code><?php echo esc_html($name); ?></code> no existe en UMP</span><br>
                            <?php endforeach; ?>
                            <?php if (!empty($report['missing'])): ?>
                                <details style="margin-top:6px;"><summary>Slugs disponibles en UMP</summary>
                                    <code><?php echo esc_html(implode(', ', $report['available'])); ?></code>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Probar con un usuario</h3>
            <p class="description" style="max-width:780px;">La modal solo sale a usuarios con el paso <strong>pendiente</strong>, y eso ocurre al darse de alta con el plugin activo. Para probar con un usuario ya existente, márcalo aquí y entra con él en la página elegida.
                Añade <code>?umprs_debug=1</code> a la URL (siendo administrador) para ver en pantalla por qué se muestra o no.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="umprs_user_state">
                <?php wp_nonce_field('umprs_user_state'); ?>
                <input type="text" name="umprs_user" class="regular-text" placeholder="ID o email del usuario" required>
                <select name="umprs_admin_step">
                    <?php foreach ($steps as $id => $step): ?>
                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($id); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="umprs_do" value="pending" class="button button-primary">Marcar pendiente</button>
                <button type="submit" name="umprs_do" value="done" class="button">Marcar completado</button>
                <button type="submit" name="umprs_do" value="view" class="button">Ver estado y envíos</button>
            </form>
            <?php if (isset($_GET['umprs_uid']) && (int) $_GET['umprs_uid'] > 0):
                $state = UMPRS_State::get((int) $_GET['umprs_uid']); ?>
                <p>Estado del usuario #<?php echo (int) $_GET['umprs_uid']; ?>: <code><?php echo esc_html(wp_json_encode($state)); ?></code></p>
                <p><strong>Últimos envíos de la modal</strong> (solo nombres de campos, nunca valores):</p>
                <?php $events = UMPRS_Debug::describe((int) $_GET['umprs_uid']); ?>
                <?php if (empty($events)): ?>
                    <p><em>Ninguno registrado. Si el usuario pulsó el botón de la modal, la petición no llegó al servidor: el fallo está en el navegador.</em></p>
                <?php else: ?>
                    <pre style="max-width:780px;overflow:auto;background:#fff;padding:8px;border:1px solid #dcdcde;"><?php echo esc_html(implode("\n", $events)); ?></pre>
                <?php endif; ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
