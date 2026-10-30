// Local validation reuses the audited isolated compiler, never its release plan
// or output directory. A fixed Git tree supplies all business source inputs.
const fs=require('fs'),path=require('path'),Module=require('module');
const source=path.join(__dirname,'r15-booking-publish-build.cjs');
let code=fs.readFileSync(source,'utf8');
for(const [before,after] of [['tests/r15-booking-release/plan.json','tests/r15-occupied-addon/evidence/build-plan.json'],['/private/tmp/r15-booking-publish-','/private/tmp/r15-occupied-addon-final-']]){
 if(code.split(before).length!==2)throw Error('Build harness drift');code=code.replace(before,after);
}
const mod=new Module(source,module);mod.filename=source;mod.paths=module.paths;mod._compile(code,source);
