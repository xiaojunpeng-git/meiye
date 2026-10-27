// Ordered release: scoped commits -> RH formal/preview -> public and readonly
// verification -> push ->008 ->010. Never replace the shared checkout/index.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R54-release';
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (bin, args, options = {}) => cp.execFileSync(bin, args, { maxBuffer: 128 * 1024 * 1024, ...options });
const git = (args, input, env = process.env) => run('git', args, { input, env });
const load = name => JSON.parse(fs.readFileSync(path.join(evidence, name)));
const save = (name, value) => fs.writeFileSync(path.join(evidence, name), JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });
const sites = { rh: { host: 'rh-server', domain: 'rh.cc3798.com', restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '" },
  '008': { host: 'mohe-server', domain: '008.cc3798.com', restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service' },
  '010': { host: 'mohe-server', domain: '010.cc3798.com', restart: 'systemctl restart mohe-010-swoole.service', health: 'systemctl is-active --quiet mohe-010-swoole.service' } };
function ssh(site, command, input) {
  if (!sites[site]) throw Error('Explicit target required');
  return run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3', sites[site].host, command], { input });
}
// Large checksum inventories are compressed in transit; never accept a partial
// JSON response after SSH loss. Retry only this idempotent read, not deployment.
const probe = site => {
  const source = fs.readFileSync('scripts/r28-permissions-inspect.php', 'utf8')
    .replace('<?php', '<?php ob_start();') + '\necho gzencode(ob_get_clean());';
  let failure;
  for (let attempt = 0; attempt < 3; attempt++) {
    try { return JSON.parse(require('zlib').gunzipSync(ssh(site, '/www/server/php/74/bin/php -- ' + site, Buffer.from(source))).toString()); }
    catch (error) { failure = error; }
  }
  throw failure;
};
const cashier = p => p.frontends.filter(x => x.kind === 'cashier');
const admin = p => p.frontends.filter(x => x.kind === 'admin');
const deps = require('./r54-member-service-dependency-gate.cjs');
function ready() {
  const p = load('trees.json'), approval = load('acceptance.json');
  if (p.candidateTree !== '523cf660337d05188c0de6db871fcc475320edca' || approval.candidateTree !== p.candidateTree
      || !approval.productManagerConfirmed || !approval.rhFormalAndPreview || !approval.complete010AmountGuard) throw Error('Final candidate acceptance missing');
  const fixed = JSON.parse(fs.readFileSync(p.acceptedFingerprintFile));
  for (const [file, digest] of Object.entries(fixed.files)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted hot source changed: ' + file);
  for (const variant of ['baseline', 'candidate']) {
    const b = load(variant + '-cashier-v3-build.json');
    if (b.tree !== p[variant + 'Tree'] || b.result !== 'PASS' || !b.sourceStable) throw Error('Fixed build missing');
    for (const [file, digest] of Object.entries(b.sourceSHA256After)) if (sha(fs.readFileSync(path.join(path.dirname(path.dirname(b.input)), file))) !== digest) throw Error('Frozen build source changed: ' + file);
  }
  const tests = load('fixed-tests.json');
  if (tests.tree !== p.candidateTree || tests.result !== 'PASS' || !tests.sourceStable) throw Error('Fixed backend tests incomplete');
  if (load('fixed-period/result.json').checks !== 88 || load('fixed-member/frontend.json').checks !== 22) throw Error('Fixed frontend regression missing');
  if (load('installer-tests.json').result !== 'PASS') throw Error('Deployment rollback tests missing');
  const old = load('baseline-cashier-v3-build.json');
  for (const site of Object.keys(sites)) for (const front of cashier(load(site + '-before.json')))
    for (const [file, digest] of Object.entries(front.assets)) if (old.assets[file.replace('/view_cashier_v3/', '')] !== digest) throw Error('Old package not reproduced');
  return p;
}
function commit() {
  const p = ready(), ref = 'refs/heads/release/r54-service-period-20261024';
  if (git(['rev-parse', 'HEAD']).toString().trim() !== p.head) throw Error('Shared HEAD changed');
  const index = git(['rev-parse', '--git-path', 'index']).toString().trim();
  if (sha(fs.readFileSync(index)) !== p.sharedIndexBefore) throw Error('Shared index changed');
  try { git(['show-ref', '--verify', '--quiet', ref]); throw Error('Release ref exists'); } catch (error) { if (error.status !== 1) throw error; }
  const files = git(['-c', 'core.quotepath=false', 'diff', '--name-only', p.baselineTree, p.candidateTree]).toString().trim().split('\n');
  if (JSON.stringify(files) !== JSON.stringify(p.paths)) throw Error('Commit boundary mismatch');
  const dependency = git(['commit-tree', p.baselineTree, '-p', p.baselineCommit], Buffer.from('R54-BASE 单独保留已上线库存分页和整元卡金额依赖\n\n来源e43cc910及30582fd0，保留R53卡转赠金和R95目标方案；010整元校验补齐已获确认。非本轮新增功能。\n')).toString().trim();
  const current = git(['commit-tree', p.candidateTree, '-p', dependency], Buffer.from('R54-00054 会员服务查询修复与统一周期控件闭环\n\n仅本轮16个源码脚本及测试文件，候选523cf660已由产品经理补验确认；独立保留既有上线依赖，不含其他未验收改动。\n')).toString().trim();
  git(['update-ref', ref, current, '0'.repeat(40)]);
  if (sha(fs.readFileSync(index)) !== p.sharedIndexBefore) throw Error('Shared index unexpectedly changed');
  save('commit.json', { commit: current, dependencyCommit: dependency, branch: ref.slice(11), tree: p.candidateTree, files, sharedIndexUntouched: true });
  console.log('R54_COMMITTED', current);
}
function unchanged(site, before) {
  const now = probe(site);
  if (JSON.stringify(now.backend) !== JSON.stringify(before.backend) || JSON.stringify(cashier(now)) !== JSON.stringify(cashier(before))
      || JSON.stringify(admin(now)) !== JSON.stringify(admin(before)) || JSON.stringify(now.protected) !== JSON.stringify(before.protected)) throw Error('Live source/entry drift: ' + site);
  return now;
}
function digests(site, keys) {
  const encoded = Buffer.from(JSON.stringify(keys)).toString('base64');
  return JSON.parse(ssh(site, '/www/server/php/74/bin/php', Buffer.from(`<?php $r='/www/wwwroot/${sites[site].domain}';$o=[];foreach(json_decode(base64_decode('${encoded}'),true)as $k){$p=strpos($k,'preview/')===0?$r.'-8080/'.substr($k,8):$r.'/'.substr($k,7);$o[$k]=is_file($p)?hash_file('sha256',$p):null;}echo json_encode($o);`)));
}
async function publicCheck(site, build, commit) {
  const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true, args: ['--no-proxy-server', '--disable-http2'] });
  const results = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1515, height: 1009 } });
    // Existing project transport workaround: execute the unmodified live page
    // through Playwright HTTP, avoiding intermittent local Chrome direct stalls.
    await context.route('https://**.cc3798.com/**', async route => {
      try { const response = await route.fetch({ timeout: 30000 }); await route.fulfill({ response }); }
      catch { await route.abort(); }
    });
    for (const prefix of site === 'rh' ? ['', '/preview-8080'] : ['']) {
      const url = 'https://' + sites[site].domain + prefix + '/view_cashier_v3/';
      const response = await context.request.get(url);
      if (!response.ok()) throw Error('Public entry unavailable: ' + url);
      const html = await response.text(), refs = [...html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(x => x[1]);
      if (refs.length !== 2) throw Error('Unexpected public entry references');
      const assets = {};
      for (const ref of refs) {
        const asset = 'assets/' + path.basename(ref), r = await context.request.get(new URL(ref, url).href);
        if (!r.ok()) throw Error('Public asset unavailable');
        assets[asset] = sha(await r.body()); if (assets[asset] !== build.assets[asset]) throw Error('Public asset fingerprint mismatch');
      }
      const page = await context.newPage(), errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.goto(url + '#/login'); await page.locator('input[type=password]').waitFor();
      await page.screenshot({ path: path.join(evidence, site + '-public-' + results.length + '.png') });
      if (errors.length) throw Error('Public app boot error: ' + errors.join(','));
      results.push({ url, commit, result: 'PASS', assets, loginBoot: 'PASS', limit: 'Anonymous boot and readonly backend tested; no authenticated financial action.' }); await page.close();
    }
  } finally { await browser.close(); }
  return results;
}
async function deploy(site, retry = false) {
  const p = ready(); if (!sites[site]) throw Error('Explicit site required');
  const receipt = load('commit.json');
  if (site !== 'rh' && load('push.json').result !== 'PASS') throw Error('RH verified push first');
  for (const prior of site === '010' ? ['rh', '008'] : site === '008' ? ['rh'] : []) if (load(prior + '-verified.json').result !== 'PASS') throw Error('Prior instance verification required');
  if (retry && load(site + '-rollback.json').result !== 'PASS') throw Error('Verified rollback required for retry');
  const before = unchanged(site, load(site + '-before.json'));
  if (!retry) save(site + '-preswitch.json', before);
  const build = load('candidate-cashier-v3-build.json'), payload = {};
  // Only missing/different dependencies are delivered; an existing source blob
  // in Git is not evidence that it was ever deployed to this instance.
  for (const [file, digest] of Object.entries(deps.requiredFiles(p.candidateTree))) if (before.backend[file] !== digest)
    payload['formal/' + file] = git(['show', receipt.commit + ':后端代码/' + file]);
  const prefixes = site === 'rh' ? ['formal', 'preview'] : ['formal'];
  for (const [file, digest] of Object.entries(build.assets)) {
    const bytes = fs.readFileSync(path.join(build.output, file)); if (sha(bytes) !== digest) throw Error('Build payload drift');
    if (file === 'index.html') continue;
    for (const prefix of prefixes) payload[prefix + '/public/view_cashier_v3/' + file] = bytes;
  }
  // Entry points switch last, after compatible backend and all hashed assets.
  for (const prefix of prefixes) payload[prefix + '/public/view_cashier_v3/index.html'] = fs.readFileSync(path.join(build.output, 'index.html'));
  const old = digests(site, Object.keys(payload)), targets = {};
  for (const [file, bytes] of Object.entries(payload)) if (old[file] !== sha(bytes)) targets[file] = { before: old[file], after: sha(bytes) };
  deps.assertDependencies(deps.requiredFiles(p.candidateTree), before.backend, targets);
  const manifest = { site, commit: receipt.commit, candidateTree: p.candidateTree, previewAuthorized: site === 'rh', targets,
    databaseChanges: false, platformStaticUnchanged: true };
  if (!retry) save(site + '-manifest.json', manifest);
  else if (JSON.stringify(load(site + '-manifest.json')) !== JSON.stringify(manifest)) throw Error('Retry manifest drift');
  const directory = '/www/backups/' + site + '/r54-service-period-' + receipt.commit.slice(0, 8);
  const stage = '/private/tmp/mohe-r54-service-period-payload-' + site;
  if (!retry) fs.mkdirSync(stage);
  for (const file of Object.keys(targets)) { const dest = path.join(stage, 'payload', file); fs.mkdirSync(path.dirname(dest), { recursive: true });
    if (!retry) fs.writeFileSync(dest, payload[file], { flag: 'wx', mode: 0o444 });
    else if (sha(fs.readFileSync(dest)) !== targets[file].after) throw Error('Retried payload changed');
  }
  const manifestBytes = Buffer.from(JSON.stringify(manifest)), digest = sha(manifestBytes);
  if (!retry) {
    fs.writeFileSync(path.join(stage, 'payload/manifest.json'), manifestBytes, { flag: 'wx', mode: 0o444 });
    fs.writeFileSync(path.join(stage, 'install.php'), fs.readFileSync('scripts/r54-service-period-install.php'), { flag: 'wx', mode: 0o444 });
    const archive = run('tar', ['-czf', '-', '-C', stage, '.']);
    ssh(site, `test ! -e '${directory}' && mkdir -m 700 -p '${directory}'`);
    ssh(site, `tar -xzf - -C '${directory}'`, archive);
  } else if (sha(fs.readFileSync(path.join(stage, 'payload/manifest.json'))) !== digest
      || !fs.readFileSync(path.join(stage, 'install.php')).equals(fs.readFileSync('scripts/r54-service-period-install.php'))) throw Error('Retry controls changed');
  const invoke = mode => ssh(site, `/www/server/php/74/bin/php '${directory}/install.php' '${directory}' '${mode}' '${digest}'`);
  unchanged(site, before);
  if (!retry) {
    fs.writeFileSync(path.join(evidence, site + '-backup.log'), invoke('backup'));
    save(site + '-backup.json', { directory, manifestSHA256: digest, independentFileBackup: true, databaseBackup: 'Not applicable: no database writes or migration.', rollback: 'install.php rollback with pinned manifest' });
  }
  let started = false;
  try {
    started = true; fs.writeFileSync(path.join(evidence, site + (retry ? '-retry-install.log' : '-install.log')), invoke('install'));
    ssh(site, 'set -e; ' + sites[site].restart + '; sleep 2; ' + sites[site].health);
    const after = probe(site), actual = digests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (actual[file] !== row.after) throw Error('Installed file mismatch: ' + file);
    const changes = new Set(Object.keys(targets).filter(k => k.startsWith('formal/app/')).map(k => k.slice(7)));
    for (const [file, hash] of Object.entries(before.backend)) if (!changes.has(file) && after.backend[file] !== hash) throw Error('Unrelated backend drift: ' + file);
    if (JSON.stringify(before.protected) !== JSON.stringify(after.protected) || JSON.stringify(admin(before)) !== JSON.stringify(admin(after))
        || JSON.stringify(before.channelRules) !== JSON.stringify(after.channelRules) || JSON.stringify(before.upgrades) !== JSON.stringify(after.upgrades)) throw Error('Protected configuration/platform/permissions/migrations changed');
    for (const entry of cashier(after)) {
      if (entry.sha256 !== build.assets['index.html']) throw Error('Disk entry mismatch');
      for (const [file, hash] of Object.entries(entry.assets)) if (build.assets[file.replace('/view_cashier_v3/', '')] !== hash) throw Error('Disk static mismatch');
    }
    const runtime = JSON.parse(ssh(site, '/www/server/php/74/bin/php -- ' + site, fs.readFileSync('scripts/r54-service-period-runtime.php')));
    if (runtime.result !== 'PASS' || !runtime.readOnly) throw Error('Full readonly projection failed');
    deps.assertDependencies(deps.requiredFiles(p.candidateTree), runtime.dependencies, {});
    if (fs.existsSync(path.join(evidence, site + '-runtime.json'))) {
      save(site + '-runtime-retry-' + Date.now() + '.json', runtime);
      if (JSON.stringify(load(site + '-runtime.json')) !== JSON.stringify(runtime)) throw Error('Runtime fixture changed; inspect recorded retry before continuing');
    } else save(site + '-runtime.json', runtime);
    const publics = await publicCheck(site, build, receipt.commit); save(site + '-public.json', publics);
    const last = digests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (last[file] !== row.after) throw Error('Post-verification target drift');
    save(site + '-after.json', after);
    save(site + '-verified.json', { result: 'PASS', site, commit: receipt.commit, candidateTree: p.candidateTree, backup: directory, files: Object.keys(targets).length,
      publicEntries: publics.length, serviceRecords: runtime.serviceRecords, businessWrites: 0, unchanged: ['platform static', 'other terminals', 'permissions', 'migrations', 'unrelated backend'], limits: publics.map(r => r.limit) });
    console.log('R54_INSTANCE_VERIFIED', site, receipt.commit, 'serviceRecords=' + runtime.serviceRecords);
  } catch (error) {
    const failureFile = path.join(evidence, site + '-failure-' + Date.now() + '.json');
    fs.writeFileSync(failureFile, JSON.stringify({ error: error.message, stack: error.stack, retry }, null, 2));
    console.error('R54_VERIFY_FAILURE', error.message);
    if (started) {
      try {
        fs.writeFileSync(path.join(evidence, site + '-rollback.log'), invoke('rollback'));
        ssh(site, 'set -e; ' + sites[site].restart + '; sleep 2; ' + sites[site].health);
        unchanged(site, before); save(site + (retry ? '-retry-rollback-' + Date.now() : '-rollback') + '.json', { result: 'PASS', directory, error: error.message });
      } catch (rollback) { save(site + '-rollback-attention-' + Date.now() + '.json', { result: 'MANUAL_ATTENTION_REQUIRED', directory, error: rollback.message, original: error.message }); }
    }
    throw error;
  }
}
function push() {
  ready(); const r = load('commit.json'); if (load('rh-verified.json').result !== 'PASS') throw Error('Verify RH first');
  const ref = 'refs/heads/' + r.branch; git(['push', 'origin', ref + ':' + ref]);
  if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== r.commit) throw Error('Push verification failed');
  save('push.json', { result: 'PASS', commit: r.commit, branch: r.branch }); console.log('R54_PUSH_VERIFIED', r.commit);
}
// Final evidence is a separate engineering-only commit. It cannot change the
// accepted business tree or make a partial deployment look complete.
function archive() {
  ready(); const r = load('commit.json');
  if (load('final-verification.json').result !== 'PASS') throw Error('Final instance and shared-source audit required');
  for (const site of Object.keys(sites)) if (load(site + '-verified.json').result !== 'PASS') throw Error('All three instances must pass');
  const ref = 'refs/heads/' + r.branch;
  if (git(['rev-parse', ref]).toString().trim() !== r.commit) throw Error('Release branch changed');
  const files = fs.readdirSync('scripts').filter(n => n.startsWith('r54-service-period-')).map(n => 'scripts/' + n);
  files.push('tests/cashier-v3/js/r54-release-installer.cjs');
  const collect = dir => { for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, item.name); if (item.isSymbolicLink()) throw Error('Evidence symlink denied');
    if (item.isDirectory()) collect(file); else if (item.isFile()) files.push(file);
  } };
  for (const dir of [evidence, 'tests/cashier-v3/evidence/R54-member-service', 'tests/cashier-v3/evidence/R54-query-period']) collect(dir);
  const index = '/private/tmp/mohe-r54-service-period-archive-' + process.pid + '.index';
  const shared = git(['rev-parse', '--git-path', 'index']).toString().trim(), before = sha(fs.readFileSync(shared));
  const env = { ...process.env, GIT_INDEX_FILE: index };
  try {
    git(['read-tree', r.commit], undefined, env);
    for (const file of files.sort()) {
      const blob = git(['hash-object', '-w', '--stdin'], fs.readFileSync(file)).toString().trim();
      git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
    }
    const tree = git(['write-tree'], undefined, env).toString().trim();
    const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', r.commit, tree]).toString().trim().split('\n');
    if (changed.some(f => !files.includes(f))) throw Error('Archive boundary drift');
    const current = git(['commit-tree', tree, '-p', r.commit], Buffer.from('R54-00054 三站发布核验和独立回滚证据归档\n\n仅工程控制与验收发布证据；线上业务提交仍为7b38fbc0。\n')).toString().trim();
    if (sha(fs.readFileSync(shared)) !== before) throw Error('Shared index changed');
    git(['update-ref', ref, current, r.commit]); git(['push', 'origin', ref + ':' + ref]);
    if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== current) throw Error('Archive push verification failed');
    save('archive.json', { result: 'PASS', archiveCommit: current, deployedBusinessCommit: r.commit, files: changed.length, sharedIndexUntouched: true });
    console.log('R54_ARCHIVE_PUSH_VERIFIED', current);
  } finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
}
function finalAudit() {
  const p = ready(), r = load('commit.json'), results = [];
  for (const site of Object.keys(sites)) {
    if (load(site + '-verified.json').result !== 'PASS') throw Error('Unverified instance');
    unchanged(site, load(site + '-after.json'));
    results.push({ site, result: 'PASS', commit: r.commit, unchangedSinceVerification: true });
  }
  const index = git(['rev-parse', '--git-path', 'index']).toString().trim();
  if (sha(fs.readFileSync(index)) !== p.sharedIndexBefore || git(['rev-parse', 'HEAD']).toString().trim() !== p.head) throw Error('Shared worktree HEAD/index changed');
  save('final-verification.json', { result: 'PASS', results, sharedIndexUntouched: true, sharedHeadUntouched: true, businessWrites: 0 });
  console.log('R54_FINAL_AUDIT_PASS');
}
const [mode, site] = process.argv.slice(2);
if (mode === 'commit') commit();
else if (mode === 'deploy') deploy(site).catch(e => { console.error(e); process.exitCode = 1; });
else if (mode === 'retry-after-rollback') deploy(site, true).catch(e => { console.error(e); process.exitCode = 1; });
else if (mode === 'push') push();
else if (mode === 'archive') archive();
else if (mode === 'final-audit') finalAudit();
else if (mode === 'probe-public-baseline') publicCheck(site, load('baseline-cashier-v3-build.json'), 'baseline').then(r => console.log(JSON.stringify(r))).catch(e => { console.error(e); process.exitCode = 1; });
else throw Error('commit|deploy <site>|push|archive required');
