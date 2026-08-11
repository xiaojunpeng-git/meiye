<?php
declare(strict_types=1);

namespace app\services\cashier\v3\member;

use app\services\cashier\v3\CashierV3ActionDispatcher;
use app\services\cashier\v3\CashierV3CommandException;
use app\services\cashier\v3\CashierV3DataScopeContext;
use app\services\cashier\v3\CashierV3OperatorScope;
use app\services\cashier\v3\CashierV3ResultCode;
use app\services\cashier\v3\CashierV3TransactionGuard;
use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\registry\CashierV3ContextPolicy;
use app\services\cashier\v3\settlement\CashierV3CheckoutBusinessSourceSelectionServices;
use think\facade\Db;

/**
 * Recharge checkout is deliberately separate from sale/entitlement checkout.
 * Drafts are eventless; only submit-recharge-checkout invokes RechargeModule's
 * existing transaction that creates recharge, balance, debt, gift and facts.
 */
final class CashierV3RechargeCheckoutModule
{
    private const PREPARE = 'prepare-recharge-checkout';
    private const ADD = 'add-recharge-checkout-payment-method';
    private const UPDATE = 'update-recharge-checkout-payment-line';
    private const REMOVE = 'remove-recharge-checkout-payment-line';
    private const SOURCE = 'update-recharge-checkout-business-source';
    private const DATE = 'update-recharge-checkout-business-date';
    private const RELOAD = 'reload-recharge-checkout';
    private const SUBMIT = 'submit-recharge-checkout';
    private const REQUEST_TABLE = 'cashier_v3_recharge_checkout_request';
    private const PAYMENT_TABLE = 'cashier_v3_recharge_checkout_payment_draft';
    private const METHODS = ['unionpay','wechat','alipay','dianping_voucher','douyin_voucher','partner_collection','other_collection'];
    private const METHOD_NAMES = ['unionpay'=>'银联','wechat'=>'微信','alipay'=>'支付宝','dianping_voucher'=>'大众验券','douyin_voucher'=>'抖音验券','partner_collection'=>'合作方收款','other_collection'=>'其他收款'];

    public static function install(CashierV3ActionDispatcher $dispatcher): void
    {
        $module = new self();
        $install = static function (string $action, array $required, array $touched, callable $handler) use ($dispatcher): void {
            if (!$dispatcher->policies()->has($action)) {
                $dispatcher->policies()->register(new CashierV3ContextPolicy($action, $required, [], static function (array $payload, array $base) use ($required, $touched): array {
                    $workspaceId = trim((string)($base['session']['workspace_id'] ?? ''));
                    $memberId = trim((string)($payload['memberId'] ?? ''));
                    $requestId = trim((string)($payload['rechargeCheckoutRequestId'] ?? ''));
                    if ($workspaceId === '' || preg_match('/^[1-9][0-9]*$/D', $memberId) !== 1) throw self::invalid('recharge_checkout_context_invalid', '充值会员或当前工作台版本无效，请刷新后重试。');
                    $identities = [
                        ['role'=>'cashier_workspace','kind'=>'cashier_workspace','id'=>$workspaceId,'required'=>true],
                        ['role'=>'member','kind'=>'member','id'=>$memberId,'required'=>true],
                        ['role'=>'member_balance','kind'=>'member_balance','id'=>$memberId,'required'=>true],
                    ];
                    if (in_array('recharge_checkout_request', $required, true)) {
                        if (preg_match('/^RCR-[0-9a-f]{40}$/D', $requestId) !== 1) throw self::invalid('recharge_checkout_request_invalid', '充值结账请求已失效，请重新进入。');
                        $identities[] = ['role'=>'recharge_checkout_request','kind'=>'recharge_checkout_request','id'=>$requestId,'required'=>true];
                    }
                    return ['required'=>$required, 'identities'=>$identities, 'required_read_roles'=>array_column($identities, 'role'), 'required_touched_roles'=>$touched];
                }, $touched, array_keys(array_flip($required)), $required));
            }
            if (!$dispatcher->handlers()->hasCommand($action)) $dispatcher->handlers()->registerCommand($action, $handler);
        };
        $install(self::PREPARE, ['cashier_workspace','member','member_balance'], ['cashier_workspace'], static function (array $scope) use ($module): array { return $module->prepareInTx($scope); });
        foreach ([self::ADD,self::UPDATE,self::REMOVE] as $action) $install($action, ['cashier_workspace','member','member_balance','recharge_checkout_request'], ['recharge_checkout_request'], static function (array $scope) use ($module, $action): array { return $module->editInTx($action, $scope); });
        // 来源选择写独立 selection_version；充值请求只用于同版本锁读，
        // 不应在来源变更时推进 recharge_checkout_request 版本。
        $install(self::SOURCE, ['cashier_workspace','member','member_balance','recharge_checkout_request'], ['cashier_workspace'], static function (array $scope) use ($module): array { return $module->updateBusinessSourceInTx($scope); });
        $install(self::DATE, ['cashier_workspace','member','member_balance','recharge_checkout_request'], ['recharge_checkout_request'], static function (array $scope) use ($module): array { return $module->updateBusinessDateInTx($scope); });
        $install(self::RELOAD, ['cashier_workspace','member','member_balance'], [], static function (array $scope) use ($module): array { return $module->reloadInTx($scope); });
        $install(self::SUBMIT, ['cashier_workspace','member','member_balance','recharge_checkout_request'], ['recharge_checkout_request','member_balance'], static function (array $scope) use ($module): array { return $module->submitInTx($scope); });
    }

    private function prepareInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutPrepare');
        [$payload,$operator,$data] = $this->scope($scope);
        $input = $this->normalizeTerms($payload);
        // 收银选客已明确允许全集团正常会员。只要会员已在选择器中可选，
        // 充值就按当前登录门店归属创建，不再额外以会员的历史门店关系拦截。
        $member = (array)Db::name('user')->where('uid',$input['memberId'])->where('status',1)->where('is_del',0)->lock(true)->find();
        if (!$member) throw self::invalid('recharge_checkout_member_unavailable', '该会员已失效，不能充值。');
        $input = $this->resolveTerms($input, $member);
        $workspace = $this->workspaceId($operator, (string)$scope['state_context_id']);
        $now = time(); $creationKey = (string)$scope['idempotency_key'];
        $requestId = 'RCR-' . hash_hmac('sha1', $data->tenantId()."\0".$workspace."\0".$creationKey, $this->secret());
        $existing = (array)Db::name(self::REQUEST_TABLE)->where('tenant_id',$data->tenantId())->where('creation_idempotency_key',$creationKey)->lock(true)->find();
        if ($existing) return ['data'=>['rechargeCheckout'=>$this->projection($existing, $this->payments((string)$existing['request_id'], (int)$existing['request_version']))], 'touched'=>['cashier_workspace'], 'message'=>'充值结账已准备。'];
        $termsFingerprint = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE));
        Db::name(self::REQUEST_TABLE)->insert([
            'request_id'=>$requestId,'tenant_id'=>$data->tenantId(),'store_id'=>$operator->storeId(),'member_id'=>$input['memberId'],'operator_id'=>$operator->operatorId(),
            'workspace_id'=>$workspace,'state_context_id'=>(string)$scope['state_context_id'],'request_version'=>1,'request_status'=>'editing','recharge_mode'=>$input['mode'],'recharge_package_id'=>$input['packageId'],
            'principal_cents'=>$input['principalCents'],'bonus_cents'=>$input['bonusCents'],'debt_cents'=>$input['debtCents'],'credited_principal_cents'=>$input['creditedPrincipalCents'],'balance_version'=>$input['balanceVersion'],
            'salespeople_json'=>json_encode($input['salespersonAllocations'], JSON_UNESCAPED_UNICODE),'terms_fingerprint'=>$termsFingerprint,'creation_idempotency_key'=>$creationKey,'last_idempotency_key'=>$creationKey,
            'business_date'=>date('Y-m-d',$now),'business_date_reason'=>'','occurred_at'=>$now,'settled_at'=>0,'recorded_at'=>$now,'add_time'=>$now,'update_time'=>$now
        ]);
        if ($input['creditedPrincipalCents'] > 0) {
            (new CashierV3BusinessConfigServices())->resolveAccountingMethodSnapshot('unionpay', true);
            $this->insertPayment($requestId, 1, 'unionpay', $input['creditedPrincipalCents'], 'payment:unionpay:initial', 1, $now);
        }
        $request = (array)Db::name(self::REQUEST_TABLE)->where('request_id',$requestId)->find();
        return ['data'=>['rechargeCheckout'=>$this->projection($request, $this->payments($requestId,1), $scope)], 'touched'=>['cashier_workspace'], 'message'=>'充值结账已准备。'];
    }

    private function editInTx(string $action, array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutEdit');
        [$payload,$operator,$data] = $this->scope($scope); $request = $this->lockEditing($payload,$operator,$data,(string)$scope['state_context_id']);
        $version = (int)$request['request_version']; $payments = $this->payments((string)$request['request_id'],$version,true); $now = time();
        if ($action === self::ADD) {
            $method = (string)($payload['paymentMethodId'] ?? ''); if (!in_array($method,self::METHODS,true)) throw self::invalid('recharge_checkout_payment_method_invalid','请选择有效的记账收款方式。');
            if (array_reduce($payments, static function (bool $found, array $payment) use ($method): bool {
                return $found || (string)$payment['payment_method'] === $method;
            }, false)) {
                throw self::invalid('recharge_checkout_payment_method_duplicate', '该收款方式已添加，请直接修改已有金额。');
            }
            // Adding a draft payment only reads configuration; the final
            // submit command takes the write lock after all methods are chosen.
            (new CashierV3BusinessConfigServices())->resolveAccountingMethodSnapshot($method, false);
            $this->copyPayments($payments,$version+1,$now,'',[]);
            $this->insertPayment((string)$request['request_id'],$version + 1,$method,max(0,$this->due($request)-$this->paymentTotal($payments)),'payment:'.$method.':'.(string)$scope['idempotency_key'],count($payments)+1,$now);
        } elseif ($action === self::UPDATE) {
            $line = $this->paymentById($payments,(string)($payload['paymentLineId'] ?? '')); $amount = $this->moneyToCents($payload['amount'] ?? null); if ($amount <= 0) throw self::invalid('recharge_checkout_payment_amount_invalid','收款金额必须为正整数。');
            $ref = trim((string)($payload['externalTransactionNo'] ?? '')); if (strlen($ref)>128 || ($ref!=='' && preg_match('/^[A-Za-z0-9_.:\/-]+$/D',$ref)!==1)) throw self::invalid('recharge_checkout_reference_invalid','流水号或备注格式无效。');
            $this->copyPayments($payments,$version+1,$now,(string)$line['payment_draft_id'],['amount_cents'=>$amount,'collection_reference'=>$ref]);
        } else {
            $line = $this->paymentById($payments,(string)($payload['paymentLineId'] ?? '')); if (count($payments)<=1) throw self::invalid('recharge_checkout_payment_last_line','请至少保留一笔收款方式。');
            $this->copyPayments($payments,$version+1,$now,(string)$line['payment_draft_id'],null);
        }
        $next = $this->payments((string)$request['request_id'],$version+1); if ($this->paymentTotal($next)>$this->due($request)) throw self::invalid('recharge_checkout_payment_exceeds_due','收款金额不能超过本次应收。');
        Db::name(self::REQUEST_TABLE)->where('id',$request['id'])->where('request_version',$version)->update(['request_version'=>$version+1,'last_idempotency_key'=>(string)$scope['idempotency_key'],'update_time'=>$now]);
        $request['request_version']=$version+1;
        return ['data'=>['rechargeCheckout'=>$this->projection($request,$next,$scope)],'touched'=>['recharge_checkout_request'],'message'=>'收款明细已更新。'];
    }

    private function updateBusinessSourceInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutBusinessSource');
        [$payload, $operator, $data] = $this->scope($scope);
        $this->assertBusinessSourcePayload($payload);
        $request = $this->lockEditing($payload, $operator, $data, (string)$scope['state_context_id']);
        $selection = (new CashierV3CheckoutBusinessSourceSelectionServices())->mutateRechargeInTx(
            $payload, $operator, $data, (string)$scope['state_context_id']
        );
        return [
            'data' => ['rechargeCheckout' => $this->projection(
                $request,
                $this->payments((string)$request['request_id'], (int)$request['request_version']),
                $scope
            )],
            'touched' => ['cashier_workspace'],
            'message' => '业务来源已更新。',
        ];
    }

    private function updateBusinessDateInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutBusinessDate');
        [$payload, $operator, $data] = $this->scope($scope);
        $businessDate = trim((string)($payload['businessDate'] ?? ''));
        $reason = trim((string)($payload['reason'] ?? ''));
        $timezone = new \DateTimeZone('Asia/Shanghai');
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate, $timezone);
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw self::invalid('recharge_business_date_invalid', '充值日期格式无效，请重新选择。');
        }
        if ($businessDate > $today) {
            throw self::invalid('recharge_business_date_future', '充值日期不能晚于今天。');
        }
        if ($businessDate < $today && $reason === '') {
            throw self::invalid('recharge_business_date_reason_required', '历史充值日期必须填写补单原因。');
        }
        if (mb_strlen($reason) > 200) {
            throw self::invalid('recharge_business_date_reason_invalid', '补单原因不能超过 200 个字。');
        }
        $request = $this->lockEditing($payload, $operator, $data, (string)$scope['state_context_id']);
        $version = (int)$request['request_version'];
        $now = time();
        Db::name(self::REQUEST_TABLE)->where('id', $request['id'])->where('request_version', $version)->update([
            'business_date' => $businessDate,
            'business_date_reason' => $businessDate < $today ? $reason : '',
            'request_version' => $version + 1,
            'last_idempotency_key' => (string)$scope['idempotency_key'],
            'update_time' => $now,
        ]);
        $request['business_date'] = $businessDate;
        $request['business_date_reason'] = $businessDate < $today ? $reason : '';
        $request['request_version'] = $version + 1;
        return [
            'data' => ['rechargeCheckout' => $this->projection($request, $this->payments((string)$request['request_id'], $version + 1), $scope)],
            'touched' => ['recharge_checkout_request'],
            'message' => '充值日期已保存。',
        ];
    }

    private function submitInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutSubmit');
        [$payload,$operator,$data] = $this->scope($scope); $request = $this->lockEditing($payload,$operator,$data,(string)$scope['state_context_id']);
        $payments = $this->payments((string)$request['request_id'],(int)$request['request_version'],true);
        if ($this->hasDuplicatePaymentMethods($payments)) {
            throw self::invalid('recharge_checkout_payment_method_duplicate', '同一种收款方式只能保留一行，请删除重复方式后重试。');
        }
        if ($this->paymentTotal($payments) !== $this->due($request)) throw self::invalid('recharge_checkout_payment_total_mismatch','各收款金额合计必须等于本次实际收款。');
        $accountingConfig = new CashierV3BusinessConfigServices();
        foreach ($payments as $payment) {
            $accountingConfig->resolveAccountingMethodSnapshot((string)$payment['payment_method'], true);
        }
        $businessSource = (new CashierV3CheckoutBusinessSourceSelectionServices())->lockResolvedForSettlementInTx(CashierV3CheckoutBusinessSourceSelectionServices::KIND_RECHARGE, (string)$request['request_id'], $data->tenantId(), $operator->storeId());
        $finalPayload = ['memberId'=>(int)$request['member_id'],'rechargeMode'=>(string)$request['recharge_mode'],'rechargePackageId'=>(int)$request['recharge_package_id'],'principalAmount'=>$this->wholeMoney((int)$request['principal_cents']),'bonusAmount'=>$this->wholeMoney((int)$request['bonus_cents']),'debtAmount'=>$this->wholeMoney((int)$request['debt_cents']),'balanceVersion'=>(int)$request['balance_version'],'businessDate'=>(string)($request['business_date'] ?? ''),'businessDateReason'=>(string)($request['business_date_reason'] ?? ''),'salespersonAllocations'=>json_decode((string)$request['salespeople_json'],true) ?: [],'paymentLines'=>array_map(static function(array $p): array { return ['paymentMethod'=>(string)$p['payment_method'],'amount'=>(string)intdiv((int)$p['amount_cents'], 100),'collectionReference'=>(string)$p['collection_reference']]; },$payments)];
        $result = (new CashierV3RechargeModule())->submitInTx(array_merge($scope,['payload'=>$finalPayload,'business_source'=>$businessSource]));
        $recharge = (array)($result['data']['recharge'] ?? []); $now=time(); $version=(int)$request['request_version'];
        Db::name(self::REQUEST_TABLE)->where('id',$request['id'])->where('request_version',$version)->update(['request_version'=>$version+1,'request_status'=>'succeeded','recharge_id'=>(int)($recharge['rechargeId']??0),'recharge_order_no'=>(string)($recharge['rechargeOrderNo']??''),'settled_at'=>$now,'last_idempotency_key'=>(string)$scope['idempotency_key'],'update_time'=>$now]);
        $request=array_merge($request,['request_version'=>$version+1,'request_status'=>'succeeded','recharge_id'=>(int)($recharge['rechargeId']??0),'recharge_order_no'=>(string)($recharge['rechargeOrderNo']??''),'settled_at'=>$now]);
        return ['data'=>['rechargeCheckout'=>$this->projection($request,$payments,$scope,['recharge'=>$recharge])],'business_no'=>(string)($recharge['rechargeOrderNo']??''),'touched'=>['recharge_checkout_request','member_balance'],'message'=>'会员充值成功。'];
    }

    private function reloadInTx(array $scope): array
    {
        CashierV3TransactionGuard::assertInTransaction('rechargeCheckoutReload');
        [$payload,$operator,$data] = $this->scope($scope);
        $requestId = trim((string)($payload['requestId'] ?? ''));
        if (preg_match('/^RCR-[0-9a-f]{40}$/D', $requestId) !== 1) {
            throw self::invalid('recharge_checkout_reload_request_invalid', '充值结账请求已失效，请重新进入。');
        }
        $request = (array)Db::name(self::REQUEST_TABLE)
            ->where('request_id', $requestId)
            ->where('tenant_id', $data->tenantId())
            ->where('store_id', $operator->storeId())
            ->where('member_id', (int)($payload['memberId'] ?? 0))
            ->where('operator_id', $operator->operatorId())
            ->where('workspace_id', $this->workspaceId($operator, (string)$scope['state_context_id']))
            ->where('state_context_id', (string)$scope['state_context_id'])
            ->find();
        if (!$request || (!($payload['queryOnly'] ?? false) && (string)$request['request_status'] !== 'editing')) {
            throw self::invalid('recharge_checkout_reload_not_available', '充值结账现场已失效，请重新进入。');
        }
        $statusOverride = (($payload['queryOnly'] ?? false) && (string)$request['request_status'] === 'editing')
            ? 'result_unknown'
            : null;
        return [
            'data' => ['rechargeCheckout' => $this->projection(
                $request,
                $this->payments($requestId, (int)$request['request_version']),
                $scope,
                [],
                $statusOverride
            )],
            'touched' => [],
            'message' => '充值结账资料已刷新。',
        ];
    }

    private function projection(array $request,array $payments,array $scope=[],array $result=[],?string $statusOverride=null): array
    {
        $businessSource = (new CashierV3CheckoutBusinessSourceSelectionServices())->read(
            CashierV3CheckoutBusinessSourceSelectionServices::KIND_RECHARGE,
            (string)$request['request_id'],
            (string)$request['tenant_id'],
            (int)$request['store_id']
        );
        $due=$this->due($request); $selected=$this->paymentTotal($payments); $version=(int)$request['request_version']; $contexts=[]; $accounting=$this->accountingMethodMap();
        if ($scope) foreach ((array)($scope['contexts']??[]) as $c) $contexts[]=['kind'=>(string)$c['kind'],'id'=>(string)$c['id'],'expectedVersion'=>(int)$c['expected_version']];
        $found=false; foreach ($contexts as &$c) if ($c['kind']==='recharge_checkout_request') {$c['expectedVersion']=$version;$found=true;} unset($c);
        if (!$found && (string)$request['request_status']==='editing') $contexts[]=['kind'=>'recharge_checkout_request','id'=>(string)$request['request_id'],'expectedVersion'=>$version];
        $status = $statusOverride ?: (string)$request['request_status'];
        $selectedMethods = [];
        foreach ($payments as $payment) $selectedMethods[(string)$payment['payment_method']] = true;
        return ['contractVersion'=>'cashier-v3-recharge-checkout-v1','businessType'=>'recharge','status'=>$status,'requestStatus'=>(string)$request['request_status'],'checkoutRequestId'=>(string)$request['request_id'],'rechargeCheckoutRequestId'=>(string)$request['request_id'],'requestId'=>(string)$request['request_id'],'checkoutRequestVersion'=>$version,'revision'=>$version,'activeStep'=>$status !== 'editing' ? 4 : 1,'resumeOnLoad'=>false,'canClose'=>$status==='succeeded','canRetry'=>false,'businessDate'=>(string)($request['business_date'] ?? ''),'businessDateReason'=>(string)($request['business_date_reason'] ?? ''),
            'orderLines'=>[['id'=>'recharge:'.(string)$request['request_id'],'lineRole'=>'recharge','name'=>(string)$request['recharge_mode']==='package'?'充值套餐':'自定义充值','quantity'=>1,'amount'=>$this->money((int)$request['principal_cents'])]],
            'summary'=>['selectedCount'=>1,'originalAmount'=>$this->money((int)$request['principal_cents']),'discountAmount'=>0,'receivableAmount'=>$this->money($due),'entitlementActualAmount'=>0],
            'composition'=>['code'=>'recharge','lineRoles'=>['recharge'],'hasSale'=>false,'hasEntitlement'=>false,'primaryAction'=>'collect_payment','primaryActionLabel'=>'确认充值','steps'=>[['key'=>'order','number'=>1,'label'=>'确认充值'],['key'=>'payment','number'=>2,'label'=>'收款信息'],['key'=>'final','number'=>3,'label'=>'确认充值'],['key'=>'result','number'=>4,'label'=>'处理结果']]],
            'member'=>['id'=>(int)$request['member_id']],'bonusAmount'=>$this->money((int)$request['bonus_cents']),'debtAmount'=>$this->money((int)$request['debt_cents']),'cashPerformanceAmount'=>$this->money($due),'balancePaymentAmount'=>0,'finalChanges'=>[],
            'sourceEnabled'=>true,'sourceSelectable'=>true,'sourceLabel'=>(string)$businessSource['displayNameSnapshot'],'sourceSelectionVersion'=>(int)$businessSource['selectionVersion'],'primarySourceId'=>(int)$businessSource['primarySourceId'],'secondarySourceId'=>(int)$businessSource['secondarySourceId'],
            'payment'=>['methods'=>array_values(array_map(static function(array $method) use ($selectedMethods):array{$code=(string)$method['code'];return ['id'=>$code,'name'=>$method['displayName'],'canAdd'=>!isset($selectedMethods[$code])];},array_filter($accounting,static function(array $method):bool{return (int)$method['status']===1;}))),'selectedLines'=>array_map(function(array $p) use ($accounting, $request):array{$method=(string)$p['payment_method'];return ['id'=>$this->publicPaymentLineId((string)$p['request_id'],(string)$p['payment_authority_key']),'kind'=>'bookkeeping_collection','method'=>$method,'name'=>(string)($accounting[$method]['displayName']??self::METHOD_NAMES[$method]??'未命名收款方式'),'amount'=>$this->money((int)$p['amount_cents']),'externalTransactionNo'=>(string)$p['collection_reference'],'remark'=>'','status'=>(string)$request['request_status']==='succeeded'?'succeeded':'editing','canEdit'=>(string)$request['request_status']==='editing','canRemove'=>(string)$request['request_status']==='editing'];},$payments),'summary'=>['receivableAmount'=>$this->money($due),'selectedAmount'=>$this->money($selected),'remainingAmount'=>$this->money(max(0,$due-$selected)),'overpaidAmount'=>$this->money(max(0,$selected-$due))]],
            'commandContexts'=>$contexts,'result'=>$result];
    }

    private function scope(array $scope): array { $p=is_array($scope['payload']??null)?$scope['payload']:[];$o=$scope['operator_scope']??null;$d=$scope['data_scope']??null;if(!$o instanceof CashierV3OperatorScope||!$d instanceof CashierV3DataScopeContext) throw self::invalid('recharge_checkout_scope_missing','充值结账服务尚未就绪，请刷新后重试。');return [$p,$o,$d]; }
    private function accountingMethodMap(): array { $out=[]; foreach ((new CashierV3BusinessConfigServices())->accountingMethods(false) as $method) $out[(string)$method['code']]=$method; return $out; }
    private function assertBusinessSourcePayload(array $payload): void { $keys=['memberId','rechargeCheckoutRequestId','rechargeCheckoutRequestVersion','primarySourceId','secondarySourceId','sourceSelectionVersion']; sort($keys);$actual=array_keys($payload);sort($actual);if($actual!==$keys || (int)($payload['primarySourceId']??0)<=0 || (int)($payload['secondarySourceId']??0)<0 || (int)($payload['sourceSelectionVersion']??0)<0) throw self::invalid('recharge_business_source_payload_invalid','业务来源资料无效，请刷新结账页面后重试。'); }
    private function normalizeTerms(array $p): array { $id=(int)($p['memberId']??0);$mode=(string)($p['rechargeMode']??'custom');$pkg=(int)($p['rechargePackageId']??0);$principal=$mode==='package'?0:$this->moneyToCents($p['principalAmount']??null);$bonus=$mode==='package'?0:$this->moneyToCents($p['bonusAmount']??null);$debt=$this->moneyToCents($p['debtAmount']??'0');$version=(int)($p['balanceVersion']??0);if($id<=0||!in_array($mode,['package','custom'],true)||($mode==='package'&&$pkg<=0)||($mode==='custom'&&($principal<=0||$bonus<0))||$debt<0||$version<=0) throw self::invalid('recharge_checkout_terms_invalid','充值信息不完整或金额无效，请重新填写。');return ['memberId'=>$id,'mode'=>$mode,'packageId'=>$pkg,'principalCents'=>$principal,'bonusCents'=>$bonus,'debtCents'=>$debt,'balanceVersion'=>$version,'salespersonAllocations'=>array_values((array)($p['salespersonAllocations']??[]))]; }
    private function resolveTerms(array $input,array $member): array { if($input['mode']==='package'){foreach((array)(sys_data('user_recharge_quota')??[]) as $pkg){if(is_array($pkg)&&(int)($pkg['id']??0)===$input['packageId']){$input['principalCents']=$this->moneyToCents($pkg['price']??null);$input['bonusCents']=$this->moneyToCents($pkg['give_money']??0);break;}}}if($input['principalCents']<=0||$input['bonusCents']<0||$input['debtCents']>$input['principalCents']) throw self::invalid('recharge_checkout_terms_unavailable','所选充值套餐已下架或欠款金额无效，请重新填写。');$input['creditedPrincipalCents']=$input['principalCents']-$input['debtCents'];return $input; }
    private function lockEditing(array $p,CashierV3OperatorScope $o,CashierV3DataScopeContext $d,string $state): array { $id=(string)($p['rechargeCheckoutRequestId']??'');$v=(int)($p['rechargeCheckoutRequestVersion']??$p['checkoutRequestVersion']??0);$row=(array)Db::name(self::REQUEST_TABLE)->where('request_id',$id)->where('tenant_id',$d->tenantId())->where('store_id',$o->storeId())->where('member_id',(int)($p['memberId']??0))->where('operator_id',$o->operatorId())->where('workspace_id',$this->workspaceId($o,$state))->where('state_context_id',$state)->where('request_status','editing')->lock(true)->find();if(!$row||$v<=0||(int)$row['request_version']!==$v) throw CashierV3CommandException::versionConflict('充值结账信息已被更新，请重新打开后重试。');return $row; }
    private function payments(string $id,int $version,bool $lock=false): array { $q=Db::name(self::PAYMENT_TABLE)->where('request_id',$id)->where('draft_version',$version)->where('draft_status','draft')->order('sort_no asc,id asc');if($lock)$q->lock(true);return $q->select()->toArray(); }
    private function insertPayment(string $id,int $version,string $method,int $amount,string $key,int $sort,int $now):void {Db::name(self::PAYMENT_TABLE)->insert(['payment_draft_id'=>'RCP-'.hash_hmac('sha1',$id."\0".$key,$this->secret()),'request_id'=>$id,'draft_version'=>$version,'payment_authority_key'=>$key,'payment_method'=>$method,'amount_cents'=>$amount,'collection_reference'=>'','sort_no'=>$sort,'draft_status'=>'draft','add_time'=>$now,'update_time'=>$now]);}
    private function copyPayments(array $payments,int $next,int $now,string $targetId,?array $replacement):void {foreach($payments as $i=>$p){if($targetId!==''&&(string)$p['payment_draft_id']===$targetId&&$replacement===null)continue;$authority=(string)$p['payment_authority_key'];$row=['payment_draft_id'=>'RCP-'.hash_hmac('sha1',(string)$p['request_id']."\0".$authority."\0".$next,$this->secret()),'request_id'=>(string)$p['request_id'],'draft_version'=>$next,'payment_authority_key'=>$authority,'payment_method'=>(string)$p['payment_method'],'amount_cents'=>(int)$p['amount_cents'],'collection_reference'=>(string)$p['collection_reference'],'sort_no'=>$i+1,'draft_status'=>'draft','add_time'=>$now,'update_time'=>$now];if($targetId!==''&&(string)$p['payment_draft_id']===$targetId)$row=array_merge($row,$replacement??[]);Db::name(self::PAYMENT_TABLE)->insert($row);}}
    private function paymentById(array $payments,string $id):array {foreach($payments as $p)if((string)$p['payment_draft_id']===$id||hash_equals($this->publicPaymentLineId((string)$p['request_id'],(string)$p['payment_authority_key']),$id))return $p;throw self::invalid('recharge_checkout_payment_not_found','该收款明细已变化，请刷新后重试。');}
    private function publicPaymentLineId(string $requestId,string $authorityKey):string{return 'RCL-'.hash_hmac('sha1',$requestId."\0".$authorityKey,$this->secret());}
    private function due(array $request):int{return (int)$request['credited_principal_cents'];}
    private function paymentTotal(array $payments):int{return array_sum(array_map(static function(array $p):int{return (int)$p['amount_cents'];},$payments));}
    private function hasDuplicatePaymentMethods(array $payments): bool
    {
        $seen = [];
        foreach ($payments as $payment) {
            $method = (string)($payment['payment_method'] ?? '');
            if ($method !== '' && isset($seen[$method])) return true;
            if ($method !== '') $seen[$method] = true;
        }
        return false;
    }
    private function moneyToCents($value):int{$raw=trim((string)$value);if(preg_match('/^(?:0|[1-9][0-9]*)$/D',$raw)!==1)return -1;return (int)$raw*100;}
    private function money(int $cents):string{return number_format($cents/100,2,'.','');}
    private function wholeMoney(int $cents):string{return (string)intdiv($cents,100);}
    private function workspaceId(CashierV3OperatorScope $o,string $state):string{return sprintf('ws:%d:%d:%s',$o->storeId(),$o->operatorId(),$state);}
    private function secret():string{$s=trim((string)config('cashier_v3.checkout_namespace_secret'));if(strlen($s)<32)throw self::invalid('recharge_checkout_secret_missing','充值结账签名服务尚未配置。');return $s;}
    private static function invalid(string $reason,string $message):CashierV3CommandException{return CashierV3CommandException::invalidContext($message,['reason'=>$reason]);}
}
