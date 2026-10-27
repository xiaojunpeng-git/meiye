// 构建期身份覆盖只在内存生效；源码清单和其他客户配置保持原样。
const fs=require('fs'),path=require('path');
const input=process.env.PAYROLL_MINI_INPUT,appid=process.env.PAYROLL_MINI_APPID;
if(!input||!['wx29d9b63c92555701','wx14666197911d052b','wxd92c6d010bd6bf01'].includes(appid))throw Error('Explicit approved merchant identity required');
const target=path.join(input,'manifest.json'),read=fs.readFileSync.bind(fs),manifest=JSON.parse(read(target,'utf8'));
manifest['mp-weixin'].appid=appid;manifest.versionName='0.66.0';manifest.versionCode='66';
const bytes=Buffer.from(JSON.stringify(manifest));const match=f=>typeof f==='string'&&path.resolve(f)===target;
const convert=o=>(typeof o==='string'||o?.encoding)?bytes.toString(typeof o==='string'?o:o.encoding):Buffer.from(bytes);
fs.readFileSync=(f,o)=>match(f)?convert(o):read(f,o);
const asyncRead=fs.readFile.bind(fs);fs.readFile=(f,o,cb)=>{if(typeof o==='function'){cb=o;o=undefined;}if(match(f))return process.nextTick(()=>cb(null,convert(o)));return asyncRead(f,o,cb)};
const promise=fs.promises.readFile.bind(fs.promises);fs.promises.readFile=async(f,o)=>match(f)?convert(o):promise(f,o);
