<?php

namespace app\services\product\product;

use app\services\cashier\v3\CashierV3ProjectLaborDefault;
use app\services\cashier\v3\CashierV3ScopeResolver;
use mohe\exceptions\AdminException;

class StoreCardRuleServices
{
    public const NORMAL = 'normal';
    public const CHOICE_KIND = 'choice_kind';
    public const CHOICE_COUNT = 'choice_count';
    public const TIME = 'time';
    public const COMBINATION = 'combination';

    private const TYPES = [
        self::NORMAL,
        self::CHOICE_KIND,
        self::CHOICE_COUNT,
        self::TIME,
        self::COMBINATION,
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

        // 组合定义随卡关系一并重建；售出时另存不可变快照，不让后续编辑改旧权益。
        $groups = $type === self::COMBINATION ? $this->validateCombinationGroups($related) : [];

        foreach ($related as $index => &$row) {
            // 卡内手工费按关联项目保存，允许 0 元；和项目通用默认值、已完成服务事实分开。
            $defaultLaborFee = 0;
            if (!array_key_exists('labor_fee', $row)) {
                $defaultLaborFee = array_key_exists('labor_fee_cents', $row)
                    ? intdiv(max(0, (int)$row['labor_fee_cents']), 100)
                    : intdiv(CashierV3ProjectLaborDefault::cents(
                        (int)($row['product_id'] ?? 0), CashierV3ScopeResolver::TENANT_SCOPE_ID
                    ), 100);
            }
            $row['labor_fee'] = $this->nonNegativeMoney(
                $row['labor_fee'] ?? $defaultLaborFee,
                '第' . ($index + 1) . '个项目的手工费'
            );
            $row['labor_fee_cents'] = (int)$row['labor_fee'] * 100;
            $usesIndependentTimes = in_array($type, [self::NORMAL, self::CHOICE_KIND], true)
                || ($type === self::COMBINATION
                    && $groups[(int)($row['combination_group_no'] ?? 0)]['rule'] === self::CHOICE_KIND);
            if ($usesIndependentTimes) {
                $row['write_times'] = $this->positiveInteger(
                    $row['write_times'] ?? 0,
                    '第' . ($index + 1) . '个项目的可使用次数'
                );
                $row['writeoff_amount'] = 0;
            } elseif ($type === self::CHOICE_COUNT || $type === self::COMBINATION) {
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
        $data['card_rule_version'] = $type === self::COMBINATION ? 2 : 1;
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

    /**
     * 每组独立使用现有任选种数或任选次数语义；一个项目只能归属一组，
     * 使核销时可由项目直接定位组，不增加收银端“选择组合”步骤。
     * @return array<int,array{rule:string,choice_limit:int,shared_times:int}>
     */
    private function validateCombinationGroups(array $related): array
    {
        $groups = [];
        $projectOwners = [];
        foreach ($related as $row) {
            $no = (int)($row['combination_group_no'] ?? 0);
            $name = trim((string)($row['combination_group_name'] ?? ''));
            $rule = trim((string)($row['combination_group_rule'] ?? ''));
            $choice = (int)($row['combination_choice_limit'] ?? 0);
            $shared = (int)($row['combination_shared_times'] ?? 0);
            $projectId = (int)($row['product_id'] ?? $row['productId'] ?? 0);
            if ($no <= 0 || $no > 20 || $name === '' || mb_strlen($name) > 64
                || !in_array($rule, [self::CHOICE_KIND, self::CHOICE_COUNT], true)
                || ($rule === self::CHOICE_KIND && ($choice <= 0 || $shared !== 0))
                || ($rule === self::CHOICE_COUNT && ($shared <= 0 || $choice !== 0))) {
                throw new AdminException('组合卡分组名称、类型或次数配置无效');
            }
            $definition = ['name' => $name, 'rule' => $rule, 'choice_limit' => $choice,
                'shared_times' => $shared];
            if (isset($groups[$no]) && $groups[$no]['definition'] !== $definition) {
                throw new AdminException('同一组合的规则配置必须一致');
            }
            $groups[$no]['definition'] = $definition;
            $groups[$no]['count'] = (int)($groups[$no]['count'] ?? 0) + 1;
            if (isset($projectOwners[$projectId]) && $projectOwners[$projectId] !== $no) {
                throw new AdminException('同一项目不能放进多个组合');
            }
            $projectOwners[$projectId] = $no;
        }
        ksort($groups, SORT_NUMERIC);
        if (!$groups || array_keys($groups) !== range(1, count($groups))) {
            throw new AdminException('组合必须从组合1开始连续设置');
        }
        foreach ($groups as $no => $entry) {
            if ($entry['definition']['rule'] === self::CHOICE_KIND
                && $entry['definition']['choice_limit'] > $entry['count']) {
                throw new AdminException('第' . $no . '个组合的最多可选种数不能超过项目数');
            }
            $groups[$no] = $entry['definition'];
        }
        return $groups;
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
