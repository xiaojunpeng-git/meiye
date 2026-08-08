#!/usr/bin/env bash
set -euo pipefail

# Produces the only local 8080 inventory integration artifact from the Vue
# source tree. It intentionally requires an explicit release gate because the
# target is a deployed derivative rather than editable source.

if [[ "${INVENTORY_V3_RELEASE_APPROVED:-}" != "1" ]]; then
  echo "Refusing to replace the local integration artifact. Set INVENTORY_V3_RELEASE_APPROVED=1 after migration and acceptance gates pass." >&2
  exit 2
fi

repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
frontend_dir="$repo_dir/前端代码/inventory-vue3"
public_dir="$repo_dir/后端代码/public"
release_root="$public_dir/.inventory-v3-releases"
target="$public_dir/view_inventory_v3"

cd "$frontend_dir"
npm run build

index_file="$frontend_dir/dist/index.html"
if [[ ! -f "$index_file" ]]; then
  echo "Inventory build did not produce dist/index.html." >&2
  exit 1
fi

asset_refs="$(sed -nE 's#.*(assets/index-[A-Za-z0-9]+\.(js|css)).*#\1#p' "$index_file" | sort -u)"
if [[ -z "$asset_refs" ]]; then
  echo "Inventory build index contains no hashed JavaScript or CSS assets." >&2
  exit 1
fi
while IFS= read -r asset; do
  [[ -f "$frontend_dir/dist/$asset" ]] || { echo "Missing built asset: $asset" >&2; exit 1; }
done <<< "$asset_refs"

build_id="$(printf '%s' "$asset_refs" | shasum -a 256 | cut -c1-16)"
release_dir="$release_root/$build_id"
mkdir -p "$release_root"
if [[ ! -d "$release_dir" ]]; then
  staging_dir="$(mktemp -d "$release_root/.staging.XXXXXX")"
  trap 'rm -rf "$staging_dir"' EXIT
  cp -R "$frontend_dir/dist/." "$staging_dir/"
  cat > "$staging_dir/build-manifest.json" <<EOF
{"build_id":"$build_id","source_commit":"$(git -C "$repo_dir" rev-parse --short HEAD)","built_at":"$(date -u +%Y-%m-%dT%H:%M:%SZ)","assets":[$(printf '%s\n' "$asset_refs" | sed 's/^/"/;s/$/"/' | paste -sd, -)]}
EOF
  mv "$staging_dir" "$release_dir"
  trap - EXIT
fi

# A symlink switch is atomic on subsequent releases. The first invocation may
# replace the legacy directory once; callers must only invoke it under the
# explicit local integration release gate above.
next_link="$public_dir/.view_inventory_v3.next"
rm -f "$next_link"
ln -s ".inventory-v3-releases/$build_id" "$next_link"
if [[ -L "$target" ]]; then
  # BSD mv follows a symlink-to-directory unless -h is supplied, which would
  # leave the public target on the prior release instead of replacing it.
  mv -fh "$next_link" "$target"
else
  legacy_dir="$public_dir/.view_inventory_v3.legacy.$(date +%Y%m%d%H%M%S)"
  mv "$target" "$legacy_dir"
  mv "$next_link" "$target"
  echo "Legacy generated artifact retained at $legacy_dir" >&2
fi

echo "INVENTORY_V3_LOCAL_INTEGRATION_BUILD_ID=$build_id"
echo "INVENTORY_V3_LOCAL_INTEGRATION_URL=http://127.0.0.1:8080/view_inventory_v3/build-manifest.json"
