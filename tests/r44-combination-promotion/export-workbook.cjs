/** Read back a real XLSX file in memory: full rows, exact money, server totals and scope notes. */
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const root=path.resolve(__dirname,'../..');
const XLSX=require(path.join(root,'前端代码/admin/node_modules/xlsx-js-style'));
const file=path.join(root,'前端代码/admin/src/utils/combinationDashboardExcel.js');
const code=fs.readFileSync(file,'utf8').replace(/^import .*;\s*$/gm,'').replace("const XLSX = require('xlsx-js-style');",'').replace('export function createCombinationDashboardWorkbook','function createCombinationDashboardWorkbook').replace('export default function downloadCombinationDashboardExcel','function downloadCombinationDashboardExcel')+'\nmodule.exports=createCombinationDashboardWorkbook;';
const context={module:{exports:{}},XLSX};vm.runInNewContext(code,context);
const data={filename:'拼团业绩明细',columns:[{key:'order_no',title:'订单号',width:180},{key:'net_amount',title:'净业绩',money:true}],
 count:10005,summary:{net_amount:'1000.50'},source_notes:['成功退款冲减原支付日期对应业绩。'],metadata:{filters:{activity_ids:'2,3',start_date:'2026-10-01',end_date:'2026-10-31',dimension:'member',dimension_id:0,status:'unformed'},scope_note:'仅授权门店',generated_at:'2026-11-03 12:00:00',timezone:'Asia/Shanghai'},
 export:Array.from({length:10005},(_,i)=>({order_no:i===0?'=SUM(A1:A2)':String(i).padStart(12,'0'),net_amount:'0.10'}))};
const buffer=XLSX.write(context.module.exports(data),{bookType:'xlsx',type:'buffer'});
const book=XLSX.read(buffer,{type:'buffer'});
assert.deepEqual(book.SheetNames,['业绩明细','统计口径']);
const rows=XLSX.utils.sheet_to_json(book.Sheets['业绩明细'],{header:1});
assert.equal(rows.length,10007);assert.equal(rows[1][1],'1000.50');assert.equal(rows[2][1],'0.10');
assert.equal(book.Sheets['业绩明细'].A3.t,'s');assert.equal(book.Sheets['业绩明细'].A3.f,undefined);
assert.equal(rows[3][0],'000000000001');
const notes=XLSX.utils.sheet_to_json(book.Sheets['统计口径'],{header:1});
const map=Object.fromEntries(notes);assert.equal(map['数据范围'],'仅授权门店');assert.equal(map['活动编号'],'2,3');
assert.equal(map['下钻对象编号'],'0');assert.equal(map['拼团状态'],'全部未成团');assert.equal(map['查询记录数'],'10005');assert.equal(map['统计口径 1'],data.source_notes[0]);
console.log('PASS R44 XLSX: 10005 rows + backend total, exact decimals, leading zeros, formula-safe strings and query/source metadata');
