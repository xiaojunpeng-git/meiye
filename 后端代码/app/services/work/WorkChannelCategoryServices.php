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

namespace app\services\work;

use app\services\other\CategoryServices;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;

/**
 * 渠道码分类
 * Class WorkChannelCategoryServices
 * @package app\services\work
 */
class WorkChannelCategoryServices extends CategoryServices
{

    const TYPE = 4;

    /**
     * @param array $where
     * @param array|string[] $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCateList(array $where = [], array $field = ['*'], array $with = [])
    {
        $where['group'] = self::TYPE;
        $where['owner_id'] = 0;
        return parent::getCateList($where, $field, $with);
    }

	/**
	 * 获取标签全部分类
	 * @param int $group
	 * @param int $storeId
	 * @return mixed
	 */
    public function getCateAll(int $group = self::TYPE, int $storeId = 0)
    {
        return $this->dao->getCateList(['type' => 0, 'owner_id' => 0, 'relation_id' => $storeId, 'group' => $group]);
    }

    /**
     * 标签分类表单
     * @param array $cataData
     * @return mixed
     */
    public function cateForm(array $cataData = [])
    {
        $f[] = Form::input('name', '分类名称：', $cataData['name'] ?? '')->maxlength(20)->required();
        $f[] = Form::number('sort', '排序：', (int)($cataData['sort'] ?? 0))->min(0);
        return $f;
    }

	/**
	 * 创建表单
	 * @return mixed
	 */
    public function createForm()
    {
        return create_form('添加渠道二维码分类', $this->cateForm(), $this->url('/work/channel/cate'), 'POST');
    }

	/**
	 * 修改分类标签表单
	 * @param int $id
	 * @return mixed
	 */
    public function updateForm(int $id)
    {
        $cate = $this->dao->get($id);
        if (!$cate) {
            throw new ValidateException('渠道二维码分类没有查到');
        }
        return create_form('编辑渠道二维码分类', $this->cateForm($cate->toArray()), $this->url('work/channel/cate/' . $id), 'PUT');
    }

}
