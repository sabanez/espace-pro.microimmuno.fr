# Changelog — Divi-child (espace-pro.microimmuno.fr)

Solo se documentan los cambios hechos a partir de esta fecha; el historial anterior del tema no está recogido aquí.

## 2026-11-03

### Añadido (`functions.php`)
- **Autorrelleno de la especialidad en el alta de UMP.** En el formulario `#createuser` (página `/inscription/`), cuando el desplegable `prefession` vale `Pharmacien`, el desplegable `specialites` toma `Pharmacien / Pharmacienne`.
  - Solo cliente (JavaScript en `wp_footer`, prioridad 99); no se ejecuta con sesión iniciada.
  - Se aplica al cambiar la profesión y también al cargar la página.
  - Si luego se cambia la profesión, la especialidad no se vacía; el usuario puede cambiarla a mano.
  - El servidor no lo valida ni lo corrige.
  - Los nombres reales de los campos en UMP son `prefession` y `specialites` (con esa ortografía). Si se renombran en UMP, hay que actualizar el snippet.
  - Comprobado en la página real ejecutando la misma lógica en el navegador, sin enviar el formulario.
