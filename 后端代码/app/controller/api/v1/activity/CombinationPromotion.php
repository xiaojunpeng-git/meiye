<?php
declare(strict_types=1);
namespace app\controller\api\v1\activity;

use app\Request;
use app\services\activity\combination\CombinationPromotionServices;

/** “我的推广”只读当前会员本次分享订单，不接受请求指定别人的 UID。 */
class CombinationPromotion
{
    public function summary(Request $request) { return app('json')->successful((new CombinationPromotionServices())->memberSummary($request->get(), (int)$request->uid())); }
    public function orders(Request $request) { return app('json')->successful((new CombinationPromotionServices())->details($request->get(), null, (int)$request->uid())); }
}
