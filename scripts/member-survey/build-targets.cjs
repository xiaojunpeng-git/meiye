// Explicitly authorized H5 targets only; this command builds locally and never uploads or deploys.
const cp=require('child_process'),path=require('path'),fs=require('fs');const root=path.resolve(__dirname,'../..');
for(const target of ['rh','008','010']){
 const output=fs.openSync('/tmp/r12-member-'+target+'-build.log','w');
 const result=cp.spawnSync('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/node/node',[path.join(__dirname,'build-local.cjs'),'h5'],{cwd:root,env:{...process.env,NODE_OPTIONS:'--openssl-legacy-provider',SURVEY_BUILD_TARGET:target},stdio:['ignore',output,output]});fs.closeSync(output);
 if(result.status!==0)throw Error('H5 build failed: '+target);console.log(target+' H5 BUILD PASS');
}
