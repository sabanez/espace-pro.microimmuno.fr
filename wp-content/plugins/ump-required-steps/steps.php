<?php
/**
 * Pasos obligatorios.
 *
 * Cada entrada es un formulario que el usuario debe completar antes de seguir usando
 * el área privada. Para añadir otro formulario basta con añadir otra entrada; el motor
 * (estado, modal, guardado) es común.
 *
 * Cómo preparar los campos en Ultimate Membership → Showcases → Register Fields:
 *   1. Crea los campos (o reutiliza campos existentes) y apunta su "Slug" (name).
 *   2. Márcalos como NO visibles en el formulario de registro (así no aparecen en el alta).
 *   3. Deja "Required" activado en los que sean obligatorios: UMP los valida al enviar.
 *   4. Pon esos slugs en 'fields' de abajo. En la modal se muestran SOLO esos campos,
 *      aunque en el perfil normal estén ocultos.
 *
 * Claves de cada paso:
 *   title           Título de la modal.
 *   intro           Texto introductorio (admite HTML básico). Opcional.
 *   fields          Slugs de los campos de UMP que forman el formulario. Obligatorio.
 *   on_register     true: se marca pendiente a cada alta nueva. Por defecto true.
 *   register_levels IDs de membresía a los que aplica (vacío = todas). Por defecto vacío.
 *   pages           Dónde se muestra: 'account' (página de cuenta de UMP y sus hijas),
 *                   'register_redirect' (la página de redirección tras el alta de UMP; si
 *                   cambias esa redirección, la modal la sigue), 'all' (todo el front) o un
 *                   array de IDs de página. Por defecto 'account'.
 *                   Se puede cambiar sin tocar código en Ajustes → Required Steps, y ese
 *                   ajuste manda sobre este valor.
 *   priority        Orden si hay varios pasos pendientes (menor = antes). Por defecto 10.
 *
 * Ejemplo (descomentar y adaptar):
 *
 * 'complemento_perfil' => array(
 *     'title'       => 'Complétez votre profil',
 *     'intro'       => '<p>Quelques informations pour finaliser votre inscription.</p>',
 *     'fields'      => array('mi_campo_1', 'mi_campo_2'),
 *     'on_register' => true,
 * ),
 *
 * También se pueden registrar pasos desde otro plugin o tema con el filtro 'umprs_steps'.
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    'complemento_perfil' => array(
        'title'       => 'Complétez votre profil',
        'intro'       => '<p>Quelques informations pour finaliser votre inscription.</p>',
        // Sustituye por los slugs reales de tus campos de UMP.
        'fields'      => array('job_title_fr','Numero_de_telephone','address','zip','city','domaineinteret','par_quels_services_de_l_institut_etes_vous_interesse___ok','origin'),
        'on_register' => true,
    ),
);