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
declare (strict_types=1);

namespace app\services\activity\integral;

use app\dao\other\CategoryDao;
use app\services\BaseServices;
use app\services\other\CategoryServices;
use mohe\services\FormBuilder as Form;
use think\facade\Route as Url;

/**
 * 积分商品
 */
class StoreIntegralCategoryServices extends BaseServices
{

	/**
	 * @param CategoryDao $dao
	 */
	public function __construct(CategoryDao $dao)
	{
		$this->dao = $dao;
	}

    /**
     * 分类类型
     * @var int
     */
    protected $group = CategoryServices::INTEGRAL_CATEGORY_GROUP;


    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $count = $this->dao->count($where);
        $list = $this->dao->getCateList($where, $page, $limit);
        if ($list) {
            foreach ($list as &$item) {
                $item['add_time'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
            }
        }
        return compact('list', 'count');
    }

    /**
     * 创建新增表单
     * @return array
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function createForm()
    {
        return create_form('添加分类', $this->form(), Url::buildUrl('/marketing/integral/category'), 'POST');
    }

    /**
     * 创建编辑表单
     * @param $id
     * @return array
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function editForm(int $id)
    {
        $info = $this->dao->get($id);
        return create_form('编辑分类', $this->form($info), $this->url('/marketing/integral/category/' . $id), 'POST');
    }

    /**
     * 生成表单参数
     * @param array $info
     * @return array
     * @throws \FormBuilder\Exception\FormBuilderException
     */
    public function form($info = [])
    {
        $f[] = Form::input('name', '标签组名称：', $info['name'] ?? '')->maxlength(30)->required();
        $f[] = Form::number('integral_min', '最低积分：', (int)($info['integral_min'] ?? 0))->min(0);
        $f[] = Form::number('integral_max', '最高积分：', (int)($info['integral_max'] ?? 0))->min(0);
        $f[] = Form::radio('is_show', '状态：', $info['is_show'] ?? 1)->options([['label' => '显示', 'value' => 1], ['label' => '隐藏', 'value' => 0]]);
        $f[] = Form::number('sort', '排序：', (int)($info['sort'] ?? 0))->min(0);
        return $f;
    }

    public function getTreeList()
    {
        $where = [
            'is_show' => 1,
            'group' => CategoryServices::INTEGRAL_CATEGORY_GROUP,
        ];
        $list = $this->dao->search($where)->field(['name', 'integral_min', 'integral_max'])->order('sort DESC')->select()->toArray();
        $data = [];
        foreach ($list as $item) {
            $data[] = [
                'label' => $item['name'],
                'value' => "{$item['integral_min']}-{$item['integral_max']}"
            ];
        }
        return $data;
    }
}
