<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use app\services\employee\EmployeeInternalAccountServices;
use app\services\organization\OrganizationScopeService;
use app\services\mobile\protocol\MobileApiException;
use think\facade\Db;

/** Read and update the authenticated employee's merchant profile. */
final class MobileMerchantProfileServices
{
    public function account(array $context): array
    {
        $employeeId = (int)($context['employeeId'] ?? 0);
        $account = app()->make(EmployeeInternalAccountServices::class)->getByEmployeeId($employeeId);
        if (!$account || (int)($account['status'] ?? 0) !== 1) {
            throw MobileApiException::business('AUTH_VERSION_CHANGED', '员工统一账号已失效，请重新进入商家端。');
        }
        $employee = Db::name('employee')->where('id', $employeeId)->where('is_del', 0)
            ->field('id,name,phone,status')->find() ?: [];
        return [
            'employee' => [
                'id' => (string)($employee['id'] ?? $employeeId),
                'name' => (string)($employee['name'] ?? $context['staffName'] ?? '员工'),
                'phone' => (string)($employee['phone'] ?? ''),
                'status' => (int)($employee['status'] ?? 0) === 1 ? 'ACTIVE' : 'INACTIVE',
            ],
            'account' => (string)($account['account'] ?? ''),
            'passwordMasked' => '••••••••',
            'credentialVersion' => (int)($account['credential_version'] ?? 0),
        ];
    }

    public function scope(array $context): array
    {
        $storeIds = array_values(array_unique(array_filter(array_map('intval', (array)($context['visibleStoreIds'] ?? [])))));
        /** @var OrganizationScopeService $scopeService */
        $scopeService = app()->make(OrganizationScopeService::class);
        return [
            'mode' => (string)($context['dataScopeMode'] ?? 'NONE'),
            'activeStoreId' => (int)($context['storeId'] ?? 0),
            'activeOrganizationId' => (string)($context['organizationId'] ?? ''),
            'tree' => $scopeService->buildPickerTree($storeIds),
            'sourceExplanation' => '按当前员工数据权限裁剪组织与门店范围。',
        ];
    }

    public function changeCredentials(array $context, array $input): array
    {
        $employeeId = (int)($context['employeeId'] ?? 0);
        $currentPassword = (string)($input['currentPassword'] ?? '');
        $account = trim((string)($input['account'] ?? ''));
        $newPassword = (string)($input['newPassword'] ?? '');
        if ($account === '' || $newPassword === '') {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '请填写登录账号和新密码。', 'credentials');
        }
        try {
            $result = Db::transaction(function () use ($employeeId, $currentPassword, $account, $newPassword, $context): array {
                $saved = app()->make(EmployeeInternalAccountServices::class)->changeOwnCredentials(
                    $employeeId,
                    $currentPassword,
                    $account,
                    $newPassword,
                    [
                        'operator_id' => $employeeId,
                        'operator_name' => (string)($context['staffName'] ?? ''),
                        'operator_ip' => (string)app('request')->ip(),
                    ]
                );
                app()->make(MobileAuthRevocationServices::class)->revokeAllInCurrentTransaction(
                    $employeeId,
                    'AUTH_CHANGED',
                    $employeeId,
                    (string)(($context['requestMetadata'] ?? [])['requestId'] ?? '')
                );
                return $saved;
            });
        } catch (MobileApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw MobileApiException::business('CREDENTIAL_UPDATE_FAILED', $exception->getMessage() ?: '账号资料保存失败。');
        }
        return ['account' => (string)($result['account'] ?? $account), 'reauthRequired' => true];
    }
}
