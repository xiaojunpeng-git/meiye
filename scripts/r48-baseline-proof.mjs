import { readFileSync, writeFileSync } from 'node:fs'
import { createHash } from 'node:crypto'

// Compare canonical rebuilds to independently captured instance fingerprints.
// Hash-label normalization is proof-only; it never edits a deployment artifact.
const directory = 'tests/cashier-v3/evidence/R48-release/'
const cashier = JSON.parse(readFileSync(directory + 'baseline-cashier-v3-build.json'))
const admin = JSON.parse(readFileSync(directory + 'baseline-admin-build.json'))
const results = []
for (const site of ['rh', '008']) {
  const live = JSON.parse(readFileSync(directory + site + '-before.json'))
  const formal = Object.values(live.front)[0]
  const htmlMatches = cashier.assets['index.html'] === formal.html
  const assetsMatch = Object.entries(formal.assets).every(([p, hash]) => cashier.assets[p] === hash)
  if (!htmlMatches || !assetsMatch) throw Error('Cashier baseline differs: ' + site)
  results.push({ site, terminal: 'cashier', result: 'BYTE_IDENTICAL', html: formal.html, assets: formal.assets })
}
const previous = '/private/tmp/mohe-r26-member-transfer-20261022/admin-candidate/'
const output = admin.output + '/'
const sha = bytes => createHash('sha256').update(bytes).digest('hex')
const live = JSON.parse(readFileSync(directory + 'rh-before.json'))
const oldHtml = readFileSync(previous + 'system.html', 'utf8')
if (sha(oldHtml) !== live.platform.html) throw Error('Canonical R26 build is not current live baseline')
const oldApp = 'view_admin/js/app.d89bd563.js'
const newApp = 'view_admin/js/app.33d40334.js'
const oldChunk = 'view_admin/js/chunk-0f56252a.84ab0fca.js'
const newChunk = 'view_admin/js/chunk-0f56252a.a9ab431b.js'
if (!readFileSync(previous + oldChunk).equals(readFileSync(output + newChunk))) throw Error('Editor chunk content changed')
if (readFileSync(previous + oldApp, 'utf8') !== readFileSync(output + newApp, 'utf8').replaceAll('a9ab431b', '84ab0fca')) throw Error('Runtime differs beyond identical-byte chunk label')
if (oldHtml !== readFileSync(output + 'system.html', 'utf8').replaceAll('app.33d40334.js', 'app.d89bd563.js')) throw Error('HTML differs beyond runtime filename')
for (const [file, fingerprint] of Object.entries(admin.assets)) {
  if (!file.startsWith('view_admin/') || file.endsWith('.gz') || [newApp, newChunk].includes(file)) continue
  if (sha(readFileSync(previous + file)) !== fingerprint) throw Error('Unexplained baseline resource difference: ' + file)
  for (const site of ['rh', '008']) {
    const instance = JSON.parse(readFileSync(directory + site + '-before.json'))
    if (instance.platform.assets[file] !== fingerprint) throw Error('Instance baseline resource mismatch: ' + site + ':' + file)
  }
}
results.push({ terminal: 'admin', sites: ['rh', '008'], result: 'IDENTICAL_BUSINESS_BYTES_WITH_HASH_LABEL_ONLY_DIFFERENCE',
  previousCanonicalCommit: '802490dcf4facafd1a29bad7fb6191d33698d772', oldHtmlSha256: sha(oldHtml), rebuiltHtmlSha256: admin.assets['system.html'],
  identicalContentResourceRename: { from: oldChunk, to: newChunk, sha256: sha(readFileSync(output + newChunk)) },
  runtimeDifference: 'Only the identical editor chunk filename hash in the loading map',
  artifactMutation: false })
writeFileSync(directory + 'baseline-proof.json', JSON.stringify({ results, result: 'PASS' }, null, 2) + '\n')
console.log(JSON.stringify(results, null, 2))
