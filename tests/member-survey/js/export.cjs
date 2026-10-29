/** Export real workbook bytes and read them back; user text must remain text, not spreadsheet formulas. */
const fs=require('fs'),path=require('path'),vm=require('vm');const root=path.resolve(__dirname,'../../..'),admin=path.join(root,'前端代码/admin');
const XLSX=require(path.join(admin,'node_modules/xlsx-js-style'));let saved;
let source=fs.readFileSync(path.join(admin,'src/utils/memberSurveyExcel.js'),'utf8').replace("import { saveAs } from 'file-saver';",'').replace(/export function /g,'function ');
const context={require:n=>{if(n!=='xlsx-js-style')throw Error(n);return XLSX;},Blob,saveAs:(blob,name)=>{saved={blob,name}}};vm.createContext(context);vm.runInContext(source+';this.taskExport=downloadMemberSurveyTaskExcel;this.questionnaireExport=downloadMemberSurveyExcel;',context);
const checks=[];const check=(v,n)=>{if(!v)throw Error(n);checks.push(n)};
(async()=>{
 const columns=[{key:'store_name',title:'门店',width:20,type:'text'},{key:'phone',title:'手机',width:20,type:'text'},{key:'answer',title:'回答',width:30,type:'text'}];
 context.taskExport({task:{id:1,title:'合成/问卷'},scope_note:'全部轮次',store_note:'当前会员归属',as_of_text:'2026-10-29 12:00:00',summary:{columns:[{key:'store_name',title:'门店',type:'text',width:20},{key:'completed_count',title:'完成',type:'number',width:12}],total:{store_name:'合计',completed_count:1},rows:[{store_name:'合成店',completed_count:1}]},details:{columns,rows:[{store_name:'合成店',phone:'00123456789',answer:'=SUM(A1:A2)'}]}});
 check(!saved.name.includes('/'),'文件名消除路径字符');const bytes=Buffer.from(await saved.blob.arrayBuffer()),book=XLSX.read(bytes,{type:'buffer'});
 check(book.SheetNames.join(',')==='门店完成数量,门店问卷明细','任务工作簿保留门店汇总与答卷明细');const sheet=book.Sheets['门店问卷明细'];
 check(sheet.B6.v==='00123456789'&&sheet.B6.t==='s','手机号前导零保留为文本');check(sheet.C6.v==='=SUM(A1:A2)'&&sheet.C6.t==='s'&&!sheet.C6.f,'用户答案不执行Excel公式');check(sheet['!autofilter'].ref==='A5:C6','筛选区域包含完整明细');
 fs.writeFileSync(path.join(root,'tests/customer-010/evidence/round12-member-survey/synthetic-export.xlsx'),bytes);
 context.questionnaireExport({analysis:{questionnaire:{id:1,title:'合成问卷'},groups:[]},answers:[]});const second=XLSX.read(Buffer.from(await saved.blob.arrayBuffer()),{type:'buffer'});check(second.SheetNames.length===2,'空分析导出仍有统计与明细工作表');
 fs.writeFileSync(path.join(root,'tests/customer-010/evidence/round12-member-survey/export.json'),JSON.stringify({passed:true,checks},null,2));console.log(JSON.stringify({passed:true,checks}));
})().catch(e=>{console.error(e);process.exit(1)});
