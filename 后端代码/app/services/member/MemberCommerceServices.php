<?php
declare(strict_types=1);

namespace app\services\member;

use app\services\cashier\v3\bootstrap\CashierV3Bootstrap;
use app\services\cashier\v3\cashier\CashierV3SaleCatalogServices;
use app\services\cashier\v3\event\CashierV3BusinessEventRecorder;
use app\services\cashier\v3\manifest\CashierV3ActionManifest;
use app\services\cashier\v3\member\CashierV3QueryEntitySelectorServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * Member-owned transaction boundary. Prices and rights come from the cashier
 * catalog; clients submit identities only. Pending online attempts are not
 * formal sales, and a successful attempt invokes the existing V3 settlement.
 */
final class MemberCommerceServices
{
    /** Inspect owned cart identities before routing an old cart entry. Activity
     * carts must not silently become ordinary, differently priced purchases. */
    public function cartEntry(int $uid, string $cartIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $cartIds)))));
        if (!$ids || count($ids) > 100) throw new ValidateException('请选择购买商品。');
        $rows = Db::name('store_cart')->whereIn('id',$ids)->where('uid',$uid)->where('is_pay',0)->where('is_del',0)->select()->toArray();
        if (count($rows) !== count($ids)) throw new ValidateException('购物车已变化，请重新选择。');
        $ordinary = true;
        foreach ($rows as $row) if ((int)$row['type'] !== 0 || (int)$row['cart_type'] !== 0 || (int)$row['activity_id'] !== 0) $ordinary = false;
        return ['ordinary'=>$ordinary];
    }

    public function quote(int $uid, array $input): array
    {
        return Db::transaction(function () use ($uid, $input): array {
            $member = $this->member($uid);
            $stores = Db::name('system_store')->where('is_show', 1)->where('is_del', 0)
                ->field('id,name')->order('id asc')->select()->toArray();
            $storeId = (int)($input['store_id'] ?? 0);
            if ($storeId <= 0) $storeId = (int)$member['belong_store_id'];
            $valid = array_column($stores, 'name', 'id');
            if (!isset($valid[$storeId])) $storeId = 0;
            $result = ['stores' => $stores, 'storeId' => $storeId,
                'balanceCents' => max(0, self::cents((string)$member['now_money']) - self::heldBalanceCents($uid)),
                'items' => [], 'salespeople' => [], 'amountCents' => 0, 'canSubmit' => false];
            if ($storeId === 0) return $result;
            $scope = MemberCommerceScope::create($uid, $storeId);
            $built = $this->buildInTx($uid, $input, $scope, false);
            $result['items'] = $built['items'];
            $result['amountCents'] = $built['amountCents'];
            $result = array_merge($result, (array)$built['benefitQuote']);
            $result['salespeople'] = array_map(static function (array $row): array {
                return ['id' => (int)$row['staffId'], 'name' => (string)$row['name']];
            }, $this->people($scope, 0));
            $result['fingerprint'] = $built['fingerprint'];
            $result['canSubmit'] = true;
            return $result;
        });
    }

    /** A retry returns its original frozen result, never a newly priced order. */
    public function create(int $uid, array $input, string $channel): array
    {
        $key = trim((string)($input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) throw new ValidateException('交易标识无效，请重新进入支付页面。');
        if (!in_array($channel, ['h5','wechat','routine','app'], true)) throw new ValidateException('下单渠道无效。');
        // Fixed ordering also makes a retry with different business intent fail.
        $intent = ['store' => (int)($input['store_id'] ?? 0), 'kind' => (string)($input['kind'] ?? 'purchase'),
            'items' => $input['items'] ?? [], 'cart_ids' => (string)($input['cart_ids'] ?? ''),
            'salespeople' => array_values((array)($input['salespeople'] ?? [])),
            'coupon_id' => (int)($input['coupon_id'] ?? 0),
            'use_integral' => !empty($input['use_integral']) ? 1 : 0,
            'payment_mode' => (string)($input['payment_mode'] ?? ''),
            'external_method' => (string)($input['external_method'] ?? 'wechat'),
            'balance_yuan' => (string)($input['balance_yuan'] ?? '0'),
            'price' => (string)($input['price'] ?? '0'), 'package_id' => (int)($input['package_id'] ?? 0)];
        $intentHash = hash('sha256', json_encode($intent));
        return Db::transaction(function () use ($uid, $input, $channel, $key, $intent, $intentHash): array {
            $member = $this->member($uid, true);
            $existing = Db::name('member_commerce_order')->where('member_id', $uid)
                ->where('idempotency_key', $key)->lock(true)->find();
            if ($existing) {
                if (!hash_equals($existing['input_fingerprint'], $intentHash)) throw new ValidateException('同一交易标识不能修改订单内容。');
                return $this->publicOrder($existing);
            }
            $scope = MemberCommerceScope::create($uid, $intent['store']);
            $built = $this->buildInTx($uid, $input, $scope, true);
            if ($intent['cart_ids'] !== '') {
                $wanted = array_filter(array_map('intval',explode(',',$intent['cart_ids'])));
                // The same user row serializes intent creation and callbacks.
                // Do not charge two pending payment intents for the same cart.
                $pending = Db::name('member_commerce_order')->where('member_id',$uid)
                    ->whereIn('status',['pending','payment_requested','payment_unknown'])->lock(true)->column('snapshot_json');
                foreach ($pending as $json) {
                    $prior = json_decode($json,true);
                    if (array_intersect($wanted,array_map('intval',explode(',',(string)($prior['cartIds'] ?? ''))))) {
                        throw new ValidateException('这些商品已有待付款订单，请先处理原订单。');
                    }
                }
            }
            if (!hash_equals($built['fingerprint'], (string)($input['fingerprint'] ?? ''))) {
                throw new ValidateException('价格或门店信息已变化，请刷新确认后支付。');
            }
            $amount = $built['amountCents'];
            $mode = $intent['payment_mode'];
            if ($built['kind'] === 'recharge' && $mode !== 'fuyou') throw new ValidateException('充值只能使用富友支付。');
            if (!in_array($mode, ['balance','fuyou','combined'], true)) throw new ValidateException('请选择支付方式。');
            $balance = $mode === 'balance' ? $amount : ($mode === 'combined' ? self::yuan($intent['balance_yuan']) : 0);
            if ($balance < 0 || $balance > $amount || ($mode === 'combined' && ($balance === 0 || $balance === $amount))) {
                throw new ValidateException('组合支付金额无效。');
            }
            $held = self::heldBalanceCents($uid);
            if ($balance > self::cents((string)$member['now_money']) - $held) throw new ValidateException('可用余额不足。');
            $source = (new MemberCommerceSettingsServices())->source($built['kind']);
            $publicId = 'MC-' . bin2hex(random_bytes(12));
            $snapshot = ['actorType' => 'member', 'memberId' => $uid, 'storeId' => $intent['store'],
                'channel' => $channel, 'lines' => $built['lines'], 'items' => $built['items'],
                'storeName' => (string)$scope['store']['name'], 'cartIds' => (string)($input['cart_ids'] ?? ''),
                'businessSource' => $source, 'bonusCents' => $built['bonusCents'],
                'grossAmountCents' => $built['grossAmountCents'],
                'benefits' => $built['benefitSelection'],
                'packageId' => (int)($input['package_id'] ?? 0), 'businessDate' => date('Y-m-d'),
                'giftConfig' => $built['giftConfig'] ?? [],
                'paymentMethod' => ($input['external_method'] ?? '') === 'alipay' ? 'alipay' : 'wechat'];
            $now = time();
            $id = (int)Db::name('member_commerce_order')->insertGetId([
                'public_id' => $publicId, 'member_id' => $uid, 'store_id' => $intent['store'],
                'kind' => $built['kind'], 'channel' => $channel, 'idempotency_key' => $key,
                'input_fingerprint' => $intentHash, 'snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'amount_cents' => $amount, 'balance_cents' => $balance, 'external_cents' => $amount - $balance,
                'status' => $amount === $balance ? 'settling' : 'pending', 'result_json' => '{}',
                'created_at' => $now, 'updated_at' => $now]);
            $row = Db::name('member_commerce_order')->where('id', $id)->find();
            (new MemberCommerceBenefitsServices())->reserveInTx($id, $uid, (array)$built['benefitSelection']);
            if ($amount === $balance) $row = $this->settleInTx($row, '');
            return $this->publicOrder($row);
        });
    }

    public function detail(int $uid, string $id): array
    {
        $row = Db::name('member_commerce_order')->where('public_id', $id)->where('member_id', $uid)->find();
        if (!$row) throw new ValidateException('订单不存在。');
        return $this->publicOrder($row);
    }

    /** Release an unsubmitted intent only. Unknown provider attempts must be
     * reconciled/closed by the provider, never guessed cancelled by the UI. */
    public function cancel(int $uid, string $id): array
    {
        return Db::transaction(function () use ($uid, $id): array {
            $this->member($uid, true);
            $row = Db::name('member_commerce_order')->where('member_id', $uid)->where('public_id', $id)->lock(true)->find();
            if (!$row) throw new ValidateException('订单不存在。');
            if ($row['status'] === 'cancelled') return $this->publicOrder($row);
            if ($row['status'] !== 'pending' || Db::name('member_commerce_payment_session')->where('commerce_id', $row['id'])->lock(true)->find()) {
                throw new ValidateException('支付请求已发出，请先核对支付结果，不能直接取消。');
            }
            Db::name('member_commerce_order')->where('id', $row['id'])->update(['status'=>'cancelled','updated_at'=>time()]);
            (new MemberCommerceBenefitsServices())->releaseInTx((int)$row['id']);
            $row['status'] = 'cancelled';
            return $this->publicOrder($row);
        });
    }

    public function listing(int $uid, array $input = []): array
    {
        $page = max(1, min(100000, (int)($input['page'] ?? 1)));
        $query = Db::name('member_commerce_order')->where('member_id', $uid)->where('kind', 'purchase');
        $status = (string)($input['status'] ?? '');
        if ($status === '0') $query->whereIn('status', ['pending','payment_requested','payment_unknown']);
        elseif ($status === '10') $query->where('status', 'paid');
        elseif ($status !== '') $query->where('id', -1);
        $search = trim((string)($input['keyword'] ?? ''));
        if ($search !== '') $query->where(function ($q) use ($search) {
            $like = '%' . addcslashes(mb_substr($search,0,100), '%_') . '%';
            $q->whereLike('document_no', $like)->whereOr('snapshot_json', 'like', $like);
        });
        $total = (int)(clone $query)->count();
        $page = min($page, max(1, (int)ceil($total / 20)));
        $rows = $query->order('id desc')->page($page, 20)->select()->toArray();
        return ['list' => array_map([$this, 'publicOrder'], $rows), 'total' => $total, 'page' => $page, 'limit' => 20];
    }

    /** Existing balance writers call this while holding the same user row. */
    public static function heldBalanceCents(int $uid): int
    {
        // A new optional migration must not break existing customers that have
        // no member-commerce entry yet. Other database errors remain failures.
        static $ready;
        if ($ready === null) $ready = (bool)Db::query("SHOW TABLES LIKE 'eb_member_commerce_order'");
        if (!$ready) return 0;
        $rows = Db::name('member_commerce_order')->where('member_id', $uid)
            ->whereIn('status', ['pending','payment_requested','payment_unknown'])->field('balance_cents')->select()->toArray();
        return array_sum(array_map('intval', array_column($rows, 'balance_cents')));
    }

    /** Confirmed provider success is the only online entry to final settlement. */
    public function paid(string $id, int $externalCents, string $transactionId, string $channel = ''): bool
    {
        return Db::transaction(function () use ($id, $externalCents, $transactionId, $channel): bool {
            $row = Db::name('member_commerce_order')->where('public_id', $id)->find();
            if (!$row) throw new ValidateException('支付订单不存在。');
            $this->member((int)$row['member_id'], true);
            $row = Db::name('member_commerce_order')->where('id', $row['id'])->lock(true)->find();
            if ($externalCents !== (int)$row['external_cents'] || $transactionId === '') throw new ValidateException('支付金额不匹配。');
            $snapshot = json_decode($row['snapshot_json'], true);
            if ($channel !== '' && $snapshot['paymentMethod'] !== $channel) throw new ValidateException('支付渠道不匹配。');
            if ($row['status'] === 'paid') {
                if (!hash_equals($row['transaction_id'], $transactionId)) throw new ValidateException('订单已由其他支付交易完成。');
                return true;
            }
            if (!in_array($row['status'], ['pending','payment_requested','payment_unknown'], true)) throw new ValidateException('订单支付状态无效。');
            Db::name('member_commerce_order')->where('id', $row['id'])->update(['status' => 'settling']);
            $this->settleInTx($row, $transactionId);
            return true;
        });
    }

    private function settleInTx(array $row, string $transactionId): array
    {
        $snapshot = json_decode($row['snapshot_json'], true);
        $scope = MemberCommerceScope::create((int)$row['member_id'], (int)$row['store_id']);
        $key = self::checkoutKey((string)$row['public_id']);
        if ($row['kind'] === 'recharge') {
            $result = (new MemberCommerceRechargeSettlement())->settleInTx($row, $snapshot, $scope, $transactionId);
        } else {
            $lines = $snapshot['lines'];
            if ((int)$row['amount_cents'] === 0) $lines[] = ['lineRole' => 'payment', 'paymentMethod' => 'wechat', 'amount' => '0'];
            if ((int)$row['balance_cents'] > 0) $lines[] = ['lineRole' => 'balance_payment', 'amount' => self::money((int)$row['balance_cents'])];
            if ((int)$row['external_cents'] > 0) $lines[] = ['lineRole' => 'payment',
                'paymentMethod' => $snapshot['paymentMethod'], 'amount' => self::money((int)$row['external_cents']),
                'externalTransactionNo' => $transactionId];
            $stateId = 'MSC-' . substr(hash('sha256', $row['public_id']), 0, 32);
            $recorder = new CashierV3BusinessEventRecorder();
            $execution = $recorder->newExecution('submit-checkout', $key, $scope['operator'], $scope['data'], $stateId);
            $handler = CashierV3Bootstrap::dispatcher()->handlers()->requireCommand('submit-checkout');
            $response = $handler(['action' => 'submit-checkout', 'idempotency_key' => $key,
                'state_context_id' => $stateId, 'operator_scope' => $scope['operator'], 'data_scope' => $scope['data'],
                'contexts' => [], 'locked_versions' => [], 'direct_snapshot_submission' => true,
                // Direct snapshots lock catalog, balance and issuance rows in
                // their authorities; no browser workspace/version is fabricated.
                'server_resource_discovery' => ['contractVersion' => 'cashier-v3-server-resource-discovery-v1',
                    'resources' => [], 'fingerprint' => hash('sha256', '[]')],
                'event_recorder' => $recorder, 'event_execution' => $execution,
                'event_contract' => CashierV3ActionManifest::eventContractFor('submit-checkout'),
                'payload' => ['checkoutSnapshot' => ['memberId' => (int)$row['member_id'],
                    'businessDate' => $snapshot['businessDate'], 'occurredAt' => time(),
                    'source' => $snapshot['businessSource'], 'lines' => $lines,
                    'orderNote' => '会员自助订单 ' . $row['public_id']]]]);
            $submitted = $response['data']['checkoutSubmission'];
            $result = ['orderId' => $submitted['salesOrder']['orderId'],
                'orderNo' => $submitted['salesOrder']['orderNo'], 'submission' => $submitted];
            // Coupon consumption is performed by the shared V3 settlement.
            // Integral consumption follows in this same transaction and is
            // linked to the resulting sales order for full-void restoration.
            (new MemberCommerceBenefitsServices())->consumeInTx((int)$row['id'],
                (int)$row['member_id'], (string)$result['orderId']);
        }
        // A cart is consumed only after the shared settlement succeeds, in the
        // same transaction; transport retries cannot create a second purchase.
        if (!empty($snapshot['cartIds'])) {
            $cartIds = array_values(array_filter(array_map('intval', explode(',', $snapshot['cartIds']))));
            Db::name('store_cart')->whereIn('id', $cartIds)->where('uid', (int)$row['member_id'])
                ->where('is_pay', 0)->update(['is_pay' => 1]);
        }
        Db::name('member_commerce_order')->where('id', $row['id'])->update([
            'status' => 'paid', 'sales_order_id' => (string)$result['orderId'],
            'document_no' => (string)$result['orderNo'], 'transaction_id' => $transactionId,
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE), 'updated_at' => time()]);
        return Db::name('member_commerce_order')->where('id', $row['id'])->find();
    }

    /** Shared catalog resolution ignores all browser prices and SKU choices. */
    private function buildInTx(int $uid, array $input, array $scope, bool $lockBenefits): array
    {
        $kind = (string)($input['kind'] ?? 'purchase');
        if ($kind === 'recharge') {
            $amount = self::yuan((string)($input['price'] ?? '0'));
            $terms = (new \app\services\cashier\v3\member\CashierV3RechargeModule())->termsForMemberQuote(
                (int)($input['package_id'] ?? 0), $amount, $scope['member']);
            $amount = (int)$terms['principalCents'];
            $bonus = (int)$terms['bonusCents'];
            if ($amount <= 0 || $amount % 100 || $bonus % 100) throw new ValidateException('充值金额必须为正整数元。');
            $giftConfig = [];
            if ((int)($input['package_id'] ?? 0) > 0) {
                $giftService = app()->make(\app\services\other\StoreGiftConfigServices::class);
                $giftConfig = $giftService->getConfig($giftService::GIFT_TYPE_RECHARGE, (int)$input['package_id']);
            }
            $lines = [];
            $items = [['name' => '余额充值', 'amountCents' => $amount, 'quantity' => 1, 'image' => '']];
        } elseif ($kind === 'purchase') {
            $requested = (array)($input['items'] ?? []);
            if (!empty($input['cart_ids'])) {
                if (!$this->cartEntry($uid,(string)$input['cart_ids'])['ordinary']) throw new ValidateException('活动商品请使用原活动结算入口。');
                $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$input['cart_ids'])))));
                if (count($ids) > 100) throw new ValidateException('购买品项过多。');
                $carts = Db::name('store_cart')->whereIn('id', $ids ?: [-1])->where('uid', $uid)
                    ->where('is_pay', 0)->where('is_del', 0)->lock(true)->select()->toArray();
                if (count($carts) !== count($ids)) throw new ValidateException('购物车已变化，请重新选择。');
                $requested = array_map(static function (array $cart): array {
                    return ['product_id' => (int)$cart['product_id'], 'quantity' => (int)$cart['cart_num']];
                }, $carts);
            }
            if (!$requested || count($requested) > 100) throw new ValidateException('请选择购买商品。');
            $lines = $items = $benefitLines = [];
            $amount = $bonus = 0;
            $catalog = new CashierV3SaleCatalogServices();
            foreach ($requested as $index => $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $quantity = filter_var($item['quantity'] ?? 1, FILTER_VALIDATE_INT);
                if (!$quantity || $quantity < 1 || $quantity > 1000) throw new ValidateException('购买数量无效。');
                $original = Db::name('store_product')->where('id', $productId)->find();
                if (!$original) throw new ValidateException('商品不存在。');
                $masterId = (int)($original['pid'] ?: $original['id']);
                $product = Db::name('store_product')->where('type', 1)->where('relation_id', (int)$scope['store']['id'])
                    ->where(function ($q) use ($productId, $masterId) {
                        $q->where('id', $productId)->whereOr('pid', $masterId);
                    })->where('is_show', 1)->where('is_del', 0)->where('is_verify', 1)->lock(true)->find();
                if (!$product || !in_array((int)$product['product_type'], [0,4,5,6], true)) throw new ValidateException('所选门店不销售该商品。');
                $skus = Db::name('store_product_attr_value')->where('product_id', $product['id'])
                    ->where('type', 0)->where('is_show', 1)->lock(true)->select()->toArray();
                if (count($skus) !== 1 || (int)$product['spec_type'] !== 0) throw new ValidateException('商品单规格资料异常，请联系门店。');
                $server = $catalog->memberSaleLineInTx((int)$skus[0]['id'],
                    'member-quote-' . $index, $scope['operator'], $scope['data']);
                $unit = (int)$server['unit_price_cents'];
                $originalUnit = max($unit, (int)$server['original_unit_price_cents']);
                if ($unit % 100 !== 0 || $originalUnit % 100 !== 0) throw new ValidateException('商品售价和原价需按整元规则配置。');
                $lineAmount = $unit * $quantity;
                $people = $this->allocatePeople($scope, (int)$product['id'], (array)($input['salespeople'] ?? []));
                $lines[] = ['lineId' => 'member-' . $index, 'lineRole' => 'sale', 'itemId' => (int)$skus[0]['id'],
                    'quantity' => $quantity, 'lineAmountCents' => $lineAmount,
                    'originalLineAmountCents' => $originalUnit * $quantity,
                    'salespeople' => $people, 'craftsmen' => [],
                    'serviceObject' => (int)$product['product_type'] === 6 ? '本人' : '',
                    'cardPurchaseSnapshot' => $server['authority_snapshot']['cardPurchase'] ?? [],
                    'inventoryOutboundRequired' => (int)$product['product_type'] === 0 ? 1 : 0];
                $items[] = ['productId' => (int)$product['id'], 'name' => $product['store_name'],
                    'image' => $product['image'], 'quantity' => $quantity, 'amountCents' => $lineAmount];
                $categoryIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
                    explode(',', (string)($product['cate_id'] ?? '')),
                    explode(',', (string)($product['store_cate_id'] ?? ''))
                )))));
                $benefitLines[] = ['index' => $index, 'productId' => (int)$product['id'],
                    'masterProductId' => $masterId, 'categoryIds' => $categoryIds,
                    'brandId' => (int)($product['brand_id'] ?? 0), 'amountCents' => $lineAmount];
                $amount += $lineAmount;
            }
        } else throw new ValidateException('交易类型无效。');
        // Match the common cashier amount envelope before a provider request,
        // so an oversized quote can never be collected then rejected on callback.
        if ($amount > 9999999999 || $bonus > 9999999999) throw new ValidateException('交易金额超出支持范围。');
        $built = compact('kind','lines','items','amount','bonus') + [
            'amountCents' => $amount, 'grossAmountCents' => $amount,
            'bonusCents' => $bonus, 'giftConfig' => $giftConfig ?? [],
            'benefitLines' => $benefitLines ?? [],
        ];
        $built = (new MemberCommerceBenefitsServices())->applyInTx($uid,
            (int)$scope['store']['id'], $built, $input, $lockBenefits);
        $built['fingerprint'] = hash('sha256', json_encode([$uid, (int)$scope['store']['id'], $kind,
            $built['lines'], $built['amountCents'], $bonus, $built['benefitSelection'], $giftConfig ?? []]));
        return $built;
    }

    private function people(array $scope, int $productId): array
    {
        $service = new CashierV3QueryEntitySelectorServices();
        $rows = [];
        for ($page = 1; $page <= 50; $page++) {
            $result = $service->query(['entityType' => 'person', 'selectorContext' => ['scope' => 'sales_performance_assignees', 'projectId' => $productId],
                'page' => $page, 'pageSize' => 20], $scope['operator'], $scope['data']);
            $rows = array_merge($rows, $result['records']);
            if (count($rows) >= (int)$result['total']) return $rows;
        }
        throw new ValidateException('门店销售人数过多，请联系管理员。');
    }

    /** Same grouping and partner defaults as personnelDefaultAllocation.js. */
    private function allocatePeople(array $scope, int $productId, array $ids): array
    {
        $ids = array_map('intval', $ids);
        if (count($ids) > 20 || count(array_unique($ids)) !== count($ids)) throw new ValidateException('销售人选择无效。');
        if (!$ids) return [];
        $byId = array_column($this->people($scope, $productId), null, 'staffId');
        $groups = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) throw new ValidateException('销售人已离职、停用或不属于所选门店。');
            $person = $byId[$id];
            $groups[$person['allocationGroupKey']][] = $person;
        }
        $weights = [];
        foreach ($groups as $group) {
            $ratio = (int)$group[0]['partnerDefaultRatio'];
            $partners = array_values(array_filter($group, static function ($p) { return $p['employeeTypeCode'] === 'partner'; }));
            $others = array_values(array_filter($group, static function ($p) { return $p['employeeTypeCode'] !== 'partner'; }));
            $pools = $ratio > 0 && $partners && $others ? [[$ratio,$partners],[100-$ratio,$others]] : [[100,$group]];
            foreach ($pools as [$total,$people]) foreach ($people as $index => $person) {
                $weights[(int)$person['staffId']] = intdiv($total, count($people)) + ($index < $total % count($people) ? 1 : 0);
            }
        }
        return array_map(static function (int $id) use ($weights): array {
            return ['staffId' => $id, 'allocationWeight' => $weights[$id], 'personnelSource' => 'store'];
        }, $ids);
    }

    private function member(int $uid, bool $lock = false): array
    {
        $query = Db::name('user')->where('uid', $uid)->where('status', 1)->where('is_del', 0)
            ->where(function ($query) { $query->whereNull('delete_time')->whereOr('delete_time', 0); });
        if ($lock) $query->lock(true);
        $member = $query->find();
        if (!$member) throw new ValidateException('请重新登录会员账号。');
        return $member;
    }

    private function publicOrder(array $row): array
    {
        $snapshot = json_decode((string)$row['snapshot_json'], true);
        return ['id' => $row['public_id'], 'status' => $row['status'], 'kind' => $row['kind'],
            'orderNo' => $row['document_no'], 'storeId' => (int)$row['store_id'],
            'storeName' => (string)($snapshot['storeName'] ?? Db::name('system_store')->where('id', $row['store_id'])->value('name')),
            'amountCents' => (int)$row['amount_cents'],
            'grossAmountCents' => (int)($snapshot['grossAmountCents'] ?? $row['amount_cents']),
            'couponDiscountCents' => (int)($snapshot['benefits']['couponDiscountCents'] ?? 0),
            'integralDiscountCents' => (int)($snapshot['benefits']['integralDiscountCents'] ?? 0),
            'integralUsed' => (int)($snapshot['benefits']['integralUsed'] ?? 0),
            'balanceCents' => (int)$row['balance_cents'],
            'externalCents' => (int)$row['external_cents'], 'items' => $snapshot['items'], 'createdAt' => (int)$row['created_at']];
    }

    public static function checkoutKey(string $id): string
    {
        $h = hash('sha256', $id);
        return 'CHECKOUT-' . substr($h,0,8) . '-' . substr($h,8,4) . '-4' . substr($h,13,3)
            . '-8' . substr($h,17,3) . '-' . substr($h,20,12);
    }

    /** Parse decimal storage exactly; new transactions require whole yuan. */
    public static function cents(string $value): int
    {
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) throw new ValidateException('金额格式无效。');
        return (int)bcmul($value, '100', 0);
    }

    public static function yuan(string $value): int
    {
        $cents = self::cents($value);
        if ($cents % 100 !== 0) throw new ValidateException('金额必须为整数元。');
        return $cents;
    }

    public static function money(int $cents): string { return bcdiv((string)$cents, '100', 2); }
}
