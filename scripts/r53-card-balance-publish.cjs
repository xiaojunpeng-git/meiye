// Explicit R53 sequence only: private-index commits -> RH -> push -> 008 ->010.
// Read/write operations are instance-bound; all sources originate in canonical
// Git objects, with independent full DB/file backups and guarded rollback.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), os = require('os');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R53-release';
const sites = {
  rh: { host: 'rh-server', domain: 'rh.cc3798.com', restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '" },
  '008': { host: 'mohe-server', domain: '008.cc3798.com', restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service' },
  '010': { host: 'mohe-server', domain: '010.cc3798.com', restart: 'systemctl restart mohe-010-swoole.service', health: 'systemctl is-active --quiet mohe-010-swoole.service' },
};
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const run = (bin, args, options = {}) => cp.execFileSync(bin, args, { maxBuffer: 256 * 1024 * 1024, ...options });
const git = (args, input, env = process.env) => run('git', args, { input, env });
const load = name => JSON.parse(fs.readFileSync(path.join(evidence, name)));
const save = (name, value) => fs.writeFileSync(path.join(evidence, name), JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });
const ssh = (site, command, input) => {
  if (!sites[site]) throw Error('Explicit instance required');
  return run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3', sites[site].host, command], { input });
};
const probe = site => JSON.parse(ssh(site, '/www/server/php/74/bin/php -- ' + site, fs.readFileSync('scripts/r28-permissions-inspect.php')));
function ready() {
  const plan = load('trees.json');
  if (plan.candidateTree !== '0ff03c6478b16b36683ff58f680b8250b8f35d46') throw Error('Candidate changed; supplemental acceptance required');
  const accepted = load('acceptance.json');
  if (!accepted.productManagerConfirmed || accepted.candidateTree !== plan.candidateTree) throw Error('Fixed candidate not accepted');
  for (const file of Object.values(JSON.parse(fs.readFileSync(plan.acceptedFingerprintFile)).files).flat()) {
    if (hash(fs.readFileSync(file.path)) !== file.sha256) throw Error('Accepted shared file changed: ' + file.path);
  }
  const fixed = load('fixed-tests.json');
  if (fixed.result !== 'PASS' || !fixed.sourceStable || fixed.tree !== plan.candidateTree) throw Error('Fixed tests not complete');
  if (load('fixed-browser/result.json').status !== 'PASS') throw Error('Fixed browser regression missing');
  for (const variant of ['baseline', 'candidate']) {
    const build = load(variant + '-cashier-v3-build.json');
    if (build.result !== 'PASS' || !build.sourceStable || build.tree !== plan[variant + 'Tree']) throw Error('Build drift');
  }
  const old = load('baseline-cashier-v3-build.json');
  for (const site of Object.keys(sites)) for (const entry of load(site + '-before.json').frontends.filter(entry => entry.kind === 'cashier')) {
    for (const [asset, digest] of Object.entries(entry.assets)) if (old.assets[asset.replace('/view_cashier_v3/', '')] !== digest) throw Error('Old package not reproducible: ' + site);
  }
  return plan;
}
function commit() {
  const plan = ready();
  if (git(['rev-parse', 'HEAD']).toString().trim() !== plan.head) throw Error('Shared HEAD changed');
  const index = git(['rev-parse', '--git-path', 'index']).toString().trim();
  const before = hash(fs.readFileSync(index));
  const branch = 'release/r53-card-balance-20261024';
  try { git(['show-ref', '--verify', '--quiet', 'refs/heads/' + branch]); throw Error('Release branch already exists'); }
  catch (error) { if (error.status !== 1) throw error; }
  const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', plan.baselineTree, plan.candidateTree]).toString().trim().split('\n');
  if (JSON.stringify(changed.slice().sort()) !== JSON.stringify(plan.paths.slice().sort())) throw Error('Commit boundary mismatch');
  const dependency = git(['commit-tree', plan.baselineTree, '-p', plan.baselineCommit],
    Buffer.from('R53-BASE 独立保留已上线整元卡操作依赖\n\n两份业务文件来源d27d1317；保留当前R94及R52前端基线，不计作本轮新增功能。\n')).toString().trim();
  const current = git(['commit-tree', plan.candidateTree, '-p', dependency],
    Buffer.from('R53-00053 卡转赠金与完整卡操作记录闭环\n\n仅本轮47个源码、迁移及测试文件；产品经理固定候选补验通过；三站逐实例部署另行核验。\n')).toString().trim();
  git(['update-ref', 'refs/heads/' + branch, current, '0'.repeat(40)]);
  if (hash(fs.readFileSync(index)) !== before || git(['rev-parse', 'HEAD']).toString().trim() !== plan.head) throw Error('Shared checkout/index changed');
  save('commit.json', { commit: current, dependencyCommit: dependency, branch, candidateTree: plan.candidateTree,
    files: changed, sharedIndexUntouched: true, productManagerAccepted: true });
  console.log('COMMITTED', current);
}
function assertAffectedBefore(site, before, plan) {
  const actual = probe(site);
  const affected = [...plan.paths.filter(file => file.startsWith('后端代码/app/')),
    ...Object.keys(plan.preservedFiles)].map(file => file.replace('后端代码/', ''));
  for (const file of new Set(affected)) if ((actual.backend[file] || null) !== (before.backend[file] || null)) throw Error('Affected live backend changed: ' + site + file);
  const cashier = value => value.frontends.filter(entry => entry.kind === 'cashier');
  if (JSON.stringify(cashier(actual)) !== JSON.stringify(cashier(before))) throw Error('Live cashier package changed: ' + site);
  return actual;
}
function targetDigests(site, keys) {
  const encoded = Buffer.from(JSON.stringify(keys)).toString('base64');
  const code = `<?php $r='/www/wwwroot/${sites[site].domain}';$out=[];foreach(json_decode(base64_decode('${encoded}'),true)as $k){$p=strpos($k,'preview/')===0?$r.'-8080/'.substr($k,8):$r.'/'.substr($k,7);$out[$k]=is_file($p)?hash_file('sha256',$p):null;}echo json_encode($out);`;
  return JSON.parse(ssh(site, '/www/server/php/74/bin/php', Buffer.from(code)));
}
function deploy(site, resumeUpload = false, resumeBackup = false, retryRollback = false) {
  if (!sites[site]) throw Error('Explicit authorized site required');
  const plan = ready(), receipt = load('commit.json');
  if (site !== 'rh') {
    if (load('push.json').result !== 'PASS') throw Error('RH verification and push required');
    for (const previous of site === '008' ? ['rh'] : ['rh', '008']) if (load(previous + '-verified.json').result !== 'PASS') throw Error('Prior instance not verified');
  }
  const before = assertAffectedBefore(site, load(site + '-before.json'), plan);
  if (!resumeUpload) save(site + '-preswitch.json', before);
  else assertAffectedBefore(site, load(site + '-preswitch.json'), plan);
  const backend = [...new Set([...plan.paths.filter(file => file.startsWith('后端代码/')), ...Object.keys(plan.preservedFiles)])];
  const files = {};
  for (const file of backend) files['formal/' + file.replace('后端代码/', '')] = git(['show', receipt.commit + ':' + file]);
  const build = load('candidate-cashier-v3-build.json');
  for (const [relative, digest] of Object.entries(build.assets)) {
    const bytes = fs.readFileSync(path.join(build.output, relative));
    if (hash(bytes) !== digest) throw Error('Built payload changed');
    files['formal/public/view_cashier_v3/' + relative] = bytes;
    if (site === 'rh') files['preview/public/view_cashier_v3/' + relative] = bytes;
  }
  const old = targetDigests(site, Object.keys(files));
  for (const file of backend.filter(file => file.startsWith('后端代码/app/'))) {
    const key = file.replace('后端代码/', '');
    if (old['formal/' + key] !== (before.backend[key] || null)) throw Error('Affected target drift');
  }
  const targets = Object.fromEntries(Object.entries(files).map(([key, bytes]) => [key, { before: old[key], after: hash(bytes) }]));
  const manifest = { site, commit: receipt.commit, candidateTree: plan.candidateTree, targets,
    database: 'independent full backup; explicit catalog, cashier grants and two indexes; no historical business writes', platformStatic: 'unchanged' };
  const bytes = Buffer.from(JSON.stringify(manifest)); const manifestHash = hash(bytes);
  const directory = '/www/backups/' + site + '/r53-card-balance-' + receipt.commit.slice(0, 8);
  const stage = path.join('/private/tmp/mohe-r53-card-balance-release-20261024', 'payload-' + site);
  if (!resumeUpload) fs.mkdirSync(stage);
  for (const [name, body] of Object.entries({ ...Object.fromEntries(Object.entries(files).map(([key, body]) => ['payload/' + key, body])),
    'payload/manifest.json': bytes, 'files.php': fs.readFileSync('scripts/r53-card-balance-files.php'), 'database.php': fs.readFileSync('scripts/r53-card-balance-database.php') })) {
    const destination = path.join(stage, name); fs.mkdirSync(path.dirname(destination), { recursive: true });
    if (!resumeUpload) fs.writeFileSync(destination, body, { flag: 'wx', mode: 0o444 });
    else if (resumeBackup && ['database.php', 'files.php'].includes(name)) {
      // Only the backup control changes; every business payload remains frozen.
      fs.chmodSync(destination, 0o644); fs.writeFileSync(destination, body); fs.chmodSync(destination, 0o444);
    } else if (!fs.existsSync(destination) || hash(fs.readFileSync(destination)) !== hash(body)) throw Error('Interrupted stage changed: ' + name);
  }
  const archive = run('tar', ['-czf', '-', '-C', stage, '.']);
  if (!resumeUpload) ssh(site, `test ! -e '${directory}' && mkdir -m 700 -p '${directory}'`);
  // Resume is permitted only for a lost upload before any backup/live phase.
  // Later failures require the recorded rollback path, not a blind rerun.
  else if (retryRollback) {
    if (site !== '010' || load('010-rollback.json').result !== 'PASS') throw Error('Safe 010 rollback required');
    ssh(site, `test -f '${directory}/database-rollback-safe.json' && test -f '${directory}/database-backup-waiver.json' && test -f '${directory}/prepared.json' && test ! -e '${directory}/metadata-after.json'`);
    const current = targetDigests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (current[file] !== row.before) throw Error('Rollback target not restored: ' + file);
  } else ssh(site, `test -d '${directory}' && test ! -e '${directory}/database-backup.json' ${resumeBackup ? '' : "&& test ! -e '" + directory + "/database-before.sql'"} && test ! -e '${directory}/prepared.json' && test ! -e '${directory}/metadata-after.json'`);
  if (resumeBackup) {
    if (site !== '010') throw Error('Backup continuation is 010 only');
    // Upload just the engineering control, not the already fingerprinted app.
    for (const [name, file] of [['database.php', 'database'], ['files.php', 'files']]) {
      const code = fs.readFileSync('scripts/r53-card-balance-' + file + '.php');
      ssh(site, `/www/server/php/74/bin/php -r 'file_put_contents($argv[1], stream_get_contents(STDIN));' '${directory}/${name}'`, code);
    }
    const approval = load('010-backup-waiver-authorization.json');
    if (approval.commit !== receipt.commit || !approval.explicitUserWaiver) throw Error('Explicit user waiver required');
    ssh(site, `/www/server/php/74/bin/php -r 'file_put_contents($argv[1], stream_get_contents(STDIN));chmod($argv[1],0600);' '${directory}/010-backup-waiver-authorization.json'`, Buffer.from(JSON.stringify(approval)));
    const stagedHash = ssh(site, `sha256sum '${directory}/payload/manifest.json'`).toString().split(/\s/)[0];
    if (stagedHash !== manifestHash) throw Error('Staged manifest changed');
  } else ssh(site, `tar -xzf - -C '${directory}'`, archive);
  assertAffectedBefore(site, before, plan);
  if (!resumeBackup) save(site + '-manifest.json', manifest);
  else if (hash(Buffer.from(JSON.stringify(load(site + '-manifest.json')))) !== manifestHash) throw Error('Recorded manifest changed');
  const invoke = (script, mode) => ssh(site, `/www/server/php/74/bin/php '${directory}/${script}.php' '${directory}' '${mode}' '${manifestHash}'`);
  // Independent full backups remain mandatory except the explicitly waived
  // unused 010 test database; target-file rollback material is still retained.
  if (!retryRollback) {
    fs.writeFileSync(path.join(evidence, site + (resumeBackup ? '-database-backup-waiver.log' : '-database-backup.log')), invoke('database', resumeBackup ? 'waive-backup' : 'backup'));
    fs.writeFileSync(path.join(evidence, site + '-file-backup.log'), invoke('files', 'backup'));
  }
  let switched = false;
  try {
    fs.writeFileSync(path.join(evidence, site + '-migration-files.log'), invoke('files', 'migrations')); switched = true;
    const indexOutput = site === '010'
      ? ssh(site, `/www/server/php/74/bin/php -- '${directory}' '${manifestHash}'`, fs.readFileSync('scripts/r53-card-balance-indexes-010.php'))
      : ssh(site, `/www/server/php/74/bin/php '/www/wwwroot/${sites[site].domain}/database/upgrades/2026-10-24-卡转余额/03-补交关联锁定索引.php' --apply`);
    fs.writeFileSync(path.join(evidence, site + '-indexes.json'), indexOutput);
    fs.writeFileSync(path.join(evidence, site + '-backend-install.log'), invoke('files', 'backend'));
    const migration = JSON.parse(invoke('database', 'migrate')); save(site + '-metadata-migration.json', migration);
    fs.writeFileSync(path.join(evidence, site + '-entry-install.log'), invoke('files', 'entry'));
    ssh(site, 'set -e; ' + sites[site].restart + '; sleep 2; ' + sites[site].health);
    const after = probe(site);
    const actual = targetDigests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (actual[file] !== row.after) throw Error('Installed target mismatch: ' + file);
    if (JSON.stringify(after.protected) !== JSON.stringify(before.protected)) throw Error('Protected instance configuration/static changed');
    const platform = value => value.frontends.filter(entry => entry.kind === 'admin');
    if (JSON.stringify(platform(after)) !== JSON.stringify(platform(before))) throw Error('Platform static changed');
    const runtime = JSON.parse(ssh(site, '/www/server/php/74/bin/php -- ' + site + ' after', fs.readFileSync('scripts/r53-card-balance-runtime.php')));
    if (runtime.result !== 'PASS' || !runtime.readOnly) throw Error('Runtime read verification failed');
    for (const [file, digest] of Object.entries(runtime.codeSources)) if (targets['formal/' + file]?.after !== digest) throw Error('Runtime source mismatch');
    save(site + '-runtime-after.json', runtime); save(site + '-after.json', after);
    save(site + '-installed.json', { result: 'PASS', site, commit: receipt.commit, backup: directory,
      files: Object.keys(targets).length, changedPositions: migration.changedPositions, publicVerificationPending: true });
    console.log('INSTALLED_PENDING_PUBLIC_VERIFICATION', site, receipt.commit);
  } catch (error) {
    if (switched) {
      try {
        fs.writeFileSync(path.join(evidence, site + '-metadata-rollback.log'), invoke('database', 'rollback'));
        fs.writeFileSync(path.join(evidence, site + '-files-rollback.log'), invoke('files', 'rollback'));
        ssh(site, 'set -e; ' + sites[site].restart + '; sleep 2; ' + sites[site].health);
        save(site + '-rollback.json', { result: 'PASS', backup: directory, retainedCompatibleIndexes: true, error: error.message });
      } catch (rollback) { save(site + '-rollback.json', { result: 'MANUAL_ATTENTION_REQUIRED', backup: directory, error: rollback.message }); }
    }
    throw error;
  }
}
function push() {
  ready(); const receipt = load('commit.json');
  if (load('rh-verified.json').result !== 'PASS') throw Error('RH runtime and public verification must pass first');
  const ref = 'refs/heads/' + receipt.branch;
  const log = git(['push', 'origin', ref + ':' + ref]);
  fs.writeFileSync(path.join(evidence, 'push.log'), log);
  if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== receipt.commit) throw Error('Push verification failed');
  save('push.json', { result: 'PASS', commit: receipt.commit, branch: receipt.branch }); console.log('PUSH_VERIFIED', receipt.commit);
}
function verify(site) {
  ready();
  if (!sites[site]) throw Error('Explicit instance required');
  const receipt = load('commit.json'), installed = load(site + '-installed.json');
  const publicRows = load(site + '-public-browser.json'), runtime = load(site + '-runtime-after.json');
  if (installed.result !== 'PASS' || installed.commit !== receipt.commit || runtime.result !== 'PASS'
      || publicRows.length !== (site === 'rh' ? 3 : 2) || publicRows.some(row => row.result !== 'PASS' || row.commit !== receipt.commit)) throw Error('Instance verification incomplete');
  const targets = load(site + '-manifest.json').targets;
  const actual = targetDigests(site, Object.keys(targets));
  for (const [file, row] of Object.entries(targets)) if (actual[file] !== row.after) throw Error('Changed since public verification: ' + file);
  save(site + '-verified.json', { ...installed, publicVerificationPending: false, publicEntries: publicRows.length,
    staffPermissionSamples: runtime.actualStaffPermissionChecks.length,
    limits: ['Public browser verifies unauthenticated boot, not an operator financial workflow.',
      ...(runtime.staffSampleLimit ? [runtime.staffSampleLimit] : [])] });
  console.log('INSTANCE_VERIFIED', site, receipt.commit);
}
// Archive only release controls and evidence after all instances pass. A private
// index preserves the shared checkout; this commit never changes deployed code.
function archive(partial = false) {
  ready(); const receipt = load('commit.json');
  for (const site of partial ? ['rh', '008'] : Object.keys(sites)) if (load(site + '-verified.json').result !== 'PASS') throw Error('Required sites must pass before archive');
  // A blocked-site archive is evidence preservation, never completion. Require
  // explicit no-live-change proof before recording the partial handoff.
  if (partial && (load('010-blocked.json').result !== 'BLOCKED_BEFORE_LIVE_CHANGE'
      || load('010-blocked-readonly.json').result !== 'UNCHANGED')) throw Error('Blocked site state not proven');
  const ref = 'refs/heads/' + receipt.branch;
  const priorArchive = fs.existsSync(path.join(evidence, 'archive-result.json')) ? load('archive-result.json') : null;
  const parent = priorArchive ? priorArchive.archiveCommit : receipt.commit;
  if (git(['rev-parse', ref]).toString().trim() !== parent) throw Error('Release branch moved');
  const priorChanges = git(['-c', 'core.quotepath=false', 'diff', '--name-only', receipt.commit, parent]).toString().trim();
  if (priorChanges && priorChanges.split('\n').some(file => !file.startsWith('scripts/r53-card-balance-')
      && !file.startsWith('tests/cashier-v3/evidence/R53-'))) throw Error('Prior archive changed deployed business source');
  const files = fs.readdirSync('scripts').filter(name => name.startsWith('r53-card-balance-')).map(name => 'scripts/' + name);
  function collect(directory) {
    for (const name of fs.readdirSync(directory)) {
      const file = path.join(directory, name), stat = fs.lstatSync(file);
      if (stat.isSymbolicLink()) throw Error('Evidence symlink denied');
      if (stat.isDirectory()) collect(file); else if (stat.isFile()) files.push(file);
    }
  }
  collect(evidence); collect('tests/cashier-v3/evidence/R53-card-to-balance');
  const index = git(['rev-parse', '--git-path', 'index']).toString().trim(), before = hash(fs.readFileSync(index));
  const head = git(['rev-parse', 'HEAD']).toString().trim();
  const privateDirectory = fs.mkdtempSync(path.join(os.tmpdir(), 'r53-archive-'));
  const env = { ...process.env, GIT_INDEX_FILE: path.join(privateDirectory, 'index') };
  try {
    git(['read-tree', parent], undefined, env);
    for (const file of files.sort()) {
      const blob = git(['hash-object', '-w', '--stdin'], fs.readFileSync(file)).toString().trim();
      git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
    }
    const tree = git(['write-tree'], undefined, env).toString().trim();
    const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', parent, tree]).toString().trim().split('\n');
    if (changed.some(file => !files.includes(file))) throw Error('Archive boundary mismatch');
    const title = partial ? '瑞昊008发布证据与010备份阻断归档' : '三站发布控制与独立核验证据归档';
    const current = git(['commit-tree', tree, '-p', parent], Buffer.from('R53-00053 ' + title + '\n\n仅工程脚本和证据，线上已部署业务仍为5fd82252。\n')).toString().trim();
    if (hash(fs.readFileSync(index)) !== before || git(['rev-parse', 'HEAD']).toString().trim() !== head) throw Error('Shared checkout changed');
    git(['update-ref', ref, current, parent]);
    git(['push', 'origin', ref + ':' + ref]);
    if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== current) throw Error('Archive push verification failed');
    save(priorArchive ? 'completion-archive-result.json' : 'archive-result.json', { result: 'PASS', publicationComplete: !partial, pendingSites: partial ? ['010'] : [], archiveCommit: current, deployedBusinessCommit: receipt.commit, files: files.length, sharedIndexUntouched: true });
    console.log('ARCHIVE_PUSH_VERIFIED', current);
  } finally { fs.rmSync(privateDirectory, { recursive: true, force: true }); }
}
const [mode, site] = process.argv.slice(2);
if (mode === 'commit') commit();
else if (mode === 'deploy') deploy(site);
else if (mode === 'resume-upload') deploy(site, true);
else if (mode === 'resume-without-db-backup') deploy(site, true, true);
else if (mode === 'retry-010-after-rollback' && site === '010') deploy(site, true, true, true);
else if (mode === 'push') push();
else if (mode === 'verify') verify(site);
else if (mode === 'archive') archive();
else if (mode === 'archive-partial') archive(true);
else throw Error('Explicit commit|deploy <site>|verify <site>|push required');
