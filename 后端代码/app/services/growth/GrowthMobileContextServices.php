<?php
declare(strict_types=1);
namespace app\services\growth;

use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiException;

/** 成长员工上下文不会放宽旧门店领域；员工身份仍由原商家会话验证。 */
final class GrowthMobileContextServices
{
    /** 员工身份来自既有商家会话，客户端不能指定保存主体或绕过成长入口。 */
    public function resolve($request): array
    {
        $identity=app()->make(MobileMerchantRequestContextResolver::class)->growthIdentity($request);
        if(!in_array('mobile.merchant.growth',(array)$identity['availableActions'],true))throw MobileApiException::business('MOBILE_JOB_FUNCTION_MISSING','当前岗位未授权成长菜单。');
        $access=app()->make(GrowthAccessServices::class);$access->assertEnabled();
        $c=$access->principal((int)$identity['employeeId'],'employee:'.$identity['employeeId']);
        $c['organization_id']=(int)$identity['organizationId'];$c['store_id']=(int)$identity['storeId'];return $c;
    }
}
