<?php
declare(strict_types=1);

namespace app\services\user;

use mohe\exceptions\AuthException;
use think\facade\Db;

/**
 * 改号后的会员登录隔离。数据库撤销事件为权威，不依赖可过期/可丢失的缓存。
 * 只改变登录身份；会员 UID、资产、订单和员工/平台端会话均不迁移。
 */
class MemberPhoneResetServices
{
    /** 调用方须先锁会员行；与手机号替换共用事务，失败时连同微信解绑一起回滚。 */
    public function revokeLocked(int $uid, array $audit): void
    {
        $ids = Db::name('wechat_user')->where('uid', $uid)->column('id');
        Db::name('member_phone_login_reset')->insert([
            'uid' => $uid, 'nonce' => bin2hex(random_bytes(16)), 'phone_verified' => 0,
            'binding_ids' => json_encode(array_values($ids)),
            'operator_id' => (int)($audit['operator_id'] ?? 0),
            'source' => (string)($audit['source'] ?? 'PHONE_CHANGE'), 'created_at' => time(),
        ]);
        // 留存微信历史行，撤掉 UID，所有旧查询也不能再凭它找到原会员。
        // 事件保留原 UID -> 微信行 ID 的追溯关系，不保存原始微信会话密钥。
        Db::name('wechat_user')->where('uid', $uid)->update(['uid' => 0, 'is_del' => 1]);
    }

    /** 使用 (uid,id) 索引读取最新事件；不缓存，避免旧 worker/cache 放行已撤销凭证。 */
    public function state(int $uid): array
    {
        return Db::name('member_phone_login_reset')->where('uid', $uid)->order('id', 'desc')->find() ?: [];
    }

    /** 静默微信识别不能解除改号门禁；返回 null 表示必须重新授权手机号。 */
    public function silentProof(int $uid): ?string
    {
        $state = $this->state($uid);
        return $state && !(int)$state['phone_verified'] ? null : (string)($state['nonce'] ?? '');
    }

    /**
     * 在微信登录写事务中锁住原会员，重新核对微信服务端已验证的号码。
     * 旧授权缓存/旧 unionid 回退不得覆盖或认领改号后的原会员。
     */
    public function authorizeWechatLocked(int $uid, string $verifiedPhone): string
    {
        $user = Db::name('user')->where('uid', $uid)->lock(true)->find();
        if (!$user || (int)$user['is_del'] !== 0) $this->deny();
        $state = $this->state($uid);
        if ($state) {
            if ($verifiedPhone !== '' && !hash_equals((string)$user['phone'], $verifiedPhone)) $this->deny();
            if (!(int)$state['phone_verified']) {
                if ($verifiedPhone === '' || !hash_equals((string)$user['phone'], $verifiedPhone)) $this->deny();
                Db::name('member_phone_login_reset')->where('id', (int)$state['id'])->update(['phone_verified' => 1]);
            }
        }
        return (string)($state['nonce'] ?? '');
    }

    /**
     * 签发与改号使用相同的会员行锁。微信调用方传入本次认证获得的 proof，
     * 阻断“认证完成后、签发前又发生改号”的竞态；没有改号的历史凭证仍兼容。
     */
    public function issue(int $uid, ?string $expectedProof, callable $create): array
    {
        return Db::transaction(function () use ($uid, $expectedProof, $create): array {
            $user = Db::name('user')->where('uid', $uid)->lock(true)->find();
            if (!$user || (int)$user['is_del'] !== 0 || !(int)$user['status']) $this->deny();
            $state = $this->state($uid);
            $nonce = (string)($state['nonce'] ?? '');
            if (($state && !(int)$state['phone_verified'])
                || ($expectedProof !== null && !hash_equals($nonce, $expectedProof))) $this->deny();
            return $create($nonce);
        });
    }

    /** 每次会员 API 都校验撤销代际；重新授权不复活旧 token，改回同号码也不复活。 */
    public function assertToken(int $uid, string $proof): void
    {
        $state = $this->state($uid);
        if (($state && !(int)$state['phone_verified'])
            || !hash_equals((string)($state['nonce'] ?? ''), $proof)) $this->deny();
    }

    private function deny(): void
    {
        throw new AuthException('手机号已变更，请重新授权当前手机号登录', 410001);
    }
}
