// Read-only release gate. A source dependency is satisfied only by the exact
// fixed Git blob already live, or by that same blob in the installation plan.
// Being present in the source tree alone never means it will reach the server.
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const root = path.resolve(__dirname, '..');
const dependencies = [
  'app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php',
  'app/services/cashier/v3/card/CashierV3CardReadAmountServices.php',
  'app/services/cashier/v3/card/CashierV3CardComponentPurchaseAmountAuthority.php',
  'app/services/cashier/v3/cashier/CashierV3EntitlementActualAmountAllocator.php',
  'app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php',
];
function requiredFiles(ref) {
  return Object.fromEntries(dependencies.map(file => {
    const bytes = cp.execFileSync('git', ['show', ref + ':后端代码/' + file], { cwd: root });
    return [file, crypto.createHash('sha256').update(bytes).digest('hex')];
  }));
}
function assertDependencies(required, live, payload) {
  const missing = Object.entries(required).filter(([file, digest]) =>
    (payload['formal/' + file]?.after ?? payload[file]?.after ?? live[file]) !== digest);
  if (missing.length) throw Error('Member detail dependencies missing or mismatched: ' + missing.map(([file]) => file).join(', '));
  return { result: 'PASS', dependencies: Object.keys(required).length, remoteWrites: false };
}
module.exports = { requiredFiles, assertDependencies };
if (require.main === module) {
  const [ref, probePath, manifestPath] = process.argv.slice(2);
  if (!ref || !probePath || !manifestPath) throw Error('fixed-ref live-probe.json installation-manifest.json required');
  const probe = JSON.parse(fs.readFileSync(probePath)), manifest = JSON.parse(fs.readFileSync(manifestPath));
  console.log(JSON.stringify(assertDependencies(requiredFiles(ref), probe.backend, manifest.targets)));
}
