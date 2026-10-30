// Exercise actual rights parsing and preserve authoritative booking submission methods.
const assert=require('assert/strict'),fs=require('fs'),cp=require('child_process'),load=require('../r15-reservation-remove/harness.cjs');
const h=load(),p=h.create({cartInfoId:16711,addonServiceDuration:20});
const row={id:77,product_type:6,product_id:42,write_surplus_times:3,is_writeoff:0,cart_info:{productInfo:{store_name:'完整项目名称',addon_service_duration:30,attrInfo:{suk:'完整规格'}}}};
let result=p.parseCartAddonRows({id:8},[row],'来源体验卡')[0];assert.equal(result.displayName,'完整项目名称（来源体验卡） / 完整规格');assert.equal(result.remainingText,'剩余3次');assert.equal(result.durationText,'30分钟');assert(result.label.endsWith('（余3次）'));assert.equal(result._key,'addon_8_77');
row.display_name='已购项目历史名称';result=p.parseCartAddonRows({id:8},[row],'来源体验卡')[0];assert.equal(result.displayName,'已购项目历史名称 / 完整规格');assert.equal(result.remainingText,'剩余3次');
assert.equal(p.parseCartAddonRows({id:8},[{...row,write_surplus_times:0}],'').length,0);
const before=cp.execFileSync('git',['show','96fe153a:前端代码/uniapp/pages/activity/reservation/index.vue'],{encoding:'utf8'});const parser=require('/Applications/HBuilderX.app/Contents/HBuilderX/plugins/uniapp-cli/node_modules/@babel/parser');
function methods(source){const script=h.compiler.parseComponent(source).script.content,ast=parser.parse(script,{sourceType:'module'}),exp=ast.program.body.find(n=>n.type==='ExportDefaultDeclaration').declaration;return Object.fromEntries(exp.properties.find(n=>n.key.name==='methods').value.properties.map(n=>[n.key.name,script.slice(n.start,n.end)]));}
const prev=methods(before),next=methods(h.source);const untouched=['buildAddonPayload','reservationOrderCreate','reservationButlerUpdate','confirmAddonPicker','removeSelectedAddon','loadStaffList','loadAvailableTime'];for(const name of untouched)assert.equal(next[name],prev[name],name);
fs.writeFileSync(__dirname+'/evidence/model.json',JSON.stringify({result:'PASS',rightsNameDurationRemaining:true,unchangedMethods:untouched,productionWrites:0},null,2));console.log('PASS rights presentation and unchanged booking methods');
