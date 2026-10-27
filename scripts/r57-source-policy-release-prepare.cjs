// R57 release preparation only. Isolated Git indexes preserve the shared index;
// canonical accepted commits supply prior features, never downloaded server code.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const dir = 'tests/cashier-v3/evidence/R57-release';
fs.mkdirSync(dir, { recursive: true });
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const git = (args, input, env = process.env) => cp.execFileSync('git', args, { input, env, maxBuffer: 128 * 1024 * 1024 });
const save = (file, data) => fs.writeFileSync(path.join(dir, file), JSON.stringify(data, null, 2) + '\n', { flag: 'wx' });
const test = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R57-source-policy/selftest.json'));
if (test.result !== 'PASS' || test.changedDuringTests.length) throw Error('Stable selftest required');
for (const item of Object.values(test.fingerprints).flat()) if (sha(fs.readFileSync(item.file)) !== item.sha256) throw Error('Selftest drift: ' + item.file);
const head = git(['rev-parse', 'HEAD']).toString().trim();
const sharedIndex = git(['rev-parse', '--git-path', 'index']).toString().trim();
const sharedSHA = sha(fs.readFileSync(sharedIndex));
const index = '/private/tmp/mohe-r57-source-release-' + process.pid + '.index';
const env = { ...process.env, GIT_INDEX_FILE: index };
const base = git(['rev-parse', '9dc945c2']).toString().trim();
const r17 = git(['rev-parse', '8998614d']).toString().trim();
const addBlob = (file, bytes) => {
  const blob = git(['hash-object', '-w', '--stdin'], bytes).toString().trim();
  git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
};
try {
  git(['read-tree', base], undefined, env);
  // R17 was independently accepted and deployed after R56. Retain both its
  // platform entry bridge and R56 fullscreen, even though their branches diverge.
  const retained = git(['-c', 'core.quotepath=false', 'diff', '--name-only', r17 + '^', r17]).toString().trim().split('\n');
  for (const file of retained) addBlob(file, git(['show', r17 + ':' + file]));
  const baselineTree = git(['write-tree'], undefined, env).toString().trim();
  const acceptedFiles = {}, candidateFiles = {}, excluded = [];
  const files = Object.values(test.fingerprints).flat().map(x => x.file);
  for (const file of files) {
    let bytes = fs.readFileSync(file);
    acceptedFiles[file] = sha(bytes);
    if (file === '前端代码/cashier-v3/src/layouts/CashierShell.vue') {
      // The separate member multi-select task is not part of R57 acceptance.
      const start = bytes.toString().indexOf('async function selectMemberCreatorServicePerson(');
      const end = bytes.toString().indexOf('\nasync function ', start + 1);
      const old = git(['show', baselineTree + ':' + file]).toString();
      const oldStart = old.indexOf('async function selectMemberCreatorServicePerson(');
      const oldEnd = old.indexOf('\nasync function ', oldStart + 1);
      if (start < 0 || end < 0 || oldStart < 0 || oldEnd < 0) throw Error('Member selection boundary changed');
      bytes = Buffer.from(bytes.toString().slice(0, start) + old.slice(oldStart, oldEnd) + bytes.toString().slice(end));
      excluded.push({ file, scope: 'unaccepted member creator multi-select handler' });
    }
    if (file === '前端代码/cashier-v3/src/views/CashierWorkbenchView.vue') {
      const text = bytes.toString();
      const marker = '                  <!-- 优惠券实例归属于会员；游客行不提供入口，避免出现无可操作的按钮。 -->\n                  <div v-if="currentCustomerMode === \'member\' && !isEntitlementLine(line)" class="cart-line__meta-slot cart-line__meta-slot--coupon">';
      if (text.split(marker).length !== 2) throw Error('Coupon exclusion boundary changed');
      bytes = Buffer.from(text.replace(marker, '                  <div v-if="!isEntitlementLine(line)" class="cart-line__meta-slot cart-line__meta-slot--coupon">'));
      excluded.push({ file, scope: 'unaccepted guest coupon visibility' });
    }
    candidateFiles[file] = sha(bytes); addBlob(file, bytes);
  }
  const candidateTree = git(['write-tree'], undefined, env).toString().trim();
  git(['diff', '--check', baselineTree, candidateTree]);
  const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', baselineTree, candidateTree]).toString().trim().split('\n');
  if (changed.some(file => !Object.hasOwn(acceptedFiles, file))) throw Error('Unexpected change');
  const patch = git(['diff', baselineTree, candidateTree]);
  fs.writeFileSync(path.join(dir, 'r57-only.patch'), patch, { flag: 'wx' });
  if (sha(fs.readFileSync(sharedIndex)) !== sharedSHA || git(['rev-parse', 'HEAD']).toString().trim() !== head) throw Error('Shared baseline changed');
  save('trees.json', { canonicalGit: root, head, baselineCommit: base, retainedCommit: r17, retainedPaths: retained,
    liveComparisonTree: git(['rev-parse', r17 + '^{tree}']).toString().trim(), baselineTree, candidateTree,
    acceptedFiles, candidateFiles, paths: changed, excluded, patchSHA256: sha(patch), sharedIndexBefore: sharedSHA,
    sharedIndexUntouched: true, supplementalAcceptanceRequired: true,
    authorization: { sites: ['rh', '008', '010'], formalOnly: true, backupAndMigration: true,
      defaultMode: 'manual', historicalBusinessWrites: false, previewRemainsRetired: true } });
  console.log(JSON.stringify({ head, baselineTree, candidateTree, files: changed.length, excluded }, null, 2));
} finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
