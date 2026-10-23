"""从唯一迁移正本生成安装契约元数据；不复制业务逻辑或读取实例数据。"""
from pathlib import Path
import re, json
root = Path(__file__).resolve().parents[2]
tables = {}
for path in sorted((root/'后端代码/database/upgrades').glob('20261023_growth_*.sql')):
    for name, body in re.findall(r'CREATE TABLE IF NOT EXISTS `eb_(growth_[a-z_]+)`\s*\((.*?)\) ENGINE',path.read_text(),re.S):
        columns=re.findall(r'(?:^|,)\s*`([a-z_]+)`\s+(?:bigint|int|tinyint|varchar|char|mediumtext|text|date)\b',body,re.I)
        unique={key:re.findall(r'`([a-z_]+)`',fields) for key,fields in re.findall(r'UNIQUE KEY `([a-z_]+)`\s*\(([^)]+)\)',body,re.I)}
        primary=re.search(r'PRIMARY KEY\s*\(([^)]+)\)',body,re.I)
        inline=re.search(r'(?:^|,)\s*`([a-z_]+)`[^,]*?PRIMARY KEY',body,re.I)
        if primary:unique['PRIMARY']=re.findall(r'`([a-z_]+)`',primary.group(1))
        elif inline:unique['PRIMARY']=[inline.group(1)]
        if not columns or not unique:raise RuntimeError('未能识别表结构：'+name)
        tables[name]={'columns':columns,'unique':unique}
text='<?php\n/** 由scripts/growth/build-schema-manifest.py生成；字段与唯一键的权威来源为database/upgrades迁移。 */\nreturn json_decode(<<<\'JSON\'\n'+json.dumps(tables,ensure_ascii=True,indent=2)+"\nJSON\n, true, 512, JSON_THROW_ON_ERROR);\n"
(root/'后端代码/app/services/growth/schema-manifest.php').write_text(text)
print('Schema manifest:',len(tables),'tables;',sum(len(t['columns']) for t in tables.values()),'columns')
