// Run the accepted browser harness against the immutable candidate, changing
// only evidence destinations/Git metadata, never application or test assertions.
const fs = require('fs'), path = require('path'), os = require('os'), crypto = require('crypto'), Module = require('module');
const root = path.resolve(__dirname, '..');
const evidence = path.join(root, 'tests/cashier-v3/evidence/R53-release');
const plan = JSON.parse(fs.readFileSync(path.join(evidence, 'trees.json')));
const input = path.join(os.homedir(), '.cache/mohe-r53-card-balance-tests', plan.candidateTree);
const file = path.join(input, 'tests/cashier-v3/js/r53-card-to-balance-browser.cjs');
const original = fs.readFileSync(file, 'utf8');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
if (hash(original) !== plan.candidates['tests/cashier-v3/js/r53-card-to-balance-browser.cjs'].sha256) throw Error('Fixed harness drift');
let source = original.replace("const evidence = path.join(root, 'tests/cashier-v3/evidence/R53-card-to-balance-frontend')",
  'const evidence = ' + JSON.stringify(path.join(evidence, 'fixed-browser')));
source = source.replace("sha(execFileSync('git', ['diff', 'HEAD', '--', ...files], { cwd: root }))", 'sha(Buffer.from(JSON.stringify(fileHashes)))');
source = source.replace("testedHead: execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim()",
  'testedTree: ' + JSON.stringify(plan.candidateTree));
fs.writeFileSync(path.join(evidence, 'fixed-browser-harness.json'), JSON.stringify({ tree: plan.candidateTree,
  originalSHA256: hash(original), evaluatedHarnessSHA256: hash(source), productionSourcesReadonly: true,
  adaptation: 'evidence destination and immutable-tree metadata only; source manifest replaces workspace diff' }, null, 2) + '\n');
const child = new Module(file, module); child.filename = file; child.paths = Module._nodeModulePaths(path.dirname(file));
child._compile(source, file);
