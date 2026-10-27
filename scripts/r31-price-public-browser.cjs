// Boot the real deployed JS in fresh, unauthenticated browser contexts.
// This checks formal/preview entries without touching any operator's session.
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto')
const modules = process.env.CODEX_NODE_MODULES || '/Users/xiaojunpeng/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules'
const {chromium} = require(path.join(modules, 'playwright'))
const site = process.argv[2]
if (!['rh','008','010'].includes(site)) throw Error('Explicit supported site required')
const evidence = path.resolve(__dirname, '../tests/cashier-v3/evidence/R31-sale-price-range/release')
;(async () => {
  const browser = await chromium.launch({channel:'chrome',headless:true,args:['--no-proxy-server','--disable-http2']})
  const result=[]
  try {
    const urls=[`https://${site==='rh'?'rh':site}.cc3798.com/view_cashier_v3/#/reservation`]
    if(site==='rh')urls.push('https://rh.cc3798.com/preview-8080/view_cashier_v3/#/reservation')
    urls.push(`https://${site==='rh'?'rh':site}.cc3798.com/admin/login`)
    for(const [index,url] of urls.entries()) {
      const context=await browser.newContext({viewport:{width:1515,height:1009}})
      // Chrome直连在本机间歇超时；用同一公共URL的HTTP传输执行原始生产脚本，不改页面内容。
      await context.route('https://**.cc3798.com/**',async route=>{try{const response=await route.fetch({timeout:30000});await route.fulfill({response});}catch(e){await route.abort();}})
      const page=await context.newPage(),errors=[]
      page.on('pageerror',e=>errors.push(e.message))
      await page.goto(url,{waitUntil:'domcontentloaded',timeout:60000})
      await page.locator('input[type=password]').waitFor({timeout:30000})
      if(errors.length)throw Error(JSON.stringify(errors))
      const after=JSON.parse(fs.readFileSync(path.join(evidence,site+'-after.json')))
      const preview=url.includes('/preview-8080/')
      const kind=url.includes('/admin/')?'admin':'cashier'
      const front=after.frontends.find(f=>f.kind===kind&&f.root.endsWith('-8080')===preview)
      if(!front)throw Error('Expected frontend manifest required')
      for(const [asset,expected]of Object.entries(front.assets)){
        const publicUrl=new URL((preview?'/preview-8080':'')+asset,url).href
        const response=await page.request.get(publicUrl,{timeout:30000})
        if(response.status()!==200||crypto.createHash('sha256').update(await response.body()).digest('hex')!==expected)throw Error('Public asset mismatch: '+publicUrl)
      }
      await page.screenshot({path:path.join(evidence,`${site}-public-entry-${index}.png`)})
      result.push({commit:JSON.parse(fs.readFileSync(path.join(evidence,'commit.json'))).commit,transport:'live public HTTP through Playwright request transport',url,finalUrl:page.url(),passwordInputs:await page.locator('input[type=password]').count(),runtimeErrors:errors,result:'PASS'})
      await context.close()
    }
    fs.writeFileSync(path.join(evidence,site+'-public-browser.json'),JSON.stringify(result,null,2))
    console.log(site+' deployed frontend boot PASS ('+result.length+' entries)')
  } finally {await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1})
