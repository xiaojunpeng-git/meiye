// Immutable accepted per-instance inputs preserve existing releases; R36 Vue change is identical.
const fs=require('fs'),path=require('path'),crypto=require('crypto'),Module=require('module');
const site=process.argv.pop();if(!['final010'].includes(site))throw Error('Explicit site required');
let source=fs.readFileSync(path.join(__dirname,'r34-release-build.cjs'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!=='f9d3fa2d504f5069d649327985eae3cc1f91705eb76f5f1301e3d2829583a0e8')throw Error('Build pipeline drift');
source=source.replace('/private/tmp/mohe-r34-store-defaults-release','/private/tmp/mohe-r37-card-labor-'+site).replace('tests/store-admin/evidence/R34-delivery-defaults/release','tests/cashier-v3/evidence/R37-card-labor-followup/release/'+site);
const child=new Module(__filename,module);child.filename=__filename;child.paths=module.paths;child._compile(source,__filename);
