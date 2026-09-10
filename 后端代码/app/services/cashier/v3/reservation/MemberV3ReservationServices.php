<?php

declare(strict_types=1);

namespace app\services\cashier\v3\reservation;

use app\services\cashier\v3\CashierV3BusinessDocumentNumberServices;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ScopeResolver;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\order\StoreReservationOrderServices;
use app\services\product\product\StoreProductReservationServices;
use think\exception\ValidateException;
use think\facade\Db;

/** Member API boundary for new V3 reservations only. */
final class MemberV3ReservationServices
{
    public function create(int $uid, int $orderId, array $input): array
    {
        if ($uid <= 0 || $orderId <= 0) throw new ValidateException('缺少预约必要信息!');
        $quantity = (int)($input['cart_num'] ?? 0);
        $date = trim((string)($input['reservation_time'] ?? ''));
        $start = trim((string)($input['reservation_start'] ?? ''));
        $timeId = (int)($input['reservation_time_id'] ?? 0);
        if ($quantity <= 0) throw new ValidateException('请选择预约数量!');
        if ($date === '') throw new ValidateException('请选择预约日期!');
        if ($start === '' && $timeId <= 0) throw new ValidateException('请选择预约时段!');
        $date = date('Y-m-d', strtotime($date) ?: 0);
        if ($date === '1970-01-01') throw new ValidateException('预约时间无效。');

        $order = Db::name('store_order')->where('id', $orderId)->where('uid', $uid)
            ->where('paid', 1)->where('is_del', 0)->where('is_system_del', 0)->where('is_user_del', 0)
            ->where('refund_status', 0)->where('terminal_action', 0)->find();
        if (!$order) throw new ValidateException('获取订单信息失败!');
        $storeId = (int)($input['store_id'] ?? $order['store_id'] ?? 0);
        if ($storeId <= 0) throw new ValidateException('缺少门店信息。');
        if ((int)$order['store_id'] > 0 && (int)$order['store_id'] !== $storeId && (int)sys_config('cross_store_verification', 1) !== 1) {
            throw new ValidateException('未开启跨店核销，不能切换门店!');
        }
        $detailId = (int)($input['cart_info_id'] ?? 0);
        $detail = Db::name('store_order_cart_info')->where('id', $detailId)->where('oid', $orderId)
            ->where('cart_type', 2)->where('product_type', 6)->find();
        if (!$detail) throw new ValidateException('已购预约项目不存在。');

        /** Preserve the old member form's configured custom-form requirement
         * without reading the legacy reservation authority. */
        $productConfig = Db::name('store_product')->where('id', (int)$detail['product_id'])
            ->field('id,system_form_id')->find();
        if (!$productConfig) throw new ValidateException('商品不存在或已下架，请联系管理员。');
        $configuredForm = (int)($productConfig['system_form_id'] ?? 0) > 0
            ? Db::name('system_form')->where('id', (int)$productConfig['system_form_id'])->field('id,name,value')->find()
            : null;
        if ($configuredForm && trim((string)($configuredForm['value'] ?? '')) !== '' && empty($input['custom_form'])) {
            throw new ValidateException('请补充预约信息!');
        }

        $productId = (int)$detail['product_id'];
        /** @var StoreReservationOrderServices $scheduleValidation */
        $scheduleValidation = app()->make(StoreReservationOrderServices::class);
        $slot = $scheduleValidation->resolveReservationTimeInfo(
            app()->make(StoreProductReservationServices::class),
            $productId,
            (string)($detail['sku_unique'] ?? ''),
            $quantity,
            $date,
            $input,
            true
        );
        $startClock = $this->clock((string)($slot['start'] ?? $start));
        $appointmentStart = strtotime($date . ' ' . $startClock . ':00');
        if (!$appointmentStart) throw new ValidateException('预约时间无效。');
        $duration = (int)($input['service_duration_minutes'] ?? 0);
        if ($duration <= 0 && !empty($slot['end'])) {
            $slotEnd = strtotime($date . ' ' . $this->clock((string)$slot['end']) . ':00');
            if ($slotEnd > $appointmentStart) $duration = max(1, (int)ceil(($slotEnd - $appointmentStart) / 60));
        }
        if ($duration <= 0) {
            try {
                [, $product] = app()->make(StoreProductReservationServices::class)->getProductInfo($productId, $storeId);
                $duration = (int)($product['project_service_duration'] ?? StoreProductReservationServices::DEFAULT_PROJECT_SERVICE_DURATION);
            } catch (\Throwable $exception) {
                $duration = StoreProductReservationServices::DEFAULT_PROJECT_SERVICE_DURATION;
            }
        }
        $duration = max(1, $duration);
        $appointmentEnd = $appointmentStart + $duration * 60;
        $staffIds = $this->staffIds($input, $storeId);
        $user = Db::name('user')->where('uid', $uid)->field('nickname,real_name,phone')->find() ?: [];
        $store = Db::name('system_store')->where('id', $storeId)->field('name')->find() ?: [];
        $org = Db::name('organization_store')->where('store_id', $storeId)->field('org_id')->find() ?: [];
        $organizationId = (string)((int)($org['org_id'] ?? 0));
        if ($organizationId === '0') throw new ValidateException('门店组织归属未配置。');
        $tenantId = CashierV3ScopeResolver::TENANT_SCOPE_ID;
        $name = trim((string)($input['reservation_name'] ?? ''))
            ?: (trim((string)($order['real_name'] ?? '')) ?: (trim((string)($user['real_name'] ?? '')) ?: (string)($user['nickname'] ?? '')));
        $phone = trim((string)($input['reservation_phone'] ?? ''))
            ?: (trim((string)($order['user_phone'] ?? '')) ?: (string)($user['phone'] ?? ''));
        $address = trim((string)($input['reservation_address'] ?? '')) ?: trim((string)($order['user_address'] ?? ''));
        $formJson = json_encode($input['custom_form'] ?? [], JSON_UNESCAPED_UNICODE);
        if (!is_string($formJson)) $formJson = '[]';
        $formTitle = trim((string)($configuredForm['name'] ?? ''));
        $projectInfo = json_decode((string)($detail['cart_info'] ?? ''), true) ?: [];
        $projectName = trim((string)($projectInfo['productInfo']['store_name'] ?? '')) ?: '预约项目';
        $addonDetails = $this->addonDetails($uid, $detailId, $input['addon_items'] ?? []);
        $naturalKey = 'member-reservation-' . hash('sha256', json_encode([
            $uid, $orderId, $detailId, $storeId, $appointmentStart, $quantity,
            array_column($addonDetails, 'id'), $staffIds, $duration,
        ], JSON_UNESCAPED_UNICODE));

        return Db::transaction(function () use ($uid, $orderId, $detailId, $quantity, $date, $appointmentStart, $appointmentEnd, $duration, $staffIds, $tenantId, $organizationId, $storeId, $store, $name, $phone, $address, $formJson, $formTitle, $detail, $projectName, $addonDetails, $input, $naturalKey): array {
            $rootOperation = Db::name('cashier_v3_reservation_operation')->where('tenant_id', $tenantId)
                ->where('command_idempotency_key', $naturalKey)->lock(true)->find();
            if ($rootOperation) {
                $attempts = $this->rows(Db::name('cashier_v3_reservation_operation')->where('tenant_id', $tenantId)
                    ->where('operation_type', 'CREATE')->whereLike('command_idempotency_key', $naturalKey . '%')
                    ->order('id desc')->lock(true)->select());
                $latest = $attempts[0] ?? $rootOperation;
                $latestHeader = Db::name('cashier_v3_reservation')->where('id', (int)$latest['reservation_id'])->lock(true)->find();
                if ($latestHeader && !in_array((string)$latestHeader['status'], ['CANCELLED', 'REJECTED'], true)) {
                    $result = json_decode((string)$latest['result_json'], true);
                    return is_array($result) ? $result : [];
                }
                // A cancelled/rejected appointment may be booked again. The
                // root operation lock serializes concurrent retries.
                $naturalKey .= ':attempt:' . (count($attempts) + 1);
            }
            $lifecycle = new CashierV3ReservationLifecycleServices();
            $lifecycle->assertStaffAvailabilityInTx($storeId, 0, $staffIds, $appointmentStart, $appointmentEnd);
            $now = time();
            $identity = 'RSV-' . bin2hex(random_bytes(12));
            $reservationNo = (new CashierV3BusinessDocumentNumberServices())->allocateForSourceInTx(
                $tenantId,
                CashierV3BusinessDocumentNumberServices::RESERVATION,
                'member_reservation',
                $identity,
                $date,
                $now
            );
            $header = [
                'reservation_id' => $identity,
                'reservation_no' => $reservationNo,
                'lifecycle_generation' => CashierV3ReservationLifecycleServices::GENERATION,
                'source_type' => CashierV3ReservationLifecycleServices::SOURCE_MEMBER,
                'tenant_id' => $tenantId,
                'organization_id' => $organizationId,
                'organization_path' => '',
                'organization_name_snapshot' => '',
                'store_id' => $storeId,
                'store_name_snapshot' => (string)($store['name'] ?? ''),
                'member_id' => $uid,
                'member_name_snapshot' => $name,
                'member_phone_snapshot' => $phone,
                'service_order_id' => 0,
                'service_order_no_snapshot' => '',
                'room_id' => 0,
                'room_name_snapshot' => '',
                'appointment_start_at' => $appointmentStart,
                'appointment_end_at' => $appointmentEnd,
                'status' => 'PENDING_CONFIRMATION',
                'confirmed_at' => 0,
                'rejected_at' => 0,
                'reject_reason' => '',
                'actual_service_started_at' => 0,
                'actual_service_ended_at' => 0,
                'member_deleted_at' => 0,
                'reservation_form_json' => $formJson,
                'reservation_form_title_snapshot' => mb_substr($formTitle, 0, 128),
                'reservation_address_snapshot' => mb_substr($address, 0, 512),
                'version' => 1,
                'remark_snapshot' => mb_substr(trim((string)($input['mark'] ?? '')), 0, 500),
                'creator_staff_id' => 0,
                'creator_employee_id' => 0,
                'creator_name_snapshot' => $name,
                'business_date' => $date,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $reservationId = (int)Db::name('cashier_v3_reservation')->insertGetId($header);
            $lineRows = [];
            $lineId = (int)Db::name('cashier_v3_reservation_line')->insertGetId([
                'tenant_id' => $tenantId,
                'reservation_id' => $reservationId,
                'service_order_line_id' => 0,
                'line_key' => 'reservation:' . $reservationId . ':1',
                'project_id' => (int)$detail['product_id'],
                'sku_id' => 0,
                'project_name_snapshot' => mb_substr($projectName, 0, 128),
                'project_source' => 'ENTITLEMENT',
                'entitlement_source_detail_id' => $detailId,
                'quantity' => $quantity,
                'role_code' => 'MAIN',
                'service_duration_minutes' => $duration,
                'artisan_staff_ids_json' => json_encode($staffIds, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $lineRows[] = Db::name('cashier_v3_reservation_line')->where('id', $lineId)->find();
            foreach ($addonDetails as $index => $addon) {
                $addonSnapshot = $this->decodeArray($addon['cart_info'] ?? []);
                $addonProduct = $this->decodeArray($addonSnapshot['productInfo'] ?? []);
                $addonName = trim((string)($addonProduct['store_name'] ?? '')) ?: ('增项服务' . ((int)$index + 1));
                $addonLineId = (int)Db::name('cashier_v3_reservation_line')->insertGetId([
                    'tenant_id' => $tenantId,
                    'reservation_id' => $reservationId,
                    'service_order_line_id' => 0,
                    'line_key' => 'reservation:' . $reservationId . ':' . ((int)$index + 2),
                    'project_id' => (int)$addon['product_id'],
                    'sku_id' => 0,
                    'project_name_snapshot' => mb_substr($addonName, 0, 128),
                    'project_source' => 'ENTITLEMENT',
                    'entitlement_source_detail_id' => (int)$addon['id'],
                    'quantity' => 1,
                    'role_code' => 'ADDON',
                    'service_duration_minutes' => max(1, (int)($addon['_duration'] ?? StoreProductReservationServices::DEFAULT_PROJECT_SERVICE_DURATION)),
                    'artisan_staff_ids_json' => json_encode($staffIds, JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $lineRows[] = Db::name('cashier_v3_reservation_line')->where('id', $addonLineId)->find();
            }
            $operator = new CashierV3OperatorScope($storeId, $uid, $organizationId, $tenantId);
            $scope = new CashierV3DataScopeContext($uid, 0, $storeId, $tenantId, $organizationId, [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, 'member_reservation', 'member-v1', [], ['name' => $name ?: ('会员' . $uid)]);
            $recorder = new CashierV3BusinessEventRecorder();
            $execution = $recorder->newExecution('create-reservation', $naturalKey, $operator, $scope, 'MEMBER-RESERVATION');
            $contract = CashierV3ActionManifest::eventContractFor('create-reservation');
            $recorder->recordInTx($execution, $contract, [
                'event_type' => 'reservation.created', 'aggregate_type' => 'reservation',
                'aggregate_id' => (string)$reservationId, 'aggregate_version' => 1,
                'source_type' => 'create-reservation', 'source_id' => (string)$reservationId,
                'member_id' => $uid, 'aggregate_name_snapshot' => $reservationNo,
                'store_name_snapshot' => (string)($store['name'] ?? ''),
                'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
                'business_date' => $date,
                'payload' => ['reservationNo' => $reservationNo, 'sourceType' => 'MEMBER', 'appointmentStartAt' => $appointmentStart, 'appointmentEndAt' => $appointmentEnd],
            ]);
            $recorder->assertRequiredPersistedInTx($execution, $contract);
            $result = ['reservationId' => $reservationId, 'reservationNo' => $reservationNo, 'status' => 'PENDING_CONFIRMATION'];
            Db::name('cashier_v3_reservation_operation')->insert([
                'tenant_id' => $tenantId, 'command_idempotency_key' => $naturalKey,
                'reservation_id' => $reservationId, 'operation_type' => 'CREATE',
                'version_before' => 0, 'version_after' => 1,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now, 'recorded_at' => $now,
            ]);
            return $result;
        });
    }

    public function listing(int $uid, array $where = []): array
    {
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = max(1, min(100, (int)($where['limit'] ?? 20)));
        $query = Db::name('cashier_v3_reservation')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->where('source_type', CashierV3ReservationLifecycleServices::SOURCE_MEMBER)
            ->where('member_id', $uid)->where('member_deleted_at', 0);
        $status = strtoupper(trim((string)($where['status'] ?? '')));
        if ($status !== '') $query->where('status', $status);
        $sourceOrderId = (int)($where['oid'] ?? 0);
        if ($sourceOrderId > 0) {
            $detailIds = Db::name('store_order_cart_info')->where('oid', $sourceOrderId)->column('id');
            $reservationIds = $detailIds ? Db::name('cashier_v3_reservation_line')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereIn('entitlement_source_detail_id', array_map('intval', $detailIds))->column('reservation_id') : [];
            $query->whereIn('id', $reservationIds ?: [-1]);
        }
        $search = trim((string)($where['search'] ?? ''));
        if ($search !== '') {
            $lineReservationIds = Db::name('cashier_v3_reservation_line')
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->whereLike('project_name_snapshot', '%' . $search . '%')->column('reservation_id');
            $query->where(function ($nested) use ($search, $lineReservationIds): void {
                $nested->whereLike('store_name_snapshot', '%' . $search . '%')
                    ->whereOr('member_name_snapshot', 'like', '%' . $search . '%');
                if ($lineReservationIds) $nested->whereOr('id', 'in', array_map('intval', $lineReservationIds));
            });
        }
        $count = (int)(clone $query)->count();
        $rows = $this->rows($query->order('appointment_start_at desc,id desc')->page($page, $limit)->select());
        $context = $this->compatibilityContext($rows);
        return ['list' => array_map(function (array $row) use ($context): array {
            return $this->summary($row, $context);
        }, $rows), 'count' => $count];
    }

    public function detail(int $uid, int $reservationId): array
    {
        $header = Db::name('cashier_v3_reservation')->where('id', $reservationId)
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
            ->where('source_type', CashierV3ReservationLifecycleServices::SOURCE_MEMBER)
            ->where('member_id', $uid)->where('member_deleted_at', 0)->find();
        if (!$header) throw new ValidateException('预约不存在。');
        $lines = $this->rows(Db::name('cashier_v3_reservation_line')->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->where('reservation_id', $reservationId)->order('id asc')->select());
        $context = $this->compatibilityContext([$header], $lines);
        return array_merge($this->summary($header, $context), ['projects' => array_map(static function (array $line): array {
            return ['id' => (int)$line['id'], 'projectId' => (int)$line['project_id'], 'name' => (string)$line['project_name_snapshot'], 'quantity' => (int)$line['quantity'], 'durationMinutes' => (int)$line['service_duration_minutes']];
        }, $lines)]);
    }

    public function cancel(int $uid, int $reservationId): array
    {
        return Db::transaction(function () use ($uid, $reservationId): array {
            $header = Db::name('cashier_v3_reservation')->where('id', $reservationId)
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
                ->where('source_type', CashierV3ReservationLifecycleServices::SOURCE_MEMBER)
                ->where('member_id', $uid)->lock(true)->find();
            if (!$header) throw new ValidateException('预约不存在。');
            if ((string)$header['status'] === 'CANCELLED') {
                return ['reservationId' => $reservationId, 'reservationNo' => (string)$header['reservation_no'], 'status' => 'CANCELLED'];
            }
            if ((string)$header['status'] !== 'PENDING_CONFIRMATION') throw new ValidateException('当前预约已由门店确认，如需取消请联系门店。');
            $now = time();
            $versionBefore = (int)$header['version'];
            $versionAfter = $versionBefore + 1;
            (new CashierV3ReservationLifecycleServices())->releaseInTx((string)$header['tenant_id'], $reservationId, $now);
            $affected = Db::name('cashier_v3_reservation')->where('id', $reservationId)->where('tenant_id', (string)$header['tenant_id'])
                ->where('version', $versionBefore)->update(['status' => 'CANCELLED', 'version' => $versionAfter, 'updated_at' => $now]);
            if ((int)$affected !== 1) throw new ValidateException('预约已变更，请刷新后重试。');
            $user = Db::name('user')->where('uid', $uid)->field('nickname,real_name')->find() ?: [];
            $operatorName = trim((string)($user['real_name'] ?? '')) ?: trim((string)($user['nickname'] ?? '')) ?: ('会员' . $uid);
            $storeId = (int)$header['store_id'];
            $organizationId = (string)$header['organization_id'];
            $tenantId = (string)$header['tenant_id'];
            $idempotencyKey = 'member-reservation-cancel-' . $reservationId;
            $operator = new CashierV3OperatorScope($storeId, $uid, $organizationId, $tenantId);
            $scope = new CashierV3DataScopeContext($uid, 0, $storeId, $tenantId, $organizationId, [$storeId], CashierV3DataScopeContext::MODE_STORES, [], false, 'member_reservation', 'member-v1', [], ['name' => $operatorName]);
            $recorder = new CashierV3BusinessEventRecorder();
            $execution = $recorder->newExecution('cancel-reservation', $idempotencyKey, $operator, $scope, 'MEMBER-RESERVATION');
            $contract = CashierV3ActionManifest::eventContractFor('cancel-reservation');
            $recorder->recordInTx($execution, $contract, [
                'event_type' => 'reservation.cancelled', 'aggregate_type' => 'reservation',
                'aggregate_id' => (string)$reservationId, 'aggregate_version' => $versionAfter,
                'source_type' => 'cancel-reservation', 'source_id' => (string)$reservationId,
                'member_id' => $uid, 'aggregate_name_snapshot' => (string)$header['reservation_no'],
                'store_name_snapshot' => (string)$header['store_name_snapshot'],
                'occurred_at' => $now, 'settled_at' => $now, 'recorded_at' => $now,
                'business_date' => date('Y-m-d', $now),
                'payload' => ['reservationNo' => (string)$header['reservation_no'], 'logicalCancel' => true, 'sourceType' => 'MEMBER'],
            ]);
            $recorder->assertRequiredPersistedInTx($execution, $contract);
            $result = ['reservationId' => $reservationId, 'reservationNo' => (string)$header['reservation_no'], 'status' => 'CANCELLED'];
            Db::name('cashier_v3_reservation_operation')->insert([
                'tenant_id' => $tenantId, 'command_idempotency_key' => $idempotencyKey,
                'reservation_id' => $reservationId, 'operation_type' => 'CANCEL',
                'version_before' => $versionBefore, 'version_after' => $versionAfter,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now, 'recorded_at' => $now,
            ]);
            return $result;
        });
    }

    public function delete(int $uid, int $reservationId): array
    {
        return Db::transaction(function () use ($uid, $reservationId): array {
            $header = Db::name('cashier_v3_reservation')->where('id', $reservationId)
                ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
                ->where('lifecycle_generation', CashierV3ReservationLifecycleServices::GENERATION)
                ->where('source_type', CashierV3ReservationLifecycleServices::SOURCE_MEMBER)
                ->where('member_id', $uid)->lock(true)->find();
            if (!$header) throw new ValidateException('预约不存在。');
            if ((int)($header['member_deleted_at'] ?? 0) > 0) {
                return ['reservationId' => $reservationId, 'deleted' => true];
            }
            if (!in_array((string)$header['status'], ['CANCELLED', 'REJECTED'], true)) {
                throw new ValidateException('只能删除已取消或已拒绝的预约。');
            }
            $now = time();
            $before = (int)$header['version'];
            $after = $before + 1;
            $affected = Db::name('cashier_v3_reservation')->where('id', $reservationId)
                ->where('tenant_id', (string)$header['tenant_id'])->where('version', $before)
                ->update(['member_deleted_at' => $now, 'version' => $after, 'updated_at' => $now]);
            if ((int)$affected !== 1) throw new ValidateException('预约已变更，请刷新后重试。');
            $result = ['reservationId' => $reservationId, 'deleted' => true];
            Db::name('cashier_v3_reservation_operation')->insert([
                'tenant_id' => (string)$header['tenant_id'],
                'command_idempotency_key' => 'member-reservation-hide-' . $reservationId,
                'reservation_id' => $reservationId, 'operation_type' => 'MEMBER_HIDE',
                'version_before' => $before, 'version_after' => $after,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'occurred_at' => $now, 'recorded_at' => $now,
            ]);
            return $result;
        });
    }

    private function summary(array $row, array $context = []): array
    {
        $reservationId = (int)$row['id'];
        $lines = $context['lines'][$reservationId] ?? [];
        $projectList = [];
        $firstCartInfo = [];
        $sourceOrder = [];
        $staffIds = [];
        foreach ($lines as $line) {
            $detailId = (int)($line['entitlement_source_detail_id'] ?? 0);
            $detail = $context['details'][$detailId] ?? [];
            $cartInfo = $this->decodeArray($detail['cart_info'] ?? []);
            $productInfo = $this->decodeArray($cartInfo['productInfo'] ?? []);
            $productId = (int)($line['project_id'] ?? $detail['product_id'] ?? 0);
            $product = $context['products'][$productId] ?? [];
            $productInfo['id'] = (int)($productInfo['id'] ?? $productId);
            $productInfo['store_name'] = trim((string)($productInfo['store_name'] ?? '')) ?: (trim((string)($line['project_name_snapshot'] ?? '')) ?: (string)($product['store_name'] ?? '服务项目'));
            $productInfo['image'] = (string)($productInfo['image'] ?? $product['image'] ?? '');
            $productInfo['store_info'] = (string)($productInfo['store_info'] ?? $product['store_info'] ?? '');
            $productInfo['unit_name'] = (string)($productInfo['unit_name'] ?? $product['unit_name'] ?? '次');
            $productInfo['store_label'] = is_array($productInfo['store_label'] ?? null) ? $productInfo['store_label'] : [];
            $attrInfo = $this->decodeArray($productInfo['attrInfo'] ?? []);
            $attrInfo['suk'] = (string)($attrInfo['suk'] ?? $detail['sku_unique'] ?? '');
            $productInfo['attrInfo'] = $attrInfo;
            $cartInfo['productInfo'] = $productInfo;
            $cartInfo['cart_num'] = (int)($line['quantity'] ?? 1);
            $cartInfo['product_id'] = $productId;
            if (!$firstCartInfo) $firstCartInfo = $cartInfo;
            $orderId = (int)($detail['oid'] ?? 0);
            if (!$sourceOrder && $orderId > 0) $sourceOrder = $context['orders'][$orderId] ?? [];
            $projectList[] = [
                'product_id' => $productId,
                'cart_info_id' => $detailId,
                'product_name' => (string)$productInfo['store_name'],
                'image' => (string)$productInfo['image'],
                'desc' => trim((string)$attrInfo['suk']) ?: (string)$productInfo['store_info'],
                'cart_num' => (int)($line['quantity'] ?? 1),
                'is_addon' => (string)($line['role_code'] ?? '') === 'ADDON' ? 1 : 0,
            ];
            foreach ($this->decodeArray($line['artisan_staff_ids_json'] ?? []) as $staffId) {
                $staffId = (int)$staffId;
                if ($staffId > 0) $staffIds[$staffId] = $staffId;
            }
        }
        $staffNames = [];
        $staffPhones = [];
        foreach ($staffIds as $staffId) {
            $staff = $context['staff'][$staffId] ?? [];
            $staffName = trim((string)($staff['staff_name'] ?? ''));
            if ($staffName !== '') $staffNames[] = $staffName;
            $staffPhone = trim((string)($staff['phone'] ?? ''));
            if ($staffPhone !== '') $staffPhones[] = $staffPhone;
        }
        $store = $context['stores'][(int)$row['store_id']] ?? [];
        $date = (int)$row['appointment_start_at'] > 0 ? date('Y-m-d', (int)$row['appointment_start_at']) : '';
        $start = (int)$row['appointment_start_at'] > 0 ? date('H:i', (int)$row['appointment_start_at']) : '';
        $end = (int)$row['appointment_end_at'] > 0 ? date('H:i', (int)$row['appointment_end_at']) : '';
        $status = (string)$row['status'];
        $form = $this->decodeArray($row['reservation_form_json'] ?? []);
        $reservationInfo = isset($form[0]) && is_array($form[0]) ? $form[0] : $form;
        $primaryStaffId = $staffIds ? (int)reset($staffIds) : 0;
        $sourceOrderId = (int)($sourceOrder['id'] ?? 0);
        $firstDetailId = (int)($lines[0]['entitlement_source_detail_id'] ?? 0);
        $firstDetail = $context['details'][$firstDetailId] ?? [];
        $availableTimes = max(0, (int)($firstDetail['write_surplus_times'] ?? 0));
        $result = [
            'reservationId' => (int)$row['id'], 'reservationNo' => (string)$row['reservation_no'],
            'status' => $status, 'statusLabel' => $this->statusLabel($status),
            'storeId' => (int)$row['store_id'], 'storeName' => (string)$row['store_name_snapshot'],
            'appointmentStartAt' => (int)$row['appointment_start_at'], 'appointmentEndAt' => (int)$row['appointment_end_at'],
            'actualServiceStartedAt' => (int)($row['actual_service_started_at'] ?? 0),
            'actualServiceEndedAt' => (int)($row['actual_service_ended_at'] ?? 0),
            'rejectReason' => (string)($row['reject_reason'] ?? ''), 'remark' => (string)$row['remark_snapshot'],
            // Vue2 member compatibility contract. Every value is projected
            // from V3 authority plus immutable purchase snapshots; never the
            // legacy reservation table.
            'id' => $reservationId,
            'uid' => (int)$row['member_id'],
            'oid' => $sourceOrderId,
            'name' => trim((string)($row['store_name_snapshot'] ?? '')) ?: (string)($store['name'] ?? ''),
            'store_id' => (int)$row['store_id'],
            'reservation_time' => $date,
            'reservation_start' => $start,
            'reservation_end' => $end,
            'reservation_show_time' => $start && $end ? ($start . '-' . $end) : ($start ?: $end),
            'appointment_time' => trim($date . ' ' . $start),
            'reservation_name' => (string)$row['member_name_snapshot'],
            'reservation_phone' => (string)$row['member_phone_snapshot'],
            'phone' => (string)$row['member_phone_snapshot'],
            'reservation_address' => (string)($row['reservation_address_snapshot'] ?? ''),
            'reservation_type' => 2,
            'reservation_create_time' => (int)$row['created_at'] > 0 ? date('Y-m-d H:i:s', (int)$row['created_at']) : '',
            'service_time' => (int)($row['actual_service_started_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$row['actual_service_started_at']) : '',
            'service_end_time' => (int)($row['actual_service_ended_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$row['actual_service_ended_at']) : '',
            'status_name' => $this->statusLabel($status),
            'status_msg' => $this->statusMessage($status, (string)($row['reject_reason'] ?? '')),
            'status_pic' => '',
            'project_list' => $projectList,
            'cart_info' => $firstCartInfo,
            'mark' => (string)$row['remark_snapshot'],
            'master_phone' => (string)($store['phone'] ?? ''),
            'staff_name' => implode('、', array_values(array_unique($staffNames))),
            'primary_staff_id' => $primaryStaffId,
            'service_staff_name' => $staffNames[0] ?? '',
            'service_staff_phone' => $staffPhones[0] ?? '',
            'custom_form_title' => (string)($row['reservation_form_title_snapshot'] ?? ''),
            'reservation_info' => $reservationInfo,
            'verify_code' => '',
            'service_images' => [],
            'service_describe' => '',
            'order_id' => (string)$row['reservation_no'],
            'store_order_id' => (string)($sourceOrder['order_id'] ?? ''),
            'storeInfo' => [
                'id' => (int)$row['store_id'],
                'name' => trim((string)($row['store_name_snapshot'] ?? '')) ?: (string)($store['name'] ?? ''),
                'phone' => (string)($store['phone'] ?? ''),
            ],
            'is_cancel_reservation' => $status === 'PENDING_CONFIRMATION' ? 1 : 0,
            'is_reservation' => $availableTimes > 0 ? 1 : 0,
        ];
        return $result;
    }

    /** Batch-loads projection data without reading the legacy reservation table. */
    private function compatibilityContext(array $headers, array $knownLines = []): array
    {
        $context = ['lines' => [], 'details' => [], 'orders' => [], 'stores' => [], 'products' => [], 'staff' => []];
        if (!$headers) return $context;
        $reservationIds = [];
        $storeIds = [];
        foreach ($headers as $header) {
            $reservationIds[(int)$header['id']] = (int)$header['id'];
            $storeIds[(int)$header['store_id']] = (int)$header['store_id'];
        }
        $lines = $knownLines ?: $this->rows(Db::name('cashier_v3_reservation_line')
            ->where('tenant_id', CashierV3ScopeResolver::TENANT_SCOPE_ID)
            ->whereIn('reservation_id', array_values($reservationIds))->order('id asc')->select());
        $detailIds = [];
        $productIds = [];
        $staffIds = [];
        foreach ($lines as $line) {
            $context['lines'][(int)$line['reservation_id']][] = $line;
            $detailId = (int)($line['entitlement_source_detail_id'] ?? 0);
            if ($detailId > 0) $detailIds[$detailId] = $detailId;
            $productId = (int)($line['project_id'] ?? 0);
            if ($productId > 0) $productIds[$productId] = $productId;
            foreach ($this->decodeArray($line['artisan_staff_ids_json'] ?? []) as $staffId) {
                $staffId = (int)$staffId;
                if ($staffId > 0) $staffIds[$staffId] = $staffId;
            }
        }
        if ($detailIds) {
            $details = $this->rows(Db::name('store_order_cart_info')->whereIn('id', array_values($detailIds))
                ->field('id,oid,product_id,sku_unique,cart_info,write_surplus_times')->select());
            $orderIds = [];
            foreach ($details as $detail) {
                $context['details'][(int)$detail['id']] = $detail;
                $orderIds[(int)$detail['oid']] = (int)$detail['oid'];
                $productIds[(int)$detail['product_id']] = (int)$detail['product_id'];
            }
            if ($orderIds) {
                foreach ($this->rows(Db::name('store_order')->whereIn('id', array_values($orderIds))->field('id,order_id')->select()) as $order) {
                    $context['orders'][(int)$order['id']] = $order;
                }
            }
        }
        if ($storeIds) {
            foreach ($this->rows(Db::name('system_store')->whereIn('id', array_values($storeIds))->field('id,name,phone')->select()) as $store) {
                $context['stores'][(int)$store['id']] = $store;
            }
        }
        if ($productIds) {
            foreach ($this->rows(Db::name('store_product')->whereIn('id', array_values($productIds))->field('id,store_name,image,store_info,unit_name')->select()) as $product) {
                $context['products'][(int)$product['id']] = $product;
            }
        }
        if ($staffIds) {
            foreach ($this->rows(Db::name('system_store_staff')->whereIn('id', array_values($staffIds))->field('id,staff_name,phone')->select()) as $staff) {
                $context['staff'][(int)$staff['id']] = $staff;
            }
        }
        return $context;
    }

    private function decodeArray($value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function staffIds(array $input, int $storeId): array
    {
        $ids = [];
        $primary = (int)($input['service_staff_id'] ?? 0);
        if ($primary > 0) $ids[$primary] = $primary;
        $sync = $input['sync_all'] ?? [];
        if (is_string($sync)) $sync = json_decode($sync, true);
        foreach (is_array($sync) ? $sync : [] as $row) {
            foreach ((array)($row['staffChoose'] ?? []) as $staff) {
                $id = (int)($staff['staff_id'] ?? 0);
                if ($id > 0) $ids[$id] = $id;
            }
        }
        foreach ($ids as $id) {
            $valid = Db::name('system_store_staff')->where('id', $id)
                ->where('status', 1)->where('is_del', 0)->find();
            if ($valid && (int)$valid['store_id'] !== $storeId && (int)($valid['can_choose'] ?? 0) !== 1) $valid = null;
            if (!$valid) throw new ValidateException('预约手艺人不存在或已离职。');
        }
        return array_values($ids);
    }

    /** Purchased add-ons are entitlement lines in the same V3 reservation. */
    private function addonDetails(int $uid, int $mainDetailId, $rawItems): array
    {
        $items = $this->decodeArray($rawItems);
        $details = [];
        $seen = [$mainDetailId => true];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $detailId = (int)($item['cart_info_id'] ?? 0);
            if ($detailId <= 0) throw new ValidateException('增项服务必须从已购项目中选择。');
            if (isset($seen[$detailId])) throw new ValidateException('预约项目不能重复选择。');
            $seen[$detailId] = true;
            $detail = Db::name('store_order_cart_info')->alias('c')
                ->join('store_order o', 'o.id=c.oid')
                ->where('c.id', $detailId)->where('o.uid', $uid)
                ->where('o.paid', 1)->where('o.is_del', 0)->where('o.is_system_del', 0)->where('o.is_user_del', 0)
                ->where('o.refund_status', 0)->where('o.terminal_action', 0)
                ->where('c.cart_type', 2)->where('c.product_type', 6)
                ->field('c.id,c.oid,c.product_id,c.sku_unique,c.cart_info,c.write_surplus_times')->find();
            if (!$detail) throw new ValidateException('增项项目不存在或不可预约。');
            $detail['_duration'] = max(1, (int)($item['addon_service_duration'] ?? StoreProductReservationServices::DEFAULT_PROJECT_SERVICE_DURATION));
            $details[] = $detail;
        }
        return $details;
    }

    private function clock(string $value): string
    {
        $value = trim($value);
        if (strpos($value, ' ') !== false) $value = date('H:i', strtotime($value) ?: 0);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) throw new ValidateException('预约时段无效。');
        return $value;
    }

    private function statusLabel(string $status): string
    {
        return ['PENDING_CONFIRMATION' => '待确认', 'UNSTARTED' => '待服务', 'IN_SERVICE' => '服务中', 'COMPLETED' => '已完成', 'CANCELLED' => '已取消', 'REJECTED' => '已拒绝'][$status] ?? '状态未知';
    }

    private function statusMessage(string $status, string $rejectReason = ''): string
    {
        if ($status === 'REJECTED' && trim($rejectReason) !== '') return trim($rejectReason);
        return [
            'PENDING_CONFIRMATION' => '预约已提交，请等待门店确认',
            'UNSTARTED' => '预约已确认，请按时到店',
            'IN_SERVICE' => '服务进行中',
            'COMPLETED' => '本次服务已完成',
            'CANCELLED' => '预约已取消',
            'REJECTED' => '门店未接受本次预约',
        ][$status] ?? '';
    }

    private function rows($rows): array
    {
        return is_object($rows) && method_exists($rows, 'toArray') ? $rows->toArray() : (array)$rows;
    }
}
