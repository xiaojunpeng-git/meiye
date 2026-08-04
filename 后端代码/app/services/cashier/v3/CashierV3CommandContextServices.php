<?php
namespace app\services\cashier\v3;

/**
 * command.contexts 结构级严格校验 + 精确身份合同绑定。
 */
class CashierV3CommandContextServices
{
    /**
     * @param array $rawContexts
     * @param array $contract CashierV3ContextPolicy::resolve 返回值
     * @return array<int,array{kind:string,id:string,expected_version:int,role?:string}>
     */
    public function validate(array $rawContexts, array $contract): array
    {
        $action = (string)($contract['action'] ?? '');
        $required = array_values((array)($contract['required'] ?? []));
        $allowed = array_values((array)($contract['allowed'] ?? []));
        $identities = array_values((array)($contract['identities'] ?? []));
        $deferredIdentityKinds = array_values(array_unique(array_map(
            'strval',
            (array)($contract['deferred_identity_kinds'] ?? [])
        )));
        foreach ($deferredIdentityKinds as $kind) {
            CashierV3ResourceKindCatalog::assertKnown($kind);
            if (!in_array($kind, $allowed, true)) {
                throw new \LogicException(sprintf(
                    'deferred identity kind %s 未包含在 allowed 合同中',
                    $kind
                ));
            }
        }

        if (!$rawContexts) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少有效的既有资源版本，请刷新当前工作台后重试。',
                ['action' => $action, 'reason' => 'contexts_empty']
            );
        }
        if (array_keys($rawContexts) !== range(0, count($rawContexts) - 1)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象版本格式无效，请刷新当前工作台后重试。',
                ['action' => $action, 'reason' => 'contexts_not_list']
            );
        }

        $normalized = [];
        $seen = [];
        foreach ($rawContexts as $index => $context) {
            $item = $this->normalizeOne($context, (int)$index, $action);
            if (!in_array($item['kind'], $allowed, true)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作携带了不适用的操作对象，请刷新当前工作台后重试。',
                    ['action' => $action, 'kind' => $item['kind'], 'reason' => 'kind_not_allowed']
                );
            }
            $dedupeKey = $item['kind'] . ':' . $item['id'];
            if (isset($seen[$dedupeKey])) {
                if ($seen[$dedupeKey] !== $item['expected_version']) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作对同一对象给出了不一致的版本，请刷新当前工作台后重试。',
                        ['action' => $action, 'kind' => $item['kind'], 'reason' => 'duplicated_kind_version_mismatch']
                    );
                }
                continue;
            }
            $seen[$dedupeKey] = $item['expected_version'];
            $normalized[] = $item;
        }

        // ---- 精确身份合同：contexts 必须与 identities 逐项相等 ----
        if ($identities) {
            $normalized = $this->bindIdentities(
                $normalized,
                $identities,
                $action,
                $deferredIdentityKinds
            );
        } else {
            // 无精确身份时仍做 kind 级必需／数量校验（兼容旧合同）
            $presentKinds = [];
            $kindCounts = [];
            foreach ($normalized as $item) {
                $presentKinds[$item['kind']] = true;
                $kindCounts[$item['kind']] = (int)($kindCounts[$item['kind']] ?? 0) + 1;
            }
            foreach ($required as $requiredKind) {
                if (!isset($presentKinds[$requiredKind])) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作缺少必需的对象版本，请刷新当前工作台后重试。',
                        ['action' => $action, 'kind' => $requiredKind, 'reason' => 'required_kind_missing']
                    );
                }
            }
            foreach ((array)($contract['min_counts'] ?? []) as $kind => $minCount) {
                $kind = (string)$kind;
                $minCount = (int)$minCount;
                if ($kind === '' || $minCount < 1) {
                    continue;
                }
                if ((int)($kindCounts[$kind] ?? 0) < $minCount) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作缺少必需的对象版本，请刷新当前工作台后重试。',
                        [
                            'action' => $action,
                            'kind' => $kind,
                            'reason' => 'required_kind_count_short',
                            'expected' => $minCount,
                            'actual' => (int)($kindCounts[$kind] ?? 0),
                        ]
                    );
                }
            }
        }

        return $this->sortForLocking($normalized);
    }

    /**
     * contexts 必须恰好覆盖 identities（同 kind+id）；多传无关对象拒绝；错 ID 拒绝。
     *
     * @param array<int,array> $normalized
     * @param array<int,array> $identities
     * @return array<int,array>
     */
    protected function bindIdentities(
        array $normalized,
        array $identities,
        string $action,
        array $deferredIdentityKinds = []
    ): array
    {
        $byKey = [];
        foreach ($normalized as $item) {
            $byKey[$item['kind'] . ':' . $item['id']] = $item;
        }

        $bound = [];
        $requiredKeys = [];
        foreach ($identities as $identity) {
            $kind = (string)($identity['kind'] ?? '');
            $id = (string)($identity['id'] ?? '');
            $role = (string)($identity['role'] ?? $kind);
            $required = (bool)($identity['required'] ?? true);
            if ($kind === '' || $id === '') {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的对象身份合同不完整，请刷新当前工作台后重试。',
                    ['action' => $action, 'role' => $role, 'reason' => 'identity_incomplete']
                );
            }
            CashierV3ResourceKindCatalog::assertKnown($kind);
            $key = $kind . ':' . $id;
            if ($required) {
                $requiredKeys[$key] = true;
                if (!isset($byKey[$key])) {
                    // 同 kind 但错误 ID：专门指出
                    $sameKindWrongId = false;
                    foreach ($normalized as $item) {
                        if ($item['kind'] === $kind && $item['id'] !== $id) {
                            $sameKindWrongId = true;
                            break;
                        }
                    }
                    throw CashierV3CommandException::invalidContext(
                        '本次操作的对象版本与业务对象不一致，请刷新当前工作台后重试。',
                        [
                            'action' => $action,
                            'kind' => $kind,
                            'role' => $role,
                            'expected_id' => $id,
                            'reason' => $sameKindWrongId ? 'identity_id_mismatch' : 'identity_missing',
                        ]
                    );
                }
            }
            if (isset($byKey[$key])) {
                $item = $byKey[$key];
                $item['role'] = $role;
                $bound[$key] = $item;
            }
        }

        // 多传同 kind 的无关对象：contexts 中有 identities 未声明的 key → 拒绝
        foreach ($byKey as $key => $item) {
            if (!isset($bound[$key]) && !isset($requiredKeys[$key])) {
                if (in_array($item['kind'], $deferredIdentityKinds, true)) {
                    $item['role'] = $item['kind'];
                    $bound[$key] = $item;
                    continue;
                }
                // 检查是否有任何 identity 声明了该 kind+id
                $declared = false;
                foreach ($identities as $identity) {
                    if (($identity['kind'] ?? '') === $item['kind'] && (string)($identity['id'] ?? '') === $item['id']) {
                        $declared = true;
                        break;
                    }
                }
                if (!$declared) {
                    throw CashierV3CommandException::invalidContext(
                        '本次操作携带了无关的操作对象，请刷新当前工作台后重试。',
                        [
                            'action' => $action,
                            'kind' => $item['kind'],
                            'id' => $item['id'],
                            'reason' => 'extra_context_not_in_identity_contract',
                        ]
                    );
                }
            }
        }

        return array_values($bound);
    }

    public function sortForLocking(array $contexts): array
    {
        usort($contexts, static function (array $left, array $right): int {
            return CashierV3ResourceKindCatalog::compareResources(
                (string)$left['kind'],
                (string)$left['id'],
                (string)$right['kind'],
                (string)$right['id']
            );
        });
        return array_values($contexts);
    }

    public function fingerprint(array $normalizedContexts): string
    {
        $parts = [];
        foreach ($normalizedContexts as $item) {
            if (!is_array($item)
                || !isset($item['kind'], $item['id'])
                || !array_key_exists('expected_version', $item)
            ) {
                throw CashierV3CommandException::invalidContext('对象版本指纹只能基于已校验的 contexts 生成。');
            }
            $scope = $item['scope'] ?? null;
            $scopeSignature = $scope instanceof CashierV3ResourceScope ? $scope->signature() : '-';
            $parts[] = $scopeSignature . '|' . $item['kind'] . '|' . $item['id'] . '|' . $item['expected_version'];
        }
        return hash('sha256', implode(';', $parts));
    }

    private function normalizeOne($context, int $index, string $action): array
    {
        if (!is_array($context)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象版本格式无效，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'reason' => 'context_not_object']
            );
        }

        $kind = trim((string)($context['kind'] ?? ''));
        if ($kind === '' || $kind === 'none' || $kind === 'unknown') {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少有效的对象类型，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'kind_invalid']
            );
        }
        if (!CashierV3ResourceKindCatalog::isKnown($kind)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作携带了未登记的对象类型，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'kind_unknown']
            );
        }

        $rawId = $context['id'] ?? null;
        if (is_bool($rawId) || is_array($rawId) || $rawId === null) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少对象标识，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'id_missing']
            );
        }
        $id = trim((string)$rawId);
        if ($id === '' || $id === '0') {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少对象标识，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'id_missing']
            );
        }
        if (strlen($id) > 64 || !preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象标识无效，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'id_invalid']
            );
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'expected_version' => $this->normalizeExpectedVersion($context, $index, $kind, $action),
        ];
    }

    private function normalizeExpectedVersion(array $context, int $index, string $kind, string $action): int
    {
        if (!array_key_exists('expectedVersion', $context) && !array_key_exists('expected_version', $context)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少对象版本，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'version_missing']
            );
        }
        $raw = array_key_exists('expectedVersion', $context) ? $context['expectedVersion'] : $context['expected_version'];
        if ($raw === null || is_bool($raw) || is_array($raw) || is_float($raw)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象版本无效，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'version_invalid']
            );
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            if (!preg_match('/^[1-9][0-9]{0,18}$/', $raw)) {
                throw CashierV3CommandException::invalidContext(
                    '本次操作的对象版本无效，请刷新当前工作台后重试。',
                    ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'version_invalid']
                );
            }
        }
        if (!is_int($raw) && !is_string($raw)) {
            throw CashierV3CommandException::invalidContext(
                '本次操作的对象版本无效，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'version_invalid']
            );
        }
        $version = (int)$raw;
        if ($version <= 0) {
            throw CashierV3CommandException::invalidContext(
                '本次操作缺少有效的既有资源版本，请刷新当前工作台后重试。',
                ['action' => $action, 'index' => $index, 'kind' => $kind, 'reason' => 'version_not_positive']
            );
        }
        return $version;
    }
}
