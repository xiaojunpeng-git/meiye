const fs = require('fs');
const path = require('path');
const Module = require('module');
const cp = require('child_process');

// Build only immutable canonical Git inputs. Baseline/current outputs are
// evidence, not editable customer source; no remote side effects are allowed.
const original = path.resolve('scripts/member-commerce-release-build.cjs');
const variant = process.argv[3] || 'candidate';
const expected = variant === 'baseline' ? '0f5ea37e314cb1058daf0c3aac21c4c387b3e108' : '25c115271e945bdb8f2a46a8bcbb85f8b7137f6f';
const tree = variant === 'baseline' ? '891aa399e7badf41ce71f265c569989ad677084e' : '1affeeabaff4f28ad9e2d6fab56a1d8e1f378c2f';
const resolve = ref => cp.execFileSync('git', ['rev-parse', ref], { encoding: 'utf8' }).trim();
let selected = process.env.M008_BACKGROUND_SOURCE || expected;
// A precision release chain has different commit ancestry but exactly the same
// accepted member/shared trees. Permit reproducible builds from that chain,
// never a merely similarly named branch or an unverified working directory.
if (!process.env.M008_BACKGROUND_SOURCE && cp.spawnSync('git', ['cat-file', '-e', expected + '^{commit}']).status !== 0) selected = 'HEAD';
const sourceCommit = resolve(selected + '^{commit}');
if (resolve(sourceCommit + ':前端代码/uniapp') !== tree || resolve(sourceCommit + ':前端代码/shared') !== 'f3580d665e7b57784131170a5d6cec637e18c359') throw Error('Accepted member/shared tree differs');
let source = fs.readFileSync(original, 'utf8');
const replace = (from, to) => {
  if (source.split(from).length !== 2) throw Error('Build hook changed: ' + from);
  source = source.replace(from, to);
};
replace("const commit=variant==='baseline'?'2ddff36f9cbb93f8006d0e23cf9a66d8067d5456':'5ba5da5526cbd23afb0ec4983fd04a3d7cdc80e9';",
  'const commit=' + JSON.stringify(sourceCommit) + ';');
replace("const base='/private/tmp/m008-round3-release-20261020';",
  "const base='/private/tmp/m008-background-release-20261021';");
// The scan pass can resolve before emission. Record success only after the
// real HTML exists, and fail closed if the IDE exits before that emission.
replace('process.exit(0);return value;', "if(fs.existsSync(path.join(output,'index.html')))process.exit(0);return value;");
replace('const init=Service.prototype.init;', `process.once('exit', code => {
 const exists = fs.existsSync(path.join(output, 'index.html'));
 fs.writeFileSync(output+'-complete.json', JSON.stringify({commit,terminal,variant,result:code===0&&exists?'PASS':'FAIL',actualOutput:exists})+'\\n');
 if(!exists)process.exitCode=1;
});
const init=Service.prototype.init;`);
const runtime = new Module(original, module);
runtime.filename = original;
runtime.paths = module.paths;
runtime._compile(source, original);
