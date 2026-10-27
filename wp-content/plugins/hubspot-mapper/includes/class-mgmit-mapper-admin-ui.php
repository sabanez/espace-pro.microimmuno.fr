<?php
if (!defined('ABSPATH')) {
    exit;
}

class MGMIT_Mapper_Admin_UI {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_mgmit_mapper_save_mapping', array($this, 'ajax_save_mapping'));
        add_action('wp_ajax_mgmit_mapper_delete_mapping', array($this, 'ajax_delete_mapping'));
        add_action('wp_ajax_mgmit_mapper_duplicate_mapping', array($this, 'ajax_duplicate_mapping'));
        add_action('wp_ajax_mgmit_mapper_get_options', array($this, 'ajax_get_options'));
        add_action('wp_ajax_mgmit_mapper_save_credentials', array($this, 'ajax_save_credentials'));
    }

    public function add_admin_menu() {
        add_menu_page(
            'HubSpot Mapper',
            'HubSpot Mapper',
            'manage_options',
            'hubspot-mapper',
            array($this, 'render_admin_page'),
            'dashicons-share-alt2',
            81
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_hubspot-mapper' !== $hook) {
            return;
        }
        wp_enqueue_script(
            'mgmit-mapper-admin',
            MGMIT_MAPPER_URL . 'assets/js/admin-mapper.js',
            array('jquery'),
            MGMIT_MAPPER_VERSION,
            true
        );
        wp_localize_script('mgmit-mapper-admin', 'MGMIT_Admin', array(
            'ajaxurl'      => admin_url('admin-ajax.php'),
            'nonce_save'   => wp_create_nonce('mgmit-mapper-save'),
            'nonce_delete' => wp_create_nonce('mgmit-mapper-delete'),
            'nonce_duplicate' => wp_create_nonce('mgmit-mapper-duplicate'),
            'nonce_creds'  => wp_create_nonce('mgmit-mapper-creds'),
            'nonce_options' => wp_create_nonce('mgmit-mapper-options'),
            'list_url'     => admin_url('admin.php?page=hubspot-mapper'),
            'strings'      => array(
                'confirm_delete' => '¿Seguro que quieres eliminar este mapeo? Esta acción no se puede deshacer.',
                'saving'         => 'Guardando...',
                'deleting'       => 'Eliminando...',
                'error'          => 'Error inesperado. Por favor, inténtalo de nuevo.',
            ),
        ));
    }

    private function get_config() {
        $config = get_option(MGMIT_MAPPER_OPTION);
        if ($config === false || !is_array($config)) {
            MGMIT_HubSpot_Mapper::get_instance()->activate_plugin();
            $config = get_option(MGMIT_MAPPER_OPTION, array());
        }
        return $config;
    }

    /**
     * Devuelve [source => label] de los adaptadores disponibles.
     *
     * @return array
     */
    private function get_adapter_labels() {
        if (!class_exists('MGMIT_Mapper_Adapters')) {
            return array();
        }
        $adapters = MGMIT_Mapper_Adapters::get_adapters();
        $labels   = array();
        foreach ($adapters as $source => $def) {
            $labels[$source] = isset($def['label']) ? $def['label'] : $source;
        }
        return $labels;
    }

    public function render_admin_page() {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';

        if ($view === 'edit') {
            $this->render_edit_view();
        } else {
            $this->render_list_view();
        }
    }

    private function render_list_view() {
        $config         = $this->get_config();
        $add_url        = admin_url('admin.php?page=hubspot-mapper&view=edit');
        $adapter_labels = $this->get_adapter_labels();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-share-alt2" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:6px;"></span>
                HubSpot Mapper (Frontend)
            </h1>
            <a href="<?php echo esc_url($add_url); ?>" class="page-title-action">&#43; Añadir Mapeo</a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['saved'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong>Mapeo guardado correctamente.</strong></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong>Mapeo eliminado correctamente.</strong></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['duplicated'])): ?>
            <div class="notice notice-success is-dismissible"><p><strong>Mapeo duplicado. Revisa y guarda los cambios (p. ej. idioma o selector) antes de continuar.</strong></p></div>
            <?php endif; ?>

            <div style="margin-bottom:16px;background:#e5f5fa;padding:10px 14px;border-left:4px solid #00a0d2;font-size:13px;">
                <strong>Envío validado (Ghost Form):</strong> Tras confirmarse el éxito server-side del formulario,
                se crea un formulario sintético que LeadIn captura — <strong>sin formGuid</strong>.
                Los datos aparecen en <em>Marketing &rarr; Formularios</em> bajo el nombre configurado en cada mapeo.
                Requiere <strong>Portal ID</strong> y <strong>Access Token</strong> (Private App) configurados abajo.
            </div>

            <?php
            $own_creds    = get_option('mgmit_mapper_credentials', array());
            if (!is_array($own_creds)) {
                $own_creds = array();
            }
            $has_own_token     = !empty($own_creds['access_token']);
            $has_own_portal    = !empty($own_creds['portal_id']);
            $portal_id_display = $has_own_portal ? $own_creds['portal_id'] : MGMIT_Ghost_Relay::get_portal_id();
            $token_source      = class_exists('MGMIT_HS_Contacts') ? MGMIT_HS_Contacts::get_token_source() : '';
            $source_text       = array(
                'constant' => 'constante <code>MGMIT_HS_ACCESS_TOKEN_SECRET</code> (wp-config.php)',
                'own'      => 'este plugin',
                'bridge'   => 'plugin HubSpot Bridge (respaldo)',
            );
            ?>
            <div style="margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:760px;">
                <h2 style="margin-top:0;font-size:15px;">&#128273; Credenciales HubSpot</h2>

                <?php if ($token_source !== '' && $portal_id_display !== ''): ?>
                    <p style="margin:.4em 0;color:#00607a;">
                        &#10003; Token activo (origen: <?php echo isset($source_text[$token_source]) ? wp_kses($source_text[$token_source], array('code' => array())) : esc_html($token_source); ?>)
                        &nbsp;&mdash;&nbsp; Portal ID: <strong><?php echo esc_html($portal_id_display); ?></strong>
                    </p>
                <?php elseif ($token_source !== '' && $portal_id_display === ''): ?>
                    <p style="margin:.4em 0;color:#b32d2e;font-weight:600;">
                        &#9888; Token activo pero falta el <strong>Portal ID</strong> — el script de HubSpot no se podrá cargar en páginas sin tracking.
                    </p>
                <?php else: ?>
                    <p style="margin:.4em 0;color:#b32d2e;font-weight:600;">
                        &#9888; No hay token configurado por ninguna vía. El envío a HubSpot no funcionará.
                    </p>
                <?php endif; ?>

                <div id="mgmit-creds-notice" style="display:none;margin:8px 0;"></div>

                <p style="margin:.6em 0;">
                    <label for="mgmit-portal-id" style="display:block;font-weight:600;margin-bottom:4px;">Portal ID</label>
                    <input type="text" id="mgmit-portal-id" class="regular-text code" autocomplete="off"
                        value="<?php echo esc_attr($portal_id_display); ?>"
                        placeholder="Ej: 12345678">
                </p>
                <p style="margin:.6em 0;">
                    <label for="mgmit-access-token" style="display:block;font-weight:600;margin-bottom:4px;">Access Token (Private App)</label>
                    <input type="password" id="mgmit-access-token" class="regular-text code" autocomplete="off"
                        placeholder="<?php echo $has_own_token ? esc_attr('•••••••• (configurado — escribe para reemplazar)') : esc_attr('pat-eu1-xxxxxxxx-...'); ?>">
                    <button type="button" id="mgmit-save-creds" class="button button-primary" style="margin-top:4px;">Guardar Credenciales</button>
                </p>
                <p class="description" style="margin:.4em 0;">
                    El Portal ID se usa para cargar el script de tracking de HubSpot en páginas de destino que no lo tengan.
                    El token se guarda en <code>mgmit_mapper_credentials</code> (prioridad sobre el token del bridge).
                </p>
            </div>

            <?php if (empty($config)): ?>
                <div class="notice notice-info" style="margin-top:20px;">
                    <p>No hay mapeos configurados todavía. <a href="<?php echo esc_url($add_url); ?>"><strong>Crea el primero ahora</strong></a>.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
                    <thead>
                        <tr>
                            <th class="manage-column column-primary" style="width:22%">Nombre del Mapeo</th>
                            <th class="manage-column" style="width:28%">Selector del Formulario</th>
                            <th class="manage-column" style="width:28%">Origen</th>
                            <th class="manage-column" style="width:10%">Campos</th>
                            <th class="manage-column" style="width:12%">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                        <?php foreach ($config as $index => $mapping):
                            $id             = isset($mapping['id']) ? $mapping['id'] : strval($index);
                            $name           = (isset($mapping['name']) && $mapping['name'] !== '') ? $mapping['name'] : '(Sin nombre)';
                            $form_id        = isset($mapping['formId']) ? $mapping['formId'] : '';
                            $source         = isset($mapping['source']) ? $mapping['source'] : '';
                            $source_label   = isset($adapter_labels[$source]) ? $adapter_labels[$source] : $source;
                            $field_count    = (isset($mapping['mapping']) && is_array($mapping['mapping'])) ? count($mapping['mapping']) : 0;
                            $dnc            = !empty($mapping['do_not_collect']);
                            $edit_url       = admin_url('admin.php?page=hubspot-mapper&view=edit&mapping_id=' . urlencode($id));
                        ?>
                        <tr>
                            <td class="column-primary" data-colname="Nombre">
                                <strong><a href="<?php echo esc_url($edit_url); ?>" class="row-title"><?php echo esc_html($name); ?></a></strong>
                            </td>
                            <td data-colname="Selector">
                                <code style="font-size:12px;word-break:break-all;"><?php echo esc_html($form_id); ?></code>
                            </td>
                            <td data-colname="Origen">
                                <?php if ($dnc): ?>
                                    <span style="background:#ffeeba;color:#856404;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;">&#128683; No recoger</span>
                                <?php elseif ($source_label !== ''): ?>
                                    <span style="background:#e5f5fa;color:#00607a;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;"><?php echo esc_html($source_label); ?></span>
                                <?php else: ?>
                                    <span style="color:#b32d2e;font-size:12px;">&#9888; Sin origen</span>
                                <?php endif; ?>
                            </td>
                            <td data-colname="Campos">
                                <span style="background:#e5f5fa;color:#00607a;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;">
                                    <?php echo intval($field_count); ?>
                                </span>
                            </td>
                            <td data-colname="Acciones">
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Editar</a>
                                <button type="button" class="button button-small mgmit-duplicate-mapping"
                                    data-id="<?php echo esc_attr($id); ?>"
                                    style="margin-left:4px;">
                                    Duplicar
                                </button>
                                <button type="button" class="button button-small mgmit-delete-mapping"
                                    data-id="<?php echo esc_attr($id); ?>"
                                    style="color:#b32d2e;border-color:#b32d2e;margin-left:4px;">
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Nombre del Mapeo</th>
                            <th>Selector del Formulario</th>
                            <th>Origen</th>
                            <th>Campos</th>
                            <th>Acciones</th>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_edit_view() {
        $config     = $this->get_config();
        $mapping_id = isset($_GET['mapping_id']) ? sanitize_text_field($_GET['mapping_id']) : null;
        $mapping    = null;
        $is_new     = true;

        if ($mapping_id !== null) {
            foreach ($config as $m) {
                if (isset($m['id']) && $m['id'] === $mapping_id) {
                    $mapping = $m;
                    $is_new  = false;
                    break;
                }
            }
            if ($mapping === null && is_numeric($mapping_id)) {
                $idx = intval($mapping_id);
                if (isset($config[$idx])) {
                    $mapping = $config[$idx];
                    $is_new  = false;
                }
            }
        }

        $name              = $is_new ? '' : (isset($mapping['name']) ? $mapping['name'] : '');
        $form_sel          = $is_new ? '' : (isset($mapping['formId']) ? $mapping['formId'] : '');
        $hs_form_name      = $is_new ? '' : (isset($mapping['hubspotFormName']) ? $mapping['hubspotFormName'] : '');
        $source            = $is_new ? '' : (isset($mapping['source']) ? $mapping['source'] : '');
        $email_field       = $is_new ? '' : (isset($mapping['email_field']) ? $mapping['email_field'] : '');
        $fields            = $is_new ? array() : (isset($mapping['mapping']) && is_array($mapping['mapping']) ? $mapping['mapping'] : array());
        $value_maps        = $is_new ? array() : (isset($mapping['value_maps']) && is_array($mapping['value_maps']) ? $mapping['value_maps'] : array());
        $static_fields     = $is_new ? array() : (isset($mapping['static_fields']) && is_array($mapping['static_fields']) ? $mapping['static_fields'] : array());
        $current_id        = $is_new ? '' : (isset($mapping['id']) ? $mapping['id'] : $mapping_id);
        $do_not_collect    = !$is_new && !empty($mapping['do_not_collect']);

        $adapter_labels = $this->get_adapter_labels();
        $list_url       = admin_url('admin.php?page=hubspot-mapper');
        $page_title = $is_new ? 'Añadir Nuevo Mapeo' : 'Editar Mapeo';
        $btn_label  = $is_new ? 'Crear Mapeo' : 'Guardar Cambios';
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-share-alt2" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:6px;"></span>
                <?php echo esc_html($page_title); ?>
            </h1>
            <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">&#8592; Volver a la lista</a>
            <hr class="wp-header-end">

            <div id="mgmit-save-notice" style="display:none;margin-top:12px;"></div>

            <form id="mgmit-mapping-form" novalidate>
                <input type="hidden" id="mgmit-mapping-id" value="<?php echo esc_attr($current_id); ?>">

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="mgmit-name">Nombre del Mapeo</label></th>
                            <td>
                                <input type="text" id="mgmit-name" class="regular-text"
                                    value="<?php echo esc_attr($name); ?>"
                                    placeholder="Ej: Registro Profesional Nivel 13">
                                <p class="description">Nombre descriptivo para identificar este mapeo en el panel.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mgmit-form-selector">Selector del Formulario <span style="color:#b32d2e;">*</span></label></th>
                            <td>
                                <input type="text" id="mgmit-form-selector" class="regular-text code"
                                    value="<?php echo esc_attr($form_sel); ?>"
                                    placeholder="Ej: #registro-profesional-13">
                                <p class="description">
                                    Selector CSS del formulario. Acepta múltiples separados por coma.<br>
                                    <strong>SWPM:</strong> usa el <code>id</code> del form, ej: <code>#professional-registration-1</code><br>
                                    <strong>Ultimate Member:</strong> usa el contenedor padre, ej: <code>.um-4842</code> (donde 4842 es el ID del formulario UM)
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mgmit-source">Origen del Formulario <span style="color:#b32d2e;">*</span></label></th>
                            <td>
                                <select id="mgmit-source" class="regular-text">
                                    <option value="">&mdash; Selecciona el plugin &mdash;</option>
                                    <?php foreach ($adapter_labels as $src_key => $src_label): ?>
                                        <option value="<?php echo esc_attr($src_key); ?>" <?php selected($source, $src_key); ?>>
                                            <?php echo esc_html($src_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">
                                    Plugin que genera y valida el formulario. Determina el hook de éxito server-side
                                    desde el que se enviarán los datos a HubSpot.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mgmit-hs-form-name">Nombre en HubSpot <span style="color:#b32d2e;">*</span></label></th>
                            <td>
                                <input type="text" id="mgmit-hs-form-name" class="regular-text code"
                                    value="<?php echo esc_attr($hs_form_name); ?>"
                                    placeholder="Ej: LLBE - Second registration form LL [France] Pharmacies">
                                <p class="description">
                                    Nombre del formulario en HubSpot. Aparecerá tal cual en
                                    <em>Marketing &rarr; Formularios</em>. Admite espacios, guiones y corchetes.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Solo bloquear recogida</th>
                            <td>
                                <label>
                                    <input type="checkbox" id="mgmit-do-not-collect" value="1" <?php checked($do_not_collect); ?>>
                                    <strong>No recoger datos en HubSpot</strong>
                                </label>
                                <p class="description">
                                    Cuando está activo, HubSpot no recogerá los datos de este formulario.
                                    Los campos de nombre HubSpot y mapeo no son necesarios y se desactivan.
                                </p>
                            </td>
                        </tr>

                    </tbody>
                    <tbody id="mgmit-hs-collect-section" <?php if ($do_not_collect) echo 'style="opacity:0.4;pointer-events:none;"'; ?>>
                        <tr>
                            <th scope="row"><label for="mgmit-email-field">Campo Email</label></th>
                            <td>
                                <input type="text" id="mgmit-email-field" class="regular-text code"
                                    value="<?php echo esc_attr($email_field); ?>"
                                    placeholder="Ej: swpm_user_email">
                                <p class="description">
                                    Atributo <code>name</code> del campo WordPress que contiene el email (clave del contacto en HubSpot).<br>
                                    Opcional: si lo dejas vacío, se usará automáticamente el campo cuya propiedad HubSpot sea <code>email</code>.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Mapeo de Campos</th>
                            <td>
                                <p class="description" style="margin-bottom:10px;">
                                    Relaciona el atributo <code>name</code> del campo WordPress con la propiedad HubSpot correspondiente.<br>
                                    Para campos select, radio o checkbox cuyo texto no coincide con las opciones de HubSpot, usa <strong>Valores</strong> para definir la equivalencia.
                                </p>
                                <table class="widefat striped" id="mgmit-fields-table" style="max-width:640px;">
                                    <thead>
                                        <tr>
                                            <th style="width:40%;">Campo WordPress <small style="font-weight:400;display:block;color:#666;">(atributo <code>name</code>)</small></th>
                                            <th style="width:40%;">Propiedad HubSpot <small style="font-weight:400;display:block;color:#666;">(formato: <code>objeto-propiedad</code>, ej: <code>contact-email</code>)</small></th>
                                            <th style="width:20%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="mgmit-fields-body">
                                        <?php if (!empty($fields)): ?>
                                            <?php foreach ($fields as $wp_field => $hs_prop): ?>
                                            <tr class="mgmit-field-row" data-value-map="<?php echo esc_attr(wp_json_encode(isset($value_maps[$wp_field]) ? (object) $value_maps[$wp_field] : (object) array())); ?>">
                                                <td><input type="text" class="mgmit-wp-field widefat code" value="<?php echo esc_attr($wp_field); ?>" placeholder="swpm-472"></td>
                                                <td><input type="text" class="mgmit-hs-prop widefat code" value="<?php echo esc_attr($hs_prop); ?>" placeholder="contact-firstname"></td>
                                                <td style="text-align:center;white-space:nowrap;">
                                                    <button type="button" class="button button-small mgmit-toggle-values" title="Equivalencias de valores (select / radio / checkbox)">Valores</button>
                                                    <button type="button" class="button button-small mgmit-remove-row" title="Eliminar fila" style="color:#b32d2e;border-color:#b32d2e;">&#10005;</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr class="mgmit-field-row" data-value-map="{}">
                                                <td><input type="text" class="mgmit-wp-field widefat code" value="" placeholder="swpm-472"></td>
                                                <td><input type="text" class="mgmit-hs-prop widefat code" value="" placeholder="firstname"></td>
                                                <td style="text-align:center;white-space:nowrap;">
                                                    <button type="button" class="button button-small mgmit-toggle-values" title="Equivalencias de valores (select / radio / checkbox)">Valores</button>
                                                    <button type="button" class="button button-small mgmit-remove-row" title="Eliminar fila" style="color:#b32d2e;border-color:#b32d2e;">&#10005;</button>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" style="padding:10px 8px;">
                                                <button type="button" id="mgmit-add-row" class="button">&#43; Añadir Campo</button>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Campos Estáticos</th>
                            <td>
                                <p class="description" style="margin-bottom:10px;">
                                    Campos adicionales que se envían siempre con la submission, con un valor fijo.
                                    No necesitan existir en el formulario de WordPress.
                                </p>
                                <table class="widefat striped" id="mgmit-static-fields-table" style="max-width:640px;">
                                    <thead>
                                        <tr>
                                            <th style="width:40%;">Propiedad HubSpot <small style="font-weight:400;display:block;color:#666;">(formato: <code>objeto-propiedad</code>, ej: <code>deal-dealname</code>; sin prefijo = contact)</small></th>
                                            <th style="width:40%;">Valor fijo</th>
                                            <th style="width:12%;text-align:center;">Concatenar <small style="font-weight:400;display:block;color:#666;">checkbox múltiple, solo objeto contact</small></th>
                                            <th style="width:8%;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="mgmit-static-fields-body">
                                        <?php if (!empty($static_fields)): ?>
                                            <?php foreach ($static_fields as $sf): ?>
                                            <tr class="mgmit-static-field-row">
                                                <td><input type="text" class="mgmit-sf-prop widefat code" value="<?php echo esc_attr(isset($sf['hs_prop']) ? $sf['hs_prop'] : ''); ?>" placeholder="deal-dealname"></td>
                                                <td><input type="text" class="mgmit-sf-value widefat code" value="<?php echo esc_attr(isset($sf['value']) ? $sf['value'] : ''); ?>" placeholder="SWPM"></td>
                                                <td style="text-align:center;"><input type="checkbox" class="mgmit-sf-append" value="1" <?php checked(!empty($sf['append'])); ?> title="Añadir al valor existente en lugar de sobreescribir"></td>
                                                <td style="text-align:center;">
                                                    <button type="button" class="button button-small mgmit-remove-static-row" title="Eliminar fila" style="color:#b32d2e;border-color:#b32d2e;">&#10005;</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="3" style="padding:10px 8px;">
                                                <button type="button" id="mgmit-add-static-row" class="button">&#43; Añadir Campo Estático</button>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </td>
                        </tr>
                    </tbody><!-- /mgmit-hs-collect-section -->
                </table>

                <p class="submit">
                    <button type="submit" id="mgmit-save-btn" class="button button-primary button-large">
                        <?php echo esc_html($btn_label); ?>
                    </button>
                    <a href="<?php echo esc_url($list_url); ?>" class="button button-large" style="margin-left:8px;">Cancelar</a>
                </p>
            </form>
        </div>
        <?php
    }

    public function ajax_save_mapping() {
        check_ajax_referer('mgmit-mapper-save', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id              = isset($_POST['id'])              ? sanitize_text_field($_POST['id'])              : '';
        $name            = isset($_POST['name'])            ? sanitize_text_field($_POST['name'])            : '';
        $form_id         = isset($_POST['formId'])          ? sanitize_text_field($_POST['formId'])          : '';
        $hs_form_name    = isset($_POST['hubspotFormName']) ? sanitize_text_field($_POST['hubspotFormName']) : '';
        $source          = isset($_POST['source'])          ? sanitize_text_field($_POST['source'])          : '';
        $email_field     = isset($_POST['email_field'])     ? sanitize_text_field($_POST['email_field'])     : '';
        $fields_raw         = isset($_POST['fields'])         ? (array) $_POST['fields']         : array();
        $static_fields_raw  = isset($_POST['static_fields'])  ? (array) $_POST['static_fields']  : array();
        $value_maps_raw     = isset($_POST['value_maps'])     ? (array) $_POST['value_maps']     : array();
        $do_not_collect     = !empty($_POST['do_not_collect']);

        if (empty($form_id)) {
            wp_send_json_error('El selector del formulario es obligatorio.');
        }

        $mapping = array();
        foreach ($fields_raw as $field) {
            $wp_field = isset($field['wp_field']) ? sanitize_text_field($field['wp_field']) : '';
            $hs_prop  = isset($field['hs_prop'])  ? sanitize_text_field($field['hs_prop'])  : '';
            if ($wp_field !== '' && $hs_prop !== '') {
                $mapping[$wp_field] = $hs_prop;
            }
        }

        // Equivalencias de valores: [campo WP => [texto del formulario => valor HubSpot]].
        // Solo se guardan para campos que siguen en el mapeo.
        $value_maps = array();
        foreach ($value_maps_raw as $vm) {
            $vm_field = isset($vm['wp_field']) ? sanitize_text_field($vm['wp_field']) : '';
            if ($vm_field === '' || !isset($mapping[$vm_field]) || empty($vm['pairs']) || !is_array($vm['pairs'])) {
                continue;
            }
            foreach ($vm['pairs'] as $pair) {
                $from = isset($pair['from']) ? sanitize_text_field($pair['from']) : '';
                $to   = isset($pair['to'])   ? sanitize_text_field($pair['to'])   : '';
                if ($from !== '' && $to !== '') {
                    $value_maps[$vm_field][$from] = $to;
                }
            }
        }

        $static_fields = array();
        foreach ($static_fields_raw as $sf) {
            $sf_prop   = isset($sf['hs_prop']) ? sanitize_text_field($sf['hs_prop']) : '';
            $sf_value  = isset($sf['value'])   ? sanitize_text_field($sf['value'])   : '';
            $sf_append = !empty($sf['append']);
            if ($sf_prop !== '') {
                $static_fields[] = array(
                    'hs_prop' => $sf_prop,
                    'value'   => $sf_value,
                    'append'  => $sf_append,
                );
            }
        }

        if (!$do_not_collect) {
            $adapter_labels = $this->get_adapter_labels();
            if ($source === '' || !isset($adapter_labels[$source])) {
                wp_send_json_error('Selecciona un origen de formulario válido.');
            }

            if ($hs_form_name === '') {
                wp_send_json_error('El campo "Nombre en HubSpot" es obligatorio para que aparezca en Marketing → Formularios.');
            }

            $hs_props_bare = array();
            foreach (array_values($mapping) as $hs_prop_val) {
                $parsed_val = class_exists('MGMIT_HS_Forms') ? MGMIT_HS_Forms::parse_prop_key($hs_prop_val) : array('name' => $hs_prop_val);
                $hs_props_bare[] = $parsed_val['name'];
            }
            $has_email = ($email_field !== '' && isset($mapping[$email_field]))
                || in_array('email', $hs_props_bare, true);
            if (!$has_email) {
                wp_send_json_error('Falta el email: indica el "Campo Email" (debe existir en el mapeo) o mapea un campo a la propiedad HubSpot "email".');
            }
        }

        $new_entry = array(
            'id'              => $id !== '' ? $id : $this->generate_id(),
            'name'            => $name,
            'formId'          => $form_id,
            'hubspotFormName' => $do_not_collect ? '' : $hs_form_name,
            'source'          => $do_not_collect ? '' : $source,
            'email_field'     => $do_not_collect ? '' : $email_field,
            'mapping'         => $do_not_collect ? array() : $mapping,
            'static_fields'   => $do_not_collect ? array() : $static_fields,
            'value_maps'      => $do_not_collect ? array() : $value_maps,
            'do_not_collect'  => $do_not_collect,
        );

        $config = $this->get_config();
        $found  = false;

        foreach ($config as &$m) {
            if (isset($m['id']) && $m['id'] === $new_entry['id']) {
                $m     = $new_entry;
                $found = true;
                break;
            }
        }
        unset($m);

        if (!$found && is_numeric($id)) {
            $idx = intval($id);
            if (isset($config[$idx])) {
                $config[$idx] = $new_entry;
                $found        = true;
            }
        }

        if (!$found) {
            $config[] = $new_entry;
        }

        update_option(MGMIT_MAPPER_OPTION, $config);

        wp_send_json_success(array(
            'redirect' => admin_url('admin.php?page=hubspot-mapper&saved=1'),
        ));
    }

    /**
     * Devuelve las opciones (value + label) de una propiedad enumeration de HubSpot
     * para rellenar los desplegables de equivalencias de valores.
     */
    public function ajax_get_options() {
        check_ajax_referer('mgmit-mapper-options', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $prop = isset($_POST['prop']) ? sanitize_text_field($_POST['prop']) : '';
        if ($prop === '') {
            wp_send_json_error('Indica primero la propiedad HubSpot.');
        }

        $options = MGMIT_HS_Forms::get_options_for_key($prop);
        if ($options === null) {
            wp_send_json_error('No hay Access Token de HubSpot configurado.');
        }
        if (empty($options)) {
            wp_send_json_error('La propiedad "' . $prop . '" no tiene opciones (no es select/radio/checkbox) o no existe en HubSpot.');
        }

        wp_send_json_success(array('options' => $options));
    }

    public function ajax_delete_mapping() {
        check_ajax_referer('mgmit-mapper-delete', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if ($id === '') {
            wp_send_json_error('ID no válido.');
        }

        $config     = $this->get_config();
        $new_config = array();

        foreach ($config as $index => $m) {
            $current_id = isset($m['id']) ? $m['id'] : strval($index);
            if ($current_id !== $id) {
                $new_config[] = $m;
            }
        }

        update_option(MGMIT_MAPPER_OPTION, $new_config);

        wp_send_json_success(array(
            'redirect' => admin_url('admin.php?page=hubspot-mapper&deleted=1'),
        ));
    }

    public function ajax_duplicate_mapping() {
        check_ajax_referer('mgmit-mapper-duplicate', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        if ($id === '') {
            wp_send_json_error('ID no válido.');
        }

        $config  = $this->get_config();
        $source  = null;

        foreach ($config as $index => $m) {
            $current_id = isset($m['id']) ? $m['id'] : strval($index);
            if ($current_id === $id) {
                $source = $m;
                break;
            }
        }

        if ($source === null) {
            wp_send_json_error('Mapeo no encontrado.');
        }

        $existing_names = array();
        foreach ($config as $m) {
            $existing_names[] = isset($m['name']) ? $m['name'] : '';
        }

        $base_name = isset($source['name']) && $source['name'] !== '' ? $source['name'] : 'Mapeo';
        $new_name  = $base_name . ' (copia)';
        $suffix    = 2;
        while (in_array($new_name, $existing_names, true)) {
            $new_name = $base_name . ' (copia ' . $suffix . ')';
            $suffix++;
        }

        $duplicate         = $source;
        $duplicate['id']   = $this->generate_id();
        $duplicate['name'] = $new_name;

        $config[] = $duplicate;
        update_option(MGMIT_MAPPER_OPTION, $config);

        wp_send_json_success(array(
            'redirect' => admin_url('admin.php?page=hubspot-mapper&view=edit&mapping_id=' . urlencode($duplicate['id']) . '&duplicated=1'),
        ));
    }

    public function ajax_save_credentials() {
        check_ajax_referer('mgmit-mapper-creds', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes.');
        }

        $token     = isset($_POST['access_token']) ? sanitize_text_field($_POST['access_token']) : '';
        $portal_id = isset($_POST['portal_id'])    ? sanitize_text_field($_POST['portal_id'])    : '';

        if ($token === '' && $portal_id === '') {
            wp_send_json_error('Introduce al menos el Portal ID o el Access Token.');
        }

        $creds = get_option('mgmit_mapper_credentials', array());
        if (!is_array($creds)) {
            $creds = array();
        }
        if ($token !== '') {
            $creds['access_token'] = $token;
        }
        if ($portal_id !== '') {
            $creds['portal_id'] = $portal_id;
        }
        update_option('mgmit_mapper_credentials', $creds);

        wp_send_json_success(array('message' => 'Credenciales guardadas correctamente.'));
    }

    private function generate_id() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
