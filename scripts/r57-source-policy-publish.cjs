// Explicit R57 sequence: scoped commits -> RH verify -> push ->008 ->010.
// Deploy bytes come only from the accepted Git tree and immutable builds.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), zlib = require('zlib');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const e = 'tests/cashier-v3/evidence/R57-release';
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (bin, args, options = {}) => cp.execFileSync(bin, args, { maxBuffer: 128 * 1024 * 1024, timeout: 180000, ...options });
const git = (args, input, env = process.env) => run('git', args, { input, env });
const load = name => JSON.parse(fs.readFileSync(path.join(e, name)));
const save = (name, value) => fs.writeFileSync(path.join(e, name), JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });
const sites = { rh: { host: 'rh-server', domain: 'rh.cc3798.com' }, '008': { host: 'mohe-server', domain: '008.cc3798.com' }, '010': { host: 'mohe-server', domain: '010.cc3798.com' } };
function ssh(site, command, input) {
  if (!sites[site]) throw Error('Explicit site required');
  return run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3', sites[site].host, command], { input });
}
function probe(site) {
  const code = fs.readFileSync('scripts/r28-permissions-inspect.php', 'utf8').replace('<?php', '<?php ob_start();') + '\necho gzencode(ob_get_clean());';
  return JSON.parse(zlib.gunzipSync(ssh(site, '/www/server/php/74/bin/php -- ' + site, code)));
}
function scope(site) { return JSON.parse(zlib.gunzipSync(ssh(site, '/www/server/php/74/bin/php -- ' + site, fs.readFileSync('scripts/r57-source-policy-release-probe.php')))); }
function verifySource() {
  const p = load('trees.json');
  for (const [file, digest] of Object.entries(load('controls.json').files)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Release control drift: ' + file);
  if (p.candidateTree !== 'c3a85bf3f85ff40cb94362268260153f41bf5d4a' || load('acceptance.json').candidateTree !== p.candidateTree) throw Error('Fixed PM acceptance required');
  for (const [file, digest] of Object.entries(p.acceptedFiles)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted source drift: ' + file);
  if (load('fixed-source-tests.json').result !== 'PASS' || load('fixed-tests.json').result !== 'PASS'
      || load('fixed-fullscreen/fixed-files.json').checks !== 41) throw Error('Fixed regression required');
  if (!fs.readFileSync(e + '/installer-tests.log', 'utf8').includes('R57_INSTALLER_TEST_PASS checks=26')) throw Error('Installer regression required');
  for (const terminal of ['admin', 'cashier-v3', 'mobile']) {
    const b = load('candidate-' + terminal + '-build.json');
    if (b.tree !== p.candidateTree || b.result !== 'PASS' || !b.sourceStable) throw Error('Fixed build required');
    for (const [file, digest] of Object.entries(b.assets)) if (sha(fs.readFileSync(path.join(b.output, file))) !== digest) throw Error('Artifact drift');
  }
  const audit = load('shared-head-recheck.json');
  if (git(['rev-parse', 'HEAD']).toString().trim() !== audit.head || sha(fs.readFileSync(git(['rev-parse', '--git-path', 'index']).toString().trim())) !== audit.indexSHA256) throw Error('Shared HEAD/index changed; audit again');
  return p;
}
function liveUnchanged(site) {
  const before = load(site + '-before.json'), actual = probe(site);
  for (const key of ['backend', 'frontends', 'protected', 'channelRules']) if (JSON.stringify(actual[key]) !== JSON.stringify(before[key])) throw Error('Live source baseline changed: ' + site + ' ' + key);
  const actualScope = scope(site), oldScope = load(site + '-scope-before.json');
  if (JSON.stringify(actualScope.static) !== JSON.stringify(oldScope.static) || JSON.stringify(actualScope.columns) !== JSON.stringify(oldScope.columns)) throw Error('Live static/schema changed: ' + site);
  return actual;
}
function commit() {
  const p = verifySource();
  for (const site of Object.keys(sites)) liveUnchanged(site);
  const branch = 'release/r57-business-source-policy-20261025', ref = 'refs/heads/' + branch;
  if (git(['for-each-ref', '--format=%(refname)', ref]).toString().trim()) throw Error('Release ref exists');
  const base = git(['commit-tree', p.baselineTree, '-p', p.baselineCommit, '-p', p.retainedCommit], Buffer.from('R57-BASE 合入已验收R17平台入口并保留R56全屏基线\n\n仅归并已验收提交，不含本轮或其他任务未验收业务。\n')).toString().trim();
  const commit = git(['commit-tree', p.candidateTree, '-p', base], Buffer.from('R57-00057 业务来源三模式及预约H5查询闭环\n\n产品经理确认固定候选c3a85bf3。仅本轮59个源码、迁移、脚本和测试文件；排除会员多选和游客优惠券显隐。保留已上线平台进入门店、集团看板和全屏。三站幂等迁移授权，默认开启且不继承上次来源。\n')).toString().trim();
  git(['update-ref', ref, commit, '0'.repeat(40)]);
  verifySource(); save('commit.json', { commit, branch, tree: p.candidateTree, baselineCommit: base, files: p.paths, sharedIndexUntouched: true });
  console.log('R57_COMMIT', commit);
}
function restart(site) {
  const command = site === 'rh' ? '/etc/init.d/ruihao_swoole restart' : 'systemctl restart mohe-' + site + '-swoole.service';
  const out = ssh(site, command); fs.writeFileSync(e + '/' + site + '-restart.log', out);
  const health = site === 'rh' ? "ss -lnt | grep -q ':20800 '" : 'systemctl is-active --quiet mohe-' + site + '-swoole.service';
  ssh(site, health);
}
function digests(site, keys) {
  const encoded = Buffer.from(JSON.stringify(keys)).toString('base64');
  return JSON.parse(ssh(site, '/www/server/php/74/bin/php', `<?php $r='/www/wwwroot/${sites[site].domain}/';$o=[];foreach(json_decode(base64_decode('${encoded}'),true)as $k){if(strpos($k,'formal/')!==0||strpos($k,'..')!==false)throw new RuntimeException('Scope');$f=$r.substr($k,7);$o[$k]=is_file($f)?hash_file('sha256',$f):null;}echo json_encode($o);`));
}
async function publicCheck(site) {
  const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true, args: ['--no-proxy-server', '--disable-http2'] });
  const results = [];
  try {
    const context = await browser.newContext({ viewport: { width: 1480, height: 1009 } });
    await context.route('https://**.cc3798.com/**', async route => { try { await route.fulfill({ response: await route.fetch({ timeout: 30000 }) }); } catch { await route.abort(); } });
    for (const [terminal, entry, visit] of [['admin', '/admin/login', '/admin/login'], ['cashier-v3', '/view_cashier_v3/', '/view_cashier_v3/#/login'], ['mobile', '/view_mobile_v3/', '/view_mobile_v3/']]) {
      const build = load('candidate-' + terminal + '-build.json'), base = 'https://' + sites[site].domain;
      const response = await context.request.get(base + entry, { timeout: 30000 });
      if (!response.ok()) throw Error('Public entry failed: ' + terminal + ' HTTP ' + response.status());
      // Production HTML intentionally minifies away optional attribute quotes.
      // Parse inert HTML with the browser, and exclude external CDN assets that
      // are not part of the fixed instance package.
      const html = await response.text(), parser = await context.newPage();
      const refs = await parser.evaluate(({ html, base, entry }) => {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        return Array.from(doc.querySelectorAll('script[src],link[href]')).map(node => node.getAttribute(node.tagName === 'SCRIPT' ? 'src' : 'href'))
          .filter(Boolean).map(ref => new URL(ref, base + entry)).filter(url => url.origin === base && /\.(?:js|css)$/.test(url.pathname)).map(url => url.href);
      }, { html, base, entry });
      await parser.close();
      if (refs.length < 2) throw Error('Missing entry assets: ' + terminal);
      const checked = [];
      for (const ref of refs) {
        const url = new URL(ref, base + entry), r = await context.request.get(url.href, { timeout: 30000 });
        const file = terminal === 'admin' ? url.pathname.slice(1) : url.pathname.replace(terminal === 'mobile' ? '/view_mobile_v3/' : '/view_cashier_v3/', '');
        const digest = sha(await r.body());
        if (!r.ok() || build.assets[file] !== digest) throw Error('Public asset drift: ' + file);
        checked.push({ file, sha256: digest });
      }
      const page = await context.newPage(), errors = [];
      page.on('pageerror', err => errors.push(err.message));
      await page.goto(base + visit, { waitUntil: 'networkidle', timeout: 45000 });
      // Router/lazy component mounting may occur after networkidle. Verify the
      // actual login surface, not a transient empty root immediately after load.
      if (terminal !== 'mobile') await page.locator('input[type=password]').first().waitFor({ state: 'visible', timeout: 30000 });
      else await page.waitForFunction(() => document.body.innerText.trim().length >= 5, null, { timeout: 30000 });
      if ((await page.locator('body').innerText()).trim().length < 5 || errors.length) throw Error('Public boot failed: ' + terminal + ' ' + errors.join(','));
      await page.screenshot({ path: e + '/' + site + '-' + terminal + '-public.png', fullPage: true }); await page.close();
      results.push({ terminal, status: response.status(), checked, pageErrors: errors });
    }
    if (site === 'rh') for (const entry of ['/preview-8080/view_cashier_v3/', '/store-v3/']) {
      const r = await context.request.get('https://' + sites[site].domain + entry, { timeout: 30000, maxRedirects: 0 });
      if (r.status() !== 410 || r.headers().location) throw Error('Retired preview changed');
      results.push({ entry, status: 410, redirect: false });
    }
  } finally { await browser.close(); }
  return { result: 'PASS', results, limit: 'Anonymous boot and exact resources; no real financial transaction or reservation confirmation.' };
}
async function deploy(site) {
  const p = verifySource(), receipt = load('commit.json');
  if (!sites[site]) throw Error('Explicit site required');
  if (site !== 'rh' && load('push.json').result !== 'PASS') throw Error('RH verification and push required');
  if (site === '010' && load('008-verified.json').result !== 'PASS') throw Error('008 verification required');
  const before = liveUnchanged(site); save(site + '-preswitch.json', before);
  const payload = {}, backendAllowlist = [];
  for (const file of p.paths.filter(f => f.startsWith('后端代码/app/'))) {
    const rel = file.slice('后端代码/'.length); backendAllowlist.push(rel);
    payload['formal/' + rel] = git(['show', receipt.commit + ':' + file]);
  }
  // New immutable assets precede each entrypoint. Old hashed assets are retained
  // for existing sessions and rollback; no directory is deleted or replaced.
  for (const [terminal, prefix, entry] of [['admin', 'public/', 'system.html'], ['cashier-v3', 'public/view_cashier_v3/', 'index.html'], ['mobile', 'public/view_mobile_v3/', 'index.html']]) {
    const b = load('candidate-' + terminal + '-build.json');
    for (const file of Object.keys(b.assets).filter(f => f !== entry && (terminal !== 'admin' || f.startsWith('view_admin/')))) payload['formal/' + prefix + file] = fs.readFileSync(path.join(b.output, file));
    payload['formal/' + prefix + entry] = fs.readFileSync(path.join(b.output, entry));
  }
  const old = digests(site, Object.keys(payload)), targets = {};
  for (const [file, bytes] of Object.entries(payload)) if (old[file] !== sha(bytes)) targets[file] = { before: old[file], after: sha(bytes) };
  const migrationFile = '后端代码/database/upgrades/2026-10-25-业务来源策略/01-schema.sql';
  const sql = git(['show', receipt.commit + ':' + migrationFile]);
  const manifest = { site, commit: receipt.commit, tree: p.candidateTree, previewAuthorized: false, backendAllowlist, targets, migrationSHA256: sha(sql), settingsBackup: 'Complete affected configuration table and schema; no financial table is modified.' };
  save(site + '-manifest.json', manifest);
  const directory = '/www/backups/' + site + '/r57-source-policy-' + receipt.commit.slice(0, 8);
  const stage = '/private/tmp/mohe-r57-source-payload-' + site + '-' + receipt.commit.slice(0, 8);
  fs.mkdirSync(stage);
  for (const file of Object.keys(targets)) { const dest = path.join(stage, 'payload', file); fs.mkdirSync(path.dirname(dest), { recursive: true }); fs.writeFileSync(dest, payload[file], { flag: 'wx', mode: 0o444 }); }
  const bytes = Buffer.from(JSON.stringify(manifest)), digest = sha(bytes);
  fs.writeFileSync(path.join(stage, 'payload/manifest.json'), bytes, { flag: 'wx', mode: 0o444 });
  fs.writeFileSync(path.join(stage, 'payload/migration.sql'), sql, { flag: 'wx', mode: 0o444 });
  for (const name of ['install', 'migrate']) fs.writeFileSync(path.join(stage, name + '.php'), fs.readFileSync('scripts/r57-source-policy-' + name + '.php'), { flag: 'wx', mode: 0o444 });
  ssh(site, `test ! -e '${directory}' && mkdir -m 700 -p '${directory}'`);
  ssh(site, `tar -xzf - -C '${directory}'`, run('tar', ['-czf', '-', '-C', stage, '.']));
  const invoke = (script, mode) => ssh(site, `/www/server/php/74/bin/php '${directory}/${script}.php' '${directory}' '${mode}' '${digest}'`);
  liveUnchanged(site);
  fs.writeFileSync(e + '/' + site + '-backup.log', invoke('install', 'backup'));
  fs.writeFileSync(e + '/' + site + '-settings-backup.log', invoke('migrate', 'backup'));
  save(site + '-backup.json', { directory, manifestSHA256: digest, settingsTableAndSchemaBackup: true, independentFileBackup: true, rollback: 'Restore only manifest-listed files; retain backward-compatible columns and migration audit.' });
  let started = false;
  try {
    fs.writeFileSync(e + '/' + site + '-migration.log', invoke('migrate', 'migrate'));
    started = true; fs.writeFileSync(e + '/' + site + '-install.log', invoke('install', 'install'));
    restart(site);
    fs.writeFileSync(e + '/' + site + '-verify.log', invoke('install', 'verify'));
    fs.writeFileSync(e + '/' + site + '-migration-verify.log', invoke('migrate', 'verify'));
    const after = probe(site), afterScope = scope(site);
    const oldScope = load(site + '-scope-before.json');
    for (const [file, hash] of Object.entries(oldScope.static)) {
      const changed = targets['formal/' + file];
      if (afterScope.static[file] !== (changed ? changed.after : hash)) throw Error('Unlisted static file changed: ' + file);
    }
    const previewBefore = before.frontends.filter(f => f.root !== before.root);
    if (JSON.stringify(previewBefore) !== JSON.stringify(after.frontends.filter(f => f.root !== after.root))) throw Error('Retired preview files changed');
    for (const [file, hash] of Object.entries(before.backend)) {
      const changed = targets['formal/' + file];
      if (after.backend[file] !== (changed ? changed.after : hash)) throw Error('Backend fingerprint mismatch: ' + file);
    }
    for (const [file, hash] of Object.entries(before.protected)) if (!targets['formal/' + file] && after.protected[file] !== hash) throw Error('Protected entry changed: ' + file);
    if (JSON.stringify(before.channelRules) !== JSON.stringify(after.channelRules)) throw Error('Permissions changed');
    const pub = await publicCheck(site); save(site + '-public.json', pub);
    const current = digests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (current[file] !== row.after) throw Error('Post-verification drift');
    save(site + '-after.json', after); save(site + '-scope-after.json', afterScope);
    save(site + '-verified.json', { result: 'PASS', site, commit: receipt.commit, tree: p.candidateTree, backup: directory,
      files: Object.keys(targets), defaultMode: 'manual', historicalBusinessWrites: false, limit: pub.limit });
    console.log('R57_INSTANCE_VERIFIED', site, receipt.commit, Object.keys(targets).length);
  } catch (error) {
    save(site + '-failure.json', { error: error.message, started });
    if (started) try { fs.writeFileSync(e + '/' + site + '-rollback.log', invoke('install', 'rollback')); restart(site); save(site + '-rollback.json', { result: 'PASS', backwardCompatibleColumnsRetained: true }); }
    catch (rollback) { save(site + '-rollback-attention.json', { error: rollback.message }); }
    throw error;
  }
}
async function resume(site) {
  // Resume only the verified RH rollback or prepared 010 batch after its
  // separately verified maintenance migration. Keep the original backups and
  // audit; never rerun or undo DDL while restoring the same accepted payload.
  verifySource();
  const rolledBackRH = site === 'rh' && load('rh-rollback.json').result === 'PASS';
  const prepared010 = site === '010' && load('010-failure.json').started === false
    && load('008-verified.json').result === 'PASS' && load('push.json').result === 'PASS';
  if ((!rolledBackRH && !prepared010) || fs.existsSync(e + '/' + site + '-verified.json')) throw Error('Explicit prepared recovery batch only');
  const backup = load(site + '-backup.json'), manifest = load(site + '-manifest.json'), before = load(site + '-before.json'), actual = probe(site);
  for (const key of ['backend', 'frontends', 'protected', 'channelRules']) if (JSON.stringify(actual[key]) !== JSON.stringify(before[key])) throw Error('Rolled-back baseline changed: ' + key);
  if (manifest.commit !== load('commit.json').commit) throw Error('Payload commit mismatch');
  const invoke = (script, mode) => ssh(site, `/www/server/php/74/bin/php '${backup.directory}/${script}.php' '${backup.directory}' '${mode}' '${backup.manifestSHA256}'`);
  fs.writeFileSync(e + '/' + site + '-resume-migration-verify.log', invoke('migrate', 'verify'));
  let started = false;
  try {
    started = true; fs.writeFileSync(e + '/' + site + '-resume-install.log', invoke('install', 'install')); restart(site);
    fs.writeFileSync(e + '/' + site + '-resume-files-verify.log', invoke('install', 'verify'));
    const after = probe(site), afterScope = scope(site), oldScope = load(site + '-scope-before.json');
    for (const [file, hash] of Object.entries(before.backend)) if (after.backend[file] !== (manifest.targets['formal/' + file]?.after || hash)) throw Error('Backend changed: ' + file);
    for (const [file, hash] of Object.entries(oldScope.static)) if (afterScope.static[file] !== (manifest.targets['formal/' + file]?.after || hash)) throw Error('Static changed: ' + file);
    for (const [file, hash] of Object.entries(before.protected)) if (!manifest.targets['formal/' + file] && after.protected[file] !== hash) throw Error('Protected entry changed');
    const pub = await publicCheck(site); save(site + '-public.json', pub);
    const current = digests(site, Object.keys(manifest.targets));
    for (const [file, row] of Object.entries(manifest.targets)) if (current[file] !== row.after) throw Error('Post-check drift');
    verifySource(); save(site + '-after.json', after); save(site + '-scope-after.json', afterScope);
    save(site + '-verified.json', { result: 'PASS', site, commit: manifest.commit, tree: manifest.tree, backup: backup.directory,
      resumedSameCandidate: true, files: Object.keys(manifest.targets), defaultMode: 'manual', historicalBusinessWrites: false, limit: pub.limit });
    console.log('R57_INSTANCE_VERIFIED', site, manifest.commit, Object.keys(manifest.targets).length);
  } catch (error) {
    const attempt = Date.now();
    save(site + '-resume-' + attempt + '-failure.json', { error: error.message, started });
    if (started) { fs.writeFileSync(e + '/' + site + '-resume-' + attempt + '-rollback.log', invoke('install', 'rollback')); restart(site); save(site + '-resume-' + attempt + '-rollback.json', { result: 'PASS' }); }
    throw error;
  }
}
function push() {
  verifySource(); const r = load('commit.json');
  if (load('rh-verified.json').result !== 'PASS') throw Error('RH must pass before push');
  const ref = 'refs/heads/' + r.branch; git(['push', 'origin', ref + ':' + ref]);
  if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== r.commit) throw Error('Push verification failed');
  save('push.json', { result: 'PASS', branch: r.branch, commit: r.commit }); console.log('R57_PUSH_VERIFIED', r.commit);
}
const mode = process.argv[2], site = process.argv[3];
if (mode === 'commit') commit();
else if (mode === 'push') push();
else if (mode === 'deploy') deploy(site).catch(error => { console.error(error); process.exitCode = 1; });
else if (mode === 'resume') resume(site).catch(error => { console.error(error); process.exitCode = 1; });
else throw Error('commit|deploy site|push required');
