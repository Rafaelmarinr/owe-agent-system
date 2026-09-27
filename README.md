# OWE Agent System v1.0.0

Sistema multiagente reutilizable para proyectos WordPress y Elementor ejecutados localmente con OpenCode.

## Alcance de esta versión

- Donna como agente principal y orquestadora.
- Diez agentes en total: Donna y nueve especialistas.
- Flujo de escritorio primero y responsive después.
- Construcción y corrección sección por sección con aprobación del Sr. Marin.
- Comando `/wp-environment` para crear o actualizar `PROJECT_ENVIRONMENT.md`.
- Elementor Bridge local para aplicar secciones, actualizar copy autorizado e insertar plantillas guardadas de sección, contenedor o página mediante las APIs de Elementor.
- WordPress Content Bridge local para buscar categorías y términos por nombre o `slug`, crear y actualizar contenido nativo y ejecutar lotes reutilizables mediante las APIs de WordPress.
- Project File Bridge local para modificar PHP y CSS propios de temas hijo, plugins declarados y `mu-plugins` declarados, y crear archivos nuevos con autorización explícita, validación, escritura atómica y rollback.
- Autorizaciones directas por tarea con excepciones de barrera limitadas a operaciones y destinos concretos, incluyendo CSS personalizado validado de Elementor.
- Modelos GPT mediante la conexión `openai/*` de la suscripción de ChatGPT.
- Instalación y actualización seguras en la raíz de un WordPress local.

Esta versión no incluye integración WP-CLI, acceso FTP/SFTP/SSH, despliegues, SQL directo ni automatización de navegador incorporada. WordPress Content Bridge no elimina contenido. El Project File Bridge modifica PHP y CSS propios permitidos, pero la creación de cualquier archivo requiere autorización específica; no modifica core, plugins de terceros, secretos, `vendor`, JavaScript, JSON ni configuración. El copy Elementor se actualiza exclusivamente mediante el catálogo editorial y las validaciones de Elementor Bridge; addons, Dynamic Tags, shortcodes y controles funcionales quedan fuera.

## Requisitos

- OpenCode 1.18.29 o posterior compatible.
- Proveedor OpenAI conectado mediante ChatGPT Plus o Pro.
- Los modelos configurados disponibles en `opencode models openai`.
- Proyecto WordPress instalado localmente.
- OpenCode abierto desde la raíz del WordPress, por ejemplo `app/public`.
- PHP del entorno local. OWE detecta automáticamente LocalWP, XAMPP/LAMPP, MAMP, WampServer, Laragon y el PHP disponible en la terminal. También puede ejecutar PHP dentro de proyectos DDEV o Lando.

Si `/wp-environment` devuelve `BLOCKED:PHP_RUNTIME_NOT_FOUND`, compruebe que el entorno y el sitio estén iniciados. El detalle de las rutas revisadas queda exclusivamente en `.owe/logs/wp-environment.log` para facilitar el diagnóstico sin cargar esa información en el contexto del modelo. En una instalación personalizada se pueden definir `OWE_PHP_BIN`, `OWE_PHP_INI` y `OWE_PHP_LIBS`; OWE nunca elige al azar entre varias versiones de PHP.

### Entornos PHP reconocidos

| Entorno | Método |
|---|---|
| LocalWP | Relaciona la raíz `app/public`, `sites.json`, la versión del sitio, `php.ini` y `shared-libs`. |
| XAMPP/LAMPP | Detecta las rutas estándar de Linux, macOS y Windows. |
| MAMP, WampServer y Laragon | Usa automáticamente una única versión válida; si existen varias, exige una selección explícita. |
| PHP del sistema, Homebrew, Herd o Valet | Utiliza `php` cuando está disponible en `PATH`. |
| DDEV | Ejecuta PHP dentro del contenedor web asociado al proyecto. |
| Lando | Utiliza el tooling `lando php` cuando está configurado en el proyecto. |
| Instalación personalizada | Permite definir `OWE_PHP_BIN`, `OWE_PHP_INI` y `OWE_PHP_LIBS` antes de iniciar OpenCode. |

WampServer, Laragon y XAMPP para Windows requieren que OWE se ejecute desde un entorno compatible con Bash, como Git Bash o WSL. Los proyectos Docker Compose personalizados necesitan configurar su propio runtime; OWE no intenta adivinar el servicio o el montaje del contenedor.

## Instalación

Desde la carpeta descomprimida:

```bash
bash scripts/install.sh "/ruta/al/proyecto/app/public"
```

Luego:

1. Si lo necesita, complete en `PROJECT_CONTEXT.md` únicamente información estable y reutilizable; las instrucciones específicas pueden entregarse con cada tarea.
2. Coloque las referencias en `referencias/`.
3. Inicie el sitio WordPress local.
4. Abra OpenCode desde la raíz del proyecto.
5. No ejecute `/init`; OWE ya incluye un `AGENTS.md` administrado.

La raíz operativa es la carpeta que contiene `wp-load.php`, normalmente `app/public`. No instale ni abra OWE desde `wp-content`, `app` o la carpeta general del sitio.
5. Compruebe que el agente activo sea `Donna`.

## Actualización o reemplazo de una instalación anterior

```bash
bash scripts/update.sh "/ruta/al/proyecto/app/public"
```

El actualizador conserva `PROJECT_CONTEXT.md`, `PROJECT_PROGRESS.md`, `PROJECT_ENVIRONMENT.md` y la carpeta `referencias/`. Solo reemplaza los archivos que pertenecen al sistema de agentes.

## Validación del paquete

```bash
bash scripts/validate-package.sh
```

## Inicio rápido

1. Háblele directamente a Donna.
2. Entregue una referencia y especifique la página y la sección.
3. Revise el plan de Donna.
4. Antes de comenzar, indique qué partes desea ejecutar en modo directo o elija el flujo estándar por etapas.
5. En modo estándar, revise cada sección en la web local y escriba `Sección aprobada. Continúa con la siguiente.` únicamente cuando esté conforme.
6. En modo directo, Donna ejecuta sin pausas únicamente las páginas, secciones, dispositivos y pasos seleccionados, y entrega una revisión final conjunta.

El modo directo nunca se activa por defecto. Donna pregunta después de presentar el plan y antes de cualquier modificación si debe aplicarse a todo el plan o solo a partes concretas. Esa autorización expira al terminar la tarea y no cubre trabajo nuevo. Las validaciones técnicas, hashes, aislamiento y bloqueos continúan siendo obligatorios. Si el alcance directo incluye redacción, el copy puede aplicarse sin borrador previo y se reporta al final; en modo estándar conserva su aprobación separada.

Para contenido, pida expresamente a Donna que convoque `wordpress-content` y entregue las instrucciones editoriales correspondientes al proyecto por texto o mediante un PDF concreto. No se exige un brief predeterminado. Donna usa primero la tarea actual; solo consulta la sección pertinente de `PROJECT_CONTEXT.md` cuando usted lo indica o falta un dato reutilizable, y delega un extracto mínimo para no duplicar contexto. Si el agente redacta, en modo estándar presenta primero el borrador; en modo directo puede insertarlo sin pausa únicamente con `copy_approval: included`. En ambos casos conserva una longitud aproximada al copy original. En contenido nativo, WordPress Content Bridge aplica los cambios autorizados y agrupa operaciones repetitivas con `apply_batch`. Si el destino usa Elementor, `inspect-content` entrega únicamente el copy editable de una sección y `update_widget_content` aplica en una sola ejecución todos los campos aprobados, sin exponer estilos ni el árbol completo al modelo.

Para insertar una plantilla, indique su slug, por ejemplo `usa la plantilla for-services`. Donna busca el slug exacto sin recorrer la biblioteca con IA. También puede solicitar una búsqueda por nombre exacto; si devuelve dos o más coincidencias, Donna mostrará sus datos y preguntará cuál usar. Antes del plan preguntará si debe insertarse en una posición específica, añadirse al final o reemplazar el contenido, y para plantillas de página preguntará si también debe aplicar sus ajustes. Headers, footers, popups y Theme Builder quedan fuera de esta versión.

Si Editar con Elementor todavía no está habilitado en un destino vacío compatible, Donna lo detecta sin modificar el sitio, avisa y pregunta si debe habilitarlo. La compatibilidad se consulta directamente a Elementor e incluye los tipos personalizados habilitados en sus ajustes. Solo después de una aceptación explícita, Elementor Bridge activa el editor mediante la API oficial. Esta operación no convierte contenido nativo, no crea widgets y no inserta la plantilla por sí misma; los destinos con contenido o metadatos Elementor parciales se bloquean.

Elementor Bridge también puede inspeccionar y modificar atributos estándar sin reinsertar contenido: cualquier plantilla registrada para el tipo de destino, su padre y su orden. Donna aplica únicamente los campos y destinos autorizados. Elementor Full Width usa el slug `elementor_header_footer`, pero no está codificado como única opción.

Consulte el manual PDF incluido para el flujo completo, permisos y solución de problemas.
