import { execFileSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdirSync, chmodSync, unlinkSync } from 'node:fs'
import { resolve } from 'node:path'
import { createHash } from 'node:crypto'

// Only canonical Git objects form the fixed tree. The live servers supply
// comparison hashes, never editable source. Never alter the shared index/HEAD.
const evidence = 'tests/cashier-v3/evidence/R49-release'
const git = (...args) => execFileSync('git', args, { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }).trimEnd()
const hash = v => createHash('sha256').update(v).digest('hex')
const fixed = JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-fixed-files.json'))
const before = [...JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-before.json')).files,
  ...JSON.parse(readFileSync('tests/cashier-v3/evidence/R49-hang-before.json')).files]
const head = git('rev-parse', 'HEAD')
if (head !== fixed.currentHeadAtFreeze) throw Error('Shared HEAD changed')
mkdirSync(evidence, { recursive: true })
const metadata = resolve(git('rev-parse', '--git-dir'), 'r49-release')
mkdirSync(metadata, { recursive: true })
const env = { ...process.env, GIT_INDEX_FILE: resolve(metadata, 'candidate-index') }
const indexed = (...args) => execFileSync('git', args, { env, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }).trimEnd()
const bytes = id => execFileSync('git', ['show', id], { maxBuffer: 8 * 1024 * 1024 })
const blob = v => execFileSync('git', ['hash-object', '-w', '--stdin'], { input: v, encoding: 'utf8' }).trim()
const set = (path, id) => indexed('update-index', '--add', '--cacheinfo', '100644', id, path)
const previous = '988e0639'
const merged = git('merge-tree', '--write-tree', head, previous)
if (!/^[a-f0-9]{40}$/.test(merged)) throw Error('Prior accepted release cannot merge cleanly')
indexed('read-tree', merged)
const baselineArchive = []
for (const [path, commit] of [
  ['后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php', '92a02ef1'],
  ['后端代码/app/services/system/config/SystemConfigServices.php', '92533c0a']
]) {
  const original = before.find(f => f.path === path)
  const id = git('rev-parse', commit + ':' + path)
  if (id !== original.blob) throw Error('Accepted deployed source provenance mismatch: ' + path)
  set(path, id); baselineArchive.push({ path, commit, blob: id, sha256: hash(bytes(id)), role: 'Already deployed dependency, not R49 work' })
}
const baselineTree = indexed('write-tree')
const sites = Object.fromEntries(['rh', '008'].map(site => [site, JSON.parse(readFileSync(`${evidence}/${site}-before.json`))]))
for (const f of before.filter(f => f.path.startsWith('后端代码/'))) {
  const sha = hash(bytes(baselineTree + ':' + f.path))
  for (const [site, live] of Object.entries(sites)) {
    if (live.files[f.path.slice('后端代码/'.length)] !== sha) throw Error('Affected baseline differs from ' + site + ': ' + f.path)
  }
}
const changes = []
for (const f of fixed.files) {
  if (hash(readFileSync(f.path)) !== f.sha256) throw Error('Accepted worktree drift: ' + f.path)
  const prior = before.find(p => p.path === f.path)
  if (!prior) { set(f.path, f.blob); changes.push({ path: f.path, blob: f.blob }); continue }
  const current = bytes(baselineTree + ':' + f.path), original = bytes(prior.blob), incoming = bytes(f.blob)
  let composed = incoming
  if (!current.equals(original)) {
    const paths = [current, original, incoming].map((content, i) => {
      const path = resolve(metadata, 'merge-' + i); writeFileSync(path, content); chmodSync(path, 0o444); return path
    })
    try { composed = execFileSync('git', ['merge-file', '-p', ...paths], { maxBuffer: 8 * 1024 * 1024 }) }
    catch { throw Error('Ambiguous round-only merge: ' + f.path) }
    finally { paths.forEach(unlinkSync) }
  }
  const id = blob(composed); set(f.path, id); changes.push({ path: f.path, blob: id, sha256: hash(composed) })
}
const candidateTree = indexed('write-tree')
const frontend = '前端代码/cashier-v3'
if (git('rev-parse', baselineTree + ':' + frontend) !== git('rev-parse', 'c3625d2a:' + frontend)) {
  throw Error('Cashier baseline differs from previously accepted R48 source')
}
const previousBuild = JSON.parse(readFileSync('tests/cashier-v3/evidence/R48-release/candidate-cashier-v3-build.json'))
for (const [site, live] of Object.entries(sites)) {
  for (const [entry, front] of Object.entries(live.front)) {
    if (front.html !== previousBuild.assets['index.html']
      || Object.entries(front.assets).some(([p, h]) => previousBuild.assets[p] !== h)) throw Error('Deployed frontend is no longer R48: ' + site + ':' + entry)
  }
}
const result = { head, previousAcceptedRelease: git('rev-parse', previous), baselineTree, candidateTree,
  baselineArchive, changes, scope: fixed.files.map(f => f.path), acceptedLocalPatch: fixed.patchSha256,
  platformStatic: 'Unchanged; the settings form is rendered by SystemConfigServices',
  frontBaseline: 'Canonical cashier subtree identical to accepted R48; captured live entries/assets byte-identical',
  status: 'Prepared only: awaiting candidate self-tests and PM supplementary acceptance before commit/deploy' }
writeFileSync(`${evidence}/trees.json`, JSON.stringify(result, null, 2) + '\n')
console.log(JSON.stringify({ head, baselineTree, candidateTree, paths: result.scope.length, baselineArchive }))
