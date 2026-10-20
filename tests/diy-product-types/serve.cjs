// Local canonical-component fixture. No remote network, credentials or business writes.
const http=require('http'),fs=require('fs'),path=require('path');
const {root,deps,source,options,helpers}=require('./source-harness.cjs');
const stylus=require(path.join(deps,'stylus')),postcss=require(path.join(deps,'postcss'));
const sass=require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/compile-dart-sass/node_modules/sass');
const sources={},styles=[];
for (const file of ['static/css/unocss.css','static/css/base.css']) {
  const css=fs.readFileSync(path.join(root,'前端代码/uniapp',file),'utf8');
  const ast=postcss.parse(css);ast.walkAtRules('import',rule=>rule.remove());
  ast.walkRules(rule=>{if(!rule.parent.name||!/keyframes/i.test(rule.parent.name))rule.selectors=rule.selectors.map(s=>'.member '+s)});
  styles.push(ast.toString().replace(/100vw/g,'var(--phone-width)').replace(/(-?[\d.]+)rpx/g,(_,n)=>'calc('+n+' * var(--rpx))'));
}
for(const key of ['selector','listPreview','tabPreview','promotion','member']) {
  const c=source(key);sources[key]={script:c.script,template:c.template};
  let css=c.styles.map(s=>s.lang==='stylus'?stylus.render(s.content):s.lang==='scss'?sass.renderSync({data:s.content}).css.toString():s.content).join('\n');
  const ast=postcss.parse(css);ast.walkRules(rule=>{rule.selectors=rule.selectors.map(s=>'.'+key+' '+s);});
  styles.push(ast.toString().replace(/100vw/g,'var(--phone-width)').replace(/(-?[\d.]+)rpx/g,(_,n)=>'calc('+n+' * var(--rpx))'));
}
const rows=[0,5,6].map((type,i)=>({id:101+i,product_type:type,store_name:['修护精华（产品）','美容项目卡（卡项）','颈部护理（项目）'][i],
 image:'/asset/product.png',price:198+i*100,sales:20,star:5,activity_frame:{},store_label:[],vip_price:0,cart_button:0}));
const list=options('listPreview').data.call({num:0}).defaultConfig;
list.goodsList={ids:rows.map(r=>r.id),list:rows};list.checkboxInfo.type=[0,2];list.cartConfig.tabVal=1;
const tabs=options('tabPreview').data.call({num:1}).defaultConfig;
const tab=tabs.tabConfig.list[0];tab.goodsList={ids:rows.map(r=>r.id),list:rows};tab.chiild[0].val='推荐';
tabs.tabConfig.list.push(JSON.parse(JSON.stringify(tab)));tabs.tabConfig.list[1].chiild[0].val='项目';tabs.tabConfig.list[1].catalogTypes.type=[6];
const html=`<!doctype html><html lang="zh-CN"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>商品类型组件本地验证</title>
<link rel="stylesheet" href="/iview.css"><style>*{box-sizing:border-box;letter-spacing:0}body{margin:0;background:#f4f6f8;color:#303133;font:14px system-ui}main{padding:24px;max-width:1240px;margin:auto}h1{font-size:20px;margin:0 0 20px}h2{font-size:15px;margin:0 0 14px}h3{font-size:13px;margin:16px 0 8px}.toolbar{display:flex;gap:12px;margin-bottom:20px;align-items:center;flex-wrap:wrap}.layout{display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start}.settings{width:400px;background:white;padding:20px 0;max-width:100%}.phone{width:375px;max-width:100%;--rpx:.5px;background:white;--view-theme:#1976d2;--view-gradient:#20b8a9}.preview{width:375px;max-width:100%;background:white}.acea-row{display:flex;flex-wrap:wrap}.row-right{justify-content:flex-end}.row-middle{align-items:center}.row-center-wrapper{align-items:center;justify-content:center}.member uni-view{display:block}.member img{object-fit:cover;max-width:100%}.member .flex{display:flex}.member .flex-col{display:flex;flex-direction:column}.member .flex-1{flex:1}.member .flex-center,.member .flex-between-center,.member .flex-y-center{display:flex;align-items:center}.member .flex-between-center{justify-content:space-between}.member .line2{overflow:hidden}.settings .c_product{padding:0 15px}select,button{padding:5px 10px}output{display:block;padding:12px;background:#eef5fb;margin:12px 0}.member .money{color:#1976d2}.catalog-types{width:100%}.settings .catalog-types{padding:0}.settings .catalog-types__label{width:70px}.settings .color-box{width:100%}.settings .conter{max-width:100%}.settings .c_row-item{display:flex;flex-wrap:wrap;gap:8px}
${styles.join('\n')}
.settings{width:300px}.layout{gap:20px}.settings .catalog-types__label{width:70px}
</style><script src="/vue.js"></script><script src="/iview.js"></script>
<main id="app"><h1>商品类型组件本地验证</h1><div class="toolbar"><button @click="mode='list'">商品列表</button><button @click="mode='tab'">商品选项卡</button><label>组件宽度 <select v-model.number="width"><option :value="375">375</option><option :value="320">320</option></select></label><button @click="save">保存测试草稿</button><button @click="legacy">加载旧配置</button></div>
<div class="layout"><section class="settings"><h2 style="padding:0 15px">{{mode==='list'?'商品列表':'商品选项卡'}} · 内容</h2><div v-if="mode==='list'" style="padding:0 15px" class="selector"><type-selector :config-obj="configs[0]"></type-selector><label>选择方式 <select v-model.number="configs[0].typeConfig.activeValue"><option :value="1">指定商品</option><option :value="2">指定品牌</option><option :value="3">指定分类</option><option :value="4">商品标签</option></select></label></div><div v-else class="promotion"><tab-settings :config-obj="configs[1]" config-nme="tabConfig"></tab-settings></div></section>
<section><h2>编辑器预览</h2><div class="preview" :style="{width:width+'px'}"><div v-if="mode==='list'" class="listPreview"><list-preview :num="0" :color-style="{theme:'#1976d2',gradient:'#20b8a9'}"></list-preview></div><div v-else class="tabPreview"><tab-preview :num="1" :color-style="{theme:'#1976d2',gradient:'#20b8a9'}"></tab-preview></div></div><output id="editor-count">编辑器 {{matching.length}} 项</output></section>
<section><h2>会员端组件</h2><div class="phone member" :style="{width:width+'px','--rpx':width/750+'px','--phone-width':width+'px'}"><member-list :data-config="memberConfig"></member-list></div><output id="selected-types">已选类型 {{selectionText}}</output></section></div></main><script>
${helpers}
const sources=${JSON.stringify(sources)},rows=${JSON.stringify(rows)};
const saved=JSON.parse(localStorage.getItem('diy-types-test-draft')||'null'),configs=saved||${JSON.stringify([list,tabs])};
const mapState=()=>({defaultArray(){return configs}}),mapGetters=()=>({});
Vue.prototype.$store={state:{admin:{mobildConfig:{defaultArray:configs}},app:{}},commit(){}};
Vue.prototype.$HandlePrice=(value,index)=>index?'':String(Math.round(Number(value)));
Vue.prototype.$config={LIMIT:20};
const empty={render(h){return h('div',this.$slots.default)}};
const size=value=>String(value||'').endsWith('rpx')?parseFloat(value)/2+'px':value;
const image={props:['imageSrc','width','height'],render(h){return h('img',{attrs:{src:this.imageSrc},style:{width:size(this.width),height:size(this.height)}})}};
const money={props:['money'],template:'<span class="money">￥{{money}}</span>'};
Vue.component('easy-loadimage',image);Vue.component('baseMoney',money);Vue.component('BaseTag',empty);
const getProductslist=data=>Promise.resolve({data:rows.filter(r=>!data.catalog_types||JSON.parse(data.catalog_types).includes(r.product_type))});
const getProduct=()=>Promise.resolve({data:rows});
const skuSelect={methods:Object.fromEntries(['onMyEvent','ChangeAttr','ChangeCartNumDuo','attrVal','iptCartNum','goCatNum','onstoreId'].map(n=>[n,()=>{}]))};
const mocks={mapState,mapGetters,diyProductTypeOptions,diyProductTypes,filterDiyProducts,createDiyProductTypes,skuSelect,productWindow:empty,vuedraggable:empty,goodsList:empty,uploadPictures:empty,storeLabelList:empty,getProductslist,getProduct,brandList:()=>Promise.resolve({data:[]}),cascaderListApi:()=>Promise.resolve({data:[]}),getAttr:()=>Promise.resolve({}),getCartCounts:()=>Promise.resolve({}),goShopDetail:()=>Promise.resolve()};
const components={};
for(const key of ['selector','listPreview','tabPreview','promotion','member']) {
 const extra=Object.assign({},mocks,{catalogTypes:components.selector}),s=sources[key];
 const o=new Function(...Object.keys(extra),s.script)(...Object.values(extra));
 let template=s.template.split('../../assets/images/').join('/asset/');
 if(key==='member')template=template.replace(/(<\\/?)(view|text|scroll-view|image)(?=[\\s>])/g,(_,p,t)=>p+({view:'uni-view',text:'span','scroll-view':'uni-view',image:'img'}[t]));
 components[key]=Object.assign(o,Vue.compile(template));
}
Vue.component('type-selector',components.selector);Vue.component('list-preview',components.listPreview);Vue.component('tab-preview',components.tabPreview);Vue.component('tab-settings',components.promotion);Vue.component('member-list',components.member);
window.fixture=new Vue({el:'#app',data:{configs,mode:'list',width:375},computed:{active(){return this.mode==='list'?this.configs[0]:this.configs[1].tabConfig.list[this.configs[1].tabConfig.tabCur]},matching(){return filterDiyProducts(rows,this.active)},selectionText(){return JSON.stringify(diyProductTypes(this.active))},memberConfig(){const c=JSON.parse(JSON.stringify(this.configs[0]));c.catalogTypes=this.active.catalogTypes;c.typeConfig.activeValue=1;c.goodsList.ids=rows.map(r=>r.id);return c}},methods:{save(){localStorage.setItem('diy-types-test-draft',JSON.stringify(this.configs))},legacy(){this.$delete(this.configs[0],'catalogTypes')}}});
</script></html>`;
const server=http.createServer((req,res)=>{
 try{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/'||url.pathname==='/fixture.html'){res.setHeader('Content-Type','text/html;charset=utf-8');return res.end(html)}
 const files={'/vue.js':'vue/dist/vue.js','/iview.js':'view-design/dist/iview.min.js','/iview.css':'view-design/dist/styles/iview.css'};
 if(files[url.pathname]){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'text/javascript');return res.end(fs.readFileSync(path.join(deps,files[url.pathname])))}
 if(/^\/asset\/[a-zA-Z0-9_-]+\.png$/.test(url.pathname)){res.setHeader('Content-Type','image/png');return res.end(fs.readFileSync(path.join(root,'前端代码/admin/src/assets/images',path.basename(url.pathname))))}
 if(url.pathname==='/favicon.ico'){res.writeHead(204);return res.end()}
 res.writeHead(404);res.end();
 }catch(error){res.writeHead(500);res.end(String(error))}
});
server.listen(Number(process.env.PORT||19215),'127.0.0.1',()=>console.log('http://127.0.0.1:'+server.address().port+'/fixture.html'));
