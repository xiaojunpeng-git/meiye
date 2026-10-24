// Keep retained assertions intact. Only fixed input/output paths and the
// independently reproduced live-comparison build are retargeted to R57.
const fs = require('fs'), path = require('path'), Module = require('module');
function evaluate(source) {
  const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
}
let fullscreen = fs.readFileSync(path.join(__dirname, 'r56-fullscreen-release-test.cjs'), 'utf8');
fullscreen = fullscreen.slice(0, fullscreen.indexOf('// Reuse the already-reviewed')).replaceAll('R56-release', 'R57-release');
evaluate(fullscreen);
let retained = fs.readFileSync(path.join(__dirname, 'r55-member-release-test.cjs'), 'utf8');
retained = retained.replaceAll('R55-release', 'R57-release').replace("load('baseline-cashier-v3-build.json')", "load('liveComparison-cashier-v3-build.json')");
evaluate(retained);
