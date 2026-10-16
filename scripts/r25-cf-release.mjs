import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'

// Reproduce R25 from the accepted source while retaining each live entry's own frontend baseline.
// Never package the shared dirty checkout or copy customer files back into the source tree.
const root = process.cwd()
const output = process.env.R25_OUTPUT || '/private/tmp/mohe-r25-cf-release'
const r24 = '/private/tmp/mohe-r25-r24-repro'
const view = '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue'
const service = '后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php'
const controller = '后端代码/app/controller/admin/v1/report/UnifiedReport.php'
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const run = (file, args, options = {}) => execFileSync(file, args, { cwd: root, encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, ...options })
const read = file => fs.readFileSync(file, 'utf8')
const write = (file, data) => { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, data) }
const replace = (text, before, after, label) => {
  const first = text.indexOf(before)
  if (first < 0 || text.indexOf(before, first + before.length) >= 0) throw Error(`Expected one ${label}`)
  return text.slice(0, first) + after + text.slice(first + before.length)
}
const current = {
  [service]: '9b5376600c9700351a91cf0b994ce8693892130ecb21aeac7a7661590013644b',
  [controller]: 'eeabc0a4d4ddae1d29c5ce8b67b3b3c9a702879e66211e968fb8f4db2a2e56ee',
  [view]: 'c60562bc4b04dc95029e1279ab55634ceb0522a8e14b84e29fc3a767f9cdf4d3'
}
for (const [file, digest] of Object.entries(current)) if (sha(fs.readFileSync(file)) !== digest) throw Error(`Fixed source changed: ${file}`)
if (fs.existsSync(output)) throw Error(`Output already exists: ${output}`)
fs.mkdirSync(output, { recursive: true })

// The current service also contains an unrelated, not-live comparison optimization
// and whole-yuan marketing setting. Strip those before forming this release candidate.
let backend = read(service)
backend = replace(backend,
  "            // Only display rounds to whole yuan; fact sums, drilldowns and exports retain cents.\n            if (in_array((string)$column['key'], ['month_total_performance', 'item_performance', 'deal_unit_output'], true)) {\n                $column['display_precision'] = 0;\n            }\n", '', 'unrelated marketing precision')
const baseService = run('git', ['show', '6a140df9827c8b1f536c0b6f6b03b32a61db6a2b:' + service])
const blockStart = '        $cashByMonth = []; $servicesByMonth = []; $previousCashByMonth = [];\n'
const blockEnd = '        foreach ($months as $month) {\n'
function sliceBlock(text, label) {
  const start = text.indexOf(blockStart)
  const end = text.indexOf(blockEnd, start + blockStart.length)
  if (start < 0 || end < 0 || text.indexOf(blockStart, start + blockStart.length) >= 0) throw Error(`Missing ${label} cash block`)
  return text.slice(start, end)
}
backend = replace(backend, sliceBlock(backend, 'candidate'),
  sliceBlock(baseService, 'live baseline').replace('if ($bdeghMetrics === [])', 'if ($sourceMetrics === [])'),
  'unrelated comparison block')
const comparisonStart = backend.indexOf('    /**\n     * Preserve the comparison\'s signed cash')
const comparisonEnd = backend.indexOf('    /** Keep only dimensions consumed by health cash formulas', comparisonStart)
if (comparisonStart < 0 || comparisonEnd < 0) throw Error('Comparison helper boundary changed')
backend = backend.slice(0, comparisonStart) + backend.slice(comparisonEnd)
write(path.join(output, 'backend', service), backend)

// The member-visit controller additions in the accepted checkout are already live.
// Keep their exact live comment and add only the C/F permission route on top.
let admin = read(controller)
admin = replace(admin,
  '// 会员进店次数与现金金额下钻透传稳定会员 ID、月份和指标，沿用汇总报表的权限。',
  '// 会员进店次数与现金金额下钻透传稳定会员 ID、月份和指标，沿用汇总报表权限。', 'live member-visit comment')
write(path.join(output, 'backend', controller), admin)
let commitController = run('git', ['show', '6a140df9827c8b1f536c0b6f6b03b32a61db6a2b:' + controller])
commitController = replace(commitController,
  "'operations_pre_sale_bdegh', 'operations_pre_sale_bdegh_detail', 'operations_cash_fact_detail'",
  "'operations_pre_sale_bdegh', 'operations_pre_sale_bdegh_detail', 'operations_referral_beautician_pre_sale_detail', 'operations_cash_fact_detail'",
  'committed C/F detail registry')
commitController = replace(commitController,
  "(in_array($report, ['operations_cash_fact_detail', 'operations_service_fact_detail'], true) ? $parentReport : $report);",
  "($report === 'operations_referral_beautician_pre_sale_detail' ? 'operations_referral_beautician_pre_sale'\n                : (in_array($report, ['operations_cash_fact_detail', 'operations_service_fact_detail'], true) ? $parentReport : $report));",
  'committed C/F parent permission')
write(path.join(output, 'commit', controller), commitController)

const detailList = "const SOURCE_PRE_SALE_DETAIL_REPORTS = ['operations_pre_sale_bdegh_detail', 'operations_referral_beautician_pre_sale_detail']\n"
function withFormalR25(content) {
  content = replace(content, 'let bdeghDetailRequestId = 0\n', detailList + 'let bdeghDetailRequestId = 0\n', 'formal detail list')
  content = replace(content, "['operations_referral_beautician_pre_sale',\n    'marketing_acquisition_pre_sale', 'marketing_referral_pre_sale']", "['marketing_acquisition_pre_sale',\n    'marketing_referral_pre_sale']", 'formal C/F annual footer')
  content = replace(content, "['operations_pre_sale_bdegh_detail', 'operations_cash_fact_detail', 'operations_service_fact_detail'].includes(target)", "[...SOURCE_PRE_SALE_DETAIL_REPORTS, 'operations_cash_fact_detail', 'operations_service_fact_detail'].includes(target)", 'formal summary drilldown')
  content = replace(content, "function openBdeghDetail(row, column) {\n  const month =", "function openBdeghDetail(row, column) {\n  const detailReport = String(drilldownConfig(row, column)?.report || '')\n  if (!SOURCE_PRE_SALE_DETAIL_REPORTS.includes(detailReport)) return\n  const month =", 'formal popup route guard')
  content = replace(content, "bdeghDetail.value = { open: true, loading: false, detailReport: 'operations_pre_sale_bdegh_detail', parentReport:", 'bdeghDetail.value = { open: true, loading: false, detailReport, parentReport:', 'formal popup route')
  content = replace(content, "if (String(drilldownConfig(row, column)?.report || '') === 'operations_pre_sale_bdegh_detail') {", "if (SOURCE_PRE_SALE_DETAIL_REPORTS.includes(String(drilldownConfig(row, column)?.report || ''))) {", 'formal popup dispatch')
  return replace(content, "bdeghDetail.detailReport === 'operations_pre_sale_bdegh_detail' ? `共", 'SOURCE_PRE_SALE_DETAIL_REPORTS.includes(bdeghDetail.detailReport) ? `共', 'formal popup footer')
}
write(path.join(output, 'commit', view), withFormalR25(run('git', ['show', '6a140df9827c8b1f536c0b6f6b03b32a61db6a2b:' + view])))
function prepareFrontend(name) {
  const dir = path.join(output, name)
  fs.cpSync(path.join(r24, name), dir, { recursive: true, dereference: false })
  const file = path.join(dir, view)
  let content = read(file)
  if (name === 'formal') {
    content = withFormalR25(content)
  } else {
    content = replace(content, 'let bdeghDetailRequestId = 0\n', detailList + 'let bdeghDetailRequestId = 0\n', 'preview detail list')
    content = replace(content, "report: 'operations_pre_sale_bdegh_detail',\n      metric_code:", 'report: bdeghDetail.value.detailReport,\n      metric_code:', 'preview popup request')
    content = replace(content, "function openBdeghDetail(row, column) {\n  const month =", "function openBdeghDetail(row, column) {\n  const detailReport = String(drilldownConfig(row, column)?.report || '')\n  if (!SOURCE_PRE_SALE_DETAIL_REPORTS.includes(detailReport)) return\n  const month =", 'preview popup route guard')
    content = replace(content, "{ open: true, loading: false, metric: String(column.key)", '{ open: true, loading: false, detailReport, metric: String(column.key)', 'preview popup route')
    content = replace(content, "if (String(drilldownConfig(row, column)?.report || '') === 'operations_pre_sale_bdegh_detail') {", "if (SOURCE_PRE_SALE_DETAIL_REPORTS.includes(String(drilldownConfig(row, column)?.report || ''))) {", 'preview popup dispatch')
    content = replace(content, "activeReport === 'operations_pre_sale_bdegh' && canDrilldown(summaryRow, column)", "['operations_pre_sale_bdegh', 'operations_referral_beautician_pre_sale'].includes(activeReport) && canDrilldown(summaryRow, column)", 'preview annual footer')
  }
  write(file, content)
  const frontend = path.join(dir, '前端代码/cashier-v3')
  run('node', [path.join(root, '前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build'], { cwd: frontend })
  const html = fs.readFileSync(path.join(frontend, 'dist/index.html'))
  const refs = [...html.toString().matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)]
    .map(match => `assets/${path.basename(match[1])}`)
  if (refs.length !== 2) throw Error(`Unexpected ${name} asset references`)
  return { sourceSha256: sha(content), index: sha(html), assets: Object.fromEntries(refs.map(ref => [ref, sha(fs.readFileSync(path.join(frontend, 'dist', ref)))])) }
}

const manifest = { fixedSource: current, backend: { service: sha(backend), controller: sha(admin) },
  commitSource: { controller: sha(commitController), frontend: sha(read(path.join(output, 'commit', view))) }, builds: {
  formal: prepareFrontend('formal'), preview: prepareFrontend('preview')
} }
write(path.join(output, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n')
console.log(JSON.stringify(manifest, null, 2))
