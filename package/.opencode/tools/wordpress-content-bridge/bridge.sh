#!/usr/bin/env bash
set -euo pipefail

owe_bridge_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)"
owe_project_root="$(CDPATH= cd -- "$owe_bridge_dir/../../.." && pwd -P)"
owe_php_runner="$owe_project_root/.opencode/tools/runtime/run-php.sh"
owe_bridge_php="$owe_bridge_dir/bridge.php"

if [[ ! -f "$owe_project_root/wp-load.php" ]]; then
  echo "BLOCKED:WORDPRESS_NOT_FOUND" >&2
  exit 66
fi

if [[ ! -f "$owe_php_runner" ]]; then
  echo "BLOCKED:RUNTIME_RUNNER_NOT_FOUND" >&2
  exit 69
fi

exec bash "$owe_php_runner" "$owe_project_root" "$owe_bridge_php" "$@"
