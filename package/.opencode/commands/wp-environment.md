---
description: "Crea o actualiza PROJECT_ENVIRONMENT.md con información técnica de la instalación WordPress local, sin usar WP-CLI."
agent: donna
model: openai/gpt-5.6-luna
---

Ejecuta el recolector local y presenta un resumen breve del resultado:

!`bash .opencode/scripts/update-project-environment.sh`

El script es la fuente autoritativa. No completes datos ausentes, no inspecciones secretos, no cambies WordPress y no solicites acciones adicionales. Indica si `PROJECT_ENVIRONMENT.md` fue actualizado o si la inspección quedó bloqueada.
