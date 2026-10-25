// Build the accepted R63 Vue2 member mini-program with 008-only identity.
// The old validated HBuilderX loader adapter is reused on an immutable Git tree.
const fs = require('fs');
const path = require('path');
const cp = require('child_process');
const Module = require('module');
const crypto = require('crypto');

const root = path.resolve(__dirname, '..');
process.chdir(root);
const commit = '626ff440b6f6d1ec6535dc09ca34f9ce9cccb8cc';
const sourcePath = 'scripts/build/member-008-round4-mini.cjs';
const original = cp.execFileSync('git', ['show', `${commit}:${sourcePath}`], {maxBuffer: 128e6});
let code = original.toString();
const tree = cp.execFileSync('git', ['rev-parse', `${commit}^{tree}`]).toString().trim();
const base = `/private/tmp/r63-card-group-mini-008-member-${tree.slice(0, 8)}`;
const replacements = [
  ["const commit=assetsCandidate?'35195f2a89bd84280ff01766c58609ef77e3eabf':'2b912bd52b5191d9f3ce73d21292a9c5db5901c9';", `const commit=${JSON.stringify(commit)};`],
  ["const base=assetsCandidate?'/private/tmp/m008-round4-mini-20261020-assets-final':'/private/tmp/m008-round4-mini-20261020-runtime-fixed';", `const base=${JSON.stringify(base)};`],
  ["if(git(['rev-parse','HEAD']).toString().trim()!==commit)throw Error('Fixed release HEAD changed');", `if(git(['rev-parse',commit+'^{tree}']).toString().trim()!==${JSON.stringify(tree)})throw Error('Fixed release tree changed');`],
  ["const dirty=git(['diff','--name-only','HEAD','--','前端代码/uniapp','前端代码/shared','scripts/build/member-mini-css-dedupe.cjs']).toString().trim();", "const dirty='';"],
];
for (const [before, after] of replacements) {
  if (code.split(before).length !== 2) throw Error('Reviewed builder boundary changed');
  code = code.replace(before, after);
}
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const evidence = path.join(root, 'tests/combination-r63/mini');
fs.mkdirSync(evidence, {recursive: true});
const adapter = JSON.stringify({
  commit, tree, customer: '008', terminal: 'member', appid: 'wx85d4bfd0b4b6aafb',
  origin: 'https://008.cc3798.com', base,
  originalBuilderSHA256: sha(original), adaptedBuilderSHA256: sha(code),
  readonly: true,
}, null, 2) + '\n';
const adapterPath = path.join(evidence, 'build-adapter.json');
if (fs.existsSync(adapterPath)) {
  if (fs.readFileSync(adapterPath, 'utf8') !== adapter) throw Error('Prior build adapter changed');
} else fs.writeFileSync(adapterPath, adapter, {flag: 'wx'});
const builderFilename = path.join(root, sourcePath);
const child = new Module(builderFilename, module);
child.filename = builderFilename;
child.paths = Module._nodeModulePaths(path.dirname(builderFilename));
child._compile(code, builderFilename);
