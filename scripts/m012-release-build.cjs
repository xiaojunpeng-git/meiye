const fs = require('fs'), path = require('path'), crypto = require('crypto'), Module = require('module');
// Reuse the immutable canonical-Git builder. Only baseline bindings differ;
// both variants contain the identical accepted reservation page and backend blob.
const variant = process.argv[2];
if (!['rh', 'existing-report-month'].includes(variant)) throw Error('Explicit baseline binding required');
process.argv.splice(2, 1);
const file = path.join(__dirname, 'r32-report-scroll-build.cjs');
let source = fs.readFileSync(file, 'utf8');
if (crypto.createHash('sha256').update(source).digest('hex') !== '26439ee50f682f9afa67076810bc616be852a0bc3b968060a1476d7eca576e5e') throw Error('Builder changed');
source = source.replace("const base = '/private/tmp/mohe-r32-report-scroll-release';", `const base = '/private/tmp/mohe-m012-${variant}-release';`)
  .replace("const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');", `const release = path.join(root, 'tests/cashier-v3/evidence/m012-reservation-shortcuts-2026-10-26/release/${variant}');`);
const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
