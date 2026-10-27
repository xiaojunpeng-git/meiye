"""隔离工资测试加载器：始终从当前 Git 工作树读取类，不建立业务源码副本。"""
import base64

def prelude(root):
    classes=['report/BasicSalaryManualServices','payroll/PayrollAlgorithmPolicy','payroll/ProjectLaborCommissionCalculator','payroll/ProjectLaborCommissionReadServices','payroll/PositionSalaryRuleResolver','payroll/IndependentPositionSalaryRuleServices','report/BasicSalaryReportServices','report/BasicActivityReportServices','mobile/warehouse/MobileWarehouseServices']
    prelude="<?php chdir('/var/www/html'); require 'vendor/autoload.php';\n"
    prelude+="eval('namespace app\\services\\payroll; function sys_config($key,$default=0) { return $GLOBALS[\"payroll_test_mode\"] ?? 0; }');\n"
    for c in classes:
     b=(root/'后端代码/app/services'/f'{c}.php').read_bytes()
     prelude+="eval(substr(base64_decode('"+base64.b64encode(b).decode()+"'),5));\n"
    prelude+="$GLOBALS['payroll_schema_sql']=base64_decode('"+base64.b64encode((root/'后端代码/database/upgrades/2026-10-27-独立职位工资规则/01-结构升级.sql').read_bytes()).decode()+"');\n"
    prelude+="$GLOBALS['salary_manual_schema_sql']=base64_decode('"+base64.b64encode((root/'后端代码/database/upgrades/2026-10-27-工资人工项与编辑冻结/01-结构升级.sql').read_bytes()).decode()+"');\n"
    return prelude
