<?php

declare(strict_types=1);

namespace app\services\report;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * 报表共用 XLSX 文件层：只序列化已授权的列、完整结果、合计和说明，不查询、不计算指标。
 * 每张工作表逐行写 XML 后封装，兼容未消耗报表的生成器，内存不随导出行数增长。
 */
final class StoreReportXlsxWriter
{
    public const MIME_TYPE='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const NS='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    /** 共享文本表也按顺序流式写入，不在 PHP 内存中建立全量文本索引。 */
    private $strings;
    private $stringCount=0;

    /** 小型同步下载只把压缩后的文件读入内存；大范围任务直接使用 writeFile。 */
    public function render(array $export,string $title='报表',array $options=[]):string
    {
        $path=tempnam(sys_get_temp_dir(),'report-xlsx-');
        if($path===false)throw new \RuntimeException('报表导出文件创建失败');
        try{
            $this->writeFile($path,$export,$title,$options);
            $bytes=file_get_contents($path);
            if($bytes===false||substr($bytes,0,2)!=='PK')throw new \RuntimeException('报表 XLSX 文件生成失败');
            return $bytes;
        }finally{@unlink($path);}
    }

    /** 输出路径由服务端生成；失败删除不完整文件，不能发布部分数据。 */
    public function writeFile(string $path,array $export,string $title='报表',array $options=[]):void
    {
        $temporary=tempnam(sys_get_temp_dir(),'report-xlsx-parts-');
        if($temporary===false)throw new \RuntimeException('报表临时目录创建失败');
        @unlink($temporary);
        if(!mkdir($temporary,0700))throw new \RuntimeException('报表临时目录创建失败');
        $zip=new \ZipArchive();$opened=false;
        try{
            $this->stringCount=0;$this->strings=new \XMLWriter();
            if(!$this->strings->openUri($temporary.'/strings.xml'))throw new \RuntimeException('报表文本表创建失败');
            $this->strings->startDocument('1.0','UTF-8','yes');$this->strings->startElementNS(null,'sst',self::NS);
            $sections=!empty($export['sections'])?array_values($export['sections']):[$export];
            $names=[];$guides=[];$files=[];
            foreach($sections as $index=>$section){
                $name=$this->sheetName((string)($section['label']??$title),$names);
                $names[]=$name;$file=$temporary.'/sheet'.($index+1).'.xml';
                $count=$this->sheet($file,$section,$options);
                $files[]=$file;
                $guides[]=['name'=>$name,'count'=>$count,'columns'=>$section['columns']??[],
                    'row_sources'=>$section['row_sources']??[]];
            }
            $names[]='列名取值来源';$guideFile=$temporary.'/sheet'.count($names).'.xml';
            $this->guide($guideFile,$export,$guides,$options);$files[]=$guideFile;
            $this->closeXml($this->strings);$this->strings=null;
            if($zip->open($path,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true)throw new \RuntimeException('报表 XLSX 封装失败');
            $opened=true;@chmod($path,0600);
            if(!$zip->addFile($temporary.'/strings.xml','xl/sharedStrings.xml'))throw new \RuntimeException('报表文本表封装失败');
            $types='<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $types.='<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
            $sheets='';$relations='<Relationship Id="rIdStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
            foreach($files as $index=>$file){
                $n=$index+1;
                if(!$zip->addFile($file,'xl/worksheets/sheet'.$n.'.xml'))throw new \RuntimeException('报表工作表封装失败');
                $types.='<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $sheets.='<sheet name="'.htmlspecialchars($names[$index],ENT_XML1|ENT_QUOTES,'UTF-8').'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
                $relations.='<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            }
            $relations.='<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $parts=[
                '[Content_Types].xml'=>'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'.$types.'</Types>',
                '_rels/.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml'=>'<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>',
                'xl/_rels/workbook.xml.rels'=>'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>',
                'xl/styles.xml'=>$this->styles(),
            ];
            foreach($parts as $name=>$xml)if(!$zip->addFromString($name,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml))throw new \RuntimeException('报表工作簿封装失败');
            if(!$zip->close())throw new \RuntimeException('报表 XLSX 文件保存失败');
            $opened=false;
        }catch(\Throwable $error){
            if($opened){$zip->close();$opened=false;}
            @unlink($path);throw $error;
        }finally{
            $this->strings=null;
            foreach(glob($temporary.'/*')?:[] as $file)@unlink($file);
            @rmdir($temporary);
        }
    }

    /** 通用下载只改变文件格式；调用方必须先完成菜单、数据范围和查询校验。 */
    public function response(string $report,array $export)
    {
        $title=trim((string)($export['title']??''))?:'报表';
        if($report==='store_item_analysis')$content=(new StoreItemAnalysisXlsxExporter())->render($export);
        elseif(StoreUnifiedReportDetailXlsxExporter::supports($report) && (substr($report,-7)==='_detail'||mb_strpos($title,'明细')!==false))$content=(new StoreUnifiedReportDetailXlsxExporter())->render($report,$export);
        else $content=$this->render($export,$title);
        $filename=preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u','-',$title).'-'.date('YmdHis').'.xlsx';
        return $this->binaryResponse($content,$filename);
    }

    /** 文件来自本次授权导出服务；先读压缩文件并删除临时副本，再由框架发送，兼容长驻进程。 */
    public function fileResponse(array $file)
    {
        $path=(string)($file['path']??'');
        try{
            $content=file_get_contents($path);
            if($content===false||substr($content,0,2)!=='PK')throw new \RuntimeException('报表 XLSX 文件不可用');
        }finally{if($path!=='')@unlink($path);}
        return $this->binaryResponse($content,(string)($file['filename']??'报表.xlsx'));
    }

    /** 所有文件下载共用真实类型、UTF-8 文件名和禁止缓存策略，不能把 JSON/CSV 仅改后缀返回。 */
    private function binaryResponse(string $content,string $filename)
    {
        $filename=preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/u','-',$filename);
        if(!preg_match('/\.xlsx$/i',$filename)||substr($content,0,2)!=='PK')throw new \RuntimeException('报表下载格式无效');
        return \think\Response::create($content,'html')->header([
            'Content-Type'=>self::MIME_TYPE,'Content-Length'=>(string)strlen($content),
            'Content-Disposition'=>'attachment; filename="report.xlsx"; filename*=UTF-8\'\''.rawurlencode($filename),
            'Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff',
        ]);
    }

    /** 分组只按后端显式列键及路径构建；相同叶子文字也不能把两个指标合为一列。 */
    private function headerPaths(array $columns,array $export):array
    {
        $groups=[];
        foreach(($export['column_groups']??[]) as $group){
            $keys=$group['column_keys']??$group['columnKeys']??$group['columns']??[];
            foreach($keys as $key){$key=is_array($key)?($key['key']??''):$key;
                if(isset($groups[$key]))throw new \InvalidArgumentException('报表导出列分组重复');
                $groups[$key]=['label'=>(string)($group['label']??$group['name']??''),'rowspan'=>(int)($group['rowspan']??$group['row_span']??0),'single'=>count($keys)===1];
            }
        }
        $paths=[];
        foreach($columns as $column){
            $key=(string)$column['key'];$group=$groups[$key]??null;
            if($group!==null){$paths[]=($group['single']&&$group['rowspan']>1)?[$group['label']]:[$group['label'],(string)$column['label']];continue;}
            $raw=$column['header_path']??$column['headerPath']??$column['header_levels']??$column['headerLevels']??[];
            if(is_string($raw))$raw=explode('/',$raw);
            $path=[];foreach($raw as $part){$label=trim((string)(is_array($part)?($part['label']??$part['name']??''):$part));if($label!=='')$path[]=$label;}
            $groupLabel=trim((string)($column['group_label']??$column['groupLabel']??$column['group']??''));
            $paths[]=$path?:($groupLabel!==''?[$groupLabel,(string)$column['label']]:[(string)$column['label']]);
        }
        return $paths;
    }

    /** 校验工作表容量；记录按迭代器读取，不把异步任务重新装成数组。 */
    private function sheet(string $file,array $export,array $options):int
    {
        $columns=array_values($export['columns']??[]);$keys=[];
        if(!$columns||count($columns)>16384)throw new \InvalidArgumentException('报表 XLSX 导出列数量无效');
        foreach($columns as $column){$key=(string)($column['key']??'');if($key===''||isset($keys[$key])||trim((string)($column['label']??''))==='')throw new \InvalidArgumentException('报表 XLSX 导出列定义无效');$keys[$key]=true;}
        $paths=$this->headerPaths($columns,$export);$depth=max(array_map('count',$paths));
        $summary=$export['summary_row']??[];$hasSummary=is_array($summary)&&$summary!==[];
        $summaryFirst=($options['summary_position']??'first')!=='last';
        $dataStart=$depth+1+($hasSummary&&$summaryFirst?1:0);$last=Coordinate::stringFromColumnIndex(count($columns));
        $fixed=0;foreach($columns as $column){if(($column['fixed']??'')!=='left')break;$fixed++;}
        $fixed=(int)($options['fixed_columns']??$fixed);
        $xml=$this->xml($file);$xml->startElement('sheetViews');$xml->startElement('sheetView');$xml->writeAttribute('workbookViewId','0');
        $xml->startElement('pane');$xml->writeAttribute('ySplit',(string)($dataStart-1));
        if($fixed>0)$xml->writeAttribute('xSplit',(string)$fixed);
        $xml->writeAttribute('topLeftCell',Coordinate::stringFromColumnIndex($fixed+1).$dataStart);
        $xml->writeAttribute('activePane',$fixed>0?'bottomRight':'bottomLeft');$xml->writeAttribute('state','frozen');$xml->endElement();$xml->endElement();$xml->endElement();
        $xml->startElement('cols');foreach($columns as $i=>$column){$xml->startElement('col');$xml->writeAttribute('min',(string)($i+1));$xml->writeAttribute('max',(string)($i+1));$xml->writeAttribute('width',(string)max(12,min(60,($column['width']??154)/7)));$xml->writeAttribute('customWidth','1');$xml->endElement();}$xml->endElement();
        $xml->startElement('sheetData');$merges=[];
        for($level=0;$level<$depth;$level++){
            $xml->startElement('row');$xml->writeAttribute('r',(string)($level+1));
            for($i=0;$i<count($columns);){
                if(!isset($paths[$i][$level])){$i++;continue;}
                $leaf=$level===count($paths[$i])-1;$end=$i;
                if(!$leaf)while($end+1<count($columns)&&$level<count($paths[$end+1])-1&&array_slice($paths[$i],0,$level+1)===array_slice($paths[$end+1],0,$level+1))$end++;
                $from=Coordinate::stringFromColumnIndex($i+1).($level+1);$to=Coordinate::stringFromColumnIndex($end+1).($leaf?$depth:$level+1);
                $this->cell($xml,$from,$paths[$i][$level],false,1);
                if($from!==$to)$merges[]=$from.':'.$to;
                $i=$end+1;
            }$xml->endElement();
        }
        $row=$depth+1;
        if($hasSummary&&$summaryFirst)$this->dataRow($xml,$row++,$columns,$summary,true);
        $count=0;$records=$export['records']??[];
        if(!is_iterable($records))throw new \InvalidArgumentException('报表导出明细无效');
        foreach($records as $record){$this->dataRow($xml,$row++,$columns,$record);$count++;}
        if($hasSummary&&!$summaryFirst)$this->dataRow($xml,$row++,$columns,$summary,true);
        if(!empty($export['summary_cards'])){
            $row++;$this->plainRow($xml,$row++,['当前查询汇总']);
            foreach($export['summary_cards'] as $card)$this->plainRow($xml,$row++,[$card['label'],$card['value']??'-']);
        }
        $xml->endElement();
        // 顶部合计保持可见；只在没有顶部合计的单层表头中为数据区启用 Excel 筛选。
        if($depth===1&&(!$hasSummary||!$summaryFirst)){$xml->startElement('autoFilter');$xml->writeAttribute('ref','A1:'.$last.max(1,$dataStart+$count-1));$xml->endElement();}
        if($merges){$xml->startElement('mergeCells');$xml->writeAttribute('count',(string)count($merges));foreach($merges as $merge){$xml->startElement('mergeCell');$xml->writeAttribute('ref',$merge);$xml->endElement();}$xml->endElement();}
        $this->closeXml($xml);return $count;
    }

    /** 数值类型来自列契约；身份、手机号、券码及任意业务文本永远不变为公式。 */
    private function dataRow(\XMLWriter $xml,int $row,array $columns,array $record,bool $summary=false):void
    {
        $xml->startElement('row');$xml->writeAttribute('r',(string)$row);
        foreach($columns as $i=>$column){
            $value=$record[$column['key']]??'-';$type=(string)($column['value_type']??$column['type']??$column['manual_input']['value_type']??'');
            $numeric=!empty($column['summable'])||!empty($column['money'])||!empty($column['_xlsx_numeric'])||in_array($type,['integer','number','money','decimal','integer_cents','nonnegative_integer'],true);
            $numeric=$numeric&&is_scalar($value)&&is_numeric($value)&&is_finite((float)$value);
            $money=$type==='money'||!empty($column['money']);
            $style=$summary?($numeric?($money?6:4):7):($numeric?($money?3:2):0);
            $this->cell($xml,Coordinate::stringFromColumnIndex($i+1).$row,$value,$numeric,$style);
        }$xml->endElement();
    }

    private function plainRow(\XMLWriter $xml,int $row,array $values,int $style=0):void
    {
        $xml->startElement('row');$xml->writeAttribute('r',(string)$row);
        foreach($values as $i=>$value)$this->cell($xml,Coordinate::stringFromColumnIndex($i+1).$row,$value,false,$style);
        $xml->endElement();
    }

    /** Excel 单元格容量不能靠截断掩盖；不合法文本拒绝生成，避免下载一个损坏工作簿。 */
    private function cell(\XMLWriter $xml,string $coordinate,$value,bool $numeric,int $style):void
    {
        if((int)preg_replace('/^[A-Z]+/','',$coordinate)>1048576)throw new \InvalidArgumentException('报表超过 XLSX 每张表 1048576 行容量，请缩小查询范围');
        if(!is_scalar($value)&&$value!==null)throw new \InvalidArgumentException('报表导出单元格必须是文字或数值');
        $text=(string)$value;
        if(!mb_check_encoding($text,'UTF-8')||strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))>65534)throw new \InvalidArgumentException('报表单元格内容超出 XLSX 容量，导出未完成，请联系管理员处理');
        // 复用已有库的 OOXML 字符编码，保留控制字符原值而非静默删除。
        $text=\PhpOffice\PhpSpreadsheet\Shared\StringHelper::controlCharacterPHP2OOXML($text);
        if(preg_match('/[\x{FFFE}\x{FFFF}]/u',$text))throw new \InvalidArgumentException('报表文本包含 XLSX 不支持的字符');
        $xml->startElement('c');$xml->writeAttribute('r',$coordinate);$xml->writeAttribute('s',(string)$style);
        $xml->writeAttribute('t',$numeric?'n':'s');
        if($numeric)$xml->writeElement('v',$text);
        else{
            $xml->writeElement('v',(string)$this->stringCount++);
            $this->strings->startElement('si');$this->strings->startElement('t');
            $this->strings->writeAttribute('xml:space','preserve');$this->strings->text($text);
            $this->strings->endElement();$this->strings->endElement();
        }
        $xml->endElement();
    }

    private function guide(string $file,array $export,array $guides,array $options):void
    {
        $xml=$this->xml($file);$xml->startElement('cols');foreach([30,100] as $i=>$width){$xml->startElement('col');$xml->writeAttribute('min',(string)($i+1));$xml->writeAttribute('max',(string)($i+1));$xml->writeAttribute('width',(string)$width);$xml->writeAttribute('customWidth','1');$xml->endElement();}$xml->endElement();$xml->startElement('sheetData');
        $this->plainRow($xml,1,['口径版本',$export['metric_version']??'-']);
        $this->plainRow($xml,2,['明细记录数',(string)array_sum(array_column($guides,'count'))]);
        $row=(int)($options['guide_header_row']??4);$this->plainRow($xml,$row++,['列名','取值来源'],1);
        foreach($guides as $guide){
            if(count($guides)>1)$this->plainRow($xml,$row++,[$guide['name'],''],1);
            $paths=$this->headerPaths($guide['columns'],['column_groups'=>$export['column_groups']??[]]);
            foreach($guide['columns'] as $i=>$column)$this->plainRow($xml,$row++,[implode('/',$paths[$i]),(string)($column['source_explanation']??$column['logic']??'-')]);
            foreach($guide['row_sources'] as $source)$this->plainRow($xml,$row++,[$source['label']??'',$source['description']??'']);
        }
        $xml->endElement();$this->closeXml($xml);
    }

    private function xml(string $file):\XMLWriter
    {
        $xml=new \XMLWriter();if(!$xml->openUri($file))throw new \RuntimeException('报表工作表创建失败');
        $xml->startDocument('1.0','UTF-8','yes');$xml->startElementNS(null,'worksheet',self::NS);return $xml;
    }
    private function closeXml(\XMLWriter $xml):void{$xml->endElement();$xml->endDocument();$xml->flush();}
    private function sheetName(string $name,array $used):string
    {
        $base=mb_substr(trim(preg_replace('/[\\\\\/?*\[\]:\x00-\x1F]/u','-',$name)," '\t\n\r"),0,31)?:'报表';
        $name=$base;$n=1;while(in_array(mb_strtolower($name),array_map('mb_strtolower',array_merge($used,['列名取值来源'])),true)){$suffix='-'.++$n;$name=mb_substr($base,0,31-mb_strlen($suffix)).$suffix;}return $name;
    }
    /** 金额样式保留分精度；显示格式不回写数值或改变后端统计口径。 */
    private function styles():string
    {
        return '<styleSheet xmlns="'.self::NS.'"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.########"/></numFmts><fonts count="2"><font><sz val="11"/><name val="等线"/></font><font><b/><sz val="11"/><name val="等线"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE3EDF8"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
