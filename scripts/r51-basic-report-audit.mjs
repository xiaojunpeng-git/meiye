import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

// 此入口只生成核对证据。远程模式必须显式点名010，绝不修改站点文件或自动导入。
const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const remote = process.argv.slice(2).includes('--010-readonly')
if (process.argv.slice(2).some(value => value !== '--010-readonly')) throw new Error('Unsupported audit option')
const run = (bin, args, options = {}) => execFileSync(bin, args, { cwd: repo, encoding: 'utf8', ...options })
const php = readFileSync(path.join(repo, 'scripts/r51-basic-report-audit.php'), 'utf8')
const hash = value => createHash('sha256').update(value).digest('hex')
const head = run('git', ['rev-parse', 'HEAD']).trim()
const baselineFiles = [
  '后端代码/app/controller/admin/v1/report/UnifiedReport.php',
  '后端代码/app/controller/cashier/v3/Report.php',
  '后端代码/app/services/report/StoreUnifiedReportServices.php',
  '前端代码/admin/src/router/modules/report.js',
  '前端代码/cashier-v3/src/router/index.js',
  '前端代码/cashier-v3/src/layouts/CashierShell.vue',
  '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue'
]
const before = Object.fromEntries(baselineFiles.map(file => [file, hash(readFileSync(path.join(repo, file)))]))
const source = JSON.parse(run('docker', ['exec', '-i', '-e', 'R51_BACKEND_ROOT=/var/www/html',
  '-e', 'R51_AUDIT_MODE=source-local', '007-local-app', 'php'], { input: php }))
const requested = JSON.parse(run('php', ['-r',
  'require "后端代码/app/services/report/BasicReportCatalogServices.php"; echo json_encode(\\app\\services\\report\\BasicReportCatalogServices::entries(), JSON_UNESCAPED_UNICODE);']))
let target = null
if (remote) {
  target = JSON.parse(run('ssh', ['-i', path.join(process.env.HOME, '.ssh/aliyun-ecs'),
    '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12', 'root@121.41.109.138',
    'R51_BACKEND_ROOT=/www/wwwroot/010.cc3798.com R51_AUDIT_MODE=target-010 php'], { input: php }))
}
const afterHead = run('git', ['rev-parse', 'HEAD']).trim()
const after = Object.fromEntries(baselineFiles.map(file => [file, hash(readFileSync(path.join(repo, file)))]))
if (head !== afterHead || JSON.stringify(before) !== JSON.stringify(after)) throw new Error('Shared source changed during audit; rerun required')
const receipt = {
  recorded_at: new Date().toISOString(), baseline_head: head, shared_files: before,
  script_sha256: hash(php), scope: remote ? 'local-reference-and-010-readonly' : 'local-reference-only',
  requested_reports: requested.map(entry => ({ ...entry,
    local_config_id: source.reports.find(report => report.name === entry.legacy_name)?.legacy_id ?? null,
    target_config_id: target?.reports.find(report => report.name === entry.legacy_name)?.legacy_id ?? null,
    implementation_status: 'definition_inventory_only_not_published'
  })),
  source, target, production_writes: false, commit_performed: false, push_performed: false,
  limitation: '配置核对不是20张新报表的实现或验收；本地007旧副本不能覆盖010现有数据。'
}
const folder = path.join(repo, 'tests/report-basic/evidence')
mkdirSync(folder, { recursive: true })
const filename = remote ? 'r51-local-and-010-readonly.json' : 'r51-local-reference.json'
writeFileSync(path.join(folder, filename), JSON.stringify(receipt, null, 2) + '\n')
console.log(JSON.stringify({ evidence: path.join(folder, filename), head, source_brush: source.brush,
  target_brush: target?.brush, import_status: target?.import_status, source_comparison: target?.import_comparison,
  target_only: target?.target_only_rows, writes_performed: false }, null, 2))
