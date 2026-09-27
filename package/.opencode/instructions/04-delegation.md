# Contrato de delegación

Donna debe incluir en cada encargo:

- solicitud exacta del Sr. Marin;
- página, sección y dispositivo;
- referencia autoritativa;
- alcance permitido;
- elementos que no pueden cambiarse;
- criterio de aceptación;
- punto de parada;
- estado de autorización;
- `authorization_mode`: `standard` o `direct`;
- `direct_scope`: páginas, secciones, dispositivos y pasos cubiertos, o `none`;
- `human_checkpoints`: `required` o `final_only`;
- `copy_approval`: `required` o `included`.

Los subagentes no amplían el alcance ni negocian decisiones materiales. Si falta un dato que cambiaría el resultado, devuelven el bloqueo a Donna.

En modo directo, un subagente puede ejecutar secuencialmente los elementos enumerados en `direct_scope` sin volver a pedir revisión, pero conserva una operación determinista, inspección y hash vigente por destino. Continúa después de bloqueos independientes y devuelve un único informe final; una decisión material no cubierta detiene solo la rama afectada. Fuera de `direct_scope` rige siempre el modo estándar.

Para especialistas bajo solicitud, Donna debe incluir la frase o intención explícita del usuario que autorizó convocarlos.

Para redactar copy, Donna debe delegar únicamente el contexto y las instrucciones editoriales que el Sr. Marin haya entregado y que afecten a la tarea actual. No completar ni exigir audiencia, tono, CTA, estructura o brief predeterminados. Si usa `PROJECT_CONTEXT.md`, leer solo la sección pertinente y delegar un extracto mínimo. Si el Sr. Marin indica un PDF, delegar la ruta exacta y las páginas o apartados señalados para que `wordpress-content` lo lea directamente. Para copy Elementor, incluir una sola sección, los campos inspeccionados y la instrucción de preservar estructura, estilos y longitud aproximada.

Para insertar una plantilla, Donna debe incluir: destino, ID, título, slug, tipo y hash de la plantilla seleccionada; posición aprobada; sección de referencia si aplica; decisión explícita sobre ajustes de página; alcance que debe conservarse; y estado de autorización. El builder no repite la búsqueda ni elige entre coincidencias.

Para modificar atributos estándar, Donna debe incluir los destinos exactos, el resultado vigente de `inspect-attributes`, cada campo solicitado y su valor aprobado. No trasladar atributos a otros destinos por similitud. En modo directo, `direct_scope` enumera todos los destinos cubiertos y el builder conserva una operación y verificación independientes por cada uno.

Si `check` devuelve `NEEDS_ELEMENTOR_ACTIVATION`, Donna debe incluir el `source_hash`, el estado `activation_supported` y la decisión explícita del Sr. Marin. Sin aceptación, el subagente se detiene. Con aceptación y soporte confirmado, habilita Editar con Elementor antes de continuar con la operación ya autorizada. Nunca convierte contenido nativo ni activa un destino que no esté vacío.

Para cambios de código, Donna debe incluir la solicitud exacta, la funcionalidad esperada, los archivos relativos enumerados, la ubicación de código propio, el comportamiento que no debe cambiar, el criterio de aceptación, las validaciones y la autorización. `php-project-editor` no puede ampliar la lista de archivos ni escribir mediante Bash genérico.

Cuando el plan incluya una excepción, la delegación debe transmitir `barrier_exceptions`, `direct_scope.operations` y los destinos exactos cubiertos. `elementor.custom_css` solo autoriza `update_widget_style`; `project.php_file` y `project.css_file` solo autorizan archivos existentes enumerados; `project.create_file` es obligatorio para cada archivo nuevo.
