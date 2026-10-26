"""Build a reviewable data-only plan; never connect to a database or execute writes.

Only standalone, unreversed, integral-yuan wallet deductions with one matching original
ledger enter this batch. Ambiguous lineage stays on the exception list rather than
being made refundable by manufacturing a payment source.
"""
import csv
import hashlib
import json
import sys
from collections import Counter
from decimal import Decimal
from pathlib import Path

evidence_path, source_path, current_path, output_path = map(Path, sys.argv[1:])
evidence = json.loads(evidence_path.read_text())
source = {r['id']: r for r in csv.DictReader(source_path.open(), delimiter='\t')}
current = list(csv.DictReader(current_path.open(), delimiter='\t'))
known = {r['orderId']: r for r in evidence['evidence']}
unknown = {r['orderId']: r for r in evidence['exceptions']}
rows, exclusions = [], []
guards = ['id', 'uid', 'pid', 'type', 'order_type', 'pay_type', 'pay_price',
          'cash_pay_price', 'yue_pay_price', 'debt_amount', 'paid', 'refund_status',
          'terminal_action', 'paid_ben_amount', 'paid_give_amount', 'paid_balance_ready',
          'card_upgrade_use_oid']
for row in current:
    oid = row['id']
    original = source[oid]
    reasons = []
    if row['uid'] != row['expected_uid'] or row['expected_uid'] == '0':
        reasons.append('member_mapping_mismatch')
    stable = ['pid', 'type', 'order_type', 'pay_type', 'pay_price', 'cash_pay_price',
              'yue_pay_price', 'debt_amount', 'paid', 'refund_status']
    if any(row[k] != original[k] for k in stable): reasons.append('source_target_changed')
    if row['pid'] != '0' or row['has_children'] != '0': reasons.append('parent_child_lineage')
    if row['order_type'] != '0' or row['type'] == '12': reasons.append('non_independent_sale')
    if row['paid'] != '1' or row['refund_status'] != '0' or row['terminal_action'] != '0':
        reasons.append('not_active_paid_order')
    if row['has_upgrade_credit'] != '0' or row['card_upgrade_use_oid'] != '0':
        reasons.append('card_upgrade_lineage')
    if row['has_transfer'] != '0': reasons.append('card_transfer_lineage')
    if row['paid_balance_ready'] != '0' or Decimal(row['paid_ben_amount']) != 0 or Decimal(row['paid_give_amount']) != 0:
        reasons.append('source_fields_already_populated')
    amount = Decimal(row['yue_pay_price'])
    if amount <= 0 or amount > Decimal(row['pay_price']): reasons.append('wallet_amount_not_closed')
    proof = known.get(oid)
    if proof:
        principal, bonus = Decimal(proof['principal']), Decimal(proof['bonus'])
        rule = 'original_ledger_closed_transition'
    elif unknown.get(oid, {}).get('reason') == 'unclosed_transition':
        principal, bonus = Decimal(0), amount
        rule = 'user_approved_unknown_split_to_bonus'
        proof = unknown[oid]
    else:
        principal, bonus = Decimal(0), amount
        rule = 'unresolved'
        reasons.append('no_unique_matching_deduction')
    if principal + bonus != amount: reasons.append('split_not_closed')
    if any(v != v.to_integral_value() for v in [principal, bonus, amount, Decimal(row['pay_price'])]):
        reasons.append('historical_fraction_requires_separate_boundary')
    if reasons:
        exclusions.append({'orderId': int(oid), 'reasons': reasons})
        continue
    rows.append({'orderId': int(oid), 'sourceMemberId': int(original['uid']),
                 'expected': {key: row[key] for key in guards},
                 'after': {'paid_ben_amount': f'{principal:.2f}', 'paid_give_amount': f'{bonus:.2f}', 'paid_balance_ready': 1},
                 'rule': rule, 'evidence': proof})
counts = Counter(row['rule'] for row in rows)
reason_counts = Counter(reason for row in exclusions for reason in row['reasons'])
plan = {'targetDatabase': 'mohe_010_app', 'sourceDatabase': 'mohe_010_refresh',
        'scope': 'standalone integral historical payment-source metadata only',
        'inputSHA256': {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in [evidence_path, source_path, current_path]},
        'count': len(rows), 'ruleCounts': dict(counts), 'exclusionCount': len(exclusions),
        'exclusionReasons': dict(reason_counts), 'rows': rows, 'exclusions': exclusions}
output_path.write_text(json.dumps(plan, ensure_ascii=False, indent=2) + '\n')
output_path.chmod(0o600)
print(json.dumps({k:v for k,v in plan.items() if k not in ('rows','exclusions')},ensure_ascii=False))
