#!/usr/bin/env bash
set -euo pipefail

# Builds the current locally served Vue applications from the single source tree,
# then switches their 8080 static artifacts to one recorded integration version.
# A caller must explicitly open this local release gate because public/ contains
# generated deployment derivatives rather than editable source.

if [[ "${LOCAL_8080_INTEGRATION_RELEASE_APPROVED:-}" != "1" ]]; then
  echo "Refusing to replace local 8080 integration artifacts. Set LOCAL_8080_INTEGRATION_RELEASE_APPROVED=1 after the required self-checks pass." >&2
  exit 2
fi

repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
public_dir="${LOCAL_8080_INTEGRATION_PUBLIC_DIR:-$repo_dir/后端代码/public}"
release_root="$public_dir/.local-8080-integration-releases"
node_home="${MOHE_NODE_HOME:-$HOME/.local/node-v16.20.2-darwin-arm64}"
export PATH="$node_home/bin:$PATH"

require_build_output() {
  local directory="$1"
  local index_file="$directory/index.html"
  [[ -f "$index_file" ]] || { echo "Missing build index: $index_file" >&2; exit 1; }
  local assets
  assets="$(sed -nE 's#.*(assets/index-[A-Za-z0-9]+\.(js|css)).*#\1#p' "$index_file" | sort -u)"
  [[ -n "$assets" ]] || { echo "No hashed assets in $index_file" >&2; exit 1; }
  while IFS= read -r asset; do
    [[ -f "$directory/$asset" ]] || { echo "Missing built asset: $directory/$asset" >&2; exit 1; }
  done <<< "$assets"
}

source_snapshot() {
  local snapshot_file="$1"
  local source_dir
  local source_dirs=(cashier-v3 inventory-vue3 admin)

  : > "$snapshot_file"
  for source_dir in "${source_dirs[@]}"; do
    find "$repo_dir/前端代码/$source_dir" \
      -type d \( -name node_modules -o -name dist -o -name .git \) -prune -o \
      -type f -print | LC_ALL=C sort | while IFS= read -r source_file; do
        shasum -a 256 "$source_file"
      done
  done >> "$snapshot_file"
}

switch_target() {
  local target_name="$1"
  local release_name="$2"
  local target="$public_dir/$target_name"
  local next_link="$public_dir/.${target_name}.next"

  rm -f "$next_link"
  ln -s ".local-8080-integration-releases/$build_id/$release_name" "$next_link"
  if [[ -L "$target" ]]; then
    mv -fh "$next_link" "$target"
  elif [[ -e "$target" ]]; then
    local legacy="$public_dir/.${target_name}.legacy.$(date +%Y%m%d%H%M%S)"
    mv "$target" "$legacy"
    mv "$next_link" "$target"
    echo "Retained previous generated artifact at $legacy" >&2
  else
    mv "$next_link" "$target"
  fi
}

command -v node >/dev/null 2>&1 || { echo "Node is unavailable: $node_home" >&2; exit 1; }

staging_dir="$(mktemp -d "$public_dir/.local-8080-integration-staging.XXXXXX")"
trap 'rm -rf "$staging_dir"' EXIT

build_vite_app() {
  local app_name="$1"
  local app_dir="$repo_dir/前端代码/$app_name"
  (
    cd "$app_dir"
    npm run build
  )
  require_build_output "$app_dir/dist"
}

build_vite_app cashier-v3
build_vite_app inventory-vue3

admin_build_mode="rebuilt"
if [[ "${LOCAL_8080_INTEGRATION_REUSE_ADMIN_DIST:-}" == "1" ]]; then
  # A previously verified platform build may be reused only when a local build
  # runner cannot remain attached long enough to finish webpack emission.
  admin_build_mode="verified-existing-dist"
else
  (
    cd "$repo_dir/前端代码/admin"
    NODE_OPTIONS="${NODE_OPTIONS:---max_old_space_size=3072}" npm run build
  )
fi
[[ -d "$repo_dir/前端代码/admin/dist/view_admin" ]] || { echo "Missing admin build directory." >&2; exit 1; }
[[ -f "$repo_dir/前端代码/admin/dist/system.html" ]] || { echo "Missing admin entry file." >&2; exit 1; }

if [[ "${LOCAL_8080_INTEGRATION_INCLUDE_STORE:-0}" == "1" ]]; then
  echo "旧门店端已迁移到 美容源码/旧端口/18082/，当前 8080 集成构建禁止重新接入旧源码。" >&2
  exit 2
fi

mkdir -p "$staging_dir/cashier-v3" "$staging_dir/view_cashier_v3" "$staging_dir/view_inventory_v3" "$staging_dir/view_admin"
cp -R "$repo_dir/前端代码/cashier-v3/dist/." "$staging_dir/cashier-v3/"
cp -R "$repo_dir/前端代码/cashier-v3/dist/." "$staging_dir/view_cashier_v3/"
cp -R "$repo_dir/前端代码/inventory-vue3/dist/." "$staging_dir/view_inventory_v3/"
cp -R "$repo_dir/前端代码/admin/dist/view_admin/." "$staging_dir/view_admin/"
cp "$repo_dir/前端代码/admin/dist/system.html" "$staging_dir/system.html"

cashier_assets="$(sed -nE 's#.*(assets/index-[A-Za-z0-9]+\.(js|css)).*#\1#p' "$staging_dir/view_cashier_v3/index.html" | sort -u | paste -sd, -)"
inventory_assets="$(sed -nE 's#.*(assets/index-[A-Za-z0-9]+\.(js|css)).*#\1#p' "$staging_dir/view_inventory_v3/index.html" | sort -u | paste -sd, -)"
admin_assets="$(find "$staging_dir/view_admin" -maxdepth 2 -type f \( -name 'app.*.js' -o -name 'app.*.css' \) -print | sort | xargs shasum -a 256 | shasum -a 256 | awk '{print $1}')"
admin_entry_digest="$(shasum -a 256 "$staging_dir/system.html" | awk '{print $1}')"
source_snapshot_file="$staging_dir/source-snapshot.sha256"
source_snapshot "$source_snapshot_file"
source_snapshot_digest="$(shasum -a 256 "$source_snapshot_file" | awk '{print $1}')"
builder_script_digest="$(shasum -a 256 "$repo_dir/scripts/build-local-8080-integration.sh" | awk '{print $1}')"
overlay_digest="${LOCAL_8080_INTEGRATION_OVERLAY_SHA256:-}"
build_id="$(printf '%s\n%s\n%s\n%s\n%s\n' "$cashier_assets" "$inventory_assets" "$admin_assets" "$admin_entry_digest" "$source_snapshot_digest" | shasum -a 256 | cut -c1-16)"
release_dir="$release_root/$build_id"

mkdir -p "$release_root"
if [[ ! -d "$release_dir" ]]; then
  mkdir -p "$release_dir"
  cp -R "$staging_dir/." "$release_dir/"
  cat > "$release_dir/build-manifest.json" <<EOF
{"build_id":"$build_id","source_commit":"$(git -C "$repo_dir" rev-parse HEAD)","overlay_sha256":"$overlay_digest","source_snapshot_digest":"$source_snapshot_digest","builder_script_digest":"$builder_script_digest","built_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","cashier_assets":"$cashier_assets","inventory_assets":"$inventory_assets","admin_asset_digest":"$admin_assets","admin_entry_digest":"$admin_entry_digest","includes_store":false,"admin_build_mode":"$admin_build_mode"}
EOF
fi

switch_target cashier-v3 cashier-v3
switch_target view_cashier_v3 view_cashier_v3
switch_target view_inventory_v3 view_inventory_v3
switch_target view_admin view_admin
switch_target system.html system.html
echo "LOCAL_8080_INTEGRATION_BUILD_ID=$build_id"
echo "LOCAL_8080_INTEGRATION_MANIFEST=http://127.0.0.1:8080/.local-8080-integration-releases/$build_id/build-manifest.json"
echo "LOCAL_8080_INTEGRATION_CASHIER=http://127.0.0.1:8080/cashier-v3/?build=$build_id#/cashier"
echo "LOCAL_8080_INTEGRATION_PLATFORM=http://127.0.0.1:8080/admin/product/product_list?build=$build_id"
echo "LOCAL_8080_INTEGRATION_INVENTORY=http://127.0.0.1:8080/view_inventory_v3/?build=$build_id"
