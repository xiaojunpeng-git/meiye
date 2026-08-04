<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use app\services\mobile\protocol\MobileApiException;
use think\facade\Db;

/** Transactional authority for every new mobile merchant-session revocation. */
final class MobileAuthRevocationServices
{
    /**
     * The caller may run inside an existing personnel/authorization transaction.
     * A failure is deliberately rethrown so the original change rolls back too.
     */
    public function revokeAllInCurrentTransaction(int $employeeId, string $reason, int $actorEmployeeId = 0, string $requestId = ''): void
    {
        if ($employeeId <= 0 || !in_array($reason, ['AUTH_CHANGED', 'EMPLOYEE_DISABLED', 'ADMIN_SIGN_OUT_ALL', 'SIGNED_OUT'], true)) {
            throw MobileApiException::business('AUTH_REQUIRED', '商家会话撤销参数无效。');
        }
        $now = time();
        $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
        $state = Db::name('employee_mobile_auth_state')->where('employee_id', $employeeId)->lock(true)->find();
        if (!$employee) {
            throw MobileApiException::business('EMPLOYEE_NOT_FOUND', '员工认证状态不存在。');
        }
        // 新建员工不会经过历史迁移。首次变更手机授权时，在同一事务补齐
        // 认证版本状态，随后仍按统一撤销链路推进版本和租约。
        if (!$state) {
            try {
                Db::name('employee_mobile_auth_state')->insert([
                    'employee_id' => $employeeId,
                    'phone_binding_version' => 1,
                    'auth_version' => max(1, (int)($employee['auth_version'] ?? 1)),
                    'merchant_session_epoch' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Throwable $exception) {
                // 并发的首次授权可能已写入唯一状态行，重新加锁读取即可。
            }
            $state = Db::name('employee_mobile_auth_state')->where('employee_id', $employeeId)->lock(true)->find();
        }
        if (!$state) {
            throw MobileApiException::business('AUTH_REQUIRED', '员工认证状态初始化失败。');
        }
        $lease = Db::name('mobile_merchant_lease')->where('employee_id', $employeeId)->lock(true)->find();
        if (!$lease) {
            try {
                Db::name('mobile_merchant_lease')->insert(['employee_id' => $employeeId, 'current_session_id' => null, 'current_epoch' => 1, 'state' => 'EMPTY', 'created_at' => $now, 'updated_at' => $now]);
            } catch (\Throwable $exception) {
                // A concurrent issuer may have inserted the unique lease. Re-lock below.
            }
            $lease = Db::name('mobile_merchant_lease')->where('employee_id', $employeeId)->lock(true)->find();
        }
        if (!$lease) throw MobileApiException::business('AUTH_REQUIRED', '商家会话租约不可用。');
        $endState = $reason === 'EMPLOYEE_DISABLED' ? 'EMPLOYEE_DISABLED' : ($reason === 'ADMIN_SIGN_OUT_ALL' ? 'ADMIN_SIGNED_OUT' : 'AUTH_REVOKED');
        $endCause = $reason === 'ADMIN_SIGN_OUT_ALL' ? 'ADMIN_SIGN_OUT_ALL' : ($reason === 'EMPLOYEE_DISABLED' ? 'EMPLOYEE_DISABLED' : 'AUTH_CHANGED');
        Db::name('mobile_merchant_session')->where('employee_id', $employeeId)->where('state', 'ACTIVE')->lock(true)->select();
        Db::name('mobile_merchant_session')->where('employee_id', $employeeId)->where('state', 'ACTIVE')->update([
            'state' => $endState, 'revoked_at' => $now, 'session_end_cause' => $endCause, 'updated_at' => $now,
        ]);
        Db::name('mobile_merchant_session_projection')->whereIn('session_id', Db::name('mobile_merchant_session')->where('employee_id', $employeeId)->column('session_id'))
            ->where('state', 'ACTIVE')->update(['state' => 'CLOSED', 'updated_at' => $now]);
        $authVersion = (int)$state['auth_version'] + 1;
        $epoch = (int)$state['merchant_session_epoch'] + 1;
        Db::name('employee_mobile_auth_state')->where('id', (int)$state['id'])->update([
            'auth_version' => $authVersion, 'merchant_session_epoch' => $epoch, 'updated_at' => $now,
        ]);
        Db::name('mobile_merchant_lease')->where('id', (int)$lease['id'])->update([
            'current_session_id' => null, 'current_epoch' => max((int)$lease['current_epoch'] + 1, $epoch), 'state' => 'REVOKED', 'updated_at' => $now,
        ]);
        Db::name('mobile_merchant_security_audit')->insert([
            'employee_id' => $employeeId, 'actor_employee_id' => $actorEmployeeId,
            'event_code' => $reason === 'EMPLOYEE_DISABLED' ? 'MERCHANT_SESSION_EMPLOYEE_DISABLED' : ($reason === 'ADMIN_SIGN_OUT_ALL' ? 'MERCHANT_SESSION_ADMIN_SIGNED_OUT' : 'MERCHANT_SESSION_AUTH_REVOKED'),
            'session_id' => null, 'lease_epoch_before' => (int)$lease['current_epoch'], 'lease_epoch_after' => max((int)$lease['current_epoch'] + 1, $epoch),
            'auth_version_before' => (int)$state['auth_version'], 'auth_version_after' => $authVersion, 'installation_digest' => null,
            'request_id' => $requestId, 'reason_code' => $reason, 'payload' => '{}', 'occurred_at' => $now, 'recorded_at' => $now,
        ]);
    }
}
