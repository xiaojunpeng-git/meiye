#!/usr/bin/env python3
"""Build historical native DATA payloads, never SQL or executable application code.

This compiler has no database connection/apply mode. Each payload remains explicitly
incomplete until payment, rights, service and reversal facts have been attached and
the unchanged application has verified the entire graph. Required schema fields are
never satisfied by a generic zero/empty-string fallback.
"""
from __future__ import annotations

import argparse
import collections
import datetime as dt
import gzip
import hashlib
import json
import pathlib
import re


def load(path):
    opener = gzip.open if str(path).endswith('.gz') else open
    with opener(path, 'rt', encoding='utf-8') as stream:
        return json.load(stream)


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True,
                                    separators=(',', ':')).encode()).hexdigest()


def schema_row(schema, values):
    """Honor actual SQL defaults only; reject every unmapped required column."""
    out = {}
    for col in schema:
        name = col['Field']
        if col['Extra'] == 'auto_increment':
            continue
        if name in values:
            out[name] = values[name]
        elif col['Default'] is not None:
            default = col['Default']
            out[name] = int(default) if 'int' in col['Type'] and re.fullmatch(r'-?\d+', str(default)) else default
        elif col['Null'] == 'YES':
            out[name] = None
        else:
            raise ValueError('unmapped_required_column:' + name)
    if 'immutable_fingerprint' in out:
        out['immutable_fingerprint'] = digest({k: v for k, v in out.items() if k != 'immutable_fingerprint'})
    return out


def build_base(order, line_plan, payment, context, lookups):
    """Build consistent headers/lines/sale facts while retaining all missing gates."""
    if line_plan['issues'] or payment['issues']:
        raise ValueError('upstream_plan_has_unresolved_issues')
    ident = int(order['id'])
    if ident != line_plan['sourceOrderId'] or ident != payment['sourceOrderId']:
        raise ValueError('source_identity_mismatch')
    store_id, member_id = int(order['store_id']), int(order['uid'])
    if member_id > 0 and not int(order['target_member_exists']):
        raise ValueError('target_member_missing')
    if store_id not in lookups['bindings']:
        raise ValueError('historical_store_has_no_current_binding')
    org_id = int(lookups['bindings'][store_id]['org_id'])
    org_path, org_names, seen = [], [], set()
    cursor = org_id
    while cursor:
        if cursor in seen or cursor not in lookups['orgs']:
            raise ValueError('organization_path_invalid')
        seen.add(cursor)
        org = lookups['orgs'][cursor]
        org_path.insert(0, str(cursor)); org_names.insert(0, org['name'])
        cursor = int(org['pid'])
    staff_id = int(order['source_staff_id'])
    staff = lookups['staff'].get(staff_id)
    if staff_id and (not staff or not int(staff['identity_verified'])):
        raise ValueError('source_operator_identity_unverified')
    source_id = int(order['source007_business_source_id'])
    source = lookups['sources'].get(source_id)
    if source_id:
        target_source = lookups['target_sources'].get(int(source['target010Id'])) if source else None
        if not target_source or target_source['name'] != source['name']:
            raise ValueError('approved_business_source_mapping_changed')
    at = int(order['pay_time'])
    if at <= 0:
        raise ValueError('historical_payment_time_missing')
    business_date = dt.datetime.fromtimestamp(at, dt.timezone(dt.timedelta(hours=8))).date().isoformat()
    stem = f'IMP007-{ident}'; oid = stem + '-sale'; rid = stem + '-request'
    amount = payment['sourceAmountCents']
    lines = line_plan['lines']
    if sum(row['sale_amount_cents'] for row in lines) != amount:
        raise ValueError('line_payment_amount_mismatch')
    evidence_hash = digest([order, line_plan, payment])
    common = dict(tenant_id='0', organization_id=str(org_id),
                  organization_path_snapshot=','.join(org_path), organization_name_snapshot=org_names[-1],
                  store_id=store_id, store_name_snapshot=lookups['stores'][store_id]['name'],
                  member_id=member_id, member_name_snapshot=order['target_member_real_name'] or order['target_member_nickname'] or '',
                  operator_id=staff_id, operator_name_snapshot=staff['staff_name'] if staff else '',
                  business_date=business_date, business_timezone='Asia/Shanghai', occurred_at=at, settled_at=at,
                  recorded_at=context['recordedAt'], add_time=at, update_time=at,
                  checkout_request_id=rid, checkout_request_version=1,
                  checkout_prepare_idempotency_key=stem, command_idempotency_key=stem,
                  immutable_fingerprint=evidence_hash, contract_version='legacy-import-data-v1',
                  source_document_type='legacy_store_order', source_document_id=str(ident),
                  source_document_no_snapshot=order['order_id'])
    def row(table, specific):
        return schema_row(context['schema'][table], dict(common, **specific))
    # Personnel arrays are staged empty, not declared complete. Their source
    # evidence must be merged before any candidate may become applicable.
    native_lines = []
    sale_facts = []
    for line in lines:
        state = dict(line, command_idempotency_key=stem, immutable_fingerprint=evidence_hash,
                     craftsmen_snapshot_json='[]', salespeople_snapshot_json='[]',
                     line_status='settled', line_version=1, line_direction='forward')
        native_line = schema_row(context['schema']['cashier_v3_sales_order_line'], common | state)
        native_lines.append(native_line)
        sale_facts.append(row('cashier_v3_sale_fact', dict(
            fact_id=line['order_line_id']+'-fact', natural_key=line['order_line_id']+'-fact',
            business_event_no=stem+'-source-sale', fact_type='sale_completed', fact_direction='forward',
            fact_version=1, status='effective', order_id=oid, order_no_snapshot=order['order_id'],
            source_line_id=line['order_line_id'], source_type=line['item_type'], item_id=line['item_id'],
            item_name_snapshot=line['item_name_snapshot'], quantity=line['quantity'],
            category_id_snapshot=line['category_id_snapshot'], category_name_snapshot=line['category_name_snapshot'],
            original_amount_cents=line['original_amount_cents'], discount_amount_cents=line['discount_amount_cents'],
            sale_amount_cents=line['sale_amount_cents'])))
    header = row('cashier_v3_sales_order', dict(
        order_id=oid, order_no=order['order_id'], natural_key=stem+':sale',
        checkout_authority_fingerprint=evidence_hash, checkout_aggregate_fingerprint=evidence_hash,
        checkout_source_set_fingerprint=evidence_hash, composition='sale_only',
        business_source_primary_id=int(source['target010Id']) if source else 0,
        business_source_primary_name_snapshot=source['name'] if source else '',
        business_source_label_snapshot=source['name'] if source else '',
        line_count=len(native_lines), total_quantity=sum(x['quantity'] for x in native_lines),
        original_amount_cents=sum(x['original_amount_cents'] for x in native_lines),
        discount_amount_cents=sum(x['discount_amount_cents'] for x in native_lines), sale_amount_cents=amount,
        order_status='settled', order_version=1, order_direction='forward'))
    header['immutable_fingerprint'] = digest([header, [x['immutable_fingerprint'] for x in native_lines]])
    request = row('cashier_v3_checkout_request', dict(
        request_id=rid, request_status='succeeded', request_version=1, composition='sale_only',
        operation_occurred_at=at, source_document_no=order['order_id'],
        sales_amount_cents=amount, receivable_amount_cents=amount,
        balance_deduction_amount_cents=payment['totals']['wallet'],
        selected_payment_amount_cents=payment['totals']['bookkeeping']+payment['totals']['historical_entry'],
        cash_performance_amount_cents=payment['totals']['bookkeeping'], balance_account_id=str(member_id),
        creation_idempotency_key=stem, last_idempotency_key=stem, last_operation='import_history',
        authority_fingerprint=evidence_hash, aggregate_fingerprint=evidence_hash, last_operation_fingerprint=evidence_hash))
    dependencies = {key: int(value.get(str(ident), 0)) for key, value in context['dependencies'].items()}
    pending = ['payment_and_balance_facts', 'personnel_and_performance_facts', 'atomic_audit_and_legacy_archive', 'native_query_and_reversal_verification']
    if dependencies['holder']:
        pending.append('card_receipts_and_asset_carrier')
    if dependencies['writeoff']:
        pending.append('service_and_writeoff_chain')
    if dependencies['refund']:
        pending.append('refund_evidence')
    if any(x['item_type'] == 'product' for x in lines):
        pending.append('inventory_outbound_and_reversal')
    return dict(sourceOrderId=ident, readyForApply=False, evidenceSHA256=evidence_hash,
                baseTables={'cashier_v3_checkout_request': [request], 'cashier_v3_sales_order': [header],
                            'cashier_v3_sales_order_line': native_lines, 'cashier_v3_sale_fact': sale_facts},
                dependencies=dependencies, pending=pending, tenderPlan=payment,
                sourcePriceEvidence=line_plan['priceEvidence'])


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('--context', required=True); ap.add_argument('--lines', required=True)
    ap.add_argument('--payments', required=True); ap.add_argument('--output', required=True)
    ap.add_argument('--summary', required=True)
    args = ap.parse_args()
    context = load(args.context); lp = load(args.lines); pp = load(args.payments)
    line_plans = {x['sourceOrderId']: x for x in lp['documents']}
    payments = {x['sourceOrderId']: x for x in pp['documents']}
    foundation = context['foundation']
    lookup = {'bindings': {int(x['store_id']): x for x in foundation['storeBindings']},
              'orgs': {int(x['id']): x for x in foundation['organizations']},
              'stores': {int(x['id']): x for x in foundation['stores']},
              'staff': {int(x['id']): x for x in context['staffIdentity']},
              'sources': {int(x['source007Id']): x for x in context['source007Mapping']},
              'target_sources': {int(x['id']): x for x in foundation['businessSources']}}
    counts = collections.Counter(); reasons = collections.Counter(); exceptions = []
    with gzip.open(args.output, 'wt', encoding='utf-8') as out:
        for order in context['orders']:
            ident = int(order['id']); counts['sourceOrders'] += 1
            try:
                plan = build_base(order, line_plans[ident], payments[ident], context, lookup)
            except (ValueError, KeyError) as exc:
                reason = str(exc); reasons[reason] += 1
                exceptions.append({'sourceOrderId': ident, 'reason': reason})
                continue
            out.write(json.dumps(plan, ensure_ascii=False, separators=(',', ':'))+'\n')
            counts['baseGraphsCompiled'] += 1
            for table, rows in plan['baseTables'].items():
                counts[table] += len(rows)
    summary = dict(mode='offline-incomplete-native-fact-data-plan', counts=dict(counts),
                   reasons=dict(reasons), exceptions=exceptions, productionWrites=0, readyForApply=False,
                   foundationSHA256=context['foundationSHA256'],
                   inputs={str(p): hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
                           for p in [args.context, args.lines, args.payments]},
                   limitations=['Base graphs only. Pending gates prohibit production application.',
                                'No foundation updates, current asset writes, or payment replay.'])
    pathlib.Path(args.summary).write_text(json.dumps(summary, ensure_ascii=False, indent=2)+'\n')
    print(json.dumps({'counts': dict(counts), 'reasons': dict(reasons), 'readyForApply': False}, ensure_ascii=False))


if __name__ == '__main__':
    main()
