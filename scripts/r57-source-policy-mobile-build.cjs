// H5 only: build immutable canonical inputs with installed HBuilderX. This
// command neither uploads mini programs nor changes customer API provisioning.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const e = 'tests/cashier-v3/evidence/R57-release';
const variant = process.argv[2];
if (!['baseline', 'candidate', 'liveComparison'].includes(variant)) throw Error('baseline|candidate|liveComparison required');
// The deployed H5 predates the separately accepted mini-program login label.
// Reproduce its original canonical tree for comparison, retaining that newer
// accepted label in the unified candidate rather than reverting source text.
const tree = variant === 'liveComparison' ? 'bfd7ab926d62a7f024fbf1d1ddd190a97f8b646b' : JSON.parse(fs.readFileSync(e + '/trees.json'))[variant + 'Tree'];
const base = '/private/tmp/mohe-r57-mobile-' + tree.slice(0, 12);
const input = path.join(base, '前端代码/mobile-vue3'), output = path.join(base, 'output');
const git = args => cp.execFileSync('git', args, { maxBuffer: 128 * 1024 * 1024 });
if (fs.existsSync(base)) throw Error('Existing fixed build retained');
fs.mkdirSync(base);
cp.execFileSync('tar', ['-xf', '-', '-C', base], { input: git(['archive', tree, '前端代码/mobile-vue3']) });
const files = git(['ls-tree', '-rz', tree, '--', '前端代码/mobile-vue3']).toString().split('\0').filter(Boolean).map(line => {
  const [, blob, file] = /^\d+ blob ([a-f0-9]+)\t(.+)$/s.exec(line); return { blob, file };
});
function verify() {
  for (const { file, blob } of files) {
    const b = fs.readFileSync(path.join(base, file));
    if (crypto.createHash('sha1').update(`blob ${b.length}\0`).update(b).digest('hex') !== blob) throw Error('Source drift: ' + file);
  }
}
for (const { file } of files) fs.chmodSync(path.join(base, file), 0o444);
verify();
const plugins = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins';
cp.execFileSync(path.join(plugins, 'node/node'), [path.join(plugins, 'uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js'), 'build', '-p', 'h5', '--base', '/view_mobile_v3/'], {
  cwd: path.join(plugins, 'uniapp-cli-vite'), env: { ...process.env, NODE_ENV: 'production', UNI_INPUT_DIR: input,
    UNI_OUTPUT_DIR: output, UNI_HBUILDERX_PLUGINS: plugins, UNI_APP_X: 'true', UNI_UTS_PLATFORM: 'web' }, stdio: 'inherit'
});
verify();
const assets = {};
function scan(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) scan(file);
    else if (entry.isFile()) assets[path.relative(output, file)] = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
    else throw Error('Unexpected output');
  }
}
scan(output);
if (!assets['index.html']) throw Error('Missing H5 entry');
fs.writeFileSync(e + '/' + variant + '-mobile-build.json', JSON.stringify({ tree, input, output, assets,
  result: 'PASS', sourceStable: true, miniProgramUpload: false }, null, 2) + '\n', { flag: 'wx' });
console.log('R57_MOBILE_BUILD_PASS', tree, Object.keys(assets).length);
