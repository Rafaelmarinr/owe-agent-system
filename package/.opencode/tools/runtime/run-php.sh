#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  exit 64
fi

owe_project_arg="$1"
owe_php_script_arg="$2"
shift 2

[[ -d "$owe_project_arg" ]] || exit 66
[[ -f "$owe_php_script_arg" ]] || exit 66

owe_runner_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)"
owe_calling_dir="$(pwd -P)"
owe_project_root="$(CDPATH= cd -- "$owe_project_arg" && pwd -P)"
owe_php_script="$(CDPATH= cd -- "$(dirname -- "$owe_php_script_arg")" && pwd -P)/$(basename -- "$owe_php_script_arg")"
owe_php_resolver="$owe_runner_dir/resolve-php.sh"

owe_debug() {
  printf '[run-php] %s\n' "$*" >&2
}

owe_find_marker_root() {
  local owe_start="$1"
  local owe_marker="$2"
  local owe_current="$owe_start"
  local owe_parent
  local owe_depth=0

  while [[ "$owe_depth" -lt 4 ]]; do
    if [[ -f "$owe_current/$owe_marker" ]]; then
      printf '%s\n' "$owe_current"
      return 0
    fi
    owe_parent="$(dirname -- "$owe_current")"
    [[ "$owe_parent" != "$owe_current" ]] || break
    owe_current="$owe_parent"
    owe_depth=$((owe_depth + 1))
  done
  return 1
}

owe_translate_for_container() {
  local owe_runtime_root="$1"
  local owe_value="$2"
  local owe_normalized_value="$owe_value"

  if [[ "$owe_value" != /* && -e "$owe_calling_dir/$owe_value" ]]; then
    if [[ -d "$owe_calling_dir/$owe_value" ]]; then
      owe_normalized_value="$(CDPATH= cd -- "$owe_calling_dir/$owe_value" && pwd -P)"
    else
      owe_normalized_value="$(CDPATH= cd -- "$(dirname -- "$owe_calling_dir/$owe_value")" && pwd -P)/$(basename -- "$owe_value")"
    fi
  fi

  case "$owe_normalized_value" in
    "$owe_runtime_root") printf '.\n' ;;
    "$owe_runtime_root"/*) printf './%s\n' "${owe_normalized_value#"$owe_runtime_root"/}" ;;
    *) printf '%s\n' "$owe_normalized_value" ;;
  esac
}

owe_try_direct_runtime() {
  local owe_runtime
  local owe_resolver_status
  local owe_php_bin
  local owe_php_ini
  local owe_php_shared_libs
  local -a owe_php_args=()

  set +e
  owe_runtime="$(bash "$owe_php_resolver" "$owe_project_root")"
  owe_resolver_status=$?
  set -e
  if [[ "$owe_resolver_status" -eq 0 && -n "$owe_runtime" ]]; then
    IFS=$'\t' read -r owe_php_bin owe_php_ini owe_php_shared_libs <<< "$owe_runtime"
    [[ "${owe_php_ini:-}" == "-" ]] && owe_php_ini=""
    [[ "${owe_php_shared_libs:-}" == "-" ]] && owe_php_shared_libs=""

    [[ -n "${owe_php_ini:-}" ]] && owe_php_args=(-c "$owe_php_ini")
    if [[ -n "${owe_php_shared_libs:-}" ]]; then
      exec env \
        "LD_LIBRARY_PATH=$owe_php_shared_libs${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}" \
        "DYLD_LIBRARY_PATH=$owe_php_shared_libs${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}" \
        "$owe_php_bin" "${owe_php_args[@]}" "$owe_php_script" "$owe_project_root" "$@"
    fi
    exec "$owe_php_bin" "${owe_php_args[@]}" "$owe_php_script" "$owe_project_root" "$@"
  fi
  return 1
}

# An explicit override always has priority over automatic container detection.
if [[ -n "${OWE_PHP_BIN:-}" ]]; then
  owe_try_direct_runtime "$@" || exit 69
fi

# Containerized runtimes have priority over host PHP. Paths inside the project are translated to paths
# relative to the container mount; commands are passed as arrays, never eval'd.
if command -v ddev >/dev/null 2>&1; then
  owe_ddev_root="$(owe_find_marker_root "$owe_project_root" ".ddev/config.yaml" || true)"
  if [[ -n "$owe_ddev_root" ]]; then
    owe_container_script="$(owe_translate_for_container "$owe_ddev_root" "$owe_php_script")"
    owe_container_project="$(owe_translate_for_container "$owe_ddev_root" "$owe_project_root")"
    owe_container_args=()
    for owe_arg in "$@"; do
      owe_container_args+=("$(owe_translate_for_container "$owe_ddev_root" "$owe_arg")")
    done
    owe_debug "DDEV detectado; ejecutando PHP dentro del contenedor web."
    cd -- "$owe_ddev_root"
    if ! ddev exec --dir /var/www/html php -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then
      owe_debug "DDEV está configurado, pero el contenedor web o su PHP no están disponibles."
      owe_debug "No se encontró un runtime PHP compatible."
      exit 69
    fi
    exec ddev exec --dir /var/www/html php "$owe_container_script" "$owe_container_project" "${owe_container_args[@]}"
  fi
fi

if command -v lando >/dev/null 2>&1; then
  owe_lando_root="$(owe_find_marker_root "$owe_project_root" ".lando.yml" || true)"
  if [[ -n "$owe_lando_root" ]]; then
    owe_container_script="$(owe_translate_for_container "$owe_lando_root" "$owe_php_script")"
    owe_container_project="$(owe_translate_for_container "$owe_lando_root" "$owe_project_root")"
    owe_container_args=()
    for owe_arg in "$@"; do
      owe_container_args+=("$(owe_translate_for_container "$owe_lando_root" "$owe_arg")")
    done
    cd -- "$owe_lando_root"
    if ! lando php -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then
      owe_debug "Lando está configurado, pero el comando de tooling 'lando php' no está disponible."
      owe_debug "No se encontró un runtime PHP compatible."
      exit 69
    fi
    owe_debug "Lando detectado; ejecutando PHP dentro del servicio de la aplicación."
    exec lando php "$owe_container_script" "$owe_container_project" "${owe_container_args[@]}"
  fi
fi

# Direct native runtime: LocalWP, XAMPP, MAMP, WampServer, Laragon or PATH.
owe_try_direct_runtime "$@" || true

owe_debug "No se encontró un runtime PHP compatible. Use OWE_PHP_BIN, OWE_PHP_INI y OWE_PHP_LIBS para una instalación personalizada."
exit 69
