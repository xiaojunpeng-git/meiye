#!/usr/bin/env python3
"""Compile historical payment DATA, without a database/apply path.

Only source-proven after balances and integral native allocations are admitted.
No current asset snapshot, fabricated collection, guessed historical balance or
changed runtime algorithm may fill an evidence gap. Failure retains the complete
base graph and reports a payment gate; partially generated payment rows are never
published. All resulting graphs still require rights/personnel/native validation.
"""
import argparse
import collections
from decimal import Decimal
import gzip
import json
import pathlib

from historical_sales_fact_plan import digest, load, schema_row


TABLES = ['cashier_v3_checkout_payment_draft', 'cashier_v3_payment_collection_batch',
          'cashier_v3_payment_collection', 'cashier_v3_payment_fact',
          'cashier_v3_payment_sale_allocation_fact', 'cashier_v3_balance_fact']


def cents(value):
    amount = Decimal(str(value)) * 100
    if amount != amount.to_integral_value():
        raise ValueError('source_money_precision_invalid')
    return int(amount)


def allocate_native(amount, sales):
    """Existing largest-remainder cent rule, with an integer-yuan admission gate.

    A migration must not silently replace the app's allocation rule. If its
    result contains fractional yuan, retain an explicit unresolved boundary.
    """
    weights = [int(s['sale_amount_cents'])-int(s['debt_amount_cents']) for s in sales]
    total = sum(weights)
    if amount < 0 or min(weights, default=0) < 0 or amount > total:
        raise ValueError('payment_allocation_total_invalid')
    if amount == 0:
        return [0] * len(weights)
    values = [amount*w//total for w in weights]
    residual = amount-sum(values)
    ranks = sorted(range(len(weights)), key=lambda i: (-(amount*weights[i] % total), i))
    for i in ranks[:residual]:
        values[i] += 1
    if any(x % 100 for x in values):
        raise ValueError('native_payment_allocation_requires_integer_boundary')
    return values


def compile_payments(plan, context, ledger_index, source_receipt_allocations=None):
    """Attach one source-backed payment graph, atomically at payload level."""
    tender = plan['tenderPlan']; tables = plan['baseTables']
    header = tables['cashier_v3_sales_order'][0]
    request = tables['cashier_v3_checkout_request'][0]
    sales = tables['cashier_v3_sale_fact']
    ident = plan['sourceOrderId']; stem = f'IMP007-{ident}'
    if tender['issues']:
        raise ValueError('payment_evidence_has_issues')
    if tender['totals']['historical_entry'] and (
            len(tender['tenders']) != 1 or tender['totals']['historical_entry'] != header['sale_amount_cents']):
        raise ValueError('legacy_non_cash_mixed_tender_unsupported_by_runtime')
    # Bank transfer now has its own actual-receipt code. Only ambiguous old
    # "other" labels still fail closed; the bank code must not be recast as other.
    # A zero-valued legacy label is evidence only: it creates no receipt or
    # performance and must not block an otherwise proven payment graph. The
    # audit keeps the label; every nonzero ambiguous amount still fails closed.
    if any(t.get('method') == 'other_collection' and t['amountCents'] != 0 for t in tender['tenders']):
        raise ValueError('other_collection_accounting_semantics_need_evidence')
    # Offline data import may preserve a proven original per-line receipt.
    # The normal compiler path remains unchanged. Callers must retain source
    # proof; accept no unknown tender, fractional amount or capacity overrun.
    if source_receipt_allocations is not None:
        expected_sources={t['sourceId'] for t in tender['tenders'] if t['kind']=='bookkeeping' and t['amountCents']>0}
        if set(source_receipt_allocations)!=expected_sources:
            raise ValueError('source_receipt_allocation_scope_mismatch')
    common_keys = ['tenant_id','organization_id','organization_path_snapshot','organization_name_snapshot',
                   'store_id','store_name_snapshot','member_id','member_name_snapshot','operator_id','operator_name_snapshot',
                   'business_date','business_timezone','occurred_at','settled_at','recorded_at','add_time','update_time',
                   'checkout_request_id','checkout_request_version','checkout_prepare_idempotency_key',
                   'command_idempotency_key','source_document_type','source_document_id','source_document_no_snapshot']
    common = {k: header[k] for k in common_keys if k in header}
    common.update(contract_version='legacy-import-data-v1', immutable_fingerprint=plan['evidenceSHA256'],
                  checkout_authority_fingerprint=header['checkout_authority_fingerprint'],
                  checkout_aggregate_fingerprint=header['checkout_aggregate_fingerprint'],
                  order_id=header['order_id'], order_no_snapshot=header['order_no'],
                  sales_order_id=header['order_id'], sales_order_no_snapshot=header['order_no'],
                  sales_order_fingerprint=header['immutable_fingerprint'],
                  business_event_no=stem+'-source-payment', fact_direction='forward', fact_version=1, status='effective')
    output = {t: [] for t in TABLES}
    def add(table, values):
        row = schema_row(context['schema'][table], common | values)
        for key, value in row.items():
            if key.endswith('_cents') and value is not None and int(value) % 100:
                raise ValueError('fractional_payment_fact:'+table+'.'+key)
        output[table].append(row)
        return row

    wallet = tender['totals']['wallet']; ledger_evidence = None
    if wallet:
        candidates = ledger_index.get(ident, [])
        matching = [x for x in candidates if int(x['uid']) == tender['sourceMemberId']
                    and int(x['pm']) == 0 and cents(x['number']) == wallet]
        if len(candidates) != 1 or len(matching) != 1:
            raise ValueError('original_wallet_after_snapshot_not_unique')
        ledger = matching[0]
        principal_after, bonus_after = cents(ledger['ben_money']), cents(ledger['give_money'])
        if min(principal_after, bonus_after) < 0:
            raise ValueError('original_wallet_after_negative_not_representable')
        if principal_after % 100 or bonus_after % 100:
            raise ValueError('original_wallet_after_requires_integer_boundary')
        if principal_after+bonus_after != cents(ledger['balance']):
            raise ValueError('original_wallet_after_components_do_not_close')
        split = tender['walletSplit']
        if not split or split['principalCents']+split['bonusCents'] != wallet:
            raise ValueError('wallet_split_does_not_close')
        if header['member_id'] <= 0:
            raise ValueError('wallet_target_member_missing')
        ledger_evidence = dict(ledger, splitRule=split['rule'])
        add('cashier_v3_balance_fact', dict(
            fact_id=stem+':balance', natural_key=stem+':balance',
            business_event_no=stem+':source-ledger:'+str(ledger['id']), fact_type='balance_deducted',
            source_line_id=sales[0]['source_line_id'], balance_change_type='order_pay',
            balance_account_id=str(header['member_id']), account_version=0,
            principal_delta_cents=-split['principalCents'], bonus_delta_cents=-split['bonusCents'],
            principal_after_cents=principal_after, bonus_after_cents=bonus_after))

    line_allocations = collections.Counter()
    for number, tender_row in enumerate(tender['tenders'], 1):
        if tender_row['kind'] == 'wallet':
            continue
        if tender_row['kind'] not in ['bookkeeping', 'historical_entry']:
            raise ValueError('unmapped_payment_tender_kind')
        amount = tender_row['amountCents']; method = tender_row['method']
        if amount <= 0:
            # Zero components have no economic event; retain them in tender audit.
            continue
        payment_id = stem+'-payment-'+str(number)
        authority = stem+'-source-'+str(number)
        fingerprint = digest([plan['evidenceSHA256'], tender_row])
        add('cashier_v3_checkout_payment_draft', dict(
            payment_draft_id=payment_id, request_id=request['request_id'], draft_version=1,
            payment_authority_key=authority, payment_method=method, amount_cents=amount,
            payment_fingerprint=fingerprint, draft_status='committed', sort_no=number,
            source_document_no=header['order_no'], operation_occurred_at=header['occurred_at']))
        if tender_row['kind'] == 'historical_entry':
            continue
        collection_id = stem+'-collection-'+str(number)
        add('cashier_v3_payment_collection', dict(
            collection_id=collection_id, collection_no=collection_id, natural_key=collection_id,
            batch_id=stem+':batch', checkout_payment_draft_id=payment_id,
            checkout_payment_authority_key=authority, checkout_payment_fingerprint=fingerprint,
            payment_line_no=number, checkout_payment_sort_no=number, payment_method=method,
            payment_method_name_snapshot=tender_row['sourceName'], amount_cents=amount,
            cash_performance_amount_cents=amount, payment_business_time_snapshot=header['occurred_at'],
            payment_recorded_at_snapshot=context['recordedAt'],
            collection_status='settled', collection_version=1, collection_direction='forward'))
        add('cashier_v3_payment_fact', dict(
            fact_id=payment_id, natural_key=payment_id, fact_type='payment_received',
            source_line_id=sales[0]['source_line_id'], payment_method=method,
            payment_authority_key=authority, collection_reference=collection_id, amount_cents=amount))
        if source_receipt_allocations is None:
            allocations = allocate_native(amount, sales)
        else:
            proven=source_receipt_allocations[tender_row['sourceId']]
            if set(proven)!={s['fact_id'] for s in sales}:raise ValueError('source_receipt_line_coverage_mismatch')
            allocations=[proven[s['fact_id']] for s in sales]
            if (any(type(v)is not int or v<0 or v%100 or v>sales[i]['sale_amount_cents']-sales[i]['debt_amount_cents'] for i,v in enumerate(allocations))
                    or sum(allocations)!=amount):raise ValueError('source_receipt_line_amount_invalid')
        for sale, allocation in zip(sales, allocations):
            allocation_id = digest([payment_id, sale['fact_id']])
            add('cashier_v3_payment_sale_allocation_fact', dict(
                allocation_fact_id=allocation_id, natural_key='legacy-allocation:'+allocation_id,
                payment_fact_id=payment_id, payment_method=method, payment_amount_cents=amount,
                sale_fact_id=sale['fact_id'], source_line_id=sale['source_line_id'],
                sale_amount_cents=sale['sale_amount_cents'], debt_amount_cents=sale['debt_amount_cents'],
                allocation_base_amount_cents=sale['sale_amount_cents']-sale['debt_amount_cents'],
                amount_cents=allocation))
            line_allocations[sale['fact_id']] += allocation
    if any(line_allocations[s['fact_id']] > s['sale_amount_cents']-s['debt_amount_cents'] for s in sales):
        raise ValueError('combined_payment_allocations_exceed_line')
    collections_ = output['cashier_v3_payment_collection']
    add('cashier_v3_payment_collection_batch', dict(
        batch_id=stem+':batch', natural_key=stem+':batch', collection_count=len(collections_),
        collected_amount_cents=sum(x['amount_cents'] for x in collections_),
        cash_performance_amount_cents=sum(x['cash_performance_amount_cents'] for x in collections_),
        receivable_amount_cents=header['sale_amount_cents'], batch_status='settled', batch_version=1, batch_direction='forward'))
    if sum(x['amount_cents'] for x in collections_) != tender['totals']['bookkeeping']:
        raise ValueError('collected_tender_total_mismatch')
    return output, ledger_evidence


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for key in ['context','base','output','summary']:
        parser.add_argument('--'+key, required=True)
    args = parser.parse_args(); context = load(args.context)
    ledger_index = collections.defaultdict(list)
    for row in context['walletLedgers']:
        ledger_index[int(row['link_id'])].append(row)
    counts = collections.Counter(); reasons = collections.Counter(); exceptions = []
    with gzip.open(args.base, 'rt', encoding='utf-8') as source, gzip.open(args.output, 'wt', encoding='utf-8') as target:
        for text in source:
            plan = json.loads(text); counts['baseGraphs'] += 1
            try:
                rows, evidence = compile_payments(plan, context, ledger_index)
                plan['baseTables'].update(rows)
                plan['paymentEvidence'] = {'sourceWalletLedger': evidence, 'nativeCentAllocationIntegral': True}
                plan['pending'].remove('payment_and_balance_facts')
                counts['paymentGraphsCompiled'] += 1
                for table, values in rows.items():
                    counts[table] += len(values)
            except ValueError as exc:
                reason = str(exc); reasons[reason] += 1
                plan['paymentIssues'] = [reason]
                exceptions.append({'sourceOrderId': plan['sourceOrderId'], 'reason': reason})
            assert plan['readyForApply'] is False and plan['pending']
            target.write(json.dumps(plan, ensure_ascii=False, separators=(',', ':'))+'\n')
    report = dict(counts=dict(counts), reasons=dict(reasons), exceptions=exceptions,
                  readyForApply=False, productionWrites=0,
                  limitations=['Payments only; unresolved rights/personnel/audit gates remain.',
                               'No current member balance changes or payment replay.'])
    pathlib.Path(args.summary).write_text(json.dumps(report, ensure_ascii=False, indent=2)+'\n')
    print(json.dumps({k: v for k, v in report.items() if k != 'exceptions'}, ensure_ascii=False))


if __name__ == '__main__':
    main()
