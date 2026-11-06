#!/usr/bin/env python3
"""Compile historical service evidence without replaying checkout or changing live rights.

Each old writeoff owns a unique request. Sales lines retain their original identity
for sale-void exclusion, while the request prevents repeated visits multiplying
category joins. Unknown current employee IDs remain zero with original names and
source staff IDs in evidence; never match people by name.
"""
import argparse
import collections
import datetime as dt
import hashlib
import json
from decimal import Decimal
from pathlib import Path
from historical_sales_fact_plan import schema_row, digest


def cents(value):
    number = Decimal(str(value))
    if number < 0 or number != number.to_integral_value():
        raise ValueError('SOURCE_NOT_WHOLE_YUAN')
    return int(number) * 100


def compile_plan(source):
    """Require exact original IDs; deleted metadata stays visibly unknown, never reconstructed by name."""
    tables = source['tables']
    index = {name: {int(r['uid'] if name == 'user' else r['id']): r for r in rows}
             for name, rows in tables.items() if rows and 'id' in rows[0] or name == 'user'}
    holders = collections.defaultdict(list)
    for row in tables['user_card_holder']:
        holders[int(row['oid'])].append(row)
    sales = {int(r['source_document_id']): r for r in tables['cashier_v3_sales_order']}
    lines = {r['order_line_id']: r for r in tables['cashier_v3_sales_order_line']}
    bindings = {int(r['store_id']): int(r['org_id']) for r in tables['organization_store']}
    labor = collections.defaultdict(list)
    for row in tables['staff_yeji']:
        if int(row['type']) == 3 and int(row['status']) == 0:
            labor[int(row['link_id'])].append(row)
    voids = {r['source_order_id'] for r in tables['cashier_v3_order_lifecycle_operation']}
    historical_staff = {int(r['id']): r for r in source.get('historical_staff', [])}
    rows = collections.defaultdict(list)
    counts = collections.Counter()
    issues = []
    source_links = []
    for w in source['missing']:
        wid, oid, uid, store = (int(w[k]) for k in ['id', 'oid', 'uid', 'relation_id'])
        try:
            amount = cents(w['writeoff_price'])
            order = index['store_order'].get(oid)
            detail = index['store_order_cart_info'].get(int(w['order_cart_id']))
            holder_list = holders[oid]
            if len(holder_list) > 1:
                raise ValueError('AMBIGUOUS_HOLDER')
            holder = holder_list[0] if holder_list else None
            sale = sales.get(oid)
            line = lines.get(f'IMP007-{oid}-line-{w["order_cart_id"]}')
            if sale and sale['order_id'] in voids:
                raise ValueError('V3_SALE_VOID')
            if order and int(order['uid']) != uid:
                raise ValueError('ORDER_MEMBER_CONFLICT')
            if detail and (int(detail['oid']) != oid or int(detail['product_id']) != int(w['product_id'])):
                raise ValueError('DETAIL_PRODUCT_CONFLICT')
            if holder and int(holder['uid']) != uid:
                raise ValueError('HOLDER_MEMBER_CONFLICT')
            if line and (int(line['member_id']) != uid or str(line['item_id']) != str(w['product_id']) or line['item_type'] != 'project'):
                raise ValueError('SALE_LINE_CONFLICT')
            if int(w['status']) != 0 or int(w['writeoff_num']) <= 0:
                raise ValueError('NOT_COMPLETED')
            if w['service_object'] not in ['本人', 'self', '朋友', 'friend']:
                raise ValueError('UNKNOWN_SERVICE_OBJECT')
            # PM explicitly retained deleted store 31 in historical HQ scope.
            # No current organization or other store may be invented for it.
            org_id = bindings.get(store, 0)
            if not org_id and store != 31:
                raise ValueError('STORE_ORGANIZATION_UNKNOWN')
            org = index['organization'].get(org_id, {'name': ''})
            path = []
            cursor = org_id
            while cursor:
                if cursor in path:
                    raise ValueError('ORG_CYCLE')
                path.insert(0, cursor)
                cursor = int(index['organization'][cursor]['pid'])
            member = index['user'].get(uid, {})
            operator = index['system_store_staff'][int(w['staff_id'])]
            product = index['store_product'].get(int(w['product_id']), {})
            snapshot = json.loads(detail['cart_info']) if detail else {}
            original = snapshot.get('productInfo', {})
            if original and int(original.get('id', 0)) != int(w['product_id']):
                original = {}
            name = (line or {}).get('item_name_snapshot') or original.get('store_name') or product.get('store_name', '')
            # Deleted product and cart metadata do not erase the original
            # completed writeoff. Keep the name empty and retain its exact ID.
            raw_category = (line or {}).get('category_id_snapshot') or original.get('cate_id') or product.get('cate_id', 0)
            category_id = int(raw_category) if str(raw_category).isdigit() else 0
            category_path = []
            cursor = category_id
            seen = set()
            while cursor:
                if cursor in seen or cursor not in index['store_product_category']:
                    category_path = []; category_id = 0; break
                seen.add(cursor)
                cat = index['store_product_category'][cursor]
                category_path.insert(0, cat['cate_name'])
                cursor = int(cat['pid'])
            stem = f'IMP007-W{wid}'
            source_line = line['order_line_id'] if line and not holder else stem + '-line'
            request = stem + '-request'
            at = int(w['add_time'])
            date = dt.datetime.fromtimestamp(at, dt.timezone(dt.timedelta(hours=8))).date().isoformat()
            common = {k: v for k, v in (sale or {}).items() if k.startswith('business_source_') or k == 'source_attribution_type_snapshot'}
            common.update(tenant_id='0', organization_id=str(org_id), organization_name_snapshot=org['name'],
                organization_path_snapshot=','.join(map(str, path)), store_id=store,
                store_name_snapshot=index['system_store'][store]['name'], member_id=uid,
                member_name_snapshot=(sale or {}).get('member_name_snapshot') or member.get('real_name') or member.get('nickname') or (order or {}).get('real_name', ''),
                operator_id=int(w['staff_id']), operator_name_snapshot=operator['staff_name'],
                business_date=date, business_timezone='Asia/Shanghai', occurred_at=at, settled_at=at,
                recorded_at=0, checkout_request_id=request, source_line_id=source_line,
                source_document_type='legacy_paid_project' if line and not holder else 'legacy_writeoff',
                source_document_id=wid, document_id=(sale['order_id'] if line and not holder else stem),
                document_no_snapshot=w['writeoff_code'], business_event_no=stem+'-source-recovery',
                command_idempotency_key=stem+'-recovery', immutable_fingerprint='',
                project_id=int(w['product_id']), project_name_snapshot=name,
                project_category_id_snapshot=category_id,
                project_category_name_snapshot=category_path[-1] if category_path else '',
                project_category_path_snapshot='/'.join(category_path), quantity=int(w['writeoff_num']),
                project_count=1, service_object='friend' if w['service_object'] in ['朋友','friend'] else 'self',
                friend_counts_as_customer=1, is_experience=int((line or {}).get('is_experience', 0)))
            people = sorted(labor[wid], key=lambda r: (int(r['staff_id']), int(r['id'])))
            if len({int(p['staff_id']) for p in people}) != len(people):
                raise ValueError('DUPLICATE_SOURCE_STAFF')
            crafts = []
            # The old report SUM(yeji) ignores NULL. Preserve NULL in the
            # craftsman evidence and create no amount fact for an unknown value.
            total_labor = sum(cents(p['yeji']) for p in people if p['yeji'] is not None)
            for position, person in enumerate(people):
                if any(int(person[k]) != v for k,v in [('order_id',oid),('goods_id',int(w['product_id'])),('store_id',store)]):
                    raise ValueError('LABOR_SOURCE_IDENTITY')
                who = index['system_store_staff'].get(int(person['staff_id']),
                    historical_staff.get(int(person['staff_id']), {}))
                employee = int(who.get('employee_id', 0))
                if int(person['is_dian']) not in [0,1]:
                    raise ValueError('LABOR_TYPE_UNKNOWN')
                share = 100 // len(people) + ((100 % len(people)) if position == len(people)-1 else 0)
                crafts.append(dict(employeeId=employee, sourceStaffId=int(person['staff_id']),
                    sourceLaborId=int(person['id']), name=person['staff_name'],
                    historicalIdentityUnmapped=employee == 0, isPointCustomer=bool(int(person['is_dian'])),
                    performanceAmountCents=cents(person['yeji']) if person['yeji'] is not None else None,
                    historicalAmountUnknown=person['yeji'] is None, laborFeeCents=0,
                    projectCount=format(Decimal(share)/100, '.6f')))
            common['craftsmen_snapshot_json'] = json.dumps(crafts, ensure_ascii=False, separators=(',', ':'))
            service = common | dict(service_fact_id=stem+'-service', natural_key=stem+'-service',
                # Legacy human-readable writeoff codes are not unique. The
                # original code remains document_no_snapshot for audit.
                service_record_no=stem, service_status='completed',
                labor_amount_cents=total_labor, labor_fee_amount_cents=0,
                detail_remark_snapshot='历史核销恢复；原核销ID='+str(wid))
            current = [('cashier_v3_entitlement_service_fact', service)]
            perf = common | dict(fact_type='performance_recorded', fact_direction='forward', fact_version=1,
                status='effective', order_id=sale['order_id'] if line and not holder else stem,
                order_no_snapshot=(order or {}).get('order_id', w['writeoff_code']),
                allocation_weight_numerator=1, allocation_weight_denominator=1,
                rule_code_snapshot='legacy_service_recovery_680', rule_name_snapshot='历史核销完整来源恢复',
                rule_version_snapshot='legacy-restoration-v1')
            current.append(('cashier_v3_performance_fact', perf | dict(fact_id=stem+'-consumption',
                natural_key=stem+'-consumption', performance_type='consumption_performance_recorded',
                amount_cents=amount, allocation_base_amount_cents=amount)))
            for person, craft in zip(people, crafts):
                if person['yeji'] is None:
                    continue
                who = index['system_store_staff'].get(int(person['staff_id']),
                    historical_staff.get(int(person['staff_id']), {}))
                ident = stem+'-labor-'+str(person['id'])
                current.append(('cashier_v3_performance_fact', perf | dict(fact_id=ident, natural_key=ident,
                    performance_type='labor_performance_allocated', employee_id=craft['employeeId'],
                    employee_name_snapshot=craft['name'], employee_type_snapshot=(
                        'partner' if int(who['is_hezuofang']) else 'internal') if who.get('is_hezuofang') in [0,1,'0','1'] else '',
                    role_snapshot='craftsman:'+('point' if craft['isPointCustomer'] else 'round'),
                    amount_cents=craft['performanceAmountCents'], allocation_base_amount_cents=total_labor,
                    allocation_weight_numerator=craft['performanceAmountCents'], allocation_weight_denominator=max(1,total_labor),
                    project_count_decimal=craft['projectCount'])))
            if holder:
                previous = sum(int(x['writeoff_num']) for x in tables['store_order_writeoff']
                    if int(x['oid']) == oid and int(x['status']) == 0
                    and (int(x['add_time']), int(x['id'])) < (at,wid))
                current.append(('cashier_v3_entitlement_writeoff_fact', common | dict(
                    writeoff_id=stem+'-writeoff', natural_key=stem+'-writeoff', status='effective',
                    entitlement_instance_type='card_holder', entitlement_instance_id=int(holder['id']),
                    source_kind='card', holder_id=int(holder['id']), origin_order_id=oid,
                    source_detail_id=int(w['order_cart_id']), source_name_snapshot=holder['card_name'],
                    source_code_snapshot=holder['card_no'], actual_entitlement_amount_cents=amount,
                    purchase_amount_cents_snapshot=int(sale['sale_amount_cents']) if sale else cents(order['pay_price']),
                    total_purchase_times_snapshot=int(holder['write_times']), consumed_times_before_snapshot=previous,
                    amount_calculation_version_snapshot='legacy-import-preserved-v1', occupation_snapshot_json='[]')))
            # Freeze exact source identifiers for missing/deleted metadata and unmapped employees.
            link = dict(writeoff_id=wid, source_order_id=oid, source_detail_id=int(w['order_cart_id']),
                holder_id=int(holder['id']) if holder else None, source_sha256=digest([w, order, detail, holder, people]),
                missing_order=order is None, missing_detail=detail is None,
                missing_member=not member, original_labor_staff=[c['sourceStaffId'] for c in crafts],
                unmapped_labor_rows=sum(c['historicalIdentityUnmapped'] for c in crafts))
            payload = json.dumps(link | {'historicalImport': True, 'noReplay': True}, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
            current.append(('cashier_v3_business_event', common | dict(
                event_no=stem+'-source-recovery', event_key=digest(['recovery680',wid]),
                event_type='service.completed', event_version=1, aggregate_type='service_record',
                aggregate_id=stem+'-service', aggregate_version=1, source_type='legacy_service_import',
                source_id=request, source_detail_id=source_line, organization_path=','.join(map(str,path)),
                aggregate_name_snapshot=name, payload=payload, payload_sha256=hashlib.sha256(payload.encode()).hexdigest(),
                route_fingerprint=digest(['historical_data_no_dispatch',wid]), created_at=0, updated_at=0)))
            compiled = [(table, schema_row(source['schema'][table], values)) for table, values in current]
            source_links.append(link)
            for table, values in compiled:
                rows[table].append(values)
            counts['services'] += 1
            counts['consumption_cents'] += amount
            counts['labor_rows'] += sum(p['yeji'] is not None for p in people)
            counts['unknown_labor_amount_rows'] += sum(p['yeji'] is None for p in people)
            counts['labor_cents'] += total_labor
            counts['unmapped_labor_rows'] += link['unmapped_labor_rows']
            counts['card_writeoffs'] += int(holder is not None)
        except (ValueError, KeyError, TypeError) as error:
            issues.append(dict(writeoff_id=wid, issue=str(error)))
    return dict(target=source['target'], source_sha256=digest(source), insert_tables=dict(rows),
                counts=dict(counts), source_links=source_links, issues=issues, production_writes=0)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    plan = compile_plan(json.loads(Path(args.source).read_text()))
    plan['plan_sha256'] = digest(plan)
    Path(args.output).write_text(json.dumps(plan, ensure_ascii=False, separators=(',', ':')))
    print(json.dumps({k:plan[k] for k in ['counts','issues','plan_sha256','production_writes']}, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
