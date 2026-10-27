const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');

// Immutable exports are build inputs, not editable business-source copies.
// Only canonical fixed Git trees are accepted, never the dirty development tree.
const root = process.cwd();
const base = '/private/tmp/mohe-r28-fixed-release';
const variant = process.argv[2], terminal = process.argv[3];
if (!['baseline', 'candidate'].includes(variant) || !['admin', 'cashier-v3'].includes(terminal)) throw Error('Expected variant and terminal');
// 平台沿用已上线 Node16 编译链；新Node的旧webpack MD4实现会对Unicode路径产生冲突。
if (terminal === 'admin' && process.versions.node.split('.')[0] !== '16') throw Error('Admin release requires the accepted Node16 toolchain');
const trees = JSON.parse(fs.readFileSync('tests/store-permission-r28/evidence/release/trees.json'));
const tree = trees[variant + 'Tree'];
const project = '前端代码/' + terminal;
const snapshot = path.join(base, variant + '-readonly');
const input = path.join(snapshot, project);
const output = path.join(base, variant + '-' + terminal + '-dist');
const git = args => cp.execFileSync('git', args, { cwd: root, maxBuffer: 256 * 1024 * 1024 });
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const packages = ['前端代码/admin', '前端代码/cashier-v3', '前端代码/shared', '前端代码/inventory-vue3'];
if (!fs.existsSync(input)) {
  fs.mkdirSync(snapshot, { recursive: true });
  cp.execFileSync('tar', ['-xf', '-', '-C', snapshot], { input: git(['archive', tree, ...packages]) });
  for (const name of ['admin', 'cashier-v3', 'inventory-vue3']) fs.symlinkSync(path.join(root, '前端代码', name, 'node_modules'), path.join(snapshot, '前端代码', name, 'node_modules'));
  const freeze = dir => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const file = path.join(dir, entry.name);
      if (entry.isSymbolicLink()) continue;
      if (entry.isDirectory()) freeze(file); else fs.chmodSync(file, 0o444);
    }
    fs.chmodSync(dir, 0o555);
  };
  freeze(snapshot);
}
// Allow a previously exported, failed build to acquire only its dependency link.
const inventory = path.join(snapshot, '前端代码/inventory-vue3');
if (!fs.existsSync(path.join(inventory, 'node_modules'))) {
  fs.chmodSync(inventory, 0o755);
  fs.symlinkSync(path.join(root, '前端代码/inventory-vue3/node_modules'), path.join(inventory, 'node_modules'));
  fs.chmodSync(inventory, 0o555);
}
const files = git(['ls-tree', '-rz', '--name-only', tree, '--', ...packages]).toString().split('\0').filter(Boolean);
for (const file of files) if (!fs.readFileSync(path.join(snapshot, file)).equals(git(['show', tree + ':' + file]))) throw Error('Snapshot drift: ' + file);
process.env.NODE_ENV = 'production';
process.env.ANALYZE = 'false';
process.env.BROWSERSLIST_IGNORE_OLD_DATA = 'true';
process.chdir(input);
const finish = () => {
  const assets = {};
  const scan = dir => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const file = path.join(dir, entry.name);
      if (entry.isDirectory()) scan(file); else assets[path.relative(output, file)] = hash(fs.readFileSync(file));
    }
  };
  scan(output);
  const result = { tree, variant, terminal, source: root, input, output, node: process.version, inputFiles: files.length, assets, result: 'PASS' };
  fs.writeFileSync(path.join(root, 'tests/store-permission-r28/evidence/release', variant + '-' + terminal + '-build.json'), JSON.stringify(result, null, 2) + '\n');
  console.log(JSON.stringify({ tree, variant, terminal, output, assets: Object.fromEntries(Object.entries(assets).filter(([p]) => /(?:index|system)\.html$|assets\/index-/.test(p))), result: 'PASS' }));
};
if (terminal === 'cashier-v3') {
  // Vite emits a short-lived compiled config next to its immutable input file.
  // Permit that generated sibling only; every tracked input remains read-only.
  fs.chmodSync(input, 0o755);
  cp.execFileSync(process.execPath, [path.join(root, project, 'node_modules/vite/bin/vite.js'), 'build', '--outDir', output], { stdio: 'inherit' });
  fs.chmodSync(input, 0o555);
  for (const file of files) if (!fs.readFileSync(path.join(snapshot, file)).equals(git(['show', tree + ':' + file]))) throw Error('Post-build source drift: ' + file);
  finish();
} else {
  process.env.VUE_CLI_CONTEXT = input;
  const Service = require(path.join(root, project, 'node_modules/@vue/cli-service/lib/Service'));
  const service = new Service(input);
  service.init('production');
  service.projectOptions.parallel = false;
  service.webpackChainFns.push(config => {
    config.module.rules.values().forEach(rule => {
      for (const name of ['cache-loader', 'thread-loader']) if (rule.uses.has(name)) rule.uses.delete(name);
    });
    // Match the accepted R26/cube production compiler pipeline exactly.
    // Coupon pages are linted independently; legacy lint loaders alter chunk IDs.
    config.module.rules.delete('eslint');
    config.cache(false);
  });
  service.webpackRawConfigFns.push(config => {
    for (const plugin of [...(config.plugins || []), ...((config.optimization || {}).minimizer || [])]) {
      if (!plugin.options) continue;
      for (const key of ['parallel', 'cache']) if (key in plugin.options) plugin.options[key] = false;
    }
  });
  service.run('build', { mode: 'production', dest: output }).then(finish).catch(error => { console.error(error); process.exitCode = 1; });
}
