"""Prepare a D1 export for an empty recovery database without changing its data."""
import argparse
import json
from pathlib import Path
import re
import sqlite3


def ordered_restore_sql(export_sql):
    source = sqlite3.connect(':memory:')
    try:
        source.executescript(export_sql)
        schema = source.execute("select type,name,sql from sqlite_master where sql is not null and name not glob 'sqlite_*' and name not glob '_cf_*' order by case type when 'table' then 0 when 'index' then 1 when 'view' then 2 else 3 end,name").fetchall()
        tables = {name for kind, name, _sql in schema if kind == 'table'}
        quote = lambda name: '"' + name + '"' if re.fullmatch(r'[a-zA-Z_][a-zA-Z_0-9]*', name) else invalid_identifier()
        literal = lambda value: source.execute('select quote(?)', [value]).fetchone()[0]
        pending, nullable, primary = {}, {}, {}
        for table in tables:
            columns = list(source.execute('pragma table_info(' + quote(table) + ')'))
            primary[table] = [row[1] for row in sorted(columns, key=lambda row: row[5]) if row[5]]
            foreign = list(source.execute('pragma foreign_key_list(' + quote(table) + ')'))
            optional = {row[1] for row in columns if not row[3] and not row[5]}
            nullable[table] = {row[3] for row in foreign if row[3] in optional}
            pending[table] = {row[2] for row in foreign if row[3] not in nullable[table]}
        order = []
        while pending:
            ready = sorted(name for name, dependencies in pending.items() if not dependencies)
            if not ready:
                raise ValueError('Non-nullable foreign-key cycle cannot be restored statement by statement')
            for table in ready:
                order.append(table)
                del pending[table]
            for dependencies in pending.values():
                dependencies.difference_update(ready)
        data, patches = [], []
        for table in order:
            cursor = source.execute('select * from ' + quote(table))
            columns = [column[0] for column in cursor.description]
            for row in cursor:
                original = dict(zip(columns, row))
                projected = {name: None if name in nullable[table] else value for name, value in original.items()}
                data.append('INSERT INTO ' + quote(table) + '(' + ','.join(map(quote, columns)) + ') VALUES(' + ','.join(literal(value) for value in projected.values()) + ');')
                restored = {name: original[name] for name in sorted(nullable[table]) if original[name] is not None}
                if restored:
                    if not primary[table]:
                        raise ValueError('Nullable-link recovery requires a primary key')
                    patches.append('UPDATE ' + quote(table) + ' SET ' + ','.join(quote(name) + '=' + literal(value) for name, value in restored.items()) + ' WHERE ' + ' AND '.join(quote(name) + '=' + literal(original[name]) for name in primary[table]) + ';')
        sequences = [statement for statement in source.iterdump() if statement.startswith(('INSERT INTO "sqlite_sequence"', 'DELETE FROM "sqlite_sequence"', 'INSERT INTO sqlite_sequence', 'DELETE FROM sqlite_sequence'))]
        statements = []
        statements += [sql + ';' for kind, _name, sql in schema if kind == 'table']
        statements += data + patches + sequences
        statements += [sql + ';' for kind, _name, sql in schema if kind != 'table']
        return '\n'.join(statements) + '\n'
    finally:
        source.close()


def invalid_identifier():
    raise ValueError('Unexpected recovery identifier')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('export', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    if args.output.exists():
        raise SystemExit('Refusing to overwrite a prepared recovery file')
    sql = ordered_restore_sql(args.export.read_text(encoding='utf-8'))
    with args.output.open('x', encoding='utf-8') as output:
        args.output.chmod(0o600)
        output.write(sql)
    print(json.dumps({'prepared': True, 'bytes': len(sql.encode())}))
