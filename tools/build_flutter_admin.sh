#!/usr/bin/env bash
#
# Build the Invoice Ninja Flutter admin web app and deploy it into this
# repository's public/ directory.
#
# This backend repository ships only the *compiled* Flutter admin bundle under
# public/ (main.dart.js, main.foss.dart.js, assets/, canvaskit/, ...). It does
# NOT contain the Flutter source. The admin portal is a separate project.
#
# The bundle in this repository reports invoiceninja_flutter 5.0.194 in
# public/version.json, which matches the admin-portal project:
#
#     https://github.com/invoiceninja/admin-portal   (default, branch master)
#
# The newer rebuild at https://github.com/invoiceninja/flutter (branch main) is
# a different client. Pass --repo and --ref to target it instead.
#
# This script clones (or reuses) that project, builds the web target, and copies
# the output into public/, then creates the flavor-renamed entrypoints that
# app/Http/Controllers/BaseController::setBuild() serves:
#
#     main.foss.dart.js   self-host default
#     main.wasm.dart.js   WebAssembly build
#     main.last.dart.js / main.next.dart.js / main.profile.dart.js
#
# Requirements: flutter and dart on PATH (plus git when the source must be
# cloned). Nothing here is installed automatically.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

source_dir="${IN_FLUTTER_SOURCE:-$repo_root/../admin-portal}"
git_ref="master"
git_url="https://github.com/invoiceninja/admin-portal.git"
flavors=()
build_wasm=0
resources_flag=()
skip_codegen=0
skip_analyze=0
public_dir="$repo_root/public"

usage() {
    cat <<'EOF'
Usage: tools/build_flutter_admin.sh [options]

  -s, --source DIR    Flutter source checkout
                      (default: $IN_FLUTTER_SOURCE, else <repo>/../admin-portal)
  -r, --ref REF       Git ref to clone when the source is missing (default: master)
      --repo URL      Git URL to clone (default: https://github.com/invoiceninja/admin-portal.git)
  -f, --flavor NAME   Flavor alias for the JS entrypoint (default: foss).
                      Repeat the flag to create several aliases.
  -w, --wasm          Also build the WebAssembly target (flutter build web --wasm)
      --no-web-resources-cdn
                      Bundle CanvasKit into build/web/ instead of the gstatic CDN
      --skip-codegen  Skip `dart run build_runner build`
      --skip-analyze  Skip `flutter analyze`
      --skip-version-check
                      Do not enforce the version pinned in .flutter-version
  -p, --public DIR    Deploy target (default: <repo>/public)
  -h, --help          Show this help
EOF
}

skip_version_check=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -s|--source) source_dir="$2"; shift 2 ;;
        -r|--ref) git_ref="$2"; shift 2 ;;
        --repo) git_url="$2"; shift 2 ;;
        -f|--flavor) flavors+=("$2"); shift 2 ;;
        -w|--wasm) build_wasm=1; shift ;;
        --no-web-resources-cdn) resources_flag+=(--no-web-resources-cdn); shift ;;
        --skip-codegen) skip_codegen=1; shift ;;
        --skip-analyze) skip_analyze=1; shift ;;
        --skip-version-check) skip_version_check=1; shift ;;
        -p|--public) public_dir="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage; exit 2 ;;
    esac
done

if [[ ${#flavors[@]} -eq 0 ]]; then
    flavors=(foss)
fi

command -v flutter >/dev/null 2>&1 || { echo "ERROR: flutter is not on PATH." >&2; exit 1; }
command -v dart >/dev/null 2>&1 || { echo "ERROR: dart is not on PATH." >&2; exit 1; }

if [[ ! -f "$source_dir/pubspec.yaml" ]]; then
    command -v git >/dev/null 2>&1 || { echo "ERROR: git is required to clone the Flutter source." >&2; exit 1; }
    echo "==> Cloning $git_url ($git_ref) into $source_dir"
    git clone --depth 1 --branch "$git_ref" "$git_url" "$source_dir"
fi

echo "==> Flutter source: $source_dir"
cd "$source_dir"

# admin-portal pins its SDK in .flutter-version. Building v5 with a much newer
# Flutter fails on removed APIs (final IconData, super_editor IME changes).
if [[ -f "$source_dir/.flutter-version" && "$skip_version_check" -eq 0 ]]; then
    pinned="$(tr -d '[:space:]' < "$source_dir/.flutter-version")"
    active="$(flutter --version --machine | grep -o '"frameworkVersion":"[^"]*"' | head -n1 | sed 's/.*:"//; s/"$//')"
    if [[ -n "$pinned" && "$active" != "$pinned" ]]; then
        echo "ERROR: Flutter version mismatch: this source requires $pinned but the active Flutter is $active." >&2
        echo "Switch SDKs (for example 'fvm use $pinned'), or pass --skip-version-check to override." >&2
        exit 1
    fi
    echo "    Flutter $active matches .flutter-version"
fi

# Prepare the app the way the upstream pipeline does.
if [[ -f "$source_dir/lib/.env.dart.example" && ! -f "$source_dir/lib/.env.dart" ]]; then
    cp "$source_dir/lib/.env.dart.example" "$source_dir/lib/.env.dart"
    echo "    created lib/.env.dart from the example"
fi

if printf '%s\n' "${flavors[@]}" | grep -qx 'foss'; then
    apply_foss_copy() {
        local from="$source_dir/$1" to="$source_dir/$2"
        [[ -f "$from" ]] && cp -f "$from" "$to"
    }
    apply_foss_copy lib/utils/oauth.dart.foss lib/utils/oauth.dart
    apply_foss_copy lib/utils/app_review.dart.foss lib/utils/app_review.dart
    apply_foss_copy lib/ui/app/upgrade_dialog.dart.foss lib/ui/app/upgrade_dialog.dart
    apply_foss_copy lib/ui/app/pinput.dart.foss lib/ui/app/pinput.dart
    apply_foss_copy pubspec.foss.yaml pubspec.yaml
    echo "    applied FOSS sources and pubspec"
fi

echo "==> flutter pub get"
flutter pub get

if [[ "$skip_codegen" -eq 0 ]]; then
    echo "==> dart run build_runner build --delete-conflicting-outputs"
    if ! dart run build_runner build --delete-conflicting-outputs; then
        generated="$(find "$source_dir/lib" -name '*.g.dart' 2>/dev/null | wc -l | tr -d ' ')"
        cat >&2 <<EOF
build_runner failed.

This is an upstream dependency conflict in the admin-portal project: the pinned
source_gen version is not compatible with the analyzer version that the current
Flutter SDK resolves, so the build script cannot compile.

The generated sources are committed to the repository ($generated .g.dart files
found), so codegen is optional for a normal build. Re-run with codegen skipped:

    tools/build_flutter_admin.sh --skip-codegen --skip-analyze

Only run codegen if you changed a built_value model or another source-gen input.
In that case fix the admin-portal dependency graph first (upgrade
built_value_generator and source_gen, or override analyzer to a compatible 7.x).
EOF
        exit 1
    fi
fi

if [[ "$skip_analyze" -eq 0 ]]; then
    echo "==> flutter analyze"
    flutter analyze
fi

if [[ "$build_wasm" -eq 1 ]]; then
    echo "==> flutter build web --release --wasm"
    flutter build web --release --wasm "${resources_flag[@]+"${resources_flag[@]}"}"
else
    echo "==> flutter build web --release"
    flutter build web --release "${resources_flag[@]+"${resources_flag[@]}"}"
fi

web_dir="$source_dir/build/web"

[[ -d "$web_dir" ]] || { echo "ERROR: build output not found: $web_dir" >&2; exit 1; }
[[ -f "$web_dir/main.dart.js" ]] || { echo "ERROR: missing $web_dir/main.dart.js" >&2; exit 1; }
[[ -d "$public_dir" ]] || { echo "ERROR: deploy target not found: $public_dir" >&2; exit 1; }

stamp="$(date -u +%Y%m%d-%H%M%S)"
backup_dir="$repo_root/storage/app/flutter-admin-backup-$stamp"
mkdir -p "$backup_dir"

echo "==> Backing up existing Flutter entrypoints into $backup_dir"
shopt -s nullglob
for f in "$public_dir"/main.*.dart.js "$public_dir"/version.json; do
    if [[ -f "$f" ]]; then
        cp -p "$f" "$backup_dir/"
    fi
done
shopt -u nullglob

echo "==> Deploying build/web/ into $public_dir (index.html excluded)"
if command -v rsync >/dev/null 2>&1; then
    rsync -a --exclude 'index.html' "$web_dir/" "$public_dir/"
else
    (cd "$web_dir" && find . -type f ! -name 'index.html' -print0 |
        while IFS= read -r -d '' f; do
            mkdir -p "$public_dir/$(dirname "$f")"
            cp -p "$f" "$public_dir/$f"
        done)
fi

echo "==> Creating flavor entrypoints from main.dart.js"
for flavor in "${flavors[@]}"; do
    cp -p "$web_dir/main.dart.js" "$public_dir/main.$flavor.dart.js"
    echo "    main.$flavor.dart.js"
done

if [[ "$build_wasm" -eq 1 ]]; then
    echo "==> WebAssembly outputs produced by this SDK:"
    for f in "$web_dir"/main.dart.*; do
        if [[ -e "$f" ]]; then
            echo "    $(basename "$f")"
        fi
    done
    echo "    NOTE: the backend serves main.wasm.dart.js (legacy name). If this"
    echo "          SDK emits main.dart.wasm / main.dart.mjs instead, wire that"
    echo "          entrypoint through BaseController::setBuild(), or keep using"
    echo "          the JS flavor above."
fi

echo "==> Done."
echo "    Backend: $repo_root"
echo "    Public:  $public_dir"
echo "    Backups: $backup_dir"
echo "    Hard-refresh the admin UI (or append ?build=foss) to load the new bundle."
