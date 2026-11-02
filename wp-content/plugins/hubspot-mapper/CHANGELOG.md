# Changelog — HubSpot Mapper

## [2.8.3] — 2026-11-02

### Corregido
- La búsqueda de formulario por nombre ahora recorre **todas las marcas** (unidades de negocio): lista de la marca por defecto y de cada unidad con `businessUnitId`, en Forms API v2 y v3 (paginada). Las marcas se descubren con la API de Business Units (scope `business_units_view.read`) o con la opción `mgmit_business_unit_ids` (IDs separados por comas). Cada paso queda en el log.

## [2.8.2] — 2026-11-02

### Añadido
- **ID del formulario de HubSpot (opcional)** en el editor del mapeo. Si se indica, se usa ese formulario y no se busca ni se crea nada por nombre. Resuelve el caso de portales con varias marcas (unidades de negocio): la búsqueda por nombre solo ve los formularios de la marca por defecto, no encontraba el de la otra y creaba uno nuevo en la marca por defecto.

### Corregido
- Al guardar un mapeo se perdía el formulario ya vinculado (`hs_form_guid`) y el siguiente envío volvía a buscarlo o crearlo por nombre. Ahora se conserva.

## [2.8.1] — 2026-10-29

### Cambiado
- **Equivalencias de valores:** la columna "Texto del formulario" ahora es un desplegable con las opciones del campo (igual que la de HubSpot) en lugar de un campo de texto libre. Las opciones se leen de la configuración del campo en Ultimate Membership (`ihc_user_fields`, nombre del campo = columna "Campo del formulario"). Si el campo no se encuentra o no tiene opciones, se mantiene el campo de texto. Un texto ya guardado que no esté entre las opciones se conserva y se marca "(no está en el formulario)".
- Nuevo endpoint AJAX `mgmit_mapper_get_source_options`.

## [2.8.0] — 2026-10-28

### Añadido
- **Adaptador "Ultimate Membership — Perfil / pasos obligatorios (modal)"** (`ihc_action_update_user`, prioridad 10): permite mapear formularios que se guardan con el flujo de actualización de perfil de UMP, como la modal de `ump-required-steps`. Solo se dispara cuando UMP ha validado y guardado el formulario. Prioridad 10 a propósito: la modal engancha el mismo hook en prioridad 20 y hace redirect+exit al completar el paso.
- `MGMIT_Mapper_Adapters::resolve_ultimate_membership_profile()`: lee los campos de `$_POST` pero toma el email del **usuario de la sesión**, no del formulario, para que un valor manipulado en el navegador no pueda escribir datos en el contacto de otro email.

## [2.7.0] — 2026-10-27

### Añadido
- **Equivalencias de valores por campo (`value_maps`)** para select / radio / checkbox cuyo texto de formulario no coincide con las opciones de HubSpot (caso típico de Ultimate Membership, que no permite definir el value de cada opción). En el editor del mapeo, cada fila de campo tiene un botón **Valores** que despliega una tabla "texto del formulario → valor HubSpot", con el desplegable cargado en vivo desde la propiedad de HubSpot (AJAX `mgmit_mapper_get_options`). Soporta checkbox múltiple (cada valor separado por `;` se traduce por separado). Se aplica en `handle()` antes de enviar; lo no mapeado sigue por la coincidencia automática.
- Persistencia: clave `value_maps` (`{campo_wp: {texto: valor_hs}}`) en cada entrada de `mgmit_mapper_config`. Se copia al duplicar un mapeo.

### Cambiado
- **Coincidencia automática más tolerante en `resolve_enum_value()`:** además del value exacto y sin distinguir mayúsculas, ahora compara con el **label** de la opción y con value/label **normalizados** (sin acentos, signos ni espacios extra). Las opciones ocultas de HubSpot se ignoran.
- Refactor: la lectura de opciones se extrae a `get_property_options()` (value + label, con caché).

---

## [2.6.0] — 2026-10-27

### Añadido
- **Adaptador para Ultimate Membership (Indeed Ultimate Membership Pro)** (`ihc_register_action_after_insert`, 5 args): permite mapear los formularios de registro del plugin (registro completo y `register_lite`). Se ejecuta en prioridad 20, tras el guardado del usuario y sus metas y tras los callbacks internos del plugin (prioridad 1).
- `MGMIT_Mapper_Adapters::resolve_ultimate_membership()`: toma los campos del segundo argumento del hook (`$postData`) y rellena `user_email` desde el usuario creado si el formulario no lo envía.

## [2.5.0] — 2026-09-16

### Cambiado
- Eliminadas las referencias visibles a "MeGeMIT" en cabecera del plugin, menú admin y textos de la UI. Los identificadores internos (`MGMIT_*`, textdomain, option keys) se mantienen sin cambios para no romper la configuración ya guardada.

### Añadido
- **Duplicar mapeo**: nuevo botón "Duplicar" en el listado que clona un mapeo existente (útil para formularios idénticos en distintos idiomas). El nombre del duplicado se genera automáticamente evitando colisiones con nombres ya existentes (sufijo `(copia)`, `(copia 2)`, etc.), y redirige directamente a la edición del nuevo mapeo.

---

## [2.4.5] — 2026-08-14

### Corregido
- **Fallo en cascada al registrar varios campos nuevos a la vez (fase de fallback uno-a-uno de `update_form_fields()`):** al añadir un campo con éxito, el código seguía usando la copia local (desactualizada) del formulario para el siguiente POST, provocando `412 CONCURRENT_UPDATE_BY_USER` en los campos siguientes de la misma tanda. Esto explicaba por qué, al guardar varios campos estáticos nuevos a la vez, solo el primero llegaba a registrarse. Ahora se usa la respuesta de cada POST exitoso como base para el siguiente, y si aun así llega un 412 (p.ej. por otro envío concurrente real), se refresca el formulario y se reintenta una vez antes de descartar el campo.

### Documentado
- `hubspot_owner_id` (y cualquier propiedad que HubSpot marque `PROPERTY_DISABLED_IN_FORMS`) no puede enviarse vía Forms API bajo ningún formato: es una restricción de HubSpot, no un bug del plugin. Queda registrado en `hubspot-mapper.log` con el detalle devuelto por HubSpot.

---

## [2.4.4] — 2026-08-14

### Añadido
- **Log propio del plugin**, independiente de `WP_DEBUG_LOG`: `wp-content/plugins/hubspot-mapper/hubspot-mapper.log`. Necesario porque en el entorno de pruebas actual `WP_DEBUG_LOG` no está activo y no había forma de diagnosticar por qué algunos campos estáticos no llegaban a HubSpot.
- Logging explícito en `process()` de las props recibidas, los campos finalmente incluidos en el submit y el resultado, y en `ensure_form()` de qué campos se detectan como nuevos a sincronizar.

---

## [2.4.3] — 2026-08-14

### Corregido
- **Propiedades de objetos no-contact (`company-`, `deal-`, `ticket-`) nunca se registraban de verdad en HubSpot:** al añadir un campo al formulario de HubSpot (`update_form_fields()`/`create_form()`), el `propertyObjectType` se enviaba siempre como `CONTACT` y la resolución de tipos/enum (`get_property_types()`, `resolve_enum_value()`) solo consultaba `crm/v3/properties/contacts`. Para una propiedad que solo existe en Company/Deal/Ticket (p.ej. `company-country_code`), HubSpot rechazaba el registro del campo (visible como `update_form_fields fallback HTTP 400` en el log) y el campo quedaba fuera de la definición del formulario, así que la submission lo enviaba pero HubSpot lo ignoraba.
- Ahora cada campo (mapeado o estático) se registra con el `propertyObjectType` correcto (`CONTACT`/`COMPANY`/`DEAL`/`TICKET`) según su prefijo, y los tipos/opciones de propiedad se resuelven contra el objeto correspondiente. Mapeos existentes se autoresincronizan en el primer envío tras la actualización.

---

## [2.4.2] — 2026-08-14

### Corregido
- **Campos estáticos nuevos no llegaban a HubSpot en mapeos ya existentes:** `MGMIT_HS_Forms::ensure_form()` devolvía el `hs_form_guid` cacheado sin volver a llamar a `update_form_fields()`, así que un campo estático (p.ej. `company-country_code`) añadido a un mapeo que ya tenía formulario creado nunca se registraba en la definición del formulario en HubSpot; la submission lo incluía pero HubSpot lo descartaba por no reconocerlo. Ahora se persiste `hs_form_fields_synced` por mapeo y solo se llama a `update_form_fields()` cuando hay campos nuevos respecto a la última sincronización, evitando además una llamada extra a la API en cada envío normal.

---

## [2.4.1] — 2026-08-14

### Añadido
- **Soporte de prefijo de objeto (`objeto-propiedad`) en campos estáticos:** la UI de "Campos Estáticos" ahora documenta y permite el mismo formato ya soportado en campos mapeados (`contact-`, `company-`, `deal-`, `ticket-`). Ya funcionaba en backend para el modo overwrite; ahora queda expuesto y explicado en el formulario de mapeo.

### Corregido
- **`append_property()` (checkbox "Concatenar") ignoraba el objeto:** solo soporta la API de Contacts, ya que requiere lookup por email. Ahora `class-mgmit-adapters.php` detecta si un campo estático con `append=true` apunta a un objeto distinto de `contact` y lo omite con log, en vez de concatenar silenciosamente contra el contacto equivocado. La UI avisa de esta limitación al guardar.

---

## [Sin publicar] — 2026-07-30

### Añadido
- **Adaptador para Profile Builder** (`wppb_register_success`): permite mapear registros creados con el plugin Profile Builder. Se registra con `priority => 5` intencionadamente baja, ya que el tema engancha `pb_force_redirect_after_registration` en prioridad 10 con `wp_redirect()+exit`, cortando cualquier callback posterior si no se ejecuta antes.
- `MGMIT_Mapper_Adapters::resolve_profile_builder()`: resuelve los datos desde `$_REQUEST` (vía `wp_unslash()`), con fallback a `post_data()`.

### Corregido
- **`flatten()` usaba `, ` como separador para valores múltiples:** ahora usa `;`, alineado con `resolve_enum_value()` en `class-mgmit-hs-forms.php`, que espera ese separador en propiedades checkbox/select de HubSpot.

---

## [2.4.0] — 2026-07-13

### Añadido
- **Soporte multi-objeto en el mapeo de propiedades HubSpot:** las propiedades ahora admiten el formato `{objeto}-{propiedad}` (ej: `contact-email`, `company-name`, `deal-dealname`). Objetos soportados: `contact` (0-1), `company` (0-2), `deal` (0-3), `ticket` (0-5).
- Helper público `MGMIT_HS_Forms::parse_prop_key($key)`: descompone la clave en `objectTypeId` y `name`; si no hay prefijo de objeto reconocido, aplica fallback a `contact` para retrocompatibilidad total con mapeos existentes.

### Corregido
- **`submit()` enviaba todos los campos al objeto `contact` (`0-1`) de forma hardcodeada:** ahora cada campo lleva su `objectTypeId` correcto derivado del prefijo del nombre de propiedad.
- `process()`: la clasificación enum y la resolución de valores (`resolve_enum_value`) ahora usan el nombre de propiedad sin prefijo para consultar la API de HubSpot.
- `handle()` en adaptadores: la detección del campo email ahora reconoce tanto `email` como `contact-email`.
- `append_property` para campos estáticos con `append=true`: el nombre de propiedad se limpia de prefijo antes de enviarse a la CRM API.
- Validación `has_email` en `ajax_save_mapping`: acepta `contact-email` además de `email`.

### UI
- Placeholder de la columna "Propiedad HubSpot" actualizado a `contact-firstname` para ilustrar el nuevo formato.
- Cabecera de la columna muestra el formato esperado: `objeto-propiedad`.

---

## [2.3.3] — 2026-06-11

### Corregido
- **Creación de formulario fallaba si algún campo no existía como propiedad en HubSpot:** `create_form()` ahora crea el formulario únicamente con el campo `email` (siempre existe en HubSpot) y a continuación llama a `update_form_fields()` para añadir el resto de campos de forma incremental. Los campos que no existan en HubSpot fallan solo en esa llamada sin bloquear la creación del formulario. Elimina la necesidad de mapear primero solo campos válidos y volver a guardar.

---

## [2.3.2] — 2026-06-05

### Añadido
- **Campos estáticos por mapeo (`static_fields`):** desde la vista de edición del mapeo se pueden definir pares (propiedad HubSpot → valor fijo) que se envían siempre con la submission, aunque esos campos no existan en el formulario de WordPress.
- UI: tabla "Campos Estáticos" con botones añadir/eliminar fila y columna "Concatenar"; integrada en la sección `mgmit-hs-collect-section` (se deshabilita junto al resto cuando `do_not_collect` está activo).
- Persistencia: clave `static_fields` (array de `{hs_prop, value, append}`) dentro de cada entrada de `mgmit_mapper_config`.
- **Modo Concatenar (`append=true`):** para campos de tipo Multiple Checkbox, en lugar de sobreescribir el valor existente hace GET → merge (deduplicado, separador `;`) → PATCH vía CRM Contacts API. Los campos con `append=false` siguen yendo por la submission del formulario.
- `MGMIT_HS_Contacts::append_property($email, $prop, $value)`: método estático que implementa el flujo GET+merge+PATCH; si el contacto no existe (404) crea uno nuevo con POST.
- `update_form_fields($guid, $field_names)` en `MGMIT_HS_Forms`: detecta campos estáticos ausentes en la definición del formulario HubSpot y los añade via Forms API v2 PUT antes de la primera submission.

### Corregido
- **CF7 en producción:** JS de optimización/caché difería la carga del script; `mgmit_mapper_id` no se inyectaba antes de que CF7 serializara el formulario. Se añade un listener `submit` en fase de captura (`true`) en `hubspot_map.js` como garantía de último recurso — ejecuta `setupForm()` justo antes de cualquier otro handler.

---

## [2.3.1] — 2026-06-04

### Corregido
- **Forms API v2 en lugar de v3:** la creación de formularios cambia a `/forms/v2/forms` (estructura más simple, sin campos requeridos ocultos de v3).
- **`postSubmitAction.type`:** corregido de `"thank_you_text"` a `"thank_you"` (enum v3).
- **`fieldType`:** corregido de `"text"` a `"single_line_text"` / `"email"` (v3 no aceptaba `"text"`).
- **Log PHP 7.4:** el sitio corre PHP 7.4 vía ServBay; el log real está en `/Applications/ServBay/package/var/log/php/7.4/errors.log`, no en `wp-content/debug.log`.

### Añadido
- **`find_form_by_name()`:** antes de crear un formulario nuevo, busca en HubSpot si ya existe uno con el mismo nombre (`hubspotFormName`). Cascada: guid en WP options → buscar por nombre en HubSpot → crear nuevo.

---

## [2.3.0] — 2026-06-04

### Cambiado
- **Nuevo modelo de envío — Forms API v3 + Submissions API:** elimina la dependencia de
  LeadIn "collected forms" (JS, navegador, adblockers, configuración de cuenta HubSpot).
  El envío es ahora 100% server-side desde PHP.

### Añadido
- **`MGMIT_HS_Forms`** (`includes/class-mgmit-hs-forms.php`):
  - `ensure_form()` — crea el formulario en HubSpot automáticamente (Forms API v3,
    `POST /marketing/v3/forms`) la primera vez que se procesa un mapeo; persiste el
    `formGuid` en la config del mapeo para no recrearlo.
  - `submit()` — envía los datos al endpoint de submissions v3
    (`POST https://api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formGuid}`)
    sin autenticación. Incluye `hutk` (leído de `$_COOKIE['hubspotutk']` server-side)
    para atribución de sesión.
- `process()` orquesta ambos pasos y es el único punto de entrada desde los adaptadores.

### Eliminado del flujo principal
- `MGMIT_Ghost_Relay::queue()` ya no se llama desde los adaptadores.
  La clase relay se mantiene en el plugin pero no participa en el flujo de envío.
- Se elimina `MGMIT_Ghost_Relay::init()` del bootstrap (hooks de wp_footer e inyección
  del script de tracking ya no se registran).

### Requisitos
- Access Token con scope `forms` (además de `crm.objects.contacts.write`).

---

## [2.2.0] — 2026-06-04

### Cambiado (BREAKING)
- **Nuevo modelo de envío — Ghost Form:** los datos ya no se envían a la CRM Contacts API.
  Tras confirmarse el éxito server-side del formulario, PHP crea un formulario sintético invisible
  («ghost form») que LeadIn captura y envía a HubSpot — **sin formGuid**. Los registros aparecen
  en **Marketing → Formularios** bajo el nombre configurado en cada mapeo (`hubspotFormName`).

### Añadido
- **`MGMIT_Ghost_Relay`** (`includes/class-mgmit-ghost-relay.php`): gestiona el ciclo completo
  del relay — transient + cookie corta (120s), encolado del tracking script de HubSpot si no está
  presente en la página destino (`//js.hs-scripts.com/{portalId}.js`), emisión del inline script en
  `wp_footer`, y endpoint AJAX `mgmit_mapper_get_relay` para formularios AJAX (CF7).
- **Campo "Nombre en HubSpot"** (`hubspotFormName`) restaurado en el admin: valor obligatorio,
  se usa como `id`/`name` del ghost form → nombre en el dashboard de HubSpot.
- **Portal ID** en el panel de credenciales del admin: necesario para cargar el tracking script
  en páginas que no lo tengan. Cascada: opción propia → `mgmit_hubspot_credentials` → `mgmit_hubspot_config`.

### Frontend (`hubspot_map.js`)
- Añadido motor del ghost form: `fireGhostForm()`, `fireWhenReady()` (espera init de `_hsq`),
  `window.__mgmitFireGhost` (punto de entrada desde el inline script PHP).
- Listener `wpcf7mailsent` para formularios CF7 AJAX: llama al endpoint `mgmit_mapper_get_relay`
  y dispara el ghost form en la misma página sin recargar.
- `getCookie()` helper para leer `hubspotutk` (hutk) y `mgmit_hs_relay`.

### Notas
- La atribución por cookie (`hutk`) queda restablecida: el ghost form incluye `hs_context`
  con `hutk`, `pageUri` y `pageName` — exactamente como hacía el bridge en v1.x.
- `MGMIT_HS_Contacts` se mantiene en el plugin como clase de utilidad pero ya no se llama
  desde el flujo principal.

---

## [2.1.0] — 2026-06-04

### Cambiado
- **Credenciales propias (desacople del bridge):** `MGMIT_HS_Contacts::get_token()` ahora resuelve el token en cascada — `MGMIT_HS_ACCESS_TOKEN_SECRET` (constante) → opción propia `mgmit_mapper_credentials` → opción del bridge `mgmit_hubspot_credentials` (respaldo). El plugin ya **no depende** de que el bridge esté instalado.

### Añadido
- Panel **"Credenciales HubSpot"** en el admin (vista de lista): guarda el Access Token en la opción propia del plugin (AJAX `mgmit_mapper_save_credentials`) e indica de qué fuente se está tomando el token activo (o avisa si no hay ninguno).
- `MGMIT_HS_Contacts::get_token_source()` para diagnóstico del origen del token.

---

## [2.0.0] — 2026-06-04

### Cambiado (BREAKING)
- **Nuevo modelo de envío:** los datos ya **no** se capturan dejando que HubSpot LeadIn escuche el `submit`. Ahora el envío lo realiza PHP contra el **CRM Contacts API** (`crm/v3/objects/contacts`, upsert por email) **solo cuando el formulario confirma su éxito server-side** (tras pasar la validación JS y PHP del propio plugin de formularios). Esto elimina los registros erróneos en HubSpot cuando el formulario tiene errores.
- **Sin formulario en HubSpot:** no se usa `formGuid` ni Forms API. Se reutiliza el token configurado en el plugin **MeGeMIT HubSpot Bridge** (`mgmit_hubspot_credentials`, con override por constante `MGMIT_HS_ACCESS_TOKEN_SECRET`).
- **Modelo de datos del mapeo:** se elimina `hubspotFormName`; se añaden `source` (plugin de origen) y `email_field` (campo email, clave del upsert).

### Añadido
- **Capa de envío** [`MGMIT_HS_Contacts`](includes/class-mgmit-hs-contacts.php): upsert por email (PATCH `idProperty=email`, con POST de creación si 404).
- **Capa de adaptadores** [`MGMIT_Mapper_Adapters`](includes/class-mgmit-adapters.php) con hooks de éxito server-side para **SWPM**, **Contact Form 7**, **WooCommerce** y **Ultimate Member**. Extensible mediante el filtro `mgmit_mapper_adapters` (añadir plugins sin tocar el core).
- Admin UI: selector **"Origen del Formulario"** y campo **"Campo Email"** por mapeo; columna **"Origen"** en la lista.

### Frontend (`hubspot_map.js`)
- Reescrito a **vanilla JS** (sin jQuery). Aplica `data-hs-do-not-collect="true"` en todos los formularios configurados (LeadIn off) e inyecta el hidden `mgmit_mapper_id`.
- `MutationObserver` con debounce para formularios cargados por AJAX/diferidos.

### Notas
- La atribución por cookie de tracking (`hutk`) no se enlaza vía CRM API; es una limitación asumida al no usar un formulario de HubSpot.

---

## [1.1.0] — 2026-06-03

### Añadido
- Nueva opción por mapeo: **"Solo bloquear recogida de datos"** (`do_not_collect`).
  - Al activarla, HubSpot LeadIn no recogerá ni enviará los datos del formulario.
  - El atributo `data-hs-do-not-collect="true"` se inyecta en el `<form>` en el evento `DOMContentLoaded` nativo (antes de que LeadIn escanee la página), garantizando que el bloqueo llegue a tiempo.
  - Compatible con formularios cuyo selector apunta directamente al `<form>` o a un `div` contenedor (se localiza el `<form>` interno automáticamente).
- Insignia visual **"🚫 No recoger"** en la vista de lista del admin para mapeos con esta opción activa.

### Cambiado
- Los campos "Nombre en HubSpot" y "Mapeo de Campos" se deshabilitan visualmente en el admin cuando `do_not_collect` está activo (no son necesarios).
- Versión bumpeada de `1.0.0` → `1.1.0`.

---

## [1.0.0] — inicial

- Mapeo frontend de campos SWPM → propiedades HubSpot mediante renombrado en el submit.
- Sin llamadas server-side — integración 100% frontend.
- Admin UI para gestionar múltiples mapeos (selector de formulario, nombre HubSpot, pares de campos).
