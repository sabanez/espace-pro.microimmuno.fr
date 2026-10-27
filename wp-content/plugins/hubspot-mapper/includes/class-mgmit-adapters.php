<?php
/**
 * Capa de adaptadores por plugin de formularios.
 *
 * Cada adaptador engancha el hook de ÉXITO server-side de su plugin (que solo
 * dispara cuando la validación del formulario —JS y PHP— ha pasado) y, desde
 * ahí, encola los datos en MGMIT_Ghost_Relay para el envío via LeadIn.
 *
 * HubSpot no valida nada: el formulario es el dueño de la validación.
 *
 * Extensible mediante el filtro 'mgmit_mapper_adapters' — se pueden añadir
 * nuevos plugins sin tocar el core.
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MGMIT_Mapper_Adapters {

    /**
     * Registra los hooks de éxito de todos los adaptadores disponibles.
     *
     * @return void
     */
    public static function init() {
        $adapters = self::get_adapters();

        foreach ($adapters as $source => $def) {
            $hook = isset($def['hook']) ? $def['hook'] : '';
            if ($hook === '') {
                continue;
            }
            $priority = isset($def['priority']) ? (int) $def['priority'] : 20;
            $args     = isset($def['args']) ? (int) $def['args'] : 1;

            add_action($hook, function () use ($source, $def) {
                $hook_args = func_get_args();
                MGMIT_Mapper_Adapters::handle($source, $def, $hook_args);
            }, $priority, $args);
        }
    }

    /**
     * Catálogo de adaptadores soportados.
     *
     * Estructura por adaptador:
     *   label    => Nombre legible (admin UI).
     *   hook     => Hook de éxito server-side del plugin.
     *   priority => Prioridad del add_action.
     *   args     => Número de argumentos que pasa el hook.
     *   resolver => callable|null que normaliza los datos enviados a array
     *               asociativo [campo => valor]. null => usar $_POST.
     *
     * @return array
     */
    public static function get_adapters() {
        $defaults = array(
            'swpm' => array(
                'label'    => 'Simple Membership (SWPM)',
                'hook'     => 'swpm_front_end_registration_complete_fb',
                'priority' => 20,
                'args'     => 1,
                'resolver' => null,
            ),
            'cf7' => array(
                'label'    => 'Contact Form 7',
                'hook'     => 'wpcf7_mail_sent',
                'priority' => 20,
                'args'     => 1,
                'resolver' => array('MGMIT_Mapper_Adapters', 'resolve_cf7'),
            ),
            'woocommerce' => array(
                'label'    => 'WooCommerce',
                'hook'     => 'woocommerce_created_customer',
                'priority' => 20,
                'args'     => 3,
                'resolver' => null,
            ),
            'ultimate_member' => array(
                'label'    => 'Ultimate Member',
                'hook'     => 'um_registration_complete',
                'priority' => 20,
                'args'     => 2,
                'resolver' => array('MGMIT_Mapper_Adapters', 'resolve_um'),
            ),
            'profile_builder' => array(
                'label'    => 'Profile Builder',
                'hook'     => 'wppb_register_success',
                // Prioridad baja a propósito: el tema (functions.php) engancha
                // 'pb_force_redirect_after_registration' en prioridad 10 y hace
                // wp_redirect()+exit, lo que corta la ejecución de callbacks
                // posteriores. Debemos ir antes que ese exit.
                'priority' => 5,
                'args'     => 3,
                'resolver' => array('MGMIT_Mapper_Adapters', 'resolve_profile_builder'),
            ),
            'ultimate_membership' => array(
                'label'    => 'Ultimate Membership (Indeed Ultimate Membership Pro)',
                // Se dispara tras wp_insert_user() y el guardado de user meta, tanto en
                // el formulario de registro completo como en el 'register_lite'.
                // Prioridad > 1 para ir después de los callbacks internos del plugin.
                'hook'     => 'ihc_register_action_after_insert',
                'priority' => 20,
                'args'     => 5,
                'resolver' => array('MGMIT_Mapper_Adapters', 'resolve_ultimate_membership'),
            ),
        );

        return apply_filters('mgmit_mapper_adapters', $defaults);
    }

    /**
     * Manejador común: localiza el mapeo, construye las propiedades y envía.
     *
     * @param string $source    Clave del adaptador que disparó.
     * @param array  $def        Definición del adaptador.
     * @param array  $hook_args  Argumentos recibidos del hook.
     * @return void
     */
    public static function handle($source, $def, $hook_args) {
        error_log('[hubspot-mapper] handle() fired. source=' . $source . ' POST keys=' . implode(',', array_keys($_POST)));

        $resolver = isset($def['resolver']) ? $def['resolver'] : null;
        $data     = is_callable($resolver) ? call_user_func($resolver, $hook_args) : self::post_data();
        if (!is_array($data)) {
            $data = array();
        }

        // El id del mapeo viaja en el hidden inyectado por JS (siempre en $_POST).
        $post      = self::post_data();
        $mapper_id = isset($post['mgmit_mapper_id']) ? sanitize_text_field($post['mgmit_mapper_id']) : '';
        if ($mapper_id === '') {
            error_log('[hubspot-mapper] BAIL: mgmit_mapper_id no encontrado en $_POST. POST=' . wp_json_encode(array_keys($post)));
            return;
        }

        $mapping = self::find_mapping($mapper_id);
        if ($mapping === null) {
            error_log('[hubspot-mapper] BAIL: no se encontró mapeo para id=' . $mapper_id);
            return;
        }

        // El origen del mapeo debe coincidir con el adaptador que disparó.
        $map_source = isset($mapping['source']) ? $mapping['source'] : '';
        if ($map_source !== '' && $map_source !== $source) {
            error_log('[hubspot-mapper] BAIL: source no coincide. mapeo_source=' . $map_source . ' hook_source=' . $source);
            return;
        }

        if (!empty($mapping['do_not_collect'])) {
            error_log('[hubspot-mapper] BAIL: do_not_collect activo para mapeo=' . $mapper_id);
            return;
        }

        $field_map = (isset($mapping['mapping']) && is_array($mapping['mapping'])) ? $mapping['mapping'] : array();
        if (empty($field_map)) {
            return;
        }
        $value_maps = (isset($mapping['value_maps']) && is_array($mapping['value_maps'])) ? $mapping['value_maps'] : array();

        $email_field = isset($mapping['email_field']) ? $mapping['email_field'] : '';
        $props       = array();
        $email       = '';

        foreach ($field_map as $wp_field => $hs_prop) {
            $value = self::pick($data, $post, $wp_field);
            if ($value !== '' && !empty($value_maps[$wp_field]) && is_array($value_maps[$wp_field])) {
                $value = self::apply_value_map($value, $value_maps[$wp_field]);
            }
            if ($value === '') {
                continue;
            }
            $props[$hs_prop] = $value;

            $parsed_prop = class_exists('MGMIT_HS_Forms') ? MGMIT_HS_Forms::parse_prop_key($hs_prop) : array('name' => $hs_prop);
            if (($email_field !== '' && $wp_field === $email_field) || $parsed_prop['name'] === 'email') {
                $email = $value;
            }
        }

        if ($email === '') {
            error_log('[hubspot-mapper] BAIL: email vacío. props=' . wp_json_encode($props));
            return;
        }

        // Separar campos estáticos: overwrite (van en $props) vs append (CRM API post-submission).
        $static_fields  = (isset($mapping['static_fields']) && is_array($mapping['static_fields'])) ? $mapping['static_fields'] : array();
        $append_fields  = array();

        foreach ($static_fields as $sf) {
            $sf_prop   = isset($sf['hs_prop']) ? (string) $sf['hs_prop'] : '';
            $sf_value  = isset($sf['value'])   ? (string) $sf['value']   : '';
            $sf_append = !empty($sf['append']);
            if ($sf_prop === '') {
                continue;
            }
            if ($sf_append) {
                $parsed_sf = class_exists('MGMIT_HS_Forms') ? MGMIT_HS_Forms::parse_prop_key($sf_prop) : array('name' => $sf_prop, 'objectTypeId' => '0-1');
                if ($parsed_sf['objectTypeId'] !== '0-1') {
                    error_log('[hubspot-mapper] Campo estático "' . $sf_prop . '" con append=true omitido: "Concatenar" solo soporta el objeto contact.');
                    continue;
                }
                $append_fields[] = array('hs_prop' => $parsed_sf['name'], 'value' => $sf_value);
            } else {
                $props[$sf_prop] = $sf_value;
            }
        }

        error_log('[hubspot-mapper] Llamando MGMIT_HS_Forms::process(). email=' . $email . ' props=' . wp_json_encode($props));
        MGMIT_HS_Forms::process($mapping, $props);

        // Campos con append=true: actualizar en CRM tras el envío del formulario.
        if (!empty($append_fields)) {
            foreach ($append_fields as $af) {
                MGMIT_HS_Contacts::append_property($email, $af['hs_prop'], $af['value']);
            }
        }
    }

    /**
     * Obtiene el valor de un campo desde los datos del resolver, con
     * respaldo en $_POST. Aplana arrays (checkbox groups) a string.
     *
     * @param array  $data Datos normalizados del resolver.
     * @param array  $post Copia de $_POST sin slashes.
     * @param string $key  Nombre del campo WordPress.
     * @return string
     */
    private static function pick($data, $post, $key) {
        if (isset($data[$key])) {
            return self::flatten($data[$key]);
        }
        if (isset($post[$key])) {
            return self::flatten($post[$key]);
        }
        return '';
    }

    /**
     * Traduce los textos de un campo select/radio/checkbox a los valores de HubSpot
     * según la tabla de equivalencias del mapeo. Cada parte separada por ';' se
     * traduce por separado: primero coincidencia exacta, luego normalizada (sin
     * acentos, mayúsculas ni signos). Lo que no esté en la tabla se deja igual
     * para que lo resuelva la coincidencia automática contra HubSpot.
     *
     * @param string $value Valor ya aplanado.
     * @param array  $map   [texto del formulario => valor HubSpot].
     * @return string
     */
    private static function apply_value_map($value, $map) {
        $normalized = array();
        foreach ($map as $from => $to) {
            $normalized[MGMIT_HS_Forms::normalize_text($from)] = $to;
        }

        $out = array();
        foreach (explode(';', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (isset($map[$part])) {
                $out[] = $map[$part];
                continue;
            }
            $norm = MGMIT_HS_Forms::normalize_text($part);
            $out[] = ($norm !== '' && isset($normalized[$norm])) ? $normalized[$norm] : $part;
        }
        return implode(';', $out);
    }

    /**
     * Convierte un valor (posible array) en string saneado.
     *
     * @param mixed $value
     * @return string
     */
    private static function flatten($value) {
        if (is_array($value)) {
            // HubSpot espera ';' como separador de valores múltiples en
            // propiedades tipo checkbox/select (ver resolve_enum_value()
            // en class-mgmit-hs-forms.php, que separa por ';').
            $value = implode(';', array_map('strval', $value));
        }
        return sanitize_text_field((string) $value);
    }

    /**
     * Devuelve $_POST sin slashes, o array vacío.
     *
     * @return array
     */
    private static function post_data() {
        if (empty($_POST) || !is_array($_POST)) {
            return array();
        }
        return wp_unslash($_POST);
    }

    /**
     * Localiza un mapeo por su id en la configuración del plugin.
     *
     * @param string $id
     * @return array|null
     */
    private static function find_mapping($id) {
        $config = get_option(MGMIT_MAPPER_OPTION, array());
        if (!is_array($config)) {
            return null;
        }
        foreach ($config as $m) {
            if (isset($m['id']) && $m['id'] === $id) {
                return $m;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Resolvers específicos por plugin
    // -------------------------------------------------------------------------

    /**
     * CF7: los datos enviados están en la instancia de submission.
     *
     * @param array $hook_args
     * @return array
     */
    public static function resolve_cf7($hook_args) {
        if (!class_exists('WPCF7_Submission')) {
            return self::post_data();
        }
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return self::post_data();
        }
        $posted = $submission->get_posted_data();
        return is_array($posted) ? $posted : self::post_data();
    }

    /**
     * Ultimate Member: um_registration_complete($user_id, $args).
     * Los campos enviados llegan en el segundo argumento.
     *
     * @param array $hook_args
     * @return array
     */
    public static function resolve_um($hook_args) {
        if (isset($hook_args[1]) && is_array($hook_args[1])) {
            return $hook_args[1];
        }
        return self::post_data();
    }

    /**
     * Profile Builder: wppb_register_success($_REQUEST, $form_name, $user_id).
     * Los datos enviados llegan en el primer argumento ($_REQUEST, sin unslash).
     *
     * @param array $hook_args
     * @return array
     */
    public static function resolve_profile_builder($hook_args) {
        if (isset($hook_args[0]) && is_array($hook_args[0])) {
            return wp_unslash($hook_args[0]);
        }
        return self::post_data();
    }

    /**
     * Ultimate Membership (Indeed Ultimate Membership Pro):
     * ihc_register_action_after_insert($uid, $postData, $fields, $shortcodeAttr, $type).
     * Los campos enviados llegan en el segundo argumento. Se añade user_email
     * desde el usuario creado por si el formulario no lo envía (register_lite).
     *
     * @param array $hook_args
     * @return array
     */
    public static function resolve_ultimate_membership($hook_args) {
        $data = (isset($hook_args[1]) && is_array($hook_args[1])) ? wp_unslash($hook_args[1]) : self::post_data();

        if (empty($data['user_email']) && !empty($hook_args[0])) {
            $user = get_userdata((int) $hook_args[0]);
            if ($user) {
                $data['user_email'] = $user->user_email;
            }
        }
        return $data;
    }
}
