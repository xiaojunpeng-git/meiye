import { saveAs } from 'file-saver';

const XLSX = require('xlsx-js-style');

const questionType = { single: '单选', multiple: '多选', text: '文字问答' };
const text = value => value == null ? '' : String(value);
// 下载文件名去除控制字符和系统保留字符，不能让任务/问卷标题产生路径或损坏文件名。
const fileTitle = value => Array.from(text(value), char => char.charCodeAt(0) < 32 ? '_' : char)
  .join('').replace(/[\\/:*?"<>|]/g, '_').slice(0, 80);

// 可选说明行只用于单任务导出；原有问卷分析导出仍从第1行开始，结构保持不变。
function makeSheet(headers, rows, widths, intro = []) {
  const sheet = XLSX.utils.aoa_to_sheet([...intro.map(line => [line]), headers, ...rows]);
  sheet['!cols'] = widths.map(wch => ({ wch }));
  sheet['!autofilter'] = { ref: XLSX.utils.encode_range({ s: { r: intro.length, c: 0 }, e: { r: intro.length + rows.length, c: headers.length - 1 }}) };
  sheet['!rows'] = [...intro.map(() => ({ hpt: 32 })), { hpt: 28 }];
  if (intro.length) sheet['!merges'] = intro.map((_, row) => ({ s: { r: row, c: 0 }, e: { r: row, c: headers.length - 1 }}));
  const range = XLSX.utils.decode_range(sheet['!ref']);
  for (let row = 0; row <= range.e.r; row++) {
    for (let col = 0; col <= range.e.c; col++) {
      const cell = sheet[XLSX.utils.encode_cell({ r: row, c: col })];
      if (!cell) continue;
      cell.s = row === intro.length
        ? { fill: { fgColor: { rgb: '2469B2' }}, font: { bold: true, color: { rgb: 'FFFFFF' }}, alignment: { vertical: 'center', wrapText: true }}
        : { alignment: { vertical: 'center', wrapText: true }};
    }
  }
  return sheet;
}

/** 只将后端列契约和统计结果写入工作簿；不在浏览器按会员去重或重算门店合计。 */
export function downloadMemberSurveyTaskExcel(data) {
  const intro = [`任务：${data.task.title}（任务ID：${data.task.id}）`, data.scope_note,
    data.store_note, `导出时间（北京时间）：${data.as_of_text}`];
  const toSheet = (section, rows) => makeSheet(section.columns.map(column => column.title),
    rows.map(row => section.columns.map(column => column.type === 'number' ? row[column.key] : text(row[column.key]))),
    section.columns.map(column => column.width), intro);
  const summary = toSheet(data.summary, [data.summary.total, ...data.summary.rows]);
  const details = toSheet(data.details, data.details.rows);
  data.summary.columns.forEach((column, index) => {
    const cell = summary[XLSX.utils.encode_cell({ r: intro.length + 1, c: index })];
    cell.s = { fill: { fgColor: { rgb: 'EAF2FA' }}, font: { bold: true }, alignment: { vertical: 'center' }};
  });
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, summary, '门店完成数量');
  XLSX.utils.book_append_sheet(workbook, details, '门店问卷明细');
  // 文字类型直接写为字符串，手机号/记录ID保留原样；用户答案不会被当作Excel公式执行。
  const output = XLSX.write(workbook, { bookType: 'xlsx', type: 'array', cellStyles: true });
  const title = fileTitle(data.task.title) || '会员调查任务';
  saveAs(new Blob([output], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
    `${title}-任务${data.task.id}-导出结果-${data.as_of_text.slice(0, 10)}.xlsx`);
}

/** 固定目标名单与后台筛选同口径；手机号和 UID 作为文本保留，Excel 不重算资格。 */
export function downloadMemberSurveyTargetsExcel(data) {
  const headers = ['会员UID', '会员姓名', '手机号', '现金金额', '到店次数', '来源', '是否完成问卷'];
  const rows = data.list.map(row => [
    text(row.uid), text(row.display_name), text(row.phone), text(row.cash_amount),
    Number(row.visit_count) || 0, row.source === 'manual' ? '手动指定' : '条件筛选',
    row.completed ? '已完成' : '未完成'
  ]);
  const intro = [`任务：${text(data.task_title)}（任务ID：${data.task_id}，第 ${data.send_round} 轮）`,
    `当前筛选命中：${data.count} 人`];
  const sheet = makeSheet(headers, rows, [16, 22, 20, 16, 14, 16, 16], intro);
  for (let index = intro.length + 1; index < intro.length + 1 + rows.length; index++) {
    for (const column of [0, 2]) {
      const cell = sheet[XLSX.utils.encode_cell({ r: index, c: column })];
      if (cell) { cell.t = 's'; cell.z = '@'; }
    }
  }
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, sheet, '目标会员名单');
  const output = XLSX.write(workbook, { bookType: 'xlsx', type: 'array', cellStyles: true });
  const title = fileTitle(data.task_title) || '会员调查任务';
  const day = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Shanghai' });
  saveAs(new Blob([output], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
    `${title}-任务${data.task_id}-目标名单-${day}.xlsx`);
}

function statisticsRows(groups) {
  const rows = [];
  groups.forEach((group, groupIndex) => {
    group.stats.forEach((stat, questionIndex) => {
      const options = stat.type === 'text' ? [null] : (stat.options.length ? stat.options : [null]);
      options.forEach(option => rows.push([
        `发布题目组 ${groupIndex + 1}`, group.task_count, group.target_count, group.answer_count,
        group.tasks.map(task => task.title).join('、'), questionIndex + 1, text(stat.title),
        questionType[stat.type] || stat.type, stat.answered_count, stat.skipped_count,
        option ? text(option.label) : '', option ? option.count : '',
        option ? (stat.answered_count ? option.count / stat.answered_count : 0) : ''
      ]));
    });
  });
  return rows.length ? rows : [['暂无已发布任务']];
}

function answerRows(answers) {
  const rows = [];
  answers.forEach(answer => {
    answer.responses.forEach(response => rows.push([
      `发布题目组 ${answer.group_number}`, text(answer.task_title), text(answer.display_name),
      text(answer.phone), answer.submitted_at ? (Number(answer.submitted_at) / 86400 + 25569 + 8 / 24) : '',
      response.number, text(response.title), questionType[response.type] || response.type,
      text(response.answer), response.answered ? '已答' : '未答'
    ]));
  });
  return rows.length ? rows : [['暂无会员回答']];
}

export function downloadMemberSurveyExcel(data) {
  const analysis = data.analysis;
  const statsHeaders = ['发布题目组', '关联任务数', '提醒人次', '已填写份数', '关联任务', '题号', '题目', '题型', '有效回答', '未答', '选项', '选择人数', '占比'];
  const answerHeaders = ['发布题目组', '任务', '会员姓名', '手机号', '提交时间', '题号', '题目', '题型', '回答', '状态'];
  const stats = makeSheet(statsHeaders, statisticsRows(analysis.groups), [18, 13, 13, 15, 32, 8, 36, 13, 13, 10, 26, 13, 12]);
  const answers = makeSheet(answerHeaders, answerRows(data.answers), [18, 30, 18, 19, 22, 8, 36, 13, 50, 10]);
  const statsRange = XLSX.utils.decode_range(stats['!ref']);
  for (let row = 1; row <= statsRange.e.r; row++) {
    const cell = stats[XLSX.utils.encode_cell({ r: row, c: 12 })];
    if (cell && cell.t === 'n') cell.z = '0.0%';
  }
  const answerRange = XLSX.utils.decode_range(answers['!ref']);
  for (let row = 1; row <= answerRange.e.r; row++) {
    const phone = answers[XLSX.utils.encode_cell({ r: row, c: 3 })];
    if (phone) { phone.t = 's'; phone.z = '@'; }
    const submitted = answers[XLSX.utils.encode_cell({ r: row, c: 4 })];
    if (submitted && submitted.t === 'n') submitted.z = 'yyyy-mm-dd hh:mm:ss';
  }
  const workbook = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook, stats, '题目统计');
  XLSX.utils.book_append_sheet(workbook, answers, '回答明细');
  const output = XLSX.write(workbook, { bookType: 'xlsx', type: 'array', cellStyles: true });
  const title = fileTitle(analysis.questionnaire.title) || '会员调查问卷';
  const day = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Shanghai' });
  saveAs(new Blob([output], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), `${title}_问卷分析_${day}.xlsx`);
}
