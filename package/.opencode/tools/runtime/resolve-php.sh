#!/usr/bin/env bash
set -euo pipefail

# Prints one tab-separated line:
# PHP_BINARY<TAB>PHP_INI_OR_DASH<TAB>PHP_SHARED_LIBS_OR_DASH.
# Diagnostic details go to stderr so the caller can keep them in a local log.

if [[ $# -ne 1 ]]; then
  exit 64
fi

owe_project_arg="$1"
if [[ ! -d "$owe_project_arg" ]]; then
  exit 66
fi

owe_project_root="$(CDPATH= cd -- "$owe_project_arg" && pwd -P)"

owe_debug() {
  printf '[resolve-php] %s\n' "$*" >&2
}

owe_shared_libs_for() {
  local owe_candidate="$1"
  local owe_platform_root
  local owe_shared_libs

  owe_platform_root="$(dirname -- "$(dirname -- "$owe_candidate")")"
  owe_shared_libs="$owe_platform_root/shared-libs"
  if [[ -d "$owe_shared_libs" ]]; then
    printf '%s\n' "$owe_shared_libs"
  fi
}

owe_verify_php() {
  local owe_candidate="$1"
  local owe_ini="${2:-}"
  local owe_shared_libs="${3:-}"
  local owe_library_path=""

  [[ -x "$owe_candidate" ]] || return 1

  if [[ -n "$owe_shared_libs" ]]; then
    [[ -d "$owe_shared_libs" ]] || return 1
    owe_library_path="$owe_shared_libs${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
  fi

  if [[ -n "$owe_ini" ]]; then
    [[ -f "$owe_ini" ]] || return 1
    if [[ -n "$owe_library_path" ]]; then
      env "LD_LIBRARY_PATH=$owe_library_path" "DYLD_LIBRARY_PATH=$owe_shared_libs${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}" \
        "$owe_candidate" -c "$owe_ini" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1
    else
      "$owe_candidate" -c "$owe_ini" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1
    fi
  else
    if [[ -n "$owe_library_path" ]]; then
      env "LD_LIBRARY_PATH=$owe_library_path" "DYLD_LIBRARY_PATH=$owe_shared_libs${DYLD_LIBRARY_PATH:+:$DYLD_LIBRARY_PATH}" \
        "$owe_candidate" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1
    else
      "$owe_candidate" -r 'exit(PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1
    fi
  fi
}

owe_emit_php() {
  local owe_candidate="$1"
  local owe_ini="${2:-}"
  local owe_shared_libs="${3:-}"
  printf '%s\t%s\t%s\n' "$owe_candidate" "${owe_ini:--}" "${owe_shared_libs:--}"
  exit 0
}

owe_project_is_under() {
  local owe_document_root="$1"
  local owe_canonical_document_root

  [[ -d "$owe_document_root" ]] || return 1
  owe_canonical_document_root="$(CDPATH= cd -- "$owe_document_root" && pwd -P)"
  case "$owe_project_root/" in
    "$owe_canonical_document_root/"*) return 0 ;;
    *) return 1 ;;
  esac
}

owe_try_native_stack() {
  local owe_label="$1"
  local owe_document_root="$2"
  local owe_candidate="$3"
  local owe_ini="${4:-}"
  local owe_shared_libs="${5:-}"

  owe_project_is_under "$owe_document_root" || return 1
  [[ -x "$owe_candidate" ]] || return 1
  [[ -f "$owe_ini" ]] || owe_ini=""
  [[ -d "$owe_shared_libs" ]] || owe_shared_libs="$(owe_shared_libs_for "$owe_candidate")"

  owe_debug "$owe_label detectado para el proyecto; comprobando $owe_candidate."
  if owe_verify_php "$owe_candidate" "$owe_ini" "$owe_shared_libs"; then
    owe_emit_php "$owe_candidate" "$owe_ini" "$owe_shared_libs"
  fi
  owe_debug "$owe_label fue detectado, pero su PHP no pudo ejecutarse: $owe_candidate."
  return 1
}

owe_try_candidate_set() {
  local owe_label="$1"
  local owe_document_root="$2"
  local owe_candidate_pattern="$3"
  local owe_ini_mode="$4"
  local owe_candidate
  local owe_candidate_ini
  local owe_candidate_libs
  local -a owe_valid_candidates=()
  local -a owe_valid_inis=()
  local -a owe_valid_libs=()

  owe_project_is_under "$owe_document_root" || return 1

  while IFS= read -r owe_candidate; do
    [[ -x "$owe_candidate" ]] || continue
    owe_candidate_ini=""
    case "$owe_ini_mode" in
      sibling)
        [[ -f "$(dirname -- "$owe_candidate")/php.ini" ]] && owe_candidate_ini="$(dirname -- "$owe_candidate")/php.ini"
        ;;
      mamp)
        [[ -f "$(dirname -- "$(dirname -- "$owe_candidate")")/conf/php.ini" ]] && owe_candidate_ini="$(dirname -- "$(dirname -- "$owe_candidate")")/conf/php.ini"
        ;;
    esac
    owe_candidate_libs="$(owe_shared_libs_for "$owe_candidate")"
    if owe_verify_php "$owe_candidate" "$owe_candidate_ini" "$owe_candidate_libs"; then
      owe_valid_candidates+=("$owe_candidate")
      owe_valid_inis+=("$owe_candidate_ini")
      owe_valid_libs+=("$owe_candidate_libs")
    fi
  done < <(compgen -G "$owe_candidate_pattern" | sort 2>/dev/null || true)

  if [[ "${#owe_valid_candidates[@]}" -eq 1 ]]; then
    owe_debug "$owe_label detectado con una única versión de PHP utilizable."
    owe_emit_php "${owe_valid_candidates[0]}" "${owe_valid_inis[0]}" "${owe_valid_libs[0]}"
  fi

  if [[ "${#owe_valid_candidates[@]}" -gt 1 ]]; then
    owe_debug "$owe_label tiene varias versiones de PHP. Configure OWE_PHP_BIN para indicar la versión activa; OWE no elegirá una al azar."
  fi
  return 1
}

# Optional explicit override for non-Local environments.
if [[ -n "${OWE_PHP_BIN:-}" ]]; then
  owe_explicit_shared_libs="${OWE_PHP_LIBS:-$(owe_shared_libs_for "$OWE_PHP_BIN")}"
  if owe_verify_php "$OWE_PHP_BIN" "${OWE_PHP_INI:-}" "$owe_explicit_shared_libs"; then
    owe_emit_php "$OWE_PHP_BIN" "${OWE_PHP_INI:-}" "$owe_explicit_shared_libs"
  fi
  exit 69
fi

# Native local stacks are selected only when the WordPress root is inside their
# document root. This prevents an installed stack from hijacking unrelated sites.
owe_try_native_stack "XAMPP/LAMPP Linux" "/opt/lampp/htdocs" "/opt/lampp/bin/php" "/opt/lampp/etc/php.ini" "/opt/lampp/lib" || true
owe_try_native_stack "XAMPP macOS" "/Applications/XAMPP/xamppfiles/htdocs" "/Applications/XAMPP/xamppfiles/bin/php" "/Applications/XAMPP/xamppfiles/etc/php.ini" "/Applications/XAMPP/xamppfiles/lib" || true

for owe_windows_root in /c/xampp /mnt/c/xampp /C/xampp; do
  owe_try_native_stack "XAMPP Windows" "$owe_windows_root/htdocs" "$owe_windows_root/php/php.exe" "$owe_windows_root/php/php.ini" "" || true
done

owe_try_candidate_set "MAMP macOS" "/Applications/MAMP/htdocs" "/Applications/MAMP/bin/php/php*/bin/php" "mamp" || true
for owe_windows_root in /c/MAMP /mnt/c/MAMP /C/MAMP; do
  owe_try_candidate_set "MAMP Windows" "$owe_windows_root/htdocs" "$owe_windows_root/bin/php/php*/php.exe" "sibling" || true
done
for owe_windows_root in /c/wamp64 /mnt/c/wamp64 /C/wamp64 /c/wamp /mnt/c/wamp /C/wamp; do
  owe_try_candidate_set "WampServer" "$owe_windows_root/www" "$owe_windows_root/bin/php/php*/php.exe" "sibling" || true
done
for owe_windows_root in /c/laragon /mnt/c/laragon /C/laragon; do
  owe_try_candidate_set "Laragon" "$owe_windows_root/www" "$owe_windows_root/bin/php/php*/php.exe" "sibling" || true
done

owe_local_bases=()
if [[ -n "${XDG_CONFIG_HOME:-}" ]]; then
  owe_local_bases+=("$XDG_CONFIG_HOME/Local")
fi
if [[ -n "${HOME:-}" ]]; then
  owe_local_bases+=(
    "$HOME/.config/Local"
    "$HOME/Library/Application Support/Local"
  )
fi
if [[ -n "${APPDATA:-}" ]]; then
  owe_local_bases+=("$APPDATA/Local")
fi

# Test/advanced override. Multiple roots are separated with a colon.
if [[ -n "${OWE_LOCAL_BASES:-}" ]]; then
  IFS=':' read -r -a owe_extra_local_bases <<< "$OWE_LOCAL_BASES"
  owe_local_bases=("${owe_extra_local_bases[@]}" "${owe_local_bases[@]}")
fi

owe_platform=""
case "$(uname -s 2>/dev/null || true)" in
  Darwin)
    case "$(uname -m 2>/dev/null || true)" in
      arm64|aarch64) owe_platform="darwin-arm64" ;;
      *) owe_platform="darwin" ;;
    esac
    ;;
  Linux)
    case "$(uname -m 2>/dev/null || true)" in
      arm64|aarch64) owe_platform="linux-arm64" ;;
      *) owe_platform="linux" ;;
    esac
    ;;
esac

# Local keeps sites.json in the user configuration directory, but on Linux its
# service binaries are commonly installed with the application under /opt.
# Treat those as separate sources and combine them when resolving a site.
owe_service_roots=()
if [[ -n "${OWE_LOCAL_SERVICES_ROOTS:-}" ]]; then
  IFS=':' read -r -a owe_extra_service_roots <<< "$OWE_LOCAL_SERVICES_ROOTS"
  owe_service_roots+=("${owe_extra_service_roots[@]}")
fi
owe_service_roots+=(
  "/opt/Local/resources/extraResources/lightning-services"
  "/opt/local/resources/extraResources/lightning-services"
  "/usr/lib/Local/resources/extraResources/lightning-services"
  "/usr/lib/local/resources/extraResources/lightning-services"
  "/Applications/Local.app/Contents/Resources/extraResources/lightning-services"
)
for owe_local_base in "${owe_local_bases[@]}"; do
  owe_service_roots+=("$owe_local_base/lightning-services")
done

owe_find_php_candidates() {
  local owe_search_root="$1"
  local owe_version_pattern="${2:-*}"

  [[ -d "$owe_search_root" ]] || return 0
  find -L "$owe_search_root" -maxdepth 7 \
    -type f \
    -path "*/php-${owe_version_pattern}*/bin/*/bin/php" \
    -print 2>/dev/null | sort
}

# Local records the exact PHP version and runtime id for each site in sites.json.
# Matching by the canonical app/public path prevents selecting another site's PHP.
if command -v python3 >/dev/null 2>&1; then
  for owe_local_base in "${owe_local_bases[@]}"; do
    owe_sites_json="$owe_local_base/sites.json"
    [[ -f "$owe_sites_json" ]] || continue

    owe_site_info="$(python3 - "$owe_sites_json" "$owe_project_root" <<'PY' 2>/dev/null || true
import json
import os
import sys

sites_path, project_root = sys.argv[1:3]
project_root = os.path.realpath(project_root)

with open(sites_path, encoding="utf-8") as handle:
    raw = json.load(handle)

sites = raw.values() if isinstance(raw, dict) else raw
for site in sites:
    if not isinstance(site, dict):
        continue
    site_path = os.path.expanduser(str(site.get("path", "")))
    public_path = os.path.realpath(os.path.join(site_path, "app", "public"))
    if public_path != project_root:
        continue
    services = site.get("services", {})
    php = services.get("php", {}) if isinstance(services, dict) else {}
    version = php.get("version", "") if isinstance(php, dict) else ""
    site_id = site.get("id", "")
    if site_id and version:
        print(f"{site_id}\t{version}")
        raise SystemExit(0)
raise SystemExit(1)
PY
)"

    [[ -n "$owe_site_info" ]] || continue
    IFS=$'\t' read -r owe_site_id owe_php_version <<< "$owe_site_info"
    [[ -n "$owe_site_id" && -n "$owe_php_version" ]] || continue

    owe_php_ini="$owe_local_base/run/$owe_site_id/conf/php/php.ini"
    owe_debug "Sitio encontrado en $owe_sites_json (id=$owe_site_id, PHP=$owe_php_version)."

    if [[ ! -f "$owe_php_ini" ]]; then
      owe_debug "No existe el php.ini activo del sitio: $owe_php_ini. ¿Está iniciado en LocalWP?"
      continue
    fi

    for owe_search_root in "${owe_service_roots[@]}"; do
      [[ -d "$owe_search_root" ]] || continue
      owe_debug "Buscando PHP $owe_php_version en $owe_search_root."
      while IFS= read -r owe_candidate; do
        [[ -n "$owe_candidate" ]] || continue
        owe_candidate_shared_libs="$(owe_shared_libs_for "$owe_candidate")"
        if owe_verify_php "$owe_candidate" "$owe_php_ini" "$owe_candidate_shared_libs"; then
          if [[ -n "$owe_candidate_shared_libs" ]]; then
            owe_debug "Bibliotecas compartidas de LocalWP detectadas en $owe_candidate_shared_libs."
          fi
          owe_emit_php "$owe_candidate" "$owe_php_ini" "$owe_candidate_shared_libs"
        fi
        owe_debug "El candidato no pudo ejecutarse con el php.ini del sitio: $owe_candidate."
      done < <(owe_find_php_candidates "$owe_search_root" "$owe_php_version")
    done
  done
fi

# A globally configured PHP is suitable for non-Local stacks and Site Shells.
if command -v php >/dev/null 2>&1; then
  owe_path_php="$(command -v php)"
  owe_path_shared_libs="$(owe_shared_libs_for "$owe_path_php")"
  if owe_verify_php "$owe_path_php" "" "$owe_path_shared_libs"; then
    owe_emit_php "$owe_path_php" "" "$owe_path_shared_libs"
  fi
fi

# Last resort: accept a bundled Local PHP only when there is exactly one viable
# candidate. Guessing among multiple PHP versions could load WordPress incorrectly.
owe_viable_candidates=()
for owe_search_root in "${owe_service_roots[@]}"; do
  [[ -d "$owe_search_root" ]] || continue
  while IFS= read -r owe_candidate; do
    [[ -n "$owe_candidate" ]] || continue
    owe_candidate_shared_libs="$(owe_shared_libs_for "$owe_candidate")"
    if owe_verify_php "$owe_candidate" "" "$owe_candidate_shared_libs"; then
      owe_already_seen=0
      for owe_seen in "${owe_viable_candidates[@]:-}"; do
        if [[ "$owe_seen" == "$owe_candidate" ]]; then
          owe_already_seen=1
          break
        fi
      done
      if [[ "$owe_already_seen" -eq 0 ]]; then
        owe_viable_candidates+=("$owe_candidate")
      fi
    fi
  done < <(owe_find_php_candidates "$owe_search_root" "*")
done

if [[ "${#owe_viable_candidates[@]}" -eq 1 ]]; then
  owe_only_candidate="${owe_viable_candidates[0]}"
  owe_emit_php "$owe_only_candidate" "" "$(owe_shared_libs_for "$owe_only_candidate")"
fi

if [[ "${#owe_viable_candidates[@]}" -gt 1 ]]; then
  owe_debug "Se encontraron varias versiones de PHP, pero no se pudo asociar una con este sitio; no se elegirá una al azar."
elif [[ "${#owe_viable_candidates[@]}" -eq 0 ]]; then
  owe_debug "No se encontró ningún ejecutable PHP compatible en PATH ni en las ubicaciones conocidas de LocalWP."
fi

exit 69
