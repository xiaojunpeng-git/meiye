<?php
declare(strict_types=1);

namespace app\services\user;

use app\services\report\StoreUnifiedReportPhaseFourServices;
use app\services\query\metric\StoreCustomerVisitEligibility;
use think\exception\ValidateException;
use think\facade\Db;

/** 会员调查：名单只控制定向提醒；有效任务公开扫码参与，答卷和奖励按会员、任务轮次防重。 */
class MemberSurveyServices
{
    /** 二维码公开读取仅返回答卷所需内容，不暴露目标名单、手机号和后台奖励配置。 */
    public function entry(string $token):array
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new ValidateException('问卷链接无效');
        $id=(int)Db::name('member_survey_task')->where('entry_token',$token)->value('id');
        if(!$id)throw new ValidateException('问卷链接无效');
        return$this->memberDetail(0,$id);
    }

    /** 域名来自实例受控配置，不能由客户端参数决定奖励门店或二维码跳转站点。 */
    public function qrEntry(int $id):array
    {
        $task=$this->task($id);if(!$this->isActive($task)||empty($task['entry_token']))throw new ValidateException('只有进行中的任务可以生成二维码');
        $base=rtrim((string)(config('member_survey.member_h5_base_url','') ?: sys_config('site_url','')),'/');
        $parts=parse_url($base);
        if(!$parts||empty($parts['host'])||!in_array($parts['scheme']??'',['https','http'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))throw new ValidateException('请先配置本实例会员端地址');
        if($parts['scheme']!=='https'&&!in_array($parts['host'],['127.0.0.1','localhost'],true))throw new ValidateException('会员端地址必须使用HTTPS');
        return['task_id'=>$id,'title'=>$task['title'],'url'=>$base.'/pages/users/survey/index?entry='.$task['entry_token']];
    }
    private function now(): int
    {
        return time();
    }

    private function today(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))->format('Y-m-d');
    }

    private function decode(string $json): array
    {
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    private function task(int $id): array
    {
        $task = Db::name('member_survey_task')->where('id', $id)->find();
        if (!$task) throw new ValidateException('调查任务不存在');
        $task['questions'] = $this->decode((string)$task['questions']);
        $task['filters'] = $this->decode((string)$task['filters']);
        return $task;
    }

    private function isActive(array $task): bool
    {
        return $task['status'] === 'published' && ((int)$task['deadline'] === 0 || (int)$task['deadline'] >= $this->now());
    }

    private function normalizedQuestions($questions): array
    {
        if (!is_array($questions) || count($questions) < 1 || count($questions) > 30) {
            throw new ValidateException('请设置 1 至 30 道题目');
        }
        $out = [];
        foreach (array_values($questions) as $index => $question) {
            if(!is_array($question))throw new ValidateException('题目格式不正确');
            $type = (string)($question['type'] ?? '');
            $title = trim((string)($question['title'] ?? ''));
            if (!in_array($type, ['single', 'multiple', 'text'], true) || $title === '' || mb_strlen($title) > 200) {
                throw new ValidateException('题目类型或标题不正确');
            }
            $options = [];
            if ($type !== 'text') {
                $raw = $question['options'] ?? [];
                if (!is_array($raw) || count($raw) < 2 || count($raw) > 20) {
                    throw new ValidateException('选择题需有 2 至 20 个选项');
                }
                foreach (array_values($raw) as $optionIndex => $option) {
                    $label = trim((string)(is_array($option) ? ($option['label'] ?? '') : $option));
                    if ($label === '' || mb_strlen($label) > 100) throw new ValidateException('选项内容不正确');
                    $options[] = ['id' => (string)($optionIndex + 1), 'label' => $label];
                }
            }
            $out[] = [
                'id' => (string)($index + 1), 'type' => $type, 'title' => $title,
                'required' => !empty($question['required']), 'options' => $options,
            ];
        }
        return $out;
    }

    private function questionnaire(int $id): array
    {
        $row = Db::name('member_survey_questionnaire')->where('id', $id)->find();
        if (!$row) throw new ValidateException('问卷不存在，请先创建问卷');
        $row['questions'] = $this->decode((string)$row['questions']);
        return $row;
    }

    /** 管理分页按更新时间和ID稳定排序，列表不承担选择目录或全部导出。 */
    public function listQuestionnaires(int $page=1,int $limit=20): array
    {
        $count=Db::name('member_survey_questionnaire')->count();
        $rows = Db::name('member_survey_questionnaire')->order('updated_at', 'desc')->order('id', 'desc')->page(max(1,$page),max(1,min(100,$limit)))->select()->toArray();
        $giftMap = $rows ? app()->make(MemberSurveyRewardConfigurationServices::class)
            ->getConfigMap(MemberSurveyRewardConfigurationServices::GIFT_TYPE_SURVEY_QUESTIONNAIRE, array_column($rows, 'id')) : [];
        foreach ($rows as &$row) {
            $row['question_count'] = count($this->decode((string)$row['questions']));
            $row['task_count'] = Db::name('member_survey_task')->where('questionnaire_id', $row['id'])->count();
            $gifts = $giftMap[(int)$row['id']] ?? ['product' => [], 'coupon' => []];
            $row['gift_count'] = count($gifts['product']) + count($gifts['coupon']);
            unset($row['questions']);
        }
        return ['list'=>$rows,'count'=>$count];
    }

    /** 选项目录独立于管理分页；只返回必要标识，最多100项并支持检索。 */
    public function questionnaireOptions(string $keyword=''):array
    {
        if(mb_strlen($keyword)>150)throw new ValidateException('搜索内容过长');
        return Db::name('member_survey_questionnaire')->when($keyword!=='',function($q)use($keyword){$q->whereLike('title','%'.$keyword.'%');})->field('id,title,revision')->order('id','desc')->limit(100)->select()->toArray();
    }

    /** 读取当前问卷供编辑预览；已发布任务另读发布时固定题目。 */
    public function readQuestionnaire(int $id): array
    {
        return $this->questionnaire($id);
    }

    /** 按发布时的题目快照分组，避免问卷编辑后把不同选项混在一起。 */
    public function questionnaireAnalysis(int $id): array
    {
        $questionnaire = $this->questionnaire($id);
        $tasks = Db::name('member_survey_task')->where('questionnaire_id', $id)
            ->whereIn('status', ['published', 'stopped'])
            ->field('id,title,status,questions,published_at,send_round')->order('published_at', 'desc')->order('id', 'desc')
            ->select()->toArray();
        $groups = $taskGroups = $taskPositions = [];
        foreach ($tasks as $task) {
            $questions = $this->decode((string)$task['questions']);
            if (!$questions) continue;
            $key = hash('sha256', json_encode($questions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!isset($groups[$key])) {
                $stats = [];
                foreach ($questions as $question) {
                    $options = [];
                    foreach ($question['options'] ?? [] as $option) {
                        $options[(string)$option['id']] = ['id' => (string)$option['id'], 'label' => $option['label'], 'count' => 0];
                    }
                    $stats[(string)$question['id']] = [
                        'id' => (string)$question['id'], 'title' => $question['title'], 'type' => $question['type'],
                        'answered_count' => 0, 'skipped_count' => 0, 'options' => $options,
                    ];
                }
                $groups[$key] = ['key' => $key, 'task_count' => 0, 'target_count' => 0,
                    'answer_count' => 0, 'tasks' => [], 'stats' => $stats];
            }
            $taskId = (int)$task['id'];
            $taskGroups[$taskId] = $key;
            $taskPositions[$taskId] = count($groups[$key]['tasks']);
            $groups[$key]['tasks'][] = ['id' => $taskId, 'title' => $task['title'],
                'status' => $task['status'], 'published_at' => (int)$task['published_at'],
                'send_round' => (int)$task['send_round'], 'target_count' => 0, 'answer_count' => 0];
            $groups[$key]['task_count']++;
        }

        if ($taskGroups) {
            $ids = array_keys($taskGroups);
            $counts = Db::name('member_survey_target')->whereIn('task_id', $ids)->where('source','<>','qrcode')
                ->field('task_id,COUNT(*) AS amount')->group('task_id')->select()->toArray();
            foreach ($counts as $count) {
                $taskId = (int)$count['task_id'];
                $key = $taskGroups[$taskId];
                $amount = (int)$count['amount'];
                $deliveries = $amount * $groups[$key]['tasks'][$taskPositions[$taskId]]['send_round'];
                $groups[$key]['tasks'][$taskPositions[$taskId]]['target_count'] = $deliveries;
                $groups[$key]['target_count'] += $deliveries;
            }
            $rows = Db::name('member_survey_answer')->whereIn('task_id', $ids)
                ->field('task_id,answers')->select()->toArray();
            foreach ($rows as $row) {
                $taskId = (int)$row['task_id'];
                $key = $taskGroups[$taskId];
                $groups[$key]['answer_count']++;
                $groups[$key]['tasks'][$taskPositions[$taskId]]['answer_count']++;
                $answers = $this->decode((string)$row['answers']);
                foreach ($groups[$key]['stats'] as $questionId => &$stat) {
                    $value = $answers[$questionId] ?? null;
                    if ($stat['type'] === 'text') {
                        if (trim((string)($value ?? '')) !== '') $stat['answered_count']++;
                        continue;
                    }
                    $choices = $stat['type'] === 'multiple' ? (is_array($value) ? $value : [])
                        : (($value === null || $value === '') ? [] : [$value]);
                    $valid = false;
                    foreach (array_unique(array_map('strval', $choices)) as $choice) {
                        if (!isset($stat['options'][$choice])) continue;
                        $stat['options'][$choice]['count']++;
                        $valid = true;
                    }
                    if ($valid) $stat['answered_count']++;
                }
                unset($stat);
            }
        }

        $totalAnswers = 0;
        foreach ($groups as &$group) {
            $totalAnswers += $group['answer_count'];
            foreach ($group['stats'] as &$stat) {
                $stat['skipped_count'] = $group['answer_count'] - $stat['answered_count'];
                $stat['options'] = array_values($stat['options']);
            }
            unset($stat);
            $group['stats'] = array_values($group['stats']);
        }
        unset($group);
        return ['questionnaire' => ['id' => (int)$questionnaire['id'], 'title' => $questionnaire['title'],
            'revision' => (int)$questionnaire['revision']], 'task_count' => count($taskGroups),
            'answer_count' => $totalAnswers, 'groups' => array_values($groups)];
    }

    /** 文字题回答按当前题目快照分页，保留所属任务与会员信息。 */
    public function questionnaireTextAnswers(int $id, string $groupKey, string $questionId, int $page = 1, int $limit = 20): array
    {
        $analysis = $this->questionnaireAnalysis($id);
        $group = null;
        foreach ($analysis['groups'] as $item) {
            if ($item['key'] === $groupKey) { $group = $item; break; }
        }
        if (!$group) throw new ValidateException('题目版本不存在');
        $question = null;
        foreach ($group['stats'] as $item) {
            if ($item['id'] === $questionId) { $question = $item; break; }
        }
        if (!$question || $question['type'] !== 'text') throw new ValidateException('文字题不存在');
        $page = max(1, $page); $limit = max(1, min(100, $limit));
        $taskIds = array_column($group['tasks'], 'id');
        $taskTitles = array_column($group['tasks'], 'title', 'id');
        $rows = Db::name('member_survey_answer')->whereIn('task_id', $taskIds)
            ->field('task_id,uid,answers,submitted_at')->order('submitted_at', 'desc')->order('id', 'desc')
            ->select()->toArray();
        $matching = [];
        foreach ($rows as $row) {
            $answer = trim((string)($this->decode((string)$row['answers'])[$questionId] ?? ''));
            if ($answer === '') continue;
            $matching[] = ['task_id' => (int)$row['task_id'], 'task_title' => $taskTitles[$row['task_id']] ?? '',
                'uid' => (int)$row['uid'], 'answer' => $answer, 'submitted_at' => (int)$row['submitted_at']];
        }
        $total = count($matching);
        $list = array_slice($matching, ($page - 1) * $limit, $limit);
        $uids = array_column($list, 'uid');
        $users = $uids ? Db::name('user')->whereIn('uid', $uids)->column('nickname,real_name,phone', 'uid') : [];
        foreach ($list as &$row) {
            $user = $users[$row['uid']] ?? [];
            $row['display_name'] = ($user['real_name'] ?? '') ?: (($user['nickname'] ?? '') ?: '未填写姓名');
            $row['phone'] = $user['phone'] ?? '';
            unset($row['uid']);
        }
        return ['question_title' => $question['title'], 'total' => $total, 'page' => $page, 'list' => $list];
    }

    /** 导出使用与分析页一致的题目快照和全部提交记录，不受页面分页限制。 */
    public function questionnaireExportData(int $id): array
    {
        $analysis = $this->questionnaireAnalysis($id);
        $taskGroups = [];
        foreach ($analysis['groups'] as $groupIndex => $group) {
            foreach ($group['tasks'] as $task) {
                $taskGroups[(int)$task['id']] = ['number' => $groupIndex + 1,
                    'task_title' => $task['title'], 'questions' => $group['stats']];
            }
        }
        if (!$taskGroups) return ['analysis' => $analysis, 'answers' => []];

        $rows = Db::name('member_survey_answer')->whereIn('task_id', array_keys($taskGroups))
            ->field('id,task_id,uid,answers,submitted_at')->order('submitted_at', 'desc')->order('id', 'desc')
            ->select()->toArray();
        $uids = array_unique(array_column($rows, 'uid'));
        $users = $uids ? Db::name('user')->whereIn('uid', $uids)->column('nickname,real_name,phone', 'uid') : [];
        $export = [];
        foreach ($rows as $row) {
            $task = $taskGroups[(int)$row['task_id']];
            $user = $users[$row['uid']] ?? [];
            $answers = $this->decode((string)$row['answers']);
            $responses = [];
            foreach ($task['questions'] as $index => $question) {
                $value = $answers[$question['id']] ?? null;
                if ($question['type'] === 'text') {
                    $display = trim((string)($value ?? ''));
                } else {
                    $optionLabels = array_column($question['options'], 'label', 'id');
                    $choices = $question['type'] === 'multiple' ? (is_array($value) ? $value : [])
                        : (($value === null || $value === '') ? [] : [$value]);
                    $labels = [];
                    foreach ($choices as $choice) {
                        $choice = (string)$choice;
                        $labels[] = $optionLabels[$choice] ?? $choice;
                    }
                    $display = implode('、', $labels);
                }
                $responses[] = ['number' => $index + 1, 'title' => $question['title'],
                    'type' => $question['type'], 'answer' => $display, 'answered' => $display !== ''];
            }
            $export[] = ['group_number' => $task['number'], 'task_title' => $task['task_title'],
                'display_name' => ($user['real_name'] ?? '') ?: (($user['nickname'] ?? '') ?: '未填写姓名'),
                'phone' => (string)($user['phone'] ?? ''), 'submitted_at' => (int)$row['submitted_at'],
                'responses' => $responses];
        }
        return ['analysis' => $analysis, 'answers' => $export];
    }

    /** 保存可复用问卷并递增修订标识，已发布题目不会跟随变化。 */
    public function saveQuestionnaire(array $input, int $creatorId, int $id = 0): int
    {
        $title = trim((string)($input['title'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) throw new ValidateException('问卷名称应为 1 至 150 字');
        if (mb_strlen($description) > 500) throw new ValidateException('问卷说明不能超过 500 字');
        $questions = $this->normalizedQuestions($input['questions'] ?? null);
        $data = ['title' => $title, 'description' => $description,
            'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE), 'updated_at' => $this->now()];
        if ($id) {
            Db::transaction(function()use($id,$data):void{
                $old=Db::name('member_survey_questionnaire')->where('id',$id)->lock(true)->find();
                if(!$old)throw new ValidateException('问卷不存在');
                Db::name('member_survey_questionnaire')->where('id',$id)->update($data+['revision'=>(int)$old['revision']+1]);
            });
            return $id;
        }
        $data += ['revision' => 1, 'creator_id' => $creatorId, 'created_at' => $this->now()];
        return (int)Db::name('member_survey_questionnaire')->insertGetId($data);
    }

    private function normalizedFilters($filters): array
    {
        if (!is_array($filters)) $filters = [];
        $cash = !empty($filters['cash_enabled']);
        $visits = !empty($filters['visits_enabled']);
        $rawUids = $filters['uids'] ?? [];
        if (!is_array($rawUids)) throw new ValidateException('指定会员 UID 不正确');
        $uids = [];
        foreach ($rawUids as $uid) {
            if (!ctype_digit((string)$uid) || (int)$uid <= 0) throw new ValidateException('指定会员 UID 不正确');
            $uids[] = (int)$uid;
        }
        $uids = array_values(array_unique($uids));
        // 空名单是有效的公开二维码任务，不应被旧定向调查资格条件阻断。
        $start = trim((string)($filters['start_date'] ?? ''));
        $end = trim((string)($filters['end_date'] ?? ''));
        foreach ([$start, $end] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date)) {
                throw new ValidateException('统计日期不正确');
            }
        }
        if ($start !== '' && $end !== '' && $start > $end) throw new ValidateException('统计开始日期不能晚于结束日期');
        $out = ['cash_enabled' => $cash, 'visits_enabled' => $visits, 'start_date' => $start, 'end_date' => $end, 'uids' => $uids];
        foreach (['cash_min', 'cash_max', 'visits_min', 'visits_max'] as $key) {
            $value = $filters[$key] ?? '';
            if ($value !== '' && (!is_numeric($value) || (float)$value < 0 || (strpos($key, 'visits_') === 0 && (string)(int)$value !== (string)$value))) {
                throw new ValidateException('筛选数值不正确');
            }
            $out[$key] = $value === '' ? '' : (string)$value;
        }
        foreach (['cash', 'visits'] as $metric) {
            if ($out[$metric . '_min'] !== '' && $out[$metric . '_max'] !== '' && (float)$out[$metric . '_min'] > (float)$out[$metric . '_max']) {
                throw new ValidateException('筛选最小值不能大于最大值');
            }
        }
        return $out;
    }

    /** 只创建或编辑草稿；任务题目以发布时问卷为准，调用方提供已认证管理员。 */
    public function save(array $input, int $creatorId, int $id = 0): int
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) throw new ValidateException('任务标题应为 1 至 150 字');
        $description = trim((string)($input['description'] ?? ''));
        if (mb_strlen($description) > 500) throw new ValidateException('说明不能超过 500 字');
        $questionnaireId = (int)($input['questionnaire_id'] ?? 0);
        $questionnaire = $this->questionnaire($questionnaireId);
        $questions = $questionnaire['questions'];
        $old = $id ? $this->task($id) : null;
        if ($old && $old['status'] !== 'draft') throw new ValidateException('已发布任务不能修改');
        $filters = array_key_exists('filters', $input)
            ? $this->normalizedFilters($input['filters'])
            : ($old ? $old['filters'] : []);
        $deadline = trim((string)($input['deadline'] ?? ''));
        $deadlineTs = 0;
        if ($deadline !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) || date('Y-m-d', strtotime($deadline)) !== $deadline) throw new ValidateException('截止日期不正确');
            $deadlineTs = strtotime($deadline . ' 23:59:59');
            if ($deadlineTs < $this->now()) throw new ValidateException('截止日期不能早于今天');
        }
        $data = [
            'title' => $title, 'description' => $description, 'questionnaire_id' => $questionnaireId,
            'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
            'filters' => json_encode($filters, JSON_UNESCAPED_UNICODE), 'deadline' => $deadlineTs,
        ];
        if ($id) {
            Db::transaction(function()use($id,$data):void{
                $locked=Db::name('member_survey_task')->where('id',$id)->lock(true)->find();
                if(!$locked||$locked['status']!=='draft')throw new ValidateException('任务已发布，不能修改');
                Db::name('member_survey_task')->where('id',$id)->update($data);
            });
            return $id;
        }
        $data += ['status' => 'draft', 'creator_id' => $creatorId, 'created_at' => $this->now()];
        return (int)Db::name('member_survey_task')->insertGetId($data);
    }

    /** 返回总部任务分页及提醒/回答数量，不在读取时补写业务记录。 */
    public function listTasks(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page); $limit = max(1, min(100, $limit));
        $query = Db::name('member_survey_task');
        $count = $query->count();
        $rows = Db::name('member_survey_task')->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $giftConfigs = $rows ? app()->make(MemberSurveyRewardConfigurationServices::class)
            ->getConfigMap(MemberSurveyRewardConfigurationServices::GIFT_TYPE_SURVEY, array_column($rows, 'id')) : [];
        $ownGiftIds = $rows ? Db::name('member_survey_reward_config')->where('gift_type', MemberSurveyRewardConfigurationServices::GIFT_TYPE_SURVEY)
            ->whereIn('relation_id', array_column($rows, 'id'))->column('relation_id') : [];
        $ownGiftLookup = array_fill_keys(array_map('intval', $ownGiftIds), true);
        $questionnaireGiftConfigs = $rows ? app()->make(MemberSurveyRewardConfigurationServices::class)
            ->getConfigMap(MemberSurveyRewardConfigurationServices::GIFT_TYPE_SURVEY_QUESTIONNAIRE, array_column($rows, 'questionnaire_id')) : [];
        foreach ($rows as &$row) {
            $filters = $this->decode((string)$row['filters']);
            $row['targets_configured'] = !empty($filters['cash_enabled']) || !empty($filters['visits_enabled']) || !empty($filters['uids']);
            unset($row['questions'], $row['filters']);
            $row['questionnaire_title'] = $row['questionnaire_id']
                ? (string)Db::name('member_survey_questionnaire')->where('id', $row['questionnaire_id'])->value('title') : '旧版任务问卷';
            $row['target_count'] = Db::name('member_survey_target')->where('task_id', $row['id'])->where('source','<>','qrcode')->count();
            $row['answer_count'] = Db::name('member_survey_answer')->where('task_id', $row['id'])
                ->where('send_round', $row['send_round'])->count();
            $row['total_answer_count'] = Db::name('member_survey_answer')->where('task_id', $row['id'])->where('source','<>','qrcode')->count();
            $row['gift_inherited'] = $row['status'] === 'draft' && (int)$row['questionnaire_id'] > 0
                && !isset($ownGiftLookup[(int)$row['id']]);
            $gift = $row['gift_inherited']
                ? ($questionnaireGiftConfigs[(int)$row['questionnaire_id']] ?? ['product' => [], 'coupon' => []])
                : ($giftConfigs[$row['id']] ?? ['product' => [], 'coupon' => []]);
            $row['gift_count'] = count($gift['product']) + count($gift['coupon']);
        }
        return ['list' => $rows, 'count' => $count];
    }

    /** 总部读取任务详情，包含配置筛选；公开入口使用独立白名单投影。 */
    public function readTask(int $id): array
    {
        return $this->task($id);
    }

    /** 读取草稿中手动指定会员，筛选匹配另由同一预览服务返回。 */
    public function readTargets(int $id): array
    {
        $task = $this->task($id);
        $uids = array_values(array_unique(array_map('intval', $task['filters']['uids'] ?? [])));
        $members = $uids ? Db::name('user')->whereIn('uid', $uids)
            ->field('uid,nickname,real_name,phone')->order('uid', 'asc')->select()->toArray() : [];
        foreach ($members as &$member) $member['display_name'] = $member['real_name'] ?: ($member['nickname'] ?: '未填写姓名');
        return ['task_id' => $id, 'status' => $task['status'], 'filters' => $task['filters'], 'members' => $members];
    }

    /** 提醒名单只在草稿修改，发布后固定且不限制公开扫码资格。 */
    public function saveTargets(int $id, array $input): void
    {
        $filters = $this->normalizedFilters($input);
        // 与发布使用相同任务锁，不能在已经发布后仍向操作者报告名单保存成功。
        Db::transaction(function()use($id,$filters):void{
            $task=Db::name('member_survey_task')->where('id',$id)->lock(true)->find();
            if(!$task||$task['status']!=='draft')throw new ValidateException('只有草稿任务可修改提醒名单');
            Db::name('member_survey_task')->where('id',$id)->update(['filters'=>json_encode($filters,JSON_UNESCAPED_UNICODE)]);
        });
    }

    /** 总部按姓名/手机号查找定向提醒会员，返回有界结果供继续缩小检索。 */
    public function searchMembers(string $keyword): array
    {
        $keyword = trim($keyword);
        if (mb_strlen($keyword) < 2 || mb_strlen($keyword) > 50) throw new ValidateException('请输入至少 2 个字的会员姓名或手机号');
        $members = Db::name('user')->where('status', 1)
            ->where(function ($q) { $q->whereNull('delete_time')->whereOr('delete_time', 0); })
            ->whereLike('uid|nickname|real_name|phone', '%' . $keyword . '%')
            ->field('uid,nickname,real_name,phone')->order('uid', 'desc')->limit(20)->select()->toArray();
        foreach ($members as &$member) $member['display_name'] = $member['real_name'] ?: ($member['nickname'] ?: '未填写姓名');
        return $members;
    }

    /** 同一查询范围内，金额与看板有效订单部分使用相同的订单条件和金额表达式。 */
    public function preview(array $input): array
    {
        $filters = $this->normalizedFilters($input);
        $cashMap = []; $visitMap = [];
        // 仅复用010已登记的签名现金分配和完成服务读模型，不从旧订单再建第二套经营口径。
        $stores = array_map('intval', Db::name('system_store')->where('is_del', 0)->column('id'));
        $range = ['start' => $filters['start_date'], 'end' => $filters['end_date'] ?: $this->today()];
        $reader = new StoreUnifiedReportPhaseFourServices();
        if ($stores && $filters['cash_enabled']) {
            $cents = [];
            foreach ($reader->customerCashRows($stores, $range) as $fact) {
                $uid=(int)($fact['member_id']??0); if($uid<=0)continue;
                $cents[$uid]=($cents[$uid]??0)+(int)$fact['amount_cents'];
            }
            foreach($cents as$uid=>$amount)$cashMap[$uid]=bcdiv((string)$amount,'100',2);
        }
        if ($stores && $filters['visits_enabled']) {
            $seen=[];
            foreach (StoreCustomerVisitEligibility::filter($reader->customerServiceRows($stores,$range)) as $fact) {
                $uid=(int)($fact['member_id']??0);if($uid<=0)continue;
                $key=$fact['store_id'].':'.$uid.':'.$fact['business_date'];
                if(isset($seen[$key]))continue;$seen[$key]=true;$visitMap[$uid]=($visitMap[$uid]??0)+1;
            }
        }
        $matched = [];
        $candidates = $filters['cash_enabled'] ? array_keys($cashMap) : array_keys($visitMap);
        foreach ($candidates as $uid) {
            $amount = (float)($cashMap[$uid] ?? 0);
            $visits = (int)($visitMap[$uid] ?? 0);
            if ($filters['cash_enabled'] && (($filters['cash_min'] !== '' && $amount < (float)$filters['cash_min']) || ($filters['cash_max'] !== '' && $amount > (float)$filters['cash_max']))) continue;
            if ($filters['visits_enabled'] && (($filters['visits_min'] !== '' && $visits < (int)$filters['visits_min']) || ($filters['visits_max'] !== '' && $visits > (int)$filters['visits_max']))) continue;
            $matched[(int)$uid] = ['uid' => (int)$uid, 'cash_amount' => number_format($amount, 2, '.', ''), 'visit_count' => $visits, 'source' => 'filter'];
        }
        foreach ($filters['uids'] as $uid) {
            if (!isset($matched[$uid])) $matched[$uid] = ['uid' => $uid, 'cash_amount' => (string)($cashMap[$uid] ?? '0.00'), 'visit_count' => $visitMap[$uid] ?? 0, 'source' => 'manual'];
        }
        if (!$matched) return ['count' => 0, 'list' => []];
        $allowed = Db::name('user')->whereIn('uid', array_keys($matched))->where('status', 1)
            ->where(function ($q) { $q->whereNull('delete_time')->whereOr('delete_time', 0); })
            ->field('uid,nickname,real_name,phone')->select()->toArray();
        $list = [];
        foreach ($allowed as $user) {
            $item = $matched[(int)$user['uid']];
            $list[] = $item + ['nickname' => $user['nickname'], 'phone' => $user['phone'],
                'display_name' => $user['real_name'] ?: ($user['nickname'] ?: '未填写姓名')];
        }
        usort($list, static function ($a, $b) { return $a['uid'] <=> $b['uid']; });
        return ['count' => count($list), 'list' => $list];
    }

    /** 同事务固定问卷、提醒名单和010奖励配置，空名单也可发布二维码任务。 */
    public function publish(int $id): array
    {
        $task = $this->task($id);
        if ($task['status'] !== 'draft') throw new ValidateException('只有草稿可发布');
        if ((int)$task['deadline'] > 0 && (int)$task['deadline'] < $this->now()) throw new ValidateException('截止日期已过');
        $questions = $task['questions'];
        if ((int)$task['questionnaire_id'] > 0) {
            $questions = $this->questionnaire((int)$task['questionnaire_id'])['questions'];
        }
        $preview = $this->preview($task['filters']);
        // 目标名单仅用于提醒；二维码任务允许零名单发布，任何扫码用户均可填写。
        Db::transaction(function () use ($id, $preview, $questions, $task) {
            $locked=Db::name('member_survey_task')->where('id',$id)->lock(true)->find();
            if(!$locked||$locked['status']!=='draft'||$locked['questions']!==json_encode($task['questions'],JSON_UNESCAPED_UNICODE)||$locked['filters']!==json_encode($task['filters'],JSON_UNESCAPED_UNICODE)|| (int)$locked['questionnaire_id']!==(int)$task['questionnaire_id'] || $locked['title']!==$task['title'] || (int)$locked['deadline']!==(int)$task['deadline'])throw new ValidateException('草稿已变化，请刷新后发布');
            // 问卷配置与奖励均锁到事务结束，新编辑只能供下一任务使用。
            $questionnaire=Db::name('member_survey_questionnaire')->where('id',$task['questionnaire_id'])->lock(true)->find();
            if(!$questionnaire)throw new ValidateException('问卷不存在');
            $questions=$this->decode($questionnaire['questions']);
            $changed = Db::name('member_survey_task')->where('id', $id)->where('status', 'draft')
                ->update(['status' => 'published', 'published_at' => $this->now(), 'sent_at' => $this->now(),
                    'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE), 'entry_token' => bin2hex(random_bytes(24))]);
            if (!$changed) throw new ValidateException('任务已发布');
            $gift = app()->make(MemberSurveyGiftServices::class);
            $gift->snapshotQuestionnaireConfig($id, (int)$task['questionnaire_id']);
            $gift->validateTargets($id, array_map('intval', array_column($preview['list'], 'uid')));
            foreach (array_chunk($preview['list'], 500) as $chunk) {
                $rows = [];
                foreach ($chunk as $item) {
                    $rows[] = ['task_id' => $id, 'uid' => $item['uid'], 'cash_amount' => $item['cash_amount'],
                        'visit_count' => $item['visit_count'], 'source' => $item['source']];
                }
                Db::name('member_survey_target')->insertAll($rows);
            }
        });
        return ['task_id' => $id, 'target_count' => $preview['count']];
    }

    /** 停止新的答卷，不删除既有回答或已获得的奖励履约关系。 */
    public function stop(int $id): void
    {
        $task = $this->task($id);
        if ($task['status'] !== 'published') throw new ValidateException('任务未在进行中');
        Db::name('member_survey_task')->where('id', $id)->update(['status' => 'stopped', 'stopped_at' => $this->now()]);
    }

    /** 对发布时固定的目标名单开启新一轮；旧回答与赠送记录完整保留。 */
    public function resend(int $id): array
    {
        return Db::transaction(function () use ($id) {
            $task = Db::name('member_survey_task')->where('id', $id)->lock(true)->find();
            if (!$task || !$this->isActive($task)) throw new ValidateException('只有进行中的调查任务可以发送');
            $count = Db::name('member_survey_target')->where('task_id', $id)->where('source','<>','qrcode')->count();
            $round = (int)$task['send_round'] + 1;
            Db::name('member_survey_task')->where('id', $id)->update([
                'send_round' => $round, 'sent_at' => $this->now(),
            ]);
            return ['task_id' => $id, 'send_round' => $round, 'target_count' => $count];
        });
    }

    private function firstPending(int $uid): ?array
    {
        $rows = Db::name('member_survey_target')->alias('r')
            ->join('member_survey_task t', 't.id=r.task_id')
            ->where('r.uid', $uid)->where('r.source','<>','qrcode')->whereRaw('r.completed_round < t.send_round')->where('t.status', 'published')
            ->where(function ($q) { $q->where('t.deadline', 0)->whereOr('t.deadline', '>=', $this->now()); })
            ->field('t.id,t.title,t.description,t.deadline,t.published_at,t.send_round,t.sent_at')
            ->order('t.published_at', 'asc')->order('t.id', 'asc')->select()->toArray();
        usort($rows, static function ($a, $b) {
            $aResent = (int)$a['send_round'] > 1;
            $bResent = (int)$b['send_round'] > 1;
            if ($aResent !== $bResent) return $aResent ? -1 : 1;
            if ($aResent && (int)$a['sent_at'] !== (int)$b['sent_at']) {
                return (int)$b['sent_at'] <=> (int)$a['sent_at'];
            }
            return ((int)$a['published_at'] <=> (int)$b['published_at']) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        foreach ($rows as $row) {
            $row['gifts'] = app()->make(MemberSurveyGiftServices::class)->summary((int)$row['id']);
            return $row;
        }
        return null;
    }

    /** 仅检查认证会员的定向提醒队列，不影响二维码直接进入任务。 */
    public function pending(int $uid): ?array
    {
        return $this->firstPending($uid);
    }

    /** 串行登记每日提醒展示，提醒弹窗不构成答卷或领奖资格。 */
    public function claim(int $uid, int $taskId, bool $repeatOnEntry = false): ?array
    {
        return Db::transaction(function () use ($uid, $taskId, $repeatOnEntry) {
            Db::name('user')->where('uid', $uid)->lock(true)->find();
            $task = $this->firstPending($uid);
            if (!$task || (int)$task['id'] !== $taskId) return null;
            // 每日唯一行仅作留痕；是否再次弹出由本轮是否提交决定。
            $popup = Db::name('member_survey_popup')->where('uid', $uid)->where('task_id', $taskId)
                ->where('show_date', $this->today())->where('send_round', $task['send_round'])->find();
            if ($popup && !$repeatOnEntry) return null; // 旧版小程序保留每天一次，避免启动时重复弹窗。
            if (!$popup) {
                Db::name('member_survey_popup')->insert(['uid' => $uid, 'task_id' => $taskId,
                    'show_date' => $this->today(), 'send_round' => $task['send_round'], 'shown_at' => $this->now()]);
            }
            return $task;
        });
    }

    /** 公开问题与本人完成状态分开投影，不暴露其他会员或提醒名单。 */
    public function memberDetail(int $uid, int $id): array
    {
        $task = $this->task($id);
        if (!$this->isActive($task)) throw new ValidateException('调查任务已结束');
        $target = Db::name('member_survey_target')->where('task_id', $id)->where('uid', $uid)->find();
        $completed=$target && (int)$target['completed_round'] >= (int)$task['send_round'];
        return ['id' => $id, 'title' => $task['title'], 'description' => $task['description'],
            'questions' => $task['questions'], 'deadline' => $task['deadline'],
            'send_round' => (int)$task['send_round'], 'completed'=>(bool)$completed,
            'reward'=>$completed?app()->make(MemberSurveyGiftServices::class)->memberStatus($id,$uid):null,
            'gifts' => app()->make(MemberSurveyGiftServices::class)->summary($id)];
    }

    /** 真实会员和固定轮次防重；答卷先完成，奖励另事务履约并可重试。 */
    public function submit(int $uid, int $id, $answers, int $expectedRound): array
    {
        $task = $this->task($id);
        if (!$this->isActive($task)) throw new ValidateException('调查任务已结束');
        // 已打开的旧轮次不能悄悄提交到新轮次，防止重发后重复奖励。
        if ($expectedRound !== (int)$task['send_round']) throw new ValidateException('调查已重新发送，请重新打开问卷');
        if (!is_array($answers)) throw new ValidateException('请填写问卷');
        $target = Db::name('member_survey_target')->where('task_id', $id)->where('uid', $uid)->find();
        // 二维码不限提醒名单；会员身份由登录态提供，提交事务内建立参与关系。
        if ($target && (int)$target['completed_round'] >= (int)$task['send_round']) return app()->make(MemberSurveyGiftServices::class)->memberStatus($id,$uid);
        $normalized = [];
        foreach ($task['questions'] as $question) {
            $key = (string)$question['id'];
            $value = $answers[$key] ?? null;
            $optionIds = array_column($question['options'], 'id');
            if ($question['type'] === 'text') {
                if($value!==null&&!is_string($value))throw new ValidateException('文字回答格式不正确');
                $value = trim((string)($value ?? ''));
                if (mb_strlen($value) > 1000) throw new ValidateException('文字回答不能超过 1000 字');
                if ($question['required'] && $value === '') throw new ValidateException('请填写必答题');
            } elseif ($question['type'] === 'single') {
                if($value!==null&&!is_string($value)&&!is_int($value))throw new ValidateException('单选答案格式不正确');
                $value = $value === null || $value === '' ? '' : (string)$value;
                if ($question['required'] && $value === '') throw new ValidateException('请填写必答题');
                if ($value !== '' && !in_array($value, $optionIds, true)) throw new ValidateException('选项不正确');
            } else {
                if ($value === null) $value = [];
                if (!is_array($value) || count($value) !== count(array_unique($value,SORT_REGULAR))) throw new ValidateException('多选答案不正确');
                foreach($value as$choice)if(!is_string($choice)&&!is_int($choice))throw new ValidateException('多选答案格式不正确');
                $value = array_map('strval', $value);
                if ($question['required'] && !$value) throw new ValidateException('请填写必答题');
                foreach ($value as $choice) if (!in_array($choice, $optionIds, true)) throw new ValidateException('选项不正确');
            }
            $normalized[$key] = $value;
        }
        if (count(array_diff(array_keys($answers), array_keys($normalized)))) throw new ValidateException('答案中存在无效题目');
        $reward = Db::transaction(function () use ($id, $uid, $normalized, $task) {
            $lockedTask = Db::name('member_survey_task')->where('id', $id)->lock(true)->find();
            if (!$lockedTask || !$this->isActive($lockedTask) || (int)$lockedTask['send_round'] !== (int)$task['send_round']) {
                throw new ValidateException('调查已重新发送，请重新打开问卷');
            }
            // 锁定会员以串行化同会员的公开扫码加入，避免并发创建参与关系。
            $member=Db::name('user')->where('uid',$uid)->where('status',1)->lock(true)->find();
            if(!$member || !empty($member['is_del']) || (!empty($member['delete_time']) && $member['delete_time']!=='0000-00-00 00:00:00'))throw new ValidateException('请使用有效会员账号提交');
            $lockedTarget = Db::name('member_survey_target')->where('task_id', $id)->where('uid', $uid)->lock(true)->find();
            if (!$lockedTarget) {
                Db::name('member_survey_target')->insert(['task_id'=>$id,'uid'=>$uid,'source'=>'qrcode']);
                $lockedTarget=['completed_round'=>0];
            }
            if ((int)$lockedTarget['completed_round'] >= (int)$task['send_round']) {
                return (int)Db::name('member_survey_reward')->where('task_id',$id)->where('uid',$uid)->order('id','desc')->value('id');
            }
            $answerId=(int)Db::name('member_survey_answer')->insertGetId(['task_id' => $id, 'uid' => $uid,
                'answers' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'send_round' => $task['send_round'], 'submitted_at' => $this->now()]);
            Db::name('member_survey_target')->where('task_id', $id)->where('uid', $uid)
                ->update(['submitted_at' => $this->now(), 'completed_round' => $task['send_round']]);
            return app()->make(MemberSurveyGiftServices::class)->enqueueInTx($id,$uid,$answerId,(int)$task['send_round']);
        });
        // 答卷先提交成功；奖励单独履约，失败保留待发状态，不诱导会员重复答卷。
        return app()->make(MemberSurveyGiftServices::class)->fulfil($reward);
    }

    /**
     * 单任务导出读模型：全部轮次一份已提交回答为一行，不按会员去重、不受列表分页影响。
     * 历史回答没有门店快照，按已确认的“会员当前所属门店”分组；汇总和明细共用同一批事实。
     * 只读原任务发布题目和回答，不重新发放赠品，也不修改名单、发送轮次或问卷。
     */
    public function taskExportData(int $id): array
    {
        return Db::transaction(function () use ($id) {
            $task = $this->task($id);
            $rows = Db::name('member_survey_answer')->alias('a')
                ->leftJoin('user u', 'u.uid = a.uid')
                ->leftJoin('system_store s', 's.id = u.belong_store_id')
                ->where('a.task_id', $id)
                ->field('a.id,a.uid,a.send_round,a.submitted_at,a.answers,u.real_name,u.nickname,u.phone,u.belong_store_id,s.name AS store_name')
                ->order('u.belong_store_id', 'asc')->order('a.submitted_at', 'desc')->order('a.id', 'desc')
                ->select()->toArray();
            // 正常门店即使没有完成记录也显示0；已删除门店仍保留其真实归属，不能静默漏掉回答。
            $stores = [];
            foreach (Db::name('system_store')->where('is_del', 0)->field('id,name')->order('id', 'asc')->select()->toArray() as $store) {
                $stores[(int)$store['id']] = ['store_id' => (int)$store['id'], 'store_name' => $store['name'], 'completed_count' => 0];
            }
            $columns = [
                ['key' => 'store_name', 'title' => '会员当前所属门店', 'width' => 24, 'type' => 'text'],
                ['key' => 'answer_id', 'title' => '填写记录ID', 'width' => 16, 'type' => 'text'],
                ['key' => 'uid', 'title' => '会员ID', 'width' => 14, 'type' => 'text'],
                ['key' => 'display_name', 'title' => '会员姓名', 'width' => 20, 'type' => 'text'],
                ['key' => 'phone', 'title' => '手机号', 'width' => 19, 'type' => 'text'],
                ['key' => 'send_round', 'title' => '填写轮次', 'width' => 12, 'type' => 'number'],
                ['key' => 'submitted_at', 'title' => '提交时间（北京时间）', 'width' => 25, 'type' => 'text'],
            ];
            foreach ($task['questions'] as $index => $question) {
                $columns[] = ['key' => 'question_' . $index, 'title' => ($index + 1) . '、' . $question['title'], 'width' => 40, 'type' => 'text'];
            }
            $details = [];
            foreach ($rows as $row) {
                $storeId = max(0, (int)($row['belong_store_id'] ?? 0));
                $storeName = $storeId ? ((string)($row['store_name'] ?? '') ?: '门店#' . $storeId . '（已移除）') : '未归属门店';
                if (!isset($stores[$storeId])) $stores[$storeId] = ['store_id' => $storeId, 'store_name' => $storeName, 'completed_count' => 0];
                $stores[$storeId]['completed_count']++;
                $detail = ['store_name' => $storeName, 'answer_id' => (string)$row['id'], 'uid' => (string)$row['uid'],
                    'display_name' => ($row['real_name'] ?? '') ?: (($row['nickname'] ?? '') ?: '未填写姓名'),
                    'phone' => (string)($row['phone'] ?? ''), 'send_round' => (int)$row['send_round'],
                    'submitted_at' => (new \DateTimeImmutable('@' . $row['submitted_at']))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s')];
                $answers = $this->decode((string)$row['answers']);
                foreach ($task['questions'] as $index => $question) {
                    $value = $answers[$question['id']] ?? null;
                    if ($question['type'] === 'text') {
                        $display = trim((string)($value ?? ''));
                    } else {
                        $labels = array_column($question['options'], 'label', 'id');
                        $choices = $question['type'] === 'multiple' ? (is_array($value) ? $value : [])
                            : (($value === null || $value === '') ? [] : [$value]);
                        // 用发布时题目选项解释历史回答；旧数据中的未知选项保留原值，避免冒充未作答。
                        $display = implode('、', array_map(static function ($choice) use ($labels) {
                            return $labels[(string)$choice] ?? (string)$choice;
                        }, $choices));
                    }
                    $detail['question_' . $index] = $display === '' ? '未答' : $display;
                }
                $details[] = $detail;
            }
            ksort($stores, SORT_NUMERIC);
            if (isset($stores[0])) { $unassigned = $stores[0]; unset($stores[0]); $stores[0] = $unassigned; }
            $asOf = $this->now();
            return ['task' => ['id' => $id, 'title' => $task['title']], 'metric_version' => 'survey-task-completions-v1',
                'data_as_of' => $asOf, 'aggregation_caught_up' => true,
                'scope_note' => '本任务全部轮次，按已提交问卷份数合计；同一会员不同轮次分别计入。',
                'store_note' => '门店按会员当前所属门店分组；未分配门店单列为未归属门店。',
                'as_of_text' => (new \DateTimeImmutable('@' . $asOf))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'),
                'summary' => ['columns' => [
                    ['key' => 'store_name', 'title' => '会员当前所属门店', 'width' => 28, 'type' => 'text'],
                    ['key' => 'completed_count', 'title' => '完成问卷数（份）', 'width' => 24, 'type' => 'number'],
                ], 'total' => ['store_name' => '合计', 'completed_count' => count($details)], 'rows' => array_values($stores)],
                'details' => ['columns' => $columns, 'rows' => $details]];
        });
    }

    /** 题目统计、全部轮次明细和分页总数使用相同答卷，提醒完成率排除公开扫码人群。 */
    public function results(int $id, int $page = 1, int $limit = 20): array
    {
        $task = $this->task($id);
        $page = max(1, $page); $limit = max(1, min(100, $limit));
        $total = Db::name('member_survey_target')->where('task_id', $id)->where('source','<>','qrcode')->count();
        $targetAnswered=Db::name('member_survey_target')->where('task_id',$id)->where('source','<>','qrcode')->where('completed_round',$task['send_round'])->count();
        $answered = Db::name('member_survey_answer')->where('task_id', $id)
            ->where('send_round', $task['send_round'])->count();
        $totalAnswers = Db::name('member_survey_answer')->where('task_id', $id)->count();
        $stats = [];
        foreach ($task['questions'] as $q) {
            $stats[$q['id']] = ['title' => $q['title'], 'type' => $q['type'], 'options' => []];
            foreach ($q['options'] as $option) $stats[$q['id']]['options'][$option['id']] = ['label' => $option['label'], 'count' => 0];
        }
        foreach (Db::name('member_survey_answer')->where('task_id', $id)
            ->where('send_round', $task['send_round'])->field('answers')->select()->toArray() as $row) {
            $answers = $this->decode($row['answers']);
            foreach ($task['questions'] as $q) {
                if ($q['type'] === 'text') continue;
                $value = $answers[$q['id']] ?? [];
                foreach ((array)$value as $choice) {
                    if (isset($stats[$q['id']]['options'][$choice])) $stats[$q['id']]['options'][$choice]['count']++;
                }
            }
        }
        $rows = Db::name('member_survey_answer')->where('task_id', $id)->order('submitted_at', 'desc')->order('id','desc')
            ->page($page, $limit)->select()->toArray();
        $uids = array_column($rows, 'uid');
        $users = $uids ? Db::name('user')->whereIn('uid', $uids)->column('nickname,real_name,phone', 'uid') : [];
        foreach ($rows as &$row) {
            $row['answers'] = $this->decode($row['answers']);
            $row['nickname'] = $users[$row['uid']]['nickname'] ?? '';
            $row['phone'] = $users[$row['uid']]['phone'] ?? '';
            $row['display_name'] = ($users[$row['uid']]['real_name'] ?? '') ?: ($row['nickname'] ?: '未填写姓名');
        }
        return ['task' => $task, 'target_count' => $total, 'answer_count' => $answered,
            'total_answer_count' => $totalAnswers,
            'answer_rate' => $total ? round($targetAnswered * 100 / $total, 2) : 0,
            'stats' => array_values($stats), 'list' => $rows];
    }
}
