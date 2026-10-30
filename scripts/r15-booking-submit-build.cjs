// Local fixed-tree builds share the audited compiler isolation, never its live release plan.
const fs=require('fs'),path=require('path'),Module=require('module');const file=path.join(__dirname,'r15-booking-publish-build.cjs');let code=fs.readFileSync(file,'utf8');
for(const [a,b]of [['tests/r15-booking-release/plan.json','tests/r15-booking-submit/evidence/build-plan.json'],['/private/tmp/r15-booking-publish-','/private/tmp/r15-booking-submit-']]){if(code.split(a).length!==2)throw Error('Build harness drift');code=code.replace(a,b);}
const mod=new Module(file,module);mod.filename=file;mod.paths=module.paths;mod._compile(code,file);
