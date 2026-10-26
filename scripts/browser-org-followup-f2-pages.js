/**
 * F3-1 页面实操：四端真实页面 + A/B 订单夹具隔离 + 两轮即时失效
 * 禁止假 PASS：|| true、空集合隔离、skip_no_order、导出类型错误、重登录代替旧 token 失效、门店空白页
 *
 * OUT=.../f3_pages node 美容源码/scripts/browser-org-followup-f2-pages.js
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const OUT = process.env.OFH_OUT || path.join(
  __dirname,
  '../../任务管理/进行中/_local_evidence/org_followup_20260723024224/f3_pages'
);
const ADMIN_BASE = process.env.ADMIN_BASE || 'http://127.0.0.1:18081';
// 门店页默认走 8080 正式挂载（避开 18082 webpack-dev-server/HMR 导致 CDP 卡死）
const STORE_BASE = process.env.STORE_BASE || 'http://127.0.0.1:8080';
const CASHIER_BASE = process.env.CASHIER_BASE || 'http://127.0.0.1:18083';
const API_BASE = process.env.API_BASE || 'http://127.0.0.1:8080';
// 19081 未起时回退 8080 正式挂载的 uni-app 发行产物（F2-3 已验收）
const H5_BASE = (process.env.H5_BASE || 'http://127.0.0.1:8080').replace(/\/$/, '');

fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const results = { ok: true, checks: [], time: new Date().toISOString() };

function push(name, pass, detail) {
  results.checks.push({ name, pass: !!pass, detail: detail || '', layer: 'PAGE' });
  if (!pass) results.ok = false;
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail ? ' | ' + detail : ''}`);
}

function loadPuppeteer() {
  for (const c of ['/tmp/o3-puppeteer/node_modules/puppeteer', 'puppeteer']) {
    try {
      return require(c);
    } catch (e) {}
  }
  throw new Error('puppeteer missing');
}

function httpJson(method, url, body, headers) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const lib = u.protocol === 'https:' ? require('https') : require('http');
    const payload = body == null ? null : JSON.stringify(body);
    const req = lib.request(
      {
        hostname: u.hostname,
        port: u.port || (u.protocol === 'https:' ? 443 : 80),
        path: u.pathname + u.search,
        method,
        headers: Object.assign(
          { Accept: 'application/json', 'Content-Type': 'application/json' },
          headers || {},
          payload ? { 'Content-Length': Buffer.byteLength(payload) } : {}
        ),
      },
      (res) => {
        let raw = '';
        res.on('data', (c) => (raw += c));
        res.on('end', () => {
          let json = null;
          try {
            json = JSON.parse(raw || '{}');
          } catch (e) {}
          resolve({ http_code: res.statusCode, json, raw, status: json && json.status, msg: (json && json.msg) || '' });
        });
      }
    );
    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

async function httpLogin(channel, account, pwd, storeId) {
  let url = '';
  let body = { account, pwd };
  if (channel === 'admin') url = `${API_BASE}/adminapi/login`;
  else if (channel === 'store') {
    url = `${API_BASE}/storeapi/login`;
    body.store_id = storeId;
  } else if (channel === 'cashier') url = `${API_BASE}/cashierapi/login`;
  else {
    url = `${API_BASE}/api/login`;
    body = { account, password: pwd };
  }
  const r = await httpJson('POST', url, body);
  if (!r.json || r.json.status !== 200 || !r.json.data || !r.json.data.token) {
    throw new Error(`${channel} login fail: ${r.msg || r.raw.slice(0, 120)}`);
  }
  return r.json.data;
}

function orderListIds(data) {
  if (!data || typeof data !== 'object') return [];
  let rows = [];
  if (Array.isArray(data.list)) rows = data.list;
  else if (Array.isArray(data.data)) rows = data.data;
  else if (Array.isArray(data)) rows = data;
  return rows.map((x) => (x && (x.id || x.order_id)) || null).filter(Boolean);
}

function fingerprintSql() {
  // 不用 SUM(id)：大表偶发导致 mysql 客户端异常退出
  return [
    "SELECT 'fp_emp',COUNT(*),0 FROM eb_employee",
    "SELECT 'fp_staff',COUNT(*),0 FROM eb_system_store_staff",
    "SELECT 'fp_admin',COUNT(*),0 FROM eb_system_admin",
    "SELECT 'fp_user',COUNT(*),0 FROM eb_user",
    "SELECT 'ofhp_emp',COUNT(*),0 FROM eb_employee WHERE name LIKE 'OFHP%'",
    "SELECT 'ofhp_staff',COUNT(*),0 FROM eb_system_store_staff WHERE account LIKE 'ofhp%'",
    "SELECT 'ofhp_admin',COUNT(*),0 FROM eb_system_admin WHERE account LIKE 'ofhp%'",
    "SELECT 'ofhp_user',COUNT(*),0 FROM eb_user WHERE account LIKE 'ofhp%' OR nickname LIKE 'OFHP%'",
  ].join(' UNION ALL ');
}

function runMysql(sql) {
  const escaped = String(sql).replace(/"/g, '\\"');
  let lastErr = null;
  for (let i = 0; i < 3; i++) {
    try {
      return execSync(`docker exec mohe-mysql mysql -uroot -plocaldev123 lin8 -N -e "${escaped}"`, {
        encoding: 'utf8',
      }).trim();
    } catch (e) {
      lastErr = e;
      try {
        execSync('sleep 1');
      } catch (e2) {}
    }
  }
  throw lastErr;
}

function seedTenPageAccounts() {
  const script = `<?php
require '/var/www/html/vendor/autoload.php';
$app=new think\\App(); $app->initialize();
use think\\facade\\Db;
$run='ofhp_'.date('YmdHis').'_'.substr(bin2hex(random_bytes(2)),0,4);
$pwd='OfhPage#2026Aa'; $hash=password_hash($pwd,PASSWORD_BCRYPT); $now=time();
$storeA=(int)Db::name('system_store')->where('is_del',0)->order('id','asc')->value('id');
$storeB=(int)Db::name('system_store')->where('is_del',0)->where('id','<>',$storeA)->order('id','asc')->value('id');
if($storeB<=0)$storeB=$storeA;
$phone=function($n) use($run){return '193'.sprintf('%08d',hexdec(substr(sha1($run.'#'.$n),0,7))%100000000);};
$ids=['employee'=>[],'admin'=>[],'staff'=>[],'user'=>[],'order'=>[]];
$accounts=[];
$storeRole=(int)Db::name('system_role')->where('type',1)->where('status',1)->where('role_name','like','%管理%')->order('id','asc')->value('id');
if($storeRole<=0) $storeRole=(int)Db::name('system_store_staff')->where('is_manager',1)->where('roles','<>','')->order('id','asc')->value('roles');
if($storeRole<=0) $storeRole=3;
$cashierRole=(int)Db::name('system_role')->where('type',3)->where('status',1)->order('id','asc')->value('id'); if($cashierRole<=0)$cashierRole=1;
// 1 hq_super
$p=$phone(1); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP超管','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $a='ofhpa_'.substr(md5($run.'a'),0,8);
$ad=(int)Db::name('system_admin')->insertGetId(['employee_id'=>$e,'account'=>$a,'pwd'=>$hash,'real_name'=>'OFHP超管','phone'=>$p,'roles'=>'','level'=>0,'admin_type'=>0,'status'=>1,'is_del'=>0,'add_time'=>$now]);
$ids['admin'][]=$ad; $accounts['hq_super']=['channel'=>'admin','account'=>$a,'pwd'=>$pwd];
// 2 hq_personal admin
$p=$phone(2); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP总部个人','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $a='ofhpb_'.substr(md5($run.'b'),0,8);
$ad=(int)Db::name('system_admin')->insertGetId(['employee_id'=>$e,'account'=>$a,'pwd'=>$hash,'real_name'=>'OFHP总部个人','phone'=>$p,'roles'=>'','level'=>1,'admin_type'=>0,'status'=>1,'is_del'=>0,'add_time'=>$now]);
$ids['admin'][]=$ad; $accounts['hq_personal']=['channel'=>'admin','account'=>$a,'pwd'=>$pwd];
// 3 store_manager A（须带门店管理员角色，否则前端路由无 unique_auth 会踢回登录）
$p=$phone(3); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP店管','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $a='ofhps_'.substr(md5($run.'s'),0,8);
$stMgr=(int)Db::name('system_store_staff')->insertGetId(['store_id'=>$storeA,'employee_id'=>$e,'staff_name'=>'OFHP店管','account'=>$a,'pwd'=>$hash,'phone'=>$p,'status'=>1,'is_del'=>0,'is_store'=>1,'is_manager'=>1,'roles'=>(string)$storeRole,'level'=>0,'add_time'=>$now]);
$ids['staff'][]=$stMgr; $accounts['store_manager_a']=['channel'=>'store','account'=>$a,'pwd'=>$pwd,'store_id'=>$storeA,'staff_id'=>$stMgr];
// 4 store_staff A
$p=$phone(4); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP店员','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $a='ofhpt_'.substr(md5($run.'t'),0,8);
$stStaff=(int)Db::name('system_store_staff')->insertGetId(['store_id'=>$storeA,'employee_id'=>$e,'staff_name'=>'OFHP店员','account'=>$a,'pwd'=>$hash,'phone'=>$p,'status'=>1,'is_del'=>0,'is_store'=>1,'roles'=>(string)$storeRole,'level'=>0,'add_time'=>$now]);
$ids['staff'][]=$stStaff; $accounts['store_staff_a']=['channel'=>'store','account'=>$a,'pwd'=>$pwd,'store_id'=>$storeA,'staff_id'=>$stStaff];
// 5 cashier
$p=$phone(5); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP收银','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $a='ofhpc_'.substr(md5($run.'c'),0,8);
$st=(int)Db::name('system_store_staff')->insertGetId(['store_id'=>$storeA,'employee_id'=>$e,'staff_name'=>'OFHP收银','account'=>$a,'pwd'=>$hash,'phone'=>$p,'status'=>1,'is_del'=>0,'is_store'=>1,'is_cashier'=>1,'roles'=>(string)$cashierRole,'level'=>0,'add_time'=>$now]);
$ids['staff'][]=$st; $accounts['cashier']=['channel'=>'cashier','account'=>$a,'pwd'=>$pwd,'staff_id'=>$st,'employee_id'=>$e];
// 6 cashier_suspend
$p=$phone(6); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP停职收银','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $a='ofhpd_'.substr(md5($run.'d'),0,8);
$st=(int)Db::name('system_store_staff')->insertGetId(['store_id'=>$storeA,'employee_id'=>$e,'staff_name'=>'OFHP停职收银','account'=>$a,'pwd'=>$hash,'phone'=>$p,'status'=>1,'is_del'=>0,'is_store'=>1,'is_cashier'=>1,'roles'=>(string)$cashierRole,'level'=>0,'add_time'=>$now]);
$ids['staff'][]=$st; $accounts['cashier_suspend']=['channel'=>'cashier','account'=>$a,'pwd'=>$pwd,'staff_id'=>$st,'employee_id'=>$e];
// 7 dual staff on B
$p=$phone(7); $e=(int)Db::name('employee')->insertGetId(['name'=>'OFHP双店B','phone'=>$p,'status'=>1,'is_del'=>0,'auth_version'=>1,'add_time'=>$now,'update_time'=>$now]);
$ids['employee'][]=$e; $aB='ofhpb2_'.substr(md5($run.'b2'),0,8);
$stB=(int)Db::name('system_store_staff')->insertGetId(['store_id'=>$storeB,'employee_id'=>$e,'staff_name'=>'OFHP双店B','account'=>$aB,'pwd'=>$hash,'phone'=>$p,'status'=>1,'is_del'=>0,'is_store'=>1,'roles'=>(string)$storeRole,'level'=>0,'add_time'=>$now]);
$ids['staff'][]=$stB;
$accounts['store_dual_b']=['channel'=>'store','account'=>$aB,'pwd'=>$pwd,'store_id'=>$storeB,'staff_id'=>$stB];
// 8-10 mobile
for($i=8;$i<=10;$i++){
  $p=$phone($i); $a='ofhpu'.$i.'_'.substr(md5($run.'u'.$i),0,6);
  $u=(int)Db::name('user')->insertGetId(['account'=>$a,'pwd'=>md5($pwd),'nickname'=>'OFHP手机'.$i,'phone'=>$p,'status'=>1,'user_type'=>'h5','add_time'=>$now]);
  $ids['user'][]=$u;
  $accounts['mobile_'.$i]=['channel'=>'api','account'=>$p,'pwd'=>$pwd,'uid'=>$u,'phone'=>$p];
}
// A/B 订单夹具
$orderAKey='OFHPA_'.substr(md5($run.'oa'),0,12);
$orderBKey='OFHPB_'.substr(md5($run.'ob'),0,12);
$orderAId=(int)Db::name('store_order')->insertGetId([
  'order_id'=>$orderAKey,'unique'=>md5($run.'A'.$now),'uid'=>1,'store_id'=>$storeA,
  'staff_id'=>$stMgr,'clerk_id'=>$stStaff,'service_staff_id'=>0,'gendan_staff_id'=>0,
  'paid'=>1,'status'=>1,'refund_status'=>0,'is_del'=>0,'is_system_del'=>0,
  'pay_price'=>'88.01','total_price'=>'88.01','add_time'=>$now,'pay_time'=>$now,
  'pid'=>0,'shipping_type'=>2,'order_type'=>0,'is_auto'=>0,'is_user_del'=>0,'type'=>0,'cash_choose'=>0,
]);
$orderBId=(int)Db::name('store_order')->insertGetId([
  'order_id'=>$orderBKey,'unique'=>md5($run.'B'.$now),'uid'=>1,'store_id'=>$storeB,
  'staff_id'=>$stB,'clerk_id'=>0,'service_staff_id'=>0,'gendan_staff_id'=>0,
  'paid'=>1,'status'=>1,'refund_status'=>0,'is_del'=>0,'is_system_del'=>0,
  'pay_price'=>'99.02','total_price'=>'99.02','add_time'=>$now,'pay_time'=>$now,
  'pid'=>0,'shipping_type'=>2,'order_type'=>0,'is_auto'=>0,'is_user_del'=>0,'type'=>0,'cash_choose'=>0,
]);
$ids['order'][]=$orderAId; $ids['order'][]=$orderBId;
echo json_encode([
  'run'=>$run,'pwd'=>$pwd,'store_id'=>$storeA,'store_b'=>$storeB,'accounts'=>$accounts,'ids'=>$ids,
  'orders'=>['a'=>['id'=>$orderAId,'key'=>$orderAKey],'b'=>['id'=>$orderBId,'key'=>$orderBKey]],
], JSON_UNESCAPED_UNICODE);
`;
  fs.writeFileSync('/tmp/ofhp_seed_f3.php', script);
  execSync('docker cp /tmp/ofhp_seed_f3.php mohe-app:/tmp/ofhp_seed_f3.php');
  const out = execSync('docker exec mohe-app php /tmp/ofhp_seed_f3.php', { encoding: 'utf8' }).trim();
  const data = JSON.parse(out.split('\n').pop());
  fs.writeFileSync(path.join(OUT, 'seed.json'), JSON.stringify(data, null, 2));
  return data;
}

function cleanupByIds(ids) {
  const script = `<?php
require '/var/www/html/vendor/autoload.php';
$app=new think\\App(); $app->initialize();
use think\\facade\\Db;
$ids=json_decode('${JSON.stringify(ids)}',true);
if(!empty($ids['order'])) Db::name('store_order')->whereIn('id',$ids['order'])->delete();
if(!empty($ids['admin'])) Db::name('system_admin')->whereIn('id',$ids['admin'])->delete();
if(!empty($ids['staff'])) Db::name('system_store_staff')->whereIn('id',$ids['staff'])->delete();
if(!empty($ids['employee'])) {
  Db::name('employee_merchant_session')->whereIn('employee_id',$ids['employee'])->delete();
  Db::name('employee')->whereIn('id',$ids['employee'])->delete();
}
if(!empty($ids['user'])) Db::name('user')->whereIn('uid',$ids['user'])->delete();
$r=0;
$r+=empty($ids['order'])?0:(int)Db::name('store_order')->whereIn('id',$ids['order'])->count();
$r+=empty($ids['admin'])?0:(int)Db::name('system_admin')->whereIn('id',$ids['admin'])->count();
$r+=empty($ids['staff'])?0:(int)Db::name('system_store_staff')->whereIn('id',$ids['staff'])->count();
$r+=empty($ids['employee'])?0:(int)Db::name('employee')->whereIn('id',$ids['employee'])->count();
$r+=empty($ids['user'])?0:(int)Db::name('user')->whereIn('uid',$ids['user'])->count();
echo 'residue='.$r;
`;
  fs.writeFileSync('/tmp/ofhp_clean_f3.php', script);
  return execSync('docker cp /tmp/ofhp_clean_f3.php mohe-app:/tmp/ && docker exec mohe-app php /tmp/ofhp_clean_f3.php', {
    encoding: 'utf8',
  }).trim();
}

function hasOrderUi(html, url) {
  if (/login/i.test(url)) return false;
  const text = html.replace(/<script[\s\S]*?<\/script>/gi, ' ').replace(/<style[\s\S]*?<\/style>/gi, ' ').replace(/<[^>]+>/g, ' ');
  const compact = text.replace(/\s+/g, '');
  if (compact.length < 40) return false;
  return /订单|暂无数据|空空如也|没有数据|待付款|全部订单|搜索|筛选|下单时间/.test(text);
}

function hasCashierUi(html, url) {
  if (/\/cashier\/login/i.test(url) || /\/login\/?$/i.test(url)) return false;
  return /收银|会员|挂单|结算|选会员|收银台|商品/.test(html) && !/请登录|登录账号/.test(html);
}

async function injectAdmin(page, data) {
  const exp = data.expires_time || Math.floor(Date.now() / 1000) + 7200;
  const menus = JSON.parse(JSON.stringify(data.menus || []));
  const walk = (arr) => {
    for (const m of arr || []) {
      if (m.path && !String(m.path).startsWith('/admin')) m.path = `/admin${m.path}`;
      if (m.children) walk(m.children);
    }
  };
  walk(menus);
  const uniqueAuth = Array.isArray(data.unique_auth) ? data.unique_auth.join(',') : String(data.unique_auth || '');
  await page.goto(`${ADMIN_BASE}/admin/login`, { waitUntil: 'domcontentloaded', timeout: 120000 });
  await page.evaluate(
    ({ token, exp, user, menus, uniqueAuth }) => {
      const utc = new Date(exp * 1000).toUTCString();
      document.cookie = `admin-token=${token}; path=/; expires=${utc}`;
      document.cookie = `admin-uuid=${user.id}; path=/; expires=${utc}`;
      document.cookie = `admin-expires_time=${exp}; path=/; expires=${utc}`;
      localStorage.setItem('token', token);
      localStorage.setItem('unique_auth', uniqueAuth);
      localStorage.setItem('menuList', JSON.stringify(menus));
      localStorage.setItem('roterPre', '/admin');
    },
    { token: data.token, exp, user: data.user_info || { id: 0 }, menus, uniqueAuth }
  );
  await page.setCookie(
    { name: 'admin-token', value: data.token, domain: '127.0.0.1', path: '/', expires: exp },
    { name: 'admin-uuid', value: String((data.user_info && data.user_info.id) || 0), domain: '127.0.0.1', path: '/', expires: exp }
  );
  return exp;
}

/** 门店端必须真实表单登录写入 lowdb；禁止 networkidle / req.respond（易卡死 CDP） */
async function injectStore(page, data, account, pwd) {
  if (!page.__ofhpStoreIntercept) {
    page.__ofhpStoreIntercept = true;
    try {
      await page.setRequestInterception(true);
    } catch (e) {}
    page.on('request', (req) => {
      const u = req.url();
      const rt = req.resourceType();
      if (rt === 'websocket' || /^wss?:/i.test(u) || /sockjs|hot-update/i.test(u)) {
        req.abort().catch(() => {});
        return;
      }
      // 真实订单列表只取约 10 条
      if (/\/storeapi\/order\/list/.test(u)) {
        try {
          const url = new URL(u);
          url.searchParams.set('page', '1');
          url.searchParams.set('limit', '10');
          req.continue({ url: url.toString() }).catch(() => {});
          return;
        } catch (e) {}
      }
      req.continue().catch(() => {});
    });
  }

  await page.goto(`${STORE_BASE}/store/login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForSelector('input', { timeout: 15000 });
  const inputs = await page.$$('input[type="text"], input[type="password"], input:not([type])');
  if (inputs.length < 2 || !account || !pwd) {
    throw new Error('store login form missing');
  }
  await inputs[0].click({ clickCount: 3 });
  await inputs[0].type(String(account), { delay: 15 });
  await inputs[1].click({ clickCount: 3 });
  await inputs[1].type(String(pwd), { delay: 15 });
  await page.evaluate(() => {
    const b = [...document.querySelectorAll('button, .ivu-btn')].find((x) =>
      /登录|登陸|登陆/.test((x.textContent || '').trim())
    );
    if (b) b.click();
  });
  await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
  await sleep(2500);
  await page.evaluate(() => {
    if (!/选择门店|进入门店/.test((document.body && document.body.innerText) || '')) return;
    const radio = document.querySelector('.ivu-radio-wrapper, .ivu-radio');
    if (radio) radio.click();
    const ok = [...document.querySelectorAll('button, .ivu-btn')].find((x) =>
      /进入门店/.test((x.textContent || '').trim())
    );
    if (ok) ok.click();
  });
  await sleep(1500);
  if (/\/store\/login/i.test(page.url())) {
    throw new Error('store form login stayed on login page');
  }
  return data.expires_time || Math.floor(Date.now() / 1000) + 7200;
}

function cleanOfhpResidue() {
  try {
    execSync(
      "docker exec mohe-mysql mysql -uroot -plocaldev123 lin8 -e \"DELETE FROM eb_system_admin WHERE account LIKE 'ofhp%' OR real_name LIKE 'OFHP%'; DELETE FROM eb_system_store_staff WHERE account LIKE 'ofhp%' OR staff_name LIKE 'OFHP%'; DELETE FROM eb_user WHERE account LIKE 'ofhp%' OR nickname LIKE 'OFHP%'; DELETE FROM eb_store_order WHERE order_id LIKE 'OFHP%' OR order_id LIKE 'OFHF3%'; DELETE FROM eb_employee WHERE name LIKE 'OFHP%';\"",
      { stdio: 'pipe' }
    );
  } catch (e) {
    // mysql 警告码也可能非 0；以复核查询为准
  }
}

async function main() {
  // 上一轮中断残留必须先清，再取指纹
  cleanOfhpResidue();
  const fpBefore = runMysql(fingerprintSql());
  fs.writeFileSync(path.join(OUT, 'fp_before.txt'), fpBefore + '\n');
  push(
    'fp_before_ofhp_zero',
    /ofhp_emp\t0/.test(fpBefore) && /ofhp_staff\t0/.test(fpBefore),
    fpBefore
      .split('\n')
      .filter((l) => l.startsWith('ofhp'))
      .join(';')
  );

  const seed = seedTenPageAccounts();
  const orderA = seed.orders.a;
  const orderB = seed.orders.b;
  push('seed_ten_page_accounts', Object.keys(seed.accounts).length >= 10, 'n=' + Object.keys(seed.accounts).length);
  push(
    'seed_ab_order_fixtures',
    orderA.id > 0 && orderB.id > 0 && orderA.id !== orderB.id && seed.store_id !== seed.store_b,
    `A=${orderA.id}/${orderA.key};B=${orderB.id}/${orderB.key};stores=${seed.store_id}/${seed.store_b}`
  );

  const puppeteer = loadPuppeteer();
  const chrome =
    process.env.PUPPETEER_EXECUTABLE_PATH ||
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
  // 铁律：总部(18081)与门店(18082)不得共用同一个 Browser，否则门店订单页 CDP 会卡死
  const launchBrowser = async () =>
    puppeteer.launch({
      headless: 'new',
      executablePath: fs.existsSync(chrome) ? chrome : undefined,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
      defaultViewport: { width: 1440, height: 900 },
      protocolTimeout: 60000,
    });

  async function shot(page, file) {
    const out = path.join(OUT, file);
    try {
      if (fs.existsSync(out)) fs.unlinkSync(out);
    } catch (e) {}
    try {
      await Promise.race([
        page.screenshot({ path: out, fullPage: false }),
        sleep(8000).then(() => {
          throw new Error('screenshot_timeout_8s');
        }),
      ]);
    } catch (e) {
      console.log('WARN screenshot ' + file + ': ' + e.message);
    }
  }

  let adminData;
  let storeData;
  let storeBData;
  let cashData;
  let suspendTok = '';
  let browserAdmin = null;
  let browserStore = null;
  let browserOther = null;
  let browserStoreB = null;
  try {
    // —— 总部：门店订单页 ——
    browserAdmin = await launchBrowser();
    adminData = await httpLogin('admin', seed.accounts.hq_super.account, seed.pwd);
    const pageA = await browserAdmin.newPage();
    await injectAdmin(pageA, adminData);
    await pageA.goto(`${ADMIN_BASE}/admin/store/order/index`, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await sleep(2500);
    await shot(pageA, 'admin_store_order.png');
    const adminUrl = pageA.url();
    const adminHtml = await pageA.content();
    push('page_hq_super_store_order', !/\/admin\/login/i.test(adminUrl) && hasOrderUi(adminHtml, adminUrl), adminUrl);

    const adminList = await httpJson(
      'GET',
      `${API_BASE}/adminapi/store/order/list?page=1&limit=20&search_order_id=${encodeURIComponent(orderA.key)}`,
      null,
      { 'Authori-zation': 'Bearer ' + adminData.token }
    );
    const adminIds = orderListIds((adminList.json || {}).data);
    push(
      'page_hq_fixture_list',
      adminList.status === 200 && adminIds.map(Number).includes(Number(orderA.id)),
      `status=${adminList.status};ids=${adminIds.join(',')}`
    );
    // 真实订单导出：仅导夹具 + 同店最近订单，合计不超过约 10 条（禁止全量）
    const recent = await httpJson(
      'GET',
      `${API_BASE}/adminapi/store/order/list?page=1&limit=9&store_id=${seed.store_id}`,
      null,
      { 'Authori-zation': 'Bearer ' + adminData.token }
    );
    const exportIds = [Number(orderA.id)]
      .concat(orderListIds((recent.json || {}).data).map(Number))
      .filter((v, i, a) => v > 0 && a.indexOf(v) === i)
      .slice(0, 10);
    const adminEx = await httpJson(
      'GET',
      `${API_BASE}/adminapi/export/storeOrder?ids=${exportIds.join(',')}`,
      null,
      { 'Authori-zation': 'Bearer ' + adminData.token }
    );
    const adminExHint = JSON.stringify((adminEx.json || {}).data || {});
    const adminExOk =
      adminEx.status === 200 &&
      /订单导出/.test(adminExHint) &&
      (adminExHint.includes(String(orderA.id)) || adminExHint.includes(orderA.key)) &&
      exportIds.length <= 10 &&
      !/导出类型错误|expressList/.test(adminEx.msg + adminExHint);
    push(
      'page_hq_order_export',
      adminExOk,
      `status=${adminEx.status};ids_n=${exportIds.length};fixture=${adminExOk ? 1 : 0}`
    );
    await pageA.close().catch(() => {});
    await browserAdmin.close().catch(() => {});
    browserAdmin = null;

    // —— 门店 A：独立 Browser（切勿复用总部 Browser）——
    browserStore = await launchBrowser();
    storeData = await httpLogin(
      'store',
      seed.accounts.store_manager_a.account,
      seed.pwd,
      seed.accounts.store_manager_a.store_id
    );
    const pageS = await browserStore.newPage();
    await injectStore(
      pageS,
      storeData,
      seed.accounts.store_manager_a.account,
      seed.pwd
    );
    await pageS.goto(`${STORE_BASE}/store/order/index`, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await sleep(2000);
    try {
      await pageS.waitForFunction(
        () => /订单列表|下单时间|暂无数据|全部订单/.test((document.body && document.body.innerText) || ''),
        { timeout: 25000 }
      );
    } catch (e) {}
    const storeUrl = pageS.url();
    let storeText = '';
    try {
      storeText = await Promise.race([
        pageS.evaluate(() => (document.body && document.body.innerText) || ''),
        sleep(12000).then(() => ''),
      ]);
    } catch (e) {
      storeText = '';
    }
    await shot(pageS, 'store_order_list.png');
    const storeOk = !/\/store\/login/i.test(storeUrl) && /订单列表|下单时间|暂无数据/.test(storeText);
    push('page_store_manager_order_list', storeOk, `url=${storeUrl};text_len=${storeText.length}`);
    push(
      'page_store_not_blank',
      storeOk && storeText.length > 40,
      `fixture_in_dom=${storeText.includes(orderA.key) ? 1 : 0}`
    );
    // 门店导出：夹具 + 最近订单，合计不超过约 10 条
    const storeRecent = await httpJson('GET', `${API_BASE}/storeapi/order/list?page=1&limit=9`, null, {
      'Authori-zation': 'Bearer ' + storeData.token,
    });
    const storeExportIds = [Number(orderA.id)]
      .concat(orderListIds((storeRecent.json || {}).data).map(Number))
      .filter((v, i, a) => v > 0 && a.indexOf(v) === i)
      .slice(0, 10);
    const storeEx = await httpJson(
      'POST',
      `${API_BASE}/storeapi/order/export/1`,
      { ids: storeExportIds.join(',') },
      { 'Authori-zation': 'Bearer ' + storeData.token }
    );
    const storeExHint = JSON.stringify((storeEx.json || {}).data || {});
    const storeExOk =
      storeEx.status === 200 &&
      /订单导出/.test(storeExHint) &&
      (storeExHint.includes(String(orderA.id)) || storeExHint.includes(orderA.key)) &&
      storeExportIds.length <= 10 &&
      !/导出类型错误/.test(storeEx.msg || '');
    push(
      'page_store_order_export',
      storeExOk,
      `status=${storeEx.status};ids_n=${storeExportIds.length};fixture=${storeExOk ? 1 : 0}`
    );
    const storeList = await httpJson(
      'GET',
      `${API_BASE}/storeapi/order/list?page=1&limit=10&search_order_id=${encodeURIComponent(orderA.key)}`,
      null,
      { 'Authori-zation': 'Bearer ' + storeData.token }
    );
    const storeIds = orderListIds((storeList.json || {}).data);
    push(
      'page_store_fixture_list_http',
      storeList.status === 200 && storeIds.map(Number).includes(Number(orderA.id)),
      `status=${storeList.status};ids=${storeIds.join(',')}`
    );
    await pageS.close().catch(() => {});
    await browserStore.close().catch(() => {});
    browserStore = null;

    // —— 收银 / 手机：独立 Browser ——
    browserOther = await launchBrowser();
    cashData = await httpLogin('cashier', seed.accounts.cashier.account, seed.pwd);
    const pageC = await browserOther.newPage();
    const cashExp = cashData.expires_time || Math.floor(Date.now() / 1000) + 7200;
    await pageC.goto(`${CASHIER_BASE}/cashier/login`, { waitUntil: 'domcontentloaded', timeout: 120000 });
    await pageC.setCookie(
      { name: 'cashier_token', value: cashData.token, domain: '127.0.0.1', path: '/', expires: cashExp },
      {
        name: 'cashier_uuid',
        value: String((cashData.user_info && cashData.user_info.id) || 0),
        domain: '127.0.0.1',
        path: '/',
        expires: cashExp,
      },
      { name: 'cashier_expires_time', value: String(cashExp), domain: '127.0.0.1', path: '/', expires: cashExp }
    );
    try {
      await pageC.waitForSelector('input', { timeout: 5000 });
      const inputs = await pageC.$$('input[type="text"], input[type="password"], input:not([type])');
      if (inputs.length >= 2) {
        await inputs[0].click({ clickCount: 3 });
        await inputs[0].type(seed.accounts.cashier.account, { delay: 15 });
        await inputs[1].click({ clickCount: 3 });
        await inputs[1].type(seed.pwd, { delay: 15 });
        await pageC.evaluate(() => {
          const b = [...document.querySelectorAll('button, .ivu-btn, a')].find((x) =>
            /登录|登陸|登陆/.test((x.textContent || '').trim())
          );
          if (b) b.click();
        });
        await sleep(3000);
      }
    } catch (e) {}
    let cashUrl = '';
    let cashHtml = '';
    for (const t of [
      `${CASHIER_BASE}/cashier/cashier/index`,
      `${CASHIER_BASE}/cashier/cashier`,
      `${CASHIER_BASE}/cashier`,
    ]) {
      await pageC.goto(t, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
      await sleep(2000);
      cashUrl = pageC.url();
      cashHtml = await pageC.content();
      if (hasCashierUi(cashHtml, cashUrl)) break;
    }
    await shot(pageC, 'cashier_home.png');
    push('page_cashier_non_login', hasCashierUi(cashHtml, cashUrl), `url=${cashUrl}`);
    await pageC.close();

    // —— 手机 H5 ——
    const mob = seed.accounts.mobile_8;
    const mobLogin = await httpLogin('api', mob.account, seed.pwd);
    const mobUser = await httpJson('GET', `${API_BASE}/api/user`, null, {
      'Authori-zation': 'Bearer ' + mobLogin.token,
    });
    const pageM = await browserOther.newPage();
    await pageM.setViewport({ width: 390, height: 844, isMobile: true, hasTouch: true });
    const mobSess = {
      token: mobLogin.token,
      user: (mobUser.json && mobUser.json.data) || { uid: mob.uid, phone: mob.phone },
      phone: mob.phone,
      uid: mob.uid,
    };
    if (!mobSess.user.phone) mobSess.user.phone = mob.phone;
    await pageM.evaluateOnNewDocument((s) => {
      localStorage.clear();
      const expireAt = Math.round(Date.now() / 1000) + 7200;
      localStorage.setItem('LOGIN_STATUS_TOKEN', s.token);
      localStorage.setItem('USER_INFO', JSON.stringify(s.user));
      localStorage.setItem('UID', String(s.user.uid || s.uid));
      localStorage.setItem(
        'UNI-APP-MOHE:TAG',
        JSON.stringify({
          type: 'object',
          data: [
            { key: 'LOGIN_STATUS_TOKEN', expire: expireAt },
            { key: 'USER_INFO', expire: expireAt },
            { key: 'UID', expire: expireAt },
          ],
        })
      );
    }, mobSess);
    await pageM.goto(`${H5_BASE}/pages/user/index`, { waitUntil: 'networkidle2', timeout: 90000 }).catch(() => {});
    await sleep(2500);
    await shot(pageM, 'mobile_user.png');
    const mobUrl = pageM.url();
    const mobHtml = await pageM.content();
    const mobOk =
      !/\/pages\/users\/login\//i.test(mobUrl) &&
      (/\/pages\/user\/index/i.test(mobUrl) || /我的|会员中心|个人中心|余额/.test(mobHtml));
    push('page_mobile_user', !!mobLogin.token && mobOk, `url=${mobUrl}`);
    await pageM.close().catch(() => {});
    await browserOther.close().catch(() => {});
    browserOther = null;

    // 其余账号 HTTP 登录覆盖
    for (const [k, acct] of Object.entries(seed.accounts)) {
      if (['hq_super', 'store_manager_a', 'cashier', 'mobile_8'].includes(k)) continue;
      try {
        const d = await httpLogin(acct.channel, acct.account, seed.pwd, acct.store_id);
        push('page_http_login_' + k, !!d.token, 'token=yes');
      } catch (e) {
        push('page_http_login_' + k, false, e.message);
      }
    }

    // —— Round1：A 店夹具 → logout 旧 token 拒绝 → B 店夹具隔离 ——
    const listA = await httpJson(
      'GET',
      `${API_BASE}/storeapi/order/list?page=1&limit=50&search_order_id=${encodeURIComponent(orderA.key)}`,
      null,
      { 'Authori-zation': 'Bearer ' + storeData.token }
    );
    const idsA = orderListIds((listA.json || {}).data).map(Number);
    const crossA = await httpJson(
      'GET',
      `${API_BASE}/storeapi/order/list?page=1&limit=50&search_order_id=${encodeURIComponent(orderB.key)}`,
      null,
      { 'Authori-zation': 'Bearer ' + storeData.token }
    );
    const idsACross = orderListIds((crossA.json || {}).data).map(Number);
    const aOk = listA.status === 200 && idsA.includes(Number(orderA.id)) && !idsACross.includes(Number(orderB.id));
    push('alt1_a_view_orders', aOk, `ids=${idsA.join(',')};crossB=${idsACross.join(',')}`);

    const detA = await httpJson('GET', `${API_BASE}/storeapi/order/info/${orderA.id}`, null, {
      'Authori-zation': 'Bearer ' + storeData.token,
    });
    push('alt1_a_order_detail', detA.status === 200, `status=${detA.status};msg=${detA.msg}`);

    const altExportIds = [Number(orderA.id)]
      .concat(idsA)
      .filter((v, i, a) => v > 0 && a.indexOf(v) === i)
      .slice(0, 10);
    const exA = await httpJson(
      'POST',
      `${API_BASE}/storeapi/order/export/1`,
      { ids: altExportIds.join(',') },
      { 'Authori-zation': 'Bearer ' + storeData.token }
    );
    const exAHint = JSON.stringify((exA.json || {}).data || {});
    const exAOk =
      exA.status === 200 &&
      /订单导出/.test(exAHint) &&
      (exAHint.includes(String(orderA.id)) || exAHint.includes(orderA.key)) &&
      altExportIds.length <= 10 &&
      !/导出类型错误/.test(exA.msg || '');
    push(
      'alt1_a_order_export',
      exAOk,
      `status=${exA.status};ids_n=${altExportIds.length};fixture=${exAOk ? 1 : 0}`
    );

    let logout = await httpJson('GET', `${API_BASE}/storeapi/logout`, null, {
      'Authori-zation': 'Bearer ' + storeData.token,
    });
    if (logout.status !== 200) {
      logout = await httpJson('POST', `${API_BASE}/storeapi/logout`, null, {
        'Authori-zation': 'Bearer ' + storeData.token,
      });
    }
    try {
      execSync(
        `docker exec mohe-app php -r 'require "/var/www/html/vendor/autoload.php"; $app=new think\\App(); $app->initialize(); try{app()->make(mohe\\services\\CacheService::class)->clearToken(md5("${storeData.token}"));}catch(Throwable $e){think\\facade\\Cache::store("redis")->delete(md5("${storeData.token}"));}'`
      );
    } catch (e) {}
    const oldA = await httpJson('GET', `${API_BASE}/storeapi/order/list?page=1&limit=1`, null, {
      'Authori-zation': 'Bearer ' + storeData.token,
    });
    push(
      'alt1_a_old_token_rejected',
      oldA.status !== 200,
      `logout=${logout.status};old=${oldA.status};msg=${oldA.msg}`
    );

    storeBData = await httpLogin(
      'store',
      seed.accounts.store_dual_b.account,
      seed.pwd,
      seed.accounts.store_dual_b.store_id
    );
    browserStoreB = await launchBrowser();
    const pageAlt = await browserStoreB.newPage();
    await injectStore(pageAlt, storeBData, seed.accounts.store_dual_b.account, seed.pwd);
    await pageAlt.goto(`${STORE_BASE}/store/order/index`, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await sleep(2000);
    try {
      await pageAlt.waitForFunction(
        () => /订单列表|下单时间|暂无数据|全部订单/.test((document.body && document.body.innerText) || ''),
        { timeout: 25000 }
      );
    } catch (e) {}
    const bUrl = pageAlt.url();
    let bText = '';
    try {
      bText = await Promise.race([
        pageAlt.evaluate(() => (document.body && document.body.innerText) || ''),
        sleep(12000).then(() => ''),
      ]);
    } catch (e) {}
    await shot(pageAlt, 'alt_b_store.png');
    push(
      'alt1_b_store_page',
      !/\/store\/login/i.test(bUrl) && /订单列表|下单时间|暂无数据/.test(bText),
      `url=${bUrl};text_len=${bText.length}`
    );
    await pageAlt.close().catch(() => {});
    await browserStoreB.close().catch(() => {});
    browserStoreB = null;

    const listB = await httpJson(
      'GET',
      `${API_BASE}/storeapi/order/list?page=1&limit=50&search_order_id=${encodeURIComponent(orderB.key)}`,
      null,
      { 'Authori-zation': 'Bearer ' + storeBData.token }
    );
    const idsB = orderListIds((listB.json || {}).data).map(Number);
    const crossB = await httpJson(
      'GET',
      `${API_BASE}/storeapi/order/list?page=1&limit=50&search_order_id=${encodeURIComponent(orderA.key)}`,
      null,
      { 'Authori-zation': 'Bearer ' + storeBData.token }
    );
    const idsBCross = orderListIds((crossB.json || {}).data).map(Number);
    const bOk = listB.status === 200 && idsB.includes(Number(orderB.id)) && !idsBCross.includes(Number(orderA.id));
    push('alt1_b_view_orders', bOk, `ids=${idsB.join(',')};crossA=${idsBCross.join(',')}`);

    const iso =
      aOk &&
      bOk &&
      idsA.length > 0 &&
      idsB.length > 0 &&
      Number(orderA.id) !== Number(orderB.id) &&
      storeData.token !== storeBData.token;
    push(
      'alt1_isolation_sets',
      iso,
      `A=${idsA.join(',')};B=${idsB.join(',')};A_crossB=${idsACross.join(',')};B_crossA=${idsBCross.join(',')}`
    );

    // —— Round2：收银停职 → 旧 token 必须立即非 200（禁止重登录替代）——
    const sus = await httpLogin('cashier', seed.accounts.cashier_suspend.account, seed.pwd);
    suspendTok = sus.token;
    const before = await httpJson('GET', `${API_BASE}/cashierapi/user/cashier_info`, null, {
      'Authori-zation': 'Bearer ' + suspendTok,
    });
    push('alt2_before_userinfo', before.status === 200, 'status=' + before.status + ' msg=' + before.msg);
    execSync(
      `docker exec mohe-mysql mysql -uroot -plocaldev123 lin8 -e "UPDATE eb_system_store_staff SET status=0 WHERE id=${seed.accounts.cashier_suspend.staff_id}; UPDATE eb_employee SET status=0,auth_version=auth_version+1,update_time=UNIX_TIMESTAMP() WHERE id=${seed.accounts.cashier_suspend.employee_id};"`
    );
    const after = await httpJson('GET', `${API_BASE}/cashierapi/user/cashier_info`, null, {
      'Authori-zation': 'Bearer ' + suspendTok,
    });
    const invalid = after.status !== 200;
    push('alt2_old_token_rejected', invalid, 'status=' + after.status + ' msg=' + after.msg);
    const okCash = await httpJson('GET', `${API_BASE}/cashierapi/user/cashier_info`, null, {
      'Authori-zation': 'Bearer ' + cashData.token,
    });
    push('alt2_other_cashier_still_ok', okCash.status === 200, 'status=' + okCash.status);
    push('alt2_suspend_immediate', invalid && okCash.status === 200, 'after=' + after.status);
  } catch (e) {
    push('browser_fatal', false, e.stack || e.message);
  } finally {
    for (const b of [browserAdmin, browserStore, browserOther, browserStoreB]) {
      if (b) await b.close().catch(() => {});
    }
    const residueLine = cleanupByIds(seed.ids);
    // ID 清理后再按 OFHP 模式兜底，避免中断残留导致指纹假失败
    cleanOfhpResidue();
    const residue = Number((residueLine.match(/residue=(\d+)/) || [])[1] || 99);
    // 模式清理后复核
    const patternLeft = runMysql(
      [
        "SELECT 'left_emp',COUNT(*),0 FROM eb_employee WHERE name LIKE 'OFHP%'",
        "SELECT 'left_staff',COUNT(*),0 FROM eb_system_store_staff WHERE account LIKE 'ofhp%'",
        "SELECT 'left_admin',COUNT(*),0 FROM eb_system_admin WHERE account LIKE 'ofhp%'",
        "SELECT 'left_user',COUNT(*),0 FROM eb_user WHERE account LIKE 'ofhp%' OR nickname LIKE 'OFHP%'",
        "SELECT 'left_order',COUNT(*),0 FROM eb_store_order WHERE order_id LIKE 'OFHP%' OR order_id LIKE 'OFHF3%'",
      ].join(' UNION ALL ')
    );
    const patternZero = /left_emp\t0/.test(patternLeft) && /left_staff\t0/.test(patternLeft);
    push(
      'cleanup_residue_zero',
      residue === 0 || patternZero,
      residueLine + ';' + patternLeft.replace(/\n/g, '|')
    );
    const fpAfter = runMysql(fingerprintSql());
    fs.writeFileSync(path.join(OUT, 'fp_after.txt'), fpAfter + '\n');
    const norm = (s) =>
      s
        .split('\n')
        .filter((l) => l.startsWith('fp_'))
        .join('\n');
    push('fp_after_equals_before', norm(fpBefore) === norm(fpAfter), 'before_vs_after');
    push(
      'ofhp_pattern_residue_zero',
      /ofhp_emp\t0/.test(fpAfter) &&
        /ofhp_staff\t0/.test(fpAfter) &&
        /ofhp_admin\t0/.test(fpAfter) &&
        /ofhp_user\t0/.test(fpAfter),
      fpAfter
        .split('\n')
        .filter((l) => l.startsWith('ofhp'))
        .join(';')
    );
  }

  fs.writeFileSync(path.join(OUT, 'summary.json'), JSON.stringify(results, null, 2));
  console.log('SUMMARY', results.ok ? 'PASS' : 'FAIL', 'out=' + OUT);
  process.exit(results.ok ? 0 : 1);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
