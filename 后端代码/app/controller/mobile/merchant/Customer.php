<?php

namespace app\controller\mobile\merchant;

use app\services\mobile\customer\MobileCustomerAudienceServices;
use app\services\mobile\customer\MobileCustomerAudienceOverviewServices;
use app\services\mobile\customer\MobileCustomerAssetRecordServices;
use app\services\mobile\customer\MobileCustomerCreateServices;
use app\services\mobile\customer\MobileCustomerQueryServices;
use app\services\mobile\customer\MobileCustomerRecentSummaryServices;
use app\services\mobile\customer\MobileCustomerOrderRecordServices;
use app\services\mobile\customer\MobileCustomerProfileServices;
use app\services\mobile\customer\MobileCustomerAvatarUploadServices;
use app\services\mobile\customer\MobileCustomerServiceRecordServices;
use app\services\mobile\customer\MobileCustomerUnifiedQueryCommandServices;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\App;

/** HTTP boundary for mobile customer read models and employee-owned dynamic audiences. */
class Customer extends BaseController
{
    /** @var MobileMerchantRequestContextResolver */
    private $merchantContext;
    /** @var MobileCustomerQueryServices */
    private $customers;
    /** @var MobileCustomerServiceRecordServices */
    private $serviceRecords;
    /** @var MobileCustomerRecentSummaryServices */
    private $recentSummary;
    /** @var MobileCustomerOrderRecordServices */
    private $orderRecords;
    /** @var MobileCustomerAssetRecordServices */
    private $assetRecords;
    /** @var MobileCustomerCreateServices */
    private $creator;
    /** @var MobileCustomerProfileServices */
    private $profiles;
    /** @var MobileCustomerAvatarUploadServices */
    private $avatarUploads;
    /** @var MobileCustomerAudienceServices */
    private $audiences;
    /** @var MobileCustomerAudienceOverviewServices */
    private $audienceOverview;
    /** @var MobileCustomerUnifiedQueryCommandServices */
    private $unifiedCommands;

    public function __construct(
        App $app,
        MobileMerchantRequestContextResolver $merchantContext,
        MobileCustomerQueryServices $customers,
        MobileCustomerServiceRecordServices $serviceRecords,
        MobileCustomerRecentSummaryServices $recentSummary,
        MobileCustomerOrderRecordServices $orderRecords,
        MobileCustomerAssetRecordServices $assetRecords,
        MobileCustomerCreateServices $creator,
        MobileCustomerProfileServices $profiles,
        MobileCustomerAvatarUploadServices $avatarUploads,
        MobileCustomerAudienceServices $audiences,
        MobileCustomerAudienceOverviewServices $audienceOverview,
        MobileCustomerUnifiedQueryCommandServices $unifiedCommands
    ) {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->customers = $customers;
        $this->serviceRecords = $serviceRecords;
        $this->recentSummary = $recentSummary;
        $this->orderRecords = $orderRecords;
        $this->assetRecords = $assetRecords;
        $this->creator = $creator;
        $this->profiles = $profiles;
        $this->avatarUploads = $avatarUploads;
        $this->audiences = $audiences;
        $this->audienceOverview = $audienceOverview;
        $this->unifiedCommands = $unifiedCommands;
    }

    public function query()
    {
        return $this->customerPage(false);
    }

    public function exclusive()
    {
        return $this->customerPage(true);
    }

    public function serviceRecords()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->serviceRecords->page($context, $this->payload()));
    }

    public function recentSummary()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->recentSummary->summary($this->payload()));
    }

    public function orderRecords()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->orderRecords->page($this->payload()));
    }

    public function orderRecordDetail()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->orderRecords->detail($this->payload()));
    }

    public function assetRecords()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->assetRecords->page($context, $this->payload()));
    }

    public function create()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_CREATE');
        return $this->mobileSuccess($this->creator->create($context, $this->payload()));
    }

    public function profileDraft()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_CREATE');
        return $this->mobileSuccess($this->profiles->draft($context));
    }

    public function profileAvatarUpload()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_CREATE');
        return $this->mobileSuccess($this->avatarUploads->upload($context, $this->request));
    }

    public function profileDetail(int $memberId)
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->profiles->detail($context, $memberId));
    }

    public function profileCreate()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_CREATE');
        return $this->mobileSuccess($this->profiles->create($context, $this->payload()));
    }

    public function profileUpdate(int $memberId)
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_CREATE');
        return $this->mobileSuccess($this->profiles->update($context, $memberId, $this->payload()));
    }

    public function audiences()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_AUDIENCE_VIEW');
        // System audiences are read-only definitions evaluated against the
        // current server scope. Reuse the overview projector so this list
        // carries a realtime memberTotal instead of a stale employee row.
        $projection = $this->audienceOverview->overview(
            $context,
            $this->identity($context),
            ['listOnly' => true]
        );
        $overview = (array)($projection['audienceOverview'] ?? []);
        return $this->mobileSuccess([
            'audiences' => (array)($overview['audiences'] ?? []),
            'metricVersion' => (string)($overview['metricVersion'] ?? 'mobile-customer-audience-overview-v1'),
            'dataAsOf' => (int)($overview['dataAsOf'] ?? time()),
            'aggregationCaughtUp' => (bool)($overview['aggregationCaughtUp'] ?? true),
        ]);
    }

    public function audienceOverview()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_AUDIENCE_VIEW');
        return $this->mobileSuccess($this->audienceOverview->overview($context, $this->identity($context), $this->payload()));
    }

    public function createAudience()
    {
        return $this->writeAudience('create', '');
    }

    public function audienceMembers(string $audienceId)
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_AUDIENCE_VIEW');
        return $this->mobileSuccess($this->audienceOverview->memberPage(
            $context,
            $this->identity($context),
            $audienceId,
            $this->payload()
        ));
    }

    public function updateAudience(string $audienceId)
    {
        return $this->writeAudience('update', $audienceId);
    }

    public function archiveAudience(string $audienceId)
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_AUDIENCE_MANAGE');
        $result = $this->audiences->archive($this->identity($context), $audienceId, $this->payload());
        return $this->mobileSuccess($result);
    }

    public function unifiedQueryCapabilities()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess([
            'unifiedQueryCapability' => $this->customers->unifiedCapabilities($context),
        ]);
    }

    public function unifiedQueryCommand()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_AUDIENCE_MANAGE');
        return $this->mobileSuccess($this->unifiedCommands->dispatch($context, $this->payload()));
    }

    private function customerPage(bool $exclusive)
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_VIEW');
        return $this->mobileSuccess($this->customers->query($context, $this->payload(), $exclusive));
    }

    private function writeAudience(string $operation, string $audienceId)
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'CUSTOMER_AUDIENCE_MANAGE');
        if (MobileCustomerAudienceServices::isSystemKey(trim($audienceId))) {
            throw new \think\exception\ValidateException('系统客群只读，不能修改或删除。');
        }
        $payload = $this->payload();
        $payload['validatedRule'] = $this->customers->validatedAudienceRule($context, $payload);
        $result = $operation === 'create'
            ? $this->audiences->create($this->identity($context), $payload)
            : $this->audiences->update($this->identity($context), $audienceId, $payload);
        return $this->mobileSuccess($result);
    }

    private function identity(array $context): array
    {
        return ['employeeId' => (string)$context['employeeId'], 'accountId' => (int)$context['accountId']];
    }

    private function payload(): array
    {
        $body = $this->request->post();
        return is_array($body) ? $body : [];
    }

    private function mobileSuccess(array $payload)
    {
        return MobileApiResponse::success($payload, MobileApiResponse::MERCHANT_CONTRACT);
    }
}
