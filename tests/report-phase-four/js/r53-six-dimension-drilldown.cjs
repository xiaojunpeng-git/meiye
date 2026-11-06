const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { createRequire } = require('node:module');
const root = path.resolve(__dirname, '../../..');
const projectRequire = createRequire(path.join(root, '前端代码/cashier-v3/package.json'));
const { parse, compileScript, compileTemplate } = projectRequire('@vue/compiler-sfc');
const filename = path.join(root, '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue');
const { descriptor } = parse(fs.readFileSync(filename, 'utf8'), { filename });
const compiled = compileScript(descriptor, { id: 'r53' });
assert.deepEqual(compileTemplate({ filename, id: 'r53', source: descriptor.template.content,
  compilerOptions: { bindingMetadata: compiled.bindings } }).errors, []);

// 执行真实页面方法，隔离接口传输；不以正则匹配代替筛选、分页和运行时行为验证。
const names = ['openSixDimensionDetail', 'bdeghDetailParams', 'canDrilldown', 'canSummaryReceiptDrilldown',
  'loadBdeghDetail', 'receiptDetailPeriod', 'detailDialogCell', 'detailDialogFilter', 'detailDialogPage',
  'closeDetailDialog', 'closeBdeghDetail', 'exportDetailDialog'];
const code = compiled.scriptSetupAst.filter(node => node.type === 'FunctionDeclaration' && names.includes(node.id.name))
  .map(node => descriptor.scriptSetup.content.slice(node.start, node.end)).join('\n');
const ref = value => ({ value });
const calls = [];
const ctx = {
  bdeghDetail: ref({}), sixDimensionAppliedParams: ref({ report: 'six_dimension_analysis', start_date: '2026-10-01',
    end_date: '2026-10-31', store_ids: '132,84', category_id: '5', row_filters: '[{"key":"store_name","op":"contains","value":"店"}]' }),
  detailMemberFilter: ref('旧条件'), detailOrderFilter: ref('旧单号'), DEFAULT_LIMIT: 20,
  reportRuntime: ref('store'), bdeghDetailRequestId: 0, detailExportRequestId: 0,
  detailExporting: ref(false), detailExportError: ref(''), memberVisitDetail: ref({ open: false }),
  detailDialog: ref({ open: true, loading: false, title: '六维明细' }), loading: ref(false), activeReport: ref('six_dimension_analysis'),
  detailPopupOpeners: { six_dimension_analysis: true }, isPlatformReport: ref(false),
  drilldownConfig: (_, c) => c.drilldown, drilldownRequest: () => null,
  formatWholeYuan: value => String(Math.round(Number(value))),
  reportParams: () => { throw Error('不得读取尚未查询的草稿'); },
  queryStoreBusinessReport: async (params, runtime) => {
    calls.push({ params, runtime });
    return { records: [{ member_name: '本地验证', detail_value: 1 }], columns: [{ key: 'detail_value', label: '计入数值' }],
      total: 27, page: params.page, detail_footer: '共27条 · 人数：27', summary_row: { detail_value: 27 } };
  },
  exportStoreBusinessReportXlsx: async (params, runtime) => { calls.push({ params, runtime, export: true }); return 'xlsx'; },
  savedFiles: [], today: () => '2026-11-07',
  saveReportWorkbook: (blob, name) => { ctx.savedFiles.push({blob, name}); },
};
vm.createContext(ctx); vm.runInContext(code, ctx);
const column = { key: 'new_visit_people', label: '新客人数', header_path: ['见诊人数', '新客人数'], drilldown: { report: 'six_dimension_analysis' } };
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  for (const runtime of ['store', 'platform']) {
    ctx.reportRuntime.value = runtime; ctx.isPlatformReport.value = runtime === 'platform';
    assert.equal(ctx.canDrilldown({ new_visit_people: 0 }, column), true);
    assert.equal(ctx.canDrilldown({ new_visit_people: '-' }, column), false);
    assert.equal(ctx.canSummaryReceiptDrilldown({ new_visit_people: 27 }, column), true);
    ctx.openSixDimensionDetail({ detail_store_key: 'stable-group', new_visit_people: 27 }, column);
    await tick();
    assert.equal(ctx.detailMemberFilter.value, '');
    assert.equal(calls.at(-1).runtime, runtime);
    assert.equal(calls.at(-1).params.detail_store_key, 'stable-group');
    assert.equal(calls.at(-1).params.start_date, '2026-10-01');
    assert.equal(ctx.bdeghDetail.value.detailFooter, '共27条 · 人数：27');
    assert.equal(ctx.bdeghDetail.value.label, '见诊人数 · 新客人数');
    const original = ctx.sixDimensionAppliedParams.value;
    ctx.sixDimensionAppliedParams.value = { ...original, start_date: '2026-11-01' };
    ctx.detailDialogFilter({ member: '本地验证', orderNo: 'FW' }); await tick();
    assert.equal(calls.at(-1).params.page, 1);
    assert.equal(calls.at(-1).params.start_date, '2026-10-01');
    assert.equal(calls.at(-1).params.detail_member, '本地验证');
    assert.equal(calls.at(-1).params.row_filters, original.row_filters);
    ctx.detailDialogPage(2); await tick();
    assert.equal(calls.at(-1).params.page, 2);
    assert.equal(calls.at(-1).params.detail_store_key, 'stable-group');
    assert.equal(ctx.receiptDetailPeriod(), '2026-10-01 至 2026-10-31');
    await ctx.exportDetailDialog();
    assert.equal(calls.at(-1).export, true);
    assert.equal(ctx.savedFiles.at(-1).name, '六维明细-20261107.xlsx');
    assert.equal(calls.at(-1).params.detail_member, '本地验证');
    assert.equal(calls.at(-1).params.start_date, '2026-10-01');
    assert.equal(ctx.detailDialogCell({ detail_value: '33.59', value_unit: '元' }, { key: 'detail_value' }), '34');
    assert.equal(ctx.detailDialogCell({ detail_value: 1, value_unit: '人' }, { key: 'detail_value' }), '1');
    ctx.closeDetailDialog();
    assert.equal(ctx.bdeghDetail.value.open, false);
    assert.equal(ctx.activeReport.value, 'six_dimension_analysis');
    ctx.sixDimensionAppliedParams.value = original;
    ctx.openSixDimensionDetail({ new_visit_people: 27 }, column); await tick();
    assert.equal(calls.at(-1).params.detail_store_key, 'all');
    ctx.closeDetailDialog();
  }
  console.log('PASS 双端真实页面方法：数值点击、零值、非数值、合计、固定查询、筛选、分页、导出、金额显示、关闭返回；Vue编译通过');
})().catch(error => { console.error(error); process.exitCode = 1; });
