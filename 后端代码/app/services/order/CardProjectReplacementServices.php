<?php

namespace app\services\order;

use app\model\order\CardProjectReplacement;
use app\model\order\StoreOrderCartInfo;
use app\model\order\StoreOrderWriteoff;
use app\model\product\product\StoreProduct;
use app\model\user\UserCardHolder;
use app\services\BaseServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 同卡项目替换：不产生核销订单、不进消费统计。
 * 来源支持同项目多条、每条独立次数；目标为当前门店已上架可用项目。
 */
class CardProjectReplacementServices extends BaseServices
{
    public function options(int $holderId, int $storeId): array
    {
        $holder = $this->requireHolder($holderId);
        $sources = $this->listSourceProjects((int)$holder['oid']);
        $targets = $this->listTargetProjects($storeId);
        return [
            'holder' => [
                'holder_id' => (int)$holder['id'],
                'oid' => (int)$holder['oid'],
                'uid' => (int)$holder['uid'],
                'card_name' => (string)$holder['card_name'],
                'verify_code' => (string)$holder['verify_code'],
                'write_surplus_times' => (int)$holder['write_surplus_times'],
            ],
            'sources' => $sources,
            'targets' => $targets,
            'target_empty_tip' => $targets
                ? ''
                : '当前没有可用的上架项目，请先上架项目后再进行替换',
        ];
    }

    public function preview(int $holderId, array $payload, int $storeId): array
    {
        $plan = $this->buildPlan($holderId, $payload, $storeId, false);
        return [
            'sources' => $plan['sources'],
            'target' => $plan['target'],
            'target_amount' => (int)$plan['target_amount'],
            'total_source_times' => (int)$plan['total_source_times'],
            'note' => $plan['note'],
        ];
    }

    public function commit(int $holderId, array $payload, int $storeId, int $staffId): array
    {
        $idem = trim((string)($payload['idempotency_key'] ?? ''));
        if ($idem === '') {
            throw new ValidateException('缺少幂等键，请刷新后重试');
        }
        $exist = CardProjectReplacement::where('idempotency_key', $idem)->find();
        if ($exist) {
            return $this->formatRecord($exist->toArray());
        }

        return $this->transaction(function () use ($holderId, $payload, $storeId, $staffId, $idem) {
            $exist = CardProjectReplacement::where('idempotency_key', $idem)->lock(true)->find();
            if ($exist) {
                return $this->formatRecord($exist->toArray());
            }
            $plan = $this->buildPlan($holderId, $payload, $storeId, true);
            $holder = $plan['holder'];
            $sources = $plan['sources'];
            $target = $plan['target'];
            $amount = (int)$plan['target_amount'];
            $totalSourceTimes = (int)$plan['total_source_times'];
            $now = time();

            // 按 cart_info_id 合计扣减（同项目多行合并校验与扣减）
            $needByCart = [];
            foreach ($sources as $src) {
                $cid = (int)$src['cart_info_id'];
                $needByCart[$cid] = ($needByCart[$cid] ?? 0) + (int)$src['times'];
            }
            $deductMeta = [];
            foreach ($needByCart as $cid => $needTimes) {
                $cart = StoreOrderCartInfo::where('id', $cid)->lock(true)->find();
                if (!$cart) {
                    throw new ValidateException('原项目不存在或不属于该卡');
                }
                $cart = $cart->toArray();
                $before = (int)$cart['write_surplus_times'];
                if ($before < $needTimes) {
                    throw new ValidateException('【' . $this->nameOf($cart) . '】剩余次数不足');
                }
                $after = $before - $needTimes;
                StoreOrderCartInfo::where('id', $cid)->update([
                    'write_surplus_times' => $after,
                    'is_writeoff' => $after > 0 ? 0 : 1,
                ]);
                $deductMeta[$cid] = ['before' => $before, 'after' => $after, 'need' => $needTimes];
            }

            // 将 before/after 回填到来源行（按行顺序滚动扣减展示）
            $cursor = [];
            foreach ($sources as $idx => $src) {
                $cid = (int)$src['cart_info_id'];
                $meta = $deductMeta[$cid];
                $used = $cursor[$cid] ?? 0;
                $lineBefore = $meta['before'] - $used;
                $lineAfter = $lineBefore - (int)$src['times'];
                $sources[$idx]['before_surplus'] = $lineBefore;
                $sources[$idx]['after_surplus'] = $lineAfter;
                $cursor[$cid] = $used + (int)$src['times'];
            }

            // 新建目标权益行（挂在同一张卡订单下）
            $firstSource = StoreOrderCartInfo::where('id', (int)$sources[0]['cart_info_id'])->find();
            $firstSource = $firstSource ? $firstSource->toArray() : [];
            $targetProduct = StoreProduct::where('id', (int)$target['product_id'])->find();
            $targetProduct = $targetProduct ? $targetProduct->toArray() : [];
            $targetSku = trim((string)($target['sku'] ?? ''));
            if ($targetSku === '') {
                $targetSku = $this->resolveDefaultProductSku((int)$target['product_id']);
            }
            $cartInfoJson = json_encode([
                'id' => 0,
                'product_id' => (int)$target['product_id'],
                'product_type' => 6,
                'product_attr_unique' => $targetSku,
                'cart_num' => 1,
                'productInfo' => [
                    'id' => (int)$target['product_id'],
                    'store_name' => (string)($targetProduct['store_name'] ?? $target['name']),
                    'product_type' => 6,
                    'attrInfo' => [
                        'suk' => $targetSku,
                        'unique' => $targetSku,
                    ],
                ],
                'attrInfo' => [
                    'suk' => $targetSku,
                    'unique' => $targetSku,
                ],
                'truePrice' => $amount,
                'pay_price' => $amount,
            ], JSON_UNESCAPED_UNICODE);

            $newCartId = 'rpl' . $now . mt_rand(100, 999);
            $targetCartPk = (int)Db::name('store_order_cart_info')->insertGetId([
                'uid' => (int)$holder['uid'],
                'oid' => (int)$holder['oid'],
                'cart_id' => $newCartId,
                'cart_type' => 2,
                'type' => (int)($firstSource['type'] ?? 1),
                'relation_id' => (int)($firstSource['relation_id'] ?? $storeId),
                'product_id' => (int)$target['product_id'],
                'product_type' => 6,
                'sku_unique' => $targetSku,
                'cart_num' => 1,
                'pay_price' => $amount,
                'total_price' => $amount,
                'write_times' => 1,
                'write_surplus_times' => 1,
                'write_start' => (int)($holder['write_start'] ?? 0),
                'write_end' => (int)($holder['write_end'] ?? 0),
                'is_writeoff' => 0,
                'is_card' => 1,
                'source_type' => 'replacement',
                'replacement_id' => 0,
                'cart_info' => $cartInfoJson,
                'unique' => md5($newCartId . $now),
                'add_time' => $now,
            ]);

            $replacementNo = 'PR' . date('YmdHis') . mt_rand(100, 999);
            $snapshot = [
                'sources' => $sources,
                'target' => array_merge($target, [
                    'cart_info_id' => $targetCartPk,
                    'cart_id' => $newCartId,
                    'amount' => $amount,
                ]),
                'total_source_times' => $totalSourceTimes,
                'remark' => (string)($payload['remark'] ?? ''),
            ];
            $replacementId = (int)Db::name('card_project_replacement')->insertGetId([
                'replacement_no' => $replacementNo,
                'uid' => (int)$holder['uid'],
                'store_id' => $storeId,
                'staff_id' => $staffId,
                'holder_id' => (int)$holder['id'],
                'oid' => (int)$holder['oid'],
                'target_product_id' => (int)$target['product_id'],
                'target_cart_id' => $targetCartPk,
                'target_amount' => $amount,
                'business_time' => $now,
                'operate_time' => $now,
                'idempotency_key' => $idem,
                'status' => 0,
                'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'add_time' => $now,
            ]);
            StoreOrderCartInfo::where('id', $targetCartPk)->update(['replacement_id' => $replacementId]);

            // 明细行允许同 cart 重复；落库按 cart 汇总，避免 uk_replacement_source 冲突；完整多行仍在 snapshot_json
            $aggSources = [];
            foreach ($sources as $src) {
                $cid = (int)$src['cart_info_id'];
                if (!isset($aggSources[$cid])) {
                    $aggSources[$cid] = [
                        'cart_info_id' => $cid,
                        'product_id' => (int)$src['product_id'],
                        'times' => 0,
                        'amount' => 0,
                        'before_surplus' => (int)$src['before_surplus'],
                        'after_surplus' => (int)$src['after_surplus'],
                        'lines' => [],
                    ];
                }
                $aggSources[$cid]['times'] += (int)$src['times'];
                $aggSources[$cid]['amount'] += (int)$src['amount'];
                $aggSources[$cid]['after_surplus'] = (int)$src['after_surplus'];
                $aggSources[$cid]['lines'][] = $src;
            }
            foreach ($aggSources as $agg) {
                Db::name('card_project_replacement_source')->insert([
                    'replacement_id' => $replacementId,
                    'source_cart_id' => (int)$agg['cart_info_id'],
                    'source_product_id' => (int)$agg['product_id'],
                    'deduct_times' => (int)$agg['times'],
                    'amount' => (int)$agg['amount'],
                    'before_surplus' => (int)$agg['before_surplus'],
                    'after_surplus' => (int)$agg['after_surplus'],
                    'source_snapshot' => json_encode($agg, JSON_UNESCAPED_UNICODE),
                ]);
            }

            // 卡 holder 可用次数：来源合计扣减 - 新增目标 1 次
            $holderDelta = $totalSourceTimes - 1;
            $holderSurplus = max((int)$holder['write_surplus_times'] - $holderDelta, 0);
            UserCardHolder::where('id', (int)$holder['id'])->update(['write_surplus_times' => $holderSurplus]);

            $row = CardProjectReplacement::where('id', $replacementId)->find();
            return $this->formatRecord($row ? $row->toArray() : ['id' => $replacementId, 'replacement_no' => $replacementNo]);
        });
    }

    public function records(int $holderId): array
    {
        $list = CardProjectReplacement::where('holder_id', $holderId)
            ->where('status', 0)
            ->order('id', 'desc')
            ->limit(50)
            ->select()
            ->toArray();
        return array_map([$this, 'formatRecord'], $list);
    }

    protected function buildPlan(int $holderId, array $payload, int $storeId, bool $forUpdate): array
    {
        $holder = $this->requireHolder($holderId);
        $sourceLines = $this->parseSourceLines($payload);
        $targetProductId = (int)($payload['target_product_id'] ?? 0);
        if ($targetProductId <= 0) {
            throw new ValidateException('请选择新项目');
        }

        $sources = [];
        $needByCart = [];
        $alreadyOffset = [];
        foreach ($sourceLines as $line) {
            $cid = (int)$line['cart_info_id'];
            $times = (int)$line['times'];
            $any = StoreOrderCartInfo::where('id', $cid)->find();
            if ($any && (int)$any['oid'] !== (int)$holder['oid']) {
                throw new ValidateException('不支持跨卡项目替换，请选择同一张卡内的项目');
            }
            $q = StoreOrderCartInfo::where('id', $cid)->where('oid', (int)$holder['oid']);
            if ($forUpdate) {
                $q->lock(true);
            }
            $cart = $q->find();
            if (!$cart) {
                throw new ValidateException('原项目不存在或不属于该卡');
            }
            $cart = $cart->toArray();
            if ((int)$cart['product_type'] !== 6) {
                throw new ValidateException('原项目必须是项目权益');
            }
            $needByCart[$cid] = ($needByCart[$cid] ?? 0) + $times;
            if ($needByCart[$cid] > (int)$cart['write_surplus_times']) {
                throw new ValidateException('【' . $this->nameOf($cart) . '】剩余次数不足');
            }

            $baseAlready = (int)StoreOrderWriteoff::where('oid', (int)$holder['oid'])
                ->where('order_cart_id', $cid)
                ->where('status', 0)
                ->sum('writeoff_num');
            $offset = $alreadyOffset[$cid] ?? 0;
            $amount = WriteoffIntegerAmount::allocate(
                $cart['pay_price'] ?? 0,
                (int)$cart['write_times'],
                $baseAlready + $offset,
                $times
            );
            $alreadyOffset[$cid] = $offset + $times;

            $sources[] = [
                'cart_info_id' => $cid,
                'cart_id' => (string)$cart['cart_id'],
                'product_id' => (int)$cart['product_id'],
                'name' => $this->nameOf($cart),
                'times' => $times,
                'amount' => $amount,
                'write_surplus_times' => (int)$cart['write_surplus_times'],
                'rights_version' => md5($cid . ':' . (int)$cart['write_surplus_times'] . ':' . WriteoffIntegerAmount::truncate($cart['pay_price'] ?? 0)),
            ];
        }

        $targetProduct = StoreProduct::where('id', $targetProductId)->where('product_type', 6)->find();
        if (!$targetProduct) {
            throw new ValidateException('新项目不存在或不可用');
        }
        $targetProduct = $targetProduct->toArray();
        $this->assertTargetAvailable($targetProduct, $storeId);

        $targetAmount = 0;
        $totalSourceTimes = 0;
        foreach ($sources as $src) {
            $targetAmount += (int)$src['amount'];
            $totalSourceTimes += (int)$src['times'];
        }
        $targetSku = $this->resolveDefaultProductSku($targetProductId);

        return [
            'holder' => $holder,
            'sources' => $sources,
            'target' => [
                'product_id' => $targetProductId,
                'name' => (string)$targetProduct['store_name'],
                'sku' => $targetSku,
            ],
            'target_amount' => $targetAmount,
            'total_source_times' => $totalSourceTimes,
            'note' => '项目替换不产生核销订单，不进入消费统计，只保留 1 条变动记录。',
        ];
    }

    /**
     * 解析来源行：保留重复 cart_info_id，不按项目 ID 去重。
     * 优先 sources=[{cart_info_id,times}]；兼容 source_cart_info_ids + source_times。
     *
     * @return array<int, array{cart_info_id:int,times:int}>
     */
    protected function parseSourceLines(array $payload): array
    {
        $lines = [];
        if (!empty($payload['sources']) && is_array($payload['sources'])) {
            foreach ($payload['sources'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cid = (int)($row['cart_info_id'] ?? 0);
                $times = (int)($row['times'] ?? 1);
                if ($cid <= 0) {
                    continue;
                }
                if ($times <= 0) {
                    throw new ValidateException('来源项目次数必须大于 0');
                }
                if ($times > 999) {
                    throw new ValidateException('来源项目次数过大');
                }
                $lines[] = ['cart_info_id' => $cid, 'times' => $times];
            }
        } else {
            $ids = $payload['source_cart_info_ids'] ?? [];
            if (!is_array($ids)) {
                throw new ValidateException('请选择替换来源项目');
            }
            $timesArr = $payload['source_times'] ?? [];
            if (!is_array($timesArr)) {
                $timesArr = [];
            }
            foreach (array_values($ids) as $i => $id) {
                $cid = (int)$id;
                if ($cid <= 0) {
                    continue;
                }
                $times = isset($timesArr[$i]) ? (int)$timesArr[$i] : 1;
                if ($times <= 0) {
                    throw new ValidateException('来源项目次数必须大于 0');
                }
                $lines[] = ['cart_info_id' => $cid, 'times' => $times];
            }
        }
        if (!$lines) {
            throw new ValidateException('请选择替换来源项目');
        }
        if (count($lines) > 20) {
            throw new ValidateException('一次替换的来源项目过多');
        }
        return $lines;
    }

    protected function assertTargetAvailable(array $targetProduct, int $storeId): void
    {
        if ((int)($targetProduct['is_del'] ?? 1) !== 0 || (int)($targetProduct['is_show'] ?? 0) !== 1) {
            throw new ValidateException('新项目已下架或不可用，请重新选择');
        }
        $type = (int)($targetProduct['type'] ?? -1);
        $relationId = (int)($targetProduct['relation_id'] ?? -1);
        $ok = ($type === 0) || ($type === 1 && $storeId > 0 && $relationId === $storeId);
        if (!$ok) {
            throw new ValidateException('新项目不属于当前门店可用范围');
        }
    }

    /**
     * 取项目默认规格 unique，供替换目标行写入，避免后续核销院装因空 SKU 直接失败。
     */
    protected function resolveDefaultProductSku(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }
        $sku = (string)(Db::name('store_product_attr_value')
            ->where('product_id', $productId)
            ->where('type', 0)
            ->order('id', 'asc')
            ->value('unique') ?: '');
        if ($sku !== '') {
            return $sku;
        }
        // 兼容门店副本：部分环境规格挂在平台商品上
        $pid = (int)(Db::name('store_product')->where('id', $productId)->value('pid') ?: 0);
        if ($pid > 0) {
            $sku = (string)(Db::name('store_product_attr_value')
                ->where('product_id', $pid)
                ->where('type', 0)
                ->order('id', 'asc')
                ->value('unique') ?: '');
        }
        return $sku;
    }

    protected function requireHolder(int $holderId): array
    {
        if ($holderId <= 0) {
            throw new ValidateException('缺少卡项');
        }
        $holder = UserCardHolder::where('id', $holderId)->where('is_del', 0)->find();
        if (!$holder) {
            throw new ValidateException('卡项不存在');
        }
        return $holder->toArray();
    }

    protected function listSourceProjects(int $oid): array
    {
        $rows = StoreOrderCartInfo::where('oid', $oid)
            ->where('cart_type', 2)
            ->where('product_type', 6)
            ->where('write_surplus_times', '>', 0)
            ->select()
            ->toArray();
        $list = [];
        foreach ($rows as $cart) {
            $already = (int)StoreOrderWriteoff::where('oid', $oid)
                ->where('order_cart_id', (int)$cart['id'])
                ->where('status', 0)
                ->sum('writeoff_num');
            $list[] = [
                'cart_info_id' => (int)$cart['id'],
                'cart_id' => (string)$cart['cart_id'],
                'product_id' => (int)$cart['product_id'],
                'name' => $this->nameOf($cart),
                'write_surplus_times' => (int)$cart['write_surplus_times'],
                'preview_amount' => WriteoffIntegerAmount::allocate(
                    $cart['pay_price'] ?? 0,
                    (int)$cart['write_times'],
                    $already,
                    1
                ),
            ];
        }
        return $list;
    }

    protected function listTargetProjects(int $storeId): array
    {
        $q = StoreProduct::where('product_type', 6)->where('is_del', 0)->where('is_show', 1);
        if ($storeId > 0) {
            $q->where(function ($query) use ($storeId) {
                $query->where('type', 0)
                    ->whereOr(function ($q2) use ($storeId) {
                        $q2->where('type', 1)->where('relation_id', $storeId);
                    });
            });
        }
        $rows = $q->field('id,store_name,cate_id,type,relation_id')->order('id', 'desc')->limit(200)->select()->toArray();
        return array_map(static function ($p) {
            return [
                'product_id' => (int)$p['id'],
                'name' => (string)$p['store_name'],
                'category' => '',
            ];
        }, $rows);
    }

    protected function formatRecord(array $row): array
    {
        $snapshot = [];
        if (!empty($row['snapshot_json'])) {
            $snapshot = json_decode((string)$row['snapshot_json'], true) ?: [];
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'replacement_no' => (string)($row['replacement_no'] ?? ''),
            'holder_id' => (int)($row['holder_id'] ?? 0),
            'oid' => (int)($row['oid'] ?? 0),
            'target_amount' => (int)($row['target_amount'] ?? 0),
            'business_time' => (int)($row['business_time'] ?? 0),
            'operate_time' => (int)($row['operate_time'] ?? 0),
            'snapshot' => $snapshot,
            'status' => (int)($row['status'] ?? 0),
        ];
    }

    protected function nameOf(array $cart): string
    {
        $info = is_string($cart['cart_info'] ?? null) ? json_decode($cart['cart_info'], true) : ($cart['cart_info'] ?? []);
        if (!is_array($info)) {
            $info = [];
        }
        return (string)($info['productInfo']['store_name'] ?? ('项目' . ($cart['product_id'] ?? '')));
    }
}
