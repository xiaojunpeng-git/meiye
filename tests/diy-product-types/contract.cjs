const assert = require('assert').strict;
const { options, source, helperOptions: h, paths } = require('./source-harness.cjs');
const rows = [0,5,6,1].map((type,i) => ({ id:i+1, product_type:type }));
async function run() {
  for (const key of Object.keys(paths)) source(key);
  assert.deepEqual(h.filterDiyProducts(rows, {}), rows);
  for (let mask=0;mask<8;mask++) {
    const type=[0,5,6].filter((_,i) => mask & (1<<i));
    assert.deepEqual(h.filterDiyProducts(rows,{catalogTypes:{type}}).map(r=>r.product_type),type);
  }
  const legacy={}, selector=options('selector');
  assert.deepEqual(selector.computed.selectedTypes.call({configObj:legacy,options:h.diyProductTypeOptions}),[0,5,6]);
  assert.equal(legacy.catalogTypes,undefined);
  selector.methods.changeTypes.call({configObj:legacy,$set:(o,k,v)=>o[k]=v,$emit:()=>{}},[]);
  assert.deepEqual(legacy.catalogTypes,{type:[]});
  assert.deepEqual(options('listPreview').data.call({num:0}).defaultConfig.catalogTypes.type,[0,5,6]);
  assert.deepEqual(options('tabPreview').data.call({num:0}).defaultConfig.tabConfig.list[0].catalogTypes.type,[0,5,6]);
  const requests=[];
  const getProduct = data => new Promise(resolve=>requests.push({data,resolve}));
  const c={catalogTypes:{type:[6]}, typeConfig:{activeValue:2}, brandList:{brandVal:[10]}, numberConfig:{val:10}, goodsSort:{tabVal:1},productList:{list:[]}};
  const vm={configObj:c};
  const method=options('listSettings',{getProduct}).methods.getConfig;
  method.call(vm,{name:'catalogTypes'});
  c.catalogTypes.type=[5];method.call(vm,{name:'catalogTypes'});
  requests[1].resolve({data:[rows[1]]});await Promise.resolve();requests[0].resolve({data:[rows[2]]});await Promise.resolve();
  assert.deepEqual(c.productList.list,[rows[1]]);assert.equal(requests[1].data.catalog_types,'[5]');
  assert.deepEqual(requests[1].data.brand_id,[10]);
  const t={catalogTypes:{type:[6]},tabVal:2,brandConfig:{brandVal:[77]},selectConfig:{activeValue:[]},numConfig:{val:6},goodsSort:0,productList:{list:[]}};
  const tabVm={configObj:{tabConfig:{tabCur:0,list:[t]}}};
  options('tabSettings',{getProduct}).methods.getConfig.call(tabVm,{name:'catalogTypes',values:999});
  assert.equal(requests[2].data.limit,6);assert.equal(requests[2].data.catalog_types,'[6]');
  const queries=[], getProductslist = data => new Promise(resolve=>queries.push({data,resolve}));
  const member=options('member',{getProductslist}), m={productRequestId:0,$config:{LIMIT:20},typeConfig:1,dataConfig:{goodsList:{ids:[1,2,3]},catalogTypes:{type:[6]}},tempArr:[]};
  member.methods.productslist.call(m);m.dataConfig.catalogTypes.type=[];member.methods.productslist.call(m);
  assert.equal(queries[1].data.catalog_types,'[]');queries[1].resolve({data:[]});await Promise.resolve();queries[0].resolve({data:[rows[2]]});await Promise.resolve();assert.deepEqual(m.tempArr,[]);
  m.dataConfig={goodsList:{ids:[]}};member.methods.productslist.call(m);assert.equal(queries.length,2);
  // Tab projection must carry its own types to the shared member list.
  const tabs=options('memberTabs');
  const parent=options('tabPreview').data.call({num:0}).defaultConfig;
  parent.tabConfig.list[0].catalogTypes={type:[5]};
  const tv={dataConfig:parent,activeValue:parent.tabConfig.list[0],goodType:1,numConfig:6};
  tabs.watch.activeValue.handler.call(tv,tv.activeValue);
  assert.deepEqual(tv.goodDataConfig.catalogTypes.type,[5]);
  // Round-3 direct single-SKU purchase and theme code are untouched by this filter.
  assert(source('member').script.includes('goShopDetail'));
  console.log(JSON.stringify({status:'PASS',cases:['10-SFC-template-script-compilation','8-combinations','legacy-no-mutation','explicit-empty','new-defaults','late-query-isolation','brand-intersection','tab-count-stable','member-filter-transport','manual-empty','tab-projection','purchase-route-preserved']}));
}
run().catch(error=>{console.error(error);process.exitCode=1;});
