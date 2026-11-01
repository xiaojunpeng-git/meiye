<?php

namespace app\controller\admin\v1\store;

use app\controller\admin\AuthController;
use app\services\order\PlatformOrderCenterReadServices;
use app\services\organization\OrganizationScopeService;
use think\facade\App;

/** 平台订单中心：管理员身份与组织范围适配，共用查询及业务操作入口。 */
class OrderCenter extends AuthController
{
    public function __construct(App $app)
    {
        parent::__construct($app);
    }

    public function metadata(PlatformOrderCenterReadServices $services)
    {
        return $this->success($services->metadata((array)$this->adminInfo));
    }

    public function records(PlatformOrderCenterReadServices $services)
    {
        $query = (array)$this->request->get();
        return $this->success($services->records((array)$this->adminInfo, $query));
    }

    /** 平台订单中心的组织／门店范围，按当前订单数据权限裁剪。 */
    public function scope(PlatformOrderCenterReadServices $services, OrganizationScopeService $organizations)
    {
        return $this->success($services->scope((array)$this->adminInfo, $organizations));
    }

    /** POST /adminapi/store/order-center/actions: Vue3 订单中心标准 action envelope。 */
    public function actions(PlatformOrderCenterReadServices $services)
    {
        $body = (array)$this->request->post();
        // 总后台共用操作不另查门店岗位/页签功能权限；登录由 AuthController，
        // 当前数据范围及领域约束由同一 gateway 在事务内验证。
        return $this->success('ok', $services->dispatchReadAction(
            (array)$this->adminInfo,
            $body
        ));
    }

    /** 下载与创建任务使用同一管理员会话及当前数据范围，不接受门店令牌。 */
    public function download(PlatformOrderCenterReadServices $services, string $taskNo)
    {
        $pageCode = (string)$this->request->get('pageCode', '');
        [, , $context] = $services->exportScopes((int)$this->adminInfo['id'], $pageCode);
        $runtime = \app\services\cashier\v3\query\UnifiedQueryModule::runtime();
        $descriptor = $runtime['exports']->resolveDownloadDescriptor($context, $taskNo);
        $path = $runtime['exportStorage']->absolutePath((string)$descriptor['storageKey']);
        if (!is_file($path) || !is_readable($path)) return app('json')->fail('导出文件不存在或已过期。');
        return download($path, (string)$descriptor['fileName'])
            ->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

}
