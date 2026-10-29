// Local-only preview of the immutable compiled H5 app. All API calls use
// deterministic fixtures; only image/static resources may leave localhost.
const http=require('http'),fs=require('fs'),path=require('path');
const root='/private/tmp/m015-project-booking-final-008-h5/output';
const fixture=String.raw`
(function(){
const put=(key,data)=>localStorage.setItem(key,typeof data==='string'?data:JSON.stringify({type:typeof data,data}));
put('LOGIN_STATUS_TOKEN','M015-LOCAL-ONLY');put('UID',1);put('EXPIRES_TIME',Math.round(Date.now()/1000)+3600);
put('USER_INFO',{uid:1,phone:'13800000000',nickname:'Local test'});put('BASIC_CONFIG',{mobile_reservation_switch:1});put('viewColor','--view-theme:#7b2941;--view-gradient:#9d5366;');
window.M015Requests=[];
const open=XMLHttpRequest.prototype.open,send=XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.open=function(method,url,...a){this._m015url=String(url);this._m015method=method;return open.call(this,method,url,...a)};
XMLHttpRequest.prototype.send=function(...a){if(!this._m015url.includes('/api/'))return send.apply(this,a);
if(!['GET','POST'].includes(this._m015method))throw Error('Unexpected API method');
window.M015Requests.push({url:this._m015url,method:this._m015method});
const gift=new URLSearchParams(location.search).get('id')==='999';const image='https://myqiniu.cc3798.com/attach/2026/10/cdb38202610242231172909.jpg';
let data={mobile_reservation_switch:1};
if(this._m015url.includes('v2/card/holder/'))data={id:6538,oid:6540,product_id:2768,product_type:gift?5:6,card_name:gift?'赠送护理项目':'八倍水动力（单次）',store_name:'海伦堡',store_image:'',write_times:1,write_surplus_times:1,status:1,write_valid:1,image,card_cover_image:'',verify_code:'LOCAL'};
else if(this._m015url.includes('card/order/benefits/'))data=[{id:16651,product_id:2768,product_type:6,write_times:1,write_surplus_times:1,waiting_service:0,cart_info:{productInfo:{store_name:'八倍水动力（单次）',attrInfo:{image}}}}];
else if(this._m015url.includes('reservation/orderInfo/'))data={id:6540,cart_info_id:16651,cart_num:1,project_service_duration:60,product_id:2768,product_name:'八倍水动力（单次）',unique:'test-sku',sku:'单次',store_id:108,store_name:'海伦堡',custom_form:[],system_from_type:1};
else if(this._m015url.includes('config'))data={mobile_reservation_switch:1};
else if(this._m015url.endsWith('/user'))data={uid:1,phone:'13800000000',nickname:'Local test'};
const text=JSON.stringify({status:200,data});setTimeout(()=>{for(const[k,v]of Object.entries({readyState:4,status:200,responseText:text,response:this.responseType==='json'?JSON.parse(text):text}))Object.defineProperty(this,k,{configurable:true,get:()=>v});this.dispatchEvent(new Event('readystatechange'));this.dispatchEvent(new ProgressEvent('load'));this.dispatchEvent(new ProgressEvent('loadend'));},10);
};
})();`;
const types={'.js':'application/javascript','.css':'text/css','.html':'text/html','.jpg':'image/jpeg','.png':'image/png','.ttf':'font/ttf'};
http.createServer((req,res)=>{try{const name=decodeURIComponent(new URL(req.url,'http://127.0.0.1').pathname);if(name==='/review'){const q=new URL(req.url,'http://127.0.0.1').searchParams,w=Number(q.get('width'))||375,id=q.get('id')||'6538';res.setHeader('Content-Type','text/html');return res.end('<!doctype html><meta charset=utf-8><title>M015 本地验收</title><body style="background:#eee;text-align:center;font:14px sans-serif"><p>本地测试数据 · '+w+'px · <a href="/review?width=320&id='+id+'">320</a> <a href="/review?width=375&id='+id+'">375</a> <a href="/review?width=430&id='+id+'">430</a> <a href="/review?width='+w+'&id=999">赠送项目</a></p><iframe id="preview" style="border:1px solid #ddd;width:'+w+'px;height:812px" src="/pages/users/user_card/index?id='+id+'"></iframe>');}if(name==='/m015-fixture.js'){res.setHeader('Content-Type','application/javascript');return res.end(fixture)}let f=path.resolve(root,'.'+name);if(!f.startsWith(root+'/'))return res.writeHead(403).end();if(name.startsWith('/pages/')||name==='/')f=path.join(root,'index.html');if(!fs.existsSync(f)||!fs.statSync(f).isFile())return res.writeHead(404).end();res.setHeader('Content-Type',types[path.extname(f)]||'application/octet-stream');res.setHeader('Cache-Control','no-store');if(f.endsWith('index.html'))return res.end(fs.readFileSync(f,'utf8').replace('<head>','<head><script src="/m015-fixture.js"></script>'));fs.createReadStream(f).pipe(res);}catch(e){res.writeHead(500).end(e.message)}}).listen(19815,'127.0.0.1',()=>console.log('M015_LOCAL_FIXTURES http://127.0.0.1:19815/pages/users/user_card/index?id=6538'));
