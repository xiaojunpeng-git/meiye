/* Local compilation proof from the only editable member source. No archive, customer
 * overlay, publication or checkout is created. Output is disposable generated assets;
 * source fingerprints are compared after compilation to detect concurrent changes. */
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '../..');
const platform = process.argv[2];
if (!['h5', 'mp-weixin'].includes(platform)) throw Error('Expected h5 or mp-weixin');
const cli = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli';
const input = path.join(root, '前端代码/uniapp');
const base = fs.mkdtempSync('/private/tmp/r44-member-' + platform + '-');
const output = path.join(base, 'output');
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const files = [...new Set(cp.execFileSync('git', ['ls-files', '-c', '-o', '--exclude-standard', '-z', '--', '前端代码/uniapp', '前端代码/shared', 'scripts/build/member-mini-css-dedupe.cjs'], { cwd: root }).toString().split('\0').filter(Boolean)
  .concat(['前端代码/uniapp/config/app.js', '前端代码/uniapp/project.config.json']))].filter(file => fs.existsSync(path.join(root, file)) && fs.statSync(path.join(root, file)).isFile()).sort();
const fingerprints = () => Object.fromEntries(files.map(file => [file, sha(fs.readFileSync(path.join(root, file)))]));
const before = fingerprints();
fs.writeFileSync(path.join(base, 'input.json'), JSON.stringify({ root, input, output, platform, head: cp.execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root }).toString().trim(), files: before }, null, 2) + '\n');
console.log('R44_LOCAL_BUILD ' + JSON.stringify({ base, input, platform }));
// H5 tree shaking intentionally skips its first emit and exits from the compiler's
// second done hook. Check the final entry and original source even on that exit path.
process.once('exit', code => {
  if (code !== 0) return;
  const entry = path.join(output, platform === 'h5' ? 'index.html' : 'app.json');
  const after = fingerprints();
  const drift = files.filter(file => before[file] !== after[file]);
  const ok = fs.existsSync(entry) && drift.length === 0;
  fs.writeFileSync(path.join(base, 'complete.json'), JSON.stringify({ result: ok ? 'PASS' : 'FAILED', entry,
    entrySha256: fs.existsSync(entry) ? sha(fs.readFileSync(entry)) : null, drift, after }, null, 2) + '\n');
  if (!ok) { console.error('Final output or source consistency check failed'); process.exitCode = 1; }
  else console.log('R44_LOCAL_BUILD_PASS ' + base);
});
Object.assign(process.env, { NODE_ENV: 'production', UNI_PLATFORM: platform, UNI_INPUT_DIR: input, UNI_OUTPUT_DIR: output,
  UNI_MINIMIZE: 'true', BROWSERSLIST_IGNORE_OLD_DATA: 'true', VUE_CLI_CONTEXT: cli,
  UNI_HBUILDERX_PLUGINS: path.dirname(cli), HBUILDER_EXTENSIONS_DIR: path.dirname(cli) });
const Service = require(path.join(cli, 'node_modules/@vue/cli-service/lib/Service'));
const originalInit = Service.prototype.init, originalRun = Service.prototype.run;
Service.prototype.init = function (...args) {
  const result = originalInit.apply(this, args);
  this.webpackChainFns.push(config => {
    config.resolve.modules.add(path.join(cli, 'node_modules'));
    config.resolveLoader.modules.add(path.join(cli, 'node_modules'));
    config.module.rules.values().forEach(rule => {
      for (const name of ['cache-loader', 'thread-loader']) if (rule.uses.has(name)) rule.uses.delete(name);
    });
    config.cache(false);
  });
  this.webpackRawConfigFns.push(config => {
    for (const optimizer of [...(config.optimization.minimizer || []), ...(config.plugins || [])]) {
      if (!optimizer.options) continue;
      if ('cache' in optimizer.options) optimizer.options.cache = false;
      if ('parallel' in optimizer.options) optimizer.options.parallel = false;
    }
    // The HBuilderX Vue2 mini loader mutates Babel options per entry. Keep options
    // local to each module without worker/cache reuse so components stay components.
    if (platform === 'mp-weixin') config.plugins.push({ apply(compiler) {
      compiler.hooks.compilation.tap('R44MemberLoaderIsolation', compilation => {
        compilation.hooks.normalModuleLoader.tap('R44MemberLoaderIsolation', (context, module) => {
          for (const loader of module.loaders || []) if (loader.loader.includes('babel-loader') && loader.options) {
            loader.options = { ...loader.options, plugins: (loader.options.plugins || []).map(p => Array.isArray(p) ? [...p] : p) };
          }
        });
      });
    } });
  });
  return result;
};
Service.prototype.run = function (command, ...args) {
  const result = originalRun.call(this, command, ...args);
  if (command !== 'uni-build') return result;
  return Promise.resolve(result).then(value => {
    const entry = path.join(output, platform === 'h5' ? 'index.html' : 'app.json');
    if (!fs.existsSync(entry)) {
      if (platform === 'h5' && process.env.UNI_OPT_TREESHAKINGNG) return value;
      throw Error('Compiler did not produce entry');
    }
    // HBuilderX's IDE notification socket may outlive the completed webpack build.
    process.exit(0);
    return value;
  });
};
process.chdir(cli);
process.argv = [process.argv[0], path.join(cli, 'bin/uniapp-cli.js')];
require(path.join(cli, 'bin/uniapp-cli.js'));
