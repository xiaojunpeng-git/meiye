// Local validation uses the project's accepted Node16 platform toolchain; not an authorized 8080 release.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve(__dirname,'../..'),app=path.join(root,'前端代码/admin'),tag=process.env.R12_BUILD_TAG||'final';
if(!/^[a-z0-9-]+$/.test(tag))throw Error('Invalid admin build tag');
const output='/tmp/r12-survey-admin-'+tag;
const evidence=process.env.R12_EVIDENCE_DIR?path.resolve(root,process.env.R12_EVIDENCE_DIR):path.join(root,'tests/customer-010/evidence/round12-member-survey');if(!evidence.startsWith(path.join(root,'tests/customer-010/evidence')+path.sep))throw Error('Invalid evidence output');fs.mkdirSync(evidence,{recursive:true});
// 共享平台整包同时固定已上线员工页基线，不能只记录本轮问卷页面。
const files=['src/pages/user/survey/index.vue','src/components/memberSurveyReward/index.vue','src/components/rechargeGiftConfig/index.vue','src/components/goodsAttr/index.vue','src/utils/memberSurveyExcel.js','src/utils/requiredGuidance.js','src/api/memberSurvey.js','src/router/modules/user.js','src/pages/setting/staff/add.vue','src/pages/setting/staff/index.vue'];
const inputs=()=>Object.fromEntries(files.map(file=>[file,crypto.createHash('sha256').update(fs.readFileSync(path.join(app,file))).digest('hex')]));const startInputs=inputs();
if(!process.version.startsWith('v16.'))throw Error('Use accepted Node16 platform toolchain');
// Rebuild the complete platform bundle from the merged source, including already deployed R15 pages.
fs.rmSync(output,{recursive:true,force:true});
Object.assign(process.env,{NODE_ENV:'production',ANALYZE:'false',VUE_CLI_CONTEXT:app});process.chdir(app);
const Service=require(path.join(app,'node_modules/@vue/cli-service/lib/Service')),service=new Service(app);service.init('production');service.projectOptions.parallel=false;
service.webpackChainFns.push(config=>{const disable=rule=>{for(const name of ['cache-loader','thread-loader','eslint-loader'])if(rule.uses.has(name))rule.uses.delete(name);for(const nested of rule.oneOfs.values())disable(nested);};config.module.rules.values().forEach(disable);config.module.rules.delete('eslint');config.cache(false);});
service.webpackRawConfigFns.push(config=>{for(const plugin of [...(config.plugins||[]),...((config.optimization||{}).minimizer||[])])if(plugin.options)for(const key of ['parallel','cache'])if(key in plugin.options)plugin.options[key]=false;});
service.run('build',{mode:'production',dest:output}).then(()=>{if(JSON.stringify(startInputs)!==JSON.stringify(inputs()))throw Error('Survey source changed during build');const html=path.join(output,'system.html');if(!fs.existsSync(html))throw Error('Missing actual output');const result={passed:true,source:app,base_head:cp.execFileSync('git',['rev-parse','HEAD'],{cwd:root}).toString().trim(),node:process.version,source_inputs:startInputs,output,html_sha256:crypto.createHash('sha256').update(fs.readFileSync(html)).digest('hex'),release:false};fs.writeFileSync(path.join(evidence,'admin-build.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));}).catch(e=>{console.error(e);process.exitCode=1;});
