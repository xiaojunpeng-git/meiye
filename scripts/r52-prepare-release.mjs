import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

// Generate immutable candidates from canonical Git objects and accepted R52 spans.
// Never import server source, switch the development checkout, or touch its index.
const [output, frontBase = '63784345'] = process.argv.slice(2);
if (!output || fs.existsSync(output)) throw new Error('A new output directory is required');
const git = (args, input) => execFileSync('git', args, {encoding:'utf8', input, maxBuffer:32*1024*1024});
const sha = body => crypto.createHash('sha256').update(body).digest('hex');
const accepted = JSON.parse(fs.readFileSync('tests/cashier-v3/evidence/R52-large-order-monthly-local-2026-10-14.json'));
for (const file of accepted.fixed_version.files) {
  if (sha(fs.readFileSync(file.path)) !== file.sha256) throw new Error(`Acceptance changed: ${file.path}`);
}
const replace = (s, before, after) => {
  if (!before || s.split(before).length !== 2) throw new Error(`Ambiguous anchor: ${before.slice(0,120)}`);
  return s.replace(before, after);
};
const method = (s, name, comment = false) => {
  const match = new RegExp(`^    (?:public|private) (?:static )?function ${name}\\(`,'m').exec(s);
  if (!match) throw new Error(`Missing method ${name}`);
  const end = s.indexOf('\n    }\n', match.index);
  let start = match.index;
  if (comment) {
    const preceding = s.slice(0,start).match(/    \/\*\*(?:(?!\*\/)[\s\S])*\*\/\s*$/);
    if (preceding) start = preceding.index;
  }
  return s.slice(start,end+7);
};
const phasePath='后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php';
const frontPath='前端代码/cashier-v3/src/views/StoreBusinessReportView.vue';
// R51's recorded composed blob, followed by R19's nine exact single-line fixes,
// reproduces the independently verified live PhaseTwo hash without a server copy.
let phaseBase=git(['cat-file','blob','72501697a79f5a9fe406d38c5b990382cb45ee73']);
const ageDiff=git(['diff','f996da2e^','f996da2e','--',phasePath]).split('\n');
let changed=0;
for (let i=0;i<ageDiff.length-1;i++) {
  if(ageDiff[i].startsWith('-')&&!ageDiff[i].startsWith('---')&&ageDiff[i+1].startsWith('+')) {
    phaseBase=replace(phaseBase,ageDiff[i].slice(1),ageDiff[i+1].slice(1));changed++;
  }
}
if(changed!==9 || sha(phaseBase)!=='be853bfb2276d3efec401aa4655fb4980e53c28e0b9f66bd5155de9656a0f628') throw new Error('Live backend provenance mismatch');
const phaseWork=fs.readFileSync(phasePath,'utf8');
let phase=replace(phaseBase,'        $storeIds = $this->initializeReadScope($storeIds, $range, $input);',
  "        // A daily legacy URL cannot narrow an installment report to one day.\n        if ($report === 'salesperson_large_order_statistics') $range = StoreLargeOrderInstallmentServices::monthRange($input, $range);\n        $storeIds = $this->initializeReadScope($storeIds, $range, $input);");
for(const name of ['salespersonBeautyLargeOrder','currentBeautyLargeOrderFacts','beautyLargeOrderMonthlyAwards']) {
  phase=replace(phase,method(phase,name,true),method(phaseWork,name,true));
}
for(const prefix of ["            '销售人生美大单统计表'=>['division_name'", "        if($title==='销售人生美大单统计表')return"]) {
  const old=phase.split('\n').find(l=>l.startsWith(prefix));
  const next=phaseWork.split('\n').find(l=>l.startsWith(prefix));
  phase=replace(phase,old,next);
}
const warning=phase.split('\n').find(l=>l.includes("'销售人生美大单统计表'=>['pending_metrics'"));
phase=replace(phase,warning+'\n','');
const frontOriginal=git(['show',`${frontBase}:${frontPath}`]);
let front=frontOriginal;
const work=fs.readFileSync(frontPath,'utf8');
if(!front.includes('import { formatMoney,')) front=replace(front,"import { requestCashierV3Action }", "import { formatMoney, requestCashierV3Action }");
for(const prefix of ['  share_30000_before:', '  share_30000_after:', '  share_50000_before:', '  if (/^share_50000_after_']) {
  const line=front.split('\n').find(l=>l.startsWith(prefix));front=replace(front,line+'\n','');
}
for(const prefix of ['const usesNaturalMonthFilter =', 'const reportPeriodMode =']) {
  const old=front.split('\n').find(l=>l.startsWith(prefix));
  const next=work.split('\n').find(l=>l.startsWith(prefix));
  const extra=prefix.includes('usesNatural') ? "const isLargeOrderReport = computed(() => activeReport.value === 'salesperson_large_order_statistics')\n" : '// 大单两端均按应计自然月查询；其他门店报表仍保留原日期区间控件。\n';
  front=replace(front,old,extra+next);
}
front=replace(front,'function formatCell(value) {','function formatCell(value, column) {');
front=replace(front,"  if (value === null || value === undefined || value === '') return '-'", "  if (value === null || value === undefined || value === '') return '-'\n  // 新月度大单金额按统一元整数展示；接口与导出继续保留分精度。\n  if (isLargeOrderReport.value && column?.value_type === 'money' && Number.isFinite(Number(value))) return formatMoney(Math.round(Number(value)))");
const monthLine=work.split('\n').find(l=>l.startsWith('  if (isLargeOrderReport.value) selectedMonth'));
front=replace(front,"  if (isAnnualAcquisitionSummary.value) selectedYear.value = today().slice(0, 4)","  if (isAnnualAcquisitionSummary.value) selectedYear.value = today().slice(0, 4)\n"+monthLine);
const monthInput=work.split('\n').find(l=>l.includes('<label v-else-if="isLargeOrderReport"'));
front=replace(front,'      <div v-else-if="!isPlatformReport" class="store-business-report__period-picker">',monthInput+'\n      <div v-else-if="!isPlatformReport" class="store-business-report__period-picker">');
front=front.replaceAll('formatCell(summaryRow[column.key])','formatCell(summaryRow[column.key], column)');
front=replace(front,'<template v-else>{{ formatCell(row[column.key]) }}</template>','<template v-else>{{ formatCell(row[column.key], column) }}</template>');
const candidates=new Map([[phasePath,phase],[frontPath,front]]);
for(const file of accepted.fixed_version.files) if(!candidates.has(file.path)) candidates.set(file.path,fs.readFileSync(file.path,'utf8'));
fs.mkdirSync(output,{recursive:true});
const manifest={round:52,front_base:git(['rev-parse',frontBase]).trim(),accepted:accepted.fixed_version,files:[],backend_before_sha256:sha(phaseBase)};
for(const [file,body] of candidates) {
  const target=path.join(output,'candidate',file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,body,{mode:0o444});
  const blob=git(['hash-object','-w','--stdin'],body).trim();
  manifest.files.push({path:file,sha256:sha(body),blob});
}
for(const [file,body]of [[phasePath,phaseBase],[frontPath,frontOriginal]]) {
  const target=path.join(output,'baseline',file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,body,{mode:0o444});
}
fs.writeFileSync(path.join(output,'manifest.json'),JSON.stringify(manifest,null,2)+'\n',{mode:0o444});
console.log(JSON.stringify({output,files:manifest.files},null,2));
