/**
 * Build one merchant mini-program from this round's fixed canonical Git tree.
 * Customer identity is a process-local manifest read overlay: canonical input
 * and generated assets are never patched, so one customer's build cannot alter
 * another customer's AppID/API or incorporate concurrent workspace drafts.
 * This control only builds and fingerprints; it never uploads or deploys.
 */
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const crypto = require('crypto');

const VERSION = '0.104.0';
const TARGETS = Object.freeze({
  rh: { appid: 'wx29d9b63c92555701', origin: 'https://rh.cc3798.com' },
  '008': { appid: 'wx14666197911d052b', origin: 'https://008.cc3798.com' },
  '010': { appid: 'wxd92c6d010bd6bf01', origin: 'https://010.cc3798.com' },
});
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

if (process.env.R12_MANAGEMENT_MINI_OVERLAY === '1') {
  const target = TARGETS[process.env.R12_MANAGEMENT_MINI_SITE];
  const input = process.env.UNI_INPUT_DIR;
  if (!target || !input || process.env.VITE_MOBILE_API_ORIGIN !== target.origin) {
    throw Error('Explicit matching merchant AppID/API identity required');
  }
  const manifestPath = path.join(input, 'manifest.json');
  const readSync = fs.readFileSync.bind(fs);
  const manifest = JSON.parse(readSync(manifestPath, 'utf8'));
  manifest['mp-weixin'].appid = target.appid;
  manifest.versionName = VERSION;
  manifest.versionCode = '104';
  const bytes = Buffer.from(JSON.stringify(manifest));
  const matches = file => typeof file === 'string' && path.resolve(file) === manifestPath;
  const converted = options => {
    const encoding = typeof options === 'string' ? options : options?.encoding;
    return encoding ? bytes.toString(encoding) : Buffer.from(bytes);
  };
  // Cover compiler sync, callback and promise reads without touching the frozen file.
  fs.readFileSync = (file, options) => matches(file) ? converted(options) : readSync(file, options);
  const read = fs.readFile.bind(fs);
  fs.readFile = (file, options, callback) => {
    if (typeof options === 'function') { callback = options; options = undefined; }
    return matches(file) ? process.nextTick(() => callback(null, converted(options))) : read(file, options, callback);
  };
  const promiseRead = fs.promises.readFile.bind(fs.promises);
  fs.promises.readFile = async (file, options) => matches(file) ? converted(options) : promiseRead(file, options);
} else {
  const repo = path.resolve(__dirname, '..');
  const site = process.argv[2];
  const target = TARGETS[site];
  if (!target || process.argv.length !== 3) throw Error('Usage: node scripts/r12-management-mini-build.cjs rh|008|010');
  const release = path.resolve(repo, '../_local_evidence/2026-11-06-r12-data-permissions/release');
  const candidatePath = path.join(release, 'precise-candidate.json');
  const candidateBytes = fs.readFileSync(candidatePath);
  const candidate = JSON.parse(candidateBytes);
  const tree = candidate.candidateTree;
  if (!/^[0-9a-f]{40}$/.test(tree || '')) throw Error('A fixed candidateTree is required');
  const git = args => cp.execFileSync('git', args, { cwd: repo, maxBuffer: 256e6 });
  if (git(['cat-file', '-t', tree]).toString().trim() !== 'tree') throw Error('candidateTree must be a Git tree');
  const controlPath = 'scripts/r12-management-mini-build.cjs';
  if (sha256(git(['show', tree + ':' + controlPath])) !== sha256(fs.readFileSync(__filename))) {
    throw Error('Build control is not part of the fixed candidate tree');
  }
  const mobilePath = '前端代码/mobile-vue3';
  const mobileTree = git(['rev-parse', tree + ':' + mobilePath]).toString().trim();
  // Tree and customer both participate in every path. Existing attempts remain immutable evidence.
  const evidence = path.join(release, 'mini', tree, site);
  const base = '/private/tmp/r12-management-mini-' + tree + '-' + site;
  if (fs.existsSync(base) || fs.existsSync(evidence)) throw Error('Preserve existing candidate; inspect it before any retry');
  const snapshot = path.join(base, 'readonly');
  const input = path.join(snapshot, mobilePath);
  const output = path.join(base, 'output');
  fs.mkdirSync(snapshot, { recursive: true });
  fs.mkdirSync(evidence, { recursive: true });
  cp.execFileSync('tar', ['-xf', '-', '-C', snapshot], { input: git(['archive', tree, mobilePath]) });
  const files = git(['ls-tree', '-rz', '--name-only', mobileTree]).toString().split('\0').filter(Boolean);
  const sourceHashes = Object.fromEntries(files.map(file => [file, sha256(fs.readFileSync(path.join(input, file)))]));
  const sourceFeatures = {
    warehouse: ['src/merchant/pages/warehouse/index.uvue', ['management_board', 'MerchantManagementBoard', 'summaryMetrics', 'rankingCatalog']],
    employee: ['src/merchant/pages/employee-performance/index.uvue', ['employee-personal-board', 'payload.tabs', 'payload.metrics']],
  };
  for (const [feature, [file, markers]] of Object.entries(sourceFeatures)) {
    const text = fs.readFileSync(path.join(input, file), 'utf8');
    if (markers.some(marker => !text.includes(marker))) throw Error('Fixed source is missing ' + feature);
  }
  const freeze = dir => {
    for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
      const file = path.join(dir, item.name);
      if (item.isDirectory()) freeze(file); else fs.chmodSync(file, 0o444);
    }
    fs.chmodSync(dir, 0o555);
  };
  freeze(snapshot);
  const plugins = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
  const node = path.join(plugins, 'node/node');
  const compiler = path.join(plugins, 'uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js');
  const buildLog = path.join(evidence, 'build.log');
  const control = {
    site, terminal: 'merchant', ...target, version: VERSION, tree, mobileTree, input, output,
    sourceHashes, candidateManifestSHA256: sha256(candidateBytes),
    buildControlSHA256: sha256(fs.readFileSync(__filename)), compilerEntrySHA256: sha256(fs.readFileSync(compiler)),
    nodeSHA256: sha256(fs.readFileSync(node)), nodeVersion: cp.execFileSync(node, ['--version']).toString().trim(),
    remote: 'not applicable; local build only', buildLog,
  };
  fs.writeFileSync(path.join(evidence, 'input.json'), JSON.stringify(control, null, 2) + '\n', { flag: 'wx' });
  const log = fs.openSync(buildLog, 'wx');
  try {
    cp.execFileSync(node, [compiler, 'build', '-p', 'mp-weixin'], {
      cwd: path.join(plugins, 'uniapp-cli-vite'),
      env: { ...process.env, NODE_ENV: 'production', UNI_INPUT_DIR: input, UNI_OUTPUT_DIR: output,
        UNI_HBUILDERX_PLUGINS: plugins, UNI_APP_X: 'true', UNI_UTS_PLATFORM: 'mp-weixin',
        VITE_MOBILE_API_ORIGIN: target.origin, R12_MANAGEMENT_MINI_OVERLAY: '1', R12_MANAGEMENT_MINI_SITE: site,
        NODE_OPTIONS: '--require ' + __filename },
      stdio: ['ignore', log, log], timeout: 300000,
    });
  } finally { fs.closeSync(log); }
  for (const [file, digest] of Object.entries(sourceHashes)) {
    if (sha256(fs.readFileSync(path.join(input, file))) !== digest) throw Error('Frozen source drift: ' + file);
  }
  if (sha256(fs.readFileSync(__filename)) !== control.buildControlSHA256 ||
      sha256(fs.readFileSync(compiler)) !== control.compilerEntrySHA256) throw Error('Build control/compiler drift');
  if (!fs.readFileSync(candidatePath).equals(candidateBytes)) throw Error('Candidate manifest changed during build');
  const project = JSON.parse(fs.readFileSync(path.join(output, 'project.config.json')));
  const app = JSON.parse(fs.readFileSync(path.join(output, 'app.json')));
  if (project.appid !== target.appid || project.compileType !== 'miniprogram' || !app.pages?.length) throw Error('Generated merchant identity/pages invalid');
  const generated = file => fs.readFileSync(path.join(output, file), 'utf8');
  const runtime = generated('src/shared/platform/mobile-runtime-config.js');
  if (!runtime.includes(target.origin) || Object.values(TARGETS).some(other => other.origin !== target.origin && runtime.includes(other.origin))) {
    throw Error('Generated API customer isolation failed');
  }
  // Compiled feature markers supplement source parity; they do not substitute for user/device acceptance.
  const featureChecks = {
    roster: generated('src/merchant/pages/reservations/index.js').includes('staff-availability-') &&
      generated('src/merchant/pages/reservations/index.js').includes('craftsmenOptions') &&
      generated('src/merchant/pages/reservation-confirmations/index.js').includes('craftsmenOptions'),
    managementBoard: generated('src/merchant/pages/warehouse/index.js').includes('management_board') &&
      generated('src/merchant/pages/warehouse/index.json').includes('merchant-management-board'),
    personalBoard: generated('src/merchant/pages/employee-performance/index.wxml').includes('employee-personal-board'),
    fixedMetricColumn: generated('src/shared/components/merchant-management-board.wxss').includes('position:sticky'),
  };
  if (Object.values(featureChecks).some(value => !value)) throw Error('Compiled roster/management feature missing: ' + JSON.stringify(featureChecks));
  const assets = {};
  const scan = dir => {
    for (const item of fs.readdirSync(dir, { withFileTypes: true })) {
      const file = path.join(dir, item.name);
      if (item.isDirectory()) scan(file); else assets[path.relative(output, file)] = sha256(fs.readFileSync(file));
    }
  };
  scan(output);
  const result = { ...control, result: 'PASS', sourceStable: true, pages: app.pages.length, featureChecks, assets };
  fs.writeFileSync(path.join(evidence, 'build.json'), JSON.stringify(result, null, 2) + '\n', { flag: 'wx' });
  console.log(JSON.stringify({ result: 'PASS', site, version: VERSION, tree, appid: target.appid, origin: target.origin,
    pages: result.pages, assets: Object.keys(assets).length, featureChecks, evidence }));
}
