<?php
declare(strict_types=1);

namespace app\dao\order;

use app\dao\BaseDao;
use app\model\order\StoreOrderReopenDraft;

class StoreOrderReopenDraftDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreOrderReopenDraft::class;
    }

    /**
     * 按原单取草稿（一张原单仅一行，含一切状态）
     */
    public function getBySourceOrderId(int $sourceOrderId, bool $lock = false)
    {
        $query = $this->getModel()->where('source_order_id', $sourceOrderId);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find();
    }

    /**
     * 未结账的活动草稿（DRAFT/LOADED）
     */
    public function getActiveBySourceOrderId(int $sourceOrderId, bool $lock = false)
    {
        $query = $this->getModel()
            ->where('source_order_id', $sourceOrderId)
            ->whereIn('state', [
                StoreOrderReopenDraft::STATE_DRAFT,
                StoreOrderReopenDraft::STATE_LOADED,
            ]);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find();
    }

    public function getByToken(string $token, bool $lock = false)
    {
        $query = $this->getModel()->where('draft_token', $token);
        if ($lock) {
            $query->lock(true);
        }
        return $query->find();
    }
}
