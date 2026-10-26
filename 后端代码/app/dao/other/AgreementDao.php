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

namespace app\dao\other;


use app\dao\BaseDao;
use app\model\other\Agreement;

/**
 * Class AgreementDao
 * @package app\dao\other
 */
class AgreementDao extends BaseDao
{

    /**
     * @return string
     */
    public function setModel(): string
    {
        return Agreement::class;
    }

	/**
	 * 修改协议内容
	 * @param array $agreement
	 * @param int $id
	 * @return bool|\mohe\basic\BaseModel
	 */
    public function saveAgreement(array $agreement, int $id = 0)
    {
        if (!$agreement) return false;
        $agreement['add_time'] = time();
        if($id){
            return $this->getModel()->update($agreement,['id' => $id]);
        }
        return $this->getModel()->save($agreement);
    }

}
