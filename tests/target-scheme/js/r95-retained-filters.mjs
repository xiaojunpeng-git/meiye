import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
const base='/private/tmp/mohe-r95-excel-release-20261024';
const trees=JSON.parse(fs.readFileSync(base+'/trees.json'));
const contract='前端代码/shared/unified-query-vue3/src/contracts/';
const dependency=pathToFileURL(base+'/candidate-readonly/'+contract+'unifiedQueryContract.js').href;
// Compare immutable deployed and retained modules: existing types must preserve
// query payloads exactly; only the already-accepted datetime type is added.
const load=async tree=>{
    const source=execFileSync('git',['show',tree+':'+contract+'reportFilters.js'],{encoding:'utf8'}).replace("'./unifiedQueryContract.js'",JSON.stringify(dependency));
    return import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
};
const old=await load(trees.deployedTree),next=await load(trees.candidateTree);
let checks=0;
for(const [type,values] of Object.entries({text:['文本',0,false,''],date:['2026-10-24',''],month:['2026-10',''],enum:[0,false,'yes',''],number:[{min:0,max:100},{min:'',max:''}]})){
    const fields=[{key:'field',label:'字段',type,defaultQuick:true}],snapshot={pageCode:'test',page:3,store_ids:[23],filters:[{field:'scope',operator:'eq',value:7}]};
    for(const value of values){assert.deepEqual(next.buildReportFilterQuery(fields,{field:value},snapshot),old.buildReportFilterQuery(fields,{field:value},snapshot));checks++;}
}
const dates=[{key:'time',label:'办理时间',type:'datetime-local',defaultQuick:true}];
assert.equal(next.reportFilterType(dates[0]),'datetime-local');checks++;
assert.equal(next.reportFilterDraft(dates,{filters:[{field:'time',operator:'eq',value:'2026-10-24 09:30:12'}]}).time,'2026-10-24T09:30:12');checks++;
assert.equal(next.buildReportFilterQuery(dates,{time:'2026-10-24T09:30'}).filters[0].value,'2026-10-24 09:30:00');checks++;
assert.equal(next.buildReportFilterQuery(dates,{time:'2026-10-24T09:30:12'}).filters[0].value,'2026-10-24 09:30:12');checks++;
const result={checks,result:'PASS',deployedTree:trees.deployedTree,candidateTree:trees.candidateTree};
fs.writeFileSync('tests/target-scheme/evidence/R95-excel-names-2026-10-24/release/retained-filter-parity.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result));
