<?php
namespace app\services\product\product;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * 商品创建请求幂等（create_request_key）
 */
class ProductCreateIdempotentServices extends BaseServices
{
    public const TABLE = 'product_create_idempotent';

    /**
     * @return array|null ['product_id'=>int]
     */
    public function findExisting(int $ownerType, int $relationId, string $requestKey): ?array
    {
        $requestKey = trim($requestKey);
        if ($requestKey === '') {
            return null;
        }
        try {
            $row = Db::name(self::TABLE)
                ->where('owner_type', $ownerType)
                ->where('relation_id', $relationId)
                ->where('request_key', $requestKey)
                ->find();
        } catch (\Throwable $e) {
            if ($this->isMissingTable($e)) {
                return null;
            }
            throw $e;
        }
        if (!$row) {
            return null;
        }
        return ['product_id' => (int)$row['product_id']];
    }

    public function save(int $ownerType, int $relationId, string $requestKey, int $productId): void
    {
        $requestKey = trim($requestKey);
        if ($requestKey === '' || $productId <= 0) {
            return;
        }
        $time = time();
        try {
            Db::name(self::TABLE)->insert([
                'owner_type' => $ownerType,
                'relation_id' => $relationId,
                'request_key' => $requestKey,
                'product_id' => $productId,
                'add_time' => $time,
            ]);
        } catch (\Throwable $e) {
            if ($this->isMissingTable($e)) {
                throw new AdminException('商品创建幂等表未初始化，请先执行数据库升级包');
            }
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false) {
                throw new AdminException('商品已创建，请勿重复提交');
            }
            throw $e;
        }
    }

    protected function isMissingTable(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return strpos($msg, self::TABLE) !== false
            || strpos($msg, "doesn't exist") !== false
            || strpos($msg, 'Base table or view not found') !== false;
    }
}
