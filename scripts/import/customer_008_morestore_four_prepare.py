#!/usr/bin/env python3
"""Freeze four operating legacy stores into a private, allowlisted 008 import plan.

The old store category IDs are source-only. The plan expresses their approved
organization paths by name and deliberately excludes payment, ERP, media, and
geographic reference IDs from the destination profile.
"""

import hashlib
import json
import os
import re
import sys
from datetime import datetime
from decimal import Decimal, InvalidOperation
from zoneinfo import ZoneInfo


# Each path reflects the source category chain; 江苏省 reuses 008's 江苏 node.
STORE_PATHS = {
    44: (("江苏省", "苏州市"), ("集团", "渼约", "江苏", "苏州市"), "19,60"),
    46: (("江苏省", "苏州市", "苏州区"), ("集团", "渼约", "江苏", "苏州市", "苏州区"), "19,60,67"),
    50: (("广东省",), ("集团", "渼约", "广东省"), "65"),
    54: (("浙江省",), ("集团", "渼约", "浙江省"), "70"),
}
SOURCE_GUARD_FIELDS = (
    "id", "name", "is_show", "is_del", "cate_id", "cate_com", "type",
    "phone", "address", "detailed_address", "latitude", "longitude",
    "day_time", "day_start", "day_end",
)
PROFILE_FIELDS = (
    "phone", "address", "detailed_address", "latitude", "longitude",
    "day_time", "day_start", "day_end",
)


def guard_hash(row: dict) -> str:
    """Hash only fields that control eligibility or would enter the import."""
    guard = {key: str(row.get(key) or "") for key in SOURCE_GUARD_FIELDS}
    canonical = json.dumps(guard, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def validate_profile(source_id: int, profile: dict) -> None:
    """Stop truncated or invalid contact/location data before freezing a plan."""
    if not re.fullmatch(r"1[3-9][0-9]{9}", profile["phone"]):
        raise ValueError(f"Store {source_id} has an invalid contact phone")
    for field in ("address", "detailed_address"):
        if not profile[field] or len(profile[field]) > 255:
            raise ValueError(f"Store {source_id} has a missing or overlong {field}")
    try:
        latitude = Decimal(profile["latitude"])
        longitude = Decimal(profile["longitude"])
    except InvalidOperation as error:
        raise ValueError(f"Store {source_id} has invalid coordinates") from error
    if not (-90 <= latitude <= 90 and -180 <= longitude <= 180):
        raise ValueError(f"Store {source_id} has out-of-range coordinates")
    for field in ("day_start", "day_end"):
        try:
            datetime.strptime(profile[field], "%H:%M:%S")
        except ValueError as error:
            raise ValueError(f"Store {source_id} has invalid {field}") from error
    if profile["day_time"] != f'{profile["day_start"]} - {profile["day_end"]}':
        raise ValueError(f"Store {source_id} has inconsistent business hours")


def prepare(raw_rows: list[dict]) -> dict:
    """Reject extra, closed, deleted, or recategorized source records."""
    by_id = {int(row["id"]): row for row in raw_rows}
    if set(by_id) != set(STORE_PATHS) or len(raw_rows) != len(STORE_PATHS):
        raise ValueError("Source rows must be exactly the four reviewed store IDs")

    stores = []
    for source_id, (category_path, target_path, category_chain) in STORE_PATHS.items():
        row = by_id[source_id]
        if row.get("is_show") != "1" or row.get("is_del") != "0":
            raise ValueError(f"Store {source_id} is not an operating, undeleted store")
        if row.get("cate_com") != category_chain or row.get("cate_id") != category_chain.split(",")[-1]:
            raise ValueError(f"Store {source_id} category changed")
        if row.get("type") != "1" or not str(row.get("name") or "").strip():
            raise ValueError(f"Store {source_id} type or name changed")

        # 新港 has mutually conflicting old category, address, and coordinates.
        # The product manager approved its name/organization only until corrected.
        profile = {} if source_id == 46 else {key: str(row.get(key) or "") for key in PROFILE_FIELDS}
        if profile:
            validate_profile(source_id, profile)
        stores.append({
            "source_id": source_id,
            "name": row["name"].strip(),
            "source_category_path": list(category_path),
            "target_org_path": list(target_path),
            "source_guard_sha256": guard_hash(row),
            "profile": profile,
        })

    return {
        "target_instance": "008.cc3798.com",
        "target_database": "mohe_008",
        "source_database": "morestore",
        "prepared_at": datetime.now(ZoneInfo("Asia/Shanghai")).isoformat(timespec="seconds"),
        "stores": stores,
    }


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("Usage: prepare.py source-private.json reviewed-plan.json")
    with open(sys.argv[1], encoding="utf-8") as source:
        plan = prepare(json.load(source))
    content = (json.dumps(plan, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    fd = os.open(sys.argv[2], os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(fd, "wb") as output:
        output.write(content)
    print(json.dumps({"stores": len(plan["stores"]), "plan_sha256": hashlib.sha256(content).hexdigest()}, ensure_ascii=False))


if __name__ == "__main__":
    main()
