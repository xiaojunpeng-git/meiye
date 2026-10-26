// Exercise real local HTTP routing with invalid, nonfinancial callbacks. They
// must reach the verifier and fail, rather than return the platform SPA's 200.
import assert from 'node:assert/strict';
import http from 'node:http';
const read=path=>new Promise((resolve,reject)=>{const req=http.request('http://127.0.0.1:8080'+path,{method:'POST',timeout:10000},res=>{let body='';res.on('data',v=>body+=v);res.on('end',()=>resolve({status:res.statusCode,body}));});req.on('error',reject);req.on('timeout',()=>req.destroy(new Error('timeout')));req.end();});
for(const path of ['/api/pay/fynotify/wechat/product','/admin/api/pay/fynotify/wechat/product','/admin/api/pay/fynotify/product']){
 const res=await read(path);
 assert.equal(res.status,500,path);assert.equal(res.body,'fail',path);
 console.log('PASS signed callback guard reached: '+path);
}
