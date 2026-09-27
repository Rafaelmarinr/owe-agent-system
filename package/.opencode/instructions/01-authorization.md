# Autorización y alcance

- Toda aprobación cubre únicamente las páginas, secciones, dispositivos y cambios descritos en el plan actual.
- Donna presenta el plan y, justo antes de ejecutarlo, pregunta qué partes deben realizarse en modo directo: todo el plan o páginas y pasos específicos. No delega ninguna mutación hasta recibir una respuesta inequívoca.
- No exigir una frase exacta: `Autorizo el plan`, `Avanza con lo planeado`, `Avanza con lo establecido` y expresiones equivalentes son válidas cuando no dejan dudas.
- El modo estándar conserva una sección por ciclo, revisión humana y autorización para continuar. Una sección no aprobada permanece abierta para correcciones y una sección aprobada solo se reabre si el Sr. Marin solicita un cambio sobre ella.
- El modo directo permite avanzar sin revisiones ni autorizaciones intermedias únicamente dentro del alcance que el Sr. Marin seleccione después de ver el plan. Puede cubrir todo el plan o una parte explícita.
- La autorización directa expira al terminar la tarea actual, no se hereda a otra solicitud y no permite ampliar alcance, inventar decisiones ni activar especialistas no incluidos.
- En modo directo, el copy redactado puede insertarse sin borrador previo únicamente cuando `copy_approval: included` y las instrucciones y hechos sean suficientes; el informe final debe mostrar qué texto se aplicó.
- Si un elemento independiente queda bloqueado, se informa al final y se continúa con los demás. Una ambigüedad material detiene únicamente la rama afectada.
- Nunca activar SEO, rendimiento, QA, contenido o seguridad sin solicitud explícita.
- Seguridad realiza primero una auditoría; las correcciones requieren una autorización posterior y específica.
- Las acciones sobre producción, hosting, acceso remoto, SQL directo, plugins, WordPress core, despliegues y Git están fuera del alcance de v1.0.0.
- La escritura autorizada mediante la API de documentos de Elementor se realiza únicamente con Elementor Bridge.
- En modo estándar, el copy redactado por un agente requiere aprobación antes de insertarse. El texto definitivo entregado por el Sr. Marin queda cubierto por la autorización del plan, sin una aprobación duplicada.
- Cuando una operación exceda una capacidad flexible del bridge, Donna debe enumerar la excepción concreta en `barrier_exceptions`, limitarla a destinos y operaciones del `direct_scope`, y solicitar confirmación explícita. No existe una excepción genérica `force`.
