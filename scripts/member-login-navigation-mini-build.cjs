// Reuse the same reviewed native 008 member compiler; only the fixed Git tree
// and private generated output directory differ from the uploaded R5 package.
const fs=require('fs'),path=require('path'),Module=require('module');
const original=path.resolve('scripts/store-address-mini-build.cjs');let code=fs.readFileSync(original,'utf8');
for(const[a,b]of [
 ['tests/store-address-layout/release/plan.json','tests/member-login-navigation/evidence/plan.json'],
 ['/private/tmp/store-address-mini-008-20261027','/private/tmp/member-login-navigation-mini-008-20261028']
]){if(code.split(a).length!==2)throw Error('Reviewed compiler adapter drift');code=code.replace(a,b);}
const child=new Module(original,module);child.filename=original;child.paths=module.paths;child._compile(code,original);
