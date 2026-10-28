const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');

// Usage: Node16 scripts/r51-basic-report-build.cjs baseline|candidate admin
//        Node24 scripts/r51-basic-report-build.cjs baseline|candidate cashier-v3
// Fixed Git exports are immutable build inputs, never another editable source tree.
const root = fs.realpathSync(path.resolve(__dirname, '..'));
const base = '/private/tmp/mohe-r38-followup-release';
const release = path.join(root, 'tests/inventory/evidence/R38-followup-statistics/release');
const treesFile = path.join(release, 'trees.json');
const packages = ['前端代码/admin', '前端代码/cashier-v3', '前端代码/shared', '前端代码/inventory-vue3'];
const [variant, terminal, ...extra] = process.argv.slice(2);
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const exists = file => fs.existsSync(file) || Boolean(fs.lstatSync(file, { throwIfNoEntry: false }));
const git = args => cp.execFileSync('git', args, { cwd: root, maxBuffer: 512 * 1024 * 1024 });

// Resolve only full, fixed trees in the canonical repository; do not follow a branch,
// a server download, or a dirty working copy when preparing either comparison build.
function loadPlan() {
  if (extra.length || !['baseline', 'candidate'].includes(variant) || !['cashier-v3', 'inventory-vue3'].includes(terminal)) {
    throw Error('Expected baseline|candidate and cashier-v3|inventory-vue3');
  }
  const major = Number(process.versions.node.split('.')[0]);
  if (major !== (terminal === 'admin' ? 16 : 24)) throw Error(`${terminal} requires Node${terminal === 'admin' ? 16 : 24}`);
  if (git(['rev-parse', '--show-toplevel']).toString().trim() !== root) throw Error('Script must belong to canonical Git root');
  const bytes = fs.readFileSync(treesFile);
  const trees = JSON.parse(bytes);
  if (trees.canonicalGit && fs.realpathSync(trees.canonicalGit) !== root) throw Error('trees.json canonicalGit does not match canonical source');
  const tree = trees[variant + 'Tree'];
  if (typeof tree !== 'string' || !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(tree)) throw Error('Missing fixed ' + variant + 'Tree');
  if (git(['cat-file', '-t', tree]).toString().trim() !== 'tree') throw Error('Expected a Git tree object');
  for (const name of packages) {
    if (git(['cat-file', '-t', tree + ':' + name]).toString().trim() !== 'tree') throw Error('Missing input package: ' + name);
  }
  const files = git(['ls-tree', '-rz', '--full-tree', tree, '--', ...packages]).toString().split('\0').filter(Boolean).map(line => {
    const match = /^(100644|100755) blob ([a-f0-9]+)\t(.+)$/s.exec(line);
    if (!match) throw Error('Only regular Git source blobs are allowed: ' + line);
    const file = match[3];
    if (file.split('/').some(part => ['node_modules', 'dist', 'unpackage', '.git'].includes(part))) {
      throw Error('Generated output/dependencies cannot be source inputs: ' + file);
    }
    return { path: file, mode: match[1], blob: match[2] };
  });
  return { tree, files, treesSHA256: hash(bytes), gitObjectFormat: git(['rev-parse', '--show-object-format']).toString().trim() };
}

// chmod never follows dependency links into the working checkout. All source files
// remain read-only, including while Vite briefly creates its generated config sibling.
function freeze(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) continue;
    if (entry.isDirectory()) freeze(file);
    else fs.chmodSync(file, 0o444);
  }
  fs.chmodSync(directory, 0o555);
}

function dependencies() {
  return Object.fromEntries(['admin', 'cashier-v3', 'inventory-vue3'].map(name => {
    const target = path.join(root, '前端代码', name, 'node_modules');
    if (!fs.statSync(target).isDirectory()) throw Error('Missing installed dependencies: ' + name);
    return ['前端代码/' + name + '/node_modules', target];
  }));
}

// Verify every exported byte against its Git object and saved SHA-256; also reject
// extra source files and foreign symlinks so stale files cannot enter module resolution.
function verifySnapshot(snapshot, plan, links, expectedSHA) {
  const allowed = new Set(plan.files.map(file => file.path));
  allowed.add('.r51-build-input.json');
  const visit = directory => {
    const stat = fs.lstatSync(directory);
    if (!stat.isDirectory() || (stat.mode & 0o222)) throw Error('Snapshot directory is not read-only: ' + directory);
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, entry.name);
      const relative = path.relative(snapshot, file).split(path.sep).join('/');
      if (Object.hasOwn(links, relative)) {
        if (!entry.isSymbolicLink() || fs.readlinkSync(file) !== links[relative]) throw Error('Dependency link drift: ' + relative);
      } else if (entry.isDirectory()) visit(file);
      else if (!entry.isFile() || !allowed.has(relative) || (fs.lstatSync(file).mode & 0o222)) {
        throw Error('Unexpected or writable snapshot input: ' + relative);
      }
    }
  };
  visit(snapshot);
  for (const [relative, target] of Object.entries(links)) {
    if (!fs.lstatSync(path.join(snapshot, relative)).isSymbolicLink() || fs.readlinkSync(path.join(snapshot, relative)) !== target) throw Error('Missing dependency link: ' + relative);
  }
  const sourceSHA256 = {};
  for (const file of plan.files) {
    const target = path.join(snapshot, file.path);
    if (!fs.lstatSync(target).isFile()) throw Error('Missing regular source input: ' + file.path);
    const bytes = fs.readFileSync(target);
    const blob = crypto.createHash(plan.gitObjectFormat).update(`blob ${bytes.length}\0`).update(bytes).digest('hex');
    if (blob !== file.blob) throw Error('Git source drift: ' + file.path);
    sourceSHA256[file.path] = hash(bytes);
    if (expectedSHA && sourceSHA256[file.path] !== expectedSHA[file.path]) throw Error('Source SHA drift: ' + file.path);
  }
  if (expectedSHA && Object.keys(expectedSHA).length !== plan.files.length) throw Error('Snapshot file manifest mismatch');
  return sourceSHA256;
}

// A conflicting or incomplete snapshot is never repaired/overwritten. A new tree
// requires the main release task to explicitly choose a new fixed build preparation.
function prepareSnapshot(snapshot, plan, links) {
  const manifestPath = path.join(snapshot, '.r51-build-input.json');
  const identity = { canonicalGit: root, tree: plan.tree, packages, files: plan.files };
  if (exists(snapshot)) {
    if (!fs.lstatSync(snapshot).isDirectory()) throw Error('Snapshot is not a real directory');
    const saved = JSON.parse(fs.readFileSync(manifestPath));
    if (JSON.stringify(saved.identity) !== JSON.stringify(identity)) throw Error('Existing readonly snapshot belongs to different inputs; refusing overwrite');
    return verifySnapshot(snapshot, plan, links, saved.sourceSHA256);
  }
  fs.mkdirSync(snapshot);
  try {
    cp.execFileSync('tar', ['-xf', '-', '-C', snapshot], { input: git(['archive', plan.tree, ...packages]), maxBuffer: 512 * 1024 * 1024 });
    for (const [relative, target] of Object.entries(links)) fs.symlinkSync(target, path.join(snapshot, relative));
    freeze(snapshot);
    const sourceSHA256 = verifySnapshot(snapshot, plan, links);
    // Only the manifest directory is opened, after every exported source blob passed verification.
    fs.chmodSync(snapshot, 0o755);
    fs.writeFileSync(manifestPath, JSON.stringify({ identity, sourceSHA256 }, null, 2) + '\n', { flag: 'wx', mode: 0o444 });
    return sourceSHA256;
  } finally {
    freeze(snapshot);
  }
}

// Keep the accepted R28 Node16 production pipeline: production transforms and
// minification stay enabled, while cache/thread/eslint loaders and workers are disabled.
async function buildAdmin(input, output) {
  process.env.VUE_CLI_CONTEXT = input;
  const Service = require(path.join(root, '前端代码/admin/node_modules/@vue/cli-service/lib/Service'));
  const service = new Service(input);
  service.init('production');
  service.projectOptions.parallel = false;
  service.webpackChainFns.push(config => {
    const disableLoaders = rule => {
      for (const name of ['cache-loader', 'thread-loader', 'eslint-loader']) if (rule.uses.has(name)) rule.uses.delete(name);
      for (const nested of rule.oneOfs.values()) disableLoaders(nested);
    };
    config.module.rules.values().forEach(disableLoaders);
    config.module.rules.delete('eslint');
    config.cache(false);
  });
  service.webpackRawConfigFns.push(config => {
    config.cache = false;
    for (const plugin of [...(config.plugins || []), ...((config.optimization || {}).minimizer || [])]) {
      if (!plugin.options) continue;
      for (const key of ['parallel', 'cache']) if (key in plugin.options) plugin.options[key] = false;
    }
  });
  await service.run('build', { mode: 'production', dest: output });
}

// Asset records describe generated outputs only. Refuse symlinks and empty/missing
// entrypoints instead of treating a successful process exit as a complete package.
function outputAssets(output) {
  const assets = {};
  const scan = directory => {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const file = path.join(directory, entry.name);
      if (entry.isDirectory()) scan(file);
      else if (entry.isFile()) assets[path.relative(output, file).split(path.sep).join('/')] = hash(fs.readFileSync(file));
      else throw Error('Unexpected generated output type: ' + file);
    }
  };
  if (!fs.lstatSync(output).isDirectory()) throw Error('Missing generated output');
  scan(output);
  const entry = terminal === 'admin' ? 'system.html' : 'index.html';
  if (!assets[entry] || !fs.statSync(path.join(output, entry)).size) throw Error('Missing built entry: ' + entry);
  return assets;
}

async function main() {
  const plan = loadPlan();
  const snapshot = path.join(base, variant + '-readonly');
  const input = path.join(snapshot, '前端代码', terminal);
  const output = path.join(base, variant + '-' + terminal + '-dist');
  const record = path.join(release, variant + '-' + terminal + '-build.json');
  const links = dependencies();
  fs.mkdirSync(base, { recursive: true });
  if (fs.realpathSync(base) !== base) throw Error('Release build directory must not be a symlink');
  const lock = path.join(base, variant + '-build.lock');
  // Variant lock also serializes admin/cashier export verification and Vite's temporary config.
  fs.mkdirSync(lock);
  let sourceSHA256Before;
  let sourceSHA256After;
  let failure;
  let started = false;
  const startedAt = new Date().toISOString();
  try {
    if (exists(output) || exists(record)) throw Error('Existing output/build record is retained; refusing overwrite');
    sourceSHA256Before = prepareSnapshot(snapshot, plan, links);
    if (hash(fs.readFileSync(treesFile)) !== plan.treesSHA256) throw Error('trees.json changed during preparation');
    process.env.NODE_ENV = 'production';
    process.env.ANALYZE = 'false';
    process.env.BROWSERSLIST_IGNORE_OLD_DATA = 'true';
    process.env.BABEL_DISABLE_CACHE = '1';
    process.chdir(input);
    started = true;
    if (terminal === 'admin') await buildAdmin(input, output);
    else {
      // Vite writes/deletes a transient compiled config beside vite.config.js.
      // Only the directory is writable temporarily; every tracked file stays 0444.
      fs.chmodSync(input, 0o755);
      try {
        cp.execFileSync(process.execPath, [path.join(root, '前端代码', terminal, 'node_modules/vite/bin/vite.js'), 'build', '--outDir', output], { stdio: 'inherit' });
      } finally { fs.chmodSync(input, 0o555); }
    }
  } catch (error) {
    failure = error;
  } finally {
    // Even failed compilers must prove the source bytes stayed stable before a
    // result is recorded; a changed tree/manifest cannot inherit an earlier PASS.
    try {
      if (sourceSHA256Before) sourceSHA256After = verifySnapshot(snapshot, plan, links, sourceSHA256Before);
      if (hash(fs.readFileSync(treesFile)) !== plan.treesSHA256) throw Error('trees.json changed during build; refix inputs before reuse');
    } catch (error) { failure = error; }
    try {
      if (started) {
        let assets = {};
        try { if (!failure) assets = outputAssets(output); } catch (error) { failure = error; }
        const result = {
          tree: plan.tree, variant, terminal, canonicalGit: root, source: root, input, output,
          node: process.version, nodeExecutable: process.execPath, startedAt, finishedAt: new Date().toISOString(),
          scriptSHA256: hash(fs.readFileSync(__filename)), treesSHA256: plan.treesSHA256,
          inputFiles: plan.files.length, sourceSHA256Before, sourceSHA256After,
          sourceStable: Boolean(sourceSHA256After), assets,
          pipeline: terminal === 'admin' ? 'node16-no-cache-no-thread-no-eslint-loader-no-parallel' : 'node24-vite',
          result: failure ? 'FAIL' : 'PASS', ...(failure ? { error: failure.message } : {})
        };
        fs.writeFileSync(record, JSON.stringify(result, null, 2) + '\n', { flag: 'wx' });
        console.log(JSON.stringify({ tree: plan.tree, variant, terminal, output, record, result: result.result }));
      }
    } finally { fs.rmdirSync(lock); }
  }
  if (failure) throw failure;
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
