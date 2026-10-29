"""Convert a read-only PostgreSQL JSON snapshot into a pre-migrated SQLite schema."""
import argparse
import hashlib
import json
import math
import os
import sqlite3
from pathlib import Path


def identifier(name):
    if not name or not all(c.isascii() and (c.isalnum() or c == '_') for c in name):
        raise ValueError('Invalid migration identifier')
    return '"' + name + '"'


def canonical(row):
    values = {key: int(value) if isinstance(value, float) and value.is_integer() else value for key, value in row.items()}
    return json.dumps(values, sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False)


def manifest(connection):
    tables = {}
    for (table,) in connection.execute("select name from sqlite_master where type='table' and name not glob 'sqlite_*' and name not glob 'd1_*' order by name"):
        rows = [canonical(dict(row)) for row in connection.execute('select * from ' + identifier(table))]
        rows.sort(key=lambda value: value.encode('utf-8'))
        tables[table] = {'rows': len(rows), 'sha256': hashlib.sha256('\n'.join(rows).encode()).hexdigest()}
    return {'tables': tables, 'foreign_key_violations': len(list(connection.execute('pragma foreign_key_check'))), 'integrity': connection.execute('pragma integrity_check').fetchone()[0]}


def convert(snapshot_path, sqlite_path, sql_path, manifest_path):
    source = json.loads(Path(snapshot_path).read_text())
    connection = sqlite3.connect(sqlite_path)
    connection.row_factory = sqlite3.Row
    available = {row[0] for row in connection.execute("select name from sqlite_master where type='table' and name not glob 'sqlite_*'")}
    missing = set(source['tables']) - available
    if missing:
        raise ValueError('Source tables missing from target schema: ' + ', '.join(sorted(missing)))
    connection.execute('pragma foreign_keys=off')
    connection.execute('begin')
    added_columns = {}
    for table in available:
        connection.execute('delete from ' + identifier(table))
    connection.execute('delete from sqlite_sequence')
    for table, data in source['tables'].items():
        columns = data['columns']
        target_columns = {row['name'] for row in connection.execute('pragma table_info(' + identifier(table) + ')')}
        if set(columns) - target_columns:
            raise ValueError('Source columns missing from target schema: ' + table)
        if target_columns - set(columns):
            added_columns[table] = sorted(target_columns - set(columns))
        query = 'insert into ' + identifier(table) + ' (' + ','.join(identifier(c) for c in columns) + ') values (' + ','.join('?' for _ in columns) + ')'
        for row in data['rows']:
            values = []
            for name, kind in columns.items():
                value = row[name]
                if value is not None:
                    if kind == 'boolean':
                        if not isinstance(value, bool):
                            raise ValueError('Expected PostgreSQL boolean')
                        value = int(value)
                    elif kind in ['smallint', 'integer', 'bigint']:
                        value = int(value)
                        if abs(value) > 9007199254740991:
                            raise ValueError('Integer cannot round trip through the D1 JavaScript binding')
                    elif kind in ['json', 'jsonb']:
                        value = json.dumps(json.loads(value) if isinstance(value, str) else value, sort_keys=True, ensure_ascii=False, separators=(',', ':'), allow_nan=False)
                    elif isinstance(value, float) and not math.isfinite(value):
                        raise ValueError('Non-finite source value')
                values.append(value)
            connection.execute(query, values)
        sequence = data.get('sequence')
        if sequence is not None:
            current = connection.execute('select seq from sqlite_sequence where name=?', [table]).fetchone()
            desired = int(sequence['last_value']) - (0 if sequence['is_called'] else 1)
            desired = max(desired, current[0] if current else 0)
            connection.execute('delete from sqlite_sequence where name=?', [table])
            connection.execute('insert into sqlite_sequence(name,seq) values(?,?)', [table, desired])
    connection.commit()
    connection.execute('pragma foreign_keys=on')
    result = manifest(connection)
    if result['foreign_key_violations'] or result['integrity'] != 'ok':
        raise ValueError('Converted SQLite failed integrity checks')
    ddl = [row[0] + ';' for row in connection.execute("select sql from sqlite_master where sql is not null and name not glob 'sqlite_*' order by case type when 'table' then 0 else 1 end, name")]
    pending = {}
    nullable = {}
    primary_keys = {}
    for table in available:
        columns = {row['name']: row for row in connection.execute('pragma table_info(' + identifier(table) + ')')}
        primary_keys[table] = [name for name, row in sorted(columns.items(), key=lambda pair: pair[1]['pk']) if row['pk']]
        foreign_keys = list(connection.execute('pragma foreign_key_list(' + identifier(table) + ')'))
        nullable[table] = {key['from'] for key in foreign_keys if not columns[key['from']]['notnull'] and not columns[key['from']]['pk']}
        pending[table] = {key['table'] for key in foreign_keys if key['from'] not in nullable[table]}
    order = []
    while pending:
        ready = sorted(table for table, dependencies in pending.items() if not dependencies)
        if not ready:
            raise ValueError('Non-nullable foreign-key cycle cannot be loaded safely: ' + ', '.join(sorted(pending)))
        for table in ready:
            order.append(table)
            del pending[table]
        for dependencies in pending.values():
            dependencies.difference_update(ready)
    def literal(value):
        return connection.execute('select quote(?)', [value]).fetchone()[0]
    inserts = []
    patches = []
    for table in order:
        for row in connection.execute('select * from ' + identifier(table)):
            original = dict(row)
            projection = {key: None if key in nullable[table] else value for key, value in original.items()}
            inserts.append('insert into ' + identifier(table) + ' (' + ','.join(identifier(key) for key in projection) + ') values (' + ','.join(literal(value) for value in projection.values()) + ');')
            restore = {key: original[key] for key in sorted(nullable[table]) if original[key] is not None}
            if restore:
                if not primary_keys[table]:
                    raise ValueError('Nullable-link restoration requires a primary key: ' + table)
                patches.append('update ' + identifier(table) + ' set ' + ','.join(identifier(key) + '=' + literal(value) for key,value in restore.items()) + ' where ' + ' and '.join(identifier(key) + '=' + literal(original[key]) for key in primary_keys[table]) + ';')
    sequences = [statement for statement in connection.iterdump() if statement.startswith(('INSERT INTO "sqlite_sequence"', 'DELETE FROM "sqlite_sequence"', 'INSERT INTO sqlite_sequence', 'DELETE FROM sqlite_sequence'))]
    sql = '\n'.join([*ddl, *inserts, *patches, *sequences]) + '\n'
    result['nullable_foreign_key_patches'] = len(patches)
    result['dependency_load_order'] = order
    Path(sql_path).write_text(sql)
    os.chmod(sql_path, 0o600)
    result.update({'source_created_at': source['created_at'], 'source_rows': sum(len(x['rows']) for x in source['tables'].values()), 'source_tables': len(source['tables']), 'excluded': source['excluded'], 'added_target_columns': added_columns, 'sql_sha256': hashlib.sha256(sql.encode()).hexdigest(), 'sql_md5': hashlib.md5(sql.encode()).hexdigest(), 'sql_bytes': len(sql.encode())})
    Path(manifest_path).write_text(json.dumps(result, indent=2) + '\n')
    os.chmod(manifest_path, 0o600)
    print(json.dumps({'source_rows': result['source_rows'], 'target_tables': len(result['tables']), 'integrity': result['integrity'], 'foreign_key_violations': result['foreign_key_violations'], 'added_target_columns': added_columns, 'sql_bytes': result['sql_bytes'], 'sql_md5': result['sql_md5']}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    for argument in ['snapshot', 'sqlite', 'sql', 'manifest']:
        parser.add_argument('--' + argument, required=True)
    args = parser.parse_args()
    convert(args.snapshot, args.sqlite, args.sql, args.manifest)
