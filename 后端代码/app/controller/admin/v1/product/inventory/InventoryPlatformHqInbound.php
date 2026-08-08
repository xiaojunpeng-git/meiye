<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformHqInboundServices;
use app\services\query\UnifiedQueryException;
use think\facade\App;

final class InventoryPlatformHqInbound extends AuthController
{
    public function __construct(App $app, InventoryPlatformHqInboundServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function create()
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0);
            unset($input['hq_location_id']);
            return $this->success($this->services->create((array)$this->adminInfo, $locationId, $input));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部入库参数不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function index()
    {
        try {
            [$locationId, $keyword, $page, $limit, $dateFrom, $dateTo] = $this->request->getMore([
                ['hq_location_id', 0],
                ['keyword', ''],
                ['page', 1],
                ['limit', 20],
                ['business_date_from', ''],
                ['business_date_to', ''],
            ], true);
            return $this->success($this->services->index(
                (array)$this->adminInfo,
                (int)$locationId,
                (string)$keyword,
                (int)$page,
                (int)$limit,
                (string)$dateFrom,
                (string)$dateTo
            ));
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function detail(string $id)
    {
        try {
            $locationId = (int)$this->request->get('hq_location_id', 0);
            return $this->success($this->services->detail((array)$this->adminInfo, $locationId, $id));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部入库详情不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function outboundDetails(string $id)
    {
        try {
            return $this->success($this->services->outboundDetails(
                (array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), $id
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部入库单不存在或无权查看。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    public function void(string $id)
    {
        try {
            $input = $this->request->post();
            $input = is_array($input) ? $input : [];
            $locationId = (int)($input['hq_location_id'] ?? 0);
            $command = [
                'source_id' => $id,
                'idempotency_key' => (string)($input['idempotency_key'] ?? ''),
                'reason' => (string)($input['reason'] ?? ''),
            ];
            return $this->success('入库单已作废，库存已按原批次冲销。', $this->services->reverse((array)$this->adminInfo, $locationId, $command));
        } catch (UnifiedQueryException $exception) {
            return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]);
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('请填写作废原因。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($this->message($exception->getMessage()), ['code' => $exception->getMessage()]);
        }
    }

    private function message(string $code): string
    {
        return [
            'inventory_hq_location_scope_empty' => '当前平台范围内没有可用总部仓，请先完成库存迁移。',
            'inventory_hq_location_scope_denied' => '当前岗位无权操作该总部仓。',
            'inventory_hq_location_selection_required' => '请选择要入库的总部仓。',
            'inventory_hq_location_invalid' => '总部仓状态无效，无法入库。',
            'inventory_manual_inbound_sku_not_found' => '所选总部商品或默认规格已失效，请重新选择。',
            'inventory_manual_reversal_document_missing' => '入库单不存在或不属于当前总部仓。',
            'inventory_manual_reversal_already_voided' => '该入库单已经作废。',
            'inventory_manual_reversal_inbound_batch_insufficient' => '原入库批次剩余库存不足，不能作废。',
            'inventory_manual_reversal_idempotency_conflict' => '本次操作号已用于其他作废操作，请刷新后重试。',
        ][$code] ?? '总部入库未完成，请刷新后重试。';
    }
}
