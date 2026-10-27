// R20 uses a private Git index to preserve the shared checkout and the
// already deployed per-site frontends while freezing only this round's files.
const fs = require('fs')
const path = require('path')
const cp = require('child_process')

const root = path.resolve(__dirname, '..')
process.chdir(root)
const evidence = path.join(root, 'tests/cashier-v3/evidence/R20-release-20261027')
fs.mkdirSync(evidence, { recursive: true })
const r19 = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R19-store-display-release-v2/trees.json'))
const git = (args, options = {}) => cp.execFileSync('git', args, { maxBuffer: 256e6, ...options })
const shell = '前端代码/cashier-v3/src/layouts/CashierShell.vue'
const member = '后端代码/app/services/cashier/v3/member/CashierV3MemberModule.php'
const roundFiles = [
  '前端代码/cashier-v3/src/components/common/MemberSelectorOverlay.vue',
  '前端代码/cashier-v3/src/services/recentCashierMembers.js',
  '前端代码/cashier-v3/src/services/presaleClaimApi.js',
  '前端代码/cashier-v3/src/views/PresaleClaimView.vue',
  '后端代码/app/controller/store/product/inventory/PresaleClaim.php',
  '后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php',
  '后端代码/app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php',
  '后端代码/route/store.php',
  member,
  'tests/cashier-v3/js/r20-recent-cashier-members.mjs',
  'tests/cashier-v3/php/presale-claim-records-readonly-integration.php',
  'tests/cashier-v3/evidence/R20-claim-records-local-selftest-2026-10-27.md',
  'scripts/r20-release-prepare.cjs',
  'scripts/r20-release-build.cjs'
]
const originalPatch = git(['diff', '-U3', r19.candidate.rh.candidateTree, '--', shell]).toString()
const parts = originalPatch.split(/(?=^@@ )/m)
const patch = parts.filter((part, index) => index === 0 || !part.includes('selectMemberCreatorServicePerson')).join('')
if (!patch.includes('queryRecentMemberSelector') || !patch.includes('presale-header-tabs') || !patch.includes('recent-history-key') || parts.length !== 7) {
  throw Error('R20 shell hunk boundary changed')
}
const output = { canonicalGit: root, head: git(['rev-parse', 'HEAD']).toString().trim(), sites: {} }
for (const site of ['rh', '008', '010']) {
  const from = r19.candidate[site].candidateTree
  const index = `/private/tmp/r20-release-${process.pid}-${site}.index`
  const env = { ...process.env, GIT_INDEX_FILE: index }
  const stageBlob = (file, bytes) => {
    const blob = git(['hash-object', '-w', '--stdin'], { input: bytes }).toString().trim()
    git(['update-index', '--add', '--cacheinfo', '100644', blob, file], { env })
  }
  try {
    git(['read-tree', from], { env })
    // All three instances run this accepted member-selector base, which is
    // newer than the R19 tree; R20 adds only its bounded recent-ID filter.
    stageBlob(member, git(['show', `232dd50b:${member}`]))
    if (site === '010') {
      // 010 independently deployed basic-report authorization after R19.
      for (const file of [shell, '前端代码/cashier-v3/src/router/index.js']) {
        stageBlob(file, git(['show', `e7afaf6b:${file}`]))
      }
    }
    const baselineTree = git(['write-tree'], { env }).toString().trim()
    for (const file of roundFiles) stageBlob(file, fs.readFileSync(file))
    git(['apply', '--cached', '--whitespace=nowarn', '-'], { env, input: patch })
    const candidateTree = git(['write-tree'], { env }).toString().trim()
    git(['diff', '--check', baselineTree, candidateTree])
    const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', baselineTree, candidateTree]).toString().trim().split('\n').filter(Boolean)
    if (changed.some(file => !new Set([...roundFiles, shell]).has(file))) throw Error('Unlisted candidate file: ' + site)
    output.sites[site] = { from, baselineTree, candidateTree, changed }
    const directory = path.join(evidence, site)
    fs.mkdirSync(directory, { recursive: true })
    fs.writeFileSync(path.join(directory, 'trees.json'), JSON.stringify({ canonicalGit: root, baselineTree, candidateTree }, null, 2) + '\n')
  } finally {
    if (fs.existsSync(index)) fs.unlinkSync(index)
  }
}
fs.writeFileSync(path.join(evidence, 'trees.json'), JSON.stringify(output, null, 2) + '\n')
console.log(JSON.stringify(output.sites))
