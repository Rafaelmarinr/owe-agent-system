# Límites técnicos de v1.0.0

- No existe integración WP-CLI.
- Existe Elementor Bridge para lectura y escritura local mediante las APIs de documentos de Elementor.
- Existe WordPress Content Bridge para contenido nativo y para buscar, crear, actualizar y asignar categorías o términos mediante las APIs de WordPress.
- No existe acceso FTP, SFTP o SSH.
- No existe despliegue a producción.
- No se incorpora una herramienta de navegador.
- No modificar `_elementor_data` ni otros metadatos Elementor mediante SQL o edición directa.
- Elementor Bridge solo admite operaciones de una sección con hash vigente, aislamiento y validación posterior.
- WordPress Content Bridge no elimina contenido preexistente, no modifica medios, usuarios, opciones ni metadatos arbitrarios y bloquea la edición del cuerpo de entradas construidas con Elementor. `apply_batch` puede retirar únicamente términos o entradas creados por el mismo lote cuando necesita revertir una ejecución fallida.
- Elementor Bridge puede actualizar copy de controles textuales en widgets oficiales Elementor y Elementor Pro, incluidos repeaters con `_id`. No admite widgets de addons, Dynamic Tags, shortcodes, URLs, HTML personalizado ni settings no textuales.
- Elementor Bridge puede buscar por slug o nombre exactos e insertar plantillas locales de tipo `section`, `container` y `page`. No admite headers, footers, popups, Theme Builder, Loop Items ni búsquedas aproximadas. Puede habilitar Editar con Elementor en destinos vacíos oficialmente compatibles, incluidos tipos personalizados habilitados, después de avisar y recibir aceptación explícita; no convierte contenido nativo ni corrige estados Elementor parciales.
- Elementor Bridge puede cambiar atributos estándar de destinos Elementor: cualquier plantilla registrada para el tipo, padre y orden. No admite metadatos arbitrarios; padre y orden requieren un tipo jerárquico.
- Elementor Bridge permite `custom_css` mediante `update_widget_style` solo con la excepción autorizada `elementor.custom_css`; no desbloquea settings Elementor arbitrarios ni código ejecutable.
- No afirmar que una sección se modificó dentro de Elementor si el Bridge no devolvió `APPLIED`.
- No afirmar que contenido nativo se modificó si WordPress Content Bridge no devolvió `APPLIED`.
- Project File Bridge modifica PHP y CSS de temas hijo o raíces de plugins y `mu-plugins` propios declaradas; permite crear archivos nuevos solo con `project.create_file`. No modifica JavaScript, JSON, core, terceros, `vendor`, secretos ni base de datos.
- Los plugins propios deben declararse en `.owe/project-file-policy.json`; la existencia de una cabecera de plugin no demuestra propiedad.
- Las solicitudes de archivos requieren rutas enumeradas, hash vigente para archivos existentes, contenido aprobado y `user_confirmed: true`; la creación exige además una autorización específica.
- Las excepciones `elementor.custom_css` y `project.php_file` son temporales, deben enumerarse en la autorización de la tarea y no se heredan a solicitudes posteriores.

 Cuando una tarea exceda estos límites, explicar exactamente qué parte sí pudo realizarse y cuál necesita intervención manual o una versión futura.
