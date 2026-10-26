const fs=require('fs'),path=require('path'),crypto=require('crypto');
const {chromium}=require('/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const site=process.argv[2],phase=process.argv[3]||'after';
if(!['rh','008','010'].includes(site)||!['before','after'].includes(phase))throw Error('Explicit site/phase required');
const evidence=path.resolve('tests/target-scheme/evidence/R95-excel-names-2026-10-24/release');
// Fresh browser contexts only. No stored operator cookies, logins, or business
// commands; this checks actual published runtime/assets without submitting forms.
(async()=>{
    const browser=await chromium.launch({channel:'chrome',headless:true,args:['--no-proxy-server','--disable-http2']});
    const urls=[{url:`https://${site}.cc3798.com/admin/login`,preview:false}];
    // Admin's router base is /admin/. The existing preview cookie selects its
    // server package while retaining this URL; a prefixed URL is not a route.
    if(site==='rh')urls.push({url:'https://rh.cc3798.com/admin/login',preview:true});
    const results=[];
    try{
        for(const [index,{url,preview}]of urls.entries()){
            const context=await browser.newContext({viewport:{width:1579,height:1076}});
            if(preview)await context.addCookies([{name:'rh_preview_8080',value:'1',domain:'rh.cc3798.com',path:'/'}]);
            const transportErrors=[];
            await context.route('https://**.cc3798.com/**',async route=>{try{const response=await route.fetch({timeout:30000});await route.fulfill({response});}catch(e){transportErrors.push({url:route.request().url(),message:e.message});await route.abort();}});
            const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
            await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000});
            try{await page.locator('input[type=password]').waitFor({timeout:30000});}
            catch(error){
                // Preserve actual page/transport evidence on failure; a timeout
                // is never recorded as a successful deployment check.
                fs.writeFileSync(path.join(evidence,`${site}-${phase}-public-failure.json`),JSON.stringify({url:page.url(),errors,transportErrors,body:await page.locator('body').innerText()},null,2));
                await page.screenshot({path:path.join(evidence,`${site}-${phase}-public-failure.png`)});
                throw error;
            }
            if(errors.length)throw Error(JSON.stringify(errors));
            const state=JSON.parse(fs.readFileSync(path.join(evidence,site+'-'+phase+'.json')));
            const front=state.frontends.find(f=>f.kind==='admin'&&f.root.endsWith('-8080')===preview);
            for(const [asset,expected]of Object.entries(front.assets)){
                const response=await page.request.get(new URL((preview?'/preview-8080':'')+asset,url).href,{timeout:30000});
                if(response.status()!==200||crypto.createHash('sha256').update(await response.body()).digest('hex')!==expected)throw Error('Public asset mismatch '+asset);
            }
            await page.screenshot({path:path.join(evidence,`${site}-${phase}-public-${index}.png`)});
            results.push({url,preview,finalUrl:page.url(),passwordInputs:await page.locator('input[type=password]').count(),runtimeErrors:errors,result:'PASS'});await context.close();
        }
        fs.writeFileSync(path.join(evidence,site+'-'+phase+'-public.json'),JSON.stringify(results,null,2));console.log(JSON.stringify(results));
    }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
