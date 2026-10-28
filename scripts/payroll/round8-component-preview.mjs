/** 组件隔离预览：直接编译正本页面，接口使用隔离数据库生成的夹具；不连接客户站点。 */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const require=createRequire(path.join(root,'前端代码/cashier-v3/package.json'));
const {createServer}=require('vite');
const vue=require('@vitejs/plugin-vue').default;
const page=path.join(root,'前端代码/mobile-vue3/src/merchant/pages/employee-performance/index.uvue');
const fixture=path.join(root,'tests/customer-010/evidence/round8-mobile-fee-project-count/fixture-projections.json');
const server=await createServer({configFile:false,optimizeDeps:{entries:[]},root:path.join(root,'前端代码/cashier-v3'),server:{host:'127.0.0.1',port:18119,strictPort:true},plugins:[{
 name:'round8-canonical-component',
 configureServer(s){s.middlewares.use((req,res,next)=>{
  if(req.url?.startsWith('/fixture')){res.setHeader('Content-Type','application/json');res.end(fs.readFileSync(fixture));return;}
  if(req.url==='/' || req.url?.startsWith('/?')){res.setHeader('Content-Type','text/html');res.end('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#eef3f9"><div id="app" style="max-width:390px;margin:auto"></div><script type="module" src="/round8-entry.js"></script></body></html>');return;}next();});},
 resolveId(id){if(id==='/round8.vue'||id==='/round8-entry.js')return id;},
 load(id){if(id==='/round8-entry.js')return `import{createApp}from'vue';import App from'/round8.vue';createApp(App).mount('#app');`;if(id!=='/round8.vue')return;let source=fs.readFileSync(page,'utf8').replace('lang="uts"','lang="ts"').replace(/^\s*import .*$/gm,'');
  const mocks=`import {ref} from 'vue';
const MerchantPrimaryTabBar={template:'<div style="padding:16px;text-align:center">经营　数据　客户　工作台　我的</div>'};
const currentMobilePlatform=()=> 'H5';const currentMerchantRoot=()=> ({});const merchantRequestSession=()=> ({});
const clearMerchantRoot=()=>{};const openMerchantBootstrap=()=>{};const returnFromMerchantEmployeePerformance=()=>{};const mobileHttpResultRequiresMerchantLogin=()=>false;
const onLoad=(fn:any)=>setTimeout(()=>fn({employeeId:99101,detailMode:'labor',periodMode:'custom',startDate:'2026-10-22',endDate:'2026-10-22'}),0);
const queryMobileWarehouseEmployeePerformance=async (_input:any,cb:any)=>{const data=await(await fetch('/fixture')).json();cb({ok:true,body:data[new URLSearchParams(location.search).get('mode')||'1']});};
`;
  source=source.replace('<script setup lang="ts">','<script setup lang="ts">'+mocks);
  // 浏览器适配仅替换原生标签与 tap，不改组件业务条件或接口值。
  return source.replace(/(<\/?)(scroll-view|view|text|picker)(?=[\s>])/g,(_,p,tag)=>p+(tag==='text'?'span':'div')).replace(/@tap/g,'@click').replace(/\btext(?=[:\s{>,])/g,'span');
 }
},vue()]});
await server.listen();
console.log('Round8 isolated component: http://127.0.0.1:18119/?mode=1');
