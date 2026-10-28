<?php
namespace app\controller\admin\v1\user;
use app\controller\admin\AuthController;
use app\services\user\PlatformMemberCenterServices;
use app\services\user\PlatformMemberScopeServices;
use app\services\user\MemberLoginManagementServices;
use app\services\query\UnifiedQueryRuntime;
use app\services\query\provider\MemberUnifiedQueryContextFactory;
use app\services\query\provider\PlatformMemberUnifiedQueryProvider;

/** 平台会员模块只使用服务端平台身份，会员范围和每项写权限均由服务层重新验证。 */
class MemberCenter extends AuthController
{
    public function bootstrap(PlatformMemberCenterServices $service) { return $this->safe(function () use ($service) { return $service->bootstrap((int)$this->adminId); }); }
    public function action(PlatformMemberCenterServices $service)
    {
        $body = (array)$this->request->post();
        return $this->safe(function () use ($body, $service) { return $service->action((int)$this->adminId, (string)($body['action'] ?? ''), (array)($body['payload'] ?? [])); });
    }
    public function login($id, MemberLoginManagementServices $service)
    {
        return $this->safe(function () use ($id, $service) { $s = (new PlatformMemberScopeServices())->build((int)$this->adminId, 'edit'); return $service->read((int)$id, $s['operator'], $s['data']); });
    }
    public function password($id, MemberLoginManagementServices $service) { return $this->loginWrite((int)$id, 'change-password', $service); }
    public function clear($id, MemberLoginManagementServices $service) { return $this->loginWrite((int)$id, 'clear-wechat', $service); }
    private function loginWrite(int $id, string $action, MemberLoginManagementServices $service)
    {
        return $this->safe(function () use ($id, $action, $service) { $s = (new PlatformMemberScopeServices())->build((int)$this->adminId, 'edit'); return $service->mutate($id, $action, (array)$this->request->post(), $s['operator'], $s['data'], 'ADMIN'); });
    }
    public function download($taskNo)
    {
        $s = (new PlatformMemberScopeServices())->build((int)$this->adminId);
        $r = UnifiedQueryRuntime::runtime();
        $c = (new MemberUnifiedQueryContextFactory($r['contextFactory']))->make($s['operator'], $s['data'], ['pageCode' => PlatformMemberUnifiedQueryProvider::PAGE_CODE]);
        $d = $r['exports']->resolveDownloadDescriptor($c, (string)$taskNo);
        $path = $r['exportStorage']->absolutePath((string)$d['storageKey']);
        if (!is_file($path)) return $this->fail('导出文件不存在或已过期');
        return download($path, (string)$d['fileName'])->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    /** 登录/编辑失败不回传含会员密码哈希或当前密码输入的 debug 参数堆栈。 */
    private function safe(callable $operation)
    {
        try { return $this->success($operation()); }
        catch (\Throwable $e) { return $this->fail(($e instanceof \think\exception\ValidateException || $e instanceof \app\services\cashier\v3\CashierV3CommandException || $e instanceof \app\services\query\UnifiedQueryException) ? $e->getMessage() : '会员操作失败，请刷新重试'); }
    }
}
