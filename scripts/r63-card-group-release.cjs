// Release only the accepted R63 card-group backend files; mini-program upload is separate.
// Each instance must match the released R62 files before its own backup and switch.
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');

const root = path.resolve(__dirname, '..');
process.chdir(root);
const commit = 'f7989c57868b3ac98f21360ae476554a16a57ca0';
const branch = 'release/r63-combination-cart-20261025';
const base = 'release/r62-combination-20261025';
const files = [
  'app/services/order/StoreCartServices.php',
  'app/services/order/StoreOrderCreateServices.php',
];
const dependencies = [
  'app/services/activity/combination/StoreCombinationServices.php',
  'mohe/services/CacheService.php',
];
const sites = {
  rh: {host: 'rh-server', domain: 'rh.cc3798.com', restart: '/etc/init.d/ruihao_swoole restart', health: "ss -lnt | grep -q ':20800 '"},
  '008': {host: 'mohe-server', domain: '008.cc3798.com', restart: 'systemctl restart mohe-008-swoole.service', health: 'systemctl is-active --quiet mohe-008-swoole.service'},
  '010': {host: 'mohe-server', domain: '010.cc3798.com', restart: 'systemctl restart mohe-010-swoole.service', health: 'systemctl is-active --quiet mohe-010-swoole.service'},
};
const evidence = 'tests/combination-r63/release';
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const run = (tool, args, options = {}) => cp.execFileSync(tool, args, {maxBuffer: 16 * 1024 * 1024, timeout: 120000, ...options});
const git = (args, options) => run('git', args, options).toString().trim();
const ssh = (site, input) => run('ssh', ['-q', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=12', sites[site].host, 'bash -s'], {input}).toString();
const save = (site, name, result) => {
  fs.mkdirSync(evidence, {recursive: true});
  fs.writeFileSync(`${evidence}/${site}-${name}.json`, JSON.stringify(result, null, 2) + '\n', {flag: 'wx'});
};
const load = (site, name) => JSON.parse(fs.readFileSync(`${evidence}/${site}-${name}.json`));
const quoted = value => `'${value.replace(/'/g, "'\\''")}'`;
const source = rel => run('git', ['show', `${commit}:后端代码/${rel}`]);
const baseline = rel => sha(run('git', ['show', `${base}:后端代码/${rel}`]));

function guard() {
  const branchHead = git(['rev-parse', branch]);
  // The release record can be appended after all sites pass; the accepted
  // business commit must remain an ancestor and is always the payload source.
  if (branchHead !== commit) run('git', ['merge-base', '--is-ancestor', commit, branchHead]);
  if (git(['rev-parse', `${commit}^`]) !== git(['rev-parse', base])) throw Error('R62 parent changed');
  const changed = git(['diff', '--name-only', base, commit]).split('\n');
  const allowed = [...files.map(f => `后端代码/${f}`), '前端代码/uniapp/pages/activity/goods_details/index.vue', 'tests/combination-r63/card-group-cart-local.php'];
  if (changed.some(f => !allowed.includes(f)) || changed.length !== allowed.length) throw Error('R63 commit scope drift');
}

function preflight(site) {
  guard();
  const target = sites[site];
  const siteRoot = `/www/wwwroot/${target.domain}`;
  const all = [...files, ...dependencies];
  const lines = all.map(rel => `sha256sum ${quoted(`${siteRoot}/${rel}`)}`).join('\n');
  const result = ssh(site, `set -euo pipefail\n${target.health}\n${lines}\n`);
  const hashes = result.trim().split('\n').reduce((map, line) => {
    const match = line.match(/^([a-f0-9]{64})\s+(.+)$/);
    if (match) map[match[2].slice(siteRoot.length + 1)] = match[1];
    return map;
  }, {});
  for (const rel of all) if (hashes[rel] !== baseline(rel)) throw Error(`${site} live baseline changed: ${rel}`);
  return {site, commit, baseline: hashes, backendCandidate: Object.fromEntries(files.map(rel => [rel, sha(source(rel))]))};
}

function deploy(site) {
  if (site === '008') {
    if (load('rh', 'verified').result !== 'PASS' || load('shared', 'push').result !== 'PASS') throw Error('RH verification and push required');
  }
  if (site === '010' && load('008', 'verified').result !== 'PASS') throw Error('008 verification required');
  const pre = preflight(site);
  const target = sites[site];
  const siteRoot = `/www/wwwroot/${target.domain}`;
  const backup = `/www/backups/${site}/r63-combination-cart-${commit.slice(0, 8)}`;
  // An interrupted upload may leave only a partial stage file; the live baseline
  // and absence of a backup archive prove that no installation has started.
  const prepare = `set -euo pipefail\nif test -e ${quoted(backup)}; then\n  test -d ${quoted(`${backup}/stage`)}\n  test ! -e ${quoted(`${backup}/before.tar.gz`)}\nelse\n  mkdir -p ${quoted(`${backup}/stage`)}\nfi\n`;
  ssh(site, prepare);
  for (const rel of files) {
    const staged = `${backup}/stage/${path.basename(rel)}`;
    run('ssh', ['-q', '-o', 'BatchMode=yes', target.host, `cat > ${quoted(staged)}`], {input: source(rel)});
  }
  const beforeChecks = files.map(rel => `test "$(sha256sum ${quoted(`${siteRoot}/${rel}`)} | cut -d' ' -f1)" = ${quoted(pre.baseline[rel])}`).join('\n');
  const stageChecks = files.map(rel => `test "$(sha256sum ${quoted(`${backup}/stage/${path.basename(rel)}`)} | cut -d' ' -f1)" = ${quoted(pre.backendCandidate[rel])}\nphp -l ${quoted(`${backup}/stage/${path.basename(rel)}`)} >/dev/null`).join('\n');
  const fileArgs = files.map(rel => quoted(rel)).join(' ');
  const install = files.map(rel => {
    const dest = `${siteRoot}/${rel}`;
    const next = `${dest}.r63-next`;
    return `test ! -e ${quoted(next)}\ninstall -o www -g www -m 644 ${quoted(`${backup}/stage/${path.basename(rel)}`)} ${quoted(next)}\nmv ${quoted(next)} ${quoted(dest)}`;
  }).join('\n');
  const afterChecks = files.map(rel => `test "$(sha256sum ${quoted(`${siteRoot}/${rel}`)} | cut -d' ' -f1)" = ${quoted(pre.backendCandidate[rel])}`).join('\n');
  const script = `set -euo pipefail\n${beforeChecks}\n${stageChecks}\n${target.health}\n` +
    `tar -czf ${quoted(`${backup}/before.tar.gz`)} -C ${quoted(siteRoot)} ${fileArgs}\ngzip -t ${quoted(`${backup}/before.tar.gz`)}\n` +
    `rollback() { set +e; tar -xzf ${quoted(`${backup}/before.tar.gz`)} -C ${quoted(siteRoot)}; ${target.restart}; echo R63_ROLLBACK_ATTEMPTED >&2; }\n` +
    `trap rollback ERR\n${install}\n${target.restart}\n${target.health}\n${afterChecks}\ntrap - ERR\necho R63_SWITCH_PASS\n`;
  const output = ssh(site, script);
  if (!output.includes('R63_SWITCH_PASS')) throw Error(`${site} switch did not report success`);
  save(site, 'switch', {...pre, backup, result: 'PASS', output: output.trim()});
  console.log('R63_SWITCH_PASS', site, backup);
}

function verify(site) {
  const installed = load(site, 'switch');
  const target = sites[site];
  const siteRoot = `/www/wwwroot/${target.domain}`;
  const lines = [...files, ...dependencies].map(rel => `sha256sum ${quoted(`${siteRoot}/${rel}`)}`).join('\n');
  const out = ssh(site, `set -euo pipefail\n${target.health}\n${lines}\ngzip -t ${quoted(`${installed.backup}/before.tar.gz`)}\necho R63_RUNTIME_PASS\n`);
  for (const rel of files) if (!out.includes(`${installed.backendCandidate[rel]}  ${siteRoot}/${rel}`)) throw Error(`${site} candidate mismatch: ${rel}`);
  for (const rel of dependencies) if (!out.includes(`${installed.baseline[rel]}  ${siteRoot}/${rel}`)) throw Error(`${site} dependency drift: ${rel}`);
  for (const endpoint of [`https://${target.domain}/api/combination/list?page=1&limit=1`, `https://${target.domain}/view_cashier_v3/`]) {
    const code = run('curl', ['-L', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '20', endpoint]).toString();
    if (!['200', '401'].includes(code)) throw Error(`${site} endpoint ${endpoint}: ${code}`);
  }
  if (site === 'rh') {
    const code = run('curl', ['-sS', '-o', '/dev/null', '-w', '%{http_code}', '--max-time', '20', 'https://rh.cc3798.com/preview-8080/view_cashier_v3/']).toString();
    if (code !== '410') throw Error('Retired RH preview unexpectedly active');
  }
  save(site, 'verified', {site, commit, backup: installed.backup, backendCandidate: installed.backendCandidate, dependencies: installed.baseline, result: 'PASS', memberMiniUploaded: false, databaseMigration: false});
  console.log('R63_VERIFIED_PASS', site);
}

function push() {
  if (load('rh', 'verified').result !== 'PASS') throw Error('RH verification required');
  const remote = git(['ls-remote', 'origin', `refs/heads/${branch}`]);
  if (remote && !remote.startsWith(`${commit}\t`)) throw Error('Remote branch has diverged');
  git(['push', 'origin', `refs/heads/${branch}:refs/heads/${branch}`]);
  if (!git(['ls-remote', 'origin', `refs/heads/${branch}`]).startsWith(`${commit}\t`)) throw Error('Push mismatch');
  save('shared', 'push', {branch, commit, result: 'PASS'});
  console.log('R63_PUSH_PASS', commit);
}

const [mode, site] = process.argv.slice(2);
if (mode === 'preflight' && sites[site]) console.log(JSON.stringify(preflight(site)));
else if (mode === 'deploy' && sites[site]) deploy(site);
else if (mode === 'verify' && sites[site]) verify(site);
else if (mode === 'push') push();
else throw Error('Usage: preflight|deploy|verify <rh|008|010> OR push');
