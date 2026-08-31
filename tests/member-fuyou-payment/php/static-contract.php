<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$read = static function (string $path) use ($root): string {
    $source = file_get_contents($root . '/' . $path);
    if ($source === false) {
        throw new RuntimeException('无法读取：' . $path);
    }
    return $source;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$pay = $read('后端代码/app/services/pay/PayServices.php');
$fuyou = $read('后端代码/mohe/services/wechat/HwcPayService.php');
$route = $read('后端代码/route/api.php');
$controller = $read('后端代码/app/controller/api/v1/Pay.php');
$rechargeController = $read('后端代码/app/controller/api/v1/user/UserRecharge.php');
$adminForm = $read('后端代码/app/services/system/config/SystemConfigServices.php');
$adminSave = $read('后端代码/app/controller/admin/v1/system/config/SystemConfig.php');
$cashier = $read('前端代码/uniapp/pages/goods/cashier/index.vue');
$cashierInfo = $read('后端代码/app/services/order/StoreOrderCreateServices.php');
$debt = $read('后端代码/app/services/order/StoreDebtServices.php');
$orders = $read('后端代码/app/services/order/StoreOrderServices.php');
$upgrade = $read('后端代码/database/upgrades/2026-09-01-富友渠道订单映射/02-正式升级.sql');

$assert(str_contains($pay, 'public static function fuyouPayReady()'), '缺少富友支付统一就绪口径');
$assert(!str_contains($pay, 'AliPayService::instance()->create('), '会员支付宝仍可能回退原生支付宝接口');
$assert(!str_contains($pay, 'return Payment::appPay('), '会员 APP 微信支付仍可能回退原生微信接口');
$assert(!str_contains($pay, 'return Payment::jsPay('), '会员公众号微信支付仍可能回退原生微信接口');
$assert(str_contains($pay, "request()->isApp() ? 'app' : 'wechat'"), 'APP 微信入口没有明确转入富友 APP 链路');
$assert(str_contains($pay, "case 'weixinh5':") && str_contains($pay, "'app');"), '普通 H5 微信入口没有富友跳转链路');
$assert(!str_contains($pay, 'var_dump(') && !str_contains($pay, 'die();'), '支付异常仍可能中断并泄露调试信息');

$assert(str_contains($fuyou, "Event::until('pay.notify', [\$notify, \$eventChannel])"), '富友回调没有携带支付渠道进入统一通知事件');
$assert(str_contains($fuyou, 'amountMatchesOrder('), '富友回调没有核对本地订单金额');
$assert(str_contains($fuyou, "\$successAction === 'member_recharge'") && str_contains($fuyou, "\$successAction = 'member'"), '旧会员购买回调没有映射到有效入账动作');
$assert(str_contains($fuyou, 'hash_equals('), '富友回调没有使用常量时间签名比较');
$assert(str_contains($fuyou, 'CURLOPT_SSL_VERIFYPEER => true'), '富友请求没有开启 TLS 证书校验');
$assert(!str_contains($fuyou, 'CURLOPT_SSL_VERIFYPEER, false'), '富友请求仍关闭 TLS 证书校验');
$assert(str_contains($fuyou, "\$settled = (bool)Event::until('pay.notify'") && str_contains($fuyou, 'return $settled;'), '本地入账失败时富友回调仍可能返回成功');
$assert(str_contains($fuyou, 'reserveChannelOrderNo('), '发起支付前没有生成富友合规渠道单号');
$assert(str_contains($fuyou, "Db::name('fuyou_payment_attempt')->insert"), '富友渠道单号与业务单号没有持久映射');
$assert(str_contains($fuyou, 'resolveOrderId('), '富友回调没有通过渠道映射还原业务单号');
$assert(str_contains($fuyou, "private const CHANNEL_ORDER_LENGTH = 20"), '富友渠道单号长度未固定为已实测通过的20位');
$notifyBlock = preg_match('/public function notify\(.*?\n    \}/s', $fuyou, $match) ? $match[0] : '';
$assert(str_contains($notifyBlock, 'assertCredentials(false)') && !str_contains($notifyBlock, 'assertReady(false)'), '关闭新支付后会拒绝已发起订单的合法回调');

$assert(str_contains($route, "pay/fynotify/:channel/:type"), '富友新回调路由没有区分微信与支付宝展示渠道');
$assert(str_contains($route, "pay/fynotify/:type") && str_contains($route, 'fyNotifyLegacy'), '缺少旧富友回调地址兼容');
$assert(str_contains($controller, "\$success ? 200 : 500"), '本地入账失败时回调 HTTP 状态没有失败保护');

$assert(str_contains($adminForm, "option('在线支付（富友）'"), '后台缺少统一富友配置入口');
$assert(!str_contains($adminForm, "option('微信支付'"), '后台仍显示原生微信支付配置入口');
$assert(!str_contains($adminForm, "option('支付宝支付'"), '后台仍显示原生支付宝支付配置入口');
$assert(str_contains($adminForm, '两种方式实际都由富友统一收单'), '后台没有说明会员端展示名称与真实收单渠道');
$assert(str_contains($adminSave, "'fuyou_sub_appid' => '富友sub_appid'"), '开启富友前没有校验完整配置');
$assert(str_contains($adminSave, "unset(\$post['fuyou_key'])"), '富友密钥空值可能覆盖已保存密钥');

$assert(str_contains($cashier, 'window.location.href = urlLink'), 'H5 没有处理富友付款链接');
$assert(str_contains($cashier, "(err && err.msg) || String(err || '支付发起失败')"), '支付失败原因仍可能在会员端静默丢失');
$assert(str_contains($cashier, 'if (that.paying) return;'), '确认付款仍可被连续重复提交');
$assert(str_contains($rechargeController, "\$from === 'weixinh5'") && str_contains($rechargeController, "? 'weixinh5'"), '普通浏览器充值微信支付仍可能错误索取公众号 OpenID');
$assert(str_contains($cashier, 'encodeURIComponent(urlLink)'), 'APP 富友付款链接未做 URL 编码');
$assert(str_contains($cashier, 'wx.openEmbeddedMiniProgram'), '小程序没有打开富友承载小程序');
$assert(str_contains($cashierInfo, 'PayServices::fuyouPayReady()'), '会员收银台仍读取原生支付开关');
$assert(str_contains($debt, "'pay_weixin_open' => (int)PayServices::fuyouPayReady()"), '欠款收银台仍读取原生微信支付开关');
$assert(str_contains($debt, "if (\$from !== 'weixinh5')"), '普通浏览器欠款微信支付仍可能错误索取公众号 OpenID');
$assert(substr_count($orders, 'PayServices::fuyouPayReady()') >= 2, '订单支付方式校验未统一到富友配置');
$assert(str_contains($upgrade, 'eb_fuyou_payment_attempt') && str_contains($upgrade, 'UNIQUE KEY `uk_channel_order_no`'), '缺少富友渠道订单映射表或唯一约束');
$assert(str_contains($upgrade, 'eb_wechat_accesstoken'), '普通浏览器微信支付缺少 URL Link 访问令牌缓存表');

echo "member fuyou payment static contract: PASS\n";
