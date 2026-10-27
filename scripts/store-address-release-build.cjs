// Fixed canonical Git inputs only. Reuse the established HBuilderX pipeline;
// archives are read-only derived inputs, never another editable source checkout.
const fs=require('fs'),path=require('path'),Module=require('module');
const variant=process.argv[2],plan=JSON.parse(fs.readFileSync('tests/store-address-layout/release/plan.json'));
if(!['baseline','candidate'].includes(variant))throw Error('baseline|candidate');
const tree=plan[variant+'Tree'];if(!/^[a-f0-9]{40}$/.test(tree||''))throw Error('Fixed tree required');
const original=path.resolve('scripts/member-commerce-release-build.cjs');let code=fs.readFileSync(original,'utf8');
const replace=(a,b)=>{if(code.split(a).length!==2)throw Error('Build hook drift');code=code.replace(a,b);};
replace("const commit=variant==='baseline'?'2ddff36f9cbb93f8006d0e23cf9a66d8067d5456':'5ba5da5526cbd23afb0ec4983fd04a3d7cdc80e9';",'const commit='+JSON.stringify(tree)+';');
replace("const base='/private/tmp/m008-round3-release-20261020';","const base='/private/tmp/store-address-final-release-20261027';");
replace('process.exit(0);return value;',"if(fs.existsSync(path.join(output,'index.html')))process.exit(0);return value;");
process.argv=[process.argv[0],__filename,'member',variant];
const child=new Module(original,module);child.filename=original;child.paths=module.paths;child._compile(code,original);
