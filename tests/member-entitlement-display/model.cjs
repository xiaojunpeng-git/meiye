// Exercise the canonical Vue2 page with issued-right fixtures. No API writes,
// navigation changes or financial commands are used to validate display rules.
const fs = require('fs'), path = require('path'), assert = require('assert/strict');
const root = path.resolve(__dirname, '../..');
const deps = '/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const compiler = require(deps + '/vue-template-compiler');
const parser = require(deps + '/@babel/parser');
const Vue = require(deps + '/vue/dist/vue.common.js');
const file = '前端代码/uniapp/pages/users/user_card_list/index.vue';
const raw = fs.readFileSync(path.join(root, file), 'utf8');
const sfc = compiler.parseComponent(raw);
assert.deepEqual(compiler.compile(sfc.template.content).errors, []);
let script = sfc.script.content;
const edits = parser.parse(script, {sourceType:'module'}).program.body.flatMap(n =>
 n.type === 'ImportDeclaration' ? [[n.start,n.end,'']] :
 n.type === 'ExportDefaultDeclaration' ? [[n.start,n.declaration.start,'return ']] : []);
for (const [a,b,v] of edits.sort((a,b)=>b[0]-a[0])) script=script.slice(0,a)+v+script.slice(b);
const cards = [
 {id:6550, product_type:5, card_name:'王牌 · 粉红丝带（体验卡）',write_surplus_times:1,write_times:1,status:1,store_name:'海伦堡',write_valid:1,
  related_list:[{id:10,project_name:'粉红丝带',write_surplus_times:1}]},
 // Shared pool counters must be read from the holder, never summed from choices.
 {id:2,product_type:'5',card_name:'任选护理卡',write_surplus_times:8,write_times:10,status:1,cate_name:'护理',
  related_list:[{id:21,project_name:'面部项目',write_surplus_times:8},{id:22,project_name:'身体项目',write_surplus_times:8}]},
 {id:3,product_type:6,card_name:'五行灸（单次）',write_surplus_times:1,status:1,write_valid:1},
 // Gift issuance uses a technical type-5 holder; its own name is the project.
 {id:4,product_type:5,card_name:'赠送面部护理',write_surplus_times:2,status:1,
  related_list:[{id:41,project_name:'赠送面部护理',write_surplus_times:2}]},
 {id:5,product_type:5,card_name:'历史卡',historical_display_single:1,write_surplus_times:4,status:1},
 {id:6,product_type:5,card_name:'已用完卡',write_surplus_times:0,status:1,related_list:[{id:61,write_surplus_times:7}]},
 {id:7,card_name:'已过期项目',write_surplus_times:1,status:2},
 {id:8,card_name:'已升级卡',write_surplus_times:3,status:1,is_card_upgraded:true},
 {id:9,card_name:'未开始项目',write_surplus_times:1,status:0},
 {id:10,product_type:4,card_name:'期限卡',write_surplus_times:1,status:1,write_end:'2027-01-01'}
];
const snapshot = JSON.stringify(cards), requests=[], nav=[];
const options = new Function('userCardList','staffUserInfo','mapGetters','uni','getCurrentPages','pageFooter', script)(
 p=>{requests.push(p);return Promise.resolve({data:{list:cards}})},()=>Promise.resolve({data:{}}),
 ()=>({uid:()=>421}),{navigateTo:p=>nav.push(p.url)},()=>[],{});
const compiled = compiler.compile(sfc.template.content);
options.render = new Function(compiled.render);
options.staticRenderFns = compiled.staticRenderFns.map(s=>new Function(s));
const vm = new Vue(options);
function texts(node) {return (node.text || '') + (node.children || []).map(texts).join('');}
(async()=>{
 vm.initPage(); await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(requests,[{uid:421}]);
 assert.equal(vm.projectList.length,cards.length);
 for (const row of vm.projectList) {
  const source=cards.find(c=>c.id===row.holderId);
  assert.equal(row.name,source.card_name);assert.equal(row.remain,source.write_surplus_times);
  assert.equal(row.cartInfoId,0);vm.goProject(row);
 }
 assert.equal(nav.length,8);assert(!nav.includes('/pages/users/user_card/index?id=7'));
 assert(!nav.includes('/pages/users/user_card/index?id=8'));
 assert(nav.includes('/pages/users/user_card/index?id=6550'));
 assert.equal(vm.projectList[1].remain,8);
 assert.equal(vm.categoryGroups[0].count,1);assert.equal(vm.categoryGroups[0].totalRemain,8);
 assert.equal(vm.filteredProjects.length,6);
 const rendered=texts(vm._render());
 assert(rendered.includes('王牌 · 粉红丝带（体验卡）'));
 assert(rendered.includes('五行灸（单次）'));assert(rendered.includes('赠送面部护理'));
 assert(!rendered.includes('面部项目'));assert(!rendered.includes('身体项目'));
 vm.searchKeyword='  体验卡  ';await Vue.nextTick();assert.equal(vm.filteredProjects.length,1);
 vm.searchKeyword='面部项目';await Vue.nextTick();assert.equal(vm.hasDisplayContent,false);
 vm.searchKeyword='赠送';await Vue.nextTick();assert.equal(vm.filteredProjects[0].holderId,4);
 vm.searchKeyword='';vm.switchFilter('all');await Vue.nextTick();assert.equal(vm.filteredProjects.length,10);
 vm.switchFilter('valid');assert.equal(vm.filteredProjects.length,6);
 vm.newDataStatus(true,34);assert.equal(vm.pdHeight,34);
 assert.equal(JSON.stringify(cards),snapshot);assert.equal(requests.length,1);
 vm.$destroy();console.log('PASS: canonical Vue2 template + list/card/project/gift/shared-pool/history/filter/search/navigation/footer/input immutability');
})().catch(e=>{console.error(e);process.exitCode=1});
