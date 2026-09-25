#!/usr/bin/env bash
set -euo pipefail

owe_script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)"
owe_project_root="$(CDPATH= cd -- "$owe_script_dir/../.." && pwd -P)"
owe_php_inspector="$owe_script_dir/inspect-wordpress.php"
owe_php_runner="$owe_project_root/.opencode/tools/runtime/run-php.sh"
owe_output_file="$owe_project_root/PROJECT_ENVIRONMENT.md"
owe_temp_file="$owe_project_root/.PROJECT_ENVIRONMENT.md.tmp"
owe_log_dir="$owe_project_root/.owe/logs"
owe_error_log="$owe_log_dir/wp-environment.log"

mkdir -p "$owe_log_dir"

if [[ ! -f "$owe_project_root/wp-load.php" ]]; then
  echo "BLOCKED: no se encontró wp-load.php en la raíz del proyecto." >&2
  exit 66
fi

if [[ ! -f "$owe_php_runner" ]]; then
  echo "BLOCKED:RUNTIME_RUNNER_NOT_FOUND" >&2
  exit 69
fi

owe_cleanup() {
  rm -f -- "$owe_temp_file"
}
trap owe_cleanup EXIT

if ! bash "$owe_php_runner" "$owe_project_root" "$owe_php_inspector" > "$owe_temp_file" 2>> "$owe_error_log"; then
  if grep -Fq 'No se encontró un runtime PHP compatible' "$owe_error_log" 2>/dev/null; then
    echo "BLOCKED:PHP_RUNTIME_NOT_FOUND" >&2
    exit 69
  fi
  echo "BLOCKED:WORDPRESS_LOAD_FAILED" >&2
  exit 70
fi
rm -f -- "$owe_error_log"

if [[ ! -s "$owe_temp_file" ]]; then
  echo "BLOCKED:INSPECTOR_EMPTY_RESULT" >&2
  exit 70
fi

if command -v git >/dev/null 2>&1 && git -C "$owe_project_root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  owe_git_branch="$(git -C "$owe_project_root" branch --show-current 2>/dev/null || true)"
  owe_git_commit="$(git -C "$owe_project_root" rev-parse --short HEAD 2>/dev/null || true)"
  owe_git_changes="$(git -C "$owe_project_root" status --short 2>/dev/null | wc -l | tr -d ' ')"
  {
    printf '\n## Git\n\n'
    printf -- '- Repository detected: Yes\n'
    printf -- '- Branch: %s\n' "${owe_git_branch:-DETACHED}"
    printf -- '- Commit: %s\n' "${owe_git_commit:-UNKNOWN}"
    printf -- '- Changed or untracked entries: %s\n' "${owe_git_changes:-0}"
  } >> "$owe_temp_file"
else
  {
    printf '\n## Git\n\n'
    printf -- '- Repository detected: No\n'
  } >> "$owe_temp_file"
fi

mv -f -- "$owe_temp_file" "$owe_output_file"
trap - EXIT

echo "UPDATED: PROJECT_ENVIRONMENT.md"
