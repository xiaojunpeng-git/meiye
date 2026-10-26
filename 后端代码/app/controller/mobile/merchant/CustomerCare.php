<?php

namespace app\controller\mobile\merchant;

use app\services\customer\care\integration\CustomerCareWorkbenchActionAdapter;
use app\services\customer\care\integration\CustomerCareActionInputMapper;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\merchant\MobileMerchantCustomerCarePhotoUploadServices;
use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\App;

/** Mobile adapter for the shared, server-authoritative customer-care workbench. */
class CustomerCare extends BaseController
{
    /** @var MobileMerchantRequestContextResolver */
    private $merchantContext;
    /** @var CustomerCareWorkbenchActionAdapter */
    private $care;
    /** @var MobileMerchantCustomerCarePhotoUploadServices */
    private $photoUploads;

    public function __construct(
        App $app,
        MobileMerchantRequestContextResolver $merchantContext,
        CustomerCareWorkbenchActionAdapter $care,
        MobileMerchantCustomerCarePhotoUploadServices $photoUploads
    ) {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->care = $care;
        $this->photoUploads = $photoUploads;
    }

    public function workbench()
    {
        return $this->dispatch(CustomerCareActionInputMapper::QUERY, 'CUSTOMER_CARE_VIEW');
    }

    public function photoUpload()
    {
        $merchant = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($merchant, 'CUSTOMER_CARE_VIEW');
        return MobileApiResponse::success(
            $this->photoUploads->upload($merchant, $this->request),
            MobileApiResponse::MERCHANT_CONTRACT
        );
    }

    public function action(string $action)
    {
        $supported = [
            CustomerCareActionInputMapper::CREATE_TASK,
            CustomerCareActionInputMapper::START_TASK,
            CustomerCareActionInputMapper::COMPLETE_TASK,
            CustomerCareActionInputMapper::REASSIGN_TASK,
            CustomerCareActionInputMapper::VOID_TASK,
            CustomerCareActionInputMapper::DELETE_TASK,
            CustomerCareActionInputMapper::CREATE_RECORD,
            CustomerCareActionInputMapper::VOID_RECORD,
        ];
        if (!in_array($action, $supported, true)) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '未支持的客情操作。', 'action');
        }
        // A customer-care view grant also authorizes direct formal record
        // capture. Task lifecycle actions remain write-scoped.
        $requiredAction = $action === CustomerCareActionInputMapper::CREATE_RECORD
            ? 'CUSTOMER_CARE_VIEW'
            : 'CUSTOMER_CARE_WRITE';
        return $this->dispatch($action, $requiredAction);
    }

    private function dispatch(string $action, string $requiredAction)
    {
        $merchant = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($merchant, $requiredAction);
        $payload = $this->request->post();
        $payload = is_array($payload) ? $payload : [];
        $idempotencyKey = trim((string)($payload['idempotencyKey'] ?? ''));
        if ($action !== CustomerCareActionInputMapper::QUERY && $idempotencyKey === '') {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '缺少本次客情操作标识，请重试。', 'idempotencyKey');
        }
        $result = $this->care->handle(
            $action,
            $this->merchantContext->customerCareContext($merchant),
            $payload,
            $idempotencyKey,
            (array)($payload['workbenchRequest'] ?? [])
        );
        return MobileApiResponse::success($result, MobileApiResponse::MERCHANT_CONTRACT);
    }
}
