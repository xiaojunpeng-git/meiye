<?php
declare(strict_types=1);
namespace app\services\user;

use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\member\CashierV3MemberModule;
use think\facade\Cache;
use think\facade\Db;
use think\exception\ValidateException;

/** 平台/门店同一会员登录资料服务。手机号只读；原密码及微信标识从不回显。 */
class MemberLoginManagementServices
{
    public function read(int $uid, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope): array
    {
        $member = CashierV3MemberModule::findManageableMember((string)$uid, $operator, $scope, false);
        return $this->projection($member);
    }

    public function mutate(int $uid, string $action, array $payload, CashierV3OperatorScope $operator, CashierV3DataScopeContext $scope, string $actorType): array
    {
        // 读写与回执重放前均复核当前权限，不能借旧请求凭证绕过调店/撤权。
        CashierV3MemberModule::findManageableMember((string)$uid, $operator, $scope, false);
        if (!in_array($action, ['change-password', 'clear-wechat'], true)) throw new ValidateException('不支持的登录管理操作');
        foreach (array_keys($payload) as $key) if (!in_array($key, ['requestId', 'revision', 'newPassword'], true)) throw new ValidateException('登录账号保持为手机号，不支持修改其他资料');
        $command = $payload + ['memberId' => $uid];
        return (new MemberManagementCommandServices())->execute($actorType, $operator->operatorId(), $action, $command, function () use ($uid, $action, $payload, $operator, $scope, $actorType): array {
            $member = CashierV3MemberModule::findManageableMember((string)$uid, $operator, $scope, true);
            if (!hash_equals($this->revision($member), (string)($payload['revision'] ?? ''))) throw new ValidateException('登录资料已变化，请刷新后重试');
            $audit = ['operator_type' => $actorType, 'operator_id' => $operator->operatorId(), 'source' => strtoupper($action)];
            if ($action === 'change-password') {
                $password = (string)($payload['newPassword'] ?? '');
                if (mb_strlen($password) < 6 || mb_strlen($password) > 20 || preg_match('/[\x00-\x1f\x7f]/', $password) || $password === '123456') throw new ValidateException('请设置6至20位密码，不能使用初始密码123456');
                // 沿用会员端现有密码验证格式；仅在用户当前输入时明文显示，不落库明文。
                if (!hash_equals((string)$member['pwd'], md5($password))) {
                    Db::name('user')->where('uid', $uid)->update(['pwd' => md5($password)]);
                    app()->make(MemberPhoneResetServices::class)->rotateLocked($uid, $audit, false);
                }
            } else {
                app()->make(MemberPhoneResetServices::class)->revokeLocked($uid, $audit);
            }
            Cache::delete('user_info_' . $uid);
            return $this->projection(Db::name('user')->where('uid', $uid)->find());
        });
    }

    private function projection(array $member): array
    {
        return ['memberId' => (int)$member['uid'], 'account' => (string)$member['phone'], 'bindings' => (new MemberWechatBindingReadServices())->bindings((int)$member['uid']), 'revision' => $this->revision($member)];
    }

    private function revision(array $member): string
    {
        $bindings = Db::name('wechat_user')->where('uid', (int)$member['uid'])->where('is_del', 0)->order('id')->column('id');
        $state = app()->make(MemberPhoneResetServices::class)->state((int)$member['uid']);
        return (new MemberManagementCommandServices())->fingerprint([(int)$member['uid'], (string)$member['phone'], (string)$member['pwd'], $bindings, (string)($state['nonce'] ?? '')]);
    }
}
