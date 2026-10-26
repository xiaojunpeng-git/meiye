import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// Records the tested canonical working files, not a release candidate or a second editable source tree.
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const sha = file => createHash('sha256').update(readFileSync(path.join(repo, file))).digest('hex')
const list = (directory, predicate) => readdirSync(path.join(repo, directory), { withFileTypes: true })
  .flatMap(entry => entry.isDirectory() ? list(`${directory}/${entry.name}`, predicate) : predicate(entry.name) ? [`${directory}/${entry.name}`] : [])
const source = {
  frontend: [
    '前端代码/cashier-v3/src/views/BasicReportsView.vue', '前端代码/cashier-v3/src/services/basicReportApi.js',
    '前端代码/cashier-v3/src/router/index.js', '前端代码/cashier-v3/src/layouts/CashierShell.vue',
    '前端代码/cashier-v3/src/components/navigation/StoreTopNavigation.vue',
    '前端代码/admin/src/pages/report/data/basic_reports.vue', '前端代码/admin/src/router/modules/report.js',
    '前端代码/shared/unified-query-vue3/src/index.js',
    '前端代码/shared/unified-query-vue3/src/components/UnifiedReportQueryToolbar.vue',
    '前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js',
    '前端代码/shared/unified-query-vue3/src/contracts/reportPresentation.js',
  ],
  backend: [...list('后端代码/app/services/report', name => /^Basic.*\.php$/.test(name)),
    '后端代码/app/controller/admin/v1/report/UnifiedReport.php', '后端代码/app/controller/cashier/v3/Report.php',
    '后端代码/app/services/organization/StorePermissionCatalogServices.php',
    '后端代码/config/unified_query.php', '后端代码/route/admin.php', '后端代码/route/cashier-v3.php'],
  migrations: list('后端代码/database/upgrades/2026-10-23-基础报表', name => name.endsWith('.sql')),
  tests: [...list('tests/report-basic/php', name => name.endsWith('.php')), ...list('tests/report-basic/js', name => name.endsWith('.mjs')),
    ...list('tests/report-r51-financial/php', name => name.endsWith('.php')), '前端代码/cashier-v3/src/dev/r51BasicReportFixture.js'],
  scripts: ['scripts/r51-basic-report-manifest.mjs', 'scripts/r51-basic-report-audit.php',
    'scripts/r51-basic-report-audit.mjs', 'scripts/r51-basic-report-local-install.php'],
}
const groups = Object.fromEntries(Object.entries(source).map(([group, files]) => [group,
  [...new Set(files)].sort().map(file => ({ file, sha256: sha(file) }))]))
const sourceFingerprint = createHash('sha256').update(JSON.stringify(groups)).digest('hex')
const dependencies = ['后端代码/app/services/query/metric/RegisteredMetricReadServices.php',
  '后端代码/app/services/query/metric/MetricDefinitionRegistry.php', '后端代码/app/services/report/StoreReportNormalDataScopeServices.php',
  '后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php']
const buildDirectory = '/private/tmp/r51-basic-report-host-build'
const manifestPath = path.join(buildDirectory, 'manifest.json')
let build = { status: 'not_recorded' }
if (existsSync(manifestPath)) {
  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
  const entry = Object.values(manifest).find(item => item.isEntry)
  if (!entry) throw new Error('Vite entry missing')
  build = { status: 'local_technical_build_not_release_candidate', directory: buildDirectory,
    resources: [entry.file, ...(entry.css || [])].map(file => ({ file,
      sha256: createHash('sha256').update(readFileSync(path.join(buildDirectory, file))).digest('hex') })) }
}
const result = {
  recorded_at: new Date().toISOString(), baseline_head: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: repo, encoding: 'utf8' }).trim(),
  status: 'canonical_working_tree_test_snapshot_not_product_accepted_release', source_fingerprint: sourceFingerprint,
  scope: 'R51 twenty original basic reports plus explicitly added realtime payroll; original reports retained.',
  caution: 'Shared files already contain other tasks changes; full-file hashes identify test dependencies, not authorization to commit all their diffs.',
  groups, reused_dependencies: dependencies.map(file => ({ file, sha256: sha(file) })), build,
  known_limits: ['Product approved today-live asset reports only; historical asset dates are not offered or reconstructed.',
    'Product confirmed lifecycle-cohort new customers: ordinary refunds retain eligibility, voided sales are excluded.',
    'Payroll and cross-store results explicitly disclose unresolved source/rule gaps; null is not zero.',
    'Providers currently enforce a 10000-row source safety window and refuse excess instead of truncating.',
    'Browser checks use API fixtures; monetary/permissions behavior is separately tested against local MySQL.',
    'No client deployment, production schema change, commit or push is authorized by this manifest.'],
}
writeFileSync(path.join(repo, 'tests/report-basic/evidence/r51-working-tree-manifest.json'), JSON.stringify(result, null, 2) + '\n')
console.log(JSON.stringify({ baseline_head: result.baseline_head, source_fingerprint: sourceFingerprint,
  counts: Object.fromEntries(Object.entries(groups).map(([group, files]) => [group, files.length])), build: build.status }))
