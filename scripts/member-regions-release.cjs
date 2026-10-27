// Only the accepted R3 source is publishable. Customer configuration, store
// address data and unrelated work remain immutable; each instance gets its own guard.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), vm = require('vm');
const https = require('https');
const base = '/private/tmp/m008-r3-release-20261024';
const commit = '4f13dba0eac686adf2a029bac6dffc011a920c3a';
const parent = 'b50fd1f13d32c157ccd13e6ef63fb139d5d09702';
const sites = {rh: {host: 'rh-server', domain: 'rh.cc3798.com'}, '008': {host: 'mohe-server', domain: '008.cc3798.com'}, '010': {host: 'mohe-server', domain: '010.cc3798.com'}};
const run = (c, a, o = {}) => cp.execFileSync(c, a, {encoding: 'utf8', maxBuffer: 64e6, ...o});
const git = args => run('git', args).trim();
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const read = f => JSON.parse(fs.readFileSync(f));
const save = (f, v) => fs.writeFileSync(path.join(base, f), JSON.stringify(v, null, 2) + '\n');
const ensure = (v, m) => {if (!v) throw Error(m);};
const quote = v => "'" + v.replace(/'/g, "'\\''") + "'";
const backend = ['app/controller/api/v1/store/Store.php', 'app/services/store/SystemStoreServices.php', 'app/services/store/StoreSelectableRegionServices.php'];
fs.mkdirSync(base, {recursive: true});
function sourceGuard() {
  for (const dir of ['前端代码/uniapp', '前端代码/shared']) git(['diff', '--exit-code', commit, 'HEAD', '--', dir]);
  const fixed = read('tests/member-store-regions/evidence/fixed-version.json');
  for (const r of fixed.files) ensure(sha(fs.readFileSync(r.file)) === r.sha256, 'Fixed source drift: ' + r.file);
}
function walk(root) {
  const files = {};
  const visit = dir => {for (const e of fs.readdirSync(dir, {withFileTypes: true})) {const f = path.join(dir, e.name);if (e.isDirectory()) visit(f);else files['public/' + path.relative(root, f)] = sha(fs.readFileSync(f));}};
  visit(root);return files;
}
function inspect(key, extra = []) {
  const prior = read('/private/tmp/m008-cube-release-20261022/payload/manifest.json');
  const previous = Object.keys(prior.allFiles).filter(f => !f.startsWith('public/view_admin/') && f !== 'public/system.html');
  const files = [...new Set([...previous, ...backend, ...extra])];
  const encoded = Buffer.from(JSON.stringify({key, files})).toString('base64');
  return JSON.parse(run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', sites[key].host, 'env REGION_PROBE=' + encoded + ' /www/server/php/74/bin/php'], {input: fs.readFileSync('scripts/member-regions-inspect.php')}));
}
function payload() {
  const root = path.join(base, 'payload');
  ensure(read(path.join(base, 'build/member-candidate-complete.json')).result === 'PASS', 'Build incomplete');
  fs.mkdirSync(root, {recursive: true});
  const files = walk(path.join(base, 'build/member-candidate'));
  for (const f of Object.keys(files)) {
    ensure(!/(?:^|\/)(?:system\.html|view_admin|view_cashier_v3)(?:\/|$)/.test(f), 'Protected payload');
    const target = path.join(root, f);fs.mkdirSync(path.dirname(target), {recursive: true});
    fs.copyFileSync(path.join(base, 'build/member-candidate', f.slice(7)), target);
  }
  for (const f of backend) {
    const bytes = cp.execFileSync('git', ['show', commit + ':后端代码/' + f]);
    const target = path.join(root, f);fs.mkdirSync(path.dirname(target), {recursive: true});fs.writeFileSync(target, bytes);files[f] = sha(bytes);
  }
  fs.writeFileSync(path.join(root, 'manifest.json'), JSON.stringify({commit, parent, files}, null, 2));
  run('tar', ['-czf', path.join(base, 'payload.tar.gz'), '-C', base, 'payload']);
  save('payload-fingerprint.json', {commit, fileCount: Object.keys(files).length, archiveSha256: sha(fs.readFileSync(path.join(base, 'payload.tar.gz'))), files});
  console.log(JSON.stringify({files: Object.keys(files).length, index: files['public/index.html']}));
}
function compareSnapshot(before, now) {
  for (const k of ['instance', 'database', 'environment', 'front', 'latestUpgrade', 'digests']) ensure(JSON.stringify(before[k]) === JSON.stringify(now[k]), 'Instance baseline drift: ' + k);
  for (const [f, v] of Object.entries(before.files)) ensure(now.files[f] === v, 'Online baseline drift: ' + f);
}
function remote(key, command, input) {return run('ssh', ['-q', '-o', 'BatchMode=yes', sites[key].host, command], {input});}
function get(key, url) {
  return new Promise((resolve, reject) => {
    const request = https.get('https://' + sites[key].domain + url, {headers: {'Accept-Encoding': 'identity', 'Cache-Control': 'no-cache'}}, r => {
      const chunks = [];r.on('data', b => chunks.push(b));r.on('end', () => resolve({status: r.statusCode, body: Buffer.concat(chunks)}));
    });request.on('error', reject);request.setTimeout(30000, () => request.destroy(Error('HTTP timeout')));
  });
}
async function publicCheck(key, candidate) {
  const snapshot = read(path.join(base, key + (candidate ? '-after.json' : '-before.json')));
  const result = {entries: {}, api: {}, result: 'PASS'};
  const apiBodies = {};
  for (const [name, entry] of Object.entries(snapshot.front)) {
    if (!entry.index) continue;
    const url = {member: '/pages/index/index', admin: '/system.html', cashier: '/view_cashier_v3/', cashier_preview: '/preview-8080/view_cashier_v3/'}[name];
    const response = await get(key, url);
    // PM explicitly approved 010's current public state after inspection
    // contradicted the historical Basic Auth record. Authentication is untouched.
    ensure(response.status === 200, 'Public entry failed: ' + name);
    if (name !== 'cashier_preview') ensure(sha(response.body) === entry.index, 'Public entry fingerprint differs: ' + name);
    for (const [ref, expected] of Object.entries(entry.assets)) {
      let asset = new URL(ref, 'https://' + sites[key].domain + url).pathname;
      if (name === 'cashier_preview' && !asset.startsWith('/preview-8080/')) asset = '/preview-8080' + asset;
      const file = await get(key, asset);ensure(file.status === 200 && sha(file.body) === expected, 'Public dependency differs: ' + asset);
    }
    result.entries[name] = {sha256: sha(response.body), dependencyCount: Object.keys(entry.assets).length};
  }
  {
    for (const url of ['/api/diy/get_diy/0', '/api/menu/user', '/api/store/list?is_select=1&page=1&limit=20', '/api/store/list?is_select=1&page=2&limit=20', '/api/store/list?is_select=1&keywords=M008_REGION_UNMATCHED_20261024']) {
      const response = await get(key, url);ensure(response.status === 200, 'HTTP API failed');
      const json = JSON.parse(response.body);ensure(Number(json.status) === 200, 'Business API failed: ' + url);
      apiBodies[url] = json.data;
      result.api[url] = {status: json.status, dataSha256: sha(JSON.stringify(json.data)), count: Array.isArray(json.data) ? json.data.length : null};
    }
    if (candidate) {
      const before = read(path.join(base, key + '-public-before.json'));
      save(key + '-api-comparison.json', {before: before.api, after: result.api});
      for (const [url, expected] of Object.entries(before.api)) {
        if (JSON.stringify(expected) === JSON.stringify(result.api[url])) continue;
        // 010's scoped worker restart exposes the existing configuration value
        // as "0" rather than the previous numeric default. Permit exactly this
        // proven scalar-only difference, never a changed menu or permission.
        const data = apiBodies[url];
        ensure(key === '010' && url === '/api/menu/user' && data.routine_contact_type === '0', 'Existing API behavior changed: ' + url);
        const normalized = {...data, routine_contact_type: 0};
        ensure(sha(JSON.stringify(normalized)) === expected.dataSha256, 'Menu changed beyond contact scalar type');
        result.configurationScalarNote = {field: 'routine_contact_type', before: 0, after: '0', exactPriorHashReproduced: true, memberConsumers: 'Number(...)', noConfigurationWrites: true};
      }
      const url = '/api/store/list?is_select=1&region_options=1';
      const regions = JSON.parse((await get(key, url)).body);ensure(Number(regions.status) === 200 && Array.isArray(regions.data.regions), 'Region API response invalid');
      const filtered = [];
      for (const p of regions.data.regions) {
        for (const q of [{province: p.id}, ...p.children.map(c => ({province: p.id, city: c.id})), ...p.children.flatMap(c => c.children.map(a => ({province: p.id, city: c.id, area: a.id})))]) {
          const response = JSON.parse((await get(key, '/api/store/list?is_select=1&' + new URLSearchParams(q))).body);
          ensure(Number(response.status) === 200 && Array.isArray(response.data) && response.data.length > 0, 'Region produces no store');
          filtered.push({...q, count: response.data.length, idsSha256: sha(JSON.stringify(response.data.map(s => s.id)))});
        }
      }
      // The existing API names the search parameter `keyword` (singular).
      // Unknown product IDs intentionally retain the original fallback and
      // are not evidence of a scoped product with zero applicable stores.
      for (const query of ['keyword=M008_REGION_UNMATCHED_20261024']) {
        const empty = JSON.parse((await get(key, url + '&' + query)).body);ensure(Number(empty.status) === 200 && empty.data.regions.length === 0, 'Region scope widened');
      }
      const unknownProduct = JSON.parse((await get(key, url + '&product_id=2147483646')).body);
      ensure(JSON.stringify(unknownProduct.data) === JSON.stringify(regions.data), 'Original unknown-product fallback changed');
      result.regions = {provinces: regions.data.regions.length, incomplete: regions.data.incomplete_count, checks: filtered};
    }
  }
  save(key + '-public-' + (candidate ? 'after' : 'before') + '.json', result);
  console.log(JSON.stringify({site: key, result: 'PASS', entries: result.entries, regions: result.regions}));
}
function deploy(key) {
  ensure(sites[key], 'Explicit target required');
  if (key !== 'rh') {
    const previous = key === '008' ? 'rh' : '008';
    ensure(read(path.join(base, previous + '-public-after.json')).result === 'PASS' && read(path.join(base, previous + '-browser.json')).result === 'PASS', 'Previous instance verification incomplete');
    const pushed = read(path.join(base, 'pushed.json'));
    ensure(git(['ls-remote', 'origin', 'refs/heads/' + pushed.branch]).split(/\s/)[0] === pushed.remoteCommit, 'Precise push not verified');
  }
  const manifest = read(path.join(base, 'payload/manifest.json'));
  const now = inspect(key, Object.keys(manifest.files));
  compareSnapshot(read(path.join(base, key + '-before.json')), now);
  save(key + '-preswitch.json', now);
  const dir = '/www/backups/' + sites[key].domain + '/20261024-member-regions-4f13dba0';
  const root = '/www/wwwroot/' + sites[key].domain;
  // An existing release directory is never reused; no retries can silently
  // overwrite the original rollback snapshot or a newer instance deployment.
  remote(key, 'test ! -e ' + quote(dir) + ' && mkdir -p ' + quote(dir) + ' && chmod 700 ' + quote(dir));
  remote(key, 'cat > ' + quote(dir + '/baseline.json'), JSON.stringify(now));
  remote(key, 'cat > ' + quote(dir + '/install.php'), fs.readFileSync('scripts/member-regions-install.php'));
  remote(key, 'cat > ' + quote(dir + '/payload.tar.gz'), fs.readFileSync(path.join(base, 'payload.tar.gz')));
  const actual = remote(key, 'sha256sum ' + quote(dir + '/payload.tar.gz')).split(/\s/)[0];
  ensure(actual === sha(fs.readFileSync(path.join(base, 'payload.tar.gz'))), 'Transferred archive differs');
  remote(key, 'tar -xzf ' + quote(dir + '/payload.tar.gz') + ' -C ' + quote(dir));
  const invoke = mode => remote(key, '/www/server/php/74/bin/php ' + quote(dir + '/install.php') + ' ' + quote(key) + ' ' + quote(dir) + ' ' + mode);
  console.log(invoke('prepare').trim());
  try {
    console.log(invoke('install').trim());
    const after = inspect(key, Object.keys(manifest.files));
    for (const [f, h] of Object.entries(manifest.files)) ensure(after.files[f] === h, 'Post-install hash mismatch: ' + f);
    for (const k of ['database', 'environment', 'digests', 'latestUpgrade']) ensure(JSON.stringify(after[k]) === JSON.stringify(now[k]), 'Protected instance state changed: ' + k);
    for (const k of Object.keys(now.front).filter(k => k !== 'member')) ensure(JSON.stringify(after.front[k]) === JSON.stringify(now.front[k]), 'Other frontend changed: ' + k);
    save(key + '-after.json', after);
    save(key + '-deploy.json', {result: 'FILES_PASS', commit, backup: dir, databaseWrites: 0, root, time: new Date().toISOString()});
  } catch (e) {
    save(key + '-failure.json', {error: e.message});
    console.log(invoke('rollback').trim());throw e;
  }
}
function push() {
  ensure(read(path.join(base, 'rh-public-after.json')).result === 'PASS' && read(path.join(base, 'rh-browser.json')).result === 'PASS', 'RH real regression must pass before push');
  const previous = 'c77d66d5b8dc9c68be5998dcf18771a80a5f1bfd';
  const branch = 'release/member-regions-r3-20261024';
  const remoteBefore = git(['ls-remote', 'origin', 'refs/heads/release/member-cube-r2-20261022', 'refs/heads/' + branch]);
  ensure(remoteBefore === previous + '\trefs/heads/release/member-cube-r2-20261022', 'Remote baseline changed or release already exists');
  for (const dir of ['前端代码/uniapp', '前端代码/shared', ...backend.map(f => '后端代码/' + f)]) git(['diff', '--exit-code', previous, parent, '--', dir]);
  const index = path.resolve(git(['rev-parse', '--git-path', 'index']));
  const indexBefore = sha(fs.readFileSync(index));
  const env = {...process.env, GIT_INDEX_FILE: path.join(base, 'push.index')};
  run('git', ['read-tree', previous], {env});
  const files = run('git', ['diff-tree', '--no-commit-id', '--name-only', '-z', '-r', commit]).split('\0').filter(Boolean);
  ensure(files.length === 32, 'Accepted commit boundary changed');
  for (const f of files) {
    const entry = run('git', ['ls-tree', '-z', commit, '--', f]);
    const m = /^(\d+) blob ([0-9a-f]+)\t/.exec(entry);ensure(m, 'Expected accepted file');
    run('git', ['update-index', '--add', '--cacheinfo', m[1], m[2], f], {env});
  }
  const tree = run('git', ['write-tree'], {env}).trim();
  for (const f of files) ensure(git(['rev-parse', tree + ':' + f]) === git(['rev-parse', commit + ':' + f]), 'Push blob differs');
  const changed = run('git', ['diff-tree', '--no-commit-id', '--name-only', '-z', '-r', previous, tree]).split('\0').filter(Boolean);
  ensure(changed.length === files.length && changed.every(f => files.includes(f)), 'Unrelated push content');
  ensure(sha(fs.readFileSync(index)) === indexBefore, 'Shared staging changed');
  const releaseCommit = run('git', ['commit-tree', tree, '-p', previous], {input: 'M008-EMERG-R3-REGION 门店实际地区筛选精准发布\n\nAccepted canonical source: ' + commit + '\nOnly the 32 accepted round3 files; previous live source retained.\n'}).trim();
  run('git', ['update-ref', 'refs/heads/' + branch, releaseCommit, '0000000000000000000000000000000000000000']);
  run('git', ['push', 'origin', releaseCommit + ':refs/heads/' + branch]);
  ensure(git(['ls-remote', 'origin', 'refs/heads/' + branch]).split(/\s/)[0] === releaseCommit, 'Remote push not verified');
  ensure(sha(fs.readFileSync(index)) === indexBefore, 'Shared staging changed after push');
  const receipt = {result: 'PASS', sourceCommit: commit, remoteCommit: releaseCommit, branch, parent: previous, files, sharedIndexUnchanged: true};
  save('pushed.json', receipt);console.log(JSON.stringify(receipt));
}
function archive() {
  const destination = 'tests/member-store-regions/release-20261024';
  fs.mkdirSync(destination, {recursive: true});
  const copied = [];
  for (const key of Object.keys(sites)) {
    ensure(read(path.join(base, key + '-public-after.json')).result === 'PASS' && read(path.join(base, key + '-browser.json')).result === 'PASS', 'Incomplete instance verification');
    for (const suffix of ['before.json', 'preswitch.json', 'after.json', 'deploy.json', 'public-before.json', 'public-after.json', 'browser.json', 'api-comparison.json', 'online-320.png', 'online-375.png', 'regions-320.png', 'regions-375.png']) {
      const name = key + '-' + suffix;
      if (fs.existsSync(path.join(base, name))) {fs.copyFileSync(path.join(base, name), path.join(destination, name));copied.push(name);}
    }
  }
  for (const name of ['pushed.json', 'payload-fingerprint.json']) {fs.copyFileSync(path.join(base, name), path.join(destination, name));copied.push(name);}
  for (const name of ['member-candidate-input.json', 'member-candidate-complete.json']) {fs.copyFileSync(path.join(base, 'build', name), path.join(destination, name));copied.push(name);}
  const report = {task: 'M008-EMERG-R3-REGION', sourceCommit: commit, acceptedPatch: '1a8849744f6e8e2ba9c25eb520580e30913a104355928a5194774cd55a2ddbfc', push: read(path.join(base, 'pushed.json')), sites: {}, miniUpload: read(path.join(destination, 'upload-pending.json')), files: {}};
  for (const key of Object.keys(sites)) report.sites[key] = {...read(path.join(base, key + '-deploy.json')), result: 'PASS', APIRegression: 'PASS', browser320and375: 'PASS', incompleteRegions: read(path.join(base, key + '-public-after.json')).regions.incomplete};
  report.authentication010 = {historicalRecord: 'Basic Auth', actualBefore: 'public HTTP 200', approval: '按当前公开状态部署010会员端H5', authenticationChanges: 0};
  report.preservation = ['original single-image/cube slides', 'member store selection callbacks', 'original entry/product scope and pagination', 'themes, cashier/admin frontends, decoration, store records and DB upgrades unchanged'];
  for (const f of copied) report.files[f] = sha(fs.readFileSync(path.join(destination, f)));
  fs.writeFileSync(path.join(destination, 'release-result.json'), JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify({sourceCommit: commit, instances: Object.keys(report.sites), upload: report.miniUpload.pendingTask.status, archivedFiles: copied.length + 2}));
}
async function main() {
  sourceGuard();
  const [mode, key] = process.argv.slice(2);
  if (mode === 'build') {
    let script = fs.readFileSync('scripts/member-commerce-release-build.cjs', 'utf8')
      .replace(/const commit=variant===.*?;/, `const commit='${commit}';`)
      .replace("const base='/private/tmp/m008-round3-release-20261020';", `const base='${base}/build';`)
      .replace('process.exit(0);return value;', "if(fs.existsSync(path.join(output,'index.html')))process.exit(0);return value;")
      .replace('const init=Service.prototype.init;', "process.once('exit', code => {const exists=fs.existsSync(path.join(output,'index.html'));fs.writeFileSync(output+'-complete.json',JSON.stringify({commit,terminal,result:code===0&&exists?'PASS':'FAIL',actualOutput:exists}));if(!exists)process.exitCode=1;});const init=Service.prototype.init;");
    process.argv = [process.argv[0], 'region-h5-build', 'member', 'candidate'];
    vm.runInThisContext('(function(require,__dirname,__filename){' + script + '\n})')(require, path.resolve('scripts/build'), __filename);
  } else if (mode === 'inspect') {
    ensure(sites[key], 'Explicit site required');
    ensure(!fs.existsSync(path.join(base, key + '-before.json')), 'Never overwrite an original instance snapshot');
    const output = path.join(base, 'build/member-candidate');
    const snap = inspect(key, fs.existsSync(output) ? Object.keys(walk(output)) : []);
    save(key + '-before.json', snap);
    const expected = Object.fromEntries(backend.map(f => {let bytes;try {bytes = cp.execFileSync('git', ['show', parent + ':后端代码/' + f]);} catch (_) {return [f, null];}return [f, sha(bytes)];}));
    console.log(JSON.stringify({site: key, front: snap.front, database: snap.database, latestUpgrade: snap.latestUpgrade, backend: Object.fromEntries(backend.map(f => [f, {actual: snap.files[f], expected: expected[f], matches: snap.files[f] === expected[f]}]))}));
  } else if (mode === 'pack') payload();
  else if (mode === 'deploy') deploy(key);
  else if (mode === 'public-before') await publicCheck(key, false);
  else if (mode === 'verify') await publicCheck(key, true);
  else if (mode === 'push') push();
  else if (mode === 'archive') archive();
  else throw Error('Unknown mode');
}
main().catch(e => {console.error(e.stack);process.exitCode = 1;});
