// Freeze only this local closure; never stage, commit, build or connect to production.
const fs=require('fs'),path=require('path'),crypto=require('crypto'),cp=require('child_process');
const root=path.resolve(__dirname,'../..');
const business=[
 '前端代码/admin/src/utils/diyProductTypes.js',
 '前端代码/admin/src/components/mobileConfigRight/c_catalog_types.vue',
 '前端代码/admin/src/components/mobilePage/home_goods_list.vue',
 '前端代码/admin/src/components/mobilePage/home_product.vue',
 '前端代码/admin/src/components/mobileConfig/c_home_goods_list.vue',
 '前端代码/admin/src/components/mobileConfig/c_home_product.vue',
 '前端代码/admin/src/components/mobileConfigRight/c_promotion.vue',
 '前端代码/admin/src/components/mobileConfigRight/c_goods.vue',
 '前端代码/admin/src/components/goodsList/index.vue',
 '前端代码/uniapp/pages/index/components/goodList.vue',
 '前端代码/uniapp/pages/index/components/promotionList.vue',
 '后端代码/app/services/diy/DiyProductTypeFilter.php',
 '后端代码/app/controller/admin/v1/diy/Diy.php',
 '后端代码/app/controller/admin/v1/product/StoreProduct.php',
 '后端代码/app/controller/api/v1/product/StoreProduct.php'
];
const tests=['source-harness.cjs','contract.cjs','api-contract.php','mysql.php','serve.cjs','verify.cjs','README.md'].map(f=>'tests/diy-product-types/'+f);
const hash=v=>crypto.createHash('sha256').update(v).digest('hex');
function run(args,input) {
 const result=cp.spawnSync(args[0],args.slice(1),{cwd:root,input,encoding:'utf8',timeout:180000,maxBuffer:4e6});
 if(result.error||result.status!==0)throw Error(args.join(' ')+'\n'+result.stdout+'\n'+result.stderr+'\n'+(result.error||''));
 return result.stdout.trim();
}
function php(file){const out=run(['docker','exec','-i','mohe-app','timeout','120','php'],fs.readFileSync(path.join(root,file)));const result=out.split('\n').filter(s=>s.startsWith('{')).map(s=>JSON.parse(s)).pop();if(!result||result.status!=='PASS')throw Error(out);return result;}
const baseline=run(['git','rev-parse','HEAD']);
const results={};
for(const file of business.filter(f=>f.endsWith('.php'))) {
 const lint=run(['docker','exec','mohe-app','php','-l','/var/www/html/'+file.slice('后端代码/'.length)]);
 if(!lint.includes('No syntax errors'))throw Error(lint);
}
results.phpLint='4 files PASS';
results.components=JSON.parse(run(['node','tests/diy-product-types/contract.cjs']));
results.api=php('tests/diy-product-types/api-contract.php');
results.mysql=php('tests/diy-product-types/mysql.php');
results.browser=JSON.parse(fs.readFileSync(path.join(__dirname,'evidence/browser-result.json'),'utf8'));
if(results.browser.status!=='PASS')throw Error('Browser verification missing');
run(['git','diff','HEAD','--check','--',...business,...tests]);
if(run(['git','rev-parse','HEAD'])!==baseline)throw Error('Baseline moved during test');
const files=[...business,...tests].sort().map(file=>({file,sha256:hash(fs.readFileSync(path.join(root,file)))}));
const fingerprint=hash(JSON.stringify({baseline,files}));
const dependencies=['后端代码/app/services/product/product/StoreProductServices.php','后端代码/app/dao/product/product/StoreProductDao.php','后端代码/app/model/product/product/StoreProduct.php'].map(file=>{
 const head=cp.execFileSync('git',['show','HEAD:'+file],{cwd:root});
 return {file,sha256:hash(fs.readFileSync(path.join(root,file))),headSha256:hash(head)};
});
const receipt={task:'M008-R4-001',status:'SELF_TEST_PASS_AWAITING_PM_ACCEPTANCE',baseline,fingerprint,files,businessFiles:business.length,testAndRecordFiles:tests.length,dependencies,results,
 online:'Not applicable: local only; no remote connection, deployment or saved online decoration',
 boundaries:{commit:false,push:false,deployed:false,miniProgramUploaded:false,unrelatedChangesPreserved:true,fullBundleBuilt:false}};
fs.mkdirSync(path.join(__dirname,'evidence'),{recursive:true});
fs.writeFileSync(path.join(__dirname,'evidence/verification.json'),JSON.stringify(receipt,null,2)+'\n');
console.log(JSON.stringify({status:'PASS',baseline,fingerprint,businessFiles:business.length,testAndRecordFiles:tests.length,mysql:results.mysql}));
