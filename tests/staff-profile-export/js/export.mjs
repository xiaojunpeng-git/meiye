import fs from 'node:fs';import vm from 'node:vm';import assert from 'node:assert/strict';
// Run the actual export handler to verify all pages and frozen submitted filters.
const source=fs.readFileSync('前端代码/admin/src/pages/setting/staff/index.vue','utf8');
const body=source.slice(source.indexOf('    async exports()'),source.indexOf('    handleClick1(id)')).trim().replace(/,$/,'');
const requests=[];let exported;const c={merchantStaffList:async p=>{requests.push({...p});const n=p.page<3?50:25;return{data:{count:125,export:Array.from({length:n},(_,i)=>({id:(p.page-1)*50+i})),header:['ID'],filekey:['id'],filename:'test'}}},exportExcel:(...x)=>exported=x};vm.createContext(c);vm.runInContext('handler=({'+body+'}).exports',c);
const errors=[];const state={exporting:false,submittedListParams:{keyword:'已查询',status:0,org_id:3,page:7},formData:{keyword:'草稿',status:1},$Message:{error:x=>errors.push(x)}};
await c.handler.call(state);assert.deepEqual(requests.map(x=>x.page),[1,2,3]);assert(requests.every(x=>x.keyword==='已查询'&&x.status===0&&x.org_id===3&&x.export_fields===1));assert.equal(exported[3].length,125);assert.equal(new Set(exported[3].map(x=>x.id)).size,125);assert.equal(state.exporting,false);console.log('PASS full export starts at page 1, includes all 125 rows, freezes submitted organization/status/keyword');
c.merchantStaffList=async()=>{throw Error('failure')};await c.handler.call(state);assert.equal(errors.length,1);assert.equal(state.exporting,false);console.log('PASS failed request reports error and releases loading state');
assert(!source.includes("v-auth=\"['export-userCommission']\""));console.log('PASS unrelated commission permission removed');
