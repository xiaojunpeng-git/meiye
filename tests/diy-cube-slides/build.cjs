// Build an immutable tree containing only the reviewed cube files, never unrelated dirty work.
const fs = require('fs'), path = require('path'), cp = require('child_process'), vm = require('vm');
const root = path.resolve(__dirname, '../..');
const files = [
  '前端代码/shared/diyCubeSlides.js',
  '前端代码/admin/src/components/mobileConfig/c_picture_cube.vue',
  '前端代码/admin/src/components/mobileConfigRight/c_cube_slides.vue',
  '前端代码/admin/src/components/mobileConfigRight/c_pictrue.vue',
  '前端代码/admin/src/components/mobileConfigRight/c_menu_list.vue',
  '前端代码/admin/src/components/mobilePage/cubePreviewImage.vue',
  '前端代码/admin/src/components/mobilePage/picture_cube.vue',
  '前端代码/uniapp/pages/index/components/cubeRegionImage.vue',
  '前端代码/uniapp/pages/index/components/pictureCube.vue'
];
const target = process.argv[2];
if (!['admin', 'member', 'mini'].includes(target)) throw Error('Explicit build target required');
process.chdir(root);
const base = process.env.CUBE_BUILD_BASE || '/private/tmp/m008-cube-slides-20261022';
if (!base.startsWith('/private/tmp/m008-cube-slides-20261022')) throw Error('Unsafe output root');
fs.mkdirSync(base, {recursive: true});
const git = (args, options = {}) => cp.execFileSync('git', args, {cwd: root, encoding: 'utf8', ...options}).trim();
const head = git(['rev-parse', 'HEAD']);
const env = {...process.env, GIT_INDEX_FILE: path.join(base, target + '.index')};
git(['read-tree', head], {env});
for (const file of files) git(['update-index', '--add', '--cacheinfo', '100644', git(['hash-object', '-w', file]), file], {env});
const tree = git(['write-tree'], {env});
fs.writeFileSync(path.join(base, target + '-source.json'), JSON.stringify({head, tree, files, remoteOperations: false}, null, 2));
let script;
if (target === 'mini') {
  script = fs.readFileSync(path.join(root, 'scripts/build/member-008-round4-mini.cjs'), 'utf8')
    .replace(/const commit=assetsCandidate\?[^;]+;/, 'const commit=' + JSON.stringify(tree) + ';')
    .replace(/const base=assetsCandidate\?[^;]+;/, 'const base=' + JSON.stringify(base + '/mini') + ';')
    .replace("git(['rev-parse','HEAD']).toString().trim()!==commit", "git(['rev-parse','HEAD']).toString().trim()!==" + JSON.stringify(head))
    .replace(/if\(dirty\.split[^\n]+/, '// Only the explicit tree above is compiled.');
  process.argv = [process.argv[0], 'cube-mini-build'];
} else {
  script = fs.readFileSync(path.join(root, 'scripts/member-commerce-release-build.cjs'), 'utf8')
    .replace(/const commit=variant===.*?;/, 'const commit=' + JSON.stringify(tree) + ';')
    .replace("const base='/private/tmp/m008-round3-release-20261020';", 'const base=' + JSON.stringify(base + '/' + target) + ';')
    // The tracked admin baseline has no ESLint config. Template/parser contracts run separately.
    .replace('config.cache(false);', "config.module.rules.delete('eslint');config.cache(false);");
  // Uni's scan pass resolves before its optimizing emission; success requires actual HTML.
  script = script.replace('process.exit(0);return value;', "if(fs.existsSync(path.join(output,'index.html')))process.exit(0);return value;")
    .replace('const init=Service.prototype.init;', "process.once('exit', code => { const exists=fs.existsSync(path.join(output,terminal==='admin'?'system.html':'index.html'));fs.writeFileSync(output+'-complete.json',JSON.stringify({commit,terminal,result:code===0&&exists?'PASS':'FAIL',actualOutput:exists}));if(!exists)process.exitCode=1;});const init=Service.prototype.init;");
  process.argv = [process.argv[0], 'cube-build', target, 'candidate'];
}
vm.runInThisContext('(function(require,__dirname,__filename){' + script + '\n})', {filename: __filename})(require, path.join(root, 'scripts/build'), __filename);
