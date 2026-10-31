import XLSX from 'xlsx-js-style';
import { saveAs } from 'file-saver';

/** 后端已筛选的完整报表直接导出；第二张表保存业务来源说明，不在浏览器重算合计。 */
export function createBusinessReportWorkbook(report) {
  const rows=[report.header,...report.export.map(row=>report.filekey.map(key=>row[key] == null?'':row[key]))];
  const workbook=XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(workbook,XLSX.utils.aoa_to_sheet(rows),'活动流水');
  const sources=[['列名','取值来源'],...Object.keys(report.sources||{}).map(label=>[label,report.sources[label]])];
  sources.push(['合计金额','按导出时已查询的条件汇总全部命中记录的原付款金额，含已退款记录，不是退款后净额。']);
  const sourceSheet=XLSX.utils.aoa_to_sheet(sources);sourceSheet['!cols']=[{wch:22},{wch:90}];
  XLSX.utils.book_append_sheet(workbook,sourceSheet,'列名取值来源');
  return workbook;
}

/** 手机号和长流水号保留文本，金额沿用服务端原精度，不能用界面整元数字替代。 */
export default function exportBusinessReport(report) {
  const workbook=createBusinessReportWorkbook(report);
  saveAs(new Blob([XLSX.write(workbook,{bookType:'xlsx',type:'array'})],{type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}),report.filename+'.xlsx');
}
