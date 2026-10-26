const fs=require('fs'),path=require('path'),cp=require('child_process');
const root=process.cwd(),base='/private/tmp/mohe-r95-excel-release-20261024';
const tree=JSON.parse(fs.readFileSync(path.join(base,'trees.json'))).candidateTree;
const out=path.join(root,'tests/target-scheme/evidence/R95-excel-names-2026-10-24/release');
const run=(cmd,args,options={})=>cp.execFileSync(cmd,args,{cwd:root,maxBuffer:64*1024*1024,...options});
const snapshot=path.join(base,'php-readonly');fs.mkdirSync(snapshot,{recursive:true});
// Export exact immutable PHP blobs, then preload them into the guarded local
// integration process. Never replace the shared checkout or production files.
const files=['app/controller/admin/v1/target/StoreTarget.php','app/services/target/TargetSchemeServices.php','app/services/report/GroupManagementDashboardTargetServices.php'];
for(const file of files){const dest=path.join(snapshot,path.basename(file));fs.writeFileSync(dest,run('git',['show',tree+':后端代码/'+file]));fs.chmodSync(dest,0o444);}
const containerPath='/tmp/r95-excel-fixed';
const preload="<?php\nrequire '/var/www/html/vendor/autoload.php';\n"+files.map(f=>`require '${containerPath}/${path.basename(f)}';`).join('\n');
fs.writeFileSync(path.join(snapshot,'preload.php'),preload);fs.chmodSync(path.join(snapshot,'preload.php'),0o444);
run('docker',['cp',snapshot+'/.','mohe-cashier-app:'+containerPath]);
const js=run('docker',['exec','-i','-e','EXPORT_PAYLOAD=/tmp/r95-excel-payload.json','mohe-admin-src','node','--input-type=module'],{input:fs.readFileSync('tests/target-scheme/js/r95-excel-names.mjs')});fs.writeFileSync(path.join(out,'fixed-import-tests.log'),js);
const payload=run('docker',['exec','mohe-admin-src','cat','/tmp/r95-excel-payload.json']);
for(const [file,env,log,input] of [
    ['r95-excel-import-integration.php','R95_EXCEL_LOCAL_TEST=1','fixed-database-tests.log',payload],
    ['r95-line-binding-integration.php','R95_LOCAL_TEST=1','retained-target-regression.log',Buffer.alloc(0)]
]){
    run('docker',['cp','tests/target-scheme/php/'+file,'mohe-cashier-app:'+containerPath+'/'+file]);
    const result=run('docker',['exec','-i','-e',env,'mohe-cashier-app','php','-d','auto_prepend_file='+containerPath+'/preload.php',containerPath+'/'+file],{input});
    fs.writeFileSync(path.join(out,log),result);console.log(result.toString());
}
console.log(js.toString());
