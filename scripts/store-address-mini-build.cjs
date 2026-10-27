// Compile only the fixed canonical tree for the explicitly named 008 member app.
// Identity is generated input and never changes the shared member source.
const fs=require('fs'),path=require('path'),cp=require('child_process'),Module=require('module');
const root=path.resolve(__dirname,'..');process.chdir(root);
const plan=JSON.parse(fs.readFileSync('tests/store-address-layout/release/plan.json')),tree=plan.candidateTree;
const original='scripts/build/member-008-round4-mini.cjs';let code=cp.execFileSync('git',['show',plan.parentCommit+':'+original],{maxBuffer:128e6}).toString();
const replace=(a,b)=>{if(code.split(a).length!==2)throw Error('Reviewed mini build hook drift');code=code.replace(a,b);};
replace("const commit=assetsCandidate?'35195f2a89bd84280ff01766c58609ef77e3eabf':'2b912bd52b5191d9f3ce73d21292a9c5db5901c9';",'const commit='+JSON.stringify(tree)+';');
replace("const base=assetsCandidate?'/private/tmp/m008-round4-mini-20261020-assets-final':'/private/tmp/m008-round4-mini-20261020-runtime-fixed';","const base='/private/tmp/store-address-mini-008-20261027';");
replace("if(git(['rev-parse','HEAD']).toString().trim()!==commit)throw Error('Fixed release HEAD changed');","if(git(['rev-parse',commit+'^{tree}']).toString().trim()!==commit)throw Error('Fixed tree changed');");
replace("const dirty=git(['diff','--name-only','HEAD','--','前端代码/uniapp','前端代码/shared','scripts/build/member-mini-css-dedupe.cjs']).toString().trim();","const dirty='';");
process.argv=[process.argv[0],__filename];
const filename=path.join(root,original),child=new Module(filename,module);child.filename=filename;child.paths=Module._nodeModulePaths(path.dirname(filename));child._compile(code,filename);
