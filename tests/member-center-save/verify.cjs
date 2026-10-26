// Freeze this closure only. Never stage, commit, deploy or recover production data.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const business = ['后端代码/app/services/diy/DiyServices.php',
  '后端代码/app/controller/api/v1/PublicController.php',
  '前端代码/admin/src/pages/setting/devise/users.vue',
  '前端代码/admin/src/store/modules/admin/modules/userTemplateConfig.js'];
const tests = ['editor.cjs', 'mysql.php', 'browser.cjs', 'mini.cjs', 'verify.cjs',
  'fixtures/accepted-service-menu.json', 'evidence/round8-fixed-candidate.md',
  'evidence/browser-result.json', 'evidence/mini-result.json',
  'evidence/h5-services-375.png', 'evidence/h5-services-320.png'].map(p => 'tests/member-center-save/' + p);
function run(command, args) {return cp.execFileSync(command, args, {cwd: root, encoding: 'utf8', timeout: 120000});}
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
const baseline = run('git', ['rev-parse', 'HEAD']).trim();
const results = {editor: run('node', ['tests/member-center-save/editor.cjs']).trim()};
for (const file of business.filter(p => p.endsWith('.php'))) {
  const result = run('docker', ['exec', 'mohe-app', 'php', '-l', '/var/www/html/' + file.slice('后端代码/'.length)]);
  if (!result.includes('No syntax errors')) throw new Error(result);
}
run('docker', ['cp', 'tests/member-center-save', 'mohe-app:/tmp/member-center-save-r8-final']);
results.mysql = run('docker', ['exec', 'mohe-app', 'php', '/tmp/member-center-save-r8-final/mysql.php']).trim();
if (!results.mysql.includes('member-center mysql PASS')) throw new Error(results.mysql);
for (const name of ['browser', 'mini']) {
  results[name] = JSON.parse(fs.readFileSync(path.join(__dirname, 'evidence', name + '-result.json')));
  if (results[name].status !== 'PASS') throw new Error(name + ' failed');
}
const fixture = fs.readFileSync(path.join(__dirname, 'fixtures/accepted-service-menu.json'), 'utf8').trim();
if (hash(fixture) !== '9bd2214a50dea15361b4b4c73df005c9b93b56f98a0ba2704db74f67d5ebf0cf') {
  throw new Error('Accepted restoration fixture changed');
}
const compiler = require(path.join(root, '前端代码/admin/node_modules/vue-template-compiler'));
const sfc = compiler.parseComponent(fs.readFileSync(path.join(root, business[2]), 'utf8'));
if (compiler.compile(sfc.template.content).errors.length) throw new Error('Editor template compile failed');
run('git', ['diff', 'HEAD', '--check', '--', ...business]);
if (run('git', ['rev-parse', 'HEAD']).trim() !== baseline) throw new Error('Baseline changed');
const files = [...business, ...tests].sort().map(file => ({file, sha256: hash(fs.readFileSync(path.join(root, file)))}));
const fingerprint = hash(JSON.stringify({baseline, files}));
const manifest = {task: 'M008-R8-001', status: 'SELF_TEST_PASS_AWAITING_PM_ACCEPTANCE', baseline,
  fingerprint, files, results, boundaries: {commit: false, deployed: false, productionDataRestored: false,
    miniUploaded: false, push: false, memberRightsModified: false},
  online: 'Read-only 008 UI/public API diagnosis; no remote shell or production write. Release baseline/backup must be captured on authorization.'};
fs.writeFileSync(path.join(__dirname, 'evidence/fixed-version.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(JSON.stringify({status: manifest.status, baseline, fingerprint, businessFiles: business.length, files: files.length}));
