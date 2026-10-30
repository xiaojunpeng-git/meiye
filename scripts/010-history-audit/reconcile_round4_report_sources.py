#!/usr/bin/env python3
"""Read-only source-key reconciliation for round-four 007 report migrations.

Usage: python3 reconcile_round4_report_sources.py source-manifest.json

The input must be an independently exported, already eligibility-filtered
snapshot. This script neither connects to a database nor writes a fact. It
refuses to assign a report period from legacy creation time. Its deterministic
missing keys are migration work items, never permission to insert them.
"""

import json
import sys
from collections import Counter, defaultdict
from pathlib import Path


KINDS = {
    "writeoff_service": "IMP007-W{source_id}-service",
    "store_item_sale": "IMP007-{order_id}-line-{cart_detail_id}",
    "product_sale": "IMP007-{order_id}-line-{cart_detail_id}",
    "cross_card_writeoff": "IMP007-W{source_id}-writeoff",
}
IDENTITY = ("store_id", "item_id", "member_id")


def source_key(row):
    kind = row.get("kind")
    if kind not in KINDS:
        raise ValueError("unrecognized source kind")
    if kind in ("store_item_sale", "product_sale"):
        order_id, cart_id = row.get("order_id"), row.get("cart_detail_id")
        if not isinstance(order_id, int) or order_id <= 0 or not isinstance(cart_id, int) or cart_id <= 0:
            raise ValueError("sale source requires positive old order and cart primary keys")
        identity = f"{order_id}:{cart_id}"
    else:
        source_id = row.get("source_id")
        if not isinstance(source_id, int) or source_id <= 0:
            raise ValueError("writeoff source requires positive old primary key")
        identity = str(source_id)
    return f"{kind}:{identity}"


def work_key(row):
    return KINDS[row["kind"]].format(**row)


def reconcile(manifest):
    """Classify exact source mappings without changing historic source values."""
    if manifest.get("business_date_policy") != "v3":
        raise ValueError("business_date_policy must be v3; old time cannot assign a report period")
    if not manifest.get("source_filter_sha256"):
        raise ValueError("independent source eligibility query hash is required")
    sources = manifest.get("qualified_sources")
    facts = manifest.get("v3_facts")
    if not isinstance(sources, list) or not isinstance(facts, list):
        raise ValueError("qualified_sources and v3_facts must be arrays")
    source_map = {}
    for row in sources:
        key = source_key(row)
        if key in source_map:
            raise ValueError("duplicate old source key")
        source_map[key] = row
    fact_map = defaultdict(list)
    for row in facts:
        fact_map[source_key(row)].append(row)
    summary = Counter()
    work = []
    for key in sorted(source_map):
        old = source_map[key]
        matches = fact_map.get(key, [])
        if not matches:
            state = "missing"
        elif len(matches) > 1:
            state = "ambiguous"
        elif any(old.get(field) != matches[0].get(field) for field in IDENTITY if field in old):
            state = "identity_mismatch"
        elif old.get("amount_cents") is not None and old["amount_cents"] != matches[0].get("amount_cents"):
            # Historical whole-yuan normalization must be reviewed against its
            # explicit rounding audit; never overwrite an original amount.
            state = "amount_mismatch"
        elif not matches[0].get("business_date"):
            state = "business_date_missing"
        else:
            state = "matched"
        summary[state] += 1
        if state != "matched":
            work.append({"source_key": key, "proposed_idempotency_key": work_key(old), "state": state})
    orphan = sorted(set(fact_map) - set(source_map))
    summary["unqualified_or_orphan_v3_keys"] = len(orphan)
    return {"source_filter_sha256": manifest["source_filter_sha256"],
            "business_date_policy": "v3", "counts": dict(sorted(summary.items())),
            "migration_work_items": work,
            "unqualified_or_orphan_v3_keys": orphan}


def main(argv):
    if len(argv) != 2:
        raise SystemExit("usage: reconcile_round4_report_sources.py source-manifest.json")
    result = reconcile(json.loads(Path(argv[1]).read_text(encoding="utf-8")))
    print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))


if __name__ == "__main__":
    main(sys.argv)
