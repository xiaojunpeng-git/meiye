<?php
namespace app\services\yeji;

use app\dao\yeji\YejiRangeDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\services\FormBuilder as Form;
use think\exception\ValidateException;
use think\facade\Db;


class YejiRangeServices extends BaseServices
{
    public function __construct(YejiRangeDao $dao)
    {
        $this->dao = $dao;
    }
    public function edit($id=0,$type = 0)
    {
        if(!empty($id)) {
            $serviceInfo = $this->dao->get($id)->toArray();
        }else{
            $serviceInfo=[];
        }
        return create_form('编辑区间', $this->createServiceForm($serviceInfo, $type), $this->url('/order/saveRange/' . $id), 'POST');
    }

    public function saveRange($data,$id){
        if ($id) {//修改
            $delivery = $this->dao->get($id);
            if (!$delivery) {
                throw new ValidateException('数据不存在');
            }
            $res = $this->dao->update($id, $data);
        } else {//添加
            $res = $this->dao->save($data);
        }
        return $res->id;
    }
    /**
     * 创建配送员表单
     * @param array $formData
     * @param int $type
     * @return array
     */
    public function createServiceForm(array $formData = [], int $type = 0)
    {
        $field=[];
        $field[] = Form::number('yeji_min', '范围区间-最少：', $formData['yeji_min'] ?? '')->style(['width' => '100%'])->required('范围区间（最少）')->col(24);
        $field[] = Form::number('yeji_max', '范围区间-最大-：', $formData['yeji_max'] ?? '')->style(['width' => '100%'])->required('范围区间（最大）')->col(24);
        return $field;
    }
    public function getList(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, $page, $limit);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {

        }
        return compact('count', 'list');
    }

}
