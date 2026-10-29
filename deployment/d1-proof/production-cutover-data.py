"""Build and locally verify the final data-only import on Hetzner."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import sqlite3
import sys

ROOT = Path('/var/www/familyfunds-d1-staging/storage/app/private/prepared-production-runtime')


def prepare(snapshot, label):
    if os.geteuid() != 0 or snapshot.parent != ROOT or not label.replace('-', '').isalnum():
        raise RuntimeError('Root-only prepared paths are required')
    os.umask(0o077)
    spec = importlib.util.spec_from_file_location('convert_postgres', ROOT / 'tools-20261027/convert-postgres.py')
    converter = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(converter)
    target = ROOT / (label + '.sqlite')
    if target.exists():
        raise RuntimeError('Refusing to replace a prepared migration')
    db = sqlite3.connect(target)
    db.executescript((ROOT / 'cutover-schema-20261027.sql').read_text())
    db.close()
    converter.convert(snapshot, target, ROOT / (label + '-schema-data.sql'), ROOT / (label + '-manifest.json'))
    db = sqlite3.connect(target)
    db.row_factory = sqlite3.Row
    previous = sqlite3.connect(ROOT / 'cutover-d1-before-20261027.sqlite')
    old_sequences = dict(previous.execute('select name,seq from sqlite_sequence'))
    old_reservations = dict(previous.execute('select name,next_id from d1_staging_sequences'))
    for row in db.execute('select name,seq from sqlite_sequence').fetchall():
        value = max(row['seq'], old_sequences.get(row['name'], 0))
        db.execute('update sqlite_sequence set seq=? where name=?', (value, row['name']))
        db.execute('insert into d1_staging_sequences(name,next_id) values(?,?)', (row['name'], max(value, old_reservations.get(row['name'], 0))))
    db.execute('insert into d1_staging_read_revision(id,revision) values(1,?)', (1 + previous.execute('select coalesce(max(revision),0) from d1_staging_read_revision').fetchone()[0],))
    db.commit()
    manifest = json.loads((ROOT / (label + '-manifest.json')).read_text())
    order = manifest['dependency_load_order']
    quote = converter.identifier
    literal = lambda value: db.execute('select quote(?)', [value]).fetchone()[0]
    statements = ['pragma defer_foreign_keys=on;']
    for name in order:
        columns = {r['name']: r for r in db.execute('pragma table_info(' + quote(name) + ')')}
        nullable = sorted({r['from'] for r in db.execute('pragma foreign_key_list(' + quote(name) + ')') if not columns[r['from']]['notnull'] and not columns[r['from']]['pk']})
        if nullable:
            statements.append('update ' + quote(name) + ' set ' + ','.join(quote(c) + '=null' for c in nullable) + ';')
    statements += ['delete from ' + quote(name) + ';' for name in reversed(order)]
    patches = []
    for name in order:
        columns = {r['name']: r for r in db.execute('pragma table_info(' + quote(name) + ')')}
        nullable = {r['from'] for r in db.execute('pragma foreign_key_list(' + quote(name) + ')') if not columns[r['from']]['notnull'] and not columns[r['from']]['pk']}
        primary = [r['name'] for r in sorted(columns.values(), key=lambda r: r['pk']) if r['pk']]
        for row in db.execute('select * from ' + quote(name)):
            original = dict(row)
            values = {c: None if c in nullable else v for c, v in original.items()}
            statements.append('insert into ' + quote(name) + '(' + ','.join(quote(c) for c in values) + ') values(' + ','.join(literal(v) for v in values.values()) + ');')
            restored = {c: original[c] for c in nullable if original[c] is not None}
            if restored:
                if not primary:
                    raise RuntimeError('Nullable restoration requires a primary key')
                patches.append('update ' + quote(name) + ' set ' + ','.join(quote(c) + '=' + literal(v) for c, v in restored.items()) + ' where ' + ' and '.join(quote(c) + '=' + literal(original[c]) for c in primary) + ';')
    statements += patches
    statements.append('delete from sqlite_sequence;')
    sequences = [dict(r) for r in db.execute('select name,seq from sqlite_sequence order by name')]
    statements += ['insert into sqlite_sequence(name,seq) values(' + literal(r['name']) + ',' + str(r['seq']) + ');' for r in sequences]
    sql = '\n'.join(statements) + '\n'
    (ROOT / (label + '-data.sql')).write_text(sql)
    rehearsal = ROOT / (label + '-rehearsal.sqlite')
    shutil.copyfile(ROOT / 'cutover-d1-before-20261027.sqlite', rehearsal)
    check = sqlite3.connect(rehearsal)
    check.row_factory = sqlite3.Row
    check.execute('pragma foreign_keys=on')
    check.executescript('begin;\n' + sql + '\ncommit;')
    expected, actual = converter.manifest(db), converter.manifest(check)
    if actual != expected or actual['foreign_key_violations'] or actual['integrity'] != 'ok':
        raise RuntimeError('Final replacement rehearsal differs from the converted source')
    if [dict(r) for r in check.execute('select name,seq from sqlite_sequence order by name')] != sequences:
        raise RuntimeError('Sequence verification failed')
    files = []
    for name in ['report_artifacts', 'reconciliation_imports']:
        for item in db.execute('select disk,path from ' + quote(name)):
            if item['disk'] not in ['local', 'public'] or item['path'].startswith('/') or '..' in item['path'] or '\\' in item['path']:
                raise RuntimeError('Unexpected referenced file path')
            base = Path('/var/www/contribution-tracker/storage/app/' + ('public' if item['disk'] == 'public' else 'private'))
            path = (base / item['path']).resolve()
            if not path.is_relative_to(base.resolve()) or not path.is_file():
                raise RuntimeError('A final referenced file is missing')
            data = path.read_bytes()
            files.append({'disk': item['disk'], 'path': item['path'], 'source': str(path), 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})
    file_manifest = {'files': files, 'missing': 0, 'snapshot_sha256': hashlib.sha256(snapshot.read_bytes()).hexdigest()}
    (ROOT / (label + '-files.json')).write_text(json.dumps(file_manifest, indent=2) + '\n')
    result = {'status': 'passed', 'tables': actual['tables'], 'source_rows': manifest['source_rows'],
              'foreign_key_violations': 0, 'integrity': 'ok', 'sequences': sequences,
              'adapter_sequences': [dict(r) for r in db.execute('select name,next_id from d1_staging_sequences order by name')],
              'files': [{'path_sha256': hashlib.sha256((r['disk'] + ':' + r['path']).encode()).hexdigest(), 'bytes': r['bytes'], 'sha256': r['sha256']} for r in files],
              'sql_sha256': hashlib.sha256(sql.encode()).hexdigest(), 'sql_md5': hashlib.md5(sql.encode()).hexdigest(), 'sql_bytes': len(sql.encode()),
              'snapshot_sha256': file_manifest['snapshot_sha256'], 'label': label, 'source_written': False}
    (ROOT / (label + '-verified.json')).write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))


if __name__ == '__main__':
    prepare(Path(sys.argv[1]), sys.argv[2])
