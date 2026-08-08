<?php
namespace app\services\organization;

use app\services\BaseServices;
use app\services\mobile\merchant\MobileMerchantCapabilityCatalog;
use app\services\system\SystemMenusServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 岗位功能策略：总部配置 / 发布 / 门店可选
 */
class JobPositionPolicyServices extends BaseServices
{
    public const CHANNEL_PLATFORM = 'platform';
    /** Vue 3 门店端；不得与旧门店后台菜单编号混用。 */
    public const CHANNEL_STORE_V3 = 'store_v3';
    /** 以下两个仅用于读取历史记录和兼容旧页面，不再是新岗位策略渠道。 */
    public const CHANNEL_STORE_BACKEND = 'store_backend';
    public const CHANNEL_CASHIER = 'cashier';
    public const CHANNEL_MOBILE = 'mobile';

    public const CHANNELS = [
        self::CHANNEL_PLATFORM,
        self::CHANNEL_STORE_V3,
        self::CHANNEL_MOBILE,
    ];

    /** @var array<int, array{id:int,title:string,feature_code:string,children?:array}> */
    public const STORE_V3_MENU_TREE = [
        ['id' => 301001, 'title' => '收银', 'feature_code' => 'cashier.v3.cashier'],
        ['id' => 301002, 'title' => '挂单', 'feature_code' => 'cashier.v3.hang'],
        ['id' => 301003, 'title' => '房间', 'feature_code' => 'cashier.v3.room'],
        ['id' => 301004, 'title' => '预约', 'feature_code' => 'cashier.v3.reservation'],
        ['id' => 301005, 'title' => '会员', 'feature_code' => 'cashier.v3.member'],
        ['id' => 301008, 'title' => '客情', 'feature_code' => 'cashier.v3.member'],
        ['id' => 301006, 'title' => '订单中心', 'feature_code' => 'cashier.v3.order_center'],
        ['id' => 301007, 'title' => '管理', 'feature_code' => 'cashier.v3.management_center'],
        ['id' => 301100, 'title' => '库存管理', 'feature_code' => 'cashier.v3.inventory.overview', 'children' => [
            ['id' => 301101, 'title' => '入库', 'feature_code' => 'cashier.v3.inventory.inbound'],
            ['id' => 301102, 'title' => '出库', 'feature_code' => 'cashier.v3.inventory.outbound'],
            ['id' => 301103, 'title' => '库存查询', 'feature_code' => 'cashier.v3.inventory.stock'],
            ['id' => 301104, 'title' => '盘点', 'feature_code' => 'cashier.v3.inventory.count'],
            ['id' => 301105, 'title' => '库存明细', 'feature_code' => 'cashier.v3.inventory.movement'],
            ['id' => 301106, 'title' => '库存统计', 'feature_code' => 'cashier.v3.inventory.statistics'],
            ['id' => 301107, 'title' => '请货', 'feature_code' => 'cashier.v3.inventory.request'],
            ['id' => 301108, 'title' => '调拨', 'feature_code' => 'cashier.v3.inventory.transfer'],
            ['id' => 301109, 'title' => '院装', 'feature_code' => 'cashier.v3.inventory.usage'],
            ['id' => 301110, 'title' => '导入', 'feature_code' => 'cashier.v3.inventory.import'],
        ]],
    ];

    public function listPositions(array $where = []): array
    {
        $q = Db::name('position');
        if (isset($where['status']) && $where['status'] !== '' && $where['status'] !== null) {
            $q->where('status', (int)$where['status']);
        }
        $keyword = trim((string)($where['keyword'] ?? ''));
        if ($keyword !== '') {
            $q->whereLike('name', '%' . $keyword . '%');
        }
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = min(100, max(1, (int)($where['limit'] ?? 20)));
        $count = (int)(clone $q)->count();
        $list = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $ids = array_values(array_filter(array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $list)));
        $ruleMap = [];
        $pubCount = [];
        if ($ids) {
            $rules = Db::name('job_position_channel_rule')
                ->whereIn('position_id', $ids)
                ->where('status', 1)
                ->select()->toArray();
            foreach ($rules as $r) {
                $pid = (int)$r['position_id'];
                $ruleMap[$pid][(string)$r['channel']] = [
                    'rules' => (string)($r['rules'] ?? ''),
                    'status' => (int)($r['status'] ?? 1),
                    'version' => (int)($r['version'] ?? 1),
                ];
            }
            $pubRows = Db::name('job_position_publish')
                ->whereIn('position_id', $ids)
                ->where('status', 1)
                ->field('position_id, COUNT(*) AS cnt')
                ->group('position_id')
                ->select()->toArray();
            foreach ($pubRows as $p) {
                $pubCount[(int)$p['position_id']] = (int)$p['cnt'];
            }
        }
        foreach ($list as &$row) {
            $pid = (int)$row['id'];
            $row['channel_rules'] = $ruleMap[$pid] ?? [];
            $row['publish_count'] = $pubCount[$pid] ?? 0;
            $row['allow_store_select'] = (int)($row['allow_store_select'] ?? 0);
            $row['is_store_manager'] = (int)($row['is_store_manager'] ?? 0);
            $row['use_platform'] = (int)($row['use_platform'] ?? 0);
            $row['use_store'] = (int)($row['use_store'] ?? 0);
            $row['use_mobile'] = (int)($row['use_mobile'] ?? 0);
            // 新岗位模型不会把历史门店后台/收银台规则映射为 Vue 3。
            $row['use_store'] = $row['use_store'] === 1 && !empty($row['channel_rules'][self::CHANNEL_STORE_V3]['rules']) ? 1 : 0;
            if ($row['use_platform'] + $row['use_store'] + $row['use_mobile'] === 0) {
                $rules = $row['channel_rules'];
                if (!empty($rules[self::CHANNEL_PLATFORM]['rules'])) {
                    $row['use_platform'] = 1;
                }
                if (!empty($rules[self::CHANNEL_STORE_V3]['rules'])) {
                    $row['use_store'] = 1;
                }
                if (!empty($rules[self::CHANNEL_MOBILE]['rules'])) {
                    $row['use_mobile'] = 1;
                }
            }
        }
        unset($row);
        return compact('count', 'list');
    }

    /**
     * 仅切换岗位启用状态，不重写岗位名称与权限规则。
     * 岗位名称历史上可能存在重复，状态开关不能走完整保存的重名校验。
     *
     * @return array{msg:string,data:array,replay:bool}
     */
    public function setPositionStatus(
        int $positionId,
        int $status,
        array $adminInfo,
        array $requestCtx
    ): array {
        $status = $status === 1 ? 1 : 0;
        $payload = ['position_id' => $positionId, 'status' => $status];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'job_position_status',
            'job_position:' . $positionId . ':status',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($positionId, $status) {
                if ($positionId <= 0) {
                    throw new AdminException('请选择岗位');
                }
                $row = Db::name('position')->where('id', $positionId)->lock(true)->find();
                if (!$row) {
                    throw new AdminException('岗位不存在');
                }
                $version = (int)($row['version'] ?? 1) + 1;
                Db::name('position')->where('id', $positionId)->update([
                    'status' => $status,
                    'version' => $version,
                    'update_time' => time(),
                ]);
                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->reprojectEmployeesByPosition($positionId);
                return [
                    'msg' => $status === 1 ? '岗位已启用' : '岗位已停用',
                    'data' => ['id' => $positionId, 'status' => $status, 'version' => $version],
                ];
            }
        );
    }

    public function getPositionDetail(int $positionId): array
    {
        if ($positionId <= 0) {
            throw new AdminException('请选择岗位');
        }
        $row = Db::name('position')->where('id', $positionId)->find();
        if (!$row) {
            throw new AdminException('岗位不存在');
        }
        $rules = Db::name('job_position_channel_rule')
            ->where('position_id', $positionId)
            ->select()->toArray();
        $channelRules = [];
        foreach ($rules as $r) {
            $channelRules[(string)$r['channel']] = [
                'id' => (int)$r['id'],
                'rules' => (string)($r['rules'] ?? ''),
                'status' => (int)($r['status'] ?? 1),
                'version' => (int)($r['version'] ?? 1),
            ];
        }
        $publishes = Db::name('job_position_publish')->alias('p')
            ->where('p.position_id', $positionId)
            ->order('p.id', 'desc')
            ->limit(200)
            ->select()->toArray();
        $platformRules = (string)($channelRules[self::CHANNEL_PLATFORM]['rules'] ?? '');
        $storeV3Rules = (string)($channelRules[self::CHANNEL_STORE_V3]['rules'] ?? '');
        $mobileRules = (string)($channelRules[self::CHANNEL_MOBILE]['rules'] ?? '');
        return [
            'position' => [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'status' => (int)($row['status'] ?? 0),
                'allow_store_select' => (int)($row['allow_store_select'] ?? 0),
                'is_store_manager' => (int)($row['is_store_manager'] ?? 0),
                'use_platform' => (int)($row['use_platform'] ?? 0),
                'use_store' => (int)($row['use_store'] ?? 0) === 1 && $storeV3Rules !== '' ? 1 : 0,
                'use_mobile' => (int)($row['use_mobile'] ?? 0),
                'remark' => (string)($row['remark'] ?? ''),
                'version' => (int)($row['version'] ?? 1),
                'update_time' => (int)($row['update_time'] ?? 0),
            ],
            'channel_rules' => $channelRules,
            'platform_rules' => $platformRules,
            'store_v3_rules' => $storeV3Rules,
            'mobile_rules' => $mobileRules,
            'platform_rules_ids' => $this->rulesToIds($platformRules),
            'store_v3_rules_ids' => $this->rulesToIds($storeV3Rules),
            'mobile_rules_ids' => $this->rulesToIds($mobileRules),
            'publishes' => $publishes,
            'help' => [
                'summary' => '岗位决定员工可以操作哪些功能，人员数据权限决定员工可以看到哪些数据。',
                'platform_hq_only' => '平台后台权限只在总部平台生效，门店员工不能看到或使用。',
                'allow_store_select' => '开启后，门店在新建或编辑本店员工时可以选择这个岗位；关闭后，只有总部可以配置，已经绑定的人员不会自动失去权限。',
                'is_store_manager' => '开启后，人员绑定该岗位时自动成为店长/副店长身份；关闭则不因该岗位获得店长身份。',
                'disable' => '停用岗位不会自动清除已绑定人员的历史权限，但门店不能再新增选择。',
            ],
        ];
    }

    /**
     * 三端菜单树（总部后台用；门店端不得调用）
     */
    public function getPositionMenus(): array
    {
        /** @var SystemMenusServices $menus */
        $menus = app()->make(SystemMenusServices::class);
        /** @var MobileMerchantCapabilityCatalog $mobileCatalog */
        $mobileCatalog = app()->make(MobileMerchantCapabilityCatalog::class);
        return [
            'platform_menus' => $menus->getList(['type' => 0, 'is_del' => 0]),
            'store_v3_menus' => self::STORE_V3_MENU_TREE,
            // 岗位手机端权限与当前 Vue 3 商家端共用同一能力目录，禁止回读旧手机菜单。
            'mobile_menus' => $mobileCatalog->menuTree(),
            'help' => '岗位决定员工能操作哪些功能。平台后台权限只在总部平台生效；门店可用只决定门店能不能选择该岗位。',
        ];
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function savePosition(array $data, array $adminInfo, array $requestCtx): array
    {
        $positionId = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $status = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        $remark = mb_substr(trim((string)($data['remark'] ?? '')), 0, 500);
        $allowStoreSelect = (int)($data['allow_store_select'] ?? 0) === 1 ? 1 : 0;
        $isStoreManager = (int)($data['is_store_manager'] ?? 0) === 1 ? 1 : 0;

        $channelRules = $this->resolveChannelRulesPayload($data);
        // use_* = 三端入口开关（与功能树解耦）：关闭入口则该端功能不可用且清空规则
        $usePlatform = array_key_exists('use_platform', $data)
            ? ((int)$data['use_platform'] === 1 ? 1 : 0)
            : ($this->rulesNonEmpty($channelRules[self::CHANNEL_PLATFORM] ?? null) ? 1 : 0);
        $useStore = array_key_exists('use_store', $data)
            ? ((int)$data['use_store'] === 1 ? 1 : 0)
            : ($this->rulesNonEmpty($channelRules[self::CHANNEL_STORE_V3] ?? null) ? 1 : 0);
        $useMobile = array_key_exists('use_mobile', $data)
            ? ((int)$data['use_mobile'] === 1 ? 1 : 0)
            : ($this->rulesNonEmpty($channelRules[self::CHANNEL_MOBILE] ?? null) ? 1 : 0);

        $normalizedRules = $this->normalizeChannelRulesInput($channelRules, [
            self::CHANNEL_PLATFORM => $usePlatform,
            self::CHANNEL_STORE_V3 => $useStore,
            self::CHANNEL_MOBILE => $useMobile,
        ]);
        // 入口关闭：强制清空该端规则；入口开启：保留规则（可为空树）
        if ($usePlatform === 0) {
            $normalizedRules[self::CHANNEL_PLATFORM] = '';
        }
        if ($useStore === 0) {
            $normalizedRules[self::CHANNEL_STORE_V3] = '';
        }
        if ($useMobile === 0) {
            $normalizedRules[self::CHANNEL_MOBILE] = '';
        }

        $payload = [
            'id' => $positionId,
            'name' => $name,
            'status' => $status,
            'remark' => $remark,
            'allow_store_select' => $allowStoreSelect,
            'is_store_manager' => $isStoreManager,
            'use_platform' => $usePlatform,
            'use_store' => $useStore,
            'use_mobile' => $useMobile,
            'platform_rules' => $normalizedRules[self::CHANNEL_PLATFORM],
            'store_v3_rules' => $normalizedRules[self::CHANNEL_STORE_V3],
            'mobile_rules' => $normalizedRules[self::CHANNEL_MOBILE],
            'channel_rules' => $normalizedRules,
        ];

        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'job_position_save',
            'job_position:' . ($positionId > 0 ? $positionId : 'new'),
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use (
                $positionId,
                $name,
                $status,
                $remark,
                $allowStoreSelect,
                $isStoreManager,
                $usePlatform,
                $useStore,
                $useMobile,
                $normalizedRules
            ) {
                if ($name === '') {
                    throw new AdminException('请填写岗位名称');
                }
                if ($usePlatform === 0 && $useStore === 0 && $useMobile === 0) {
                    throw new AdminException('请至少开启平台后台、门店端或手机端其中一端入口');
                }
                // 门店可用与平台权限可并存：门店选用时只下发门店端/手机端规则，不下发平台入口。
                $now = time();
                if ($positionId > 0) {
                    $row = Db::name('position')->where('id', $positionId)->lock(true)->find();
                    if (!$row) {
                        throw new AdminException('岗位不存在');
                    }
                    $dup = Db::name('position')->where('name', $name)->where('id', '<>', $positionId)->find();
                    if ($dup) {
                        throw new AdminException('岗位名称已存在');
                    }
                    $ver = (int)($row['version'] ?? 1) + 1;
                    Db::name('position')->where('id', $positionId)->update([
                        'name' => $name,
                        'status' => $status,
                        'remark' => $remark,
                        'allow_store_select' => $allowStoreSelect,
                        'is_store_manager' => $isStoreManager,
                        'use_platform' => $usePlatform,
                        'use_store' => $useStore,
                        'use_mobile' => $useMobile,
                        'version' => $ver,
                        'update_time' => $now,
                    ]);
                    $id = $positionId;
                } else {
                    $dup = Db::name('position')->where('name', $name)->find();
                    if ($dup) {
                        throw new AdminException('岗位名称已存在');
                    }
                    $id = (int)Db::name('position')->insertGetId([
                        'name' => $name,
                        'status' => $status,
                        'allow_store_select' => $allowStoreSelect,
                        'is_store_manager' => $isStoreManager,
                        'use_platform' => $usePlatform,
                        'use_store' => $useStore,
                        'use_cashier' => 0,
                        'use_mobile' => $useMobile,
                        'remark' => $remark,
                        'version' => 1,
                        'update_time' => $now,
                    ]);
                    $ver = 1;
                }

                $this->upsertChannelRules($id, $normalizedRules, $now);
                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->reprojectEmployeesByPosition($id);
                return [
                    'msg' => '保存成功',
                    'data' => [
                        'id' => $id,
                        'version' => $ver,
                        'allow_store_select' => $allowStoreSelect,
                        'is_store_manager' => $isStoreManager,
                        'use_platform' => $usePlatform,
                    ],
                ];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function publishPosition(array $data, array $adminInfo, array $requestCtx): array
    {
        $positionId = (int)($data['position_id'] ?? 0);
        $scopeType = trim((string)($data['scope_type'] ?? 'store'));
        $scopeId = (int)($data['scope_id'] ?? 0);
        $payload = [
            'position_id' => $positionId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'job_position_publish',
            'job_position:' . $positionId . ':' . $scopeType . ':' . $scopeId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($positionId, $scopeType, $scopeId, $adminInfo) {
                if ($positionId <= 0 || $scopeId <= 0) {
                    throw new AdminException('请选择岗位和发布范围');
                }
                if (!in_array($scopeType, ['org', 'store'], true)) {
                    throw new AdminException('发布范围类型无效');
                }
                $pos = Db::name('position')->where('id', $positionId)->lock(true)->find();
                if (!$pos) {
                    throw new AdminException('岗位不存在');
                }
                if ((int)($pos['status'] ?? 0) !== 1) {
                    throw new AdminException('岗位已停用，无法发布');
                }
                if ($scopeType === 'org') {
                    $org = Db::name('organization')->where('id', $scopeId)->where('is_del', 0)->find();
                    if (!$org) {
                        throw new AdminException('目标组织不存在或已删除');
                    }
                } else {
                    $store = Db::name('system_store')->where('id', $scopeId)->where('is_del', 0)->find();
                    if (!$store) {
                        throw new AdminException('目标门店不存在或已删除');
                    }
                }
                $now = time();
                $token = (string)($auditMeta['request_token'] ?? '');
                $allowSnap = (int)($pos['allow_store_select'] ?? 0);
                $exist = Db::name('job_position_publish')
                    ->where('position_id', $positionId)
                    ->where('scope_type', $scopeType)
                    ->where('scope_id', $scopeId)
                    ->lock(true)
                    ->find();
                $opId = (int)($auditMeta['operator_id'] ?? $adminInfo['id'] ?? 0);
                $opName = (string)($auditMeta['operator_name'] ?? $adminInfo['real_name'] ?? $adminInfo['account'] ?? '');
                if ($exist) {
                    Db::name('job_position_publish')->where('id', (int)$exist['id'])->update([
                        'status' => 1,
                        'allow_store_select' => $allowSnap,
                        'request_token' => $token !== '' ? $token : (string)($exist['request_token'] ?? ''),
                        'operator_id' => $opId,
                        'operator_name' => $opName,
                        'update_time' => $now,
                    ]);
                    $pubId = (int)$exist['id'];
                } else {
                    $pubId = (int)Db::name('job_position_publish')->insertGetId([
                        'position_id' => $positionId,
                        'scope_type' => $scopeType,
                        'scope_id' => $scopeId,
                        'status' => 1,
                        'allow_store_select' => $allowSnap,
                        'request_token' => $token,
                        'operator_id' => $opId,
                        'operator_name' => $opName,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                }
                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->reprojectEmployeesByPosition($positionId);
                return [
                    'msg' => '发布成功',
                    'data' => [
                        'publish_id' => $pubId,
                        'position_id' => $positionId,
                        'scope_type' => $scopeType,
                        'scope_id' => $scopeId,
                    ],
                ];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function disablePublish(int $publishId, array $data, array $adminInfo, array $requestCtx): array
    {
        $payload = ['publish_id' => $publishId];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'job_position_publish_disable',
            'job_position_publish:' . $publishId,
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($publishId) {
                if ($publishId <= 0) {
                    throw new AdminException('请指定发布记录');
                }
                $row = Db::name('job_position_publish')->where('id', $publishId)->lock(true)->find();
                if (!$row) {
                    throw new AdminException('发布记录不存在');
                }
                Db::name('job_position_publish')->where('id', $publishId)->update([
                    'status' => 0,
                    'update_time' => time(),
                    'operator_id' => (int)($auditMeta['operator_id'] ?? 0),
                    'operator_name' => (string)($auditMeta['operator_name'] ?? ''),
                ]);
                /** @var StaffJobPositionServices $jobSvc */
                $jobSvc = app()->make(StaffJobPositionServices::class);
                $jobSvc->reprojectEmployeesByPosition((int)$row['position_id']);
                return [
                    'msg' => '已停用发布',
                    'data' => ['publish_id' => $publishId, 'status' => 0],
                ];
            }
        );
    }

    /**
     * 门店可选岗位：启用 + 门店可用 + 至少一端门店端/手机端功能。
     * 含平台规则的岗位也可选，但门店侧只使用门店端/手机端规则，不下发平台入口。
     * @return array<int, array{value:int,label:string,allow_store_select:int}>
     */
    public function listStoreSelectablePositions(int $storeId): array
    {
        if ($storeId <= 0) {
            return [];
        }
        // $storeId 保留入参以兼容调用方；门店可用不再依赖发布范围
        $rows = Db::name('position')
            ->where('status', 1)
            ->where('allow_store_select', 1)
            ->where(function ($q) {
                $q->where('use_store', 1)->whereOr('use_mobile', 1);
            })
            ->field('id,name,allow_store_select,use_store,use_mobile,use_platform')
            ->order('id', 'asc')
            ->select()->toArray();

        $out = [];
        foreach ($rows as $row) {
            $pid = (int)$row['id'];
            // 再按 channel_rules 确认至少有一端门店侧规则，不映射历史旧菜单。
            $hasStoreSide = $this->positionHasStoreSideRules($pid);
            if (!$hasStoreSide) {
                continue;
            }
            $out[] = [
                'value' => $pid,
                'label' => (string)$row['name'],
                'publish_id' => 0,
                'allow_store_select' => (int)($row['allow_store_select'] ?? 0),
                'use_platform' => (int)($row['use_platform'] ?? 0),
                'use_store' => (int)($row['use_store'] ?? 0),
                'use_mobile' => (int)($row['use_mobile'] ?? 0),
            ];
        }
        return $out;
    }

    protected function positionHasStoreSideRules(int $positionId): bool
    {
        if ($positionId <= 0) {
            return false;
        }
        $cnt = (int)Db::name('job_position_channel_rule')
            ->where('position_id', $positionId)
            ->where('status', 1)
            ->whereIn('channel', [
                self::CHANNEL_STORE_V3,
                self::CHANNEL_MOBILE,
            ])
            ->where('rules', '<>', '')
            ->count();
        return $cnt > 0;
    }

    /**
     * @return int[]
     */
    public function resolveStoreAncestorOrgIds(int $storeId): array
    {
        $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if ($orgId <= 0) {
            return [];
        }
        $rows = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $pidMap = [];
        foreach ($rows as $r) {
            $pidMap[(int)$r['id']] = (int)($r['pid'] ?? 0);
        }
        $out = [];
        $cur = $orgId;
        $guard = 0;
        while ($cur > 0 && $guard < 64) {
            $out[] = $cur;
            $cur = $pidMap[$cur] ?? 0;
            $guard++;
        }
        return array_values(array_unique($out));
    }

    /**
     * 新岗位策略只接受 platform_rules/store_v3_rules/mobile_rules，兼容 channel_rules。
     * @return array<string, mixed>
     */
    protected function resolveChannelRulesPayload(array $data): array
    {
        $map = [
            self::CHANNEL_PLATFORM => 'platform_rules',
            self::CHANNEL_STORE_V3 => 'store_v3_rules',
            self::CHANNEL_MOBILE => 'mobile_rules',
        ];
        $hasSplit = false;
        foreach ($map as $key) {
            if ($this->rulesNonEmpty($data[$key] ?? null)) {
                $hasSplit = true;
                break;
            }
        }
        if ($hasSplit) {
            $channelRules = [];
            foreach ($map as $channel => $key) {
                $channelRules[$channel] = $data[$key] ?? [];
            }
            return $channelRules;
        }
        return is_array($data['channel_rules'] ?? null) ? $data['channel_rules'] : [];
    }

    /**
     * @param mixed $raw
     */
    protected function rulesNonEmpty($raw): bool
    {
        if (is_array($raw)) {
            if (isset($raw['rules'])) {
                return trim((string)$raw['rules']) !== '';
            }
            foreach ($raw as $v) {
                if ((int)$v > 0) {
                    return true;
                }
            }
            return false;
        }
        return trim((string)$raw) !== '';
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

    protected function positionHasPlatformRules(int $positionId): bool
    {
        if ($positionId <= 0) {
            return false;
        }
        $rules = (string)Db::name('job_position_channel_rule')
            ->where('position_id', $positionId)
            ->where('channel', self::CHANNEL_PLATFORM)
            ->where('status', 1)
            ->value('rules');
        return trim($rules) !== '';
    }

    /**
     * @param array<string, mixed> $channelRules
     * @param array<string, int> $useFlags
     * @return array<string, string>
     */
    protected function normalizeChannelRulesInput(array $channelRules, array $useFlags): array
    {
        $out = [];
        foreach (self::CHANNELS as $ch) {
            $raw = '';
            if (isset($channelRules[$ch])) {
                if (is_array($channelRules[$ch])) {
                    if (array_key_exists('rules', $channelRules[$ch])) {
                        $raw = (string)($channelRules[$ch]['rules'] ?? '');
                    } else {
                        // 兼容直接传菜单 ID 数组
                        $ids = array_values(array_unique(array_filter(array_map('intval', $channelRules[$ch]))));
                        sort($ids);
                        $raw = $ids ? implode(',', $ids) : '';
                    }
                } else {
                    $raw = (string)$channelRules[$ch];
                }
            }
            $rules = $this->normalizeRulesString($raw);
            if ($ch === self::CHANNEL_STORE_V3 && $rules !== '') {
                $this->assertStoreV3RuleIds($this->rulesToIds($rules));
            }
            if ($ch === self::CHANNEL_MOBILE && $rules !== '') {
                $rules = implode(',', $this->normalizeMobileRuleIds($this->rulesToIds($rules)));
            }
            if ((int)($useFlags[$ch] ?? 0) === 1 && $rules === '') {
                throw new AdminException($this->channelLabel($ch) . '已启用，请配置功能权限');
            }
            if ((int)($useFlags[$ch] ?? 0) === 0) {
                $rules = '';
            }
            $out[$ch] = $rules;
        }
        return $out;
    }

    protected function normalizeRulesString(string $rules): string
    {
        $rules = trim($rules);
        if ($rules === '') {
            return '';
        }
        if (!preg_match('/^\d+(,\d+)*$/', $rules)) {
            throw new AdminException('功能权限格式无效，请使用菜单编号列表');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $rules)))));
        sort($ids);
        return $ids ? implode(',', $ids) : '';
    }

    /** @return array<int, string> stable policy-rule ID => Vue 3 feature code */
    public static function storeV3FeatureMap(): array
    {
        $map = [];
        $walk = static function (array $nodes) use (&$walk, &$map): void {
            foreach ($nodes as $node) {
                $id = (int)($node['id'] ?? 0);
                $code = trim((string)($node['feature_code'] ?? ''));
                if ($id > 0 && $code !== '') {
                    $map[$id] = $code;
                }
                $walk((array)($node['children'] ?? []));
            }
        };
        $walk(self::STORE_V3_MENU_TREE);
        return $map;
    }

    /** @return string[] */
    public static function storeV3FeaturesFromRuleIds(array $ruleIds): array
    {
        $catalog = self::storeV3FeatureMap();
        $features = [];
        foreach ($ruleIds as $ruleId) {
            $feature = $catalog[(int)$ruleId] ?? '';
            if ($feature !== '') {
                $features[$feature] = true;
            }
        }
        return array_keys($features);
    }

    /** @param int[] $ruleIds */
    protected function assertStoreV3RuleIds(array $ruleIds): void
    {
        $catalog = self::storeV3FeatureMap();
        foreach ($ruleIds as $ruleId) {
            if (!isset($catalog[(int)$ruleId])) {
                throw new AdminException('门店端功能权限无效，请重新加载后再保存');
            }
        }
    }

    /**
     * 手机端岗位规则只允许写入当前 Vue 3 商家端能力目录中的叶子功能。
     * 历史 system_menus 编号只读兼容，不可再通过新岗位保存写入。
     *
     * @param int[] $ruleIds
     * @return int[]
     */
    protected function normalizeMobileRuleIds(array $ruleIds): array
    {
        /** @var MobileMerchantCapabilityCatalog $catalog */
        $catalog = app()->make(MobileMerchantCapabilityCatalog::class);
        try {
            return $catalog->normalizeRuleIds($ruleIds);
        } catch (\InvalidArgumentException $e) {
            throw new AdminException('手机端功能权限无效，请重新加载当前商家端功能后再保存');
        }
    }

    /**
     * @param array<string, string> $normalizedRules
     */
    protected function upsertChannelRules(int $positionId, array $normalizedRules, int $now): void
    {
        foreach ($normalizedRules as $channel => $rules) {
            $exist = Db::name('job_position_channel_rule')
                ->where('position_id', $positionId)
                ->where('channel', $channel)
                ->lock(true)
                ->find();
            $status = $rules !== '' ? 1 : 0;
            if ($exist) {
                Db::name('job_position_channel_rule')->where('id', (int)$exist['id'])->update([
                    'rules' => $rules,
                    'status' => $status,
                    'version' => (int)($exist['version'] ?? 1) + 1,
                    'update_time' => $now,
                ]);
            } else {
                Db::name('job_position_channel_rule')->insert([
                    'position_id' => $positionId,
                    'channel' => $channel,
                    'rules' => $rules,
                    'status' => $status,
                    'version' => 1,
                    'add_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
    }

    public function channelLabel(string $channel): string
    {
        $map = [
            self::CHANNEL_PLATFORM => '平台后台',
            self::CHANNEL_STORE_V3 => '门店端',
            self::CHANNEL_STORE_BACKEND => '旧门店后台',
            self::CHANNEL_CASHIER => '旧收银台',
            self::CHANNEL_MOBILE => '手机端',
        ];
        return $map[$channel] ?? $channel;
    }

    public function useFlagColumn(string $channel): string
    {
        $map = [
            self::CHANNEL_PLATFORM => 'use_platform',
            self::CHANNEL_STORE_V3 => 'use_store',
            self::CHANNEL_STORE_BACKEND => 'use_store',
            self::CHANNEL_CASHIER => 'use_cashier',
            self::CHANNEL_MOBILE => 'use_mobile',
        ];
        if (!isset($map[$channel])) {
            throw new AdminException('渠道无效');
        }
        return $map[$channel];
    }
}
