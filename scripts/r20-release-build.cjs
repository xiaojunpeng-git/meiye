// Reuse the immutable Git-export builder with an R20-specific evidence root.
const fs = require('fs')
const path = require('path')
const Module = require('module')
const site = process.argv[2]
const variant = process.argv[3]
if (!['rh', '008', '010'].includes(site) || !['baseline', 'candidate'].includes(variant)) throw Error('site and variant required')
const source = fs.readFileSync(path.join(__dirname, 'r32-report-scroll-build.cjs'), 'utf8')
  .replace("const base = '/private/tmp/mohe-r32-report-scroll-release';", `const base = '/private/tmp/mohe-r20-release-${site}';`)
  .replace("const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');", `const release = path.join(root, 'tests/cashier-v3/evidence/R20-release-20261027/${site}');`)
if (!source.includes(`mohe-r20-release-${site}`)) throw Error('Build pipeline anchor drift')
process.argv = [process.argv[0], process.argv[1], variant, 'cashier-v3']
const child = new Module(__filename, module)
child.filename = __filename
child.paths = module.paths
child._compile(source, __filename)
