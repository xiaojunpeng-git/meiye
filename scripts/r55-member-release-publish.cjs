// Explicitly approved R55 only: scoped commit -> RH formal verification -> push
// ->008 ->010. Remote writes are limited to backed-up cashier static assets.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), zlib = require('zlib');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R55-release';
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (bin, args, options = {}) => cp.execFileSync(bin, args, { maxBuffer: 128 * 1024 * 1024, ...options });
const git = (args, input, env = process.env) => run('git', args, { input, env });
const load = name => JSON.parse(fs.readFileSync(path.join(evidence, name)));
const save = (name, data) => fs.writeFileSync(path.join(evidence, name), JSON.stringify(data, null, 2) + '\n', { flag: 'wx' });
const sites = { rh: { host: 'rh-server', domain: 'rh.cc3798.com' }, '008': { host: 'mohe-server', domain: '008.cc3798.com' }, '010': { host: 'mohe-server', domain: '010.cc3798.com' } };
function ssh(site, command, input) {
  if (!sites[site]) throw Error('Explicit instance required');
  return run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=3', sites[site].host, command], { input });
}
function probe(site) {
  const source = fs.readFileSync('scripts/r28-permissions-inspect.php', 'utf8').replace('<?php', '<?php ob_start();') + '\necho gzencode(ob_get_clean());';
  let failure;
  for (let i = 0; i < 3; i++) try { return JSON.parse(zlib.gunzipSync(ssh(site, '/www/server/php/74/bin/php -- ' + site, source)).toString()); } catch (error) { failure = error; }
  throw failure;
}
function unchanged(site, expected) {
  const actual = probe(site);
  for (const key of ['backend', 'frontends', 'protected', 'upgrades', 'channelRules']) if (JSON.stringify(actual[key]) !== JSON.stringify(expected[key])) throw Error('Live baseline changed: ' + site + ' ' + key);
  return actual;
}
function ready() {
  const p = load('trees.json'), approval = load('acceptance.json');
  if (p.candidateTree !== 'df92b91f52e940a245cfd44b7448067a40bbd427' || approval.candidateTree !== p.candidateTree || !approval.productManagerConfirmed || !approval.rhFormalOnly) throw Error('Fixed acceptance required');
  for (const [file, digest] of Object.entries(p.acceptedFiles)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted source drift: ' + file);
  for (const variant of ['baseline', 'candidate']) {
    const b = load(variant + '-cashier-v3-build.json');
    if (b.tree !== p[variant + 'Tree'] || b.result !== 'PASS' || !b.sourceStable) throw Error('Fixed build required');
    for (const [file, digest] of Object.entries(b.sourceSHA256After)) if (sha(fs.readFileSync(path.join(path.dirname(path.dirname(b.input)), file))) !== digest) throw Error('Frozen source drift');
    for (const [file, digest] of Object.entries(b.assets)) if (sha(fs.readFileSync(path.join(b.output, file))) !== digest) throw Error('Build output drift');
  }
  const tests = load('fixed-tests.json');
  if (tests.tree !== p.candidateTree || tests.result !== 'PASS' || !tests.sourceStable) throw Error('Fixed tests required');
  for (const site of Object.keys(sites)) if (load(site + '-runtime-before.json').result !== 'PASS') throw Error('Runtime compatibility required');
  if (load('fixed-member/result.json').checks !== 114) throw Error('Member regression incomplete');
  if (load('installer-tests.json').result !== 'PASS' || load('installer-tests.json').checks !== 16 || load('installer-source.json').sha256 !== sha(installerSource())) throw Error('Installer backup/rollback/scope regression required');
  return p;
}
function sharedUnchanged(p) {
  if (git(['rev-parse', 'HEAD']).toString().trim() !== p.head || sha(fs.readFileSync(git(['rev-parse', '--git-path', 'index']).toString().trim())) !== p.sharedIndexBefore) throw Error('Shared HEAD/index drift');
}
function commit() {
  const p = ready(); sharedUnchanged(p);
  for (const site of Object.keys(sites)) unchanged(site, load(site + '-before.json'));
  const ref = 'refs/heads/release/r55-member-filters-20261024';
  try { git(['show-ref', '--verify', '--quiet', ref]); throw Error('Release ref already exists'); } catch (error) { if (error.status !== 1) throw error; }
  const files = git(['-c', 'core.quotepath=false', 'diff', '--name-only', p.baselineTree, p.candidateTree]).toString().trim().split('\n');
  if (JSON.stringify(files) !== JSON.stringify(p.paths)) throw Error('Scoped commit boundary changed');
  const commit = git(['commit-tree', p.candidateTree, '-p', p.baselineCommit], Buffer.from('R55-00055 会员统一动态筛选及列表列展示闭环\n\n产品经理补验确认df92b91f。仅本轮10个文件；保留R54上线前端基线，排除其他未验收热更新改动。本轮无后端部署或数据库迁移。\n')).toString().trim();
  git(['update-ref', ref, commit, '0'.repeat(40)]); sharedUnchanged(p);
  save('commit.json', { commit, branch: ref.slice(11), tree: p.candidateTree, files, sharedIndexUntouched: true });
  console.log('R55_COMMITTED', commit);
}
// Reuse the already tested manifest installer, with an R55 namespace and a
// strict static-only guard; no backend or retired preview key is accepted.
function installerSource() {
  let bytes = fs.readFileSync('scripts/r54-service-period-install.php');
  if (sha(bytes) !== '58955f910b993cc244b6f9c570b78fdf3e4a3358e127a9c8e46f1f4755674282') throw Error('Reviewed installer changed');
  let source = bytes.toString().replaceAll('r54', 'r55').replaceAll('R54', 'R55').replaceAll('service-period', 'member-list');
  source = source.replace("$site = $match[1];", `foreach (array_keys($manifest['targets']) as $key) {
    if (!preg_match('~^formal/public/view_cashier_v3/(?:index\\.html|favicon\\.ico|assets/[A-Za-z0-9_.-]+)$~D', $key)) throw new RuntimeException('R55_STATIC_FORMAL_ONLY');
}
$site = $match[1];`);
  return Buffer.from(source);
}
function digests(site, keys) {
  const encoded = Buffer.from(JSON.stringify(keys)).toString('base64');
  return JSON.parse(ssh(site, '/www/server/php/74/bin/php', `<?php $r='/www/wwwroot/${sites[site].domain}/';$o=[];foreach(json_decode(base64_decode('${encoded}'),true)as $k){if(strpos($k,'formal/public/view_cashier_v3/')!==0||strpos($k,'..')!==false)throw new RuntimeException('Scope');$f=$r.substr($k,7);$o[$k]=is_file($f)?hash_file('sha256',$f):null;}echo json_encode($o);`));
}
async function publicCheck(site, build, commit) {
  const { chromium } = require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
  const browser = await chromium.launch({ executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true, args: ['--no-proxy-server', '--disable-http2'] });
  try {
    const context = await browser.newContext({ viewport: { width: 1480, height: 1009 } });
    await context.route('https://**.cc3798.com/**', async route => { try { const response = await route.fetch({ timeout: 30000 }); await route.fulfill({ response }); } catch { await route.abort(); } });
    const url = 'https://' + sites[site].domain + '/view_cashier_v3/';
    const response = await context.request.get(url, { timeout: 30000 });
    if (!response.ok()) throw Error('Public entry HTTP failure');
    const html = await response.text(), refs = [...html.matchAll(/(?:src|href)="([^"\s]+\.(?:js|css))"/g)].map(x => x[1]);
    if (refs.length !== 2) throw Error('Unexpected entry asset references');
    const assets = {};
    for (const ref of refs) {
      const r = await context.request.get(new URL(ref, url).href, { timeout: 30000 });
      const file = 'assets/' + path.basename(ref); assets[file] = sha(await r.body());
      if (!r.ok() || assets[file] !== build.assets[file]) throw Error('Public asset mismatch');
    }
    const retired = [];
    if (site === 'rh') for (const suffix of ['/preview-8080/view_cashier_v3/', '/store-v3/']) {
      const r = await context.request.get('https://' + sites[site].domain + suffix, { maxRedirects: 0, timeout: 30000 });
      if (r.status() !== 410 || r.headers().location) throw Error('Retired entry changed');
      retired.push({ suffix, status: 410, redirect: false });
    }
    const page = await context.newPage(), errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(url + '#/login', { timeout: 30000 }); await page.locator('input[type=password]').waitFor({ timeout: 30000 });
    await page.screenshot({ path: path.join(evidence, site + '-public.png') });
    if (errors.length) throw Error('Public app runtime errors: ' + errors.join(','));
    return { result: 'PASS', site, commit, url, assets, retired, loginBoot: 'PASS', limit: 'Anonymous browser boot and trusted-scope CLI reads only; no authenticated financial action.' };
  } finally { await browser.close(); }
}
async function deploy(site) {
  const p = ready(), receipt = load('commit.json'); sharedUnchanged(p);
  if (!sites[site]) throw Error('Explicit instance required');
  if (site !== 'rh' && load('push.json').result !== 'PASS') throw Error('Verified RH push required');
  for (const prior of site === '010' ? ['rh', '008'] : site === '008' ? ['rh'] : []) if (load(prior + '-verified.json').result !== 'PASS') throw Error('Prior site not verified');
  const before = unchanged(site, load(site + '-before.json')); save(site + '-preswitch.json', before);
  const build = load('candidate-cashier-v3-build.json'), payload = {};
  for (const file of Object.keys(build.assets).filter(f => f !== 'index.html')) payload['formal/public/view_cashier_v3/' + file] = fs.readFileSync(path.join(build.output, file));
  payload['formal/public/view_cashier_v3/index.html'] = fs.readFileSync(path.join(build.output, 'index.html'));
  const old = digests(site, Object.keys(payload)), targets = {};
  for (const [file, bytes] of Object.entries(payload)) if (old[file] !== sha(bytes)) targets[file] = { before: old[file], after: sha(bytes) };
  if (!targets['formal/public/view_cashier_v3/index.html'] || Object.keys(targets).length !== 3) throw Error('Expected exactly entry/JS/CSS replacement');
  const manifest = { site, commit: receipt.commit, candidateTree: p.candidateTree, previewAuthorized: false, targets, databaseChanges: false, backendChanges: false, platformStaticUnchanged: true };
  save(site + '-manifest.json', manifest);
  const directory = '/www/backups/' + site + '/r55-member-list-' + receipt.commit.slice(0, 8), stage = '/private/tmp/mohe-r55-member-payload-' + site;
  fs.mkdirSync(stage);
  for (const file of Object.keys(targets)) { const dest = path.join(stage, 'payload', file); fs.mkdirSync(path.dirname(dest), { recursive: true }); fs.writeFileSync(dest, payload[file], { flag: 'wx', mode: 0o444 }); }
  const bytes = Buffer.from(JSON.stringify(manifest)), digest = sha(bytes);
  fs.writeFileSync(path.join(stage, 'payload/manifest.json'), bytes, { flag: 'wx', mode: 0o444 });
  fs.writeFileSync(path.join(stage, 'install.php'), installerSource(), { flag: 'wx', mode: 0o444 });
  ssh(site, `test ! -e '${directory}' && mkdir -m 700 -p '${directory}'`);
  ssh(site, `tar -xzf - -C '${directory}'`, run('tar', ['-czf', '-', '-C', stage, '.']));
  const invoke = mode => ssh(site, `/www/server/php/74/bin/php '${directory}/install.php' '${directory}' '${mode}' '${digest}'`);
  unchanged(site, before);
  fs.writeFileSync(path.join(evidence, site + '-backup.log'), invoke('backup'));
  save(site + '-backup.json', { directory, manifestSHA256: digest, independentFileBackup: true, databaseBackup: 'Not applicable: static-only deployment, no database mutation.', rollback: 'Pinned install.php rollback' });
  let started = false;
  try {
    started = true; fs.writeFileSync(path.join(evidence, site + '-install.log'), invoke('install'));
    const after = probe(site);
    for (const key of ['backend', 'protected', 'upgrades', 'channelRules']) if (JSON.stringify(before[key]) !== JSON.stringify(after[key])) throw Error('Protected instance state changed: ' + key);
    const expectedFronts = before.frontends.filter(f => f.kind !== 'cashier' || f.root !== before.root);
    if (JSON.stringify(expectedFronts) !== JSON.stringify(after.frontends.filter(f => f.kind !== 'cashier' || f.root !== after.root))) throw Error('Other terminal/retired preview changed');
    const actual = digests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (actual[file] !== row.after) throw Error('Installed file mismatch');
    const runtime = JSON.parse(ssh(site, '/www/server/php/74/bin/php -- ' + site, fs.readFileSync('scripts/r55-member-release-runtime.php')));
    save(site + '-runtime-after.json', runtime);
    if (runtime.result !== 'PASS' || !runtime.serverReadOnly || runtime.filterFields !== 21) throw Error('Actual query compatibility failed');
    const pub = await publicCheck(site, build, receipt.commit); save(site + '-public.json', pub);
    const last = digests(site, Object.keys(targets));
    for (const [file, row] of Object.entries(targets)) if (last[file] !== row.after) throw Error('Target changed during verification');
    sharedUnchanged(p); save(site + '-after.json', after);
    save(site + '-verified.json', { result: 'PASS', site, commit: receipt.commit, tree: p.candidateTree, backup: directory, files: Object.keys(targets), businessWrites: 0, backendUnchanged: true, platformUnchanged: true, noServiceRestart: true, limit: pub.limit });
    console.log('R55_INSTANCE_VERIFIED', site, receipt.commit);
  } catch (error) {
    save(site + '-failure.json', { result: 'FAIL', error: error.message });
    if (started) try {
      fs.writeFileSync(path.join(evidence, site + '-rollback.log'), invoke('rollback')); unchanged(site, before);
      save(site + '-rollback.json', { result: 'PASS', directory, error: error.message });
    } catch (rollback) { save(site + '-rollback-attention.json', { result: 'MANUAL_ATTENTION_REQUIRED', error: rollback.message }); }
    throw error;
  }
}
function push() {
  ready(); const r = load('commit.json');
  if (load('rh-verified.json').result !== 'PASS') throw Error('Verify RH before push');
  unchanged('rh', load('rh-after.json'));
  const ref = 'refs/heads/' + r.branch; git(['push', 'origin', ref + ':' + ref]);
  if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== r.commit) throw Error('Push not verified');
  save('push.json', { result: 'PASS', commit: r.commit, branch: r.branch }); console.log('R55_PUSH_VERIFIED', r.commit);
}
function finalAudit() {
  const p = ready(), r = load('commit.json'); sharedUnchanged(p);
  for (const site of Object.keys(sites)) { if (load(site + '-verified.json').result !== 'PASS') throw Error('Incomplete instance'); unchanged(site, load(site + '-after.json')); }
  save('final-verification.json', { result: 'PASS', commit: r.commit, sites: Object.keys(sites), sharedIndexUntouched: true, sharedHeadUntouched: true, businessWrites: 0 });
  console.log('R55_FINAL_AUDIT_PASS');
}
// Archive only this round's release controls/evidence after all sites pass.
// The deployed business commit remains unchanged; shared checkout/index stay put.
function archive() {
  const p = ready(), r = load('commit.json'); sharedUnchanged(p);
  if (load('final-verification.json').result !== 'PASS') throw Error('Final audit required');
  for (const site of Object.keys(sites)) if (load(site + '-verified.json').result !== 'PASS') throw Error('All sites required');
  const ref = 'refs/heads/' + r.branch;
  if (git(['rev-parse', ref]).toString().trim() !== r.commit) throw Error('Release branch drift');
  const files = fs.readdirSync('scripts').filter(n => n.startsWith('r55-member-release-')).map(n => 'scripts/' + n);
  files.push('tests/cashier-v3/js/r55-release-installer.cjs');
  const collect = dir => { for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isSymbolicLink()) throw Error('Evidence symlink denied');
    if (entry.isDirectory()) collect(file); else if (entry.isFile()) files.push(file);
  } };
  collect(evidence); collect('tests/cashier-v3/evidence/R55-member-filters');
  const index = '/private/tmp/mohe-r55-archive-' + process.pid + '.index', env = { ...process.env, GIT_INDEX_FILE: index };
  try {
    git(['read-tree', r.commit], undefined, env);
    for (const file of files.sort()) {
      const blob = git(['hash-object', '-w', '--stdin'], fs.readFileSync(file)).toString().trim();
      git(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
    }
    const tree = git(['write-tree'], undefined, env).toString().trim();
    const changed = git(['-c', 'core.quotepath=false', 'diff', '--name-only', r.commit, tree]).toString().trim().split('\n');
    if (changed.some(f => !files.includes(f))) throw Error('Archive boundary mismatch');
    const commit = git(['commit-tree', tree, '-p', r.commit], Buffer.from('R55-00055 三站静态发布核验及独立回滚证据归档\n\n仅本轮工程控制与脱敏证据；线上业务提交保持3b078c16，无后端或数据库变更。\n')).toString().trim();
    sharedUnchanged(p); git(['update-ref', ref, commit, r.commit]); git(['push', 'origin', ref + ':' + ref]);
    if (git(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== commit) throw Error('Archive push verification failed');
    save('archive.json', { result: 'PASS', archiveCommit: commit, deployedBusinessCommit: r.commit, files: changed.length, sharedIndexUntouched: true });
    console.log('R55_ARCHIVE_PUSH_VERIFIED', commit);
  } finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
}
const [mode, site] = process.argv.slice(2);
if (mode === 'commit') commit();
else if (mode === 'deploy') deploy(site).catch(error => { console.error(error); process.exitCode = 1; });
else if (mode === 'push') push();
else if (mode === 'final-audit') finalAudit();
else if (mode === 'archive') archive();
else if (mode === 'installer') process.stdout.write(installerSource());
else throw Error('commit|deploy <site>|push|final-audit|installer required');
