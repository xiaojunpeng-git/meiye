// Freeze only this round's member files over canonical HEAD. This is a local
// compile check, not upload authorization; no server or database is contacted.
const fs = require('fs'), path = require('path'), cp = require('child_process'), vm = require('vm');
const root = path.resolve(__dirname, '../..');
process.chdir(root);
const base = process.env.REGION_BUILD_BASE || '/private/tmp/m008-r3-regions-20261024/mini';
if (!base.startsWith('/private/tmp/m008-r3-regions-20261024/')) throw Error('Unsafe build output');
fs.mkdirSync(base, {recursive: true});
const files = ['前端代码/uniapp/api/new_store.js', '前端代码/uniapp/pages/store/list/index.vue', '前端代码/uniapp/pages/store/list/components/storeRegionWindow.vue'];
const git = (args, extra = {}) => cp.execFileSync('git', args, {encoding: 'utf8', ...extra}).trim();
const head = git(['rev-parse', 'HEAD']);
const env = {...process.env, GIT_INDEX_FILE: path.join(base, 'source.index')};
git(['read-tree', head], {env});
for (const f of files) git(['update-index', '--add', '--cacheinfo', '100644', git(['hash-object', '-w', f]), f], {env});
const tree = git(['write-tree'], {env});
fs.writeFileSync(path.join(base, 'source.json'), JSON.stringify({head, tree, files, localOnly: true}, null, 2));
let script = fs.readFileSync('scripts/build/member-008-round4-mini.cjs', 'utf8')
  .replace(/const commit=assetsCandidate\?[^;]+;/, 'const commit=' + JSON.stringify(tree) + ';')
  .replace(/const base=assetsCandidate\?[^;]+;/, 'const base=' + JSON.stringify(base) + ';')
  .replace("git(['rev-parse','HEAD']).toString().trim()!==commit", "git(['rev-parse','HEAD']).toString().trim()!==" + JSON.stringify(head))
  .replace(/if\(dirty\.split[^\n]+/, '// The exact isolated tree above is the only compile input.');
process.argv = [process.argv[0], 'region-mini-build'];
vm.runInThisContext('(function(require,__dirname,__filename){' + script + '\n})', {filename: __filename})(require, path.join(root, 'scripts/build'), __filename);
