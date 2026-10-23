const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const crypto = require('node:crypto');

// An immutable canonical export is a build input, never another editable source.
const root = process.cwd();
const evidence = path.join(root, 'tests/cashier-v3/evidence/R49-release');
const { candidateTree: tree } = JSON.parse(fs.readFileSync(path.join(evidence, 'trees.json')));
const base = '/private/tmp/mohe-r49-fixed-' + tree.slice(0, 12);
const input = path.join(base, 'readonly-complete');
const output = path.join(base, 'cashier-dist');
const git = args => cp.execFileSync('git', args, { maxBuffer: 128 * 1024 * 1024 });
const hash = v => crypto.createHash('sha256').update(v).digest('hex');
const packages = ['前端代码/cashier-v3', '前端代码/shared', '前端代码/inventory-vue3', 'tests/cashier-v3'];
if (!fs.existsSync(input)) {
  fs.mkdirSync(input, { recursive: true });
  cp.execFileSync('tar', ['-xf', '-', '-C', input], { input: git(['archive', tree, ...packages]) });
  fs.symlinkSync(path.join(root, '前端代码/cashier-v3/node_modules'), path.join(input, '前端代码/cashier-v3/node_modules'));
  fs.symlinkSync(path.join(root, '前端代码/inventory-vue3/node_modules'), path.join(input, '前端代码/inventory-vue3/node_modules'));
  const freeze = directory => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, entry.name);
      if (entry.isSymbolicLink()) continue;
      if (entry.isDirectory()) freeze(file); else fs.chmodSync(file, 0o444);
    }
    fs.chmodSync(directory, 0o555);
  };
  freeze(input);
}
const files = git(['ls-tree', '-rz', '--name-only', tree, '--', ...packages]).toString().split('\0').filter(Boolean);
const check = () => {
  for (const file of files) if (!fs.readFileSync(path.join(input, file)).equals(git(['show', tree + ':' + file]))) throw Error('Source changed: ' + file);
};
check();
const project = path.join(input, '前端代码/cashier-v3');
// Vite may emit its temporary compiled config sibling, but no tracked source is writable.
fs.chmodSync(project, 0o755);
try {
  cp.execFileSync(process.execPath, [path.join(root, '前端代码/cashier-v3/node_modules/vite/bin/vite.js'), 'build', '--outDir', output],
    { cwd: project, stdio: 'inherit', env: { ...process.env, NODE_ENV: 'production' } });
} finally { fs.chmodSync(project, 0o555); }
check();
const assets = {};
const scan = directory => {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) scan(file); else assets[path.relative(output, file)] = hash(fs.readFileSync(file));
  }
};
scan(output);
fs.writeFileSync(path.join(evidence, 'candidate-cashier-build.json'), JSON.stringify({ tree, input, output, assets,
  source: root, inputFiles: files.length, result: 'PASS' }, null, 2) + '\n');
console.log(JSON.stringify({ tree, output, assets, result: 'PASS' }));
