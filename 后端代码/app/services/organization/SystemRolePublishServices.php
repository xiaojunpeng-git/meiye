<?php
namespace app\services\organization;

use app\services\BaseServices;
use app\services\system\SystemMenusServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use think\facade\Db;

/**
 * 总部门店角色模板 + 门店发布
 * 模板：system_role type=1 relation_id=0（不含平台后台权限）
 * 门店角色：type=1 relation_id=store_id
 * 写路径：OrganizationStrictIdempotencyServices
 */
class SystemRolePublishServices extends BaseServices
{
    public const CHANNEL_STORE_BACKEND = 'store_backend';
    public const CHANNEL_CASHIER = 'cashier';

    public function listTemplates(array $where = []): array
    {
        $q = Db::name('system_role')->where('type', 1)->where('relation_id', 0);
        if (isset($where['status']) && $where['status'] !== '' && $where['status'] !== null) {
            $q->where('status', (int)$where['status']);
        }
        $keyword = trim((string)($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $q->whereLike('role_name', '%' . $keyword . '%');
        }
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = min(50, max(1, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $q)->count();
        $list = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $ids = array_values(array_filter(array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $list)));
        $countMap = [];
        if ($ids) {
            $rows = Db::name('system_role_store_publish')
                ->whereIn('template_role_id', $ids)
                ->where('status', 1)
                ->field('template_role_id, COUNT(*) AS publish_count')
                ->group('template_role_id')
                ->select()->toArray();
            foreach ($rows as $r) {
                $countMap[(int)$r['template_role_id']] = (int)$r['publish_count'];
            }
        }
        foreach ($list as &$row) {
            $row = $this->presentTemplate($row);
            $row['publish_count'] = $countMap[(int)$row['id']] ?? 0;
        }
        unset($row);
        return compact('count', 'list');
    }

    public function getTemplateDetail(int $id): array
    {
        if ($id <= 0) {
            throw new AdminException('请指定模板');
        }
        $row = Db::name('system_role')->where('id', $id)->find();
        if (!$row || (int)$row['type'] !== 1 || (int)$row['relation_id'] !== 0) {
            throw new AdminException('总部门店角色模板不存在');
        }
        $detail = $this->presentTemplate($row);
        $detail['rules_ids'] = $this->rulesToIds((string)($row['rules'] ?? ''));
        $detail['cashier_rules_ids'] = $this->rulesToIds((string)($row['cashier_rules'] ?? ''));
        $detail['mall_rules_ids'] = $this->rulesToIds((string)($row['mall_rules'] ?? ''));
        $detail['help'] = [
            'summary' => '岗位决定能操作哪些功能，人员数据权限决定能看到哪些数据。',
            'scope' => '本模板只配置门店后台、收银台、手机端功能；不能配置平台后台权限。',
            'data_scope' => '角色模板本身不能决定员工数据范围；数据权限仍按个人单独设置。',
        ];
        return $detail;
    }

    /**
     * 三端功能菜单树（不含平台后台）
     */
    public function getTemplateMenus(): array
    {
        /** @var SystemMenusServices $menus */
        $menus = app()->make(SystemMenusServices::class);
        $storeMenus = $menus->getList(['type' => 2, 'is_del' => 0]);
        $cashierMenus = $menus->getList(['type' => 3, 'is_del' => 0]);
        $mallMenus = $menus->tidyMenuTier(false, $menus->getMallMenus(1));
        return [
            'store_menus' => $storeMenus,
            'cashier_menus' => $cashierMenus,
            'mall_menus' => $mallMenus,
            'help' => '岗位决定能操作哪些功能，人员数据权限决定能看到哪些数据。本页只配置门店后台、收银台、手机端，不含平台后台。',
        ];
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function saveTemplate(array $data, array $adminInfo, array $requestCtx): array
    {
        $id = (int)($data['id'] ?? 0);
        $roleName = trim((string)($data['role_name'] ?? $data['name'] ?? ''));
        $remark = trim((string)($data['remark'] ?? ''));
        $status = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        $allowSelect = (int)($data['allow_store_select'] ?? 0) === 1 ? 1 : 0;
        $rules = $this->normalizeRulesInput($data['rules'] ?? $data['checked_menus'] ?? []);
        $cashierRules = $this->normalizeRulesInput($data['cashier_rules'] ?? $data['checked_cashier_menus'] ?? []);
        $mallRules = $this->normalizeRulesInput($data['mall_rules'] ?? $data['checked_mall_menus'] ?? []);

        $payload = [
            'id' => $id,
            'role_name' => $roleName,
            'remark' => $remark,
            'status' => $status,
            'allow_store_select' => $allowSelect,
            'rules' => $rules,
            'cashier_rules' => $cashierRules,
            'mall_rules' => $mallRules,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'role_template_save',
            'role_tpl_save:' . ($id > 0 ? (string)$id : ('new:' . md5($roleName))),
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($id, $roleName, $remark, $status, $allowSelect, $rules, $cashierRules, $mallRules, $adminInfo) {
                if ($roleName === '') {
                    throw new AdminException('请填写模板名称');
                }
                if (mb_strlen($roleName) > 32) {
                    throw new AdminException('模板名称不能超过32个字');
                }
                if (mb_strlen($remark) > 500) {
                    throw new AdminException('模板说明不能超过500个字');
                }
                if ($rules === '' && $cashierRules === '' && $mallRules === '') {
                    throw new AdminException('请至少配置门店后台、收银台或手机端其中一端功能权限');
                }
                $this->assertStoreChannelMenuIds($rules, 2, '门店后台');
                $this->assertStoreChannelMenuIds($cashierRules, 3, '收银台');
                $this->assertMallMenuIds($mallRules);

                $now = time();
                if ($id > 0) {
                    $exist = Db::name('system_role')->where('id', $id)->lock(true)->find();
                    if (!$exist || (int)$exist['type'] !== 1 || (int)$exist['relation_id'] !== 0) {
                        throw new AdminException('只能编辑总部门店角色模板');
                    }
                    // 禁止用平台角色冒充
                    if ((int)$exist['type'] === 0 || (int)$exist['type'] === 4) {
                        throw new AdminException('身份管理中的平台角色和普通移动端角色不能冒充总部门店角色模板');
                    }
                    Db::name('system_role')->where('id', $id)->update([
                        'role_name' => $roleName,
                        'remark' => $remark,
                        'rules' => $rules,
                        'cashier_rules' => $cashierRules,
                        'mall_rules' => $mallRules,
                        'status' => $status,
                        'allow_store_select' => $allowSelect,
                    ]);
                    $tplId = $id;
                    $msg = '模板已更新';
                } else {
                    $tplId = (int)Db::name('system_role')->insertGetId([
                        'type' => 1,
                        'relation_id' => 0,
                        'role_name' => $roleName,
                        'remark' => $remark,
                        'rules' => $rules,
                        'cashier_rules' => $cashierRules,
                        'mall_rules' => $mallRules,
                        'level' => 1,
                        'status' => $status,
                        'allow_store_select' => $allowSelect,
                        'add_time' => $now,
                    ]);
                    $msg = '模板已创建';
                }

                // 停用模板时：禁止门店新增选择，但不删除已绑定人员
                if ($status === 0) {
                    Db::name('system_role_store_publish')
                        ->where('template_role_id', $tplId)
                        ->where('status', 1)
                        ->update([
                            'allow_store_select' => 0,
                            'update_time' => $now,
                        ]);
                }

                try {
                    CacheService::redisHandler('system_menus')->clear();
                } catch (\Throwable $e) {
                    // ignore cache clear failure
                }

                $this->audit(0, 'role_template_save', $tplId, [
                    'role_name' => $roleName,
                    'status' => $status,
                    'allow_store_select' => $allowSelect,
                    'has_rules' => $rules !== '',
                    'has_cashier_rules' => $cashierRules !== '',
                    'has_mall_rules' => $mallRules !== '',
                ], $adminInfo, $auditMeta, $id > 0 ? '编辑总部门店角色模板' : '新建总部门店角色模板');

                return ['msg' => $msg, 'data' => $this->getTemplateDetail($tplId)];
            }
        );
    }

    /**
     * 停用模板（不自动删除已绑定人员）
     * @return array{msg:string,data:array,replay:bool}
     */
    public function disableTemplate(int $id, array $data, array $adminInfo, array $requestCtx): array
    {
        $payload = ['id' => $id, 'action' => 'disable_template'];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'role_template_disable',
            'role_tpl_disable:' . $id,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($id, $adminInfo) {
                if ($id <= 0) {
                    throw new AdminException('请指定模板');
                }
                $row = Db::name('system_role')->where('id', $id)->lock(true)->find();
                if (!$row || (int)$row['type'] !== 1 || (int)$row['relation_id'] !== 0) {
                    throw new AdminException('总部门店角色模板不存在');
                }
                $now = time();
                Db::name('system_role')->where('id', $id)->update([
                    'status' => 0,
                    'allow_store_select' => 0,
                ]);
                // 仅关闭门店新增选择；不删发布关系、不删门店角色、不清人员绑定
                Db::name('system_role_store_publish')
                    ->where('template_role_id', $id)
                    ->where('status', 1)
                    ->update([
                        'allow_store_select' => 0,
                        'update_time' => $now,
                    ]);
                $this->audit(0, 'role_template_disable', $id, [
                    'status' => 0,
                    'allow_store_select' => 0,
                ], $adminInfo, $auditMeta, '停用总部门店角色模板');
                return ['msg' => '模板已停用（已绑定人员不会自动删除）', 'data' => $this->getTemplateDetail($id)];
            }
        );
    }

    public function listPublishes(int $templateRoleId = 0, int $storeId = 0): array
    {
        $q = Db::name('system_role_store_publish')->alias('p')
            ->leftJoin('system_role t', 't.id = p.template_role_id')
            ->leftJoin('system_role s', 's.id = p.store_role_id')
            ->leftJoin('system_store st', 'st.id = p.store_id')
            ->field('p.*,t.role_name AS template_role_name,t.status AS template_status,s.role_name AS store_role_name,st.name AS store_name');
        if ($templateRoleId > 0) {
            $q->where('p.template_role_id', $templateRoleId);
        }
        if ($storeId > 0) {
            $q->where('p.store_id', $storeId);
        }
        return $q->order('p.id', 'desc')->limit(200)->select()->toArray();
    }

    /**
     * 门店可选：已发布 + 模板启用 + allow_store_select + store_backend
     * 统一模板可同时含门店后台/收银/手机规则
     */
    public function listStoreSelectableRoles(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $rows = Db::name('system_role_store_publish')->alias('p')
            ->join('system_role r', 'r.id = p.store_role_id')
            ->join('system_role t', 't.id = p.template_role_id')
            ->where('p.store_id', $storeId)
            ->where('p.channel', self::CHANNEL_STORE_BACKEND)
            ->where('p.status', 1)
            ->where('p.allow_store_select', 1)
            ->where('r.status', 1)
            ->where('r.type', 1)
            ->where('r.relation_id', $storeId)
            ->where('t.type', 1)
            ->where('t.relation_id', 0)
            ->where('t.status', 1)
            ->field('r.id,r.role_name,r.rules,r.cashier_rules,r.mall_rules,r.remark,p.id AS publish_id,t.id AS template_role_id')
            ->select()->toArray();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'label' => (string)$row['role_name'],
                'value' => (int)$row['id'],
                'publish_id' => (int)$row['publish_id'],
                'template_role_id' => (int)$row['template_role_id'],
                'remark' => (string)($row['remark'] ?? ''),
                'has_store_rules' => trim((string)($row['rules'] ?? '')) !== '',
                'has_cashier_rules' => trim((string)($row['cashier_rules'] ?? '')) !== '',
                'has_mall_rules' => trim((string)($row['mall_rules'] ?? '')) !== '',
            ];
        }
        return $out;
    }

    /**
     * 门店可选收银角色：cashier 通道，或 store_backend 统一模板含收银规则
     */
    public function listCashierSelectableRoles(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        $rows = Db::name('system_role_store_publish')->alias('p')
            ->join('system_role r', 'r.id = p.store_role_id')
            ->join('system_role t', 't.id = p.template_role_id')
            ->where('p.store_id', $storeId)
            ->whereIn('p.channel', [self::CHANNEL_STORE_BACKEND, self::CHANNEL_CASHIER])
            ->where('p.status', 1)
            ->where('p.allow_store_select', 1)
            ->where('r.status', 1)
            ->where('r.type', 1)
            ->where('r.relation_id', $storeId)
            ->where('t.status', 1)
            ->where('t.type', 1)
            ->where('t.relation_id', 0)
            ->field('r.id,r.role_name,r.cashier_rules,p.id AS publish_id,p.channel')
            ->select()->toArray();
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (trim((string)($row['cashier_rules'] ?? '')) === '') {
                continue;
            }
            $rid = (int)$row['id'];
            if (isset($seen[$rid])) {
                continue;
            }
            $seen[$rid] = true;
            $out[] = [
                'label' => (string)$row['role_name'],
                'value' => $rid,
                'publish_id' => (int)$row['publish_id'],
            ];
        }
        return $out;
    }

    public function assertStoreSelectableRoleIds(array $roleIds, int $storeId): void
    {
        if (!$roleIds) {
            return;
        }
        $allowed = $this->listStoreSelectableRoles($storeId);
        $allowedIds = array_column($allowed, 'value');
        foreach ($roleIds as $rid) {
            $rid = (int)$rid;
            if ($rid <= 0) {
                continue;
            }
            // 明确拒绝平台角色
            $role = Db::name('system_role')->where('id', $rid)->find();
            if ($role && in_array((int)$role['type'], [0, 4], true)) {
                throw new AdminException('门店不能选择或绑定平台后台权限');
            }
            if (!in_array($rid, $allowedIds, true)) {
                throw new AdminException('只能选择总部已发布且允许本店使用的角色模板；未发布、已停用或不允许门店选择的模板不能使用');
            }
        }
    }

    /**
     * 已收口：人员功能权限只通过岗位投影，禁止门店单独绑定角色模板。
     * @param int[] $roleIds
     * @return array{msg:string,data:array,replay:bool}
     */
    public function bindStaffPublishedRoles(
        int $staffId,
        int $storeId,
        array $roleIds,
        array $adminInfo,
        array $requestCtx,
        bool $requireSuperAdmin = false
    ): array {
        throw new AdminException('人员功能权限只通过岗位配置，不能再单独选择角色模板');
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function publish(array $data, array $adminInfo, array $requestCtx): array
    {
        $templateRoleId = (int)($data['template_role_id'] ?? 0);
        $channel = trim((string)($data['channel'] ?? self::CHANNEL_STORE_BACKEND));
        if ($channel === '') {
            $channel = self::CHANNEL_STORE_BACKEND;
        }
        $storeIds = $this->resolvePublishStoreIds($data);
        $allowSelectIn = $data['allow_store_select'] ?? null;
        $payload = [
            'template_role_id' => $templateRoleId,
            'store_ids' => $storeIds,
            'channel' => $channel,
            'allow_store_select' => $allowSelectIn === null ? null : ((int)$allowSelectIn === 1 ? 1 : 0),
            'org_id' => (int)($data['org_id'] ?? 0),
            'store_id' => (int)($data['store_id'] ?? 0),
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'role_template_publish',
            'role_tpl:' . $templateRoleId . ':stores:' . implode(',', $storeIds) . ':' . $channel,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($templateRoleId, $storeIds, $channel, $allowSelectIn, $adminInfo) {
                if (!in_array($channel, [self::CHANNEL_STORE_BACKEND, self::CHANNEL_CASHIER], true)) {
                    throw new AdminException('发布通道无效');
                }
                if ($templateRoleId <= 0) {
                    throw new AdminException('请选择总部门店角色模板');
                }
                if (!$storeIds) {
                    throw new AdminException('请选择要发布的组织或门店');
                }

                $tpl = Db::name('system_role')->where('id', $templateRoleId)->lock(true)->find();
                if (!$tpl || (int)$tpl['type'] !== 1 || (int)$tpl['relation_id'] !== 0) {
                    throw new AdminException('请选择有效的总部门店角色模板（不能使用平台角色或普通移动端角色）');
                }
                if ((int)($tpl['status'] ?? 0) !== 1) {
                    throw new AdminException('角色模板已停用，不能发布');
                }
                if ($channel === self::CHANNEL_CASHIER && trim((string)($tpl['cashier_rules'] ?? '')) === '') {
                    throw new AdminException('收银通道发布须选择含收银规则的模板');
                }

                $allowSelect = $allowSelectIn === null
                    ? ((int)($tpl['allow_store_select'] ?? 0) === 1 ? 1 : 0)
                    : ((int)$allowSelectIn === 1 ? 1 : 0);

                $results = [];
                foreach ($storeIds as $storeId) {
                    $results[] = $this->publishOneStore(
                        $tpl,
                        (int)$storeId,
                        $channel,
                        $allowSelect,
                        $adminInfo,
                        $auditMeta
                    );
                }

                return [
                    'msg' => '发布成功',
                    'data' => [
                        'template_role_id' => $templateRoleId,
                        'channel' => $channel,
                        'allow_store_select' => $allowSelect,
                        'store_count' => count($results),
                        'publishes' => $results,
                    ],
                ];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function disable(int $publishId, array $data, array $adminInfo, array $requestCtx): array
    {
        $payload = [
            'publish_id' => $publishId,
            'action' => 'disable',
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'role_template_publish_disable',
            'role_publish:' . $publishId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($publishId, $adminInfo) {
                $row = Db::name('system_role_store_publish')->where('id', $publishId)->lock(true)->find();
                if (!$row) {
                    throw new AdminException('发布记录不存在');
                }
                Db::name('system_role_store_publish')->where('id', $publishId)->update([
                    'status' => 0,
                    'allow_store_select' => 0,
                    'update_time' => time(),
                    'operator_id' => (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0),
                    'operator_name' => (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? ''),
                ]);
                $this->audit(0, 'role_template_publish_disable', $publishId, [
                    'status' => 0,
                ], $adminInfo, $auditMeta, '停用角色发布');
                $row['status'] = 0;
                $row['allow_store_select'] = 0;
                return ['msg' => '已停用', 'data' => $this->presentPublish($row)];
            }
        );
    }

    /**
     * @param array<string,mixed> $tpl
     * @return array<string,mixed>
     */
    protected function publishOneStore(
        array $tpl,
        int $storeId,
        string $channel,
        int $allowSelect,
        array $adminInfo,
        array $auditMeta
    ): array {
        $templateRoleId = (int)$tpl['id'];
        $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->find();
        if (!$store || (int)($store['is_show'] ?? 0) !== 1) {
            throw new AdminException('目标门店不存在或未启用（门店#' . $storeId . '）');
        }

        $now = time();
        $exist = Db::name('system_role_store_publish')
            ->where('template_role_id', $templateRoleId)
            ->where('store_id', $storeId)
            ->where('channel', $channel)
            ->lock(true)->find();

        $storeRoleId = $exist ? (int)$exist['store_role_id'] : 0;
        // 统一模板：门店后台通道复制三端规则；收银通道只写 cashier_rules
        if ($channel === self::CHANNEL_CASHIER) {
            $rules = '';
            $cashierRules = (string)($tpl['cashier_rules'] ?? '');
            $mallRules = '';
        } else {
            $rules = (string)($tpl['rules'] ?? '');
            $cashierRules = (string)($tpl['cashier_rules'] ?? '');
            $mallRules = (string)($tpl['mall_rules'] ?? '');
        }

        $rolePayload = [
            'role_name' => (string)$tpl['role_name'],
            'rules' => $rules,
            'cashier_rules' => $cashierRules,
            'mall_rules' => $mallRules,
            'status' => 1,
        ];
        if ($this->hasRoleColumn('remark')) {
            $rolePayload['remark'] = (string)($tpl['remark'] ?? '');
        }

        if ($storeRoleId > 0) {
            Db::name('system_role')->where('id', $storeRoleId)->update($rolePayload);
        } else {
            $insert = $rolePayload + [
                'type' => 1,
                'relation_id' => $storeId,
                'level' => (int)($tpl['level'] ?? 1),
                'add_time' => $now,
            ];
            if ($this->hasRoleColumn('allow_store_select')) {
                $insert['allow_store_select'] = 0;
            }
            $storeRoleId = (int)Db::name('system_role')->insertGetId($insert);
        }

        $token = (string)($auditMeta['request_token'] ?? '');
        $rowToken = $token !== '' ? ($token . '#s' . $storeId . '#' . $channel) : '';
        if (strlen($rowToken) > 64) {
            $rowToken = substr(hash('sha256', $rowToken), 0, 64);
        }
        if ($exist) {
            Db::name('system_role_store_publish')->where('id', (int)$exist['id'])->update([
                'store_role_id' => $storeRoleId,
                'allow_store_select' => $allowSelect,
                'status' => 1,
                'request_token' => $rowToken !== '' ? $rowToken : (string)($exist['request_token'] ?? ''),
                'operator_id' => (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0),
                'operator_name' => (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? ''),
                'update_time' => $now,
            ]);
            $pubId = (int)$exist['id'];
        } else {
            try {
                $pubId = (int)Db::name('system_role_store_publish')->insertGetId([
                    'template_role_id' => $templateRoleId,
                    'store_id' => $storeId,
                    'store_role_id' => $storeRoleId,
                    'channel' => $channel,
                    'allow_store_select' => $allowSelect,
                    'status' => 1,
                    'request_token' => $rowToken !== '' ? $rowToken : ('rtp-' . $templateRoleId . '-' . $storeId . '-' . $channel . '-' . $now),
                    'operator_id' => (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0),
                    'operator_name' => (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? ''),
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            } catch (\Throwable $e) {
                if (!$this->isDup($e)) {
                    throw $e;
                }
                $again = Db::name('system_role_store_publish')
                    ->where('template_role_id', $templateRoleId)
                    ->where('store_id', $storeId)
                    ->where('channel', $channel)
                    ->lock(true)->find();
                if (!$again) {
                    throw $e;
                }
                Db::name('system_role_store_publish')->where('id', (int)$again['id'])->update([
                    'store_role_id' => $storeRoleId,
                    'allow_store_select' => $allowSelect,
                    'status' => 1,
                    'request_token' => $rowToken !== '' ? $rowToken : (string)($again['request_token'] ?? ''),
                    'operator_id' => (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0),
                    'operator_name' => (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? ''),
                    'update_time' => $now,
                ]);
                $pubId = (int)$again['id'];
            }
        }

        $this->audit(0, 'role_template_publish', $pubId, [
            'template_role_id' => $templateRoleId,
            'store_id' => $storeId,
            'store_role_id' => $storeRoleId,
            'channel' => $channel,
            'allow_store_select' => $allowSelect,
        ], $adminInfo, $auditMeta, '发布门店角色');

        return $this->presentPublish(Db::name('system_role_store_publish')->where('id', $pubId)->find() ?: [
            'id' => $pubId,
            'template_role_id' => $templateRoleId,
            'store_id' => $storeId,
            'store_role_id' => $storeRoleId,
            'channel' => $channel,
            'allow_store_select' => $allowSelect,
            'status' => 1,
        ]);
    }

    /**
     * @return int[]
     */
    protected function resolvePublishStoreIds(array $data): array
    {
        $ids = [];
        $storeId = (int)($data['store_id'] ?? 0);
        if ($storeId > 0) {
            $ids[] = $storeId;
        }
        if (!empty($data['store_ids']) && is_array($data['store_ids'])) {
            foreach ($data['store_ids'] as $sid) {
                $sid = (int)$sid;
                if ($sid > 0) {
                    $ids[] = $sid;
                }
            }
        }
        $orgId = (int)($data['org_id'] ?? 0);
        if ($orgId > 0) {
            $orgIds = $this->collectOrgSubtreeIds($orgId);
            if ($orgIds) {
                $fromOrg = Db::name('organization_store')->whereIn('org_id', $orgIds)->column('store_id');
                foreach ($fromOrg ?: [] as $sid) {
                    $sid = (int)$sid;
                    if ($sid > 0) {
                        $ids[] = $sid;
                    }
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * @return int[]
     */
    protected function collectOrgSubtreeIds(int $rootOrgId): array
    {
        $all = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $children = [];
        foreach ($all as $row) {
            $pid = (int)$row['pid'];
            $children[$pid][] = (int)$row['id'];
        }
        $out = [];
        $stack = [$rootOrgId];
        $seen = [];
        while ($stack) {
            $id = (int)array_pop($stack);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
            foreach ($children[$id] ?? [] as $cid) {
                $stack[] = (int)$cid;
            }
        }
        return $out;
    }

    /**
     * @return int[]
     */
    protected function listProjectedRoleIdsForStaff(int $staffId, int $storeId): array
    {
        $rows = Db::name('system_role')
            ->where('type', 1)
            ->where('relation_id', $storeId)
            ->whereLike('role_name', '岗位投影-' . $staffId . '-%')
            ->column('id');
        return array_values(array_unique(array_map('intval', $rows ?: [])));
    }

    protected function presentTemplate(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'role_name' => (string)($row['role_name'] ?? ''),
            'remark' => (string)($row['remark'] ?? ''),
            'status' => (int)($row['status'] ?? 0),
            'allow_store_select' => (int)($row['allow_store_select'] ?? 0),
            'type' => (int)($row['type'] ?? 0),
            'relation_id' => (int)($row['relation_id'] ?? 0),
            'has_store_rules' => trim((string)($row['rules'] ?? '')) !== '',
            'has_cashier_rules' => trim((string)($row['cashier_rules'] ?? '')) !== '',
            'has_mall_rules' => trim((string)($row['mall_rules'] ?? '')) !== '',
            'rules' => (string)($row['rules'] ?? ''),
            'cashier_rules' => (string)($row['cashier_rules'] ?? ''),
            'mall_rules' => (string)($row['mall_rules'] ?? ''),
            'add_time' => (int)($row['add_time'] ?? 0),
        ];
    }

    protected function presentPublish(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'template_role_id' => (int)($row['template_role_id'] ?? 0),
            'store_id' => (int)($row['store_id'] ?? 0),
            'store_role_id' => (int)($row['store_role_id'] ?? 0),
            'channel' => (string)($row['channel'] ?? ''),
            'allow_store_select' => (int)($row['allow_store_select'] ?? 0),
            'status' => (int)($row['status'] ?? 0),
            'request_token' => (string)($row['request_token'] ?? ''),
        ];
    }

    /**
     * @param mixed $raw
     */
    protected function normalizeRulesInput($raw): string
    {
        if (is_array($raw)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
        } else {
            $raw = trim((string)$raw);
            if ($raw === '') {
                return '';
            }
            if (!preg_match('/^\d+(,\d+)*$/', $raw)) {
                throw new AdminException('功能权限格式无效');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
        }
        sort($ids);
        return implode(',', $ids);
    }

    /**
     * @return int[]
     */
    protected function rulesToIds(string $rules): array
    {
        $rules = trim($rules);
        if ($rules === '') {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $rules)))));
    }

    protected function assertStoreChannelMenuIds(string $rules, int $menuType, string $label): void
    {
        $ids = $this->rulesToIds($rules);
        if (!$ids) {
            return;
        }
        $found = Db::name('system_menus')
            ->whereIn('id', $ids)
            ->where('type', $menuType)
            ->where('is_del', 0)
            ->column('id');
        $found = array_map('intval', $found ?: []);
        sort($found);
        $want = $ids;
        sort($want);
        if ($found !== $want) {
            throw new AdminException($label . '功能权限包含无效菜单，或混入了平台后台权限');
        }
        // 双保险：拒绝 type=1 平台菜单
        $platformHit = (int)Db::name('system_menus')->whereIn('id', $ids)->where('type', 1)->where('is_del', 0)->count();
        if ($platformHit > 0) {
            throw new AdminException('总部门店角色模板不能配置平台后台权限');
        }
    }

    protected function assertMallMenuIds(string $rules): void
    {
        $ids = $this->rulesToIds($rules);
        if (!$ids) {
            return;
        }
        /** @var SystemMenusServices $menus */
        $menus = app()->make(SystemMenusServices::class);
        $allowed = [];
        foreach ($menus->getMallMenus(1) as $item) {
            $allowed[(int)($item['id'] ?? 0)] = true;
        }
        foreach ($ids as $id) {
            if ($id <= 0 || !isset($allowed[$id])) {
                throw new AdminException('手机端功能权限包含无效项');
            }
        }
    }

    protected function hasRoleColumn(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $db = Db::query('SELECT DATABASE() AS db');
            $schema = (string)($db[0]['db'] ?? '');
            $cnt = (int)Db::query(
                "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='eb_system_role' AND COLUMN_NAME=?",
                [$schema, $column]
            )[0]['c'];
            $cache[$column] = $cnt > 0;
        } catch (\Throwable $e) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }

    protected function audit(
        int $employeeId,
        string $action,
        int $targetId,
        array $after,
        array $operator,
        array $auditMeta,
        string $reason
    ): void {
        $json = json_encode($after, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new AdminException('审计序列化失败');
        }
        Db::name('employee_change_log')->insert([
            'employee_id' => $employeeId,
            'action' => $action,
            'target_type' => 'role_publish',
            'target_id' => $targetId,
            'source' => 'admin',
            'before_data' => '',
            'after_data' => $json,
            'reason' => $reason,
            'operator_type' => 'admin',
            'operator_id' => (int)($auditMeta['operator_id'] ?? $operator['id'] ?? 0),
            'operator_name' => (string)($auditMeta['operator_name'] ?? $operator['real_name'] ?? $operator['account'] ?? ''),
            'operator_ip' => (string)($auditMeta['operator_ip'] ?? ''),
            'request_id' => (string)($auditMeta['request_id'] ?? ''),
            'add_time' => time(),
        ]);
    }

    protected function isDup(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return stripos($msg, 'Duplicate') !== false || stripos($msg, '1062') !== false;
    }
}
