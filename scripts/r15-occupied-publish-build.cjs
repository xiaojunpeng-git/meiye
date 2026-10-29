// Same isolated fixed-tree build pipeline, with this release's own plan and outputs.
const fs=require('fs'),path=require('path'),Module=require('module');
const file=path.join(__dirname,'r15-booking-publish-build.cjs');let code=fs.readFileSync(file,'utf8');
for(const [from,to] of [['tests/r15-booking-release/plan.json','tests/r15-occupied-release/plan.json'],['/private/tmp/r15-booking-publish-','/private/tmp/r15-occupied-publish-']]){
 if(code.split(from).length!==2)throw Error('Build harness drift');code=code.replace(from,to);
}
const mod=new Module(file,module);mod.filename=file;mod.paths=module.paths;mod._compile(code,file);
