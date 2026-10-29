// Same accepted canonical source for both customers; only generated identity and
// compiler output directories differ. No shared source/config or remote writes.
const fs=require('fs'),path=require('path'),Module=require('module');
const original=path.resolve('scripts/m015-member-build.cjs');let code=fs.readFileSync(original,'utf8');
for(const[a,b]of [
 ['tests/member-project-booking/evidence/build-plan.json','tests/m015-release/plan.json'],
 ["platform==='mp-weixin'&&target!=='008'","platform==='mp-weixin'&&!['008','010'].includes(target)"],
 ['/private/tmp/m015-project-booking-final-','/private/tmp/m015-release-'],
 ['parsed[\'mp-weixin\'].appid=plan.appid','parsed[\'mp-weixin\'].appid=plan.apps[target].appid'],
 ['{appid:plan.appid,compileType:','{appid:plan.apps[target].appid,compileType:']
]){if(code.split(a).length!==2)throw Error('Reviewed build hook drift');code=code.replace(a,b);}
const child=new Module(original,module);child.filename=original;child.paths=Module._nodeModulePaths(path.dirname(original));child._compile(code,original);
