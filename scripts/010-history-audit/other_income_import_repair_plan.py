#!/usr/bin/env python3
"""Plan source-proven 007 type-8 receipts imported into 010 as actual other income.

Source combination receipts outrank summaries. Zero, refunded, missing and rounded-
to-zero source records never become invented cash. Wallet balances are not replayed.
"""
import argparse
import collections
import json
import pathlib
import time
from historical_payment_fact_plan import compile_payments, cents
from historical_sales_fact_plan import digest


def compile_other_income(source, target, details, recorded_at):
    headers = {r['order_id']: r for r in target['sales']}
    original_modes = {int(r['id']): r for r in details['orders']}
    sale_facts = collections.defaultdict(list)
    for row in target['saleFacts']:
        sale_facts[row['order_id']].append(row)
    combinations = collections.defaultdict(list)
    for row in details['combinations']:
        combinations[int(row['order_id'])].append(row)
    updates, proof, exceptions = [], [], []
    inserts = collections.defaultdict(list)
    total_delta, october_delta, receipt_count = 0, 0, 0
    for original in source['bankOrders']:
        sid = int(original['id'])
        if int(original['paid']) != 1 or int(original['is_system_del']) != 0 or int(original['refund_status']) != 0:
            continue
        combo = combinations[sid]
        if combo:
            amount = sum(cents(r['price']) for r in combo if int(r['active_pay']) == 2 and int(r['cash_choose']) == 8)
        elif int(original['cash_choose']) == 8:
            amount = cents(original['cash_pay_price'])
        else:
            # The audit contains source split-8 rows from other summary channels too.
            # Only a complete original combination can prove their amount.
            continue
        if amount <= 0:
            continue
        mode = original_modes.get(sid)
        if not mode or mode['pay_type'] not in ['cash', 'combination']:
            raise ValueError('original_actual_receipt_mode_not_proven')
        if mode['pay_type'] == 'combination' and not combo:
            raise ValueError('complete_original_combination_required')
        if int(original['order_type']) == 1:
            rid = int(original['link_id'])
            facts = [r for r in target['rechargePaymentFacts'] if r['order_id'] == f'RCH:{rid}']
            if not facts:
                continue
            if len(facts) != 1:
                raise ValueError('recharge_receipt_not_unique')
            before = facts[0]
            if before['payment_method'] != 'other_collection' or before['status'] != 'effective' or int(before['amount_cents']) != amount:
                raise ValueError('recharge_receipt_evidence_changed')
            after = dict(before, payment_method='other_income')
            after['immutable_fingerprint'] = digest({k: v for k, v in after.items() if k not in ['id', 'immutable_fingerprint']})
            updates.append({'table': 'cashier_v3_payment_fact', 'key': 'fact_id', 'value': before['fact_id'],
                            'before': before, 'after': after, 'updateColumns': ['payment_method', 'immutable_fingerprint']})
            business_date = before['business_date']
            proof.append({'original': original, 'actualReceiptMode': mode, 'before': before})
        else:
            oid = f'IMP007-{sid}-sale'
            if oid not in headers:
                continue
            header, facts = headers[oid], sale_facts[oid]
            if amount % 100:
                # These two 0.01-yuan originals were already imported as zero-yuan
                # orders. Preserve the source evidence; never invent a 1-yuan receipt.
                if int(header['sale_amount_cents']) != 0:
                    raise ValueError('fractional_nonzero_import_needs_business_review')
                exceptions.append({'sourceOrderId': sid, 'sourceAmountCents': amount,
                                   'reason': 'original_cent_receipt_already_imported_at_zero_yuan'})
                continue
            if (header['order_status'] != 'settled' or int(header['store_id']) != int(original['store_id'])
                    or sum(int(r['sale_amount_cents']) - int(r['debt_amount_cents']) for r in facts) < amount):
                raise ValueError('settled_sale_capacity_or_store_changed')
            if any(r['order_id'] == oid for r in target['salePaymentFacts'] + target['saleAllocations']):
                raise ValueError('existing_receipt_requires_a_different_plan')
            request = next(r for r in target['requests'] if r['request_id'] == header['checkout_request_id'])
            if int(request['selected_payment_amount_cents']) != amount:
                raise ValueError('confirmed_original_cash_amount_changed')
            evidence = {'original': original, 'actualReceiptMode': mode, 'combinations': combo, 'header': header, 'saleFacts': facts, 'request': request}
            graph_header = dict(header, recorded_at=recorded_at, add_time=recorded_at, update_time=recorded_at)
            plan = {'sourceOrderId': sid, 'evidenceSHA256': digest(evidence),
                    'baseTables': {'cashier_v3_sales_order': [graph_header], 'cashier_v3_checkout_request': [request], 'cashier_v3_sale_fact': facts},
                    'tenderPlan': {'issues': [], 'tenders': [{'sourceId': f'order:{sid}:type8', 'kind': 'bookkeeping',
                                 'method': 'other_income', 'sourceCashType': 8, 'sourceName': '其他收入', 'amountCents': amount}],
                                 'totals': {'historical_entry': 0, 'bookkeeping': amount, 'wallet': 0, 'card_upgrade': 0}}}
            # Restoring only the proven cash graph does not repeat the original
            # wallet deduction or change any current member/account balance.
            graph, _ = compile_payments(plan, {'schema': target['schema'], 'recordedAt': recorded_at}, {})
            batch = graph.pop('cashier_v3_payment_collection_batch')[0]
            before = next(r for r in target['existingPaymentGraphs']['cashier_v3_payment_collection_batch'] if r['sales_order_id'] == oid)
            if int(before['collection_count']) != 0 or int(before['collected_amount_cents']) != 0:
                raise ValueError('nonempty_batch_not_replaceable')
            updates.append({'table': 'cashier_v3_payment_collection_batch', 'key': 'batch_id', 'value': before['batch_id'], 'before': before, 'after': batch})
            for table, rows in graph.items():
                inserts[table].extend(rows)
            business_date = header['business_date']
            proof.append(evidence)
        receipt_count += 1
        total_delta += amount
        if '2026-10-01' <= business_date <= '2026-10-31':
            october_delta += amount
    inserts = {table: rows for table, rows in inserts.items() if rows}
    return {'contract': '010-other-income-import-correction-v1', 'recordedAt': recorded_at, 'sourceProofSHA256': digest(proof),
            'receiptCount': receipt_count, 'inserts': inserts, 'updates': updates, 'exceptions': exceptions,
            'expectedOperationCount': len(updates) + sum(map(len, inserts.values())),
            'expectedCashDeltaCents': total_delta, 'expectedOctoberCashDeltaCents': october_delta,
            'expectedRefundDeltaCents': 0, 'assetWrites': 0}


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    for name in ['source', 'target', 'details', 'output']:
        p.add_argument('--' + name, required=True)
    args = p.parse_args()
    load = lambda path: json.loads(pathlib.Path(path).read_text())
    plan = compile_other_income(load(args.source), load(args.target), load(args.details), int(time.time()))
    pathlib.Path(args.output).write_text(json.dumps(plan, ensure_ascii=False, indent=2))
    print(json.dumps({k: plan[k] for k in ['receiptCount', 'expectedOperationCount', 'expectedCashDeltaCents', 'expectedOctoberCashDeltaCents', 'exceptions']}, ensure_ascii=False))
