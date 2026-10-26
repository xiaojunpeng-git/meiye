// Fixed R52 preparation only: canonical Git objects, explicit files and read-only instance probes.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), os = require('os');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const release = path.join(root, 'tests/order-center/evidence/r52-release-v2');
const git = (args, input, env = process.env) => cp.execFileSync('git', args, { cwd: root, input, env, maxBuffer: 128 * 1024 * 1024 });
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const base = git(['rev-parse', '77173ee8']).toString().trim();
const preservedPaths = ['AbstractCashierV3OrderCenterUnifiedQueryProvider.php', 'CashierV3OrderCenterUnifiedQueryContract.php'].map(file => '后端代码/app/services/cashier/v3/order/' + file);
const preservedCommit = git(['rev-parse', 'cb03288e']).toString().trim();
const baseline = file => git(['show', `${preservedPaths.includes(file) ? preservedCommit : base}:${file}`]).toString();
const replace = (text, before, after) => { if (!before || text.split(before).length !== 2) throw Error('Ambiguous R52 anchor: ' + before.slice(0, 100)); return text.replace(before, after); };
const save = (file, value) => fs.writeFileSync(path.join(release, file), JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });
fs.mkdirSync(release, { recursive: true });
if (process.argv[2] === 'probe') {
  for (const site of ['rh', '008', '010']) {
    const data = cp.execFileSync('ssh', ['-q', '-o', 'BatchMode=yes', site === 'rh' ? 'rh-server' : 'mohe-server', `/www/server/php/74/bin/php -- ${site}`], { input: fs.readFileSync('scripts/r28-permissions-inspect.php'), maxBuffer: 32 * 1024 * 1024 });
    save(`${site}-before.json`, JSON.parse(data)); console.log('READ_ONLY_PROBE', site);
  }
} else if (process.argv[2] === 'prepare') {
  const accepted = JSON.parse(fs.readFileSync('tests/order-center/evidence/r52-report-filters/result.json'));
  for (const [file, digest] of Object.entries(accepted.fingerprints)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted source changed: ' + file);
  const paths = Object.keys(accepted.fingerprints);
  const provider = '后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php';
  let backend = baseline(provider), work = fs.readFileSync(provider, 'utf8');
  // The hot workspace also contains unrelated category/read-model changes. Only empty-source normalization belongs to R52.
  const helper = work.match(/    \/\*\* 旧单据空串[^\n]+\n    protected static function filterableAllOrderSource[\s\S]*?\n    }\n/)[0];
  if (backend.includes('function filterableAllOrderSource')) throw Error('Baseline now owns source normalization; re-review');
  backend = replace(backend, '    protected function allSalesRows(', helper + '\n    protected function allSalesRows(');
  const marker = "            if ($type === 'sales') {\n                // 直接使用表格的权威明细";
  const normalized = "            // 来源控件在各页签共用 is_null；必须在统一投影归一，而不能只处理混合页。\n            if (array_key_exists('source', $row)) $row['source'] = self::filterableAllOrderSource($row['source']);\n";
  backend = replace(backend, marker, normalized + marker);
  const head = git(['rev-parse', 'HEAD']).toString().trim();
  const indexPath = git(['rev-parse', '--git-path', 'index']).toString().trim();
  const indexBefore = sha(fs.readFileSync(indexPath));
  const privateIndex = path.join(os.tmpdir(), 'mohe-r52-order-query-index-' + process.pid);
  const env = { ...process.env, GIT_INDEX_FILE: privateIndex };
  const candidates = {};
  try {
    git(['read-tree', base], undefined, env);
    const preserved = {};
    for (const file of preservedPaths) {
      const bytes = Buffer.from(baseline(file));
      const blob = git(['hash-object', '-w', '--stdin'], bytes).toString().trim();
      git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
      preserved[file] = { sourceCommit: preservedCommit, sha256: sha(bytes), blob };
    }
    const baselineTree = git(['write-tree'], undefined, env).toString().trim();
    for (const file of paths) {
      const bytes = file === provider ? Buffer.from(backend) : fs.readFileSync(file);
      const blob = git(['hash-object', '-w', '--stdin'], bytes).toString().trim();
      git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
      candidates[file] = { sha256: sha(bytes), blob, hotSHA256: sha(fs.readFileSync(file)), identicalToHot: sha(bytes) === sha(fs.readFileSync(file)) };
    }
    const tree = git(['write-tree'], undefined, env).toString().trim();
    const patch = git(['diff', baselineTree, tree, '--', ...paths]);
    fs.writeFileSync(path.join(release, 'r52-only.patch'), patch, { flag: 'wx' });
    save('trees.json', { canonicalGit: root, head, baselineCommit: base, baselineTree, candidateTree: tree, paths, preserved,
      acceptedFingerprintFile: 'tests/order-center/evidence/r52-report-filters/result.json', candidates,
      sharedIndexBefore: indexBefore, sharedIndexUntouched: sha(fs.readFileSync(indexPath)) === indexBefore,
      scope: 'R52 nine order pages only; R31/R32 and R51 preserved; no migration; RH formal+preview authorized',
      candidateRequiresSupplementalAcceptance: true });
    console.log(JSON.stringify({ candidateTree: tree, baselineCommit: base, files: paths.length, backendBefore: sha(baseline(provider)), backendAfter: sha(backend) }));
  } finally { if (fs.existsSync(privateIndex)) fs.unlinkSync(privateIndex); }
} else throw Error('Expected probe|prepare');
