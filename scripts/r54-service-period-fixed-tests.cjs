// Execute the accepted tests against the frozen candidate, not shared hot edits.
// The harness changes paths only; assertions and actual SFC modules stay intact.
const fs = require('fs'), path = require('path'), cp = require('child_process'), Module = require('module');
const root = path.resolve(__dirname, '..'); process.chdir(root);
const evidence = 'tests/cashier-v3/evidence/R54-release';
const plan = JSON.parse(fs.readFileSync(evidence + '/trees.json'));
const mode = process.argv[2];
if (mode === 'frontend') {
  const snapshot = JSON.parse(fs.readFileSync(evidence + '/candidate-cashier-v3-build.json')).input.replace(/\/前端代码\/cashier-v3$/, '');
  const name = process.argv[3];
  if (!['r54-query-period.cjs', 'r54-member-service-failure.cjs'].includes(name)) throw Error('Explicit fixed test required');
  let source = cp.execFileSync('git', ['show', plan.candidateTree + ':tests/cashier-v3/js/' + name], { encoding: 'utf8' });
  source = source.replace("path.resolve(__dirname, '../../..')", JSON.stringify(snapshot));
  source = source.replace("path.join(root, 'scripts/r54-member-service-dependency-gate.cjs')", JSON.stringify(path.join(root, 'scripts/r54-member-service-dependency-gate.cjs')));
  const sub = name.includes('query-period') ? 'fixed-period' : 'fixed-member';
  const directory = path.join(root, evidence, sub); fs.mkdirSync(directory, { recursive: true });
  // Vite needs a transient compiled-config sibling; fixed source files remain
  // read-only and are verified by the build manifest after the harness exits.
  const project = path.join(snapshot, '前端代码/cashier-v3');
  fs.chmodSync(project, 0o755);
  process.once('exit', () => fs.chmodSync(project, 0o555));
  source = source.replace(/path.join\(root, 'tests\/cashier-v3\/evidence\/R54-(?:query-period|member-service)'\)/g, JSON.stringify(directory));
  source = source.replace('/private/tmp/mohe-r54-query-period-vite-cache', '/private/tmp/mohe-r54-fixed-period-vite-cache');
  if (name.includes('member-service')) source = source.replace('root: frontend, configFile:', "root: frontend, cacheDir: '/private/tmp/mohe-r54-fixed-member-vite-cache', configFile:");
  const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
} else if (mode === 'backend') {
  let source = fs.readFileSync('scripts/r53-card-balance-test-fixed.cjs', 'utf8');
  source = source.replace("const evidence = 'tests/cashier-v3/evidence/R53-release';", "const evidence = 'tests/cashier-v3/evidence/R54-release';")
    .replace('.cache/mohe-r53-card-balance-tests', '.cache/mohe-r54-service-period-tests');
  const start = source.indexOf('const tests = ['), end = source.indexOf('\nconst results = [];', start);
  source = source.slice(0, start) + `const tests = [
    ['r54-member-service-readonly.php', '"serverReadOnly":true'],
    ['member-detail-v2-contract.php', 'PASS'],
    ['r53-card-history-sqlite.php', 'R53_CARD_HISTORY_SQLITE=PASS'],
    ['r53-historical-scope-contract.php', 'R53_HISTORICAL_SCOPE_SQLITE_OK'],
    ['r53-card-to-balance-gateway.php', 'PASS'],
    ['r53-conversion-reversal-guard.php', 'PASS'],
  ];` + source.slice(end);
  source = source.replace("'-e', 'R53_LOCAL_ONLY=1'", "'-e', 'R54_MEMBER_ID=913206', '-e', 'R53_LOCAL_ONLY=1'");
  const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
} else throw Error('frontend <test>|backend required');
