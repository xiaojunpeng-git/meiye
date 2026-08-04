<?php

namespace app\services\product\product;

use mohe\exceptions\AdminException;

class StoreCardRuleServices
{
    public const NORMAL = 'normal';
    public const CHOICE_KIND = 'choice_kind';
    public const CHOICE_COUNT = 'choice_count';
    public const TIME = 'time';

    private const TYPES = [
        self::NORMAL,
        self::CHOICE_KIND,
        self::CHOICE_COUNT,
        self::TIME,
    ];

    public function preserveLockedDefinition(array $submitted, array $definition): array
    {
        foreach ([
            'card_rule_type',
            'card_rule_version',
            'card_choice_limit',
            'card_shared_times',
            'card_num',
            'card_num_type',
            'related',
        ] as $field) {
            if (array_key_exists($field, $definition)) {
                $submitted[$field] = $definition[$field];
            }
        }

        $validityFields = ['write_valid', 'days', 'section_time'];
        if ((int)($definition['spec_type'] ?? 0) === 0) {
            $submitted['attr'] = (array)($submitted['attr'] ?? []);
            $definitionAttr = (array)($definition['attr'] ?? []);
            foreach ($validityFields as $field) {
                if (array_key_exists($field, $definitionAttr)) {
                    $submitted['attr'][$field] = $definitionAttr[$field];
                }
            }
        } else {
            $submitted['attrs'] = array_values((array)($submitted['attrs'] ?? []));
            $definitionAttrs = array_values((array)($definition['attrs'] ?? []));
            foreach ($submitted['attrs'] as $index => &$submittedAttr) {
                $definitionAttr = (array)($definitionAttrs[$index] ?? []);
                foreach ($validityFields as $field) {
                    if (array_key_exists($field, $definitionAttr)) {
                        $submittedAttr[$field] = $definitionAttr[$field];
                    }
                }
            }
            unset($submittedAttr);
        }

        return $submitted;
    }

    public function normalizeForSave(array $data): array
    {
        if ((int)($data['product_type'] ?? 0) !== 5) {
            $data['card_rule_type'] = '';
            $data['card_rule_version'] = 0;
            $data['card_choice_limit'] = 0;
            $data['card_shared_times'] = 0;
            return $data;
        }

        $type = trim((string)($data['card_rule_type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new AdminException('请选择卡项规则');
        }

        $related = array_values((array)($data['related'] ?? []));
        if (!$related) {
            throw new AdminException('请添加卡内项目');
        }
        $this->assertUniqueProjects($related);

        $choiceLimit = 0;
        $sharedTimes = 0;

        if ($type === self::CHOICE_KIND) {
            $choiceLimit = $this->positiveInteger($data['card_choice_limit'] ?? 0, '最多可选项目种数');
            if ($choiceLimit > count($related)) {
                throw new AdminException('最多可选项目种数不能超过卡内项目总数');
            }
        }

        if ($type === self::CHOICE_COUNT) {
            $sharedTimes = $this->positiveInteger($data['card_shared_times'] ?? 0, '共享总次数');
        }

        foreach ($related as $index => &$row) {
            if (in_array($type, [self::NORMAL, self::CHOICE_KIND], true)) {
                $row['write_times'] = $this->positiveInteger(
                    $row['write_times'] ?? 0,
                    '第' . ($index + 1) . '个项目的可使用次数'
                );
                $row['writeoff_amount'] = 0;
            } elseif ($type === self::CHOICE_COUNT) {
                $row['write_times'] = 0;
                $row['writeoff_amount'] = 0;
            } else {
                $row['write_times'] = 0;
                $row['writeoff_amount'] = $this->nonNegativeMoney(
                    $row['writeoff_amount'] ?? 0,
                    '第' . ($index + 1) . '个项目的单次核销金额'
                );
            }
        }
        unset($row);

        $this->assertValidity($data, $type);

        $data['related'] = $related;
        $data['card_rule_type'] = $type;
        $data['card_rule_version'] = 1;
        $data['card_choice_limit'] = $choiceLimit;
        $data['card_shared_times'] = $sharedTimes;

        // Existing consumers keep receiving the legacy projection until their
        // card-rule integration is completed.
        if ($type === self::CHOICE_KIND) {
            $data['card_num'] = $choiceLimit;
            $data['card_num_type'] = 0;
        } elseif ($type === self::CHOICE_COUNT) {
            $data['card_num'] = $sharedTimes;
            $data['card_num_type'] = 1;
        } else {
            $data['card_num'] = 0;
            $data['card_num_type'] = 0;
        }

        return $data;
    }

    private function assertValidity(array $data, string $type): void
    {
        $variants = (int)($data['spec_type'] ?? 0) === 0
            ? [(array)($data['attr'] ?? [])]
            : array_values((array)($data['attrs'] ?? []));
        if (!$variants) {
            throw new AdminException('卡项价格与有效期信息不完整');
        }

        foreach ($variants as $variant) {
            $validity = (int)($variant['write_valid'] ?? 0);
            if (!in_array($validity, [1, 2, 3], true)) {
                throw new AdminException('请选择核销时效类型');
            }
            if ($type === self::TIME && $validity === 1) {
                throw new AdminException('时间卡不允许永久有效');
            }
        }
    }

    private function assertUniqueProjects(array $related): void
    {
        $seen = [];
        foreach ($related as $row) {
            $productId = (int)($row['product_id'] ?? $row['productId'] ?? 0);
            $unique = trim((string)($row['product_attr_unique'] ?? $row['unique'] ?? ''));
            if ($productId <= 0 || $unique === '') {
                throw new AdminException('卡内项目或规格参数有误');
            }
            $key = $productId . ':' . $unique;
            if (isset($seen[$key])) {
                throw new AdminException('同一项目规格不能重复添加');
            }
            $seen[$key] = true;
        }
    }

    private function positiveInteger($value, string $label): int
    {
        if ($value === '' || $value === null || !is_numeric($value) || (float)$value != (int)$value || (int)$value <= 0) {
            throw new AdminException($label . '必须是大于0的整数');
        }
        return (int)$value;
    }

    private function nonNegativeMoney($value, string $label): string
    {
        $raw = is_int($value) || is_string($value) ? trim((string)$value) : '';
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1) {
            throw new AdminException($label . '必须是整数元');
        }
        if (strlen($raw) > 10 || (strlen($raw) === 10 && strcmp($raw, '9999999999') === 1)) {
            throw new AdminException($label . '超出允许范围');
        }
        return (string)(int)$raw;
    }
}
