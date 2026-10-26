const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto'), zlib = require('zlib');
// Deploy only this accepted reader and its canonical fixed-tree cashier assets.
// Existing report baselines remain bound to their prior approval; no DB writes.
const root = path.resolve(__dirname, '..'); process.chdir(root);
const site = process.argv[2]; if (!['rh', '008', '010'].includes(site)) throw Error('Explicit site required');
const dir = 'tests/cashier-v3/evidence/m012-reservation-shortcuts-2026-10-26/release';
const binding = site === 'rh' ? 'rh' : 'existing-report-month';
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const run = (cmd, args, options = {}) => cp.execFileSync(cmd, args, { maxBuffer: 32e6, ...options });
const ssh = (cmd, input) => run('ssh', ['-q', site === 'rh' ? 'rh-server' : 'mohe-server', cmd], { input });
const php = input => ssh('/www/server/php/74/bin/php -- ' + site, input);
const inspect = () => JSON.parse(php(fs.readFileSync('scripts/r28-permissions-inspect.php')));
const save = (name, value) => fs.writeFileSync(dir + '/' + site + '-' + name + '.json', JSON.stringify(value, null, 2) + '\n', { flag: 'wx' });
const commit = JSON.parse(fs.readFileSync(dir + '/commit.json')).roundCommit;
const build = JSON.parse(fs.readFileSync(dir + '/' + binding + '/candidate-cashier-v3-build.json'));
const before = JSON.parse(fs.readFileSync(dir + '/' + site + '-before.json'));
const base = '/www/wwwroot/' + (site === 'rh' ? 'rh' : site) + '.cc3798.com';
const backup = '/www/backups/' + site + '/m012-reservation-' + commit.slice(0, 8);
const backend = 'app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php';
const source = run('git', ['show', commit + ':后端代码/' + backend]);
if (sha(source) !== '0517613481cd3775bc9523b8fdbd446d25491b829e4793219b186d6423efba0f') throw Error('Accepted reader mismatch');
if (build.result !== 'PASS' || !build.sourceStable) throw Error('Fixed build not verified');
const payload = { [backend]: source };
for (const [rel, hash] of Object.entries(build.assets)) {
  const bytes = fs.readFileSync(path.join(build.output, rel)); if (sha(bytes) !== hash) throw Error('Artifact drift');
  payload['public/view_cashier_v3/' + rel] = bytes;
}
// In-memory POSIX tar: deployment payloads never become editable application copies.
function archive(files) {
  const blocks = [];
  for (const [name, bytes] of Object.entries(files)) {
    if (Buffer.byteLength(name) > 100 || !Buffer.isBuffer(bytes)) throw Error('Invalid archive entry');
    const h = Buffer.alloc(512); h.write(name); h.write('0000600\0', 100); h.write('0000000\0', 108); h.write('0000000\0', 116);
    h.write(bytes.length.toString(8).padStart(11, '0') + '\0', 124); h.write('00000000000\0', 136); h.fill(32, 148, 156); h.write('0', 156); h.write('ustar\0', 257); h.write('00', 263);
    const sum = h.reduce((a, b) => a + b, 0); h.write(sum.toString(8).padStart(6, '0') + '\0 ', 148);
    blocks.push(h, bytes, Buffer.alloc((512 - bytes.length % 512) % 512));
  }
  return zlib.gzipSync(Buffer.concat([...blocks, Buffer.alloc(1024)]));
}
function assertSame(a, b, label) { if (JSON.stringify(a) !== JSON.stringify(b)) throw Error(label); }
assertSame(inspect(), before, 'Instance changed since preflight');
const names = Object.keys(payload);
const hashProbe = Buffer.from('<?php $root=' + JSON.stringify(base) + ';$r=[];foreach(json_decode(' + JSON.stringify(JSON.stringify(names)) + ',true) as $p)$r[$p]=is_file($root."/".$p)?hash_file("sha256",$root."/".$p):null;echo json_encode($r);');
const currentHashes = JSON.parse(php(hashProbe));
const targets = {};
// Skip identical shared images/vendor assets, but verify their fingerprints.
for (const name of names.sort((a, b) => Number(a.endsWith('index.html')) - Number(b.endsWith('index.html')) || a.localeCompare(b))) {
  const after = sha(payload[name]); if (currentHashes[name] !== after) targets[name] = { before: currentHashes[name], after };
}
if (targets[backend]?.before !== before.backend[backend]) throw Error('Backend baseline mismatch');
const manifest = { site, commit, sourceTree: build.tree, binding, targets, noDatabaseWrites: true, acceptedPatch: 'f43cbd4d' };
const installer = fs.readFileSync('scripts/r33-release-install.php', 'utf8').split('r33-deal-people').join('m012-reservation');
const files = { 'install.php': Buffer.from(installer), 'payload/manifest.json': Buffer.from(JSON.stringify(manifest)) };
for (const name of Object.keys(targets)) files['payload/' + name] = payload[name];
ssh("test ! -e '" + backup + "' && mkdir -p -m 700 '" + backup + "'");
ssh("tar -xzf - -C '" + backup + "'", archive(files));
ssh('/www/server/php/74/bin/php -l ' + backup + '/payload/' + backend);
assertSame(inspect(), before, 'Instance changed during upload');
save('preswitch', before);
const restart = site === 'rh' ? '/etc/init.d/ruihao_swoole restart' : 'systemctl restart mohe-' + site + '-swoole.service';
const healthy = site === 'rh' ? '/etc/init.d/ruihao_swoole status' : 'systemctl is-active mohe-' + site + '-swoole.service';
let touched = false;
try {
  touched = true;
  const installed = ssh('/www/server/php/74/bin/php ' + backup + '/install.php ' + backup + ' install').toString();
  const runtime = ssh(restart + ' && ' + healthy).toString();
  const after = inspect(), expected = JSON.parse(JSON.stringify(before));
  expected.backend[backend] = sha(source);
  const expectedFront = expected.frontends.find(f => f.kind === 'cashier' && f.root === base);
  expectedFront.sha256 = build.assets['index.html'];
  expectedFront.assets = Object.fromEntries(Object.entries(build.assets).filter(([p]) => /\.(js|css)$/.test(p) && p.startsWith('assets/index-')).map(([p, h]) => ['/view_cashier_v3/' + p, h]));
  // Object order is immaterial for fingerprints; compare normalized JSON.
  const normalize = x => x && typeof x === 'object' ? (Array.isArray(x) ? x.map(normalize) : Object.fromEntries(Object.keys(x).sort().map(k => [k, normalize(x[k])]))) : x;
  assertSame(normalize(after), normalize(expected), 'Unplanned instance file change');
  const actual = JSON.parse(php(hashProbe)); for (const [name, bytes] of Object.entries(payload)) if (actual[name] !== sha(bytes)) throw Error('Deployed asset mismatch');
  const business = JSON.parse(php(fs.readFileSync('scripts/m012-release-readonly.php'))); if (!business.patched || !business.customSnapshotMatch) throw Error('Business verification failed');
  const publicChecks = [];
  for (const rel of ['index.html', ...Object.keys(expectedFront.assets).map(p => p.replace('/view_cashier_v3/', ''))]) {
    const url = 'https://' + (site === 'rh' ? 'rh' : site) + '.cc3798.com/view_cashier_v3/' + (rel === 'index.html' ? '' : rel);
    const bytes = run('curl', ['--fail', '-sS', '--retry', '1', '--max-time', '30', url]);
    if (sha(bytes) !== build.assets[rel]) throw Error('Public fingerprint mismatch ' + rel);
    publicChecks.push({ url, sha256: sha(bytes), matched: true });
  }
  if (site === 'rh') for (const rel of ['preview-8080/', 'store-v3/']) {
    const status = run('curl', ['-sS', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '20', 'https://rh.cc3798.com/' + rel]).toString();
    if (status !== '410') throw Error('Retired entry changed'); publicChecks.push({ path: rel, status });
  }
  save('after', after); save('business-after', business); save('manifest', manifest);
  save('verified', { site, commit, sourceTree: build.tree, backup, installed, runtime, publicChecks, files: Object.keys(targets).length, existingBackendAndOtherEntrypointsPreserved: true, noBusinessCommands: true, result: 'PASS', at: new Date().toISOString() });
  console.log(JSON.stringify({ site, result: 'PASS', commit, backup, files: Object.keys(targets).length, business }));
} catch (error) {
  if (touched) {
    const rollback = ssh('/www/server/php/74/bin/php ' + backup + '/install.php ' + backup + ' rollback && ' + restart).toString();
    save('rollback', { error: error.message, rollback, at: new Date().toISOString() });
  }
  throw error;
}
