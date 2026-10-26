// Mechanically reuse the tested backup/rollback harness with the exact generated
// R55 installer. An offline tmpfs container cannot reach customer files or DBs.
const fs = require('fs'), path = require('path'), cp = require('child_process'), Module = require('module'), crypto = require('crypto');
const root = path.resolve(__dirname, '../../..');
const publisher = path.join(root, 'scripts/r55-member-release-publish.cjs');
const bytes = cp.execFileSync(process.execPath, [publisher, 'installer']);
// Docker Desktop shares the canonical workspace, not the host /private/tmp.
// This is a read-only generated installer artifact, never an editable source.
const artifact = path.join(root, 'tests/cashier-v3/evidence/R55-release/installer.generated.php');
if (fs.existsSync(artifact)) { if (!fs.readFileSync(artifact).equals(bytes)) throw Error('Generated installer drift'); }
else fs.writeFileSync(artifact, bytes, { flag: 'wx', mode: 0o444 });
let source = fs.readFileSync(path.join(__dirname, 'r54-release-installer.cjs'), 'utf8');
source = source.replaceAll('r54-service-period', 'r55-member-list').replaceAll('R54-release', 'R55-release')
  .replace("path.join(root, 'scripts/r55-member-list-install.php')", JSON.stringify(artifact));
const negative = `
foreach(['preview/public/view_cashier_v3/index.html','formal/app/forbidden.php'] as $forbidden){
  $manifest['targets'][$forbidden]=['before'=>null,'after'=>hash('sha256','forbidden')];
  file_put_contents($directory.'/payload/manifest.json',json_encode($manifest));$digest=hash_file('sha256',$directory.'/payload/manifest.json');
  verify(invoke('backup')[0]!==0);unset($manifest['targets'][$forbidden]);
}
file_put_contents($directory.'/payload/manifest.json',json_encode($manifest));$digest=hash_file('sha256',$directory.'/payload/manifest.json');
`;
source = source.replace("verify(invoke('backup')[0]===0);", negative + "verify(invoke('backup')[0]===0);")
  .replace('result.checks !== 14', 'result.checks !== 16');
const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
fs.writeFileSync(path.join(root, 'tests/cashier-v3/evidence/R55-release/installer-source.json'), JSON.stringify({ sha256: crypto.createHash('sha256').update(bytes).digest('hex'), staticOnly: true, retiredPreviewRejected: true, backendRejected: true }, null, 2));
