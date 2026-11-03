/* Fixed Git export only. Customer origin/AppID are generated build parameters,
 * never a second editable member implementation. Every canonical input is hashed
 * before and after compilation; a compiler exit alone is not a successful build. */
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '../../..');
const evidence = path.join(root, '../任务管理/进行中/第44轮-拼团大屏与推广/发布核对');
const plan = JSON.parse(fs.readFileSync(path.join(evidence, 'trees.json')));
const [variant, target, platform] = process.argv.slice(2);
const ids = { '008': 'wx85d4bfd0b4b6aafb', '010': 'wxce4f61fc9c4eaaa6' };
if (!['baseline', 'candidate'].includes(variant) || !['rh', '008', '010'].includes(target) || !['h5', 'mp-weixin'].includes(platform) || platform === 'mp-weixin' && !ids[target]) throw Error('Explicit variant, customer and supported platform required');
const tree = plan[variant + 'Tree'], sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const git = args => cp.execFileSync('git', args, { cwd: root, maxBuffer: 512e6 });
const base = '/private/tmp/r44-member-release-' + tree.slice(0, 12) + '-' + target + '-' + platform;
const snapshot = path.join(base, 'readonly'), input = path.join(snapshot, '前端代码/uniapp'), output = path.join(base, 'output');
const cli = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli';
const packages = ['前端代码/uniapp', '前端代码/shared', 'scripts/build/member-mini-css-dedupe.cjs'];
if (fs.existsSync(base)) throw Error('Retaining existing fixed output; choose a new candidate instead of overwriting');
fs.mkdirSync(snapshot, { recursive: true });
cp.execFileSync('tar', ['-xf', '-', '-C', snapshot], { input: git(['archive', tree, ...packages]) });
const files = git(['ls-tree', '-rz', '--name-only', tree, '--', ...packages]).toString().split('\0').filter(Boolean);
for (const file of files) if (!fs.readFileSync(path.join(snapshot, file)).equals(git(['show', tree + ':' + file]))) throw Error('Git export differs: ' + file);
const origin = 'https://' + target + '.cc3798.com';
// app.js is ignored source configuration. Supply the canonical customer template
// and a per-process origin without replacing tracked customer configuration.
fs.writeFileSync(path.join(input, 'config/app.js'), git(['show', tree + ':前端代码/uniapp/config/app.mp.template.js']));
const originalRead = fs.readFileSync.bind(fs), originalAsync = fs.readFile.bind(fs), originalPromise = fs.promises.readFile.bind(fs.promises);
const customer = path.join(input, 'config/member-customer.json'), body = Buffer.from(JSON.stringify({ apiOrigin: origin }));
const convert = option => (typeof option === 'string' || option && option.encoding) ? body.toString(typeof option === 'string' ? option : option.encoding) : Buffer.from(body);
const isCustomer = file => typeof file === 'string' && path.resolve(file) === customer;
fs.readFileSync = (file, option) => isCustomer(file) ? convert(option) : originalRead(file, option);
fs.readFile = (file, option, callback) => { if (typeof option === 'function') { callback = option; option = undefined; } if (isCustomer(file)) return process.nextTick(() => callback(null, convert(option))); return originalAsync(file, option, callback); };
fs.promises.readFile = (file, option) => isCustomer(file) ? Promise.resolve(convert(option)) : originalPromise(file, option);
// AppID/project configuration are explicit generated overlays, separately recorded.
const overlays = ['前端代码/uniapp/config/app.js'];
if (platform === 'mp-weixin') {
  const manifest = path.join(input, 'manifest.json');
  const parser = require(path.join(cli, 'node_modules/@dcloudio/uni-cli-shared/lib/json.js'));
  const parsed = parser.parseJson(originalRead(manifest, 'utf8')); parsed['mp-weixin'].appid = ids[target];
  fs.writeFileSync(manifest, JSON.stringify(parsed, null, 2));
  fs.writeFileSync(path.join(input, 'project.config.json'), JSON.stringify({ appid: ids[target], compileType: 'miniprogram', setting: parsed['mp-weixin'].setting, packOptions: parsed['mp-weixin'].packOptions }));
  overlays.push('前端代码/uniapp/manifest.json', '前端代码/uniapp/project.config.json');
}
const inputs = [...new Set(files.concat(overlays))].sort();
const fingerprints = () => Object.fromEntries(inputs.map(file => [file, sha(originalRead(path.join(snapshot, file)))]));
const before = fingerprints();
fs.symlinkSync(path.join(cli, 'node_modules'), path.join(input, 'node_modules'));
const freeze = dir => { for (const item of fs.readdirSync(dir, { withFileTypes: true })) { const file = path.join(dir, item.name); if (item.isSymbolicLink()) continue; if (item.isDirectory()) freeze(file); else fs.chmodSync(file, 0o444); } fs.chmodSync(dir, 0o555); };
freeze(snapshot);
fs.writeFileSync(path.join(base, 'input.json'), JSON.stringify({ canonicalGit: root, tree, variant, target, platform, origin, appid: ids[target] || null, input, output, overlays, files: before }, null, 2));
process.once('exit', code => {
  if (code !== 0) return;
  const after = fingerprints(), drift = inputs.filter(file => before[file] !== after[file]);
  const entry = path.join(output, platform === 'h5' ? 'index.html' : 'app.json');
  const assets = {};
  function scan(dir) { for (const item of fs.readdirSync(dir, { withFileTypes: true })) { const file = path.join(dir, item.name); if (item.isDirectory()) scan(file); else if (item.isFile()) assets[path.relative(output, file)] = sha(originalRead(file)); else throw Error('Unexpected generated symlink'); } }
  if (fs.existsSync(entry)) scan(output);
  const ok = fs.existsSync(entry) && !drift.length;
  const record = { result: ok ? 'PASS' : 'FAIL', tree, variant, target, platform, origin, appid: ids[target] || null, input, output, drift, sourceStable: !drift.length, assets };
  fs.writeFileSync(path.join(evidence, tree.slice(0, 12) + '-' + target + '-' + platform + '-build.json'), JSON.stringify(record, null, 2));
  if (!ok) process.exitCode = 1;
  console.log('R44_MEMBER_BUILD ' + JSON.stringify({ result: record.result, tree, target, platform, output }));
});
Object.assign(process.env, { NODE_ENV: 'production', UNI_PLATFORM: platform, UNI_INPUT_DIR: input, UNI_OUTPUT_DIR: output, UNI_MINIMIZE: 'true', BROWSERSLIST_IGNORE_OLD_DATA: 'true', VUE_CLI_CONTEXT: cli, UNI_HBUILDERX_PLUGINS: path.dirname(cli), HBUILDER_EXTENSIONS_DIR: path.dirname(cli) });
const Service = require(path.join(cli, 'node_modules/@vue/cli-service/lib/Service')), init = Service.prototype.init, run = Service.prototype.run;
Service.prototype.init = function (...args) {
  const result = init.apply(this, args);
  this.webpackChainFns.push(config => { config.resolve.modules.add(path.join(cli, 'node_modules')); config.resolveLoader.modules.add(path.join(cli, 'node_modules')); config.module.rules.values().forEach(rule => { for (const name of ['cache-loader', 'thread-loader']) if (rule.uses.has(name)) rule.uses.delete(name); }); config.cache(false); });
  this.webpackRawConfigFns.push(config => {
    for (const item of [...(config.optimization.minimizer || []), ...(config.plugins || [])]) if (item.options) { if ('cache' in item.options) item.options.cache = false; if ('parallel' in item.options) item.options.parallel = false; }
    if (platform === 'mp-weixin') config.plugins.push({ apply(compiler) { compiler.hooks.compilation.tap('R44FixedMember', compilation => { compilation.hooks.normalModuleLoader.tap('R44FixedMember', (context, module) => { for (const loader of module.loaders || []) if (loader.loader.includes('babel-loader') && loader.options) loader.options = { ...loader.options, plugins: (loader.options.plugins || []).map(p => Array.isArray(p) ? [...p] : p) }; }); }); } });
  }); return result;
};
Service.prototype.run = function (command, ...args) { const result = run.call(this, command, ...args); if (command !== 'uni-build') return result; return Promise.resolve(result).then(value => { if (!fs.existsSync(path.join(output, platform === 'h5' ? 'index.html' : 'app.json'))) { if (platform === 'h5' && process.env.UNI_OPT_TREESHAKINGNG) return value; throw Error('No final compiler output'); } process.exit(0); }); };
process.chdir(cli); process.argv = [process.argv[0], path.join(cli, 'bin/uniapp-cli.js')]; require(path.join(cli, 'bin/uniapp-cli.js'));
