<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\checkout\provider\CashierV3MemberBalanceProvider;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use think\facade\Db;

/**
 * 充值结账只接受本次浏览器确认快照。最终事务重新锁定会员余额、套餐和来源，
 * 校验收款合计后调用充值领域事务；不得创建或读取充值编辑草稿。
 */
final class CashierV3RechargeCheckoutModule
{
    private const SUBMIT = 'submit-recharge-checkout';
    private const METHODS = ['unionpay','wechat','alipay','dianping_voucher','douyin_voucher','partner_collection','other_collection'];

    public static function install(CashierV3ActionDispatcher $dispatcher): void
    {
        $module = new self();
        if (!$dispatcher->policies()->has(self::SUBMIT)) {
            // 本次快照没有既有请求版本；并发与权限在最终事务内按权威数据校验。
            $dispatcher->policies()->register(new CashierV3ContextPolicy(
                self::SUBMIT, [], [], static function (): array {
                    return ['required' => [], 'identities' => [], 'required_read_roles' => [],
                        'required_touched_roles' => [], 'allows_empty_contexts' => true];
                }, [], [], [], true
            ));
        }
        if (!$dispatcher->handlers()->hasCommand(self::SUBMIT)) {
            $dispatcher->handlers()->registerCommand(self::SUBMIT, static function (array $scope) use ($module): array {
                return $module->submitInTx($scope);
            });
        }
    }

    private function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutSubmit');
        [$payload, $operator, $data] = $this->scope($scope);
        $snapshot = $payload['checkoutSnapshot'] ?? null;
        if (!is_array($snapshot) || !empty($payload['rechargeCheckoutRequestId'])) {
            throw self::invalid('recharge_checkout_snapshot_required', '充值确认资料已失效，请重新进入充值。');
        }
        return $this->submitLocalSnapshotInTx($scope, $snapshot, $operator, $data);
    }

    /**
     * 本地确认快照仅是用户输入，不是金额、套餐或余额权威事实。此事务重新
     * 锁定会员与来源、按当前套餐重算金额，再调用原充值领域事务一次性入账。
     * 除最终业务事实外，不创建 recharge_checkout_request/payment_draft。
     */
    private function submitLocalSnapshotInTx(
        array $scope,
        array $snapshot,
        CashierV3OperatorScope $operator,
        CashierV3DataScopeContext $data
    ): array {
        $payload = (array)($scope['payload'] ?? []);
        if ((string)($snapshot['businessType'] ?? '') !== 'recharge'
            || (int)($snapshot['memberId'] ?? 0) <= 0
            || (int)($snapshot['memberId'] ?? 0) !== (int)($payload['memberId'] ?? 0)) {
            throw self::invalid('recharge_checkout_snapshot_invalid', '充值确认资料已失效，请重新进入充值。');
        }
        $input = $this->normalizeTerms($snapshot);
        $member = (array)Db::name('user')->where('uid', $input['memberId'])
            ->where('status', 1)->where('is_del', 0)->lock(true)->find();
        if (!$member) throw self::invalid('recharge_checkout_member_unavailable', '该会员已失效，不能充值。');
        $input = $this->resolveTerms($input, $member);
        $balance = (new CashierV3MemberBalanceProvider())->lockSnapshotInTx($input['memberId'], $operator, $data);
        $balanceVersion = (int)($balance['accountVersion'] ?? 0);
        if ($balanceVersion <= 0) throw self::invalid('recharge_checkout_balance_unavailable', '会员余额资料无效，请重新选择会员后重试。');
        $source = (new CashierV3BusinessConfigServices())->resolveSourceSnapshot(
            (int)$input['businessSource']['primarySourceId'],
            (int)$input['businessSource']['secondarySourceId'],
            true
        );
        if ($input['creditedPrincipalCents'] === 0 && !empty($snapshot['paymentLines'])) {
            throw self::invalid('recharge_checkout_payment_total_mismatch', '全额欠款时不能再填写收款金额。');
        }
        $payments = $input['creditedPrincipalCents'] > 0
            ? $this->snapshotPayments($snapshot, 'local-recharge') : [];
        if ($this->paymentTotal($payments) !== $input['creditedPrincipalCents']) {
            throw self::invalid('recharge_checkout_payment_total_mismatch', '各收款金额合计必须等于本次实际收款。');
        }
        $finalPayload = [
            'memberId' => $input['memberId'], 'rechargeMode' => $input['mode'],
            'rechargePackageId' => $input['packageId'],
            'principalAmount' => $this->wholeMoney($input['principalCents']),
            'bonusAmount' => $this->wholeMoney($input['bonusCents']),
            'debtAmount' => $this->wholeMoney($input['debtCents']),
            'balanceVersion' => $balanceVersion,
            'businessDate' => $input['businessDate'],
            'businessDateReason' => (string)($snapshot['businessDateReason'] ?? ''),
            'salespersonAllocations' => $input['salespersonAllocations'],
            'paymentLines' => array_map(static function (array $payment): array {
                return [
                    'paymentMethod' => (string)$payment['payment_method'],
                    'amount' => (string)intdiv((int)$payment['amount_cents'], 100),
                    'collectionReference' => (string)$payment['collection_reference'],
                ];
            }, $payments),
        ];
        $result = (new CashierV3RechargeModule())->submitInTx(array_merge($scope, [
            'payload' => $finalPayload, 'business_source' => $source,
        ]));
        $recharge = (array)($result['data']['recharge'] ?? []);
        return [
            'data' => ['rechargeCheckout' => [
                'businessType' => 'recharge', 'status' => 'succeeded', 'activeStep' => 4,
                'localSnapshotPreview' => true, 'member' => ['id' => $input['memberId']],
                'result' => ['recharge' => $recharge], 'canClose' => true,
            ]],
            'business_no' => (string)($recharge['rechargeOrderNo'] ?? ''),
            'touched' => [], 'message' => '会员充值成功。',
        ];
    }

    private function scope(array $scope): array { $p=is_array($scope['payload']??null)?$scope['payload']:[];$o=$scope['operator_scope']??null;$d=$scope['data_scope']??null;if(!$o instanceof CashierV3OperatorScope||!$d instanceof CashierV3DataScopeContext) throw self::invalid('recharge_checkout_scope_missing','充值结账服务尚未就绪，请刷新后重试。');return [$p,$o,$d]; }
    private function normalizeTerms(array $p): array { $id=(int)($p['memberId']??0);$mode=(string)($p['rechargeMode']??'custom');$pkg=(int)($p['rechargePackageId']??0);$principal=$mode==='package'?0:$this->moneyToCents($p['principalAmount']??null);$bonus=$mode==='package'?0:$this->moneyToCents($p['bonusAmount']??null);$debt=$this->moneyToCents($p['debtAmount']??'0');$businessDate=$this->businessDate($p['businessDate']??'');$businessSource=$this->businessSource($p['businessSource']??[]);if($id<=0||!in_array($mode,['package','custom'],true)||($mode==='package'&&$pkg<=0)||($mode==='custom'&&($principal<=0||$bonus<0))||$debt<0) throw self::invalid('recharge_checkout_terms_invalid','充值信息不完整或金额无效，请重新填写。');return ['memberId'=>$id,'mode'=>$mode,'packageId'=>$pkg,'principalCents'=>$principal,'bonusCents'=>$bonus,'debtCents'=>$debt,'businessDate'=>$businessDate,'businessSource'=>$businessSource,'salespersonAllocations'=>array_values((array)($p['salespersonAllocations']??[]))]; }
    private function businessSource($value): array { if(!is_array($value)) throw self::invalid('recharge_business_source_invalid','请先在收银页面顶部选择客户来源。');$primary=(int)($value['primarySourceId']??0);$secondary=(int)($value['secondarySourceId']??0);if($primary<=0||$secondary<0) throw self::invalid('recharge_business_source_invalid','请先在收银页面顶部选择客户来源。');return ['primarySourceId'=>$primary,'secondarySourceId'=>$secondary]; }
    private function resolveTerms(array $input,array $member): array { if($input['mode']==='package'){foreach((array)(sys_data('user_recharge_quota')??[]) as $pkg){if(is_array($pkg)&&(int)($pkg['id']??0)===$input['packageId']){$input['principalCents']=$this->moneyToCents($pkg['price']??null);$input['bonusCents']=$this->moneyToCents($pkg['give_money']??0);break;}}}if($input['principalCents']<=0||$input['bonusCents']<0||$input['debtCents']>$input['principalCents']) throw self::invalid('recharge_checkout_terms_unavailable','所选充值套餐已下架或欠款金额无效，请重新填写。');$input['creditedPrincipalCents']=$input['principalCents']-$input['debtCents'];return $input; }
    private function snapshotMoneyToCents($value): int
    {
        $raw = trim((string)$value);
        if (preg_match('/^[1-9][0-9]*(?:\.00)?$/D', $raw) !== 1) {
            throw self::invalid('recharge_checkout_snapshot_payment_invalid','充值收款快照金额无效，请返回收款信息后重试。');
        }
        $whole = strpos($raw, '.') === false ? $raw : substr($raw, 0, -3);
        return (int)$whole * 100;
    }

    private function snapshotPayments(array $snapshot, string $requestId): array
    {
        $requestKey = (string)$requestId;
        $raw = is_array($snapshot['paymentLines'] ?? null) ? $snapshot['paymentLines'] : [];
        $out = [];
        $seen = [];
        foreach ($raw as $line) {
            if (!is_array($line)) continue;
            $method = (string)($line['paymentMethod'] ?? $line['method'] ?? $line['id'] ?? '');
            $method = trim($method);
            if ($method === '' || !in_array($method, self::METHODS, true) || isset($seen[$method])) {
                throw self::invalid('recharge_checkout_snapshot_payment_invalid','充值收款快照资料无效，请返回收款信息后重试。');
            }
            $amount = $this->snapshotMoneyToCents($line['amount'] ?? null);
            if ($amount <= 0) throw self::invalid('recharge_checkout_snapshot_payment_invalid','充值收款快照资料无效，请返回收款信息后重试。');
            $seen[$method] = true;
            $out[] = [
                'request_id' => $requestKey,
                'payment_authority_key' => 'snapshot:' . $method,
                'payment_method' => $method,
                'amount_cents' => $amount,
                'collection_reference' => (string)($line['externalTransactionNo'] ?? $line['collectionReference'] ?? ''),
                'sort_no' => count($out) + 1,
            ];
        }
        if (!$out) throw self::invalid('recharge_checkout_snapshot_payment_invalid','充值收款快照资料不完整，请返回收款信息后重试。');
        return $out;
    }
    private function paymentTotal(array $payments):int{return array_sum(array_map(static function(array $p):int{return (int)$p['amount_cents'];},$payments));}
    private function businessDate($value): string
    {
        $businessDate = trim((string)$value);
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
        if (!$date || $date->format('Y-m-d') !== $businessDate || $businessDate > $today) {
            throw self::invalid('recharge_business_date_invalid', '充值日期无效，请选择不晚于今天的日期。');
        }
        return $businessDate;
    }
    private function moneyToCents($value):int{$raw=trim((string)$value);if(preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw)!==1)return -1;return (int)$raw*100;}
    private function wholeMoney(int $cents):string{return (string)intdiv($cents,100);}
    private static function invalid(string $reason,string $message):CashierV3CommandException{return CashierV3CommandException::invalidContext($message,['reason'=>$reason]);}
}
