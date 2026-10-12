"""Prepare a private 008 employee plan from read-only old/target snapshots.

The old technician page is backed by morestore.eb_user(group_id=7). This
script deliberately imports identity and store assignment only: existing 008
phone owners remain authoritative, and closed-store technicians stay held.
The input and output contain personal data and must be kept outside Git.
"""

import hashlib
import json
import re
import sys
from pathlib import Path


GUARD_FIELDS = (
    "uid", "real_name", "nickname", "phone", "store_id", "status", "is_del",
    "store_name", "store_is_show", "store_is_del",
)


def canonical_guard(row):
    """Hash only the old values that govern identity and current store eligibility."""
    values = {key: "" if row.get(key) is None else str(row[key]) for key in sorted(GUARD_FIELDS)}
    raw = json.dumps(values, ensure_ascii=False, separators=(",", ":"))
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def prepare(snapshot, store_map):
    """Resolve exact phone and reviewed store-ID matches; never fuzzy-merge people."""
    employees = {}
    for employee in snapshot["target_employees"]:
        phone = str(employee["phone"])
        if phone in employees:
            raise ValueError("008 has a duplicate employee phone")
        employees[phone] = employee
    stores = {int(row["id"]): row for row in snapshot["target_stores"]}
    accounts = set(snapshot["target_accounts"])
    seen_phones = set()
    candidates, skipped, held = [], [], []
    for row in snapshot["source_rows"]:
        uid = int(row["uid"])
        phone = str(row["phone"])
        if not re.fullmatch(r"1[3-9][0-9]{9}", phone) or phone in seen_phones:
            raise ValueError("Old technician phone is invalid or duplicated")
        seen_phones.add(phone)
        guard = canonical_guard(row)
        if phone in employees:
            skipped.append({"source_uid": uid, "phone": phone,
                            "employee_id": int(employees[phone]["id"]), "source_guard_sha256": guard})
            continue
        active = str(row["store_is_show"]) == "1" and str(row["store_is_del"]) == "0"
        if not active:
            held.append({"source_uid": uid, "source_store_id": int(row["store_id"]),
                         "source_guard_sha256": guard, "reason": "closed_store"})
            continue
        source_store_id = int(row["store_id"])
        target_store_id = int(store_map.get(str(source_store_id), 0))
        target = stores.get(target_store_id)
        if not target or str(target["is_show"]) != "1" or str(target["is_del"]) != "0":
            raise ValueError("Old active store lacks an active reviewed 008 mapping")
        name = (row["real_name"] or "").strip() or (row["nickname"] or "").strip()
        if not name or len(name) > 64 or "A" + phone in accounts:
            raise ValueError("New employee name/account is unusable")
        candidates.append({
            "source_uid": uid, "source_store_id": source_store_id,
            "target_store_id": target_store_id, "target_org_id": int(target["org_id"]),
            "name": name, "name_source": "real_name" if (row["real_name"] or "").strip() else "nickname",
            "phone": phone, "source_guard_sha256": guard,
        })
    if (len(snapshot["source_rows"]), len(skipped), len(candidates), len(held)) != (99, 50, 46, 3):
        raise ValueError("Reviewed technician partition changed")
    return {
        "plan_version": "008-morestore-technician-2026-10-13-v1",
        "source_database": "morestore", "target_database": "mohe_008",
        "target_instance": "008.cc3798.com", "position_id": 19,
        "account_policy": "A+phone / last-four / no-forced-change",
        "skipped_existing": skipped, "held_closed_store": held, "candidates": candidates,
    }


def main():
    if len(sys.argv) != 4:
        raise SystemExit("usage: prepare.py private-snapshot.json reviewed-catalog-plan.json private-output.json")
    snapshot = json.loads(Path(sys.argv[1]).read_text())
    mapping = json.loads(Path(sys.argv[2]).read_text())["store_map"]
    output = Path(sys.argv[3])
    if output.exists():
        raise SystemExit("refusing to replace an existing private plan")
    plan = prepare(snapshot, mapping)
    output.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + "\n")
    output.chmod(0o600)
    print(json.dumps({"candidates": 46, "existing_unchanged": 50, "held": 3,
                      "sha256": hashlib.sha256(output.read_bytes()).hexdigest()}))


if __name__ == "__main__":
    main()
