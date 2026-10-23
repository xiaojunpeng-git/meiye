import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// Only canonical Git objects and the accepted R51 file list enter the private index.
// The shared checkout/index and unrelated staged work are never changed here.
const evidence = 'tests/report-basic/evidence/release'
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trimEnd()
const bytes = id => execFileSync('git', ['show', id], { maxBuffer: 16 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] })
const hash = value => createHash('sha256').update(value).digest('hex')
const head = git('rev-parse', 'HEAD')
if (head !== 'b50fd1f13d32c157ccd13e6ef63fb139d5d09702') throw new Error('Shared source baseline changed; re-audit before freezing')
const accepted = git('rev-parse', '1a74fbb4')
const baselineTree = git('merge-tree', '--write-tree', head, accepted)
if (!/^[a-f0-9]{40}$/.test(baselineTree)) throw new Error('Accepted baseline merge needs manual review')
if (git('diff', '--name-only', accepted, baselineTree, '--', '前端代码', '后端代码/app', '后端代码/route', '后端代码/config')) {
  throw new Error('Unified baseline runtime differs from the accepted R28 release')
}
const inventory = JSON.parse(readFileSync('tests/report-basic/evidence/r51-working-tree-manifest.json'))
const records = Object.entries(inventory.groups).flatMap(([group, files]) => files.map(file => ({ ...file, group })))
const additions = ['scripts/r51-basic-report-prepare.mjs', 'scripts/r51-basic-report-build.cjs',
  'scripts/r51-basic-report-release-probe.php', 'scripts/r51-basic-report-release-probe.mjs',
  'scripts/r51-basic-report-test-fixed.mjs']
for (const file of additions) if (!records.some(record => record.file === file)) records.push({ file, group: 'scripts', sha256: hash(readFileSync(file)) })
// Generated evidence is preserved separately; it is not an editable build source.
const metadata = resolve(git('rev-parse', '--git-dir'), 'r51-basic-report-release')
mkdirSync(metadata, { recursive: true }); mkdirSync(evidence, { recursive: true })
const env = { ...process.env, GIT_INDEX_FILE: resolve(metadata, 'candidate-index') }
const indexed = (...args) => execFileSync('git', args, { env, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trimEnd()
indexed('read-tree', baselineTree)
const sharedIndex = resolve(git('rev-parse', '--git-path', 'index'))
const beforeIndex = hash(readFileSync(sharedIndex))
const changes = []
for (const record of records) {
  const file = record.file
  const working = readFileSync(file)
  if (hash(working) !== record.sha256) throw new Error('Tested source changed: ' + file)
  let original = null
  try { original = bytes(baselineTree + ':' + file) } catch (error) { if (error.status !== 128) throw error }
  let incoming = working
  if (file.endsWith('/layouts/CashierShell.vue')) {
    // Member-creation multi-select belongs to another unaccepted task; keep its live single-select contract.
    const source = working.toString(), old = original.toString()
    const start = source.indexOf('async function selectMemberCreatorServicePerson(')
    const end = source.indexOf('\n}', start)
    const oldStart = old.indexOf('async function selectMemberCreatorServicePerson(')
    const oldEnd = old.indexOf('\n}', oldStart)
    if ([start, end, oldStart, oldEnd].some(value => value < 0)) throw new Error('Member selector isolation anchor missing')
    incoming = Buffer.from(source.slice(0, start) + old.slice(oldStart, oldEnd + 2) + source.slice(end + 2))
  }
  if (file === '后端代码/app/controller/admin/v1/report/UnifiedReport.php') {
    incoming = Buffer.from(incoming.toString().replace('// 页面、CSV 和明细工作簿在授权查询后使用相同的游客展示值。', '// 授权查询与导出共用游客姓名投影，保留汇总和历史事实。'))
  }
  if (file === '后端代码/app/controller/cashier/v3/Report.php') {
    incoming = Buffer.from(incoming.toString().replace('// 页面、CSV 和明细工作簿共用同一游客姓名投影；汇总行保持原样。', '// 授权查询与导出共用游客姓名投影，保留汇总和历史事实。'))
  }
  if (original?.equals(incoming)) continue
  const blob = execFileSync('git', ['hash-object', '-w', '--stdin'], { input: incoming, encoding: 'utf8' }).trim()
  indexed('update-index', '--add', '--cacheinfo', '100644', blob, file)
  changes.push({ path: file, group: record.group, beforeSha256: original ? hash(original) : null,
    acceptedWorkingSha256: record.sha256, sha256: hash(incoming), blob, differsFromHotWorktree: !working.equals(incoming) })
}
const candidateTree = indexed('write-tree')
if (hash(readFileSync(sharedIndex)) !== beforeIndex) throw new Error('Shared index changed during preparation')
const result = { head, acceptedBaselineCommit: accepted, baselineTree, candidateTree, changes,
  sharedIndexSha256: beforeIndex, targets: ['rh', '008', '010'], rhFronts: ['formal', 'preview'],
  scope: 'R51 basic reports only; preserve published R28 and its accepted dependencies in a separate baseline archive',
  migration: 'Idempotent registration audit table + new platform menus + basic report navigation metadata; no historical import',
  status: 'Prepared for isolated regression and fixed-candidate supplementary product acceptance; not committed or deployed' }
writeFileSync(`${evidence}/trees.json`, JSON.stringify(result, null, 2) + '\n')
writeFileSync(`${evidence}/round.patch`, execFileSync('git', ['diff', '--binary', baselineTree, candidateTree], { maxBuffer: 64 * 1024 * 1024 }))
console.log(JSON.stringify({ head, baselineTree, candidateTree, files: changes.length,
  isolated: changes.filter(file => file.differsFromHotWorktree).map(file => file.path) }))
