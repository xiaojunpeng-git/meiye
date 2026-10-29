// Consume actual backend resolver DTOs in the canonical Vue2 list. Classification
// only changes grouping; holder identities, total times and detail URLs persist.
const fs=require('fs'),assert=require('assert/strict');
const deps='/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules';
const Vue=require(deps+'/vue/dist/vue.common.js'),parser=require(deps+'/@babel/parser'),compiler=require(deps+'/vue-template-compiler');
const sfc=compiler.parseComponent(fs.readFileSync('前端代码/uniapp/pages/users/user_card_list/index.vue','utf8'));assert.deepEqual(compiler.compile(sfc.template.content).errors,[]);
let code=sfc.script.content;const edits=parser.parse(code,{sourceType:'module'}).program.body.flatMap(n=>n.type==='ImportDeclaration'?[[n.start,n.end,'']]:n.type==='ExportDefaultDeclaration'?[[n.start,n.declaration.start,'return ']]:[]);for(const[a,b,v]of edits.sort((a,b)=>b[0]-a[0]))code=code.slice(0,a)+v+code.slice(b);
const nav=[],options=new Function('pageFooter','userCardList','staffUserInfo','mapGetters','uni','getCurrentPages',code)({},()=>{},()=>{},()=>({uid:()=>1}),{navigateTo:p=>nav.push(p.url)},()=>[]);
const vm=new Vue(options),line=fs.readFileSync('tests/member-card-category/evidence/mysql.log','utf8').split('\n').find(s=>s.startsWith('MEMBER_CATEGORY_DTO='));assert(line,'Backend DTO evidence required');const data=JSON.parse(line.slice('MEMBER_CATEGORY_DTO='.length));
vm.cardList=data.child;assert.deepEqual(vm.categoryGroups.map(g=>g.name),['面部','身体']);assert.equal(vm.uncategorizedProjects.length,1);assert.equal(vm.projectList.length,3);assert.equal(vm.categoryGroups.reduce((n,g)=>n+g.totalRemain,0),2);
vm.cardList=data.parent;assert.deepEqual(vm.categoryGroups.map(g=>g.name),['美容项目']);assert.equal(vm.categoryGroups[0].count,2);assert.equal(vm.categoryGroups[0].totalRemain,2);assert.equal(vm.uncategorizedProjects.length,1);
vm.projectList.forEach(p=>vm.goProject(p));assert.deepEqual(nav,['/pages/users/user_card/index?id=101','/pages/users/user_card/index?id=102','/pages/users/user_card/index?id=104']);assert.deepEqual(vm.projectList.map(p=>p.remain),[1,1,1]);vm.$destroy();console.log('PASS actual backend DTO -> canonical Vue2 child/parent groups; no duplicate or hidden rights; original detail IDs/counts retained');
