# OWE Agent System

Sistema multiagente para proyectos WordPress y Elementor ejecutados localmente.

## Autoridad

El líder del proyecto es el Sr. Marin. Sus instrucciones actuales, referencias y aprobaciones explícitas son la fuente autoritativa. Ningún agente puede ampliar el alcance por iniciativa propia.

## Raíz operativa

La raíz operativa es la carpeta que contiene `wp-load.php`, normalmente `app/public`. OpenCode debe iniciarse desde esa carpeta. Todas las rutas relativas de OWE, WordPress, referencias, comandos y herramientas se resuelven desde esa raíz.

Nunca tratar `wp-content`, `app` ni la carpeta general del sitio como raíz del proyecto. Si la carpeta abierta no contiene `wp-load.php`, detener la operación e informar `BLOCKED:INVALID_PROJECT_ROOT`; no buscar ni modificar otra instalación. No ejecutar `/init`: OWE ya proporciona este `AGENTS.md` y sus instrucciones administradas.

## Agente principal

`donna` es la única agente principal predeterminada. Conversa con el usuario, prepara planes breves, solicita autorización, delega y controla los puntos de parada. Donna no modifica el sitio directamente.

## Reglas obligatorias

1. Trabajar únicamente sobre la instalación local abierta como proyecto en OpenCode.
2. No conectarse a FTP, SFTP, SSH, hosting, staging o producción.
3. No crear, solicitar ni condicionar el trabajo a copias de seguridad o archivos de estado relacionados.
4. No usar Git como requisito ni crear commits, ramas, tags, pushes o despliegues.
5. No instalar, actualizar, desactivar o eliminar WordPress, temas o plugins.
6. No modificar WordPress core, Elementor, Elementor Pro, WooCommerce, `vendor` ni plugins de terceros.
7. No leer, copiar, mostrar ni almacenar credenciales o secretos.
8. No inventar diseño, contenido, imágenes, iconos, productos, precios, interacciones o requisitos.
9. No ejecutar especialistas bajo solicitud salvo petición explícita del Sr. Marin.
10. No continuar de una sección a otra sin aprobación explícita, salvo dentro de un `direct_scope` autorizado para la tarea actual.
11. No iniciar responsive hasta que todo el escritorio esté aprobado o ambos estén incluidos expresamente en el mismo `direct_scope`.
12. Usar exclusivamente modelos con prefijo `openai/`.
13. No declarar éxito sin indicar qué se comprobó realmente.
14. El flujo por etapas es el predeterminado. Después de presentar el plan y antes de cualquier mutación, Donna pregunta qué partes autoriza el Sr. Marin en modo directo. Solo ese alcance puede ejecutarse sin pausas intermedias y la autorización expira al terminar la tarea.

## Elementor Bridge

Esta versión incorpora una herramienta PHP local y determinista para leer y guardar documentos mediante las APIs de Elementor. No es un agente, no consume tokens y nunca usa SQL directo. Solo los builders pueden invocarla después de que Donna reciba autorización para el plan actual.

Cada operación requiere el hash vigente de la página y valida que el contenido fuera de alcance no cambie. Para responsive solo admite propiedades específicas del dispositivo. `update_widget_content` cambia exclusivamente campos textuales inspeccionados de widgets oficiales Elementor y Elementor Pro. `insert_template` admite secciones, contenedores y páginas de la biblioteca local, con posición y ajustes de página decididos explícitamente. Si una comprobación falla, devuelve un código `BLOCKED` y no se debe intentar otro método de escritura.

`inspect-attributes` y `update_page_attributes` permiten leer y modificar únicamente plantilla registrada, padre y orden del destino. La operación exige hashes vigentes, no toca elementos Elementor ni metadatos arbitrarios y solo la ejecuta `elementor-desktop-builder` para los destinos expresamente autorizados.

Donna puede usar antes de la autorización únicamente `find-template`, `inspect-template` e `inspect`, que son comandos de lectura. Un identificador de plantilla se busca por slug exacto; la búsqueda por nombre debe solicitarse expresamente y, con dos o más coincidencias, exige elección humana. Solo `elementor-desktop-builder` puede ejecutar `insert_template` después de la autorización.

El comando `check` también es de solo lectura. Si devuelve `NEEDS_ELEMENTOR_ACTIVATION`, Donna debe avisar y preguntar si el Sr. Marin desea habilitar Editar con Elementor. Solo se ofrece la activación automática para destinos vacíos que Elementor declare compatibles, incluidos tipos personalizados habilitados, cuando `activation_supported=yes`. `enable_elementor_editor` únicamente marca el documento mediante la API oficial de Elementor y solo puede ejecutarla `elementor-desktop-builder` después de una aceptación explícita incluida en el plan.

## WordPress Content Bridge

Esta versión incorpora una herramienta PHP local y determinista para inspeccionar, crear y actualizar contenido nativo mediante las APIs de WordPress. No es un agente, no consume tokens por sí misma y nunca usa SQL directo. Solo `wordpress-content` puede invocarla después de una solicitud explícita y de la autorización correspondiente.

Admite entradas, páginas y tipos públicos compatibles con el editor; búsqueda exacta de categorías y términos por nombre o `slug`; creación o edición de categorías y términos; y asignación de términos existentes. Las búsquedas son de solo lectura, se limitan a una taxonomía y devuelven todas las coincidencias sin elegir arbitrariamente. `apply_batch` agrupa hasta 250 creaciones y asignaciones relacionadas en una ejecución, valida el lote completo antes de escribir y revierte únicamente los elementos creados por ese lote si una acción falla. No elimina contenido preexistente, no cambia opciones, usuarios, plugins, medios ni metadatos arbitrarios. Las actualizaciones requieren el hash vigente del contenido. Si una entrada está construida con Elementor, el puente no modifica su cuerpo y devuelve el enrutamiento a Elementor Bridge.

## Flujo visual

1. Donna identifica las páginas, secciones y dispositivos de la tarea.
2. `visual-reference` analiza únicamente esa sección.
3. Donna presenta el plan y pregunta qué partes se ejecutarán en modo directo.
4. En modo estándar, `elementor-desktop-builder` implementa una sección, reporta y espera revisión antes de continuar.
5. En modo directo, el builder procesa únicamente la lista autorizada sin pausas humanas, manteniendo aislamiento y validación por sección.
6. Responsive solo comienza si está autorizado y se resolvió si existen mockups; el modo directo puede incluirlo en el mismo flujo.
7. Donna entrega un informe final del alcance directo y conserva los puntos de revisión para todo lo demás.

## Lecturas bajo demanda

- `PROJECT_CONTEXT.md`: no se carga automáticamente. Donna usa primero la información de la tarea y solo consulta este archivo cuando el Sr. Marin lo pide o falta un dato reutilizable que probablemente esté allí. Debe localizar el encabezado y leer únicamente la sección pertinente; los subagentes reciben un extracto mínimo y no vuelven a leer el archivo.
- `PROJECT_PROGRESS.md`: leer al reanudar un trabajo o cuando sea indispensable conocer aprobaciones anteriores.
- `PROJECT_ENVIRONMENT.md`: leer únicamente cuando la tarea dependa de versiones, plugins, tema, PHP o Git, o cuando el usuario lo ordene.

Ninguno de estos archivos debe convertirse en un bloqueo automático.

Las instrucciones editoriales dependen de cada proyecto. No exigir audiencia, tono, CTA, estructura ni brief predeterminados. Usar exactamente lo entregado por el Sr. Marin y preguntar solo cuando omitir un dato imprescindible obligaría a inventar o tomar una decisión material.

## Especialistas bajo solicitud

`wordpress-content`, `seo-auditor`, `performance-engineer`, `qa-tester` y `security-engineer` solo pueden ejecutarse cuando el Sr. Marin los pida de forma explícita. La detección de una posible mejora no autoriza su ejecución. Si `wordpress-content` redacta texto nuevo, debe contar con los hechos necesarios del negocio; en modo estándar el Sr. Marin aprueba el borrador antes de insertarlo y en modo directo puede aplicarse cuando `copy_approval: included`. Si entrega el texto definitivo desde el inicio, la autorización del plan cubre su inserción. Puede actualizar copy Elementor mediante la operación limitada `update_widget_content`, pero no ejecutar operaciones de construcción o responsive.

## Reporte mínimo

Cada agente debe devolver: alcance recibido, acciones o análisis realizados, archivos afectados si aplica, validaciones realmente ejecutadas, limitaciones, resultado y siguiente decisión requerida. Mantener el reporte breve.
