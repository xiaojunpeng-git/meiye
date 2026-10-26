const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), os = require('os');
// One accepted reservation patch, bound to each already-approved runtime baseline.
// Immutable build trees preserve the independently approved RH report rollback;
// no customer condition or second editable application source is introduced.
const root = path.resolve(__dirname, '..'); process.chdir(root);
const dir = 'tests/cashier-v3/evidence/m012-reservation-shortcuts-2026-10-26';
const release = dir + '/release';
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const git = (args, options = {}) => cp.execFileSync('git', args, { maxBuffer: 128e6, ...options });
const accepted = JSON.parse(fs.readFileSync(dir + '/fixed-version.json'));
for (const f of accepted.files) if (sha(fs.readFileSync(f.path)) !== f.sha256) throw Error('Accepted candidate drift: ' + f.path);
if (sha(fs.readFileSync(dir + '/fixed.patch')) !== accepted.patchSha256) throw Error('Patch drift');
const head = git(['rev-parse', 'HEAD']).toString().trim();
const sharedIndex = git(['rev-parse', '--git-path', 'index']).toString().trim(), indexHash = sha(fs.readFileSync(sharedIndex));
const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'm012-index-'));
const env = { ...process.env, GIT_INDEX_FILE: path.join(temp, 'index') };
const isolated = (args, input) => git(args, { env, input });
const put = (file, bytes) => { const blob = git(['hash-object', '-w', '--stdin'], { input: bytes }).toString().trim(); isolated(['update-index', '--add', '--cacheinfo', '100644', blob, file]); };
const provider = '后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php';
const moduleFile = '后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php';
const baseCommit = git(['rev-parse', '5f790d67']).toString().trim();
const prior = '01ea55feb4e8097519c8b5356b64974b33d0c8d8';
try {
  for (const site of ['rh', '008', '010']) {
    const before = JSON.parse(fs.readFileSync(release + '/' + site + '-before.json'));
    if (before.backend[provider.replace('后端代码/', '')] !== accepted.sourceBaselines[1].sha256) throw Error('Live provider drift');
    if (before.backend[moduleFile.replace('后端代码/', '')] !== sha(git(['show', prior + ':' + moduleFile]))) throw Error('Prior purchased-duration fix missing');
  }
  isolated(['read-tree', baseCommit]);
  const preserved = [moduleFile, 'tests/cashier-v3/php/m011-purchased-reservation-duration.php', 'tests/cashier-v3/php/m011-purchased-duration-mysql.php'];
  for (const file of preserved) put(file, git(['show', prior + ':' + file]));
  const baselineTree = isolated(['write-tree']).toString().trim();
  for (const f of accepted.files) put(f.path, fs.readFileSync(f.path));
  const candidateTree = isolated(['write-tree']).toString().trim();
  const paths = git(['-c', 'core.quotepath=false', 'diff', '--name-only', baselineTree, candidateTree]).toString().trim().split('\n');
  if (JSON.stringify(paths.sort()) !== JSON.stringify(accepted.files.map(f => f.path).sort())) throw Error('Round scope mismatch');
  git(['diff', '--check', baselineTree, candidateTree]);
  const plan = { canonicalGit: root, head, indexHash, baseCommit, priorAcceptedCommit: prior, preserved, baselineTree, candidateTree, patchSha256: accepted.patchSha256, accepted: true, sites: ['rh', '008', '010'], roundPaths: paths, variants: {} };
  for (const [variant, commit] of [['rh', baseCommit], ['existing-report-month', '5181674d17eea941137489bc58f817af92e92ab8']]) {
    const report = '前端代码/cashier-v3/src/views/StoreBusinessReportView.vue';
    isolated(['read-tree', baselineTree]); put(report, git(['show', commit + ':' + report]));
    const boundBaseline = isolated(['write-tree']).toString().trim();
    for (const f of accepted.files) put(f.path, fs.readFileSync(f.path));
    const boundCandidate = isolated(['write-tree']).toString().trim();
    const boundDir = release + '/' + variant; fs.mkdirSync(boundDir, { recursive: true });
    const bound = { canonicalGit: root, baselineTree: boundBaseline, candidateTree: boundCandidate, sourceCommit: commit, historicalReportOnly: true };
    fs.writeFileSync(boundDir + '/trees.json', JSON.stringify(bound, null, 2) + '\n', { flag: 'wx' });
    plan.variants[variant] = bound;
  }
  if (sha(fs.readFileSync(sharedIndex)) !== indexHash || git(['rev-parse', 'HEAD']).toString().trim() !== head) throw Error('Shared state changed');
  fs.writeFileSync(release + '/plan.json', JSON.stringify(plan, null, 2) + '\n', { flag: 'wx' });
  console.log(JSON.stringify(plan, null, 2));
} finally { fs.rmSync(temp, { recursive: true }); }
