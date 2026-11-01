#!/usr/bin/env python3
"""Read-only plan compiler for the explicitly approved 010 bank import correction.

Only original receipts linked to already imported, settled business are admitted.
No customer assets, refund, balance replay or newly invented payment is produced.
The existing historical graph compiler supplies the complete payment graph.
"""
import argparse
import json
import pathlib
import time
from historical_payment_fact_plan import compile_payments, cents
from historical_sales_fact_plan import digest


def compile_bank_repair(source, target, recorded_at):
    orders = {int(r['id']): r for r in source['bankOrders']}
    expected_sales = {27904: 1000000, 117263: 29800}
    expected_recharges = {1255: 1000000, 4725: 199900, 5056: 188800}
    corrections = []
    inserts = {}
    replacements = []
    proof = []
    for sid, amount in expected_sales.items():
        original = orders[sid]
        if not (int(original['paid']) == 1 and int(original['pid']) == 0
                and int(original['refund_status']) == 0 and int(original['is_system_del']) == 0
                and int(original['cash_choose']) == 5 and cents(original['cash_pay_price']) == amount
                and cents(original['yue_pay_price']) == 0):
            raise ValueError('original_sale_bank_receipt_not_proven')
        oid = f'IMP007-{sid}-sale'
        header = next(r for r in target['sales'] if r['order_id'] == oid)
        sales = [r for r in target['saleFacts'] if r['order_id'] == oid]
        request = next(r for r in target['requests'] if r['request_id'] == header['checkout_request_id'])
        if (header['order_status'] != 'settled' or len(sales) != 1
                or int(header['sale_amount_cents']) != amount or int(sales[0]['debt_amount_cents']) != 0
                or int(sales[0]['sale_amount_cents']) != amount or int(header['store_id']) != int(original['store_id'])):
            raise ValueError('imported_sale_or_single_line_boundary_changed')
        if any(r['order_id'] == oid for r in target['salePaymentFacts'] + target['saleAllocations']):
            raise ValueError('receipt_already_exists')
        evidence = {'original': original, 'saleFact': sales[0], 'request': request, 'header': header}
        proof.append(evidence)
        # Keep historical occurrence/settlement dates. Only recording time belongs to the repair.
        graph_header = dict(header, recorded_at=recorded_at, add_time=recorded_at, update_time=recorded_at)
        tender = {'sourceId': f'store_order:{sid}:bank', 'kind': 'bookkeeping', 'method': 'bank_transfer',
                  'sourceCashType': 5, 'sourceName': '银行转账', 'amountCents': amount}
        plan = {'sourceOrderId': sid, 'evidenceSHA256': digest(evidence),
                'baseTables': {'cashier_v3_sales_order': [graph_header], 'cashier_v3_checkout_request': [request],
                               'cashier_v3_sale_fact': sales},
                'tenderPlan': {'issues': [], 'tenders': [tender],
                               'totals': {'historical_entry': 0, 'bookkeeping': amount, 'wallet': 0, 'card_upgrade': 0}}}
        rows, _ = compile_payments(plan, {'schema': target['schema'], 'recordedAt': recorded_at}, {})
        batch = rows.pop('cashier_v3_payment_collection_batch')[0]
        before = next(r for r in target['existingPaymentGraphs']['cashier_v3_payment_collection_batch']
                      if r['sales_order_id'] == oid)
        if int(before['collection_count']) != 0 or int(before['collected_amount_cents']) != 0:
            raise ValueError('nonempty_batch_not_replaceable')
        replacements.append({'table': 'cashier_v3_payment_collection_batch', 'key': 'batch_id',
                             'value': before['batch_id'], 'before': before, 'after': batch})
        for table, values in rows.items():
            if values:
                inserts.setdefault(table, []).extend(values)
    for rid, amount in expected_recharges.items():
        linked = [r for r in orders.values() if int(r['order_type']) == 1 and int(r['link_id']) == rid]
        if len(linked) != 1:
            raise ValueError('recharge_original_receipt_not_unique')
        original = linked[0]
        if not (int(original['cash_choose']) == 5 and int(original['paid']) == 1
                and int(original['refund_status']) == 0 and int(original['is_system_del']) == 0
                and cents(original['cash_pay_price']) == amount):
            raise ValueError('recharge_original_bank_receipt_not_proven')
        fact = next(r for r in target['rechargePaymentFacts'] if r['fact_id'] == f'IMP007-RCH-{rid}-payment-1')
        if (fact['status'] != 'effective' or int(fact['amount_cents']) != amount
                or fact['payment_method'] != ('unionpay' if rid == 5056 else 'other_collection')
                or int(fact['store_id']) != int(original['store_id'])):
            raise ValueError('imported_recharge_receipt_boundary_changed')
        # Product manager explicitly allows correcting these original imported fields.
        # Full before/after rows remain in the immutable repair audit and backup.
        after = dict(fact, payment_method='bank_transfer')
        after['immutable_fingerprint'] = digest({k: v for k, v in after.items() if k not in ['id', 'immutable_fingerprint']})
        corrections.append({'table': 'cashier_v3_payment_fact', 'key': 'fact_id', 'value': fact['fact_id'],
                            'before': fact, 'after': after, 'updateColumns': ['payment_method', 'immutable_fingerprint']})
        proof.append({'original': original, 'before': fact})
    return {'contract': '010-bank-import-correction-v1', 'recordedAt': recorded_at,
            'sourceProofSHA256': digest(proof), 'inserts': inserts, 'updates': replacements + corrections,
            'expectedCashDeltaCents': 2229700, 'expectedOctoberCashDeltaCents': 229700,
            'expectedRefundDeltaCents': 0, 'assetWrites': 0}


if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', required=True); p.add_argument('--target', required=True); p.add_argument('--output', required=True)
    args = p.parse_args()
    plan = compile_bank_repair(json.loads(pathlib.Path(args.source).read_text()),
                               json.loads(pathlib.Path(args.target).read_text()), int(time.time()))
    pathlib.Path(args.output).write_text(json.dumps(plan, ensure_ascii=False, indent=2))
    print(json.dumps({'inserts': {k: len(v) for k, v in plan['inserts'].items()}, 'updates': len(plan['updates']),
                      'cashDeltaCents': plan['expectedCashDeltaCents'], 'assetWrites': 0}))
