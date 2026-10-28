<?php

namespace app\services\cashier\v3\order;

/**
 * 把已授权、已筛选的销售订单投影为与订单中心表格一致的商品明细行。
 * 明细金额只读订单 Reader 的历史快照；分类取商品当前归属，整单金额仅放首行。
 */
final class CashierV3SalesOrderExportRows
{
    private const LINE_FIELDS = [
        'item_name', 'product_category', 'unit_price', 'quantity', 'craftsman', 'salesperson',
        'sales_manager', 'guide', 'line_amount',
    ];
    private const REPEATED_CONTEXT_FIELDS = [
        // 同一销售单拆成商品明细时，每行都需保留单据识别列和办理时间；整单金额仍只在首行。
        'business_type', 'order_no', 'event_completed_at',
        'sales_order_no', 'business_date', 'member_name', 'phone', 'store',
        'occurred_at', 'cashier', 'source', 'payment_status', 'order_status',
        'supplement', 'payment_completed_at',
    ];

    /** @return array<int,array<string,mixed>> */
    public static function project(array $record, array $selected): array
    {
        $fields = array_keys($selected);
        if (!array_intersect($fields, self::LINE_FIELDS)) return [self::normalize($selected)];
        $items = array_values(array_filter((array)($record['items'] ?? []), 'is_array'));
        if (!$items) {
            // 页面在旧单无明细时仍渲染一条摘要行；导出不能让这张订单消失。
            $items = [['name' => $record['item_summary'] ?? '', 'quantity' => $record['item_count'] ?? null]];
        }
        $rows = [];
        foreach ($items as $index => $item) {
            $row = self::normalize($selected);
            foreach ($fields as $field) {
                if (in_array($field, self::LINE_FIELDS, true)) {
                    $row[$field] = self::lineValue($field, $item);
                } elseif ($index > 0 && !in_array($field, self::REPEATED_CONTEXT_FIELDS, true)) {
                    // 整单金额、商品总数和自定义列只在第一明细出现；不擅自分摊。
                    $row[$field] = null;
                }
            }
            if (array_key_exists('member_name', $row) && (string)$row['member_name'] === '') $row['member_name'] = '游客';
            if ($index === 0 && array_key_exists('payment_method', $row)) $row['payment_method'] = self::paymentText($record);
            $rows[] = $row;
        }
        return $rows;
    }

    private static function normalize(array $selected): array
    {
        foreach ($selected as &$value) if (is_array($value)) $value = implode('、', array_map('strval', $value));
        unset($value);
        return $selected;
    }

    private static function lineValue(string $field, array $item)
    {
        switch ($field) {
            case 'item_name': return self::itemText($item);
            case 'product_category': return $item['category'] ?? '未分类';
            case 'unit_price': return $item['unitPrice'] ?? null;
            case 'quantity': return $item['quantity'] ?? null;
            case 'craftsman': return self::craftsmenText($item);
            case 'salesperson': return self::salespeopleText($item);
            case 'sales_manager': return self::names($item, 'salesManagers');
            case 'guide': return self::guidesText($item);
            case 'line_amount': return $item[($item['businessTag'] ?? '') === '权益' ? 'entitlementAmount' : 'amountBeforeCoupon'] ?? null;
        }
        return null;
    }

    /** 商品名称保留页面展示的购买/权益、类型和已冻结的业务标记。 */
    private static function itemText(array $item): string
    {
        $parts = [];
        if (!empty($item['businessTag'])) $parts[] = (string)$item['businessTag'];
        if (!empty($item['itemType'])) $parts[] = $item['itemType'] === '商品' ? '产品' : (string)$item['itemType'];
        $parts[] = (string)(($item['name'] ?? '') ?: '未命名商品');
        if (($item['itemType'] ?? '') === '商品' && ($item['isPresale'] ?? null) === true) $parts[] = '预售';
        elseif (($item['itemType'] ?? '') === '商品' && ($item['isNoOutbound'] ?? null) === true) $parts[] = '不出库';
        if (($item['itemType'] ?? '') === '项目' && !empty($item['isExperience'])) $parts[] = '体验';
        if (($item['itemType'] ?? '') === '项目' && in_array($item['serviceRecipientType'] ?? '', ['self', 'friend'], true)) {
            if (in_array($item['friendCountsAsCustomer'] ?? null, [false, 0, '0'], true)) $parts[] = '不算人数人次';
            elseif (($item['serviceRecipientType'] ?? '') === 'friend') $parts[] = '朋友算';
        }
        return implode(' ', $parts);
    }

    /** 与页面手艺人文本使用同一分配事实，保留类型、业绩、手工和项目数。 */
    private static function craftsmenText(array $item): string
    {
        $allocations = $item['craftsmenListAllocations'] ?? $item['laborPerformanceAllocations'] ?? null;
        if (!is_array($allocations)) return self::names($item, 'craftsmen');
        if (!$allocations) return (string)(($item['craftsmenSummary'] ?? '') ?: self::emptyPersonnelText($item, 'craftsmen'));
        $parts = [];
        foreach ($allocations as $person) {
            $person = (array)$person;
            $name = self::personName($person);
            if ($name === '') continue;
            $type = ($person['isPointCustomer'] ?? null) === true ? '点' : '轮';
            $count = $person['projectCount'] ?? null;
            $parts[] = $name . '（' . $type . '、' . self::wholeYuan($person['amount'] ?? null)
                . '、' . self::wholeYuan($person['laborFeeAmount'] ?? null)
                . '、' . ($count === null || $count === '' ? '—' : (string)$count) . '）';
        }
        return $parts ? implode('，', $parts) : self::emptyPersonnelText($item, 'craftsmen');
    }

    private static function salespeopleText(array $item): string
    {
        $parts = [];
        foreach (is_array($item['salespeople'] ?? null) ? $item['salespeople'] : [] as $person) {
            $person = (array)$person;
            $name = self::personName($person);
            if ($name === '') continue;
            $role = strtolower((string)($person['roleSnapshot'] ?? $person['role_snapshot'] ?? ''));
            $presale = ($person['isPreSale'] ?? null) === true || ($person['isPresale'] ?? null) === true
                || (bool)preg_match('/:presale$/D', $role);
            $parts[] = $name . '（' . ($presale ? '售前、' : '')
                . self::wholeYuan($person['salesPerformanceAmount'] ?? $person['performanceAmount'] ?? null) . '）';
        }
        return $parts ? implode('，', $parts) : self::emptyPersonnelText($item, 'salespeople');
    }

    private static function guidesText(array $item): string
    {
        $parts = [];
        foreach (is_array($item['guides'] ?? null) ? $item['guides'] : [] as $person) {
            $person = (array)$person;
            $name = self::personName($person);
            if ($name === '') continue;
            $round = (int)($person['guideRoundNo'] ?? $person['guide_round_no'] ?? 0);
            $parts[] = $name . '（' . ($round >= 1 && $round <= 3 ? '第' . $round . '轮' : '无') . '）';
        }
        return $parts ? implode('、', $parts) : self::emptyPersonnelText($item, 'guides');
    }

    private static function names(array $item, string $role): string
    {
        $names = [];
        foreach (is_array($item[$role] ?? null) ? $item[$role] : [] as $person) {
            $name = self::personName((array)$person);
            if ($name !== '') $names[] = $name;
        }
        return $names ? implode('、', $names) : self::emptyPersonnelText($item, $role);
    }

    /** 列表和导出共用服务端岗位能力；未知旧数据保持“—”，不得猜成未分配。 */
    private static function emptyPersonnelText(array $item, string $role): string
    {
        return (($item['assignmentAvailability'][$role] ?? false) === true) ? '未分配' : '—';
    }

    private static function personName(array $person): string
    {
        foreach (['name', 'employeeName', 'employee_name_snapshot'] as $key) {
            $name = trim((string)($person[$key] ?? ''));
            if ($name !== '') return $name;
        }
        return '';
    }

    private static function wholeYuan($value): string
    {
        return $value === null || $value === '' || !is_numeric($value) ? '—' : (string)(int)floor((float)$value + 0.5);
    }

    /** 记账收款按页面逐笔收款事实展示；这里的文本不替代其他金额列的分精度。 */
    private static function paymentText(array $record): string
    {
        $parts = [];
        foreach ((array)($record['paymentDetails'] ?? []) as $payment) {
            if (!is_array($payment) || empty($payment['methodName']) || !isset($payment['amount'])) continue;
            $amount = (float)$payment['amount'];
            $formatted = floor($amount) == $amount ? number_format($amount, 0) : number_format($amount, 2);
            $parts[] = trim((string)$payment['methodName']) . ' ¥' . $formatted;
        }
        return $parts ? implode('；', $parts) : '—';
    }
}
