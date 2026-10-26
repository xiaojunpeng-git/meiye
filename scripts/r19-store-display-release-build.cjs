// Build one site's fixed Git tree through the project's immutable export pipeline.
// The site argument selects only the source tree and evidence directory; no client
// configuration or mutable server files enter the build.
const fs = require('fs')
const path = require('path')
const Module = require('module')
const site = process.argv[2]
const variant = process.argv[3]
const terminal = process.argv[4]
if (!['rh', '008', '010'].includes(site)) throw Error('Explicit site required')
const original = fs.readFileSync(path.join(__dirname, 'r32-report-scroll-build.cjs'), 'utf8')
const source = original
  .replace("const base = '/private/tmp/mohe-r32-report-scroll-release';", `const base = '/private/tmp/mohe-r19-store-display-v2-${site}';`)
  .replace("const release = path.join(root, 'tests/cashier-v3/evidence/R32-report-scroll/release');", `const release = path.join(root, 'tests/cashier-v3/evidence/R19-store-display-release-v2/${site}');`)
if (source === original || !source.includes(`mohe-r19-store-display-v2-${site}`)) throw Error('Build pipeline anchor drift')
process.argv = [process.argv[0], process.argv[1], variant, terminal]
const child = new Module(__filename, module)
child.filename = __filename
child.paths = module.paths
child._compile(source, __filename)
