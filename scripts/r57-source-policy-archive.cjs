// Archive only R57 controls/evidence after all three independent deployments.
// The application version stays the fixed business commit; shared HEAD/index
// and other tasks' staged files are never modified by this archive commit.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const e = 'tests/cashier-v3/evidence/R57-release';
const load = name => JSON.parse(fs.readFileSync(path.join(e, name)));
const run = (args, input, env = process.env) => cp.execFileSync('git', args, { input, env, maxBuffer: 128 * 1024 * 1024 });
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const release = load('commit.json'), audit = load('shared-head-recheck.json');
for (const site of ['rh', '008', '010']) {
  if (load(site + '-verified.json').result !== 'PASS' || load(site + '-runtime.json').result !== 'PASS') throw Error('All instances must pass');
}
const files = [];
function collect(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) collect(file); else if (entry.isFile()) files.push(file); else throw Error('Unexpected evidence link');
  }
}
collect(e);
for (const name of fs.readdirSync('scripts').filter(name => /^r57-source-policy-(?:archive|publish|install|migrate|runtime|release-.+|mobile-build|preserved-test)\.(?:cjs|php)$/.test(name))) files.push('scripts/' + name);
files.push('tests/cashier-v3/php/r57-release-installer.php');
for (const name of ['README.md', 'main-baseline.json', 'selftest.json', 'builds.json', 'fixed-source-member-followup.json', 'previous-source-followup.json']) {
  const file = 'tests/cashier-v3/evidence/R57-source-policy/' + name;
  if (fs.existsSync(file)) files.push(file);
}
const index = '/private/tmp/mohe-r57-archive-' + process.pid + '.index';
const env = { ...process.env, GIT_INDEX_FILE: index };
function sharedUnchanged() {
  if (run(['rev-parse', 'HEAD']).toString().trim() !== audit.head || sha(fs.readFileSync(run(['rev-parse', '--git-path', 'index']).toString().trim())) !== audit.indexSHA256) throw Error('Shared state drift; audit before archiving');
}
sharedUnchanged();
try {
  run(['read-tree', release.commit], undefined, env);
  for (const file of files) {
    const blob = run(['hash-object', '-w', '--stdin'], fs.readFileSync(file)).toString().trim();
    run(['update-index', '--add', '--cacheinfo', '100644', blob, file], undefined, env);
  }
  const tree = run(['write-tree'], undefined, env).toString().trim();
  // Keep compiler logs and patch evidence byte-for-byte, including whitespace
  // emitted by upstream tools. Whitespace lint applies to source and prose.
  const lint = files.filter(file => /\.(?:php|cjs|mjs|md)$/.test(file));
  run(['diff', '--check', release.commit, tree, '--', ...lint]);
  const paths = run(['-c', 'core.quotepath=false', 'diff', '--name-only', release.commit, tree]).toString().trim().split('\n');
  if (paths.some(file => !files.includes(file))) throw Error('Archive scope drift');
  const commit = run(['commit-tree', tree, '-p', release.commit], Buffer.from('R57-00057 三站来源策略发布控制与核验证据归档\n\n应用业务提交保持' + release.commit + '。仅归档本轮脚本、安装回滚测试及脱敏证据；共享HEAD/index保持原样。\n')).toString().trim();
  const ref = 'refs/heads/' + release.branch;
  run(['update-ref', ref, commit, release.commit]); sharedUnchanged();
  run(['push', 'origin', ref + ':' + ref]);
  if (run(['ls-remote', 'origin', ref]).toString().split(/\s/)[0] !== commit) throw Error('Archive push mismatch');
  fs.writeFileSync(e + '/archive.json', JSON.stringify({ result: 'PASS', commit, tree, applicationCommit: release.commit,
    branch: release.branch, files: paths, sharedIndexUntouched: true }, null, 2) + '\n', { flag: 'wx' });
  console.log('R57_ARCHIVE_PUSH_VERIFIED', commit, 'files', paths.length);
} finally { if (fs.existsSync(index)) fs.unlinkSync(index); }
