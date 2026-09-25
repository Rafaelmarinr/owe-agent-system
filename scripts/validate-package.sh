#!/usr/bin/env bash
set -euo pipefail

owe_root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
owe_package="$owe_root/package"

owe_required=(
  "$owe_package/AGENTS.md"
  "$owe_package/PROJECT_CONTEXT.md"
  "$owe_package/PROJECT_PROGRESS.md"
  "$owe_package/opencode.json"
  "$owe_package/.opencode/agents/donna.md"
  "$owe_package/.opencode/commands/wp-environment.md"
  "$owe_package/.opencode/scripts/update-project-environment.sh"
  "$owe_package/.opencode/scripts/inspect-wordpress.php"
  "$owe_package/.opencode/tools/runtime/resolve-php.sh"
  "$owe_package/.opencode/tools/runtime/run-php.sh"
  "$owe_package/.opencode/tools/elementor-bridge/bridge.sh"
  "$owe_package/.opencode/tools/elementor-bridge/bridge.php"
  "$owe_package/.opencode/tools/elementor-bridge/checks.php"
  "$owe_package/.opencode/tools/elementor-bridge/reader.php"
  "$owe_package/.opencode/tools/elementor-bridge/validator.php"
  "$owe_package/.opencode/tools/elementor-bridge/writer.php"
  "$owe_package/.opencode/tools/elementor-bridge/templates.php"
  "$owe_package/.opencode/tools/elementor-bridge/REQUEST_SCHEMA.md"
  "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.sh"
  "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php"
  "$owe_package/.opencode/tools/wordpress-content-bridge/checks.php"
  "$owe_package/.opencode/tools/wordpress-content-bridge/validator.php"
  "$owe_package/.opencode/tools/wordpress-content-bridge/REQUEST_SCHEMA.md"
  "$owe_package/.owe/requests/README.md"
  "$owe_package/.owe/requests/current.json"
  "$owe_package/.owe/requests/current-content.json"
  "$owe_root/tests/fixtures/content-batch.json"
  "$owe_root/tests/fixtures/elementor-enable-request.json"
  "$owe_root/tests/fixtures/elementor-page-attributes-request.json"
  "$owe_root/tests/fixtures/elementor-widget-content.json"
  "$owe_root/tests/fixtures/elementor-template-request.json"
  "$owe_root/tests/fixtures/fake-wordpress/wp-load.php"
  "$owe_root/tests/test-elementor-widget-content.php"
)

for owe_file in "${owe_required[@]}"; do
  if [[ ! -f "$owe_file" ]]; then
    echo "Falta archivo requerido: $owe_file" >&2
    exit 1
  fi
done

python3 - "$owe_package/opencode.json" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    config = json.load(handle)

if config.get("default_agent") != "donna":
    raise SystemExit("default_agent debe ser donna")
if config.get("enabled_providers") != ["openai"]:
    raise SystemExit("enabled_providers debe contener exclusivamente openai")
if config.get("subagent_depth") != 1:
    raise SystemExit("subagent_depth debe ser 1")
instructions = config.get("instructions", [])
if any("PROJECT_CONTEXT.md" in instruction for instruction in instructions):
    raise SystemExit("PROJECT_CONTEXT.md no debe cargarse automáticamente")
PY

python3 - "$owe_package/AGENTS.md" "$owe_package/.opencode/instructions/01-authorization.md" "$owe_package/.opencode/instructions/02-visual-workflow.md" "$owe_package/.opencode/instructions/04-delegation.md" "$owe_package/.opencode/agents/donna.md" "$owe_package/.opencode/agents/elementor-desktop-builder.md" "$owe_package/.opencode/agents/elementor-responsive-builder.md" "$owe_package/.opencode/agents/wordpress-content.md" "$owe_root/README.md" "$owe_root/CHANGELOG.md" <<'PY'
import pathlib
import sys

agents_path, authorization_path, visual_path, delegation_path, donna_path, desktop_path, responsive_path, content_path, readme_path, changelog_path = map(pathlib.Path, sys.argv[1:])
agents = agents_path.read_text(encoding="utf-8")
authorization = authorization_path.read_text(encoding="utf-8")
visual = visual_path.read_text(encoding="utf-8")
delegation = delegation_path.read_text(encoding="utf-8")
donna = donna_path.read_text(encoding="utf-8")
desktop = desktop_path.read_text(encoding="utf-8")
responsive = responsive_path.read_text(encoding="utf-8")
content = content_path.read_text(encoding="utf-8")
readme = readme_path.read_text(encoding="utf-8")
changelog = changelog_path.read_text(encoding="utf-8")

for required in ("flujo por etapas es el predeterminado", "direct_scope", "expira al terminar la tarea"):
    if required not in agents:
        raise SystemExit(f"Contrato global de modo directo incompleto: {required}")
for required in ("modo estándar", "modo directo", "expira al terminar la tarea actual", "copy_approval: included"):
    if required not in authorization:
        raise SystemExit(f"Autorización directa incompleta: {required}")
for required in ("modo estándar", "modo directo", "hash vigente por sección"):
    if required not in visual:
        raise SystemExit(f"Flujo visual directo incompleto: {required}")
for required in ("authorization_mode", "direct_scope", "human_checkpoints", "copy_approval"):
    if required not in delegation:
        raise SystemExit(f"Delegación directa incompleta: {required}")
for required in ("Después del plan", "qué partes autoriza", "final_only", "congela la lista"):
    if required not in donna:
        raise SystemExit(f"Donna no aplica modo directo: {required}")
for specialist in ("wordpress-content", "seo-auditor", "performance-engineer", "qa-tester", "security-engineer"):
    if f'"{specialist}": allow' not in donna or f'"{specialist}": ask' in donna:
        raise SystemExit(f"Donna conserva una aprobación de herramienta redundante: {specialist}")
for label, text in (("desktop", desktop), ("responsive", responsive)):
    for required in ("modo estándar", "modo directo", "direct_scope"):
        if required not in text:
            raise SystemExit(f"Builder {label} no aplica modo directo: {required}")
for required in ("copy_approval: included", "modo directo", "informe final"):
    if required not in content:
        raise SystemExit(f"wordpress-content no aplica modo directo: {required}")
for required in ("modo directo nunca se activa por defecto", "después de presentar el plan", "expira al terminar la tarea"):
    if required not in readme.lower():
        raise SystemExit(f"README no documenta modo directo: {required}")
if "modo estándar presenta primero el borrador" not in readme or "copy_approval: included" not in changelog:
    raise SystemExit("Documentación editorial inconsistente con el modo directo")
PY

python3 - "$owe_root/tests/fixtures/elementor-enable-request.json" "$owe_package/.opencode/tools/elementor-bridge/bridge.php" "$owe_package/.opencode/tools/elementor-bridge/checks.php" "$owe_package/.opencode/agents/donna.md" "$owe_package/.opencode/agents/elementor-desktop-builder.md" <<'PY'
import json
import pathlib
import re
import sys

fixture_path, bridge_path, checks_path, donna_path, builder_path = map(pathlib.Path, sys.argv[1:])
with fixture_path.open(encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("operation") != "enable_elementor_editor" or request.get("device") != "desktop":
    raise SystemExit("Fixture activación Elementor: operación inválida")
if request.get("native_content_policy") != "require_empty":
    raise SystemExit("Fixture activación Elementor: política inválida")
if request.get("user_confirmed") is not True:
    raise SystemExit("Fixture activación Elementor: falta confirmación")
if not re.fullmatch(r"[a-f0-9]{64}", request.get("expected_source_hash", "")):
    raise SystemExit("Fixture activación Elementor: hash inválido")

bridge = bridge_path.read_text(encoding="utf-8")
checks = checks_path.read_text(encoding="utf-8")
for required in (
    "NEEDS_ELEMENTOR_ACTIVATION",
    "enable_elementor_editor",
    "set_is_built_with_elementor(true)",
    "Elementor\\Utils::is_post_support",
    "function owe_bridge_elementor_activation_hash",
    "NATIVE_CONTENT_PRESENT",
    "ELEMENTOR_STATE_INCONSISTENT",
):
    if required not in bridge + checks:
        raise SystemExit(f"Activación Elementor incompleta: {required}")
activation_block = bridge.split("if ($operation === 'enable_elementor_editor')", 1)[1].split(
    "owe_bridge_check_elementor($requiresPro)", 1
)[0]
if any(forbidden in activation_block for forbidden in ("convert_to_elementor", "owe_bridge_save_document", "->save(")):
    raise SystemExit("La activación Elementor excede el alcance permitido")

donna = donna_path.read_text(encoding="utf-8")
builder = builder_path.read_text(encoding="utf-8")
for required in ("NEEDS_ELEMENTOR_ACTIVATION", "pregunta si", "activation_supported=yes"):
    if required not in donna:
        raise SystemExit(f"Donna no protege la activación Elementor: {required}")
for required in ("enable_elementor_editor", "source_hash", "No convierte contenido nativo"):
    if required not in builder:
        raise SystemExit(f"Builder no protege la activación Elementor: {required}")
PY

python3 - "$owe_root/tests/fixtures/elementor-page-attributes-request.json" "$owe_package/.opencode/tools/elementor-bridge/bridge.php" "$owe_package/.opencode/tools/elementor-bridge/reader.php" "$owe_package/.opencode/tools/elementor-bridge/writer.php" "$owe_package/.opencode/agents/donna.md" "$owe_package/.opencode/agents/elementor-desktop-builder.md" <<'PY'
import json
import pathlib
import re
import sys

fixture_path, bridge_path, reader_path, writer_path, donna_path, builder_path = map(pathlib.Path, sys.argv[1:])
with fixture_path.open(encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("operation") != "update_page_attributes" or request.get("device") != "desktop":
    raise SystemExit("Fixture atributos: operación inválida")
if request.get("user_confirmed") is not True:
    raise SystemExit("Fixture atributos: falta confirmación")
for key in ("expected_page_hash", "expected_attributes_hash", "expected_templates_hash"):
    if not re.fullmatch(r"[a-f0-9]{64}", request.get(key, "")):
        raise SystemExit(f"Fixture atributos: hash inválido {key}")
attributes = request.get("attributes")
if not isinstance(attributes, dict) or not attributes or set(attributes) - {"template", "parent_id", "menu_order"}:
    raise SystemExit("Fixture atributos: campos inválidos")

combined = "\n".join(path.read_text(encoding="utf-8") for path in (bridge_path, reader_path, writer_path))
for required in (
    "inspect-attributes",
    "update_page_attributes",
    "function owe_bridge_page_attributes_hash",
    "function owe_bridge_available_page_templates",
    "function owe_bridge_prepare_page_attributes",
    "function owe_bridge_restore_page_attributes",
    "PAGE_ATTRIBUTES_VALIDATION_FAILED",
):
    if required not in combined:
        raise SystemExit(f"Atributos estándar incompletos: {required}")
attributes_block = bridge_path.read_text(encoding="utf-8").split(
    "if ($operation === 'update_page_attributes')", 1
)[1].split("elseif ($operation === 'insert_template')", 1)[0]
if any(forbidden in attributes_block for forbidden in ("owe_bridge_save_document", "->save(")):
    raise SystemExit("Los atributos estándar invocan un guardado general de Elementor")
for required in ("owe_bridge_apply_page_attributes", "expected_templates_hash"):
    if required not in bridge_path.read_text(encoding="utf-8"):
        raise SystemExit(f"Aplicación acotada de atributos incompleta: {required}")

donna = donna_path.read_text(encoding="utf-8")
builder = builder_path.read_text(encoding="utf-8")
for required in ("inspect-attributes", "template`, `parent_id` y `menu_order", "destinos no enumerados"):
    if required not in donna:
        raise SystemExit(f"Donna no protege atributos estándar: {required}")
for required in ("update_page_attributes", "available_templates", "elementor_header_footer"):
    if required not in builder:
        raise SystemExit(f"Builder no protege atributos estándar: {required}")
PY

python3 - "$owe_root/tests/fixtures/elementor-template-request.json" "$owe_package/.opencode/tools/elementor-bridge/bridge.php" "$owe_package/.opencode/tools/elementor-bridge/templates.php" "$owe_package/.opencode/agents/donna.md" "$owe_package/.opencode/agents/elementor-desktop-builder.md" <<'PY'
import json
import pathlib
import re
import sys

fixture_path, bridge_path, templates_path, donna_path, builder_path = map(pathlib.Path, sys.argv[1:])
with fixture_path.open(encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("operation") != "insert_template" or request.get("device") != "desktop":
    raise SystemExit("Fixture plantilla: operación inválida")
if request.get("position") not in {"prepend", "append", "before", "after", "replace"}:
    raise SystemExit("Fixture plantilla: posición inválida")
if not isinstance(request.get("apply_page_settings"), bool):
    raise SystemExit("Fixture plantilla: falta decisión de ajustes")
for key in ("expected_page_hash", "expected_template_hash"):
    if not re.fullmatch(r"[a-f0-9]{64}", request.get(key, "")):
        raise SystemExit(f"Fixture plantilla: {key} inválido")

combined = bridge_path.read_text(encoding="utf-8") + templates_path.read_text(encoding="utf-8")
for required in (
    "find-template",
    "inspect-template",
    "insert_template",
    "function owe_bridge_find_templates",
    "function owe_bridge_prepare_template_clone",
    "function owe_bridge_verify_template_isolation",
    "TEMPLATE_PAGE_SETTINGS_DECISION_REQUIRED",
):
    if required not in combined:
        raise SystemExit(f"Integración de plantillas incompleta: {required}")

donna = donna_path.read_text(encoding="utf-8")
builder = builder_path.read_text(encoding="utf-8")
for required in ("dos o más coincidencias", "posición específica", "ajustes de página"):
    if required not in donna:
        raise SystemExit(f"Donna no aplica el flujo de plantillas: {required}")
for required in ("insert_template", "apply_page_settings", "No reconstruyas la plantilla con IA"):
    if required not in builder:
        raise SystemExit(f"Builder no aplica el contrato de plantillas: {required}")
PY

python3 - "$owe_root/tests/fixtures/elementor-widget-content.json" "$owe_package/.opencode/tools/elementor-bridge/bridge.php" "$owe_package/.opencode/tools/elementor-bridge/reader.php" "$owe_package/.opencode/tools/elementor-bridge/validator.php" "$owe_package/.opencode/tools/elementor-bridge/writer.php" "$owe_package/.opencode/agents/wordpress-content.md" <<'PY'
import json
import pathlib
import re
import sys

fixture_path, bridge_path, reader_path, validator_path, writer_path, agent_path = map(pathlib.Path, sys.argv[1:])
with fixture_path.open(encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("schema") != "owe-elementor-bridge/1.0":
    raise SystemExit("Fixture copy Elementor: schema inválido")
if request.get("operation") != "update_widget_content" or request.get("device") != "all":
    raise SystemExit("Fixture copy Elementor: operación inválida")
if not re.fullmatch(r"[a-f0-9]{64}", request.get("expected_page_hash", "")):
    raise SystemExit("Fixture copy Elementor: hash de página inválido")
updates = request.get("updates")
if not isinstance(updates, list) or len(updates) < 2:
    raise SystemExit("Fixture copy Elementor: faltan actualizaciones")
for update in updates:
    if not re.fullmatch(r"[A-Za-z0-9_-]+(?::[A-Za-z0-9_-]+){1,3}", update.get("ref", "")):
        raise SystemExit("Fixture copy Elementor: ref inválida")
    if not re.fullmatch(r"[a-f0-9]{64}", update.get("expected_content_hash", "")):
        raise SystemExit("Fixture copy Elementor: hash de contenido inválido")

combined = "\n".join(path.read_text(encoding="utf-8") for path in (bridge_path, reader_path, validator_path, writer_path))
for required in (
    "inspect-content",
    "update_widget_content",
    "function owe_bridge_section_content_index",
    "function owe_bridge_prepare_content_updates",
    "function owe_bridge_verify_content_isolation",
    "CONTENT_DIFF_OUT_OF_SCOPE",
):
    if required not in combined:
        raise SystemExit(f"Elementor copy incompleto: {required}")

agent = agent_path.read_text(encoding="utf-8")
for required in ("escritor profesional", "PDF", "inspect-content", "update_widget_content"):
    if required not in agent:
        raise SystemExit(f"Agente de contenido incompleto: {required}")
PY

python3 - "$owe_package/.opencode/agents/donna.md" "$owe_package/.opencode/agents/wordpress-content.md" "$owe_package/AGENTS.md" "$owe_package/.opencode/instructions/04-delegation.md" <<'PY'
import pathlib
import sys

donna_path, content_path, agents_path, delegation_path = map(pathlib.Path, sys.argv[1:])
donna = donna_path.read_text(encoding="utf-8")
content = content_path.read_text(encoding="utf-8")
agents = agents_path.read_text(encoding="utf-8")
delegation = delegation_path.read_text(encoding="utf-8")

for required in (
    "No leas `PROJECT_CONTEXT.md` por defecto",
    "lee solo esa sección",
    "delega su ruta exacta",
    "una sola pregunta concreta",
):
    if required not in donna:
        raise SystemExit(f"Donna no aplica contexto selectivo: {required}")

for denied_path in (
    '"PROJECT_CONTEXT.md": deny',
    '"./PROJECT_CONTEXT.md": deny',
    '"*/PROJECT_CONTEXT.md": deny',
):
    if denied_path not in content:
        raise SystemExit(f"wordpress-content puede releer contexto: {denied_path}")

for required in (
    "No exijas un brief fijo",
    "No leas `PROJECT_CONTEXT.md`",
    "lee solo ese archivo",
):
    if required not in content:
        raise SystemExit(f"wordpress-content no aplica contexto mínimo: {required}")

if "confirma que dispones de negocio u oferta, audiencia" in content:
    raise SystemExit("wordpress-content todavía exige el brief rígido anterior")
if "no se carga automáticamente" not in agents:
    raise SystemExit("AGENTS.md no protege la carga bajo demanda")
if "No completar ni exigir audiencia, tono, CTA" not in delegation:
    raise SystemExit("El contrato de delegación todavía permite un brief predeterminado")
PY

python3 - "$owe_root/tests/fixtures/content-batch.json" "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" "$owe_package/.opencode/agents/wordpress-content.md" <<'PY'
import json
import pathlib
import re
import sys

fixture_path, bridge_path, agent_path = map(pathlib.Path, sys.argv[1:])
with fixture_path.open(encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("schema") != "owe-wordpress-content-bridge/1.0":
    raise SystemExit("Fixture batch: schema inválido")
if request.get("operation") != "apply_batch":
    raise SystemExit("Fixture batch: operación inválida")
actions = request.get("actions")
if not isinstance(actions, list) or not actions:
    raise SystemExit("Fixture batch: faltan acciones")

seen = set()
for action in actions:
    key = action.get("key")
    if not isinstance(key, str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,63}", key):
        raise SystemExit("Fixture batch: key inválida")
    if key in seen:
        raise SystemExit("Fixture batch: key duplicada")
    seen.add(key)
    for selectors in action.get("taxonomies", {}).values():
        for selector in selectors:
            if isinstance(selector, str) and selector.startswith("@") and selector[1:] not in seen:
                raise SystemExit("Fixture batch: referencia fuera de orden")

bridge = bridge_path.read_text(encoding="utf-8")
agent = agent_path.read_text(encoding="utf-8")
for required in (
    "function owe_content_apply_batch",
    "case 'apply_batch':",
    "wordpress-content-batch.json",
    "function owe_content_find_terms",
    "if ($command === 'find-term')",
    "wordpress-term-search.json",
):
    if required not in bridge:
        raise SystemExit(f"Bridge batch incompleto: {required}")
if "apply_batch" not in agent or "no inspecciones uno por uno" not in agent:
    raise SystemExit("Agente wordpress-content no prioriza el lote")
if "find-term" not in agent or "varias coincidencias no se resuelven por suposición" not in agent:
    raise SystemExit("Agente wordpress-content no usa la búsqueda segura de términos")
PY

python3 - "$owe_package/.owe/requests/current-content.json" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("schema") != "owe-wordpress-content-bridge/1.0":
    raise SystemExit("Plantilla current-content.json inválida")
if request.get("operation") != "replace_before_use":
    raise SystemExit("La plantilla debe permanecer inerte")
PY

python3 - "$owe_package/.owe/requests/current.json" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    request = json.load(handle)

if request.get("schema") != "owe-elementor-bridge/1.0":
    raise SystemExit("Plantilla current.json inválida")
if request.get("operation") != "replace_before_use":
    raise SystemExit("La plantilla Elementor debe permanecer inerte")
PY

owe_agent_count=0
while IFS= read -r -d '' owe_agent; do
  owe_agent_count=$((owe_agent_count + 1))
  [[ "$(head -n 1 "$owe_agent")" == "---" ]] || { echo "Frontmatter inválido: $owe_agent" >&2; exit 1; }
  grep -Eq '^description:' "$owe_agent" || { echo "Falta description: $owe_agent" >&2; exit 1; }
  grep -Eq '^mode: (primary|subagent)$' "$owe_agent" || { echo "Mode inválido: $owe_agent" >&2; exit 1; }
  owe_model="$(sed -n 's/^model: //p' "$owe_agent" | head -n 1)"
  [[ "$owe_model" == openai/gpt-* ]] || { echo "Proveedor no permitido: $owe_agent" >&2; exit 1; }
done < <(find "$owe_package/.opencode/agents" -type f -name '*.md' -print0)

if [[ "$owe_agent_count" -ne 9 ]]; then
  echo "Cantidad inesperada de agentes: $owe_agent_count" >&2
  exit 1
fi

if grep -R -n -i -E 'backup-status|safety-backup' "$owe_package"; then
  echo "Se encontró una referencia obsoleta." >&2
  exit 1
fi

bash -n "$owe_root/scripts/install.sh"
bash -n "$owe_root/scripts/update.sh"
bash -n "$owe_package/.opencode/scripts/update-project-environment.sh"
bash -n "$owe_package/.opencode/tools/runtime/resolve-php.sh"
bash -n "$owe_package/.opencode/tools/runtime/run-php.sh"
bash -n "$owe_package/.opencode/tools/elementor-bridge/bridge.sh"
bash -n "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.sh"
if command -v php >/dev/null 2>&1; then
  php -l "$owe_package/.opencode/scripts/inspect-wordpress.php" >/dev/null
  php -l "$owe_package/.opencode/tools/elementor-bridge/bridge.php" >/dev/null
  php -l "$owe_package/.opencode/tools/elementor-bridge/checks.php" >/dev/null
  php -l "$owe_package/.opencode/tools/elementor-bridge/reader.php" >/dev/null
  php -l "$owe_package/.opencode/tools/elementor-bridge/validator.php" >/dev/null
  php -l "$owe_package/.opencode/tools/elementor-bridge/writer.php" >/dev/null
  php -l "$owe_package/.opencode/tools/elementor-bridge/templates.php" >/dev/null
  php -l "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" >/dev/null
  php -l "$owe_package/.opencode/tools/wordpress-content-bridge/checks.php" >/dev/null
  php -l "$owe_package/.opencode/tools/wordpress-content-bridge/validator.php" >/dev/null
  php -l "$owe_root/tests/test-elementor-widget-content.php" >/dev/null
  php "$owe_root/tests/test-elementor-widget-content.php"

  owe_term_test_root="$(mktemp -d)"
  trap 'rm -rf -- "$owe_term_test_root"' EXIT
  cp "$owe_root/tests/fixtures/fake-wordpress/wp-load.php" "$owe_term_test_root/wp-load.php"
  mkdir -p "$owe_term_test_root/.owe/runtime"

  owe_term_name_result="$(php "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" \
    "$owe_term_test_root" find-term --taxonomy portfolio_category --name "Color & Balayage")"
  [[ "$owe_term_name_result" == *"count=1 term_ids=7"* ]] \
    || { echo "Búsqueda por nombre falló: $owe_term_name_result" >&2; exit 1; }

  owe_term_slug_result="$(php "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" \
    "$owe_term_test_root" find-term --taxonomy portfolio_category --slug "color-balayage")"
  [[ "$owe_term_slug_result" == *"count=1 term_ids=7"* ]] \
    || { echo "Búsqueda por slug falló: $owe_term_slug_result" >&2; exit 1; }

  owe_term_multiple_result="$(php "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" \
    "$owe_term_test_root" find-term --taxonomy portfolio_category --name "Hair")"
  [[ "$owe_term_multiple_result" == *"count=2 term_ids=8,9"* ]] \
    || { echo "Búsqueda con nombres repetidos falló: $owe_term_multiple_result" >&2; exit 1; }

  owe_term_missing_result="$(php "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" \
    "$owe_term_test_root" find-term --taxonomy portfolio_category --slug "missing")"
  [[ "$owe_term_missing_result" == *"count=0 term_ids=none"* ]] \
    || { echo "Búsqueda sin coincidencias falló: $owe_term_missing_result" >&2; exit 1; }

  if php "$owe_package/.opencode/tools/wordpress-content-bridge/bridge.php" \
    "$owe_term_test_root" find-term --taxonomy portfolio_category \
    --name "Hair" --slug "hair" >/dev/null 2>&1; then
    echo "find-term aceptó dos selectores simultáneos." >&2
    exit 1
  fi

  python3 - "$owe_term_test_root/.owe/runtime/wordpress-term-search.json" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    report = json.load(handle)

if report.get("schema") != "owe-wordpress-term-search/1.0":
    raise SystemExit("Informe find-term: schema inválido")
if report.get("count") != 0 or report.get("matches") != []:
    raise SystemExit("Informe find-term: resultado vacío inválido")
PY
else
  echo "Aviso: PHP CLI no está disponible; se omite php -l."
fi

echo "Validación correcta: OWE Agent System v1.0.0, 9 agentes, activación, atributos, copy y plantillas Elementor, WordPress Content Bridge y proveedor openai."
