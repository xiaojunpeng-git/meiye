#!/usr/bin/env python3
"""Prepare the 008 organization roster without writing any business database.

The input workbooks are customer data. The private JSON output is a staging
artifact outside Git; this script only records source hashes in its summary.
"""

from __future__ import annotations

import argparse
from collections import defaultdict
from hashlib import sha256
import json
import os
from pathlib import Path
import re

from openpyxl import load_workbook


PHONE = re.compile(r"1[3-9][0-9]{9}\Z")
REGION_TITLES = {"经理", "区域经理", "区经理", "直营经理", "一区经理", "三区经理", "区财务"}
MEIYUE_OVERLAP_STORES = {"越秀店", "海伦堡"}
# This one-time plan must never silently accept a different attachment: a
# swapped workbook could grant the wrong company's personnel and data scope.
EXPECTED_SOURCE_SHA256 = {
    "meiyue": "fedb12bd73929347754569cef1a37b193ab9c32e4d1331cb583495104d316692",
    "songni_other": "1a639cfd66cc3612186f109b3544020913e011477380fff4619b0e45c88002b5",
    "songni_summary": "dfac7de9b9abf587aff771d2cbf655be2870649969d428557dda99f053169f92",
}


def cell(value: object) -> str:
    """Keep identifiers as text so Excel never truncates or reformats phones."""
    return "" if value is None else str(value).strip()


def parse_source(path: Path, source: str) -> list[dict]:
    """Fill the workbook's visually merged hierarchy from prior nonblank cells."""
    sheet = load_workbook(path, read_only=True, data_only=True).active
    province = region = store = ""
    records = []
    for row_no, raw in enumerate(sheet.iter_rows(min_row=3, values_only=True), 3):
        values = [cell(value) for value in raw[:7]]
        values.extend([""] * (7 - len(values)))
        province_cell, region_cell, store_cell, title, name, phone, note = values
        if not any(values):
            continue
        # A new province or region starts a fresh hierarchy branch. Carrying
        # the prior store into a region-only manager row would misassign staff.
        if province_cell and province_cell != province:
            region = store = ""
        if region_cell and region_cell != region:
            store = ""
        province = province_cell or province
        region = region_cell or region
        store = store_cell or store
        # In the Songni summary, columns D and E are reversed for exactly this
        # contiguous block; preserving the literal columns would invent roles.
        if source == "songni_summary" and 166 <= row_no <= 185:
            title, name = name, title
        company = "渼约" if source == "meiyue" else "歌妮"
        row_province, row_region = province, region
        if source == "songni_other" and store in MEIYUE_OVERLAP_STORES:
            # Product manager confirmed both overlap stores belong to Meiyue.
            # Resolve to the destination written in Meiyue's primary workbook.
            company, row_province, row_region = "渼约", "广州", ""
        records.append(
            {
                "source": source,
                "row": row_no,
                "company": company,
                "province": row_province,
                "region": row_region,
                "store": store,
                "store_header": bool(store_cell),
                "title": title,
                "name": name,
                "phone": phone,
                "note": note,
            }
        )
    return records


def plan(records: list[dict], region_roles: bool) -> dict:
    """Quarantine uncertain identities and retain row-level provenance."""
    def assignment_destination(row: dict) -> tuple[str, ...]:
        """Compare actual job scope; a region worker's sheet store is context only."""
        if region_roles and row["title"] in REGION_TITLES and row["region"]:
            return (row["company"], row["province"], row["region"])
        return (row["company"], row["province"], row["region"], row["store"])

    by_phone: dict[str, list[dict]] = defaultdict(list)
    for row in records:
        if row["phone"]:
            by_phone[row["phone"]].append(row)

    blocked: dict[tuple[str, int], str] = {}
    for row in records:
        key = (row["source"], row["row"])
        is_region_role = region_roles and row["title"] in REGION_TITLES and bool(row["region"])
        if not row["name"] or not row["title"] or not row["province"] or (not is_region_role and not row["store"]):
            blocked[key] = "required_field_missing"
        elif not PHONE.fullmatch(row["phone"]):
            blocked[key] = "phone_missing_or_invalid"
        elif row["source"] == "songni_other" and row["row"] == 170:
            # The title says manager of region three, but the region column
            # still says region one. A silent guess would grant wrong scope.
            blocked[key] = "region_title_conflict"

    # The two reassigned overlap stores contain short nicknames in Meiyue's
    # list and longer names with different phones in the other workbook.
    # A possible phone change must not create two employee identities.
    overlap = [row for row in records if row["company"] == "渼约" and row["store"] in MEIYUE_OVERLAP_STORES]
    for index, first in enumerate(overlap):
        for second in overlap[index + 1:]:
            if first["source"] == second["source"] or first["store"] != second["store"] or first["phone"] == second["phone"]:
                continue
            short, long = sorted((first["name"], second["name"]), key=len)
            alias = len(short) >= 2 and (
                long.endswith(short) or (len(short) == 2 and short[0] == short[1] and long.endswith(short[0]))
            )
            if alias:
                blocked[(first["source"], first["row"])] = "possible_same_person_different_phone"
                blocked[(second["source"], second["row"])] = "possible_same_person_different_phone"

    for phone, group in by_phone.items():
        if len(group) <= 1:
            continue
        names = {row["name"] for row in group}
        destinations = {assignment_destination(row) for row in group}
        titles = {row["title"] for row in group}
        # The same phone is one global employee. A different name or a second
        # destination requires a human choice before any assignment is saved.
        if len(names) > 1 or len(destinations) > 1:
            for row in group:
                blocked[(row["source"], row["row"])] = "phone_identity_or_assignment_conflict"
        elif len(titles) > 1:
            # One person and scope still leave a business choice when job titles
            # disagree; no title receives an invented priority in this import.
            for row in group:
                blocked[(row["source"], row["row"])] = "phone_job_title_conflict"

    candidates: list[dict] = []
    same_person_collapsed_rows: list[dict] = []
    seen_phone: set[str] = set()
    for row in records:
        key = (row["source"], row["row"])
        if key in blocked:
            continue
        if row["phone"] in seen_phone:
            same_person_collapsed_rows.append(row)
            continue
        seen_phone.add(row["phone"])
        candidate = dict(row)
        candidate["assignment_kind"] = "region" if region_roles and row["title"] in REGION_TITLES and row["region"] else "store"
        # The product manager set data scope by appointment, with mobile entry
        # enabled for every person. Functional grants are resolved from jobs
        # at apply time; this plan never invents role IDs or passwords.
        candidate["data_scope"] = "group" if candidate["assignment_kind"] == "region" else "appointment_store"
        candidate["mobile_scope"] = "group" if candidate["assignment_kind"] == "region" else "appointment_store"
        candidate["channels"] = ["platform", "store_v3", "mobile"] if candidate["assignment_kind"] == "region" else ["store_v3", "mobile"]
        # The approved A+phone convention is a login identifier, not a phone
        # login alias. The initial four-digit secret is derived only at apply
        # time and never persisted in this reviewable plan as plain text.
        candidate["internal_account"] = "A" + row["phone"]
        candidate["function_scope"] = "all_enabled_functions_per_channel"
        candidate["initial_password_policy"] = "phone_last_four_no_forced_change"
        candidates.append(candidate)

    # Store definitions follow explicit store headers, plus destination-only
    # cross-file rows. Personnel with blank store cells never create a store.
    store_keys = {
        (row["company"], row["province"], row["region"], row["store"])
        for row in records
        if row["store_header"] and row["store"]
    }
    for row in candidates:
        if row["assignment_kind"] == "region":
            continue
        key = (row["company"], row["province"], row["region"], row["store"])
        if key not in store_keys:
            blocked[(row["source"], row["row"])] = "store_header_missing"
    candidates = [row for row in candidates if (row["source"], row["row"]) not in blocked]

    exceptions = [dict(row, reason=blocked[(row["source"], row["row"])]) for row in records if (row["source"], row["row"]) in blocked]
    stores = [dict(zip(("company", "province", "region", "store"), key)) for key in sorted(store_keys)]
    companies = sorted({row["company"] for row in records})
    provinces = sorted({(row["company"], row["province"]) for row in records if row["province"]})
    regions = sorted({(row["company"], row["province"], row["region"]) for row in records if row["region"]})
    return {
        "organizations": {"root": "集团", "companies": companies, "provinces": provinces, "regions": regions},
        "stores": stores,
        "candidates": candidates,
        "exceptions": exceptions,
        "same_person_collapsed_rows": same_person_collapsed_rows,
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--meiyue", type=Path, required=True)
    parser.add_argument("--songni-other", type=Path, required=True)
    parser.add_argument("--songni-summary", type=Path, required=True)
    parser.add_argument("--out", type=Path, required=True)
    parser.add_argument("--region-roles", action="store_true")
    args = parser.parse_args()
    if not args.region_roles:
        raise SystemExit("008 requires the confirmed region-vs-store appointment rule")
    sources = {"meiyue": args.meiyue, "songni_other": args.songni_other, "songni_summary": args.songni_summary}
    source_hashes = {key: sha256(path.read_bytes()).hexdigest() for key, path in sources.items()}
    if source_hashes != EXPECTED_SOURCE_SHA256:
        raise SystemExit("Input workbook hashes differ from the three reviewed attachments")
    records = [record for key, path in sources.items() for record in parse_source(path, key)]
    result = plan(records, args.region_roles)
    result["source_sha256"] = source_hashes
    result["target_instance"] = "008.cc3798.com"
    result["target_database"] = "mohe_008"
    args.out.parent.mkdir(parents=True, exist_ok=True)
    descriptor = os.open(args.out, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
        json.dump(result, stream, ensure_ascii=False, sort_keys=True, indent=2)
        stream.write("\n")
    summary = {
        "source_rows": len(records),
        "organizations": 1 + len(result["organizations"]["companies"]) + len(result["organizations"]["provinces"]) + len(result["organizations"]["regions"]),
        "stores": len(result["stores"]),
        "candidate_people": len(result["candidates"]),
        "exceptions": len(result["exceptions"]),
        "same_person_collapsed_rows": len(result["same_person_collapsed_rows"]),
        "exception_reasons": {reason: sum(row["reason"] == reason for row in result["exceptions"]) for reason in sorted({row["reason"] for row in result["exceptions"]})},
        "source_sha256": source_hashes,
        "staging_sha256": sha256(args.out.read_bytes()).hexdigest(),
    }
    print(json.dumps(summary, ensure_ascii=False, sort_keys=True))


if __name__ == "__main__":
    main()
