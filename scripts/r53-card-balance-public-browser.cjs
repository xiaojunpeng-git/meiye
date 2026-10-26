// Public boot/resource verification only; no operator session or financial action.
const fs = require('fs'), path = require('path'), crypto = require('crypto'), Module = require('module');
const template = path.join(__dirname, 'r32-report-scroll-public-browser.cjs');
let source = fs.readFileSync(template, 'utf8');
if (crypto.createHash('sha256').update(source).digest('hex') !== 'e14ef961dd12c648cd5d7260e72c8e259162df2afc53efee2d3d3a35058e538f') throw Error('Reviewed public browser pipeline changed');
source = source.replace('../tests/cashier-v3/evidence/R32-report-scroll/release', '../tests/cashier-v3/evidence/R53-release').replaceAll('#/reservation', '#/cashier');
source = source.replace('      for(const [asset,expected]of Object.entries(front.assets)){', `      const liveRefs=await page.evaluate(()=>[...document.querySelectorAll('script[src],link[rel="stylesheet"][href]')].map(node=>new URL(node.src||node.href,location.href).pathname.replace(/^\\/preview-8080\\//,'/')))
      for(const asset of Object.keys(front.assets))if(!liveRefs.includes(asset))throw Error('Live entry does not reference fixed resource: '+asset)
      for(const [asset,expected]of Object.entries(front.assets)){`)
  .replace('runtimeErrors:errors,result:', 'runtimeErrors:errors,entryReferences:liveRefs,result:');
const child = new Module(__filename, module); child.filename = __filename; child.paths = module.paths; child._compile(source, __filename);
