<?php

/* Chargement des styles du parent. */
add_action( 'wp_enqueue_scripts', 'wpchild_enqueue_styles' );
function wpchild_enqueue_styles(){
  wp_enqueue_style( 'wpm-Divi-style', get_template_directory_uri() . '/style.css' );
}

/* Afficher le texte GRATUIT au lieu du prix nul */
add_filter( 'woocommerce_get_price_html', 'we_price_free_zero', 9999, 2 );
   
function we_price_free_zero( $price, $product ) {
    if ( $product->is_type( 'variable' ) ) {
        $prices = $product->get_variation_prices( true );
        $min_price = current( $prices['price'] );
        if ( 0 == $min_price ) {
            $max_price = end( $prices['price'] );
            $min_reg_price = current( $prices['regular_price'] );
            $max_reg_price = end( $prices['regular_price'] );
            if ( $min_price !== $max_price ) {
                $price = wc_format_price_range( __( 'Free', 'woocommerce' ), $max_price );
                $price .= $product->get_price_suffix();
            } elseif ( $product->is_on_sale() && $min_reg_price === $max_reg_price ) {
                $price = wc_format_sale_price( wc_price( $max_reg_price ), __( 'Free', 'woocommerce' ) );
                $price .= $product->get_price_suffix();
            } else {
                $price = __( 'Free', 'woocommerce' );
            }
        }
    } elseif ( 0 == $product->get_price() ) {
        $price = '<span class="woocommerce-Price-amount amount">' . __( 'Free', 'woocommerce' ) . '</span>';
    }  
    return $price;
}

/* Custom commandes */
add_filter( 'woocommerce_account_menu_items', 'remove_tab_from_my_account', 9999 );
function remove_tab_from_my_account( $items ) {
    unset( $items['dashboard'] );
    return $items;
}
// === TRK: Rellenar campos ocultos del formulario con parámetros de Ads ===
add_action('wp_footer', function() { ?>
<script>
(function(){
  const PARAMS = ["gclid", "gbraid", "wbraid", "gad_source"];
  const PREFIX = "trk_";

  function getStored(name){
    try {
      return sessionStorage.getItem(PREFIX + name)
          || sessionStorage.getItem(name)
          || (document.cookie.match('(^|;)\\s*' + PREFIX + name + '\\s*=\\s*([^;]+)')?.pop())
          || (document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)')?.pop())
          || "";
    } catch(e){ return ""; }
  }

  function fillHiddenFields(form){
    if(!form) return;
    PARAMS.forEach(function(p){
      var input = form.querySelector('input[name="'+p+'"]');
      if(input){
        var val = getStored(p);
        if(val) input.value = val;
      }
    });
  }

  function tryFill(){
    var form = document.querySelector('#createuser');
    if(form) fillHiddenFields(form);
  }

  document.addEventListener('DOMContentLoaded', tryFill);

  // Por si el formulario se carga dinámicamente
  const obs = new MutationObserver(tryFill);
  obs.observe(document.body, { childList: true, subtree: true });

  // Justo antes del envío
  document.addEventListener('submit', function(e){
    fillHiddenFields(e.target);
  }, true);
})();
</script>
<?php });

/**
 * Evitar doble pago en la membresía "Adhérents" (lid=2)
 * si el usuario ya tiene un pedido recurrente COMPLETADO para ese nivel.
 */

add_action('template_redirect', function () {
    if (!is_user_logged_in()) return;
    global $wpdb;
    $user_id = get_current_user_id();
    $level_id = 2; // Adhérents
    // El parámetro real en la URL de checkout es 'lid'
    $requested_level = isset($_REQUEST['lid']) ? intval($_REQUEST['lid']) : 0;
    if ($requested_level !== $level_id) return;
    // ¿Existe un pedido completado y recurrente para este nivel y usuario?
    // Si no existe la meta 'is_recurring' o su valor no es '1', se considera "no recurrente".
    $recurrent_order = $wpdb->get_var($wpdb->prepare(
        "SELECT o.id
         FROM {$wpdb->prefix}ihc_orders o
         WHERE o.uid = %d
           AND o.lid = %d
           AND o.status = 'Completed'
           AND EXISTS (
               SELECT 1 FROM {$wpdb->prefix}ihc_orders_meta m
               WHERE m.order_id = o.id
                 AND m.meta_key = 'is_recurring'
                 AND m.meta_value = '1'
           )
         LIMIT 1",
        $user_id, $level_id
    ));
    if (!$recurrent_order) return;
    wp_safe_redirect(add_query_arg('ihc_already_member', '1', home_url('/mon-compte/')));
    exit;
});
add_action('wp_footer', function () {
    if (isset($_GET['ihc_already_member'])) {
        echo '<script>alert("Vous avez déjà un abonnement actif Adhérents avec paiement récurrent. Si vous pensez qu\'il s\'agit d\'une erreur, merci de contacter notre support avant de procéder à un nouveau paiement.");</script>';
    }
});

/**
 * L'addon officiel "Ultimate Membership Pro - Redirect Back" (ump-redirect-back)
 * est trop fragile pour ce site : sa cookie 'ump_rb' est écrasée par des appels
 * qui n'ont rien à voir avec la navigation réelle de l'utilisateur (ex: l'appel
 * AJAX WooCommerce "wc-ajax=xoo_wsc_refresh_fragments" déclenché en arrière-plan,
 * qui se fait lui-même bloquer/rediriger par UMP et re-déclenche les mêmes
 * filtres, écrasant la bonne valeur avant même que l'utilisateur soumette le
 * formulaire).
 *
 * On implémente donc notre propre mémorisation, avec notre propre cookie.
 * IMPORTANT : le blocage de contenu d'UMP redirige et fait exit() avant que
 * 'template_redirect' (notre théme) puisse s'exécuter, donc on doit se brancher
 * directement sur le filtre qu'UMP applique au moment même du blocage, comme le
 * fait l'addon — mais en excluant explicitement les requêtes wc-ajax/AJAX/REST
 * qui sont à l'origine de la pollution du cookie.
 */
function santi_ump_remember_origin_post($redirectLink = '', $current_user = null, $target_users = [], $postId = 0)
{
    if (
        $postId
        && !is_user_logged_in()
        && !wp_doing_ajax()
        && !isset($_GET['wc-ajax'])
        && !(defined('REST_REQUEST') && REST_REQUEST)
        && get_post_status($postId)
    ) {
        $isDefaultPage = false;
        if (class_exists('\Ihc_Db')) {
            foreach (\Ihc_Db::getUmpDefaultPages() as $ump_page_id) {
                if ((int) $ump_page_id === (int) $postId) {
                    $isDefaultPage = true;
                    break;
                }
            }
        }
        // Ne jamais mémoriser une page système UMP (login, registre, compte...)
        // comme destination de retour après connexion.
        if (!$isDefaultPage) {
            setcookie('santi_login_redirect', $postId, time() + 24 * 60 * 60, '/');
        }
    }
    return $redirectLink;
}
add_filter('filter_on_ihc_block_url', 'santi_ump_remember_origin_post', 9999, 4);
add_filter('filter_on_ihc_link_to_redirect', 'santi_ump_remember_origin_post', 9999, 4);

add_filter('ump_public_filter_post_id_on_redirect_after_login', function ($id) {
    if (!empty($_COOKIE['santi_login_redirect'])) {
        return (int) $_COOKIE['santi_login_redirect'];
    }
    return $id;
}, 9999);

/**
 * Alta de UMP (#createuser): si la profesión es "Pharmacien", la especialidad se rellena
 * con "Pharmacien / Pharmacienne". Solo cliente; el usuario puede cambiarla después.
 */
add_action('wp_footer', function () {
    if (is_user_logged_in()) {
        return;
    }
    ?>
    <script>
    (function () {
        var form = document.getElementById('createuser');
        if (!form) return;
        var profesion = form.querySelector('select[name="prefession"]');
        var especialidad = form.querySelector('select[name="specialites"]');
        if (!profesion || !especialidad) return;

        function sync() {
            if (profesion.value === 'Pharmacien') {
                especialidad.value = 'Pharmacien / Pharmacienne';
                especialidad.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        profesion.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php
}, 99);

/**
 * Permitir a los administradores editar la página de inscripción con Divi.
 *
 * UMP redirige a cualquier usuario con sesión iniciada que abra la página de
 * registro (GeneralActions::user_logged_on_register_page, enganchada a
 * 'ihc_action_general_init' con prioridad 999). Eso impide abrir el Visual
 * Builder de Divi (?et_fb=1) con la cuenta de administrador.
 *
 * Si quien visita puede editar páginas Y está en el constructor/previsualización
 * de Divi, se eliminan los callbacks de 'ihc_action_general_init' solo en esa
 * petición (prioridad 1, antes de que corran). Los visitantes normales no se ven
 * afectados y la redirección sigue funcionando para ellos.
 */
add_action('ihc_action_general_init', function () {
    $in_divi_builder = isset($_GET['et_fb']) || isset($_GET['et_pb_preview']);
    if ($in_divi_builder && is_user_logged_in() && current_user_can('edit_pages')) {
        remove_all_actions('ihc_action_general_init');
    }
}, 1);
