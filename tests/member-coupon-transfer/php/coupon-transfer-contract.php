<?php
declare(strict_types=1);

use app\services\activity\coupon\StoreCouponUserServices;
use app\services\activity\coupon\StoreCouponIssueServices;
use think\exception\ValidateException;
use think\facade\Db;

$backendRoot = getenv('MOHE_BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
chdir($backendRoot);
require 'vendor/autoload.php';
$app = new think\App();
$app->initialize();

$targetPhone = (string)($argv[1] ?? '18326679486');
$marker = 'coupon-transfer-contract-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
$requestId = $marker . '-request';
$tempCouponId = 0;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $repoRoot = dirname($backendRoot);
    $controllerSource = (string)file_get_contents(
        $backendRoot . '/app/controller/admin/v1/marketing/coupon/StoreCouponIssue.php'
    );
    $routeSource = (string)file_get_contents($backendRoot . '/route/admin.php');
    $adminApiSource = (string)file_get_contents($repoRoot . '/前端代码/admin/src/api/marketing.js');
    $adminPageSource = (string)file_get_contents(
        $repoRoot . '/前端代码/admin/src/pages/marketing/storeCouponIssue/create.vue'
    );
    $methodStart = strpos($controllerSource, 'public function transferSetting($id)');
    $assert($methodStart !== false, '后台缺少转赠开关专用接口');
    $methodEnd = strpos($controllerSource, "\n    /**", (int)$methodStart);
    $methodSource = substr($controllerSource, (int)$methodStart, $methodEnd - (int)$methodStart);
    $assert(
        strpos($methodSource, "update(\$id, ['allow_transfer' => \$allowTransfer])") !== false,
        '转赠开关接口未限定为单字段更新'
    );
    $assert(strpos($methodSource, 'saveCoupon(') === false, '转赠开关接口误用了整券保存逻辑');
    $assert(
        strpos($routeSource, "coupon/transfer-setting/:id") !== false,
        '后台路由未注册转赠开关接口'
    );
    $assert(
        strpos($adminApiSource, 'couponTransferSettingApi') !== false &&
        strpos($adminPageSource, '保存转赠设置') !== false,
        '已发布券详情页缺少转赠开关保存入口'
    );

    $target = Db::name('user')->where('phone', $targetPhone)->field('uid,phone')->find();
    $assert((bool)$target, '接收会员不存在');

    $source = Db::name('user')
        ->where('uid', '<>', (int)$target['uid'])
        ->where('status', 1)
        ->where('is_del', 0)
        ->where('phone', 'regexp', '^1[0-9]{10}$')
        ->field('uid,phone')
        ->order('uid asc')
        ->find();
    $assert((bool)$source, '没有可用的本地测试转出会员');

    $template = Db::name('store_coupon_user')
        ->where('uid', (int)$target['uid'])
        ->where('status', 0)
        ->where('is_fail', 0)
        ->where('use_time', 0)
        ->where('end_time', '>=', time())
        ->order('id desc')
        ->find();
    $assert((bool)$template, '接收会员没有可用的券模板');

    Db::startTrans();
    $issueFields = 'coupon_price,use_min_price,start_use_time,end_use_time,start_time,end_time,total_count,remain_count';
    $issueBefore = Db::name('store_coupon_issue')->where('id', (int)$template['cid'])->field($issueFields)->find();
    $currentAllowTransfer = (int)Db::name('store_coupon_issue')
        ->where('id', (int)$template['cid'])
        ->value('allow_transfer');
    /** @var StoreCouponIssueServices $issueServices */
    $issueServices = app()->make(StoreCouponIssueServices::class);
    $issueServices->update((int)$template['cid'], ['allow_transfer' => $currentAllowTransfer === 1 ? 0 : 1]);
    $issueAfterToggle = Db::name('store_coupon_issue')->where('id', (int)$template['cid'])->field($issueFields)->find();
    $assert($issueBefore === $issueAfterToggle, '切换转赠开关时改动了优惠券其他规则');
    $issueServices->update((int)$template['cid'], ['allow_transfer' => 1]);
    $issueAfter = Db::name('store_coupon_issue')->where('id', (int)$template['cid'])->field($issueFields)->find();
    $assert($issueBefore === $issueAfter, '保存转赠开关时改动了优惠券其他规则');
    unset($template['id']);
    $template['uid'] = (int)$source['uid'];
    $template['coupon_title'] = '转赠合同测试券';
    $template['add_time'] = time();
    $template['type'] = 'send';
    $template['oid'] = null;
    $tempCouponId = (int)Db::name('store_coupon_user')->insertGetId($template);

    /** @var StoreCouponUserServices $services */
    $services = app()->make(StoreCouponUserServices::class);
    $unauthorizedLookupBlocked = false;
    try {
        $services->getTransferTargetByPhone((int)$target['uid'], $tempCouponId, (string)$source['phone']);
    } catch (ValidateException $exception) {
        $unauthorizedLookupBlocked = true;
    }
    $assert($unauthorizedLookupBlocked, '非持券会员仍可使用转赠查找接口');

    $selfTransferBlocked = false;
    try {
        $services->getTransferTargetByPhone((int)$source['uid'], $tempCouponId, (string)$source['phone']);
    } catch (ValidateException $exception) {
        $selfTransferBlocked = strpos($exception->getMessage(), '不能转赠给自己') !== false;
    }
    $assert($selfTransferBlocked, '自转赠未被拦截');

    $lookup = $services->getTransferTargetByPhone((int)$source['uid'], $tempCouponId, $targetPhone);
    $assert((int)$lookup['uid'] === (int)$target['uid'], '精确手机号查找返回了错误会员');
    $assert(strpos((string)$lookup['phone_masked'], '****') !== false, '接收人手机号未脱敏');

    $beforeEndTime = (int)$template['end_time'];
    $result = $services->transferCoupon((int)$source['uid'], $tempCouponId, $targetPhone, $requestId);
    $assert(empty($result['idempotent_replay']), '首次转赠被错误标记为幂等回放');

    $couponAfter = Db::name('store_coupon_user')->where('id', $tempCouponId)->find();
    $assert((int)$couponAfter['uid'] === (int)$target['uid'], '优惠券权威归属未更新');
    $assert((int)$couponAfter['end_time'] === $beforeEndTime, '转赠后优惠券有效期发生变化');
    $assert(Db::name('store_coupon_transfer')->where('coupon_user_id', $tempCouponId)->count() === 1, '转赠审计记录数量错误');

    $replay = $services->transferCoupon((int)$source['uid'], $tempCouponId, $targetPhone, $requestId);
    $assert(!empty($replay['idempotent_replay']), '重复请求没有按幂等回放处理');
    $assert(Db::name('store_coupon_transfer')->where('coupon_user_id', $tempCouponId)->count() === 1, '幂等回放重复写入了审计记录');

    $blocked = false;
    try {
        $services->transferCoupon(
            (int)$target['uid'],
            $tempCouponId,
            (string)$source['phone'],
            $marker . '-second-request'
        );
    } catch (ValidateException $exception) {
        $blocked = strpos($exception->getMessage(), '已经转赠过') !== false;
    }
    $assert($blocked, '同一张券二次转赠未被正确拦截');

    Db::rollback();
    $assert(Db::name('store_coupon_user')->where('coupon_title', '转赠合同测试券')->count() === 0, '测试券未回滚');
    $assert(Db::name('store_coupon_transfer')->where('request_id', $requestId)->count() === 0, '测试转赠记录未回滚');

    echo "coupon transfer contract PASS\n";
} catch (Throwable $exception) {
    try {
        Db::rollback();
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, "coupon transfer contract FAIL: {$exception->getMessage()}\n");
    exit(1);
}
