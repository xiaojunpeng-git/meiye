<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------

declare (strict_types=1);

namespace app\services\user;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * The only writer for a member's phone identity mapping.
 *
 * Callers create the member row inside the supplied callback only after the
 * phone identity row has been reserved. This keeps the identity -> member lock
 * order stable for every new writer that is migrated to this service.
 */
class CanonicalUserIdentityServices extends BaseServices
{
    private const IDENTITY_BOUND = 'BOUND';
    private const IDENTITY_RELEASED = 'RELEASED';
    private const IDENTITY_RESERVED = 'RESERVED';
    private const IDENTITY_LEGACY_CONFLICT = 'LEGACY_CONFLICT';

    public function phoneDigest(string $phone): string
    {
        $phone = trim($phone);
        $this->assertPhone($phone);
        return hash('sha256', $phone);
    }

    /**
     * Returns the already-bound uid or creates and binds one atomically.
     */
    public function ensureUserIdentity(string $phone, callable $createUser, array $audit = []): int
    {
        $phone = trim($phone);
        $digest = $this->phoneDigest($phone);

        return Db::transaction(function () use ($phone, $digest, $createUser, $audit): int {
            $identity = $this->lockOrReserveIdentity($digest);
            $boundUid = (int)($identity['bound_uid'] ?? 0);

            // 注销不释放号码；历史映射即使是 RELEASED 也必须核对 user 全状态。
            $owners = $this->lockPhoneOwners($phone);
            if (count($owners) > 1) {
                throw new AdminException('该手机号码存在历史重复会员，请联系管理员处理');
            }
            if ($owners) {
                $owner = $owners[0];
                if ((int)$owner['is_del'] !== 0 || $this->hasCancellationTime($owner)) {
                    throw new AdminException('该手机号码属于已注销会员，请在会员列表恢复原会员');
                }
                if (($identity['state'] ?? '') === self::IDENTITY_BOUND && $boundUid === (int)$owner['uid']) {
                    return $boundUid;
                }
                if (($identity['state'] ?? '') === self::IDENTITY_BOUND) {
                    throw new AdminException('该手机号码的会员身份不一致，请联系管理员处理');
                }
                $this->bindReservedIdentity($identity, (int)$owner['uid'], $phone, $audit, 'BIND_LEGACY_OWNER');
                return (int)$owner['uid'];
            }
            if (($identity['state'] ?? '') === self::IDENTITY_BOUND) {
                throw new AdminException('该手机号码的会员身份不一致，请联系管理员处理');
            }

            $uid = $this->requireUid($createUser());
            $this->lockUser($uid);
            $this->bindReservedIdentity($identity, $uid, $phone, $audit, 'BIND');
            return $uid;
        });
    }

    /**
     * Binds a verified phone to an existing member. It never creates a member.
     */
    public function bindPhoneIdentity(int $uid, string $phone, array $audit = []): int
    {
        $phone = trim($phone);
        $digest = $this->phoneDigest($phone);

        return Db::transaction(function () use ($uid, $phone, $digest, $audit): int {
            $identity = $this->lockOrReserveIdentity($digest);
            $boundUid = (int)($identity['bound_uid'] ?? 0);
            $owners = $this->lockPhoneOwners($phone);
            foreach ($owners as $owner) {
                if ((int)$owner['uid'] !== $uid) {
                    throw new AdminException('该手机号码已属于其他会员');
                }
            }
            if (($identity['state'] ?? '') === self::IDENTITY_BOUND && $boundUid !== $uid) {
                throw new AdminException('该手机号码已属于其他会员');
            }

            $this->lockUser($uid);
            $this->bindReservedIdentity($identity, $uid, $phone, $audit, 'BIND');
            return $uid;
        });
    }

    /**
     * Replaces a member's phone without ever updating eb_user.phone directly.
     */
    public function replacePhoneIdentity(int $uid, string $phone, array $audit = []): int
    {
        $phone = trim($phone);
        $newDigest = $this->phoneDigest($phone);

        return Db::transaction(function () use ($uid, $phone, $newDigest, $audit): int {
            $before = Db::name('user')->where('uid', $uid)->field('uid,phone,is_del')->find();
            if (!$before || (int)$before['is_del'] !== 0) {
                throw new AdminException('会员不存在或已注销');
            }

            $oldDigest = $this->digestForStoredPhone((string)$before['phone']);
            $oldIdentity = $oldDigest === '' ? null : $this->lockIdentity($oldDigest);
            $newIdentity = $oldDigest === $newDigest && $oldIdentity !== null
                ? $oldIdentity
                : $this->lockOrReserveIdentity($newDigest);
            foreach ($this->lockPhoneOwners($phone) as $owner) {
                if ((int)$owner['uid'] !== $uid) {
                    throw new AdminException('该手机号码已属于其他会员');
                }
            }
            $boundUid = (int)($newIdentity['bound_uid'] ?? 0);
            if (($newIdentity['state'] ?? '') === self::IDENTITY_BOUND && $boundUid !== $uid) {
                throw new AdminException('该手机号码已属于其他会员');
            }

            $user = $this->lockUser($uid);
            if ($this->digestForStoredPhone((string)$user['phone']) !== $oldDigest) {
                throw new AdminException('会员手机号已变化，请重试');
            }

            if ($oldIdentity !== null && $oldDigest !== $newDigest && (int)$oldIdentity['bound_uid'] === $uid) {
                $this->releaseIdentity($oldIdentity, $audit, 'RELEASE');
            }
            $this->bindReservedIdentity($newIdentity, $uid, $phone, $audit, 'BIND');
            // 真正换号才撤销登录；重复保存同号不踢人，初次补号仍走原绑定流程。
            if ((string)$user['phone'] !== '' && (string)$user['phone'] !== $phone) {
                app()->make(MemberPhoneResetServices::class)->revokeLocked($uid, $audit);
            }
            return $uid;
        });
    }

    /** 仅活跃会员主动清空当前号码时释放映射；注销流程不得调用此入口。 */
    public function clearPhoneIdentity(int $uid, array $audit = []): void
    {
        Db::transaction(function () use ($uid, $audit): void {
            $before = Db::name('user')->where('uid', $uid)->field('uid,phone,is_del')->find();
            if (!$before || (int)$before['is_del'] !== 0) {
                throw new AdminException('会员不存在或已注销');
            }
            $oldPhone = (string)$before['phone'];
            $digest = $this->digestForStoredPhone($oldPhone);
            $identity = $digest === '' ? null : $this->lockIdentity($digest);
            $user = $this->lockUser($uid);
            if ((string)$user['phone'] !== $oldPhone || (int)$user['is_del'] !== 0) {
                throw new AdminException('会员手机号已变化，请重试');
            }
            if ($identity && (int)($identity['bound_uid'] ?? 0) > 0
                && (int)$identity['bound_uid'] !== $uid) {
                throw new AdminException('手机号身份不一致，请联系管理员处理');
            }
            if ($identity && (string)$identity['state'] === self::IDENTITY_BOUND) {
                $this->releaseIdentity($identity, $audit, 'RELEASE_ON_PHONE_CLEAR');
            }
            Db::name('user')->where('uid', $uid)->update(['phone' => '']);
            if ($oldPhone !== '') {
                app()->make(MemberPhoneResetServices::class)->revokeLocked($uid, $audit);
            }
        });
    }

    /** Cancellation never releases a phone; legacy callers fail closed. */
    public function releasePhoneIdentity(int $uid, array $audit = [], bool $useOuterTransaction = false): void
    {
        // 保留原接口以便旧调用者显式收到失败，而非静默释放注销号码。
        throw new AdminException('会员注销不释放手机号身份');
    }

    /**
     * Empty phone is deliberately not an identity and must stay off the map.
     */
    public function createUserWithoutPhoneIdentity(callable $createUser): int
    {
        return $this->requireUid($createUser());
    }

    private function lockOrReserveIdentity(string $digest): array
    {
        $identity = $this->lockIdentity($digest);
        if ($identity) {
            if (($identity['state'] ?? '') === self::IDENTITY_LEGACY_CONFLICT) {
                throw new AdminException('该手机号码存在历史重复数据，请联系总部处理');
            }
            return $identity;
        }

        $now = time();
        try {
            Db::name('user_phone_identity')->insert([
                'phone_digest' => $digest,
                'bound_uid' => null,
                'state' => self::IDENTITY_RESERVED,
                'identity_version' => 1,
                'bound_at' => 0,
                'released_at' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // A concurrent writer may have reserved the unique digest first.
        }

        $identity = $this->lockIdentity($digest);
        if (!$identity) {
            throw new AdminException('手机号身份占位失败');
        }
        if (($identity['state'] ?? '') === self::IDENTITY_LEGACY_CONFLICT) {
            throw new AdminException('该手机号码存在历史重复数据，请联系总部处理');
        }
        return $identity;
    }

    private function lockIdentity(string $digest): ?array
    {
        $identity = Db::name('user_phone_identity')->where('phone_digest', $digest)->lock(true)->find();
        return $identity ? (array)$identity : null;
    }

    private function lockUser(int $uid): array
    {
        $user = Db::name('user')->where('uid', $uid)->field('uid,phone,is_del')->lock(true)->find();
        if (!$user) {
            throw new AdminException('会员不存在');
        }
        return (array)$user;
    }

    /** 以 user 当前号码为最终占用依据，含正常、停用和注销会员。 */
    private function lockPhoneOwners(string $phone): array
    {
        return Db::name('user')->where('phone', $phone)->field('uid,is_del,delete_time')->order('uid')->limit(2)->lock(true)->select()->toArray();
    }

    private function hasCancellationTime(array $owner): bool
    {
        $time = $owner['delete_time'] ?? null;
        return !($time === null || $time === '' || $time === 0 || $time === '0'
            || $time === '0000-00-00 00:00:00');
    }

    private function bindReservedIdentity(array $identity, int $uid, string $phone, array $audit, string $action): void
    {
        $state = (string)($identity['state'] ?? '');
        $boundUid = (int)($identity['bound_uid'] ?? 0);
        if ($state === self::IDENTITY_BOUND && $boundUid === $uid) {
            Db::name('user')->where('uid', $uid)->update(['phone' => $phone]);
            return;
        }
        if (!in_array($state, [self::IDENTITY_RESERVED, self::IDENTITY_RELEASED], true)) {
            throw new AdminException('手机号身份不可绑定');
        }

        $beforeVersion = (int)$identity['identity_version'];
        $now = time();
        $updated = Db::name('user_phone_identity')->where('id', (int)$identity['id'])->update([
            'bound_uid' => $uid,
            'state' => self::IDENTITY_BOUND,
            'identity_version' => $beforeVersion + 1,
            'bound_at' => $now,
            'released_at' => 0,
            'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            throw new AdminException('手机号身份绑定失败');
        }
        Db::name('user')->where('uid', $uid)->update(['phone' => $phone]);
        $persistedPhone = (string)Db::name('user')->where('uid', $uid)->value('phone');
        if ($persistedPhone !== $phone) {
            throw new AdminException('会员手机号更新失败');
        }
        $this->writeAudit($identity, $uid, self::IDENTITY_BOUND, $beforeVersion + 1, $action, $audit);
    }

    private function releaseIdentity(array $identity, array $audit, string $action): void
    {
        $beforeVersion = (int)$identity['identity_version'];
        $now = time();
        $updated = Db::name('user_phone_identity')->where('id', (int)$identity['id'])->update([
            'bound_uid' => null,
            'state' => self::IDENTITY_RELEASED,
            'identity_version' => $beforeVersion + 1,
            'released_at' => $now,
            'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            throw new AdminException('手机号身份释放失败');
        }
        $this->writeAudit($identity, null, self::IDENTITY_RELEASED, $beforeVersion + 1, $action, $audit);
    }

    private function writeAudit(array $identity, ?int $afterUid, string $afterState, int $afterVersion, string $action, array $audit): void
    {
        Db::name('user_phone_identity_audit')->insert([
            'identity_id' => (int)$identity['id'],
            'phone_digest' => (string)$identity['phone_digest'],
            'before_uid' => !empty($identity['bound_uid']) ? (int)$identity['bound_uid'] : null,
            'after_uid' => $afterUid,
            'before_state' => (string)$identity['state'],
            'after_state' => $afterState,
            'before_version' => (int)$identity['identity_version'],
            'after_version' => $afterVersion,
            'action' => $action,
            'operator_type' => (string)($audit['operator_type'] ?? 'SYSTEM'),
            'operator_id' => (int)($audit['operator_id'] ?? 0),
            'source' => (string)($audit['source'] ?? 'CANONICAL_IDENTITY'),
            'request_id' => (string)($audit['request_id'] ?? ''),
            'occurred_at' => time(),
            'recorded_at' => time(),
        ]);
    }

    private function digestForStoredPhone(string $phone): string
    {
        $phone = trim($phone);
        return preg_match('/^1[3-9][0-9]{9}$/', $phone) ? hash('sha256', $phone) : '';
    }

    private function assertPhone(string $phone): void
    {
        if (!preg_match('/^1[3-9][0-9]{9}$/', $phone)) {
            throw new AdminException('手机号格式错误');
        }
    }

    private function requireUid($value): int
    {
        if (is_array($value)) {
            $value = $value['uid'] ?? 0;
        } elseif (is_object($value)) {
            $value = $value->uid ?? 0;
        }
        $uid = (int)$value;
        if ($uid <= 0) {
            throw new AdminException('会员创建失败');
        }
        return $uid;
    }
}
