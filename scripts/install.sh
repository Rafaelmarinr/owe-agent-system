#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
  echo "Uso: bash scripts/install.sh /ruta/al/proyecto [--dry-run]" >&2
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
  echo "No se permite instalar en la raíz del sistema." >&2
  exit 77
fi

if [[ ! -f "$owe_target_dir/wp-load.php" && "$owe_mode" != "--dry-run" ]]; then
  echo "Instalación cancelada: la carpeta elegida no contiene wp-load.php." >&2
  echo "Use la raíz de WordPress, normalmente app/public; no use wp-content ni app." >&2
  exit 66
fi

owe_conflict=0
for owe_path in AGENTS.md PROJECT_CONTEXT.md PROJECT_PROGRESS.md opencode.json .opencode .owe-agent-system-version .opencode-agent-system-version; do
  if [[ -e "$owe_target_dir/$owe_path" ]]; then
    echo "Conflicto: $owe_target_dir/$owe_path" >&2
    owe_conflict=1
  fi
done

if [[ "$owe_conflict" -ne 0 ]]; then
  echo "Instalación cancelada. Si es una versión anterior, use scripts/update.sh." >&2
  exit 73
fi

if [[ "$owe_mode" == "--dry-run" ]]; then
  echo "Validación correcta. Se instalaría OWE Agent System en: $owe_target_dir"
  exit 0
fi

cp -a "$owe_source_dir/." "$owe_target_dir/"
echo "OWE Agent System v1.0.0 instalado en: $owe_target_dir"
echo "PROJECT_CONTEXT.md es opcional; complete solo información estable y reutilizable."
echo "Abra OpenCode desde esta raíz (app/public)."
echo "No ejecute /init: OWE ya incluye AGENTS.md."
