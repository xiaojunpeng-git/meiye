#!/usr/bin/env python3
"""Compose fixed, disjoint receipt corrections into one all-or-nothing audit plan."""
import argparse
import collections
import json
import pathlib
from historical_sales_fact_plan import digest


def compose(plans):
    inserts, updates, occupied = collections.defaultdict(list), [], set()
    keys = {'cashier_v3_checkout_payment_draft': 'payment_draft_id', 'cashier_v3_payment_collection': 'collection_id',
            'cashier_v3_payment_fact': 'fact_id', 'cashier_v3_payment_sale_allocation_fact': 'allocation_fact_id',
            'cashier_v3_payment_collection_batch': 'batch_id'}
    for plan in plans:
        for row in plan['updates']:
            identity = (row['table'], row['value'])
            if identity in occupied:
                raise ValueError('overlapping receipt correction')
            occupied.add(identity); updates.append(row)
        for table, rows in plan['inserts'].items():
            for row in rows:
                identity = (table, row[keys[table]])
                if identity in occupied:
                    raise ValueError('overlapping receipt insertion')
                occupied.add(identity); inserts[table].append(row)
    return {'contract': '010-import-receipt-correction-v1', 'expectedMethods': ['bank_transfer', 'other_income'],
            'sourceProofSHA256': digest([p['sourceProofSHA256'] for p in plans]),
            'recordedAt': max(p['recordedAt'] for p in plans), 'inserts': dict(inserts), 'updates': updates,
            'expectedOperationCount': len(occupied), 'receiptCounts': {'bank': 5, 'otherIncome': plans[1]['receiptCount']},
            'expectedCashDeltaCents': sum(p['expectedCashDeltaCents'] for p in plans),
            'expectedOctoberCashDeltaCents': sum(p['expectedOctoberCashDeltaCents'] for p in plans),
            'expectedRefundDeltaCents': 0, 'assetWrites': 0,
            'exceptions': [e for p in plans for e in p.get('exceptions', [])]}


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--bank', required=True);p.add_argument('--income', required=True);p.add_argument('--output', required=True)
    args=p.parse_args(); load=lambda name:json.loads(pathlib.Path(name).read_text())
    result=compose([load(args.bank),load(args.income)])
    pathlib.Path(args.output).write_text(json.dumps(result,ensure_ascii=False,indent=2))
    print(json.dumps({k:result[k]for k in ['receiptCounts','expectedOperationCount','expectedCashDeltaCents','expectedOctoberCashDeltaCents']},ensure_ascii=False))
