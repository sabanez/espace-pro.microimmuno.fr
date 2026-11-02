# Changelog — UMP Required Steps

## [1.4.7] — 2026-10-28

### Corregido
- **Causa real de "el botón no envía":** el campo obligatorio `Numero_de_telephone` estaba oculto por el tema (`Divi-child/style.css`: `.page-id-12 .iump-form-Numero_de_telephone { display: none }`). UMP lo validaba, devolvía "Veuillez remplir tous les champs obligatoires" y pintaba el aviso dentro de un elemento invisible, así que el formulario no se enviaba y el usuario no veía ningún mensaje. Detectado entrando en la página con el navegador y leyendo la respuesta de `ihc_ajax_forms_check_all_fields`. Ahora los campos del paso se muestran siempre en la modal (`display: block` con especificidad suficiente, sin `!important` para respetar la lógica condicional de UMP).
- Los avisos de error se desplazan a la vista dentro de la modal tras la comprobación de UMP.

## [1.4.6] — 2026-10-28

### Corregido
- **El navegador seguía ejecutando un `modal.js` antiguo:** la constante de versión nunca se había subido (seguía en `1.0.0`) y PhastPress cachea los scripts por URL, así que los arreglos de las versiones 1.4.1–1.4.5 podían no haber llegado al navegador. Ahora la versión del CSS y del JS incluye la fecha de modificación del archivo (`1.4.6-<mtime>`) y cambia sola con cada edición.
- **Envío de reserva:** si UMP valida bien (`IhcProfileForm.must_submit == 1`) y el navegador no empieza a navegar en 400 ms, `modal.js` envía el formulario de forma nativa. Cubre el caso de que algo en la página impida el envío que UMP deja en manos de jQuery.

### Añadido
- Eventos de rastro `navegador:version_js` (confirma qué versión de `modal.js` se está ejecutando) y `navegador:forzar_envio`.

## [1.4.5] — 2026-10-28

### Corregido
- El `<form>` de la modal salía con `action=""` (plantilla de UMP). Ahora se fija explícitamente la URL actual (sin `umprs_debug`) en el atributo `action`, para que el envío no dependa de cómo el navegador resuelva un action vacío. Misma URL usada para la redirección tras completar.

## [1.4.4] — 2026-10-28

### Corregido
- El desplegable de "Probar con un usuario" se llamaba `umprs_step`, igual que el campo oculto de la modal, y el rastro lo registraba como si fuera un envío de la modal. Ahora se llama `umprs_admin_step`, y el rastro exige también el nonce de la modal.

### Añadido
- **Rastro desde el navegador** (acción AJAX `umprs_client_log`, solo logueados, con nonce): `modal.js` informa de si se dispara el `submit` (y si UMP hizo `preventDefault`), de la respuesta de la comprobación AJAX previa de UMP (`ihc_ajax_forms_check_all_fields`), de errores de JS tras el envío y de si el navegador llega a navegar. Se ve junto al rastro del servidor. Se guardan los últimos 24 eventos.

## [1.4.3] — 2026-10-28

### Corregido
- La caja `?umprs_debug=1` quedaba tapada por la modal (un `<dialog>` modal vive en la capa superior del navegador y ningún `z-index` la supera). Ahora la caja es un `popover="manual"` que se abre en `window.load`, después de la modal, y se ve por encima. En navegadores sin Popover API se muestra como antes.

## [1.4.2] — 2026-10-28

### Añadido
- **Rastro de envíos en la base de datos** (`umprs_events`, user_meta, últimos 8): cada vez que llega un envío de la modal se guarda si el paso y el nonce eran válidos, la acción de UMP recibida, los nombres de los campos del POST, si UMP llegó a procesar la actualización, qué campos dieron error y si el paso se completó. Sin valores. No depende de `WP_DEBUG_LOG`.
- Se muestra en la caja `?umprs_debug=1` y en Ajustes → Required Steps → "Ver estado y envíos" (por usuario). Si no hay ningún evento tras pulsar el botón, la petición no llegó al servidor.

## [1.4.1] — 2026-10-28

### Corregido
- **El botón de envío no enviaba aunque la validación de obligatorios funcionara.** El JS de UMP selecciona los formularios por la clase `.ihc-form-create-edit` y, al validar, reenvía todos los de la página; si la página ya traía su formulario de perfil se pisaban con el de la modal. Ahora, con la modal abierta, `modal.js` quita esa clase a los formularios ajenos a la modal antes de que UMP inicie su script (la página queda inerte tras el `<dialog>`).
- **Errores invisibles:** la plantilla de UMP solo pinta los errores junto a su campo, así que un fallo general (nonce, validación de un campo oculto) hacía que la modal reapareciera sin mensaje. Ahora esos errores salen en un aviso rojo dentro de la modal.

### Añadido
- Registro en el log de PHP de los errores de UMP al guardar el paso y de los envíos descartados por nonce/estado, prefijados con `[ump-required-steps]`.

## [1.4.0] — 2026-10-28

### Añadido
- **Campo oculto `user_email`** en el formulario de la modal con el email del cliente en sesión, para integraciones que lo necesiten (p. ej. HubSpot). UMP lo ignora al guardar, porque solo procesa los campos del paso.
- `UMPRS_Ump::force_user_email()` (init, prioridad 1): en un envío válido de la modal sustituye `$_POST['user_email']` por el email real del usuario logueado, de modo que un valor manipulado desde el navegador nunca llega a UMP ni a otras integraciones.

## [1.3.0] — 2026-10-28

### Cambiado
- **Estilo uniforme del formulario de la modal**, alineado con el formulario de perfil del tema: Poppins (`--iump-primary-font` de UMP) en todos los controles, escala tipográfica única (etiquetas 13px/600, controles y opciones 14px, avisos 12px), inputs/selects/textarea con la misma altura, borde y radio, botón de envío igual que el de `#edituser` (azul `--color-blue-alt`, hover `#fad6ad`). Los colores salen de las variables del tema con valores de respaldo.
- **Rejilla de 2 columnas** (1 en móvil). Los campos largos (checkbox, radio, textarea, multiselect, archivo) ocupan el ancho completo.
- **Listas de checkbox/radio ordenadas:** rejilla responsive con una tarjeta por opción (marcador alineado, texto con salto de línea, resaltado al marcar) en lugar de los `inline-block` de UMP. Se anulan las reglas del tema que forzaban `width:250px; font-size:16px` en `domaineinteret` a ≤1000px.
- La tarjeta entera de cada opción es clicable (el texto de UMP no es un `<label>`).
- Selectores con `#umprs-modal` para ganar a las reglas `!important` de UMP y del tema sin tocarlas.

## [1.2.0] — 2026-10-28

### Añadido
- **Diagnóstico en la página:** con `?umprs_debug=1` (solo administradores) aparece una caja que explica por qué se muestra o no la modal: estado del usuario, campos encontrados en UMP, página objetivo frente a página actual y resultado.
- **Ajustes → Required Steps → Diagnóstico:** comprobación de cada campo del paso contra los de UMP (con la lista de slugs disponibles si falta alguno) y herramienta para marcar un usuario existente como pendiente/completado, para probar sin hacer un alta nueva.

### Cambiado
- Los slugs de `fields` se emparejan sin distinguir mayúsculas.

## [1.1.0] — 2026-10-28

### Añadido
- **Ajustes → Required Steps:** elegir por paso en qué página se muestra la modal: página de cuenta de UMP, **página de redirección tras el alta (sigue a UMP)**, páginas concretas o todo el sitio. Cada paso muestra además "Se muestra ahora en:" con enlaces a las páginas resueltas. Lo guardado ahí manda sobre `steps.php` (opción `umprs_settings`).
- Modo `register_redirect`: lee la redirección global de UMP (`ihc_general_register_redirect`) y las reglas por nivel (`ihc_register_redirects_by_level_rules`), así que si cambias la redirección del alta la modal se mueve sola. Si UMP redirige a un enlace externo o no redirige, se usa la página de cuenta para no perder la modal.
- Nueva clase `UMPRS_Pages` con la resolución de páginas, compartida entre el admin y la modal.

## [1.0.0] — 2026-10-28

### Añadido
- Modal bloqueante (`<dialog>` nativo, sin botón de cierre, Esc y clic fuera desactivados) con un formulario de campos de Ultimate Membership, mostrada en el área privada mientras el paso siga pendiente.
- Estado por usuario en `user_meta` (`umprs_state`): se marca pendiente al alta (`ihc_action_create_user_register` / `_review_request`) y la modal reaparece en cada carga, también tras cerrar el navegador o cambiar de dispositivo.
- Registro de pasos (`steps.php` + filtro `umprs_steps`) para añadir más formularios sin tocar el motor. Opciones: `title`, `intro`, `fields`, `on_register`, `register_levels`, `pages`, `priority`.
- Formulario acotado a los campos del paso mediante el filtro nativo `option_ihc_user_fields`, solo al pintar/procesar la modal (nonce por paso). Los campos pueden estar ocultos en el perfil normal.
- Completado con `ihc_action_update_user` (solo se dispara si UMP ha validado los obligatorios) y redirección POST/Redirect/GET.
- Salvaguarda: un paso sin campos válidos en UMP no muestra la modal para no bloquear al usuario.
- Hooks: `umprs_step_pending`, `umprs_step_completed`, `umprs_current_step`, `umprs_after_complete_redirect`.
