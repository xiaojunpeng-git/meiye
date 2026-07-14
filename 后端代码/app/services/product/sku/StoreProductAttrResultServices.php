<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\product\sku;


use app\dao\product\sku\StoreProductAttrResultDao;
use app\services\BaseServices;
use app\services\product\product\StoreProductServices;
use mohe\exceptions\AdminException;

/**
 * Class StoreProductAttrResultService
 * @package app\services\product\sku
 * @mixin StoreProductAttrResultDao
 */
class StoreProductAttrResultServices extends BaseServices
{
    /**
     * StoreProductAttrResultServices constructor.
     * @param StoreProductAttrResultDao $dao
     */
    public function __construct(StoreProductAttrResultDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取属性规格
     * @param array $where
     * @return mixed
     */
    public function getResult(array $where)
    {
        return json_decode($this->dao->value($where, 'result'), true);
    }

    /**
     * 删除属性
     * @param int $id
     * @param int $type
     * @return bool
     */
    public function del(int $id, int $type)
    {
        return $this->dao->del($id, $type);
    }

    /**
     * 设置属性
     * @param array $data
     * @param int $id
     * @param int $type
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function setResult(array $data, int $id, int $type)
    {
        $result = $this->dao->get(['product_id' => $id, 'type' => $type]);
        if ($result) {
            $res = $this->dao->update($result['id'], ['result' => json_encode($data), 'change_time' => time()]);
        } else {
            $res = $this->dao->save(['product_id' => $id, 'result' => json_encode($data), 'change_time' => time(), 'type' => $type]);
        }
        if (!$res) throw new AdminException('规格保存失败');
        return true;
    }

	/**
	 * 检测商品无属性添加默认属性
	 * @param int $id
	 * @param int $type
	 * @param array $productInfo
	 * @return bool
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function checkProductReseultAndSaveAttr(int $id, int $type = 0, array $productInfo = [])
	{
		if (!$id) return true;
		/** @var StoreProductServices $storeProductServices */
		$storeProductServices = app()->make(StoreProductServices::class);
		if (!$productInfo) {
			$productInfo = $storeProductServices->get($id);
		}
		if (!$productInfo) {
			return true;
		}
		$productInfo = is_object($productInfo) ? $productInfo->toArray() : $productInfo;
		//无属性添加默认属性
		if ($this->dao->getCount(['product_id' => $id, 'type' => $type])) {
			return  true;
		}
		$attr = [
			[
				'value' => '规格',
				'detailValue' => '',
				'attrHidden' => '',
				'detail' => ['默认']
			]
		];
		$detail[0] = [
			'value1' => '默认',
			'detail' => ['规格' => '默认'],
			'pic' => $productInfo['image'],
			'price' => $productInfo['price'],
			'settle_price' => $productInfo['settle_price'] ?? 0,
			'cost' => $productInfo['cost'],
			'ot_price' => $productInfo['ot_price'],
			'stock' => $productInfo['stock'],
			'bar_code' => '',
			'weight' => 0,
			'volume' => 0,
			'brokerage' => 0,
			'brokerage_two' => 0,
			'code' => 0,
			'write_times' => 1,
			'write_valid' => 1,
			'days' => 0,
			'section_time' => []
		];
		/** @var StoreProductAttrServices $storeProductAttrServices */
		$storeProductAttrServices = app()->make(StoreProductAttrServices::class);
		$skuList = $storeProductAttrServices->validateProductAttr($attr, $detail, $id);
		$storeProductAttrServices->saveProductAttr($skuList, $id, $type);
		$storeProductServices->update($id, ['spec_type' => 0]);
		return true;
	}


}
