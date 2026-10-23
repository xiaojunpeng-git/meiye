/* 成长统计业务契约：服务器分页/全量合计/精确下钻/导出同源，经营开关和六项来源独立。 */
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const base = 'http://127.0.0.1:18115';
const key = () => `report-${randomUUID()}`;
(async () => {
  const l = await (
    await fetch(base + '/adminapi/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ account: 'growth_admin', pwd: 'GrowthLocal!2026' })
    })
  ).json();
  assert.equal(l.status, 200);
  const h = {
    'Authori-zation': 'Bearer ' + l.data.token,
    'X-Source': 'f76d38d0ee4f854f',
    'Content-Type': 'application/json'
  };
  async function api(p, d, fail = false) {
    const response = await fetch(base + '/adminapi/growth/' + p, {
      method: d ? 'POST' : 'GET',
      headers: h,
      body: d ? JSON.stringify({ ...d, idempotency_key: key() }) : undefined
    });
    const r = await response.json();
    if (!fail) assert.equal(r.status, 200, p + ': ' + r.msg);
    return fail ? r : r.data;
  }
  const filters = await api('report/filters');
  assert.ok(filters.tree.length);
  const range = 'start=2026-01-01&end=2026-12-31';
  const first = await api('report?' + range);
  assert.ok(first.total > 20);
  assert.equal(first.records.length, 20);
  const second = await api('report?' + range + '&page=2');
  assert.equal(second.records.length, Math.min(20, first.total - 20));
  assert.deepEqual(first.totals, second.totals);
  assert.equal(first.table_layout.fixed, true);
  assert.ok(first.columns.every((c) => c.value_source));
  assert.ok(first.totals.completed_tasks <= first.totals.required_tasks);
  const empty = await api('report?' + range + '&employee_id=999999');
  assert.equal(empty.total, 0);
  assert.equal(empty.total_pages, 1);
  assert.equal(empty.totals.completion_rate, null);
  const completed = await api('report?' + range + '&state=COMPLETED');
  assert.ok(completed.records.every((r) => r.state === 'COMPLETED'));
  const row = first.records[0];
  const drill = await api('report/drill?' + range + '&report=tasks&id=' + row.id);
  assert.equal(Number(drill.record.id), Number(row.id));
  const wrong = await api(
    'report/drill?' + range + '&report=tasks&employee_id=999999&id=' + row.id,
    undefined,
    true
  );
  assert.notEqual(wrong.status, 200);
  const ex = await api('report/export', { report: 'tasks', start: '2026-01-01', end: '2026-12-31' });
  assert.equal(ex.row_count, first.total);
  const csv = await fetch(base + '/adminapi/growth/report/file?id=' + ex.id, { headers: h });
  assert.match(csv.headers.get('content-type'), /text\/csv/);
  const text = await csv.text();
  assert.ok(text.includes('完整筛选合计'));
  assert.ok(text.includes(first.columns[0].value_source));
  for (const type of ['exams', 'issues', 'points']) {
    const r = await api('report?' + range + '&report=' + type);
    assert.equal(r.report, type);
    assert.ok(r.total >= 0);
  }
  const overview = await api('overview?' + range);
  assert.deepEqual(overview.tasks, first.totals);
  const settings = await api('settings');
  try {
    const templates = JSON.parse(settings.templates_json || '{}');
    await api('settings', { enabled: true, business_link_enabled: false, templates });
    assert.equal((await api('business?object_type=employee&object_id=91002')).enabled, false);
    await api('settings', { enabled: true, business_link_enabled: true, templates });
    const employee = await api(
      'business?object_type=employee&object_id=91002&start=2026-10-01&end=2026-10-31'
    );
    assert.deepEqual(
      employee.metrics.map((m) => m.code),
      ['staff_sales_yeji', 'staff_labor_yeji', 'staff_service_num']
    );
    const store = await api('business?object_type=store&object_id=91001&start=2026-10-01&end=2026-10-31');
    assert.deepEqual(
      store.metrics.map((m) => m.code),
      ['staff_sales_yeji', 'consume_amount', 'store_customer_visit']
    );
    assert.ok(store.metrics.every((m) => m.value_source && typeof m.raw_value === 'number'));
    const review = await api('review/save', {
      title: '同口径成长复盘-' + Date.now(),
      object_type: 'employee',
      object_id: 91002,
      baseline_start: '2026-09-01',
      baseline_end: '2026-09-30',
      followup_start: '2026-10-01',
      followup_end: '2026-10-31',
      metrics: ['staff_sales_yeji'],
      background: '本地隔离样例，无实际经营事实',
      judgment: '需要结合服务机会观察，不能据此归因',
      next_steps: '负责人安排进一步练习'
    });
    const list = await api('reviews');
    const saved = list.rows.find((r) => Number(r.id) === review.id);
    assert.ok(saved);
    assert.equal(saved.snapshot.baseline.metrics.length, 3);
    assert.equal(saved.snapshot.followup.metrics.length, 3);
    assert.match(saved.judgment, /不能据此归因/);
  } finally {
    await api('settings', {
      enabled: !!Number(settings.enabled),
      business_link_enabled: !!Number(settings.business_link_enabled),
      templates: JSON.parse(settings.templates_json || '{}')
    });
  }
  console.log(
    JSON.stringify(
      {
        passed: [
          '超过20条服务器分页',
          '页码变化合计不变',
          '完整列来源元数据',
          '空分母不显示零率',
          '状态筛选同源',
          '精确下钻',
          '下钻不能突破筛选',
          '全量导出及来源说明',
          '四类统计源',
          '工作台复用统计',
          '独立模式隐藏经营',
          '员工三指标原读取器',
          '门店三指标原资格',
          '复盘数据库保存与刷新读回'
        ],
        task_total: first.total,
        export_id: ex.id
      },
      null,
      2
    )
  );
})().catch((e) => {
  console.error(e);
  process.exitCode = 1;
});
