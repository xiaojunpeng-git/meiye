// Reuse the byte-verified readonly export pipeline. Live comparison is R17's
// canonical tree; the unified baseline additionally retains accepted fullscreen.
const fs = require('fs'), path = require('path'), crypto = require('crypto'), Module = require('module');
let source = fs.readFileSync(path.join(__dirname, 'r32-report-scroll-build.cjs'), 'utf8');
if (crypto.createHash('sha256').update(source).digest('hex') !== '26439ee50f682f9afa67076810bc616be852a0bc3b968060a1476d7eca576e5e') throw Error('Build pipeline drift');
source = source.replace("const base = '/private/tmp/mohe-r32-report-scroll-release';", "const base = '/private/tmp/mohe-r57-source-policy-release-20261025';")
  .replace("const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');", "const release = path.join(root, 'tests/cashier-v3/evidence/R57-release');")
  .replace("!['baseline', 'candidate'].includes(variant)", "!['baseline', 'candidate', 'liveComparison'].includes(variant)");
const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
