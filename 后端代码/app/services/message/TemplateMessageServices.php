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
namespace app\services\message;


use app\dao\message\TemplateMessageDao;
use app\services\BaseServices;

/**
 * 模板消息
 * Class TemplateMessageServices
 * @package app\services\other
 * @mixin TemplateMessageDao
 */
class TemplateMessageServices extends BaseServices
{
    /**
     * 模板消息
     * TemplateMessageServices constructor.
     * @param TemplateMessageDao $dao
     */
    public function __construct(TemplateMessageDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取模板消息列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getTemplateList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getTemplateList($where, $page, $limit);
        foreach ($list as &$item) {
            if ($item['content']) $item['content'] = explode("\n", $item['content']);
        }
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }

	/**
	 * 根据消息ID获取tempid
	 * @param int $notification_id
	 * @param int $type
	 * @return array|mixed
	 */
	public function getTempIdByNotificationId(int $notification_id, int $type = 0)
	{
		return $this->dao->value(['type' => $type, 'notification_id' => $notification_id, 'status' => 1], 'tempid', true);
	}

    /**
     * 获取模板消息id
     * @param string $templateId
     * @param int $type
     * @return mixed
     */
    public function getTempId(string $templateId, int $type = 0)
    {
        return $this->dao->value(['type' => $type, 'tempkey' => $templateId, 'status' => 1], 'tempid');
    }
}
