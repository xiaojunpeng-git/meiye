/** 从唯一工作树构建；基线证明仅以内存中的既有Git blob替换本轮工资页面，不生成第二份源码。 */
const fs=require('fs'),path=require('path'),{execFileSync}=require('child_process')
const root=path.resolve(__dirname,'../..'),frontend=path.join(root,'前端代码/cashier-v3')
const variant=process.argv[2];if(!['baseline','candidate'].includes(variant))throw Error('Explicit build variant required')
const {build}=require(path.join(frontend,'node_modules/vite'))
const output='/tmp/beauty-round9-'+variant+'-cashier'
const target=path.join(frontend,'src/views/BasicReportsView.vue')
const baseline=execFileSync('git',['show','59f7cdc8^:前端代码/cashier-v3/src/views/BasicReportsView.vue'],{cwd:root,encoding:'utf8'})
process.chdir(frontend)
build({root:frontend,configFile:path.join(frontend,'vite.config.js'),build:{outDir:output,emptyOutDir:true},plugins:variant==='baseline'?[{name:'fixed-baseline-proof',enforce:'pre',load(id){if(id===target)return baseline}}]:[]}).then(()=>console.log(JSON.stringify({result:'PASS',variant,output}))).catch(e=>{console.error(e);process.exitCode=1})
