<?php
namespace app\controller\mobile\merchant;

final class Ai extends \mohe\basic\BaseController
{
    use \app\controller\ai\AiHttpActions;
    protected function isMobileAi(): bool { return true; }
    protected function buildAiContext(): array
    {
        $merchant=app()->make(\app\services\mobile\merchant\MobileMerchantRequestContextResolver::class)->resolve($this->request);
        if (!in_array('MERCHANT_WAREHOUSE_VIEW',(array)$merchant['availableActions'],true)) {
            return ['terminal'=>'merchant','account_id'=>(int)$merchant['accountId'],'scope_mode'=>'none','store_ids'=>[],
                'permission_version'=>(string)$merchant['permissionVersion'],'can_use'=>false,'can_configure'=>false,
                'report_capability_code'=>'group_management_dashboard'];
        }
        return app()->make(\app\services\ai\execution\AiMerchantPrincipalResolver::class)->authenticated($merchant);
    }
    protected function refreshAiContext(): array { return $this->buildAiContext(); }
}
