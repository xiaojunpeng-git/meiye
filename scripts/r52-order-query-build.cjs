// Reuse the previously verified immutable Git build pipeline without editing its archived source.
const fs = require('fs'), path = require('path'), crypto = require('crypto'), Module = require('module');
const file = path.join(__dirname, 'r32-report-scroll-build.cjs');
let source = fs.readFileSync(file, 'utf8');
if (crypto.createHash('sha256').update(source).digest('hex') !== '26439ee50f682f9afa67076810bc616be852a0bc3b968060a1476d7eca576e5e') throw Error('Build pipeline changed; re-review');
source = source.replace("const base = '/private/tmp/mohe-r32-report-scroll-release';", "const base = '/private/tmp/mohe-r52-order-query-release-v2';")
  .replace("const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');", "const release = path.join(root, 'tests/order-center/evidence/r52-release-v2');");
const child = new Module(__filename, module);
child.filename = __filename; child.paths = module.paths;
child._compile(source, __filename);
