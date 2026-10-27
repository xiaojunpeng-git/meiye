// Release preparation reads canonical Git objects and explicitly accepted files.
// A private index protects the shared dirty checkout; remote probes are read-only.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R54-release';
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const git = (args, input, env = process.env) => cp.execFileSync('git', args, { input, env, maxBuffer: 128 * 1024 * 1024 });
const save = (name, value) => fs.writeFileSync(path.join(evidence, name), JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });
fs.mkdirSync(evidence, { recursive: true });
const accepted = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R54-query-period/fixed-files.json'));
for (const [file, digest] of Object.entries(accepted.files)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted source drift: ' + file);
if (process.argv[2] === 'probe') {
  for (const site of ['rh', '008', '010']) {
    const bytes = cp.execFileSync('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', site === 'rh' ? 'rh-server' : 'mohe-server', '/www/server/php/74/bin/php -- ' + site],
      { input: fs.readFileSync('scripts/r28-permissions-inspect.php'), maxBuffer: 32 * 1024 * 1024 });
    const result = JSON.parse(bytes); if (result.site !== site) throw Error('Probe target mismatch');
    save(site + '-before.json', result);
    console.log(site, result.frontends.filter(x => x.kind === 'cashier').map(x => Object.keys(x.assets).join(',')));
  }
  process.exit(0);
}
if (process.argv[2] !== 'prepare') throw Error('probe|prepare required');
const base = git(['rev-parse', '132c0912']).toString().trim();
const indexPath = git(['rev-parse', '--git-path', 'index']).toString().trim();
const before = sha(fs.readFileSync(indexPath));
const index = '/private/tmp/mohe-r54-service-period-' + process.pid + '.index';
const env = { ...process.env, GIT_INDEX_FILE: index };
const put = (file, bytes) => {
  const blob = git(['hash-object', '-w', '--stdin'], bytes).toString().trim();
  git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
};
try {
  git(['read-tree', base], undefined, env);
  const retainedCommit = git(['rev-parse', 'e43cc910']).toString().trim();
  const retained = git(['-c', 'core.quotepath=false', 'diff-tree', '--no-commit-id', '--name-only', '-r', retainedCommit]).toString().trim().split('\n');
  for (const file of retained) {
    const bytes = git(['show', retainedCommit + ':' + file]);
    if (file.startsWith('后端代码/app/')) {
      for (const site of ['rh', '008', '010']) {
        const live = JSON.parse(fs.readFileSync(path.join(evidence, site + '-before.json')));
        if (live.backend[file.slice('后端代码/'.length)] !== sha(bytes)) throw Error('Retained inventory backend not live: ' + site + file);
      }
    }
    put(file, bytes);
  }
  const amountFile = '后端代码/app/services/cashier/v3/card/CashierV3CardComponentPurchaseAmountAuthority.php';
  const amountCommit = git(['rev-parse', '30582fd0']).toString().trim();
  const amountBytes = git(['show', amountCommit + ':' + amountFile]);
  if (sha(amountBytes) !== accepted.files[amountFile]) throw Error('Accepted amount dependency changed');
  for (const site of ['rh', '008', '010']) {
    const live = JSON.parse(fs.readFileSync(path.join(evidence, site + '-before.json')));
    const observed = live.backend[amountFile.slice('后端代码/'.length)];
    const expected = site === '010' ? sha(git(['show', base + ':' + amountFile])) : sha(amountBytes);
    if (observed !== expected) throw Error('Amount baseline not recognized: ' + site);
  }
  put(amountFile, amountBytes);
  const baselineTree = git(['write-tree'], undefined, env).toString().trim();
  // Member overlay contains only this round's failure and period changes.
  const member = '前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue';
  put(member, fs.readFileSync(member));
  // Apply the captured period-only delta, excluding the overlay already above.
  // Do not inherit unrelated report changes that happen to share these files.
  const patch = fs.readFileSync('tests/cashier-v3/evidence/R54-query-period/period-only.patch');
  const employee = '前端代码/cashier-v3/src/views/EmployeeDashboardView.vue';
  git(['apply', '--cached', '--check', '--exclude=' + member, '--exclude=' + employee, '-'], patch, env);
  git(['apply', '--cached', '--exclude=' + member, '--exclude=' + employee, '-'], patch, env);
  // The employee template has an unrelated no-scope state on the next line.
  // Transfer only exact changed blocks, retaining that baseline line unchanged.
  const employeePart = patch.toString().split('--- a/' + employee + '\n')[1].split('\n--- a/')[0];
  let employeeBytes = git(['show', base + ':' + employee]).toString();
  for (const hunk of employeePart.split(/(?=^@@)/m).slice(1)) {
    const lines = hunk.split('\n').slice(1).filter(line => /^[ +\-]/.test(line));
    while (lines[0]?.startsWith(' ')) lines.shift();
    while (lines.at(-1)?.startsWith(' ')) lines.pop();
    const old = lines.filter(l => l[0] !== '+').map(l => l.slice(1)).join('\n') + '\n';
    const next = lines.filter(l => l[0] !== '-').map(l => l.slice(1)).join('\n') + '\n';
    if (old === '\n') {
      const full = hunk.split('\n').slice(1).filter(line => /^[ +\-]/.test(line));
      const oldContext = full.filter(l => l[0] !== '+').map(l => l.slice(1)).join('\n') + '\n';
      const nextContext = full.filter(l => l[0] !== '-').map(l => l.slice(1)).join('\n') + '\n';
      if (employeeBytes.split(oldContext).length !== 2) throw Error('Ambiguous employee insertion');
      employeeBytes = employeeBytes.replace(oldContext, nextContext);
    } else {
      if (employeeBytes.split(old).length !== 2) throw Error('Employee changed bytes mismatch');
      employeeBytes = employeeBytes.replace(old, next);
    }
  }
  put(employee, Buffer.from(employeeBytes));
  const shell = '前端代码/cashier-v3/src/layouts/CashierShell.vue';
  let diff;
  try { diff = cp.execFileSync('diff', ['-u', '--label', 'a/' + shell, '--label', 'b/' + shell, '-', shell], { input: git(['show', base + ':' + shell]), maxBuffer: 16 * 1024 * 1024 }).toString(); }
  catch (error) { if (error.status !== 1) throw error; diff = error.stdout.toString(); }
  const hunks = diff.split(/(?=^@@)/m).slice(1).filter(h => !h.includes('selectMemberCreatorServicePerson'));
  const shellPatch = Buffer.from('--- a/' + shell + '\n+++ b/' + shell + '\n' + hunks.join(''));
  git(['apply', '--cached', '--check', '-'], shellPatch, env); git(['apply', '--cached', '-'], shellPatch, env);
  for (const file of ['后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php',
    'scripts/r54-member-service-dependency-gate.cjs', 'tests/cashier-v3/js/r54-member-service-failure.cjs',
    'tests/cashier-v3/js/r54-query-period.cjs', 'tests/cashier-v3/php/r54-member-service-readonly.php']) put(file, fs.readFileSync(file));
  const candidateTree = git(['write-tree'], undefined, env).toString().trim();
  git(['diff', '--check', baselineTree, candidateTree]);
  const delta = git(['diff', baselineTree, candidateTree]);
  fs.writeFileSync(path.join(evidence, 'r54-only.patch'), delta, { flag: 'wx' });
  const paths = git(['-c', 'core.quotepath=false', 'diff', '--name-only', baselineTree, candidateTree]).toString().trim().split('\n');
  if (paths.length !== 16) throw Error('Expected 16 scoped files, got ' + paths.length);
  if (sha(fs.readFileSync(indexPath)) !== before) throw Error('Shared index changed');
  const record = { canonicalGit: root, head: git(['rev-parse', 'HEAD']).toString().trim(), baselineCommit: base, baselineTree, candidateTree, paths,
    preservedFiles: { ...Object.fromEntries(retained.map(file => [file, { sourceCommit: retainedCommit }])), [amountFile]: { sourceCommit: amountCommit } }, sharedIndexBefore: before, sharedIndexUntouched: true, patchSHA256: sha(delta),
    acceptedFingerprintFile: 'tests/cashier-v3/evidence/R54-query-period/fixed-files.json',
    excluded: ['member multi-person selection', 'unaccepted report business changes', 'guest coupon visibility', 'other shared workspace edits'],
    authorization: { sites: ['rh', '008', '010'], rhFormalAndPreview: true, databaseMigrations: false, platformStaticUnchanged: true },
    supplementalAcceptanceRequired: true };
  save('trees.json', record); console.log(JSON.stringify(record, null, 2));
} finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
