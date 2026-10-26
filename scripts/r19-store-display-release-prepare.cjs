// Freeze the approved store-display patch over each site's last accepted source.
// A private Git index keeps the shared checkout and other tasks' staged work intact.
const fs = require('fs')
const path = require('path')
const cp = require('child_process')
const crypto = require('crypto')

const root = path.resolve(__dirname, '..')
process.chdir(root)
const evidence = 'tests/cashier-v3/evidence/R19-store-display-release-v2'
fs.mkdirSync(evidence, { recursive: true })
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex')
const git = (args, opts = {}) => cp.execFileSync('git', args, { maxBuffer: 256e6, ...opts })
const show = (tree, file) => git(['show', `${tree}:${file}`])
const sourceTree = site => JSON.parse(fs.readFileSync(`tests/cashier-v3/evidence/R36-member-debt-visibility/release/${site}/trees.json`)).candidateTree
const platformTree = '6fafa0ccd63d7a1371d2ba88473ba690486b60fa'
const files = {
  adminBase: '前端代码/admin/src/pages/setting/shop/base.vue',
  tabs: '前端代码/admin/src/components/fromBuild/tabsBuild.vue',
  use: '前端代码/admin/src/components/fromBuild/useComponent.vue',
  submit: '前端代码/admin/src/components/fromSubmit/fromSubmit.vue',
  bridge: '前端代码/cashier-v3/src/services/cashierV3Bridge.js',
  workbench: '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue',
  order: '前端代码/cashier-v3/src/views/OrderCenterView.vue',
  config: '后端代码/app/services/system/config/SystemConfigServices.php',
  assembler: '后端代码/app/services/cashier/v3/projection/CashierV3RootDomainAssembler.php',
  contract: '后端代码/app/services/cashier/v3/projection/CashierV3RootStateContract.php',
  migration: '后端代码/database/upgrades/20261026_r19_store_display_switches.sql'
}
const workbench = fs.readFileSync(files.workbench, 'utf8')
const couponExtra = `                  <!-- 优惠券实例归属于会员；游客行不提供入口，避免出现无可操作的按钮。 -->\n                  <div v-if="currentCustomerMode === 'member' && !isEntitlementLine(line)" class="cart-line__meta-slot cart-line__meta-slot--coupon">`
if (!workbench.includes(couponExtra)) throw Error('Other-task coupon anchor changed')
const workbenchOnly = workbench.replace(couponExtra, `                  <div v-if="!isEntitlementLine(line)" class="cart-line__meta-slot cart-line__meta-slot--coupon">`)
const actual = Object.fromEntries(Object.entries(files).map(([key, file]) => [key, key === 'workbench' ? Buffer.from(workbenchOnly) : fs.readFileSync(file)]))
const inputSHA256 = Object.fromEntries(Object.entries(files).map(([key, file]) => [file, sha(fs.readFileSync(file))]))
const head = git(['rev-parse', 'HEAD']).toString().trim()
const indexPath = path.resolve(git(['rev-parse', '--git-path', 'index']).toString().trim())
const indexSHA256 = sha(fs.readFileSync(indexPath))
const baselineVersions = {
  [files.adminBase]: '232dd50b',
  [files.config]: '39c69097',
  [files.assembler]: '232dd50b',
  [files.contract]: '232dd50b'
}
const candidate = {}
for (const site of ['rh', '008', '010']) {
  const from = sourceTree(site)
  const privateIndex = `/private/tmp/r19-store-display-${process.pid}-${site}.index`
  const env = { ...process.env, GIT_INDEX_FILE: privateIndex }
  const stage = (file, bytes) => {
    const blob = git(['hash-object', '-w', '--stdin'], { input: bytes }).toString().trim()
    git(['update-index', '--add', '--cacheinfo', '100644', blob, file], { env })
  }
  try {
    git(['read-tree', from], { env })
    // The R36 member-debt trees reproduce each site's cashier assets. Their
    // platform subtree predates the separately deployed job/setting releases.
    // Overlay exactly the accepted platform delta so those 50 files survive.
    const platformDelta = git(['-c', 'core.quotepath=false', 'diff', '--name-only', from, platformTree, '--', '前端代码/admin']).toString().trim().split('\n').filter(Boolean)
    const wanted = new Set(platformDelta)
    const platformBlobs = git(['ls-tree', '-rz', '--full-tree', platformTree, '--', '前端代码/admin']).toString().split('\0').filter(Boolean)
      .map(row => /^(100644|100755) blob ([a-f0-9]+)\t(.+)$/s.exec(row))
      .filter(match => match && wanted.has(match[3]))
    if (platformBlobs.length !== wanted.size) throw Error(`${site}: platform delta includes a deletion or non-blob`)
    git(['update-index', '--index-info'], { env, input: platformBlobs.map(match => `${match[1]} ${match[2]}\t${match[3]}\n`).join('') })
    // These accepted source blobs are already running on all three sites, or
    // are an accepted frontend dependency of the running R57 source policy.
    for (const [file, commit] of Object.entries(baselineVersions)) stage(file, show(commit, file))
    const baselineTree = git(['write-tree'], { env }).toString().trim()
    for (const [key, file] of Object.entries(files)) stage(file, actual[key])
    stage('scripts/r19-store-display-release-prepare.cjs', fs.readFileSync(__filename))
    stage('scripts/r19-store-display-release-build.cjs', fs.readFileSync(path.join(__dirname, 'r19-store-display-release-build.cjs')))
    const candidateTree = git(['write-tree'], { env }).toString().trim()
    git(['diff', '--check', baselineTree, candidateTree])
    const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', baselineTree, candidateTree]).toString().trim().split('\n')
    const allowed = new Set([...Object.values(files), 'scripts/r19-store-display-release-prepare.cjs', 'scripts/r19-store-display-release-build.cjs'])
    if (changed.some(file => !allowed.has(file))) throw Error(`${site}: unlisted candidate file`)
    candidate[site] = { sourceTree: from, platformTree, platformDelta, baselineTree, candidateTree, changed }
    const siteEvidence = path.join(evidence, site)
    fs.mkdirSync(siteEvidence, { recursive: true })
    fs.writeFileSync(path.join(siteEvidence, 'trees.json'), JSON.stringify({ canonicalGit: root, baselineTree, candidateTree }, null, 2) + '\n')
  } finally {
    if (fs.existsSync(privateIndex)) fs.unlinkSync(privateIndex)
  }
}
if (git(['rev-parse', 'HEAD']).toString().trim() !== head || sha(fs.readFileSync(indexPath)) !== indexSHA256) throw Error('Shared checkout changed during freeze')
const output = { head, indexSHA256, inputSHA256, baselineVersions, candidate, acceptedByUser: false, createdAt: new Date().toISOString() }
fs.writeFileSync(path.join(evidence, 'trees.json'), JSON.stringify(output, null, 2) + '\n')
console.log(JSON.stringify({ head, candidates: Object.fromEntries(Object.entries(candidate).map(([site, value]) => [site, value.candidateTree])), files: candidate.rh.changed.length }))
