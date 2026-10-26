// Reuse the verified immutable Node16 platform pipeline; only fixed Git trees are build inputs.
const fs=require('fs'),path=require('path'),crypto=require('crypto'),Module=require('module');
let source=fs.readFileSync(path.join(__dirname,'r34-release-build.cjs'),'utf8');
if(crypto.createHash('sha256').update(source).digest('hex')!=='f9d3fa2d504f5069d649327985eae3cc1f91705eb76f5f1301e3d2829583a0e8')throw Error('Build pipeline drift');
source=source.replace('/private/tmp/mohe-r34-store-defaults-release','/private/tmp/mohe-r35-org-edit-release').replace('tests/store-admin/evidence/R34-delivery-defaults/release','tests/organization-store-v3/evidence/R35-organization-edit/release');
const child=new Module(__filename,module);child.filename=__filename;child.paths=module.paths;child._compile(source,__filename);
