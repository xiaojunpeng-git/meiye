// Immutable R53 release input only. Never switch the shared checkout/index or
// infer source from downloaded server code. All preserved bytes are Git objects.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), os = require('os');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R53-release';
const git = (args, input, env = process.env) => cp.execFileSync('git', args, { input, env, maxBuffer: 128 * 1024 * 1024 });
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const base = git(['rev-parse', '461dd8d1']).toString().trim();
const accepted = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R53-card-to-balance/fixed-files.json'));
const sourceFiles = Object.values(accepted.files).flat().map(file => file.path);
for (const file of Object.values(accepted.files).flat()) {
  if (hash(fs.readFileSync(file.path)) !== file.sha256) throw Error('Accepted file changed: ' + file.path);
}
const preserved = [
  '后端代码/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php',
  '后端代码/app/services/cashier/v3/card/CashierV3CardOperationKernel.php',
];
const preservedCommit = git(['rev-parse', 'd27d1317']).toString().trim();
const indexPath = git(['rev-parse', '--git-path', 'index']).toString().trim();
const sharedIndexBefore = hash(fs.readFileSync(indexPath));
const index = path.join(os.tmpdir(), 'mohe-r53-card-balance-index-' + process.pid);
const env = { ...process.env, GIT_INDEX_FILE: index };
const put = (file, bytes) => {
  const blob = git(['hash-object', '-w', '--stdin'], bytes).toString().trim();
  git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
  return { sha256: hash(bytes), blob };
};

// Apply only explicitly classified hunks to a fixed baseline. Hunk old bytes
// must match exactly; no fuzzy merge and no second editable application tree.
function selectHunks(file, baseline, include) {
  let patch;
  try {
    patch = cp.execFileSync('diff', ['-u', '--label', 'a/' + file, '--label', 'b/' + file, '-', file],
      { input: baseline, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  } catch (error) {
    if (error.status !== 1) throw error;
    patch = error.stdout;
  }
  let result = baseline.toString();
  const chunks = patch.split(/(?=^@@)/m).slice(1);
  for (const [index, hunk] of chunks.entries()) {
    if (!include(hunk, index)) continue;
    const lines = hunk.split('\n').slice(1).filter(line => /^[ +\-]/.test(line));
    const before = lines.filter(line => line[0] !== '+').map(line => line.slice(1)).join('\n') + '\n';
    const after = lines.filter(line => line[0] !== '-').map(line => line.slice(1)).join('\n') + '\n';
    if (!before || result.split(before).length !== 2) throw Error('Ambiguous fixed hunk: ' + file + ':' + index);
    result = result.replace(before, after);
  }
  return Buffer.from(result);
}

fs.mkdirSync(evidence, { recursive: true });
try {
  git(['read-tree', base], undefined, env);
  const preservedFiles = {};
  const beforeSites = ['rh', '008', '010'].map(site => JSON.parse(fs.readFileSync(evidence + '/' + site + '-before.json')));
  for (const file of preserved) {
    const bytes = git(['show', preservedCommit + ':' + file]);
    if (!beforeSites.some(site => site.backend[file.replace('后端代码/', '')] === hash(bytes))) {
      throw Error('Preserved dependency has no observed deployed match: ' + file);
    }
    preservedFiles[file] = { ...put(file, bytes), sourceCommit: preservedCommit };
  }
  const baselineTree = git(['write-tree'], undefined, env).toString().trim();
  const candidates = {};
  for (const file of sourceFiles) {
    let bytes = fs.readFileSync(file);
    if (file.endsWith('/CashierShell.vue')) {
      bytes = selectHunks(file, git(['show', baselineTree + ':' + file]), hunk => !hunk.includes('selectMemberCreatorServicePerson'));
    } else if (file.endsWith('/CashierWorkbenchView.vue')) {
      bytes = selectHunks(file, git(['show', baselineTree + ':' + file]), hunk =>
        !hunk.includes('guideSelections: clonePlain(intent.guideSelections') && !hunk.includes('cart-line__meta-slot--coupon'));
    }
    candidates[file] = { ...put(file, bytes), hotSHA256: hash(fs.readFileSync(file)), identicalToHot: hash(bytes) === hash(fs.readFileSync(file)) };
  }
  const candidateTree = git(['write-tree'], undefined, env).toString().trim();
  const patch = git(['diff', baselineTree, candidateTree]);
  const baselinePatch = git(['diff', base, baselineTree]);
  fs.writeFileSync(evidence + '/r53-only.patch', patch, { flag: 'wx' });
  fs.writeFileSync(evidence + '/preserved-baseline.patch', baselinePatch, { flag: 'wx' });
  const record = { canonicalGit: root, head: git(['rev-parse', 'HEAD']).toString().trim(), baselineCommit: base,
    baselineTree, candidateTree, preservedFiles, paths: sourceFiles, candidates,
    patchSHA256: hash(patch), preservedPatchSHA256: hash(baselinePatch), sharedIndexBefore,
    sharedIndexUntouched: hash(fs.readFileSync(indexPath)) === sharedIndexBefore,
    acceptedFingerprintFile: 'tests/cashier-v3/evidence/R53-card-to-balance/fixed-files.json',
    supplementalAcceptanceRequired: true,
    excluded: ['member creator multi-person selection', 'guest coupon entry hiding', 'unrelated repayment property reordering'],
    authorization: { sites: ['rh', '008', '010'], rhFormalAndPreview: true, migrationsAndExistingCashierGrants: true,
      noHistoricalBusinessRewrite: true, platformStaticUnchanged: true } };
  fs.writeFileSync(evidence + '/trees.json', JSON.stringify(record, null, 2) + '\n', { flag: 'wx' });
  console.log(JSON.stringify({ baselineTree, candidateTree, preserved: preserved.length, files: sourceFiles.length,
    differsFromHot: Object.entries(candidates).filter(([, file]) => !file.identicalToHot).map(([file]) => file), sharedIndexUntouched: record.sharedIndexUntouched }));
} finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
