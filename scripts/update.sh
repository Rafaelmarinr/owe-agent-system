#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Uso: bash scripts/update.sh /ruta/al/proyecto [--dry-run]" >&2
  exit 64
fi

owe_target_arg="$1"
owe_mode="${2:-}"

if [[ "$owe_mode" != "" && "$owe_mode" != "--dry-run" ]]; then
  echo "Segundo argumento no reconocido: $owe_mode" >&2
  exit 64
fi

if [[ ! -d "$owe_target_arg" ]]; then
  echo "La carpeta de destino no existe: $owe_target_arg" >&2
  exit 66
fi

owe_script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)"
owe_source_dir="$(CDPATH= cd -- "$owe_script_dir/../package" && pwd -P)"
owe_target_dir="$(CDPATH= cd -- "$owe_target_arg" && pwd -P)"

if [[ "$owe_target_dir" == "/" ]]; then
  echo "No se permite actualizar la raíz del sistema." >&2
  exit 77
fi

if [[ ! -f "$owe_target_dir/wp-load.php" ]]; then
  echo "Actualización cancelada: la carpeta elegida no contiene wp-load.php." >&2
  echo "Use la raíz de WordPress, normalmente app/public; no use wp-content ni app." >&2
  exit 66
fi

if [[ ! -f "$owe_target_dir/.owe-agent-system-version" && ! -f "$owe_target_dir/.opencode-agent-system-version" ]]; then
  echo "No se encontró una instalación reconocida de OWE o del paquete anterior." >&2
  exit 66
fi

if [[ "$owe_mode" == "--dry-run" ]]; then
  echo "Validación correcta. Se reemplazarían únicamente los archivos administrados por OWE."
  echo "Se conservarían PROJECT_CONTEXT.md, PROJECT_PROGRESS.md, PROJECT_ENVIRONMENT.md y referencias/."
  exit 0
fi

mkdir -p "$owe_target_dir/.opencode/agents" "$owe_target_dir/.opencode/commands" "$owe_target_dir/.opencode/instructions" "$owe_target_dir/.opencode/scripts" "$owe_target_dir/.opencode/tools" "$owe_target_dir/.owe/requests" "$owe_target_dir/referencias"

owe_legacy_agents=(
  documentation.md elementor-builder-advanced.md elementor-builder.md emergency-reviewer.md
  project-auditor.md responsive-accessibility.md technical-reviewer.md visual-reference-advanced.md
  wp-orchestrator.md wp-planner.md safety-backup.md
)
owe_legacy_commands=(wp-audit.md wp-execute.md wp-plan.md wp-verify.md)
owe_legacy_instructions=(authorization-policy.md handoff-contracts.md model-routing.md validation-standards.md wordpress-elementor-standards.md)

for owe_file in "${owe_legacy_agents[@]}"; do
  rm -f -- "$owe_target_dir/.opencode/agents/$owe_file"
done
for owe_file in "${owe_legacy_commands[@]}"; do
  rm -f -- "$owe_target_dir/.opencode/commands/$owe_file"
done
for owe_file in "${owe_legacy_instructions[@]}"; do
  rm -f -- "$owe_target_dir/.opencode/instructions/$owe_file"
done

cp -a "$owe_source_dir/AGENTS.md" "$owe_target_dir/AGENTS.md"
cp -a "$owe_source_dir/opencode.json" "$owe_target_dir/opencode.json"
cp -a "$owe_source_dir/.opencode/agents/." "$owe_target_dir/.opencode/agents/"
cp -a "$owe_source_dir/.opencode/commands/." "$owe_target_dir/.opencode/commands/"
cp -a "$owe_source_dir/.opencode/instructions/." "$owe_target_dir/.opencode/instructions/"
cp -a "$owe_source_dir/.opencode/scripts/." "$owe_target_dir/.opencode/scripts/"
cp -a "$owe_source_dir/.opencode/tools/." "$owe_target_dir/.opencode/tools/"
if [[ ! -f "$owe_target_dir/.owe/requests/README.md" ]]; then
  cp -a "$owe_source_dir/.owe/requests/README.md" "$owe_target_dir/.owe/requests/README.md"
fi
if [[ ! -f "$owe_target_dir/.owe/requests/current-content.json" ]]; then
  cp -a "$owe_source_dir/.owe/requests/current-content.json" "$owe_target_dir/.owe/requests/current-content.json"
fi
if [[ ! -f "$owe_target_dir/.owe/requests/current.json" ]]; then
  cp -a "$owe_source_dir/.owe/requests/current.json" "$owe_target_dir/.owe/requests/current.json"
fi
cp -a "$owe_source_dir/.owe-agent-system-version" "$owe_target_dir/.owe-agent-system-version"
rm -f -- "$owe_target_dir/.opencode-agent-system-version"

if [[ ! -f "$owe_target_dir/PROJECT_CONTEXT.md" ]]; then
  cp -a "$owe_source_dir/PROJECT_CONTEXT.md" "$owe_target_dir/PROJECT_CONTEXT.md"
fi
if [[ ! -f "$owe_target_dir/PROJECT_PROGRESS.md" ]]; then
  cp -a "$owe_source_dir/PROJECT_PROGRESS.md" "$owe_target_dir/PROJECT_PROGRESS.md"
fi
if [[ ! -f "$owe_target_dir/referencias/README.md" ]]; then
  cp -a "$owe_source_dir/referencias/README.md" "$owe_target_dir/referencias/README.md"
fi

echo "OWE Agent System actualizado en: $owe_target_dir"
echo "Cierre y vuelva a abrir OpenCode para cargar v1.0.0."
echo "Abra OpenCode desde esta raíz (app/public) y no ejecute /init."
