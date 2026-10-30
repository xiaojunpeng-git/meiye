// Fixed-tree release builds reuse compiler isolation and explicit customer mapping.
const fs=require('fs'),path=require('path'),Module=require('module');const file=path.join(__dirname,'r15-booking-publish-build.cjs');let code=fs.readFileSync(file,'utf8');
for(const[a,b]of [['tests/r15-booking-release/plan.json','tests/r15-card-release/plan.json'],['/private/tmp/r15-booking-publish-','/private/tmp/r15-card-release-']]){if(code.split(a).length!==2)throw Error('Compiler harness drift');code=code.replace(a,b);}
const mod=new Module(file,module);mod.filename=file;mod.paths=module.paths;mod._compile(code,file);
