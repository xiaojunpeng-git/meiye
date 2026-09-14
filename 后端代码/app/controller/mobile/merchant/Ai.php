<?php
namespace app\controller\mobile\merchant;

final class Ai extends \mohe\basic\BaseController
{
    use \app\controller\ai\AiHttpActions;
    protected function isMobileAi(): bool { return true; }
    protected function buildAiContext(): array
    {
        $merchant=app()->make(\app\services\mobile\merchant\MobileMerchantRequestContextResolver::class)->resolve($this->request);
        return app()->make(\app\services\ai\execution\AiMerchantPrincipalResolver::class)->authenticated($merchant);
    }
    protected function refreshAiContext(): array { return $this->buildAiContext(); }
}
