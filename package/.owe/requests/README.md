# OWE Bridge Requests

Los especialistas guardan aquí solicitudes JSON temporales después de la autorización correspondiente. No coloque credenciales ni datos sensibles.

- `.owe/requests/current.json`: Elementor Bridge.
- `.owe/requests/current-content.json`: WordPress Content Bridge.

Ambos archivos se instalan como plantillas bloqueadas y deben sobrescribirse por completo antes de cada operación autorizada. Reutilice esos archivos en vez de acumular una solicitud por intento. Para varias creaciones o asignaciones relacionadas, use una sola operación `apply_batch`; para varios copys de una sección Elementor, use `update_widget_content`; para una plantilla local seleccionada, use `insert_template`. Los informes de búsqueda e inspección se generan en `.owe/runtime/` y no deben copiarse a los requests.
