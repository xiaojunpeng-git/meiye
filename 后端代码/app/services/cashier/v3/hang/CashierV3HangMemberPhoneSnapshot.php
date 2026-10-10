<?php

namespace app\services\cashier\v3\hang;

/**
 * 挂单手机号是创建时的展示快照，不从会员现值回填历史挂单。
 * 快照绑定会员 ID，避免草稿切换会员后误展示前一位会员的联系方式。
 */
final class CashierV3HangMemberPhoneSnapshot
{
    /** 只接受服务端读取且与当前挂单会员一致的摘要，游客与错配资料均写空快照。 */
    public static function capture(int $memberId, array $memberSummary): array
    {
        if ($memberId <= 0 || (int)($memberSummary['memberId'] ?? 0) !== $memberId) {
            return ['memberId' => 0, 'phone' => ''];
        }

        return [
            'memberId' => $memberId,
            'phone' => mb_substr(trim((string)($memberSummary['phone'] ?? '')), 0, 32, 'UTF-8'),
        ];
    }

    /** 旧挂单没有快照时返回空值，绝不查询或推断会员当前手机号。 */
    public static function displayFromJson($json, int $memberId): string
    {
        if ($memberId <= 0) {
            return '';
        }
        $sidecar = json_decode((string)$json, true);
        $contact = is_array($sidecar) ? ($sidecar['memberContactSnapshot'] ?? null) : null;
        if (!is_array($contact) || (int)($contact['memberId'] ?? 0) !== $memberId) {
            return '';
        }

        return mb_substr(trim((string)($contact['phone'] ?? '')), 0, 32, 'UTF-8');
    }
}
