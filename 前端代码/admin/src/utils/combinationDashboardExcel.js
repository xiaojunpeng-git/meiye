import { saveAs } from 'file-saver';
const XLSX = require('xlsx-js-style');

const valueText = value => Array.isArray(value) ? value.join('、') : (value == null || value === '' ? '全部' : String(value));
const dimensionNames = { member: '推广人', store: '业绩归属门店', activity: '拼团活动' };
const statusNames = { formed: '已成团', pending: '拼团中', failed: '未成团／失败', unformed: '全部未成团' };

/**
 * 只组织服务端已授权的明细、完整合计和统计口径，不在浏览器重算业绩。
 * 金额、订单号均以原始字符串写入：保留分精度与前导零，会员名称也不会成为Excel公式。
 * XLSX参数可注入，允许测试真实生成的工作簿而不触发浏览器下载。
 */
export function createCombinationDashboardWorkbook(data, engine = XLSX) {
  const columns = data.columns || data.filekey.map((key, index) => ({ key, title: data.header[index] }));
  const toRow = row => columns.map(column => row[column.key] == null ? '' : String(row[column.key]));
  const total = columns.map((column, index) => index === 0 ? '合计' : (data.summary && data.summary[column.key] != null ? String(data.summary[column.key]) : ''));
  const rows = [columns.map(column => column.title), total, ...(data.export || []).map(toRow)];
  const details = engine.utils.aoa_to_sheet(rows);
  details['!cols'] = columns.map(column => ({ wch: Math.max(14, Math.round((column.width || 120) / 7)) }));
  details['!autofilter'] = { ref: engine.utils.encode_range({s:{r:0,c:0},e:{r:rows.length-1,c:columns.length-1}}) };
  for (let index = 0; index < columns.length; index++) {
    for (let row = 0; row < 2; row++) {
      const cell = details[engine.utils.encode_cell({r:row,c:index})];
      if (cell) cell.s = { font: { bold: true, color: {rgb: row ? '284F73' : 'FFFFFF'} }, fill:{fgColor:{rgb:row ? 'EDF4FA' : '7D2934'}} };
    }
  }
  const meta = data.metadata || {}, filters = meta.filters || {};
  const metadataRows = [
    ['项目','内容'], ['报表名称',data.filename || '拼团业绩明细'], ['数据范围',meta.scope_note || '当前账号授权范围'],
    ['查询记录数',String(data.count == null ? (data.export || []).length : data.count)],
    ['统计日期口径',meta.date_basis || '订单支付日期'], ['开始日期',valueText(filters.start_date)], ['结束日期',valueText(filters.end_date)],
    ['活动编号',valueText(filters.activity_ids || filters.activity_id)], ['门店编号',valueText(filters.store_ids)],
    ['下钻维度',dimensionNames[filters.dimension] || '全部'], ['下钻对象编号',valueText(filters.dimension_id)],
    ['拼团状态',statusNames[filters.status] || '全部'], ['金额单位',meta.amount_unit || '元，保留分精度'],
    ['导出时间',meta.generated_at || ''], ['时区',meta.timezone || ''],
    ...(data.source_notes || []).map((note,index) => [`统计口径 ${index+1}`,typeof note === 'string' ? note : `${note.title}：${note.description}`])
  ];
  const notes = engine.utils.aoa_to_sheet(metadataRows);
  notes['!cols'] = [{wch:24},{wch:110}];
  Object.keys(notes).filter(key => !key.startsWith('!')).forEach(key => { notes[key].s = {alignment:{vertical:'top',wrapText:true}}; });
  const workbook = engine.utils.book_new();
  engine.utils.book_append_sheet(workbook,details,'业绩明细');
  engine.utils.book_append_sheet(workbook,notes,'统计口径');
  return workbook;
}

export default function downloadCombinationDashboardExcel(data) {
  const output = XLSX.write(createCombinationDashboardWorkbook(data), {bookType:'xlsx',type:'array',cellStyles:true});
  const filename = String(data.filename || '拼团业绩明细').replace(/[\\/:*?"<>|\x00-\x1f]/g,'_');
  saveAs(new Blob([output],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),`${filename}.xlsx`);
}
