#!/usr/bin/env python3
"""Materialize the bounded evidence into the isolated local D3 verification database.

No connection is opened here. The generated SQL hard-selects the local fixture
database and is never a production recovery payload.
"""
import argparse
import json
import re
from pathlib import Path


def literal(value):
    if value is None:
        return 'NULL'
    if isinstance(value, (int, float)):
        return str(value)
    text = str(value)
    return "''" if not text else 'CONVERT(0x'+text.encode().hex()+' USING utf8mb4)'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    data = json.loads(Path(args.source).read_text())
    with Path(args.output).open('w') as out:
        out.write('USE codex_d3_010_680;\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n')
        for table, rows in data['tables'].items():
            if not re.fullmatch('[a-z0-9_]+', table):
                raise ValueError('Invalid table')
            for row in rows:
                if table == 'system_store_staff':
                    row = {k:v for k,v in row.items() if k != 'active_employee_id'}
                if any(not re.fullmatch('[A-Za-z0-9_]+', field) for field in row):
                    raise ValueError('Invalid field')
                columns = ','.join('`'+field+'`' for field in row)
                out.write('INSERT INTO `eb_'+table+'` ('+columns+') VALUES ('+','.join(map(literal,row.values()))+');\n')
        out.write('COMMIT;\n')


if __name__ == '__main__':
    main()
