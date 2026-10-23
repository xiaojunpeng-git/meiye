import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync, chmodSync, unlinkSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// Compose only accepted canonical Git objects. The shared index and checkout
// remain untouched; immutable merge inputs are technical Git metadata only.
const root = process.cwd()
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
const metadata = resolve(git('rev-parse', '--git-dir').trim(), 'r48-candidate')
mkdirSync(metadata, { recursive: true })
const env = { ...process.env, GIT_INDEX_FILE: resolve(metadata, 'index') }
const indexed = (...args) => execFileSync('git', args, { env, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
const blob = content => execFileSync('git', ['hash-object', '-w', '--stdin'], { input: Buffer.from(content), encoding: 'utf8', timeout: 15000 }).trim()
const load = id => git('show', id)
const set = (path, id) => indexed('update-index', '--add', '--cacheinfo', '100644', id, path)
const head = git('rev-parse', 'HEAD').trim()
const fixed = JSON.parse(readFileSync('tests/cashier-v3/evidence/R48-fixed-files.json'))
const changes = []

/** Three-way composition rejects ambiguous changes instead of choosing a side. */
function merge(current, base, incoming, label) {
  if (current === base) return incoming
  const files = [current, base, incoming].map((text, i) => {
    const file = resolve(metadata, 'merge-' + i)
    writeFileSync(file, text); chmodSync(file, 0o444); return file
  })
  try { return execFileSync('git', ['merge-file', '-p', ...files], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }) }
  catch (error) {
    const conflicts = error.stdout.match(/^<<<<<<<[\s\S]*?^>>>>>>>.*$/gm) || []
    // Historical store scope changes the cards call next to lazy tab loading.
    // Git already carries that call into both sides; retain the accepted lazy
    // service read only when the sole overlap has precisely that known shape.
    if (label === '7e63624051cba91fed9d1332adb2c8dba5668fb9' && conflicts.length === 1) {
      const [ours, theirs] = conflicts[0].replace(/^<<<<<<<.*\n/, '').replace(/^>>>>>>>.*$/m, '').split(/^=======\n/m)
      const calls = [ours, theirs].map(s => s.split('\n').find(l => l.includes('$cards = $this->cards(')))
      if (calls[0] === calls[1] && calls[0].includes('$operatorScope->storeId()')
        && ours.includes('$loadAll = $tab ===') && ours.includes(': null;') && !theirs.includes('$loadAll')) {
        return error.stdout.replace(conflicts[0] + '\n', ours)
      }
    }
    throw new Error('Unresolved canonical merge: ' + label + '\n' + conflicts.join('\n'))
  }
  finally { files.forEach(unlinkSync) }
}
indexed('read-tree', head)
for (const path of [
  '前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue',
  '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue',
]) {
  const source = '6d0eeac1b91241f832db814d3a4b28f6bb84bde3'
  const id = git('rev-parse', source + ':' + path).trim()
  set(path, id); changes.push({ path, source, blob: id, purpose: 'Preserve deployed M011 and R47 cashier behavior' })
}
for (const f of fixed.files.filter(f => /WorkspaceServices|CashierModule/.test(f.path))) {
  const source = '7c7bc5c972867d6e98a00abd884ee313945157e3'
  if (git('rev-parse', source + ':' + f.path).trim() !== f.beforeBlob) throw Error('Baseline provenance mismatch')
  set(f.path, f.beforeBlob); changes.push({ path: f.path, source, blob: f.beforeBlob, purpose: 'Archive exact live dependency' })
}
// The shared branch predates several deployed R47 repayment dependencies.
// Preserve their exact accepted source, without reclassifying them as R48 work.
const live = JSON.parse(readFileSync('tests/cashier-v3/evidence/R48-release/rh-before.json'))
for (const [relative, fingerprint] of Object.entries(live.files)) {
  if (!fingerprint || !/(WriterAdapter|Supplement|Projection|DebtRepayment|report\/)/.test(relative)) continue
  const path = '后端代码/' + relative
  const source = '45090ffe'
  const content = load(source + ':' + path)
  if (createHash('sha256').update(content).digest('hex') !== fingerprint) throw Error('R47 baseline changed: ' + path)
  set(path, blob(content)); changes.push({ path, source, blob: blob(content), purpose: 'Preserve deployed R47 repayment dependencies' })
}
const member = '后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php'
{
  const path = '后端代码/app/services/cashier/v3/cashier/CashierV3MemberDebtProjectionServices.php'
  const source = '5d4d6bd3a38e858945a904a17c14a3f8d2054671'
  const content = load(source + ':' + path)
  if (createHash('sha256').update(content).digest('hex') !== '4be13dfb639d40b993cfd4d611a6931d272ce227b447a60ff5db0379bb80e47d') throw Error('Live debt helper provenance mismatch')
  set(path, blob(content)); changes.push({ path, source, blob: blob(content), purpose: 'Exact live R47 issued-card dependency; both sites verified' })
}
for (const path of ['tests/cashier-v3/js/r47-sales-debt-balance-contract.mjs',
  'tests/cashier-v3/php/r47-sales-debt-balance-local.php',
  'tests/cashier-v3/php/r47-sales-debt-v3-allocation-local.php',
  '后端代码/database/upgrades/2026-10-21-销售欠款余额补交/01-schema.sql']) {
  const source = '45090ffe'
  const id = git('rev-parse', source + ':' + path).trim()
  set(path, id); changes.push({ path, source, blob: id, purpose: 'Preserve accepted R47 migration and regression fixtures' })
}
let memberSource = load(head + ':' + member)
for (const commit of ['30582fd009384a1a8aa88d7954d9d2ae06015bd4', '92a02ef16c4802a05e170c32d44c45e208de9d8c', '7e63624051cba91fed9d1332adb2c8dba5668fb9']) {
  memberSource = merge(memberSource, load(commit + '^:' + member), load(commit + ':' + member), commit)
}
set(member, blob(memberSource)); changes.push({ path: member, blob: blob(memberSource), purpose: 'Union of canonical already-deployed member-detail features' })
const baselineTree = indexed('write-tree').trim()
for (const f of fixed.files) {
  const actual = createHash('sha256').update(readFileSync(resolve(root, f.path))).digest('hex')
  if (actual !== f.sha256) throw Error('Accepted file drift: ' + f.path)
  let baseline = ''
  try { baseline = load(baselineTree + ':' + f.path) } catch {}
  const composed = merge(baseline, load(f.beforeBlob), load(f.blob), f.path)
  set(f.path, blob(composed))
}
const candidateTree = indexed('write-tree').trim()
const result = { head, baselineTree, candidateTree, changes, acceptedLocalFingerprint: fixed.fingerprint,
  scope: fixed.files.map(f => f.path), status: 'Prepared Git trees only; not committed, accepted for release, or deployed' }
writeFileSync('tests/cashier-v3/evidence/R48-release/trees.json', JSON.stringify(result, null, 2) + '\n')
console.log(JSON.stringify(result, null, 2))
