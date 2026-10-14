<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\permission\CashierV3FeatureResolver;
use think\facade\Db;

/** Editable notes for five inventory documents; stock facts and settled quantities remain immutable. */
final class InventoryDocumentRemarkServices
{
    private const TENANT = CashierV3ScopeResolver::TENANT_SCOPE_ID;
    private const TYPES = ['inbound', 'outbound', 'request', 'transfer', 'usage'];

    /** Called inside the stock command transaction so a new manual voucher keeps its entered header note. */
    public function initializeManual(string $type, string $tenantId, string $sourceId, string $remark): void
    {
        if (!in_array($type, ['inbound', 'outbound'], true) || $tenantId !== self::TENANT) {
            throw new \InvalidArgumentException('inventory_document_remark_identity_invalid');
        }
        $this->assertIdentity($type, $sourceId);
        $existing = Db::name('inventory_document_remark')->where('tenant_id', $tenantId)
            ->where('document_type', $type)->where('source_id', $sourceId)->find();
        if ($existing) return;
        $lines = Db::name('inventory_batch_movement_fact')->where('tenant_id', $tenantId)
            ->where('source_type', 'manual_' . $type)->where('source_id', $sourceId)
            ->field('id,detail_remark')->order('id asc')->select()->toArray();
        if (!$lines) throw new \RuntimeException('inventory_document_remark_missing');
        $notes = [];
        foreach ($lines as $line) $notes[(string)$line['id']] = (string)($line['detail_remark'] ?? '');
        Db::name('inventory_document_remark')->insert([
            'tenant_id' => $tenantId, 'document_type' => $type, 'source_id' => $sourceId,
            'remark' => $remark, 'line_remarks_json' => json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'version' => 0, 'updated_by_type' => '', 'updated_by_id' => 0, 'updated_at' => 0,
        ]);
    }

    /** Reuse each document's authorized read model before applying the latest note projection. */
    public function detailForStore(int $storeId, int $operatorId, string $type, string $id): array
    {
        $this->assertIdentity($type, $id);
        // The generic read URL must not turn another inventory page's session into this page's read grant.
        $this->assertStorePageReadable($storeId, $operatorId, $type);
        $policy = new InventoryStoreAccessPolicy();
        switch ($type) {
            case 'inbound':
                $detail = (new InventoryManualInboundReadServices())->detail($storeId, $operatorId, $id, $policy->canViewCostForFeature($storeId, $operatorId, 'cashier.v3.inventory.inbound'));
                break;
            case 'outbound':
                $detail = (new InventoryMovementQueryServices())->outboundDetail($storeId, $operatorId, $id, $policy->canViewCostForFeature($storeId, $operatorId, 'cashier.v3.inventory.outbound'));
                break;
            case 'request':
                $detail = (new InventoryStockRequestQueryServices())->detail($storeId, $operatorId, (int)$id, $policy->canViewCostForFeature($storeId, $operatorId, 'cashier.v3.inventory.request'));
                break;
            case 'transfer':
                $detail = (new InventoryCrossSubjectTransferServices())->detailForStore($storeId, $operatorId, (int)$id, $policy->canViewCostForFeature($storeId, $operatorId, 'cashier.v3.inventory.transfer'));
                break;
            default:
                $detail = (new InventorySalonUsageServices())->detail($storeId, $operatorId, (int)$id);
        }
        $editable = !$this->notSeparatelyEditable($type, $detail);
        if ($editable) {
            try { $this->storeActor($storeId, $operatorId, $type); }
            catch (\RuntimeException $exception) { $editable = false; }
        }
        return $this->project($type, $id, $detail, $editable);
    }

    /** Platform function access permits note edits inside the document reader's warehouse scope. */
    public function detailForPlatform(array $adminInfo, string $type, string $id, array $selection): array
    {
        $this->assertIdentity($type, $id);
        $policy = new InventoryPlatformAccessPolicy();
        $access = $policy->resolve($adminInfo);
        // The read route's any-menu bridge is narrowed before touching another page's document model.
        $policy->assertQueryPage($access, 'inventory_' . ($type === 'usage' ? 'salon_usage' : $type));
        $locationId = (int)($selection['hq_location_id'] ?? 0);
        $storeId = (int)($selection['store_id'] ?? 0);
        switch ($type) {
            case 'inbound':
                $detail = (new InventoryPlatformHqInboundServices())->detail($adminInfo, $locationId, $id, $selection);
                break;
            case 'outbound':
                $detail = (new InventoryPlatformHqOutboundServices())->detail($adminInfo, $locationId, $id, $selection);
                break;
            case 'request':
                $detail = (new InventoryStockRequestQueryServices())->detailForHeadquarters($adminInfo, $locationId, (int)$id);
                break;
            case 'transfer':
                $detail = (new InventoryCrossSubjectTransferServices())->detailForHeadquarters($adminInfo, $locationId, (int)$id);
                break;
            default:
                $detail = (new InventorySalonUsageServices())->detailForPlatform($adminInfo, $storeId, (int)$id, $locationId);
        }
        $editable = !$this->notSeparatelyEditable($type, $detail);
        return $this->project($type, $id, $detail, $editable);
    }

    /** Optimistic version and source-row lock keep concurrent edits attributable and lossless. */
    public function saveForStore(int $storeId, int $operatorId, string $type, string $id, array $input): array
    {
        return Db::transaction(function () use ($storeId, $operatorId, $type, $id, $input): array {
            $this->lockOriginal($type, $id);
            $detail = $this->detailForStore($storeId, $operatorId, $type, $id);
            $actor = $this->storeActor($storeId, $operatorId, $type);
            return $this->save($type, $id, $detail, $input, $actor);
        });
    }

    /** Platform writes retain the same source lock and version check as store writes. */
    public function saveForPlatform(array $adminInfo, string $type, string $id, array $selection, array $input): array
    {
        return Db::transaction(function () use ($adminInfo, $type, $id, $selection, $input): array {
            $this->lockOriginal($type, $id);
            $detail = $this->detailForPlatform($adminInfo, $type, $id, $selection);
            return $this->save($type, $id, $detail, $input, ['type' => 'PLATFORM_ADMIN', 'id' => (int)$adminInfo['id']]);
        });
    }

    private function assertIdentity(string $type, string $id): void
    {
        if (!in_array($type, self::TYPES, true) || $id === '' || strlen($id) > 96
            || !preg_match('/^[A-Za-z0-9:._-]+$/D', $id)
            || (in_array($type, ['request', 'transfer', 'usage'], true) && (!ctype_digit($id) || (int)$id <= 0))) {
            throw new \InvalidArgumentException('inventory_document_remark_identity_invalid');
        }
    }

    /** Lock the authoritative document before inserting its first note row. */
    private function lockOriginal(string $type, string $id): void
    {
        $this->assertIdentity($type, $id);
        if ($type === 'inbound' || $type === 'outbound') {
            $row = Db::name('inventory_batch_movement_fact')->where('tenant_id', self::TENANT)
                ->where('source_type', 'manual_' . $type)->where('source_id', $id)
                ->order('id asc')->field('id')->lock(true)->find();
        } else {
            $table = ['request' => 'inventory_stock_request_document', 'transfer' => 'inventory_cross_transfer_document', 'usage' => 'inventory_salon_usage_document'][$type];
            $row = Db::name($table)->where('tenant_id', self::TENANT)->where('id', (int)$id)->field('id')->lock(true)->find();
        }
        if (!$row) throw new \RuntimeException('inventory_document_remark_missing');
    }

    private function storeActor(int $storeId, int $operatorId, string $type): array
    {
        $policy = new InventoryStoreAccessPolicy();
        $actor = $type === 'transfer' ? $policy->transferActor($storeId, $operatorId)
            : $policy->writableActor($storeId, $operatorId, 'cashier.v3.inventory.' . $type);
        // Generic note routes must verify this page's grant even for ordinary store-staff sessions.
        $profile = (array)(request()->storeStaffInfo ?? []);
        $delegatedTransfer = $type === 'transfer' && !empty($profile['_cashier_v3_delegated']);
        if (!$delegatedTransfer && !$policy->canViewCostForFeature($storeId, $operatorId, 'cashier.v3.inventory.' . $type)) {
            throw new \RuntimeException('inventory_document_remark_denied');
        }
        return $actor;
    }

    /** Preserve each original reader's read-only policy while restricting this generic URL to its own page. */
    private function assertStorePageReadable(int $storeId, int $operatorId, string $type): void
    {
        $feature = 'cashier.v3.inventory.' . $type;
        $profile = (array)(request()->storeStaffInfo ?? []);
        if (!empty($profile['_cashier_v3_organization']) || !empty($profile['_cashier_v3_delegated'])) {
            if ((int)($profile['id'] ?? 0) !== $operatorId || (int)($profile['store_id'] ?? 0) !== $storeId) {
                throw new \RuntimeException('inventory_document_remark_denied');
            }
            $features = app()->make(CashierV3FeatureResolver::class)->resolveVisibleFeatures($profile);
        } else {
            $profile = (array)Db::name('system_store_staff')->where('id', $operatorId)->where('store_id', $storeId)
                ->where('status', 1)->where('is_del', 0)->find();
            if (!$profile) throw new \RuntimeException('inventory_document_remark_denied');
            $features = app()->make(CashierV3FeatureResolver::class)->resolveGrantedFeatures($profile);
        }
        if (!in_array($feature, $features, true)) throw new \RuntimeException('inventory_document_remark_denied');
    }

    private function notSeparatelyEditable(string $type, array $detail): bool
    {
        return $type === 'outbound' && ($detail['document']['source_type'] ?? 'manual_outbound') !== 'manual_outbound';
    }

    /** Rebase a separately edited note onto newly created line IDs after a full voucher edit. */
    public function rebaseAfterFullEdit(string $type, int $documentId, string $remark, array $actor): void
    {
        if (!in_array($type, ['request', 'transfer'], true) || $documentId <= 0) {
            throw new \InvalidArgumentException('inventory_document_remark_identity_invalid');
        }
        // Caller owns the original document lock and the surrounding voucher transaction.
        $state = Db::name('inventory_document_remark')->where('tenant_id', self::TENANT)
            ->where('document_type', $type)->where('source_id', (string)$documentId)->lock(true)->find();
        if (!$state) return;
        $lineTable = $type === 'request' ? 'inventory_stock_request_line' : 'inventory_cross_transfer_line';
        $query = Db::name($lineTable)->where('document_id', $documentId);
        // 撤回后重编调拨会保留旧发货明细，备注投影只能指向本轮仍有效的明细。
        if ($type === 'transfer') $query->where('retired_at', 0);
        $rows = $query->field('id,detail_remark')->order('id asc')->select()->toArray();
        $notes = [];
        foreach ($rows as $row) $notes[(string)$row['id']] = (string)($row['detail_remark'] ?? '');
        if (!$notes) throw new \RuntimeException('inventory_document_remark_lines_incomplete');
        $previousJson = (string)$state['line_remarks_json'];
        $nextJson = json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = time();
        Db::name('inventory_document_remark')->where('id', (int)$state['id'])->update([
            'remark' => $remark, 'line_remarks_json' => $nextJson, 'version' => (int)$state['version'] + 1,
            'updated_by_type' => (string)$actor['type'], 'updated_by_id' => (int)$actor['id'], 'updated_at' => $now,
        ]);
        Db::name('inventory_document_remark_history')->insert([
            'remark_id' => (int)$state['id'], 'previous_version' => (int)$state['version'],
            'previous_remark' => (string)$state['remark'], 'new_remark' => $remark,
            'previous_line_remarks_json' => $previousJson, 'new_line_remarks_json' => $nextJson,
            'operator_type' => (string)$actor['type'], 'operator_id' => (int)$actor['id'], 'changed_at' => $now,
        ]);
    }

    private function project(string $type, string $id, array $detail, bool $editable): array
    {
        // Transfer readers return a flat document; keep that contract while exposing one common editor shape.
        if ($type === 'transfer' && !isset($detail['document'])) {
            $detail['document'] = ['transfer_no' => (string)($detail['transfer_no'] ?? ''), 'remark' => (string)($detail['remark'] ?? '')];
        }
        $state = Db::name('inventory_document_remark')->where('tenant_id', self::TENANT)
            ->where('document_type', $type)->where('source_id', $id)->find();
        $detail['remark_version'] = (int)($state['version'] ?? 0);
        $detail['remark_editable'] = $editable;
        if ($state) {
            $detail['document']['remark'] = (string)$state['remark'];
            if ($type === 'transfer') $detail['remark'] = (string)$state['remark'];
            $notes = json_decode((string)$state['line_remarks_json'], true);
            if (!is_array($notes)) throw new \RuntimeException('inventory_document_remark_corrupt');
            foreach ($detail['lines'] as &$line) {
                $key = (string)($line['line_id'] ?? $line['id'] ?? '');
                if (array_key_exists($key, $notes)) $line['detail_remark'] = (string)$notes[$key];
            }
            unset($line);
        }
        $detail['remark_history'] = $state ? Db::name('inventory_document_remark_history')
            ->where('remark_id', (int)$state['id'])->order('id desc')->limit(20)
            ->field('previous_version,previous_remark,new_remark,previous_line_remarks_json,new_line_remarks_json,operator_type,operator_id,changed_at')->select()->toArray() : [];
        return $detail;
    }

    private function save(string $type, string $id, array $detail, array $input, array $actor): array
    {
        if (!$detail['remark_editable']) throw new \RuntimeException('inventory_document_remark_denied');
        $inputKeys = array_keys($input);
        sort($inputKeys);
        // Store request filters stringify JSON scalars; accept only lossless decimal IDs and versions.
        $version = $this->transportInteger($input['version'] ?? null, 0);
        if ($inputKeys !== ['lines', 'remark', 'type', 'version'] || $input['type'] !== $type
            || $version === null || !is_string($input['remark']) || !is_array($input['lines'])) {
            throw new \InvalidArgumentException('inventory_document_remark_input_invalid');
        }
        $remark = trim($input['remark']);
        if (mb_strlen($remark) > 500) throw new \InvalidArgumentException('inventory_document_remark_too_long');
        $baseLines = [];
        foreach ($detail['lines'] as $line) {
            $key = (string)($line['line_id'] ?? $line['id'] ?? '');
            if ($key === '' || isset($baseLines[$key])) throw new \RuntimeException('inventory_document_remark_line_identity_invalid');
            $baseLines[$key] = (string)($line['detail_remark'] ?? '');
        }
        $notes = [];
        foreach ($input['lines'] as $line) {
            $lineKeys = is_array($line) ? array_keys($line) : [];
            sort($lineKeys);
            $lineId = is_array($line) ? $this->transportInteger($line['line_id'] ?? null, 1) : null;
            if (!is_array($line) || $lineKeys !== ['detail_remark', 'line_id']
                || $lineId === null || !is_string($line['detail_remark'])) {
                throw new \InvalidArgumentException('inventory_document_remark_line_invalid');
            }
            $key = (string)$lineId;
            $value = trim($line['detail_remark']);
            if (!array_key_exists($key, $baseLines) || isset($notes[$key]) || mb_strlen($value) > 500) {
                throw new \InvalidArgumentException('inventory_document_remark_line_invalid');
            }
            $notes[$key] = $value;
        }
        if (count($notes) !== count($baseLines)) throw new \InvalidArgumentException('inventory_document_remark_lines_incomplete');
        ksort($notes, SORT_NUMERIC);
        ksort($baseLines, SORT_NUMERIC);
        if ($version !== (int)$detail['remark_version']) throw new \RuntimeException('inventory_document_remark_changed');
        $beforeRemark = (string)($detail['document']['remark'] ?? '');
        if ($beforeRemark === $remark && $baseLines === $notes) return $detail;
        $state = Db::name('inventory_document_remark')->where('tenant_id', self::TENANT)
            ->where('document_type', $type)->where('source_id', $id)->lock(true)->find();
        $beforeJson = json_encode($baseLines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $afterJson = json_encode($notes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = time();
        if ($state) {
            if ((int)$state['version'] !== $version) throw new \RuntimeException('inventory_document_remark_changed');
            $remarkId = (int)$state['id'];
            Db::name('inventory_document_remark')->where('id', $remarkId)->update([
                'remark' => $remark, 'line_remarks_json' => $afterJson, 'version' => $version + 1,
                'updated_by_type' => $actor['type'], 'updated_by_id' => $actor['id'], 'updated_at' => $now,
            ]);
        } else {
            $remarkId = (int)Db::name('inventory_document_remark')->insertGetId([
                'tenant_id' => self::TENANT, 'document_type' => $type, 'source_id' => $id,
                'remark' => $remark, 'line_remarks_json' => $afterJson, 'version' => 1,
                'updated_by_type' => $actor['type'], 'updated_by_id' => $actor['id'], 'updated_at' => $now,
            ]);
        }
        Db::name('inventory_document_remark_history')->insert([
            'remark_id' => $remarkId, 'previous_version' => $version,
            'previous_remark' => $beforeRemark, 'new_remark' => $remark,
            'previous_line_remarks_json' => $beforeJson, 'new_line_remarks_json' => $afterJson,
            'operator_type' => $actor['type'], 'operator_id' => $actor['id'], 'changed_at' => $now,
        ]);
        // Existing list/search/export readers use these three voucher note columns; keep them in the same transaction.
        // Settled inbound/outbound fact rows are never rewritten; only their separate note projection changes.
        if (in_array($type, ['request', 'transfer', 'usage'], true)) {
            $tables = [
                'request' => ['inventory_stock_request_document', 'inventory_stock_request_line'],
                'transfer' => ['inventory_cross_transfer_document', 'inventory_cross_transfer_line'],
                'usage' => ['inventory_salon_usage_document', 'inventory_salon_usage_line'],
            ];
            [$documentTable, $lineTable] = $tables[$type];
            Db::name($documentTable)->where('tenant_id', self::TENANT)->where('id', (int)$id)->update(['remark' => $remark]);
            foreach ($notes as $lineId => $lineRemark) {
                Db::name($lineTable)->where('document_id', (int)$id)->where('id', (int)$lineId)
                    ->update(['detail_remark' => $lineRemark]);
            }
        }
        return ['version' => $version + 1, 'changed' => true];
    }

    /** Request filters may stringify JSON integers; reject noncanonical, overflowed or fractional values. */
    private function transportInteger($value, int $minimum): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value))) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed !== false && $parsed >= $minimum ? $parsed : null;
    }
}
