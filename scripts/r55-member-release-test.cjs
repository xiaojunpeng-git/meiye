// Retarget accepted tests to immutable canonical Git build inputs. Only fixture
// output/loader paths change; all assertions and actual Vue components are kept.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const dir = 'tests/cashier-v3/evidence/R55-release';
const load = name => JSON.parse(fs.readFileSync(path.join(dir, name)));
const plan = load('trees.json'), build = load('candidate-cashier-v3-build.json'), baseline = load('baseline-cashier-v3-build.json');
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const git = args => cp.execFileSync('git', args, { maxBuffer: 128 * 1024 * 1024 });
const snapshot = path.dirname(path.dirname(build.input));
if (build.tree !== plan.candidateTree || build.result !== 'PASS' || baseline.result !== 'PASS') throw Error('Fixed builds missing');
function verifyInputs() {
  for (const [file, digest] of Object.entries(build.sourceSHA256After)) if (sha(fs.readFileSync(path.join(snapshot, file))) !== digest) throw Error('Frozen source drift');
  for (const [file, digest] of Object.entries(plan.acceptedFiles)) if (sha(fs.readFileSync(file)) !== digest) throw Error('Accepted working source drift');
}
verifyInputs();
const runtimeDependencies = ['app/services/query/provider/MemberUnifiedQueryProvider.php', 'app/services/query/provider/MemberUnifiedQueryPageRegistrar.php',
  'app/services/query/UnifiedQueryExecutionServices.php', 'app/services/query/UnifiedQueryCapabilityServices.php',
  'app/services/cashier/v3/query/UnifiedQueryModule.php'];
for (const site of ['rh', '008', '010']) {
  const before = load(site + '-before.json');
  const formal = before.frontends.find(f => f.kind === 'cashier' && f.root === before.root);
  if (!formal || formal.sha256 !== baseline.assets['index.html']) throw Error('Baseline entry not reproduced: ' + site);
  for (const [file, digest] of Object.entries(formal.assets)) if (baseline.assets[file.replace('/view_cashier_v3/', '')] !== digest) throw Error('Baseline asset not reproduced: ' + site);
  for (const file of runtimeDependencies) {
    // RH retains the previously accepted R12 provider. Its only difference
    // here is imported-card summary support; R55 delivers no backend files.
    const sourceCommit = site === 'rh' && file === 'app/services/query/provider/MemberUnifiedQueryProvider.php' ? '4e63b2e5' : plan.baselineCommit;
    if (before.backend[file] !== sha(git(['show', sourceCommit + ':后端代码/' + file]))) throw Error('Unrecognized live query dependency: ' + site + ' ' + file);
  }
}
const results = [];
for (const name of ['member', 'reports']) {
  const file = name === 'member' ? 'tests/cashier-v3/js/r55-member-filters.mjs' : 'tests/report-basic/js/report-filter-contract.mjs';
  let source = git(['show', plan.candidateTree + ':' + file]).toString();
  const output = path.join(root, dir, 'fixed-' + name); fs.mkdirSync(output, { recursive: true });
  source = source.replace("path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..')", JSON.stringify(snapshot));
  if (name === 'member') {
    source = source.replace("path.join(root, 'tests/cashier-v3/evidence/R55-member-filters')", JSON.stringify(output));
    // PHP fixtures read canonical source only, whose registrar bytes are checked
    // against the fixed tree; they create no records or export jobs.
    source = source.replace("{ cwd: root, encoding: 'utf8' }", '{ cwd: ' + JSON.stringify(root) + ", encoding: 'utf8' }");
    const fixture = 'tests/cashier-v3/php/r55-member-filter-metadata.php';
    if (!git(['show', plan.candidateTree + ':' + fixture]).equals(fs.readFileSync(fixture))) throw Error('PHP fixture changed');
    const registrar = '后端代码/app/services/query/provider/MemberUnifiedQueryPageRegistrar.php';
    if (!git(['show', plan.candidateTree + ':' + registrar]).equals(fs.readFileSync(registrar))) throw Error('Registrar changed');
  } else {
    source = source.replace("'../../../前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js'", JSON.stringify(require('url').pathToFileURL(path.join(snapshot, '前端代码/shared/unified-query-vue3/src/contracts/reportFilters.js')).href));
    source = source.replace("{ cwd: repo, encoding: 'utf8' }", '{ cwd: ' + JSON.stringify(root) + ", encoding: 'utf8' }");
    source = source.replace('readFileSync(path.join(repo, file))', 'readFileSync(path.join(file.startsWith("tests/") ? ' + JSON.stringify(root) + ' : repo, file))');
  }
  const response = cp.spawnSync(process.execPath, ['--input-type=module'], { input: source, encoding: 'utf8', timeout: 180000, maxBuffer: 16 * 1024 * 1024,
    env: { ...process.env, REPORT_FILTER_EVIDENCE_DIR: output } });
  fs.writeFileSync(path.join(dir, 'fixed-' + name + '.log'), (response.stdout || '') + (response.stderr || ''));
  if (response.status !== 0) throw Error('Fixed regression failed: ' + name + '\n' + response.stderr);
  results.push({ name, result: 'PASS' }); console.log(name, 'PASS');
}
verifyInputs();
fs.writeFileSync(path.join(dir, 'fixed-tests.json'), JSON.stringify({ result: 'PASS', tree: plan.candidateTree, sourceStable: true,
  baselineReproduced: ['rh', '008', '010'], runtimeDependencies, results, financialWrites: 0, limits: 'Fixture transport; no authenticated production transactions' }, null, 2));
