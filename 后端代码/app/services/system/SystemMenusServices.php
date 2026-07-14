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

namespace app\services\system;

use app\dao\system\SystemMenusDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use mohe\utils\Arr;

/**
 * 权限菜单
 * Class SystemMenusServices
 * @package app\services\system
 * @mixin SystemMenusDao
 */
class SystemMenusServices extends BaseServices
{

	/**
	 * @var string[]
	 */
	protected $type = [
		1 => 'admin',//平台
		2 => 'store',//门店
		3 => 'cashier',//收银台
		4 => 'supplier',//供应商
	];

	/**
	 * 移动端菜单权限数据
	 * @var array[]
	 */
	protected $mallRules = [
		['id' => 1, 'pid' => 0, 'type' => 5, 'menu_name' => '工作台', 'menu_path' => '/admin/work/index', 'unique_auth' => 'mall-admin'],
		['id' => 2, 'pid' => 0, 'type' => 5, 'menu_name' => '商品管理', 'menu_path' => '/admin/goods/index', 'unique_auth' => 'mall-admin-product'],
		['id' => 3, 'pid' => 0, 'type' => 5, 'menu_name' => '订单管理', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-order'],
		['id' => 4, 'pid' => 0, 'type' => 5, 'menu_name' => '售后管理', 'menu_path' => '/admin/refundOrderList/index', 'unique_auth' => 'mall-admin-refund_order'],
		['id' => 5, 'pid' => 0, 'type' => 5, 'menu_name' => '用户管理', 'menu_path' => '/admin/user/list', 'unique_auth' => 'mall-admin-user'],
		['id' => 6, 'pid' => 0, 'type' => 5, 'menu_name' => '代客下单', 'menu_path' => '/behalf/user_list/index', 'unique_auth' => 'mall-admin-behalf'],
		['id' => 50, 'pid' => 1, 'type' => 5, 'menu_name' => '快捷入口', 'menu_path' => '', 'unique_auth' => 'mall-admin-home'],
		//['id' => 51, 'pid' => 1, 'type' => 5, 'menu_name' => '店铺管理', 'menu_path' => '', 'unique_auth' => 'mall-admin-index'],
		['id' => 52, 'pid' => 2, 'type' => 5, 'menu_name' => '商品列表', 'menu_path' => '/admin/goods/index', 'unique_auth' => 'mall-admin-product'],
		['id' => 53, 'pid' => 2, 'type' => 5, 'menu_name' => '商品上/下架', 'menu_path' => '/admin/goods/show', 'unique_auth' => 'mall-admin-product-show'],
		['id' => 54, 'pid' => 2, 'type' => 5, 'menu_name' => '商品修改价格/库存', 'menu_path' => '/admin/goods/edit', 'unique_auth' => 'mall-admin-product-edit_price_stock'],
		['id' => 55, 'pid' => 2, 'type' => 5, 'menu_name' => '商品修改库存', 'menu_path' => '/admin/goods/edit/stock', 'unique_auth' => 'mall-admin-product-edit-stock'],
		['id' => 56, 'pid' => 2, 'type' => 5, 'menu_name' => '商品修改分类', 'menu_path' => '/admin/goods/edit/category', 'unique_auth' => 'mall-admin-product-edit-category'],
		['id' => 57, 'pid' => 2, 'type' => 5, 'menu_name' => '商品修改标签', 'menu_path' => '/admin/goods/edit/label', 'unique_auth' => 'mall-admin-product-edit-label'],
		['id' => 70, 'pid' => 3, 'type' => 5, 'menu_name' => '订单列表', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-order'],
		['id' => 71, 'pid' => 3, 'type' => 5, 'menu_name' => '订单详情', 'menu_path' => '/admin/orderDetail/index', 'unique_auth' => 'mall-admin-order-detail'],
		['id' => 72, 'pid' => 3, 'type' => 5, 'menu_name' => '订单备注', 'menu_path' => '/admin/orderList/remark', 'unique_auth' => 'mall-admin-order-remark'],
		['id' => 73, 'pid' => 3, 'type' => 5, 'menu_name' => '订单改价', 'menu_path' => '/admin/orderList/changePrice', 'unique_auth' => 'mall-admin-order-change_price'],
		['id' => 74, 'pid' => 3, 'type' => 5, 'menu_name' => '订单确认收款', 'menu_path' => '/admin/orderList/offline', 'unique_auth' => 'mall-admin-order-offline'],
		['id' => 75, 'pid' => 3, 'type' => 5, 'menu_name' => '订单发货', 'menu_path' => '/admin/orderList/delivery', 'unique_auth' => 'mall-admin-order-delivery'],
		['id' => 76, 'pid' => 3, 'type' => 5, 'menu_name' => '订单核销', 'menu_path' => '/admin/orderList/writeoff', 'unique_auth' => 'mall-admin-order-writeoff'],
		['id' => 77, 'pid' => 3, 'type' => 5, 'menu_name' => '订单退款', 'menu_path' => '/admin/orderList/refund', 'unique_auth' => 'mall-admin-order-refund'],
		['id' => 78, 'pid' => 3, 'type' => 5, 'menu_name' => '确认收货', 'menu_path' => '/admin/orderList/take', 'unique_auth' => 'mall-admin-order-take'],
        ['id' => 79, 'pid' => 3, 'type' => 5, 'menu_name' => '订单派单', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-delivery-keep'],
        ['id' => 80, 'pid' => 3, 'type' => 5, 'menu_name' => '订单改单', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-reassign-delivery'],
        ['id' => 81, 'pid' => 3, 'type' => 5, 'menu_name' => '确认送达', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-confirm-delivery'],
        ['id' => 90, 'pid' => 4, 'type' => 5, 'menu_name' => '售后订单列表', 'menu_path' => '/admin/refundOrderList/index', 'unique_auth' => 'mall-admin-refund_order-index'],
		['id' => 91, 'pid' => 4, 'type' => 5, 'menu_name' => '售后订单详情', 'menu_path' => '/admin/refundOrderDetail/index', 'unique_auth' => 'mall-admin-refund_order-detail'],
		['id' => 92, 'pid' => 4, 'type' => 5, 'menu_name' => '售后订单备注', 'menu_path' => '/admin/refundOrderList/remark', 'unique_auth' => 'mall-admin-refund_order-remark'],
		['id' => 93, 'pid' => 4, 'type' => 5, 'menu_name' => '售后订单退款审核', 'menu_path' => '/admin/refundOrderList/refund', 'unique_auth' => 'mall-admin-refund_order-refund'],
		['id' => 100, 'pid' => 5, 'type' => 5, 'menu_name' => '用户列表', 'menu_path' => '/admin/user/list', 'unique_auth' => 'mall-admin-user-list'],
		['id' => 101, 'pid' => 5, 'type' => 5, 'menu_name' => '用户批量修改分组', 'menu_path' => '/admin/user/batch/group', 'unique_auth' => 'mall-admin-user-batch_group'],
		['id' => 102, 'pid' => 5, 'type' => 5, 'menu_name' => '用户批量添加标签', 'menu_path' => '/admin/user/batch/label', 'unique_auth' => 'mall-admin-user-batch_label'],
		['id' => 103, 'pid' => 5, 'type' => 5, 'menu_name' => '用户批量发送优惠券', 'menu_path' => '/admin/user/batch/coupon', 'unique_auth' => 'mall-admin-user-batch_coupon'],
		['id' => 104, 'pid' => 5, 'type' => 5, 'menu_name' => '用户详情', 'menu_path' => '/admin/user/index', 'unique_auth' => 'mall-admin-user-index'],
		['id' => 105, 'pid' => 5, 'type' => 5, 'menu_name' => '用户设置分组', 'menu_path' => '/admin/user/group', 'unique_auth' => 'mall-admin-user-group'],
		['id' => 106, 'pid' => 5, 'type' => 5, 'menu_name' => '用户设置等级', 'menu_path' => '/admin/user/level', 'unique_auth' => 'mall-admin-user-level'],
		['id' => 107, 'pid' => 5, 'type' => 5, 'menu_name' => '用户设置标签', 'menu_path' => '/admin/user/label', 'unique_auth' => 'mall-admin-user-label'],
		['id' => 108, 'pid' => 5, 'type' => 5, 'menu_name' => '用户修改积分', 'menu_path' => '/admin/user/integral', 'unique_auth' => 'mall-admin-user-integral'],
		['id' => 109, 'pid' => 5, 'type' => 5, 'menu_name' => '用户修改余额', 'menu_path' => '/admin/user/money', 'unique_auth' => 'mall-admin-user-moeny'],
		['id' => 110, 'pid' => 5, 'type' => 5, 'menu_name' => '用户赠送优惠券', 'menu_path' => '/admin/user/coupon', 'unique_auth' => 'mall-admin-user-coupon'],
		['id' => 111, 'pid' => 5, 'type' => 5, 'menu_name' => '用户赠送付费会员', 'menu_path' => '/admin/user/svip', 'unique_auth' => 'mall-admin-user-svip'],
		['id' => 130, 'pid' => 6, 'type' => 5, 'menu_name' => '代客下单用户列表', 'menu_path' => '/behalf/user_list/index', 'unique_auth' => 'mall-admin-behalf-user'],
		['id' => 131, 'pid' => 6, 'type' => 5, 'menu_name' => '代客下单', 'menu_path' => '/behalf/user_list/order', 'unique_auth' => 'mall-admin-behalf-order'],
	];

	/**
	 * 移动端门店中心菜单权限数据
	 * @var array[]
	 */
	protected $stockMallRules = [
		['id' => 1, 'pid' => 0, 'type' => 6, 'menu_name' => '工作台', 'menu_path' => '/admin/work/index', 'unique_auth' => 'mall-admin'],
		['id' => 2, 'pid' => 0, 'type' => 6, 'menu_name' => '商品管理', 'menu_path' => '/admin/goods/index', 'unique_auth' => 'mall-admin-product'],
		['id' => 3, 'pid' => 0, 'type' => 6, 'menu_name' => '订单管理', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-order'],
		['id' => 4, 'pid' => 0, 'type' => 6, 'menu_name' => '售后管理', 'menu_path' => '/admin/refundOrderList/index', 'unique_auth' => 'mall-admin-refund_order'],
		['id' => 5, 'pid' => 0, 'type' => 6, 'menu_name' => '用户管理', 'menu_path' => '/admin/user/list', 'unique_auth' => 'mall-admin-user'],
		['id' => 8, 'pid' => 0, 'type' => 6, 'menu_name' => '预约管理', 'menu_path' => '/admin/reservation_list/index', 'unique_auth' => 'mall-admin-reservation'],
		['id' => 9, 'pid' => 0, 'type' => 6, 'menu_name' => '订单核销', 'menu_path' => '/admin/order_cancellation/index', 'unique_auth' => 'mall-admin-order_cancellation'],
		['id' => 50, 'pid' => 1, 'type' => 6, 'menu_name' => '快捷入口', 'menu_path' => '', 'unique_auth' => 'mall-admin-home'],
		//['id' => 51, 'pid' => 1, 'type' => 6, 'menu_name' => '店铺管理', 'menu_path' => '', 'unique_auth' => 'mall-admin-index'],
		['id' => 52, 'pid' => 2, 'type' => 6, 'menu_name' => '商品列表', 'menu_path' => '/admin/goods/index', 'unique_auth' => 'mall-admin-product'],
		['id' => 53, 'pid' => 2, 'type' => 6, 'menu_name' => '商品上/下架', 'menu_path' => '/admin/goods/show', 'unique_auth' => 'mall-admin-product-show'],
		['id' => 54, 'pid' => 2, 'type' => 6, 'menu_name' => '商品修改价格/库存', 'menu_path' => '/admin/goods/edit', 'unique_auth' => 'mall-admin-product-edit_price_stock'],
		['id' => 55, 'pid' => 2, 'type' => 6, 'menu_name' => '商品修改库存', 'menu_path' => '/admin/goods/edit/stock', 'unique_auth' => 'mall-admin-product-edit-stock'],
		['id' => 56, 'pid' => 2, 'type' => 6, 'menu_name' => '商品修改分类', 'menu_path' => '/admin/goods/edit/category', 'unique_auth' => 'mall-admin-product-edit-category'],
		['id' => 57, 'pid' => 2, 'type' => 6, 'menu_name' => '商品修改标签', 'menu_path' => '/admin/goods/edit/label', 'unique_auth' => 'mall-admin-product-edit-label'],
		['id' => 70, 'pid' => 3, 'type' => 6, 'menu_name' => '订单列表', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-order'],
		['id' => 71, 'pid' => 3, 'type' => 6, 'menu_name' => '订单详情', 'menu_path' => '/admin/orderDetail/index', 'unique_auth' => 'mall-admin-order-detail'],
		['id' => 72, 'pid' => 3, 'type' => 6, 'menu_name' => '订单备注', 'menu_path' => '/admin/orderList/remark', 'unique_auth' => 'mall-admin-order-remark'],
		['id' => 73, 'pid' => 3, 'type' => 6, 'menu_name' => '订单改价', 'menu_path' => '/admin/orderList/changePrice', 'unique_auth' => 'mall-admin-order-change_price'],
		['id' => 74, 'pid' => 3, 'type' => 6, 'menu_name' => '订单确认收款', 'menu_path' => '/admin/orderList/offline', 'unique_auth' => 'mall-admin-order-offline'],
		['id' => 75, 'pid' => 3, 'type' => 6, 'menu_name' => '订单发货', 'menu_path' => '/admin/orderList/delivery', 'unique_auth' => 'mall-admin-order-delivery'],
		['id' => 76, 'pid' => 3, 'type' => 6, 'menu_name' => '订单核销', 'menu_path' => '/admin/orderList/writeoff', 'unique_auth' => 'mall-admin-order-writeoff'],
		['id' => 77, 'pid' => 3, 'type' => 6, 'menu_name' => '订单退款', 'menu_path' => '/admin/orderList/refund', 'unique_auth' => 'mall-admin-order-refund'],
		['id' => 78, 'pid' => 3, 'type' => 6, 'menu_name' => '确认收货', 'menu_path' => '/admin/orderList/take', 'unique_auth' => 'mall-admin-order-take'],
        ['id' => 79, 'pid' => 3, 'type' => 6, 'menu_name' => '订单派单', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-delivery-keep'],
        ['id' => 80, 'pid' => 3, 'type' => 6, 'menu_name' => '订单改单', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-reassign-delivery'],
        ['id' => 81, 'pid' => 3, 'type' => 6, 'menu_name' => '确认送达', 'menu_path' => '/admin/orderList/index', 'unique_auth' => 'mall-admin-confirm-delivery'],
        ['id' => 90, 'pid' => 4, 'type' => 6, 'menu_name' => '售后订单列表', 'menu_path' => '/admin/refundOrderList/index', 'unique_auth' => 'mall-admin-refund_order-index'],
		['id' => 91, 'pid' => 4, 'type' => 6, 'menu_name' => '售后订单详情', 'menu_path' => '/admin/refundOrderDetail/index', 'unique_auth' => 'mall-admin-refund_order-detail'],
		['id' => 92, 'pid' => 4, 'type' => 6, 'menu_name' => '售后订单备注', 'menu_path' => '/admin/refundOrderList/remark', 'unique_auth' => 'mall-admin-refund_order-remark'],
		['id' => 93, 'pid' => 4, 'type' => 6, 'menu_name' => '售后订单退款审核', 'menu_path' => '/admin/refundOrderList/refund', 'unique_auth' => 'mall-admin-refund_order-refund'],
		['id' => 100, 'pid' => 5, 'type' => 6, 'menu_name' => '用户列表', 'menu_path' => '/admin/user/list', 'unique_auth' => 'mall-admin-user-list'],
		['id' => 104, 'pid' => 5, 'type' => 6, 'menu_name' => '用户详情', 'menu_path' => '/admin/user/index', 'unique_auth' => 'mall-admin-user-index'],
		['id' => 150, 'pid' => 8, 'type' => 6, 'menu_name' => '预约列表', 'menu_path' => '/admin/reservation_list/index', 'unique_auth' => 'mall-admin-reservation-list'],
		['id' => 151, 'pid' => 8, 'type' => 6, 'menu_name' => '预约详情', 'menu_path' => '/admin/reservation_details/index', 'unique_auth' => 'mall-admin-reservation-detail'],
		['id' => 152, 'pid' => 8, 'type' => 6, 'menu_name' => '预约开始服务', 'menu_path' => '/admin/reservation_list/start', 'unique_auth' => 'mall-admin-reservation-start'],
		['id' => 153, 'pid' => 8, 'type' => 6, 'menu_name' => '预约结束服务', 'menu_path' => '/admin/reservation_list/end', 'unique_auth' => 'mall-admin-reservation-end'],
		['id' => 170, 'pid' => 9, 'type' => 6, 'menu_name' => '扫码核销', 'menu_path' => '/admin/order_cancellation/index', 'unique_auth' => 'mall-admin-order_cancellation'],
	];

    /**
     * 初始化
     * SystemMenusServices constructor.
     * @param SystemMenusDao $dao
     */
    public function __construct(SystemMenusDao $dao)
    {
        $this->dao = $dao;
    }

	/**
	 * 获取移动端菜单权限
	 * @param int $type
	 * @return array|array[]
	 */
	public function getMallMenus(int $type = 0)
	{
		if ($type == 1) {//门店，无待客下单
			$result = $this->stockMallRules;
		} else {
			$result = $this->mallRules;
		}
		return $result;
	}

    /**
     * 获取菜单没有被修改器修改的数据
     * @param $menusList
     * @return array
     */
    public function getMenusData($menusList, int $type = 1)
    {
        $data = [];
        foreach ($menusList as $item) {
//            $item['expand'] = true;
            $item['selected'] = false;
            $item['title'] = $item['menu_name'];
			$item['menu_path'] = preg_replace('/^\/' . ($this->type[$type] ?? 'admin') . '/', '', $item['menu_path']);
            $data[] = $item->getData();
        }
        return $data;
    }

	/**
	 * 获取菜单和权限
	 * @param $roleId
	 * @param int $level
	 * @param int $type
	 * @param int $adminType
	 * @return array
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function getMenusList($roleId, int $level, int $type = 1, int $adminType = 0)
    {
		$rulesStr = '';
		if ($level || $adminType == 3) {//不是超级管理员 || 是区域代理商
			/** @var SystemRoleServices $systemRoleServices */
			$systemRoleServices = app()->make(SystemRoleServices::class);
			$rules = $systemRoleServices->getRoleArray(['status' => 1, 'id' => $roleId], $type == 3 ? 'cashier_rules' : 'rules');
			$rulesStr = Arr::unique($rules);
		}
        $menusList = $this->dao->getMenusRoule(['auth_type' => 1, 'is_show' => 1, 'is_del' => 0, 'type' => $type, 'route' => $rulesStr]);
        $unique = $this->dao->getMenusUnique(['type' => $type, 'unique' => $rulesStr]);
        return [Arr::getMenuIviewList($this->getMenusData($menusList, $type)), $unique];
    }

    /**
     * 获取后台菜单树型结构列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where)
    {
        $menusList = $this->dao->getMenusList($where);
        $menusList = $this->getMenusData($menusList);
        return get_tree_children($menusList);
    }

    /**
     * 获取form表单所需要的所要的菜单列表
     * @return array[]
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    protected function getFormSelectMenus()
    {
        $menuList = $this->dao->getMenusRoule(['is_del' => 0], ['id', 'pid', 'menu_name']);
        $list = get_tree_children($this->getMenusData($menuList), '0', 'pid', 'id');
        $menus = [['value' => 0, 'label' => '顶级按钮']];
        foreach ($list as $menu) {
            $menus[] = ['value' => $menu['id'], 'label' => $menu['html'] . $menu['menu_name']];
        }
        return $menus;
    }

    /**
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    protected function getFormCascaderMenus(int $value = 0, int $type = 1)
    {
        $menuList = $this->dao->getMenusRoule(['is_del' => 0, 'type' => $type], ['id as value', 'pid', 'menu_name as label']);
        $menuList = $this->getMenusData($menuList);
        if ($value) {
            $data = get_tree_value($menuList, $value);
        } else {
            $data = [];
        }
        return [get_tree_children($menuList, 'children', 'value'), array_reverse($data)];
    }

	/**
	 * 创建权限规格生表单
	 * @param array $formData
	 * @param int $type
	 * @return array
	 */
    public function createMenusForm(array $formData = [], int $type = 1)
    {
        $field[] = Form::hidden('type', $type);
        $field[] = Form::input('menu_name', '按钮名称：', $formData['menu_name'] ?? '')->required('按钮名称必填');
        // $field[] = Form::select('pid', '父级id', $formData['pid'] ?? 0)->setOptions($this->getFormSelectMenus())->filterable(1);
        $field[] = Form::input('menu_path', '路由名称：', $formData['menu_path'] ?? '')->placeholder('请输入前台跳转路由地址')->required('请填写前台路由地址');
        $field[] = Form::input('unique_auth', '权限标识：', $formData['unique_auth'] ?? '')->placeholder('不填写则后台自动生成');
        $params = $formData['params'] ?? '';
        $field[] = Form::input('params', '参数：', is_array($params) ? '' : $params)->placeholder('举例:a/123/b/234');
        $field[] = Form::frameInput('icon', '图标：', $this->url(config('admin.admin_prefix') . '/widget.widgets/icon', ['fodder' => 'icon']), $formData['icon'] ?? '')->icon('md-add')->height('500px');
        $field[] = Form::number('sort', '排序：', (int)($formData['sort'] ?? 0))->min(0);
        $field[] = Form::radio('auth_type', '类型：', $formData['auth_type'] ?? 1)->options([['value' => 2, 'label' => '接口'], ['value' => 1, 'label' => '菜单(菜单只显示三级)']]);
        $field[] = Form::radio('is_show', '状态：', $formData['is_show'] ?? 1)->options([['value' => 0, 'label' => '关闭'], ['value' => 1, 'label' => '开启']]);
        $field[] = Form::radio('is_show_path', '是否为前端隐藏菜单：', $formData['is_show_path'] ?? 0)->options([['value' => 1, 'label' => '是'], ['value' => 0, 'label' => '否']]);
        [$menuList, $data] = $this->getFormCascaderMenus((int)($formData['pid'] ?? 0), $type);
        $field[] = Form::cascader('menu_list', '父级id：', $data)->data($menuList)->filterable(true);
        return $field;
    }

	/**
	 * 新增权限表单
	 * @param int $type
	 * @return mixed
	 */
    public function createMenus(int $type = 1)
    {
        return create_form('添加权限', $this->createMenusForm([], $type), $this->url('/setting/save'));
    }

	/**
	 * 修改权限菜单
	 * @param int $id
	 * @return mixed
	 */
    public function updateMenus(int $id)
    {
        $menusInfo = $this->dao->get($id);
        if (!$menusInfo) {
            throw new AdminException('数据不存在');
        }
        $menusInfo = $menusInfo->getData();
        return create_form('修改权限', $this->createMenusForm($menusInfo, $menusInfo['type'] ?? 1), $this->url('/setting/update/' . $id), 'PUT');
    }

    /**
     * 获取一条数据
     * @param int $id
     * @return mixed
     */
    public function find(int $id)
    {
        $menusInfo = $this->dao->get($id);
        if (!$menusInfo) {
            throw new AdminException('数据不存在');
        }
        $menu = $menusInfo->getData();
        $menu['pid'] = (int)$menu['pid'];
        $menu['auth_type'] = (int)$menu['auth_type'];
        $menu['is_header'] = (int)$menu['is_header'];
        $menu['is_show'] = (int)$menu['is_show'];
        $menu['is_show_path'] = (int)$menu['is_show_path'];
        if (!$menu['path']) {
            [$menuList, $data] = $this->getFormCascaderMenus($menu['pid']);
            $menu['path'] = $data;
        } else {
            $menu['path'] = explode('/', $menu['path']);
            if (is_array($menu['path'])) {
                $menu['path'] = array_map(function ($item) {
                    return (int)$item;
                }, $menu['path']);
            }
        }
        return $menu;
    }

    /**
     * 删除菜单
     * @param int $id
     * @return mixed
     */
    public function delete(int $id)
    {
        if ($this->dao->count(['pid' => $id])) {
            throw new AdminException('请先删除改菜单下的子菜单');
        }
        return $this->dao->delete($id);
    }

    /**
     * 获取添加身份规格
     * @param $roles
     * @param int $type
     * @param int $is_show
     * @return array
     */
    public function getMenus($roles, int $type = 1, int $is_show = 1): array
    {
		if ($type == 5) {//移动端
			$menus = $this->getMallMenus();
		} else {
			$field = ['menu_name', 'pid', 'id', 'pid as parent_id'];
			$where = ['is_del' => 0, 'type' => $type];
			if ($is_show) $where['is_show'] = 1;
			if (!$roles) {
				$menus = $this->dao->getMenusRoule($where, $field);
			} else {
				/** @var SystemRoleServices $service */
				$service = app()->make(SystemRoleServices::class);
				//拼接有长度限制
//            $ids = $service->value([['id', 'in', $roles]], 'GROUP_CONCAT(rules) as ids');
				$roles = is_string($roles) ? explode(',', $roles) : $roles;
				$ids = $service->getRoleIds($roles);
				$menus = $this->dao->getMenusRoule(['rule' => $ids] + $where, $field);
			}
			$menus = $menus ? $menus->toArray() : [];
		}
        return $this->tidyMenuTier(false, $menus);
    }

    /**
     * 组合菜单数据
     * @param bool $adminFilter
     * @param array $menusList
     * @param int $pid
     * @param array $navList
     * @return array
     */
    public function tidyMenuTier(bool $adminFilter = false, array $menusList = [], int $pid = 0, array $navList = []): array
    {
        foreach ($menusList as $k => $menu) {
            $menu['title'] = $menu['menu_name'];
			$menu['pid'] = $menu['parent_id'] ?? $menu['pid'] ?? 0;
            unset($menu['menu_name'], $menu['parent_id']);
            if ($menu['pid'] == $pid) {
                unset($menusList[$k]);
                $menu['children'] = $this->tidyMenuTier($adminFilter, $menusList, $menu['id']);
                if ($pid == 0 && !count($menu['children'])) continue;
                if ($menu['children']) $menu['expand'] = true;
                $navList[] = $menu;
            }
        }
        return $navList;
    }
}
