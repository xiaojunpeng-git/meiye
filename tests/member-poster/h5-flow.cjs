// Execute the actual page methods: conversion races, rejection, retry and QR identity.
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync('前端代码/uniapp/pages/activity/goods_details/index.vue','utf8');
const start = source.indexOf('getImageBase64: function()');
const end = source.indexOf('// #endif', source.indexOf('generateH5Poster: async function()',start));
const methods = source.slice(start,end).replace('// #ifdef H5','');
let calls=[], mode='ok', drawn=[];
const context = {Promise, Error, clearTimeout, setTimeout,
 imageBase64: async (image,code)=>{ calls.push({image,code}); await new Promise(r=>setTimeout(r,5)); if(mode==='reject')throw Error('network failed');return {data:{image:mode==='false'?false:image?'data:image/png;base64,PRODUCT':false,code:code?'data:image/png;base64,QR':false}}; }};
const actual = vm.runInNewContext('({'+methods+'})',context);
function page(){return {...actual,storeInfo:{image:'https://media.example.com/product.png',title:'测试',price:1,product_price:1980},PromotionCode:'data:image/png;base64,LOCALQR',canvasStatus:false,posters:true,posterImageStatus:false,type:3,$nextTick:async()=>{},$util:{Tips:x=>drawn.push({error:x.title}),PosterCanvas:(...args)=>{drawn.push(args.slice(0,8)); if(mode==='drawFail')args[9]();else args[8]('data:image/png;base64,POSTER');}}};}
(async()=>{
 let p=page(); const preload=p.getImageBase64(); await p.generateH5Poster(); await preload;
 assert.equal(calls.length,1); assert.equal(p.posterImageStatus,true); assert.equal(p.canvasStatus,false); assert.equal(drawn[0][3][1],'data:image/png;base64,PRODUCT'); assert.equal(drawn[0][3][2],p.PromotionCode);
 mode='false';calls=[];drawn=[];p=page();await p.generateH5Poster();assert.equal(p.posters,false);assert.equal(p.storeImage,undefined);assert.equal(p._h5PosterPending,false);assert.equal(p.canvasStatus,false);assert.match(drawn[0].error,/商品图片/);
 mode='ok';p.posters=true;await p.generateH5Poster();assert.equal(p.posterImageStatus,true);assert.equal(calls.length,2);
 mode='reject';drawn=[];p=page();await p.generateH5Poster();assert.equal(p.canvasStatus,false);assert.equal(p.posters,false);
 mode='drawFail';drawn=[];p=page();await p.generateH5Poster();assert.equal(p.canvasStatus,false);assert.equal(p._h5PosterPending,false);assert.match(drawn[1].error,/生成失败/);
 mode='ok';calls=[];p=page();p.PromotionCode='https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=123';await p.generateH5Poster();assert.equal(calls[1].code,p.PromotionCode);assert.equal(p.posterImageStatus,true);
 p=page();p.PromotionCode='';await p.generateH5Poster();assert.equal(p.posters,false);assert.equal(p.canvasStatus,false);
 console.log('H5 POSTER PASS: preloading race, invalid result, retry, network failure, canvas failure, remote QR, missing QR');
})().catch(e=>{console.error(e);process.exitCode=1});
