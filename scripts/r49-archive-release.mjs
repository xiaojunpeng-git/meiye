import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// Archive only this round's engineering and sanitized delivery evidence.
// Raw production business query results stay local and are never pushed.
const directory = 'tests/cashier-v3/evidence/R49-release'
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim()
const code = JSON.parse(readFileSync(`${directory}/commit-receipt.json`))
if (existsSync(`${directory}/archive-receipt.json`)) throw Error('Archive already recorded')
const sites = Object.fromEntries(['rh', '008'].map(site => [site, JSON.parse(readFileSync(`${directory}/${site}-verified.json`))]))
if (Object.values(sites).some(s => s.result !== 'PASS' || s.commit !== code.commit)) throw Error('Both deployments must verify first')
if (git('ls-remote', 'origin', 'refs/heads/' + code.branch).split(/\s+/)[0] !== code.commit) throw Error('Exact business commit must already be pushed')
const summary = { codeCommit: code.commit, branch: code.branch,
  candidateTree: code.tree, sites: Object.fromEntries(Object.entries(sites).map(([site, value]) => {
    const backup = JSON.parse(readFileSync(`${directory}/${site}-database-backup.json`))
    return [site, { result: value.result, commit: value.commit, backup: value.backup,
      backupSha256: backup.database.sha256, backupBytes: backup.database.bytes,
      publicChecks: value.publicChecks, migration: value.migration.migration,
      switch: value.migration.switch, settingsForm: value.migration.settingsForm,
      historicalBusinessComparison: 'PASS', catalogueReadAndComparison: 'PASS',
      platformStatic: value.platformStatic, verifiedAt: value.verifiedAt }]
  })), productionFinancialOperations: 'None', rawProductionBusinessQueryResults: 'Kept local; excluded from this archive' }
writeFileSync(`${directory}/public-release-receipt.json`, JSON.stringify(summary, null, 2) + '\n')
const paths = [
  ...['release-probe.mjs', 'prepare-release.mjs', 'build-fixed.cjs', 'test-fixed.mjs', 'test-frontend-fixed.mjs',
    'config-release.php', 'publish.mjs', 'commit-fixed.mjs', 'archive-release.mjs'].map(p => 'scripts/r49-' + p),
  `${directory}/发布记录.md`, `${directory}/public-release-receipt.json`,
  `${directory}/candidate-cashier-build.json`, `${directory}/fixed-candidate.json`, `${directory}/isolated-lint.json`,
  `${directory}/trees.json`, `${directory}/commit-receipt.json`,
  'tests/cashier-v3/evidence/R49-fixed-files.json', 'tests/cashier-v3/evidence/R49-fixed.patch',
  'tests/cashier-v3/evidence/R49-before.json', 'tests/cashier-v3/evidence/R49-hang-before.json'
]
const indexPath = resolve(git('rev-parse', '--git-path', 'index'))
const hash = () => createHash('sha256').update(readFileSync(indexPath)).digest('hex')
const before = hash(), head = git('rev-parse', 'HEAD')
const env = { ...process.env, GIT_INDEX_FILE: resolve(git('rev-parse', '--git-dir'), 'r49-release', 'archive-index') }
const staged = (...args) => execFileSync('git', args, { env, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }).trim()
staged('read-tree', code.commit)
for (const path of paths) {
  const blob = git('hash-object', '-w', '--', path)
  staged('update-index', '--add', '--cacheinfo', '100644', blob, path)
}
const actual = staged('diff', '--cached', '--name-only', '-z', code.commit).split('\0').filter(Boolean)
if (actual.some(p => !paths.includes(p))) throw Error('Unrelated archive path')
// Patch evidence preserves byte-exact diff context, including blank-line
// prefixes. Check source/docs whitespace without rewriting that signed evidence.
staged('diff', '--cached', '--check', code.commit, '--', '.', ':!tests/cashier-v3/evidence/R49-fixed.patch')
const tree = staged('write-tree')
if (git('diff', '--name-only', code.commit, tree, '--', '前端代码', '后端代码')) throw Error('Evidence archive changes runtime code')
const commit = git('commit-tree', tree, '-p', code.commit, '-m', 'R49-RELEASE 归档双站备份迁移与上线核验', '-m',
  '仅本轮发布工程和脱敏交付证据；运行代码仍为39c69097。真实生产经营查询结果仅保存在本地，不纳入推送。')
git('update-ref', 'refs/heads/' + code.branch, commit, code.commit)
if (hash() !== before || git('rev-parse', 'HEAD') !== head) throw Error('Shared HEAD/index changed concurrently; inspect archive ref')
writeFileSync(`${directory}/archive-receipt.json`, JSON.stringify({ commit, tree, codeCommit: code.commit, branch: code.branch,
  paths: actual, sharedIndexUnchanged: true, runtimeCodeUnchanged: true }, null, 2) + '\n')
console.log(JSON.stringify({ commit, codeCommit: code.commit, branch: code.branch, files: actual.length, productionMetricsExcluded: true }))
