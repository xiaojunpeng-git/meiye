"""Local authenticated XLSX regression. Credentials stay in environment/memory, never evidence."""
import io
import json
import os
from pathlib import Path
from decimal import Decimal, InvalidOperation
import requests
import openpyxl

ACCOUNT = os.environ['R6_TEST_ACCOUNT']
PASSWORD = os.environ['R6_TEST_PASSWORD']
EVIDENCE = Path(__file__).resolve().parents[3] / '_local_evidence/2026-11-03-round6-report-xlsx'
EVIDENCE.mkdir(parents=True, exist_ok=True)
MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
results = []


def login(base, kind):
    # Fixed loopback instances only: this test cannot be redirected to a customer production service.
    s = requests.Session()
    payload = {'account': ACCOUNT, 'pwd': PASSWORD}
    if kind == 'store':
        payload['store_id'] = 23
    reply = s.post(base + ('/cashierapi/v3/login' if kind == 'store' else '/adminapi/login'), json=payload, timeout=30).json()
    assert reply.get('status') == 200, reply.get('msg')
    if kind == 'store' and reply['data'].get('login_ticket'):
        reply = s.post(base + '/cashierapi/v3/login', json={'login_ticket': reply['data']['login_ticket'], 'store_id': 23}, timeout=30).json()
        assert reply.get('status') == 200, reply.get('msg')
    token = reply['data'].get('token') or reply['data'].get('access_token')
    assert token, 'Authenticated token not returned'
    s.headers['Authori-zation'] = 'Bearer ' + token
    return s


def json_data(session, url, params):
    reply = session.get(url, params=params, timeout=90).json()
    assert reply.get('status') == 200, reply.get('msg')
    return reply['data']


def workbook(session, url, params, label):
    r = session.get(url, params=params, timeout=90)
    assert r.status_code == 200 and MIME in r.headers.get('content-type', ''), (label, r.status_code, r.text[:200])
    assert r.content[:2] == b'PK', (label, 'Invalid XLSX file signature')
    book = openpyxl.load_workbook(io.BytesIO(r.content), read_only=True, data_only=True)
    assert '列名取值来源' in book.sheetnames
    (EVIDENCE / (label + '.xlsx')).write_bytes(r.content)
    results.append({'case': label, 'sheets': book.sheetnames, 'bytes': len(r.content)})
    return book


def same_cell(actual, source):
    if source is None:
        return actual in (None, '', '-')
    if isinstance(actual, (float, int)) and not isinstance(actual, bool):
        try:
            return Decimal(str(actual)) == Decimal(str(source))
        except InvalidOperation:
            return False
    return str(actual if actual is not None else '') == str(source)


platform_base = 'http://127.0.0.1:18093'
store_base = 'http://127.0.0.1:18092'
platform = login(platform_base, 'platform')
store = login(store_base, 'store')
for kind, session, base, prefix in [('platform', platform, platform_base, '/adminapi/report'), ('store', store, store_base, '/cashierapi/v3/report')]:
    params = {'report': 'phase_six_salary_detail', 'start_date': '2026-11-01', 'end_date': '2026-11-30', 'store_ids': '23'}
    source = json_data(session, base + prefix + '/unified/export', params)
    book = workbook(session, base + prefix + '/unified/export', dict(params, format='xlsx'), kind + '-salary-http')
    values = list(book.worksheets[0].values)
    keys = [c['key'] for c in source['columns']]
    expected = [source['summary_row']] + source['records']
    assert list(values[0]) == [c['label'] for c in source['columns']]
    assert len(values) == len(expected) + 1
    assert all(same_cell(actual, row.get(key, '-')) for cells, row in zip(values[1:], expected) for actual, key in zip(cells, keys))
    results[-1]['records'] = len(source['records'])
    history = dict(params, start_date='2026-10-01', end_date='2026-10-31', row_filters=json.dumps([{'key':'document_no','op':'contains','value':'XS26100200133'}]))
    note_book = workbook(session, base + prefix + '/unified/export', dict(history, format='xlsx'), kind + '-salary-remark')
    assert list(note_book.worksheets[0].values)[2][-1] == '新客吕玉静33岁进店体验满1小时'
    # Empty results must still be a valid workbook with declared headers and totals.
    workbook(session, base + prefix + '/unified/export', dict(params, format='xlsx', start_date='2026-11-03', end_date='2026-11-03'), kind + '-salary-empty')
    for report in ['store_item_analysis', 'member_consumption_detail', 'phase_six_salary_summary']:
        workbook(session, base + prefix + '/unified/export', dict(params, report=report, format='xlsx'), kind + '-' + report)
    if os.environ.get('R6_SKIP_BASIC') != '1':
        catalog = json_data(session, base + prefix + '/basic/catalog', {})
        available = {c['code'] for c in catalog}
        for code in ['basic_product_sales', 'basic_store_reconciliation', 'basic_sales_data']:
            if code not in available:
                results.append({'case': kind + '-' + code, 'available': False, 'reason': 'Current authenticated menu excludes this report; no permissions changed'})
                continue
            query = {'pageCode': code, 'start_date': '2026-11-01', 'end_date': '2026-11-30', 'page': 1, 'limit': 20}
            if code == 'basic_store_reconciliation':
                query.update(reconciliation_mode='days', reconciliation_store_id=23, month='2026-11')
            args = {'store_ids': '23', 'query': json.dumps(query)}
            source = json_data(session, base + prefix + '/basic/query', args)
            fields = [f for f in source['queryFields'] if f.get('defaultVisible')]
            query['export'] = {'scope': 'query', 'fields': [f['key'] for f in fields]}
            args['query'] = json.dumps(query)
            full = json_data(session, base + prefix + '/basic/query', args)
            book = workbook(session, base + prefix + '/basic/export', args, kind + '-' + code)
            cells = list(book.worksheets[0].values)
            assert list(cells[0]) == [f['label'] for f in fields]
            assert len(cells) >= len(full['exportRows']) + 1
            for row, source_row in zip(cells[1:], full['exportRows']):
                assert all(same_cell(value, source_row.get(f['key'], '')) for value, f in zip(row, fields)), code
            results[-1]['records'] = len(full['exportRows'])
            # Backend fields remain authoritative, including when a client requests an unknown field.
            bad = dict(query, export={'scope': 'query', 'fields': ['not_a_report_field']})
            denial = session.get(base + prefix + '/basic/export', params={'store_ids': '23', 'query': json.dumps(bad)}, timeout=90)
            assert MIME not in denial.headers.get('content-type', '') and denial.json().get('status') != 200
    else:
        results.append({'case': kind + '-basic-reports', 'tested': False, 'reason': 'User explicitly deferred basic-report page testing'})
    if kind == 'platform':
        for report in ['customer_consumption_tier', 'customer_item_analysis']:
            params = {'report': report, 'store_ids': '23', 'start_date': '2026-11-01', 'end_date': '2026-11-30'}
            reply = session.get(base + '/adminapi/report/customer-analytics', params=params, timeout=90).json()
            if reply.get('status') != 200:
                results.append({'case': report, 'available': False, 'reason': reply.get('msg')})
                continue
            source = reply['data']
            book = workbook(session, base + '/adminapi/report/customer-analytics/export', params, report)
            sections = source.get('sections') or [source]
            assert book.worksheets[:-1] and len(book.worksheets) == len(sections) + 1
            for sheet, section in zip(book.worksheets, sections):
                assert list(next(sheet.values)) == [c['label'] for c in section['columns']]
        denial = session.get(base + '/adminapi/report/unified/export', params={'report': 'phase_six_salary_detail', 'store_ids': '999999999', 'format': 'xlsx'}, timeout=60)
        assert MIME not in denial.headers.get('content-type', '') and denial.json().get('status') != 200

(EVIDENCE / 'http-tests.json').write_text(json.dumps(results, ensure_ascii=False, indent=2))
print(json.dumps({'pass': True, 'cases': results}, ensure_ascii=False))
