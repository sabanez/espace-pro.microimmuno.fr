<?php
/**
 * Capa de envío a HubSpot CRM Contacts API (v3).
 *
 * Upsert por email — crea o actualiza el contacto sin necesidad de un
 * formulario creado en HubSpot (no usa formGuid ni Forms API).
 *
 * Resolución del access token en cascada (la primera no vacía gana):
 *   1. Constante MGMIT_HS_ACCESS_TOKEN_SECRET (wp-config.php — lo más seguro).
 *   2. Opción propia del plugin: 'mgmit_mapper_credentials'['access_token'].
 *   3. Opción del plugin mgmit-hubspot-bridge: 'mgmit_hubspot_credentials'['access_token']
 *      (respaldo, solo si el bridge está instalado).
 *
 * Compatible con PHP 7.4.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MGMIT_HS_Contacts {

    const API_BASE   = 'https://api.hubapi.com/crm/v3/objects/contacts';
    const OPTION     = 'mgmit_mapper_credentials';
    const OPTION_BRIDGE = 'mgmit_hubspot_credentials';

    /**
     * Devuelve el access token de HubSpot resuelto en cascada.
     *
     * @return string Token o cadena vacía si no está configurado por ninguna vía.
     */
    public static function get_token() {
        if (defined('MGMIT_HS_ACCESS_TOKEN_SECRET') && MGMIT_HS_ACCESS_TOKEN_SECRET !== '') {
            return MGMIT_HS_ACCESS_TOKEN_SECRET;
        }

        $own = get_option(self::OPTION, array());
        if (is_array($own) && !empty($own['access_token'])) {
            return trim($own['access_token']);
        }

        $bridge = get_option(self::OPTION_BRIDGE, array());
        if (is_array($bridge) && !empty($bridge['access_token'])) {
            return trim($bridge['access_token']);
        }

        return '';
    }

    /**
     * Indica de qué fuente proviene el token resuelto (para diagnóstico en el admin).
     *
     * @return string 'constant' | 'own' | 'bridge' | '' (ninguna)
     */
    public static function get_token_source() {
        if (defined('MGMIT_HS_ACCESS_TOKEN_SECRET') && MGMIT_HS_ACCESS_TOKEN_SECRET !== '') {
            return 'constant';
        }
        $own = get_option(self::OPTION, array());
        if (is_array($own) && !empty($own['access_token'])) {
            return 'own';
        }
        $bridge = get_option(self::OPTION_BRIDGE, array());
        if (is_array($bridge) && !empty($bridge['access_token'])) {
            return 'bridge';
        }
        return '';
    }

    /**
     * Crea o actualiza un contacto en HubSpot por email.
     *
     * Estrategia: PATCH por idProperty=email; si el contacto no existe (404),
     * se reintenta con POST para crearlo.
     *
     * @param string $email Email del contacto (clave del upsert).
     * @param array  $props Propiedades HubSpot a establecer [propiedad => valor].
     * @return bool True si HubSpot aceptó la operación; false en error.
     */
    public static function upsert($email, $props) {
        $email = is_string($email) ? sanitize_email($email) : '';
        if ($email === '' || !is_email($email)) {
            self::log('Email inválido o vacío; se omite el envío.');
            return false;
        }

        $token = self::get_token();
        if ($token === '') {
            self::log('Sin access token configurado; se omite el envío.');
            return false;
        }

        if (!is_array($props)) {
            $props = array();
        }
        $props['email'] = $email;

        // 1) Intentar actualizar por email.
        $patch_url = self::API_BASE . '/' . rawurlencode($email) . '?idProperty=email';
        $response  = self::request('PATCH', $patch_url, $token, array('properties' => $props));

        if (is_wp_error($response)) {
            self::log('Error de red en PATCH: ' . $response->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code >= 200 && $code < 300) {
            return true;
        }

        // 2) No existe → crear.
        if ($code === 404) {
            $post_response = self::request('POST', self::API_BASE, $token, array('properties' => $props));

            if (is_wp_error($post_response)) {
                self::log('Error de red en POST: ' . $post_response->get_error_message());
                return false;
            }

            $post_code = (int) wp_remote_retrieve_response_code($post_response);

            if ($post_code >= 200 && $post_code < 300) {
                return true;
            }

            self::log('POST falló (HTTP ' . $post_code . '): ' . wp_remote_retrieve_body($post_response));
            return false;
        }

        self::log('PATCH falló (HTTP ' . $code . '): ' . wp_remote_retrieve_body($response));
        return false;
    }

    /**
     * Añade un valor a una propiedad de tipo Multiple Checkbox sin sobreescribir.
     *
     * Flujo: GET contacto → leer valor actual → merge (deduplicado, ";"-separado) → PATCH.
     * Si el contacto no existe, hace upsert directo con el valor nuevo.
     *
     * @param string $email
     * @param string $prop  Nombre de la propiedad HubSpot.
     * @param string $value Valor(es) a añadir, separados por ";" si son varios.
     * @return bool
     */
    public static function append_property($email, $prop, $value) {
        $email = is_string($email) ? sanitize_email($email) : '';
        if ($email === '' || !is_email($email) || $prop === '') {
            return false;
        }

        $token = self::get_token();
        if ($token === '') {
            self::log('append_property: sin access token.');
            return false;
        }

        // Obtener valor actual del contacto.
        $get_url  = self::API_BASE . '/' . rawurlencode($email)
                    . '?idProperty=email&properties=' . rawurlencode($prop);
        $response = wp_remote_get(
            $get_url,
            array(
                'headers' => array('Authorization' => 'Bearer ' . $token),
                'timeout' => 15,
            )
        );

        $merged = $value;

        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
            $data    = json_decode(wp_remote_retrieve_body($response), true);
            $current = (isset($data['properties'][$prop]) && $data['properties'][$prop] !== null)
                ? (string) $data['properties'][$prop]
                : '';

            if ($current !== '') {
                $existing_vals = array_filter(array_map('trim', explode(';', $current)));
                $new_vals      = array_filter(array_map('trim', explode(';', $value)));
                $merged_vals   = array_unique(array_merge($existing_vals, $new_vals));
                $merged        = implode(';', $merged_vals);
            }
        }

        // PATCH con el valor fusionado.
        $patch_url = self::API_BASE . '/' . rawurlencode($email) . '?idProperty=email';
        $res       = self::request('PATCH', $patch_url, $token, array('properties' => array($prop => $merged)));

        if (is_wp_error($res)) {
            self::log('append_property PATCH error: ' . $res->get_error_message());
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code >= 200 && $code < 300) {
            self::log('append_property OK. prop=' . $prop . ' merged=' . $merged);
            return true;
        }

        // Contacto no existe → crear con el valor nuevo.
        if ($code === 404) {
            $post_res  = self::request('POST', self::API_BASE, $token, array(
                'properties' => array('email' => $email, $prop => $value),
            ));
            $post_code = is_wp_error($post_res) ? 0 : (int) wp_remote_retrieve_response_code($post_res);
            return ($post_code >= 200 && $post_code < 300);
        }

        self::log('append_property PATCH HTTP ' . $code . ': ' . wp_remote_retrieve_body($res));
        return false;
    }

    /**
     * Lee un conjunto de propiedades de un contacto por email.
     *
     * @param string $email Email del contacto a consultar.
     * @param array  $props Nombres internos de las propiedades HubSpot a leer.
     * @return array{values: array, http_code: int, error: string} Resultado detallado para diagnóstico.
     */
    public static function get_properties($email, array $props) {
        $result = array('values' => array(), 'http_code' => 0, 'error' => '');

        $email = is_string($email) ? sanitize_email($email) : '';
        if ($email === '' || !is_email($email)) {
            $result['error'] = 'Email inválido o vacío.';
            return $result;
        }

        $token = self::get_token();
        if ($token === '') {
            $result['error'] = 'Sin access token configurado.';
            return $result;
        }

        $get_url = self::API_BASE . '/' . rawurlencode($email)
            . '?idProperty=email&properties=' . rawurlencode(implode(',', $props));

        $response = wp_remote_get(
            $get_url,
            array(
                'headers' => array('Authorization' => 'Bearer ' . $token),
                'timeout' => 15,
            )
        );

        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            self::log('get_properties error de red: ' . $result['error']);
            return $result;
        }

        $result['http_code'] = (int) wp_remote_retrieve_response_code($response);

        if ($result['http_code'] !== 200) {
            $result['error'] = 'HTTP ' . $result['http_code'] . ': ' . wp_remote_retrieve_body($response);
            self::log('get_properties ' . $result['error']);
            return $result;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        foreach ($props as $prop) {
            $result['values'][$prop] = (isset($data['properties'][$prop]) && $data['properties'][$prop] !== null)
                ? (string) $data['properties'][$prop]
                : '';
        }

        return $result;
    }

    /**
     * Ejecuta una petición HTTP autenticada contra la API de HubSpot.
     *
     * @param string $method Método HTTP (PATCH|POST).
     * @param string $url    URL completa del endpoint.
     * @param string $token  Access token (Bearer).
     * @param array  $body   Cuerpo a serializar como JSON.
     * @return array|WP_Error Respuesta de wp_remote_request.
     */
    private static function request($method, $url, $token, $body) {
        return wp_remote_request(
            $url,
            array(
                'method'  => $method,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($body),
                'timeout' => 15,
            )
        );
    }

    /**
     * Registra un mensaje de diagnóstico en el log de PHP.
     *
     * @param string $message Mensaje a registrar.
     * @return void
     */
    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[hubspot-mapper] ' . $message);
        }
    }
}
