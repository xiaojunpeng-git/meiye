<?php

namespace app\services\order;

use app\model\order\StoreOrder;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\order\StoreReservationOrder;
use app\model\order\StoreWriteoffBatch;
use app\model\product\product\StoreProduct;
use app\model\yeji\StaffYeji;
use app\model\user\User;
use app\model\user\UserCardHolder;
use app\services\BaseServices;
use app\services\order\store\WriteOffOrderServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 多卡批量核销
 */
class BatchWriteoffServices extends BaseServices
{
    /**
     * 可选卡/项目列表
     */
    public function options(int $uid, int $storeId, string $keyword = ''): array
    {
        if ($uid <= 0) {
            throw new ValidateException('请先选择会员');
        }
        // 账户余额权威字段：会员维度 now_money/ben_money/give_money（非卡维度）
        $user = User::where('uid', $uid)
            ->field('uid,nickname,real_name,phone,avatar,now_money,ben_money,give_money')
            ->find();
        if (!$user) {
            throw new ValidateException('会员不存在');
        }
        $user = $user->toArray();

        $crossStore = (int)sys_config('cross_store_verification', 1);
        $holders = UserCardHolder::where('uid', $uid)
            ->where('is_del', 0)
            ->where('write_surplus_times', '>', 0)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $cards = [];
        $timesCardBalance = 0;
        $remainingTimes = 0;
        $validCardCount = 0;
        /** @var WriteOffOrderServices $writeOffServices */
        $writeOffServices = app()->make(WriteOffOrderServices::class);
        $time = time();
        // 几选几订单级剩余金额只加一次
        $packageAmountCounted = [];

        if ($holders) {
            $oids = array_values(array_unique(array_map('intval', array_column($holders, 'oid'))));
            $orders = StoreOrder::whereIn('id', $oids)
                ->where('paid', 1)
                ->where('is_del', 0)
                ->where('is_system_del', 0)
                ->where('is_user_del', 0)
                ->where('refund_status', 0)
                ->where('terminal_action', 0)
                ->where('card_upgrade_use_oid', 0)
                ->column('*', 'id');

            $cartRows = StoreOrderCartInfo::whereIn('oid', $oids)
                ->where('cart_type', 2)
                ->where('product_type', 6)
                ->where('write_surplus_times', '>', 0)
                ->where('is_writeoff', 0)
                ->select()
                ->toArray();
            $cartsByOid = [];
            foreach ($cartRows as $row) {
                $cartsByOid[(int)$row['oid']][] = $row;
            }

            $cartInfoIds = array_map(static fn($r) => (int)$r['id'], $cartRows);
            $reservationMap = [];
            if ($cartInfoIds) {
                $reservations = StoreReservationOrder::whereIn('cart_info_id', $cartInfoIds)
                    ->whereIn('status', [0, 1, 3])
                    ->where('is_del', 0)
                    ->where('is_system_del', 0)
                    ->field('id,oid,cart_info_id,status')
                    ->select()
                    ->toArray();
                foreach ($reservations as $res) {
                    $cid = (int)$res['cart_info_id'];
                    $reservationMap[$cid] = ($reservationMap[$cid] ?? 0) + 1;
                }
            }

            $keyword = trim($keyword);
            $exactCardNo = (bool)preg_match('/^[1-9]\d{6}$/', $keyword);

            foreach ($holders as $holder) {
                $oid = (int)$holder['oid'];
                $order = $orders[$oid] ?? null;
                if (!$order) {
                    continue;
                }
                if (!$crossStore && $storeId > 0 && (int)($order['store_id'] ?? 0) !== $storeId) {
                    continue;
                }
                $cardNo = trim((string)($holder['card_no'] ?? ''));
                // 完整 7 位卡号：仅精确匹配 card_no；禁止尾号/核销码冒充
                $keywordSkipCard = $exactCardNo && $cardNo !== $keyword;

                $projects = [];
                $cardHasWritableProject = false;
                $packageTimes = $writeOffServices->resolveSelectPackageWriteTimes($oid);
                $packageAlready = 0;
                if ($packageTimes > 0) {
                    $packageAlready = (int)StoreOrderWriteoff::where('oid', $oid)->where('status', 0)->sum('writeoff_num');
                }

                foreach ($cartsByOid[$oid] ?? [] as $cart) {
                    // 项目核销汇总仅 product_type=6；产品类型权益不计入
                    if ((int)($cart['product_type'] ?? -1) !== 6 || (int)($cart['cart_type'] ?? 0) !== 2) {
                        continue;
                    }
                    $decoded = is_string($cart['cart_info'] ?? null)
                        ? json_decode((string)$cart['cart_info'], true)
                        : ($cart['cart_info'] ?? []);
                    if (!is_array($decoded)) {
                        $decoded = [];
                    }
                    $productName = (string)($decoded['productInfo']['store_name'] ?? StoreProduct::where('id', (int)$cart['product_id'])->value('store_name') ?: '');
                    // 与 saveWriteOff 落账一致：行字段 pay_price（几选几在提交按订单分摊；列表预估仍按行）
                    $payPrice = WriteoffIntegerAmount::truncate($cart['pay_price'] ?? 0);
                    $writeTimes = (int)($cart['write_times'] ?? 0);
                    $surplus = (int)($cart['write_surplus_times'] ?? 0);
                    $already = max($writeTimes - $surplus, 0);
                    if ($packageTimes > 0) {
                        $nextAmount = $writeOffServices->allocatePersistedWriteoffAmount(
                            $cart,
                            $order,
                            1,
                            $already,
                            $packageTimes,
                            $packageAlready
                        );
                        $payPrice = WriteoffIntegerAmount::truncate($order['pay_price'] ?? 0);
                    } else {
                        $nextAmount = $writeOffServices->allocatePersistedWriteoffAmount(
                            $cart,
                            $order,
                            1,
                            $already,
                            0,
                            0
                        );
                    }
                    $occupied = (int)($reservationMap[(int)$cart['id']] ?? 0);
                    $available = max($surplus - $occupied, 0);
                    $writeStart = (int)($cart['write_start'] ?? 0);
                    $writeEnd = (int)($cart['write_end'] ?? 0);
                    $expired = ($writeStart > 0 && $time < $writeStart) || ($writeEnd > 0 && $time > $writeEnd);
                    $rightsVersion = $this->buildRightsVersion($cart);

                    // 会员汇总不受搜索关键字影响：过期/不可用/产品已排除
                    if (!$expired && $available > 0) {
                        $cardHasWritableProject = true;
                        $remainingTimes += $available;
                        if ($packageTimes > 0) {
                            if (!isset($packageAmountCounted[$oid])) {
                                $pkgRemain = max(0, $packageTimes - $packageAlready);
                                $timesCardBalance += $writeOffServices->allocatePersistedWriteoffAmount(
                                    $cart,
                                    $order,
                                    $pkgRemain,
                                    $packageAlready,
                                    $packageTimes,
                                    $packageAlready
                                );
                                $packageAmountCounted[$oid] = true;
                            }
                        } else {
                            $timesCardBalance += $writeOffServices->allocatePersistedWriteoffAmount(
                                $cart,
                                $order,
                                $available,
                                $already,
                                0,
                                0
                            );
                        }
                    }

                    $project = [
                        'cart_info_id' => (int)$cart['id'],
                        'cart_id' => (string)$cart['cart_id'],
                        'product_id' => (int)$cart['product_id'],
                        'product_type' => 6,
                        'product_name' => $productName,
                        'write_times' => $writeTimes,
                        'write_surplus_times' => $surplus,
                        'reservation_occupied' => $occupied,
                        'available_times' => $available,
                        'pay_price' => $payPrice,
                        'next_writeoff_amount' => $nextAmount,
                        'unit_performance' => (float)$writeOffServices->resolveUnitPerformancePublic($cart, $decoded),
                        'write_start' => $writeStart,
                        'write_end' => $writeEnd,
                        'expired' => $expired ? 1 : 0,
                        'rights_version' => $rightsVersion,
                        'source_type' => (string)($cart['source_type'] ?? ''),
                    ];

                    if ($keywordSkipCard) {
                        continue;
                    }
                    if ($keyword !== '') {
                        if (preg_match('/^[1-9]\d{6}$/', $keyword)) {
                            // 完整 7 位卡号精确匹配；不支持后 6 位模糊
                            if ($cardNo !== $keyword && mb_stripos($productName, $keyword) === false) {
                                continue;
                            }
                        } else {
                            $hay = $holder['card_name'] . ' ' . $cardNo . ' ' . $productName;
                            // 禁止用核销码/订单尾号冒充卡号搜索
                            if (mb_stripos($hay, $keyword) === false) {
                                continue;
                            }
                        }
                    }
                    $projects[] = $project;
                }

                if ($cardHasWritableProject) {
                    $validCardCount++;
                }

                if ($keywordSkipCard) {
                    continue;
                }
                if ($keyword !== '' && !$projects) {
                    if (preg_match('/^[1-9]\d{6}$/', $keyword)) {
                        if ($cardNo !== $keyword) {
                            continue;
                        }
                    } else {
                        $hay = ($holder['card_name'] ?? '') . ' ' . $cardNo;
                        if (mb_stripos($hay, $keyword) === false) {
                            continue;
                        }
                    }
                }

                $cardProjectSurplus = 0;
                foreach ($projects as $projectRow) {
                    if (!(int)($projectRow['expired'] ?? 0)) {
                        $cardProjectSurplus += (int)($projectRow['available_times'] ?? 0);
                    }
                }

                $cards[] = [
                    'holder_id' => (int)$holder['id'],
                    'oid' => $oid,
                    'card_name' => (string)$holder['card_name'],
                    'card_no' => trim((string)($holder['card_no'] ?? '')),
                    'verify_code' => (string)($holder['verify_code'] ?? ''),
                    'store_id' => (int)($holder['store_id'] ?? $order['store_id'] ?? 0),
                    'write_surplus_times' => $cardProjectSurplus,
                    'write_start' => (int)($holder['write_start'] ?? 0),
                    'write_end' => (int)($holder['write_end'] ?? 0),
                    'order_id' => (string)($order['order_id'] ?? ''),
                    'projects' => $projects,
                ];
            }
        }

        $memberSummary = $this->buildMemberSummary(
            $user,
            $timesCardBalance,
            $remainingTimes,
            $validCardCount
        );

        return [
            'user' => $user,
            'member_summary' => $memberSummary,
            'cards' => $cards,
            'performance_mode' => app()->make(WriteoffPerformanceModeServices::class)->getMode(),
        ];
    }

    /**
     * 项目核销工作台会员汇总（账户余额读会员权威字段；次卡权益服务端汇总）。
     * 金额单位：元；核销相关金额按 WriteoffIntegerAmount 整数截断。
     * 账户余额以 now_money 为准；若与 ben+give 不一致仅标记，不修正数据。
     */
    protected function buildMemberSummary(
        array $user,
        int $timesCardBalance,
        int $remainingTimes,
        int $validCardCount
    ): array {
        $nowRaw = (string)($user['now_money'] ?? '0');
        $benRaw = (string)($user['ben_money'] ?? '0');
        $giveRaw = (string)($user['give_money'] ?? '0');
        $sumParts = bcadd($benRaw, $giveRaw, 2);
        $balanceMismatch = bccomp($nowRaw, $sumParts, 2) !== 0;

        $nowMoney = WriteoffIntegerAmount::truncate($nowRaw);
        $benMoney = WriteoffIntegerAmount::truncate($benRaw);
        $giveMoney = WriteoffIntegerAmount::truncate($giveRaw);
        $timesCardBalance = max(0, $timesCardBalance);
        $remainingTimes = max(0, $remainingTimes);
        $validCardCount = max(0, $validCardCount);

        $phone = trim((string)($user['phone'] ?? ''));
        $phoneMasked = $phone;
        if (preg_match('/^\d{7,}$/', $phone)) {
            $phoneMasked = substr_replace($phone, '****', 3, 4);
        }

        $summary = [
            'uid' => (int)($user['uid'] ?? 0),
            'nickname' => (string)($user['nickname'] ?? ''),
            'real_name' => (string)($user['real_name'] ?? ''),
            'phone' => $phone,
            'phone_masked' => $phoneMasked,
            'avatar' => (string)($user['avatar'] ?? ''),
            // 会员账户维度（非卡）：页面「账户余额/本金/赠金」
            'now_money' => $nowMoney,
            'ben_money' => $benMoney,
            'give_money' => $giveMoney,
            // 次卡汇总：仅 product_type=6 可核销项目
            'times_card_balance' => $timesCardBalance,
            'remaining_times' => $remainingTimes,
            'valid_card_count' => $validCardCount,
            // 总可用余额 = 账户余额(now_money) + 次卡权益
            'total_available_balance' => $nowMoney + $timesCardBalance,
            'amount_unit' => 'yuan',
            'amount_scale' => 'integer_truncated',
        ];
        if ($balanceMismatch) {
            $summary['balance_mismatch'] = true;
            $summary['balance_mismatch_detail'] = [
                'now_money_raw' => $nowRaw,
                'ben_money_raw' => $benRaw,
                'give_money_raw' => $giveRaw,
                'ben_plus_give_raw' => $sumParts,
            ];
        } else {
            $summary['balance_mismatch'] = false;
        }
        return $summary;
    }

    /**
     * 试算：不落库
     */
    public function preview(array $payload, int $storeId, int $staffId): array
    {
        $parsed = $this->parseAndValidateItems($payload, $storeId, false);
        return [
            'items' => $parsed['lines'],
            'total_amount' => $parsed['total_amount'],
            'total_times' => $parsed['total_times'],
            'total_cards' => $parsed['total_cards'],
            'total_projects' => count($parsed['lines']),
            'performance_mode' => app()->make(WriteoffPerformanceModeServices::class)->getMode(),
        ];
    }

    /**
     * 提交批量核销
     */
    public function commit(array $payload, int $storeId, int $staffId): array
    {
        $idempotencyKey = trim((string)($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new ValidateException('缺少幂等键');
        }

        // 先按原始 payload 生成指纹并处理同键命中（重放时权益已扣，不能先走严格余次/版本校验）
        $fingerprint = $this->buildPayloadFingerprint($payload, $storeId, $staffId);
        $existing = Db::name('store_writeoff_batch')->where('idempotency_key', $idempotencyKey)->find();
        if ($existing) {
            return $this->returnIdempotentBatch($existing, $fingerprint, $staffId, $storeId);
        }

        // 新请求：严格校验（含手艺人必填、版本、余次）
        $parsed = $this->parseAndValidateItems($payload, $storeId, true);
        $uid = (int)$parsed['uid'];
        $isBudan = (int)($payload['is_budan'] ?? 0);
        $budanTime = trim((string)($payload['budan_time'] ?? ''));
        $operateTime = time();
        $businessTime = $operateTime;
        if ($isBudan === 1) {
            if ($budanTime === '') {
                throw new ValidateException('启用补单时必须选择补单日期');
            }
            $bt = strtotime($budanTime);
            if ($bt === false) {
                throw new ValidateException('补单日期无效');
            }
            if ($bt > $operateTime + 60) {
                throw new ValidateException('补单日期不能选择未来时间');
            }
            $businessTime = $bt;
        } else {
            $isBudan = 0;
            $budanTime = '';
        }

        $mode = app()->make(WriteoffPerformanceModeServices::class)->getMode();
        $batchNo = $this->makeBatchNo();

        try {
            $result = $this->transaction(function () use (
                $parsed, $uid, $storeId, $staffId, $idempotencyKey, $batchNo,
                $isBudan, $budanTime, $businessTime, $operateTime, $mode, $payload, $fingerprint
            ) {
                // 行锁 + 版本重校验
                $cartInfoIds = array_map(static fn($l) => (int)$l['cart_info_id'], $parsed['lines']);
                $locked = StoreOrderCartInfo::whereIn('id', $cartInfoIds)->lock(true)->select()->toArray();
                $lockedMap = [];
                foreach ($locked as $row) {
                    $lockedMap[(int)$row['id']] = $row;
                }
                foreach ($parsed['lines'] as $line) {
                    $row = $lockedMap[(int)$line['cart_info_id']] ?? null;
                    if (!$row) {
                        throw new ValidateException($this->lineError($line, '权益行不存在或已变更'));
                    }
                    if ($this->buildRightsVersion($row) !== (string)$line['rights_version']) {
                        throw new ValidateException($this->lineError($line, '权益已被其他人操作，请刷新后重试'));
                    }
                    if ((int)$row['write_surplus_times'] < (int)$line['cart_num']) {
                        throw new ValidateException($this->lineError($line, '剩余次数不足'));
                    }
                }

                $batchId = (int)Db::name('store_writeoff_batch')->insertGetId([
                    'batch_no' => $batchNo,
                    'uid' => $uid,
                    'store_id' => $storeId,
                    'staff_id' => $staffId,
                    'total_amount' => 0,
                    'total_times' => 0,
                    'total_cards' => $parsed['total_cards'],
                    'total_projects' => count($parsed['lines']),
                    'business_time' => $businessTime,
                    'operate_time' => $operateTime,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'status' => 0,
                    'remark' => (string)($payload['remark'] ?? ''),
                    'add_time' => $operateTime,
                    'update_time' => $operateTime,
                ]);

                /** @var WriteOffOrderServices $writeOffServices */
                $writeOffServices = app()->make(WriteOffOrderServices::class);
                $writeoffIds = [];
                $totalAmount = 0;
                $totalTimes = 0;
                $detailLines = [];

                foreach ($parsed['by_oid'] as $oid => $group) {
                    $orderInfo = $writeOffServices->getOrderCartInfo(0, (int)$oid);
                    $cartIds = [];
                    $syncAll = [];
                    foreach ($group['items'] as $item) {
                        $cartIds[] = [
                            'cart_id' => $item['cart_id'],
                            'cart_num' => (int)$item['cart_num'],
                            'service_object' => $item['service_object'] ?? '本人',
                        ];
                        if (!empty($item['sync']) && is_array($item['sync'])) {
                            $sync = $item['sync'];
                            $sync['cart_id'] = $item['cart_id'];
                            if (empty($sync['goods_id'])) {
                                $sync['goods_id'] = (int)$item['product_id'];
                            }
                            if (empty($sync['type'])) {
                                $sync['type'] = 3;
                            }
                            $syncAll[] = $sync;
                        }
                    }

                    try {
                        $writeOffServices->writeoffOrder(
                            0,
                            $orderInfo,
                            $cartIds,
                            'cashier',
                            $staffId,
                            $syncAll,
                            $isBudan,
                            $budanTime,
                            0,
                            0,
                            [
                                'batch_id' => $batchId,
                                'performance_mode' => $mode,
                                'defer_side_effects' => true,
                            ]
                        );
                    } catch (\Throwable $e) {
                        $cardName = $group['card_name'] ?? ('订单' . $oid);
                        $msg = $e->getMessage() ?: '核销失败';
                        throw new ValidateException('【' . $cardName . '】' . $msg);
                    }

                    $cartIdList = array_column($cartIds, 'cart_id');
                    $newRows = StoreOrderWriteoff::where('oid', (int)$oid)
                        ->where('status', 0)
                        ->whereIn('order_cart_id', array_column($group['items'], 'cart_info_id'))
                        ->where(function ($q) use ($batchId) {
                            $q->where('batch_id', 0)->whereOr('batch_id', $batchId);
                        })
                        ->order('id', 'desc')
                        ->limit(count($cartIds))
                        ->select()
                        ->toArray();

                    // 精确匹配：按 order_cart_id 取本次新增（batch_id=0）再更新
                    $byCartInfo = [];
                    foreach ($newRows as $nr) {
                        $byCartInfo[(int)$nr['order_cart_id']] = $nr;
                    }
                    foreach ($group['items'] as $item) {
                        $wo = $byCartInfo[(int)$item['cart_info_id']] ?? null;
                        if (!$wo) {
                            // 回退：取该行最新一条未撤销核销
                            $wo = StoreOrderWriteoff::where('oid', (int)$oid)
                                ->where('order_cart_id', (int)$item['cart_info_id'])
                                ->where('status', 0)
                                ->order('id', 'desc')
                                ->find();
                            $wo = $wo ? $wo->toArray() : null;
                        }
                        if (!$wo) {
                            throw new ValidateException($this->lineError($item, '未生成核销记录'));
                        }
                        StoreOrderWriteoff::where('id', (int)$wo['id'])->update([
                            'batch_id' => $batchId,
                            'performance_mode' => $mode,
                            // DB 列为 INT：禁止把 "surplus_end_id" 去非数字后整串强转（会超 INT 上限）
                            'rights_version' => $this->rightsVersionToStorageInt((string)($item['rights_version'] ?? '')),
                        ]);
                        $amount = WriteoffIntegerAmount::truncate($wo['writeoff_price'] ?? 0);
                        $times = (int)($wo['writeoff_num'] ?? $item['cart_num']);
                        $totalAmount += $amount;
                        $totalTimes += $times;
                        $writeoffIds[] = (int)$wo['id'];
                        $detailLines[] = [
                            'writeoff_id' => (int)$wo['id'],
                            'holder_id' => (int)$item['holder_id'],
                            'oid' => (int)$oid,
                            'card_name' => $group['card_name'],
                            'cart_info_id' => (int)$item['cart_info_id'],
                            'product_id' => (int)$item['product_id'],
                            'product_name' => $item['product_name'],
                            'cart_num' => $times,
                            'writeoff_amount' => $amount,
                        ];
                    }
                    unset($cartIdList);
                }

                Db::name('store_writeoff_batch')->where('id', $batchId)->update([
                    'total_amount' => $totalAmount,
                    'total_times' => $totalTimes,
                    'update_time' => time(),
                ]);

                return [
                    'batch_id' => $batchId,
                    'batch_no' => $batchNo,
                    'uid' => $uid,
                    'store_id' => $storeId,
                    'staff_id' => $staffId,
                    'total_amount' => $totalAmount,
                    'total_times' => $totalTimes,
                    'total_cards' => $parsed['total_cards'],
                    'total_projects' => count($detailLines),
                    'business_time' => $businessTime,
                    'operate_time' => $operateTime,
                    'performance_mode' => $mode,
                    'items' => $detailLines,
                    'writeoff_ids' => $writeoffIds,
                    'idempotent' => false,
                ];
            });
        } catch (\Throwable $e) {
            // 仅明确的同键并发竞争可复用；禁止吞掉任意业务/事务异常
            if ($this->isConcurrentRaceException($e)) {
                $again = StoreWriteoffBatch::where('idempotency_key', $idempotencyKey)->find();
                if ($again) {
                    return $this->returnIdempotentBatch($again->toArray(), $fingerprint, $staffId, $storeId);
                }
            }
            throw $e instanceof ValidateException ? $e : new ValidateException($e->getMessage() ?: '批量核销失败');
        }

        // 事务成功后再触发侧效应（财务流水等）
        $this->flushDeferredFinance($result['writeoff_ids'] ?? [], $staffId, $storeId);

        unset($result['writeoff_ids']);
        return $result;
    }

    /**
     * 按批量核销主单撤销：逐条撤销关联核销子单。
     *
     * 并发/幂等加固（20260724 核销业务主单聚合方案 §3.4）：
     * 整个撤销流程放在同一事务内，且先对 batch 行加悲观锁（SELECT ... FOR UPDATE）。
     * 并发/连续第二次调用会阻塞在锁上，待第一次事务提交后重读 status=1，
     * 直接幂等返回 true，不再二次处理 writeoff / 不二次恢复权益，也不抛错。
     */
    public function cancelBatch(int $batchId, string $remark = '', int $storeScope = 0): bool
    {
        if ($batchId <= 0) {
            throw new ValidateException('缺少批量核销单');
        }

        return (bool)$this->transaction(function () use ($batchId, $remark, $storeScope) {
            $batch = Db::name('store_writeoff_batch')->where('id', $batchId)->lock(true)->find();
            if (!$batch) {
                throw new ValidateException('批量核销单不存在');
            }
            if ($storeScope > 0 && (int)$batch['store_id'] !== $storeScope) {
                throw new ValidateException('无权撤销其它门店的批量核销');
            }
            if ((int)$batch['status'] === 1) {
                // 幂等：已撤销的批次直接成功返回，绝不重复处理 writeoff / 二次恢复权益
                return true;
            }

            /** @var StoreOrderWriteOffServices $writeOffServices */
            $writeOffServices = app()->make(StoreOrderWriteOffServices::class);
            $writeoffs = StoreOrderWriteoff::where('batch_id', $batchId)->where('status', 0)->select()->toArray();
            foreach ($writeoffs as $wo) {
                $sub = StoreOrder::where('link_id', (int)$wo['id'])->where('order_type', 2)->find();
                if (!$sub) {
                    // 无子单：仍须冲销核销记录并恢复权益，禁止只改 status 导致次数黑洞
                    $this->cancelOrphanWriteoffRow($wo, $remark);
                    continue;
                }
                $writeOffServices->cancelWriteoff((int)$sub['id'], $remark, $storeScope);
            }

            Db::name('store_writeoff_batch')->where('id', $batchId)->update([
                'status' => 1,
                'update_time' => time(),
                'remark' => $remark !== '' ? $remark : (string)($batch['remark'] ?? ''),
            ]);
            return true;
        });
    }

    /**
     * 批量撤销时遇到无核销子单的异常明细：标记撤销并恢复剩余次数。
     */
    protected function cancelOrphanWriteoffRow(array $wo, string $remark = ''): void
    {
        $wid = (int)($wo['id'] ?? 0);
        if ($wid <= 0 || (int)($wo['status'] ?? 0) === 1) {
            return;
        }
        $locked = StoreOrderWriteoff::where('id', $wid)->lock(true)->find();
        if (!$locked || (int)$locked['status'] === 1) {
            return;
        }
        $row = $locked->toArray();
        StoreOrderWriteoff::where('id', $wid)->update(['status' => 1]);
        StaffYeji::where('link_id', $wid)->where('type', 3)->update(['status' => 1]);
        $cartId = (int)($row['order_cart_id'] ?? 0);
        $number = (int)($row['writeoff_num'] ?? 1);
        if ($number < 1) {
            $number = 1;
        }
        if ($cartId > 0) {
            StoreOrderCartInfo::where('id', $cartId)->inc('write_surplus_times', $number)->update();
            StoreOrderCartInfo::where('id', $cartId)->update(['is_writeoff' => 0]);
        }
        if ((int)($row['uid'] ?? 0) > 0 && (int)($row['oid'] ?? 0) > 0) {
            UserCardHolder::where('uid', (int)$row['uid'])->where('oid', (int)$row['oid'])
                ->inc('write_surplus_times', $number)->update();
        }
        unset($remark);
    }

    /**
     * 事务提交后补齐财务侧效应。
     * link_id 保持原订单号；幂等靠 staff/store 流水 idempotency_key（bw:{writeoffId}:...）。
     */
    protected function flushDeferredFinance(array $writeoffIds, int $staffId, int $storeId): void
    {
        if (!$writeoffIds) {
            return;
        }
        /** @var \app\services\store\finance\StaffFlowingWaterServices $staffFinance */
        $staffFinance = app()->make(\app\services\store\finance\StaffFlowingWaterServices::class);
        /** @var \app\services\store\finance\StoreFinanceFlowServices $storeFinance */
        $storeFinance = app()->make(\app\services\store\finance\StoreFinanceFlowServices::class);

        foreach (array_values(array_unique(array_map('intval', $writeoffIds))) as $writeoffId) {
            if ($writeoffId <= 0) {
                continue;
            }
            $lockName = 'wo_batch_fin_' . $writeoffId;
            $gotLock = false;
            try {
                $lockRet = Db::query("SELECT GET_LOCK(?, 5) AS l", [$lockName]);
                $gotLock = (int)($lockRet[0]['l'] ?? 0) === 1;
                if (!$gotLock) {
                    throw new ValidateException('系统繁忙，财务入账请稍后重试');
                }
                $row = StoreOrderWriteoff::where('id', $writeoffId)->find();
                if (!$row) {
                    continue;
                }
                $row = $row->toArray();
                $orderInfo = StoreOrder::where('id', (int)$row['oid'])->find();
                if (!$orderInfo) {
                    continue;
                }
                $orderInfo = $orderInfo->toArray();
                $price = WriteoffIntegerAmount::truncate($row['writeoff_price'] ?? 0);
                if ($price <= 0) {
                    continue;
                }
                if ($staffId > 0) {
                    $orderInfo['staff_id'] = $staffId;
                }
                if ($storeId > 0) {
                    $orderInfo['store_id'] = $storeId;
                }
                // 保留原订单 order_id 作为财务 link_id；用 batch_writeoff_id 派生各类流水幂等键
                $orderInfo['batch_writeoff_id'] = $writeoffId;

                if ($staffId > 0) {
                    $staffFinance->setFinance($orderInfo, 6, $price);
                }
                $storeFinance->setFinance($orderInfo, 8, $price);
            } finally {
                if ($gotLock) {
                    Db::query("SELECT RELEASE_LOCK(?)", [$lockName]);
                }
            }
        }
    }

    /**
     * 幂等复用：指纹一致 + 主单完整 → 补齐侧效应后返回。
     */
    protected function returnIdempotentBatch(array $batch, string $fingerprint, int $staffId, int $storeId): array
    {
        if ((int)($batch['status'] ?? 0) === 1) {
            throw new ValidateException('该幂等键对应的批量核销已撤销，请使用新的幂等键');
        }
        $storedFp = trim((string)($batch['request_fingerprint'] ?? ''));
        // 旧空指纹主单：无可信原始请求 hash，禁止用入账后回填数据冒充兼容重放
        if ($storedFp === '') {
            throw new ValidateException('旧核销单无法安全重放，请使用新的幂等键');
        }
        if (!hash_equals($storedFp, $fingerprint)) {
            throw new ValidateException('幂等键已用于其它核销请求，请使用新的幂等键');
        }
        $this->assertBatchComplete($batch);
        $result = $this->formatBatchResult($batch, true);
        $writeoffIds = array_map(static fn($item) => (int)$item['writeoff_id'], $result['items'] ?? []);
        $this->flushDeferredFinance($writeoffIds, $staffId > 0 ? $staffId : (int)$batch['staff_id'], $storeId > 0 ? $storeId : (int)$batch['store_id']);
        return $result;
    }

    /**
     * 从提交 payload 生成稳定指纹（含补单、手艺人业绩/点客轮牌）。
     */
    protected function buildPayloadFingerprint(array $payload, int $storeId, int $staffId): string
    {
        $uid = (int)($payload['uid'] ?? 0);
        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new ValidateException('请选择要核销的项目');
        }
        $merged = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ValidateException('核销明细格式错误');
            }
            $cartInfoId = (int)($item['cart_info_id'] ?? 0);
            $cartNum = (int)($item['cart_num'] ?? 0);
            if ($cartInfoId <= 0 || $cartNum <= 0) {
                throw new ValidateException('第' . ($idx + 1) . '项：请填写有效的核销次数');
            }
            if (!isset($merged[$cartInfoId])) {
                $merged[$cartInfoId] = $item;
                $merged[$cartInfoId]['cart_num'] = $cartNum;
            } else {
                $merged[$cartInfoId]['cart_num'] += $cartNum;
                $prevStaff = $this->normalizeStaffChooseFingerprint($merged[$cartInfoId]['sync'] ?? null);
                $nextStaff = $this->normalizeStaffChooseFingerprint($item['sync'] ?? null);
                if ($prevStaff !== $nextStaff) {
                    throw new ValidateException('同一权益行手艺人分配不一致');
                }
            }
        }
        $parts = [];
        foreach ($merged as $item) {
            $parts[] = implode(':', [
                (int)$item['cart_info_id'],
                (int)$item['cart_num'],
                ((trim((string)($item['service_object'] ?? '')) === '朋友') ? '朋友' : '本人'),
                $this->normalizeStaffChooseFingerprint($item['sync'] ?? null),
            ]);
        }
        sort($parts);
        [$isBudan, $bizTs] = $this->normalizeBudanFingerprint($payload);
        return hash('sha256', implode('|', [
            $uid,
            $storeId,
            $staffId,
            $isBudan,
            $bizTs,
            implode(';', $parts),
        ]));
    }

    /**
     * 与 commit() 保存 business_time 相同口径：完整秒级时间戳。
     * @return array{0:int,1:string} [is_budan, unix_ts or '0']
     */
    protected function normalizeBudanFingerprint(array $payload): array
    {
        $isBudan = (int)($payload['is_budan'] ?? 0) === 1 ? 1 : 0;
        if ($isBudan !== 1) {
            return [0, '0'];
        }
        $budanTime = trim((string)($payload['budan_time'] ?? ''));
        $bt = $budanTime !== '' ? strtotime($budanTime) : false;
        return [1, ($bt !== false) ? (string)$bt : '0'];
    }

    /**
     * 手艺人分配规范化：staff_id,yeji,percent,is_dian,is_lun
     */
    protected function normalizeStaffChooseFingerprint($sync): string
    {
        if (!is_array($sync)) {
            return '';
        }
        $rows = [];
        foreach (($sync['staffChoose'] ?? []) as $staff) {
            if (!is_array($staff)) {
                continue;
            }
            $sid = (int)($staff['staff_id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $yeji = bcadd((string)($staff['yeji'] ?? '0'), '0', 2);
            $percent = bcadd((string)($staff['percent'] ?? '0'), '0', 2);
            $isDian = (int)($staff['is_dian'] ?? 0) === 1 ? 1 : 0;
            $isLun = (int)($staff['is_lun'] ?? 0) === 1 ? 1 : 0;
            $rows[] = $sid . ',' . $yeji . ',' . $percent . ',' . $isDian . ',' . $isLun;
        }
        sort($rows);
        return implode('+', $rows);
    }

    protected function assertBatchComplete(array $batch): void
    {
        $writeoffs = StoreOrderWriteoff::where('batch_id', (int)$batch['id'])
            ->where('status', 0)
            ->select()
            ->toArray();
        if ($writeoffs === []) {
            throw new ValidateException('幂等键对应的核销明细不完整，请联系管理员');
        }
        $sumTimes = 0;
        $sumAmount = 0;
        foreach ($writeoffs as $wo) {
            $sumTimes += (int)($wo['writeoff_num'] ?? 0);
            $sumAmount += WriteoffIntegerAmount::truncate($wo['writeoff_price'] ?? 0);
        }
        if ($sumTimes !== (int)($batch['total_times'] ?? 0)
            || $sumAmount !== WriteoffIntegerAmount::truncate($batch['total_amount'] ?? 0)
            || count($writeoffs) !== (int)($batch['total_projects'] ?? 0)
        ) {
            throw new ValidateException('幂等键对应的批量核销数据不完整，请联系管理员');
        }
    }

    protected function isConcurrentRaceException(\Throwable $e): bool
    {
        $msg = (string)$e->getMessage();
        if ($msg === '') {
            return false;
        }
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false) {
            return true;
        }
        if (mb_strpos($msg, '权益已被其他人操作') !== false) {
            return true;
        }
        if (mb_strpos($msg, '剩余次数不足') !== false) {
            return true;
        }
        return false;
    }

    protected function parseAndValidateItems(array $payload, int $storeId, bool $strictVersion): array
    {
        $uid = (int)($payload['uid'] ?? 0);
        if ($uid <= 0) {
            throw new ValidateException('请先选择会员');
        }
        $items = $payload['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new ValidateException('请选择要核销的项目');
        }

        $crossStore = (int)sys_config('cross_store_verification', 1);
        /** @var WriteOffOrderServices $writeOffServices */
        $writeOffServices = app()->make(WriteOffOrderServices::class);
        $time = time();
        $lines = [];
        $byOid = [];
        $totalAmount = 0;
        $totalTimes = 0;
        $cardOids = [];
        $packageTimesByOid = [];
        $packageAlreadyByOid = [];

        // 同批同权益行合并次数
        $merged = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                throw new ValidateException('核销明细格式错误');
            }
            $cartInfoId = (int)($item['cart_info_id'] ?? 0);
            $cartNum = (int)($item['cart_num'] ?? 0);
            if ($cartInfoId <= 0 || $cartNum <= 0) {
                throw new ValidateException('第' . ($idx + 1) . '项：请填写有效的核销次数');
            }
            $key = $cartInfoId;
            if (!isset($merged[$key])) {
                $merged[$key] = $item;
                $merged[$key]['cart_num'] = $cartNum;
            } else {
                $merged[$key]['cart_num'] += $cartNum;
            }
        }

        foreach ($merged as $item) {
            $cartInfoId = (int)$item['cart_info_id'];
            $holderId = (int)($item['holder_id'] ?? 0);
            $oid = (int)($item['oid'] ?? 0);
            $cartNum = (int)$item['cart_num'];

            $cart = StoreOrderCartInfo::where('id', $cartInfoId)->find();
            if (!$cart) {
                throw new ValidateException('权益行不存在');
            }
            $cart = $cart->toArray();
            if ($oid > 0 && (int)$cart['oid'] !== $oid) {
                throw new ValidateException('权益行与订单不匹配');
            }
            $oid = (int)$cart['oid'];

            $holder = null;
            if ($holderId > 0) {
                $holder = UserCardHolder::where('id', $holderId)->where('uid', $uid)->find();
            }
            if (!$holder) {
                $holder = UserCardHolder::where('uid', $uid)->where('oid', $oid)->where('is_del', 0)->find();
            }
            if (!$holder) {
                throw new ValidateException('卡项不存在或不属于该会员');
            }
            $holder = $holder->toArray();
            $cardName = (string)$holder['card_name'];

            $order = StoreOrder::where('id', $oid)->find();
            if (!$order) {
                throw new ValidateException('【' . $cardName . '】订单不存在');
            }
            $order = $order->toArray();
            if ((int)$order['uid'] !== $uid) {
                throw new ValidateException('【' . $cardName . '】不属于该会员');
            }
            if ((int)($order['card_upgrade_use_oid'] ?? 0) > 0) {
                throw new ValidateException('【' . $cardName . '】失效卡不可核销');
            }
            if ((int)($order['refund_status'] ?? 0) !== 0 || (int)($order['terminal_action'] ?? 0) !== 0) {
                throw new ValidateException('【' . $cardName . '】订单状态不可核销');
            }
            if (!$crossStore && $storeId > 0 && (int)($order['store_id'] ?? 0) !== $storeId) {
                throw new ValidateException('【' . $cardName . '】不支持跨店核销');
            }

            $decoded = is_string($cart['cart_info'] ?? null)
                ? json_decode((string)$cart['cart_info'], true)
                : ($cart['cart_info'] ?? []);
            if (!is_array($decoded)) {
                $decoded = [];
            }
            $productName = (string)($decoded['productInfo']['store_name']
                ?? StoreProduct::where('id', (int)$cart['product_id'])->value('store_name')
                ?: '项目');

            $lineMeta = [
                'holder_id' => (int)$holder['id'],
                'oid' => $oid,
                'card_name' => $cardName,
                'cart_info_id' => $cartInfoId,
                'cart_id' => (string)$cart['cart_id'],
                'product_id' => (int)$cart['product_id'],
                'product_name' => $productName,
                'cart_num' => $cartNum,
                'service_object' => ((trim((string)($item['service_object'] ?? '')) === '朋友') ? '朋友' : '本人'),
                'rights_version' => (string)($item['rights_version'] ?? ''),
                'sync' => $item['sync'] ?? null,
            ];

            if ((int)$cart['product_type'] !== 6 || (int)$cart['cart_type'] !== 2) {
                throw new ValidateException($this->lineError($lineMeta, '不是可核销项目'));
            }
            // 与原核销/页面一致：可核销非赠送项目必须分配手艺人（staffChoose）；禁止接口绕过
            $isGift = (int)($decoded['is_gift'] ?? $cart['is_gift'] ?? 0) === 1;
            if (!$isGift) {
                $sync = is_array($item['sync'] ?? null) ? $item['sync'] : null;
                $staffChoose = is_array($sync) ? ($sync['staffChoose'] ?? null) : null;
                if (!is_array($staffChoose) || $staffChoose === []) {
                    throw new ValidateException($this->lineError($lineMeta, '请先选择手艺人'));
                }
                $hasStaff = false;
                foreach ($staffChoose as $staffRow) {
                    if (is_array($staffRow) && (int)($staffRow['staff_id'] ?? 0) > 0) {
                        $hasStaff = true;
                        break;
                    }
                }
                if (!$hasStaff) {
                    throw new ValidateException($this->lineError($lineMeta, '请先选择手艺人'));
                }
            }
            if ((int)$cart['is_writeoff'] === 1 || (int)$cart['write_surplus_times'] <= 0) {
                throw new ValidateException($this->lineError($lineMeta, '已无剩余次数'));
            }
            if ((int)$cart['write_surplus_times'] < $cartNum) {
                throw new ValidateException($this->lineError($lineMeta, '剩余次数不足（剩余'
                    . (int)$cart['write_surplus_times'] . '次）'));
            }

            $writeStart = (int)($cart['write_start'] ?? 0);
            $writeEnd = (int)($cart['write_end'] ?? 0);
            if ($writeStart > 0 && $time < $writeStart) {
                throw new ValidateException($this->lineError($lineMeta, '还未到核销开始时间'));
            }
            if ($writeEnd > 0 && $time > $writeEnd) {
                throw new ValidateException($this->lineError($lineMeta, '已超过核销结束时间'));
            }

            $occupied = (int)StoreReservationOrder::where('cart_info_id', $cartInfoId)
                ->whereIn('status', [0, 1, 3])
                ->where('is_del', 0)
                ->where('is_system_del', 0)
                ->count();
            if ($occupied > 0 && ((int)$cart['write_surplus_times'] - $occupied) < $cartNum) {
                throw new ValidateException($this->lineError($lineMeta, '有预约占用，可用次数不足'));
            }

            $currentVersion = $this->buildRightsVersion($cart);
            if ($strictVersion && $lineMeta['rights_version'] !== '' && $lineMeta['rights_version'] !== $currentVersion) {
                throw new ValidateException($this->lineError($lineMeta, '权益已被其他人操作，请刷新后重试'));
            }
            $lineMeta['rights_version'] = $currentVersion;

            try {
                $writeOffServices->assertWriteoffWithinDebtLimit($cart, $cartNum, $order);
            } catch (ValidateException $e) {
                throw new ValidateException($this->lineError($lineMeta, $e->getMessage()));
            }

            $writeTimes = (int)($cart['write_times'] ?? 0);
            $already = (int)StoreOrderWriteoff::where('oid', $oid)
                ->where('order_cart_id', $cartInfoId)
                ->where('status', 0)
                ->sum('writeoff_num');
            // 同批内已累计（同权益行）
            if (isset($byOid[$oid])) {
                foreach ($byOid[$oid]['items'] as $prev) {
                    if ((int)$prev['cart_info_id'] === $cartInfoId) {
                        $already += (int)$prev['cart_num'];
                    }
                }
            }
            // 与 saveWriteOff 落账同一口径，保证 preview.total_amount === commit.total_amount
            if (!isset($packageTimesByOid[$oid])) {
                $packageTimesByOid[$oid] = $writeOffServices->resolveSelectPackageWriteTimes($oid);
                $packageAlreadyByOid[$oid] = $packageTimesByOid[$oid] > 0
                    ? (int)StoreOrderWriteoff::where('oid', $oid)->where('status', 0)->sum('writeoff_num')
                    : 0;
            }
            if ($packageTimesByOid[$oid] > 0) {
                $payPrice = WriteoffIntegerAmount::truncate($order['pay_price'] ?? 0);
                $amount = $writeOffServices->allocatePersistedWriteoffAmount(
                    $cart,
                    $order,
                    $cartNum,
                    $already,
                    $packageTimesByOid[$oid],
                    $packageAlreadyByOid[$oid]
                );
                $packageAlreadyByOid[$oid] += $cartNum;
            } else {
                $payPrice = WriteoffIntegerAmount::truncate($cart['pay_price'] ?? 0);
                $amount = $writeOffServices->allocatePersistedWriteoffAmount(
                    $cart,
                    $order,
                    $cartNum,
                    $already,
                    0,
                    0
                );
            }
            $lineMeta['writeoff_amount'] = $amount;
            $lineMeta['pay_price'] = $payPrice;
            $lineMeta['write_times'] = $writeTimes;
            $lineMeta['already_times'] = $already;
            $lineMeta['write_surplus_times'] = (int)$cart['write_surplus_times'];

            $lines[] = $lineMeta;
            $totalAmount += $amount;
            $totalTimes += $cartNum;
            $cardOids[$oid] = true;

            if (!isset($byOid[$oid])) {
                $byOid[$oid] = [
                    'card_name' => $cardName,
                    'holder_id' => (int)$holder['id'],
                    'items' => [],
                ];
            }
            $byOid[$oid]['items'][] = $lineMeta;
        }

        return [
            'uid' => $uid,
            'lines' => $lines,
            'by_oid' => $byOid,
            'total_amount' => $totalAmount,
            'total_times' => $totalTimes,
            'total_cards' => count($cardOids),
        ];
    }

    public function buildRightsVersion(array $cart): string
    {
        return (int)($cart['write_surplus_times'] ?? 0)
            . '_' . (int)($cart['write_end'] ?? 0)
            . '_' . (int)($cart['id'] ?? 0);
    }

    /**
     * 将乐观锁字符串落入 eb_store_order_writeoff.rights_version（SIGNED INT）。
     * 比较仍用字符串 buildRightsVersion；落库仅存可容纳的稳定哈希。
     */
    protected function rightsVersionToStorageInt(string $version): int
    {
        $version = trim($version);
        if ($version === '') {
            return 0;
        }
        return (int)(crc32($version) & 0x7FFFFFFF);
    }

    protected function lineError(array $line, string $msg): string
    {
        $card = $line['card_name'] ?? '';
        $project = $line['product_name'] ?? '';
        $prefix = '';
        if ($card !== '') {
            $prefix .= '【' . $card . '】';
        }
        if ($project !== '') {
            $prefix .= $project . '：';
        }
        return $prefix . $msg;
    }

    /**
     * 生成 batch_no：恒为 WB + 纯数字。
     * 修复：原实现 substr((string)microtime(true), -4) 在小数点恰好落入末 4 位时会把 "." 混入
     * batch_no（如 WB20260724005743.474791），破坏「完整 WB[0-9]+ batch_no 搜索」的前提；
     * 改为对微秒部分单独取整、左补零，确保恒为数字。
     */
    protected function makeBatchNo(): string
    {
        $micro = (int)round((microtime(true) - floor(microtime(true))) * 10000); // 0~9999
        return 'WB' . date('YmdHis') . str_pad((string)$micro, 4, '0', STR_PAD_LEFT) . random_int(100, 999);
    }

    protected function formatBatchResult(array $batch, bool $idempotent): array
    {
        $items = StoreOrderWriteoff::where('batch_id', (int)$batch['id'])
            ->where('status', 0)
            ->select()
            ->toArray();
        $detail = [];
        foreach ($items as $wo) {
            $cart = StoreOrderCartInfo::where('id', (int)$wo['order_cart_id'])->find();
            $cart = $cart ? $cart->toArray() : [];
            $decoded = is_string($cart['cart_info'] ?? null)
                ? json_decode((string)$cart['cart_info'], true)
                : ($cart['cart_info'] ?? []);
            $productName = (string)($decoded['productInfo']['store_name'] ?? '');
            $holder = UserCardHolder::where('oid', (int)$wo['oid'])->where('uid', (int)$wo['uid'])->find();
            $detail[] = [
                'writeoff_id' => (int)$wo['id'],
                'holder_id' => $holder ? (int)$holder['id'] : 0,
                'oid' => (int)$wo['oid'],
                'card_name' => $holder ? (string)$holder['card_name'] : '',
                'cart_info_id' => (int)$wo['order_cart_id'],
                'product_id' => (int)$wo['product_id'],
                'product_name' => $productName,
                'cart_num' => (int)$wo['writeoff_num'],
                'writeoff_amount' => WriteoffIntegerAmount::truncate($wo['writeoff_price'] ?? 0),
            ];
        }
        return [
            'batch_id' => (int)$batch['id'],
            'batch_no' => (string)$batch['batch_no'],
            'uid' => (int)$batch['uid'],
            'store_id' => (int)$batch['store_id'],
            'staff_id' => (int)$batch['staff_id'],
            'total_amount' => WriteoffIntegerAmount::truncate($batch['total_amount'] ?? 0),
            'total_times' => (int)$batch['total_times'],
            'total_cards' => (int)$batch['total_cards'],
            'total_projects' => (int)$batch['total_projects'],
            'business_time' => (int)$batch['business_time'],
            'operate_time' => (int)$batch['operate_time'],
            'performance_mode' => (string)($items[0]['performance_mode'] ?? app()->make(WriteoffPerformanceModeServices::class)->getMode()),
            'items' => $detail,
            'idempotent' => $idempotent,
            'status' => (int)$batch['status'],
        ];
    }
}
