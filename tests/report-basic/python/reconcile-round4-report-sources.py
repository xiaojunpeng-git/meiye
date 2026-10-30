#!/usr/bin/env python3
"""Stable source identity and read-only failure classification contract."""

import importlib.util
from pathlib import Path

root = Path(__file__).resolve().parents[3]
path = root / "scripts/010-history-audit/reconcile_round4_report_sources.py"
spec = importlib.util.spec_from_file_location("round4_reconcile", path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

old = {"kind": "writeoff_service", "source_id": 17, "store_id": 9,
       "item_id": 44, "member_id": 21, "amount_cents": 1200}
fact = dict(old, business_date="2026-10-20")
manifest = {"business_date_policy": "v3", "source_filter_sha256": "test-sha",
            "qualified_sources": [old], "v3_facts": [fact]}
assert module.reconcile(manifest)["counts"]["matched"] == 1
manifest["v3_facts"] = []
missing = module.reconcile(manifest)
assert missing["migration_work_items"] == [{"source_key": "writeoff_service:17",
    "proposed_idempotency_key": "IMP007-W17-service", "state": "missing"}]
manifest["v3_facts"] = [dict(fact, member_id=22)]
assert module.reconcile(manifest)["counts"]["identity_mismatch"] == 1
manifest["v3_facts"] = [fact, fact]
assert module.reconcile(manifest)["counts"]["ambiguous"] == 1
manifest["v3_facts"] = [dict(fact, amount_cents=1100)]
assert module.reconcile(manifest)["counts"]["amount_mismatch"] == 1
manifest["business_date_policy"] = "legacy_add_time"
try:
    module.reconcile(manifest)
    raise AssertionError("old creation time assigned report period")
except ValueError:
    pass
print("reconcile-round4-report-sources: PASS")
