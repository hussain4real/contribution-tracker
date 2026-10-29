"""Generate atomic first-mutation markers for the cutover rollback boundary."""
import json
import re
import sqlite3
import sys


def preparation(snapshot, checkpoint):
    if not re.fullmatch('[a-f0-9]{64}', snapshot) or not re.fullmatch('cutover-[0-9]{8}t[0-9]{6}z', checkpoint):
        raise ValueError('A frozen snapshot SHA-256 and explicit cutover checkpoint are required')
    details = json.dumps({'phase': 'prepared', 'snapshot_sha256': snapshot,
                          'cutover': checkpoint, 'first_write_utc': None})
    return ("insert into d1_runtime_health(name,status,details,checked_at) values('cutover-write-boundary','healthy','"
            + details + "',unixepoch()) on conflict(name) do update set status=excluded.status,"
            "details=excluded.details,checked_at=excluded.checked_at where d1_runtime_health.status='healthy' "
            "and json_extract(d1_runtime_health.details,'$.phase')='prepared' returning name,status,details")


def statements(tables):
    sql = []
    for table in tables:
        if not re.fullmatch('[a-z_][a-z0-9_]*', table):
            raise ValueError('Invalid table identifier')
        if table.startswith(('sqlite_', '_cf_', 'd1_')) and table != 'd1_runtime_webhook_inbox':
            continue
        if table in ['sessions', 'cache', 'cache_locks', 'features', 'migrations']:
            continue
        for operation in ['insert', 'update', 'delete']:
            name = 'd1_cutover_' + table + '_' + operation
            sql.append('drop trigger if exists "' + name + '"')
            sql.append('create trigger "' + name + '" after ' + operation + ' on "' + table + '" begin '
                       "update d1_runtime_health set checked_at=unixepoch(),details=json_set(details,'$.phase','written','$.first_table','" + table + "','$.first_operation','" + operation + "','$.first_write_utc',strftime('%Y-%m-%dT%H:%M:%fZ','now')) where name='cutover-write-boundary' and status='healthy' and json_extract(details,'$.phase')='armed'; end")
    return sql


def verify():
    db = sqlite3.connect(':memory:')
    db.executescript('create table payments(id integer primary key); create table d1_runtime_health(name text primary key,status text,details text,checked_at integer);')
    snapshot = 'a' * 64
    checkpoint = 'cutover-20261028t000000z'
    for _ in range(2):
        row = db.execute(preparation(snapshot, checkpoint)).fetchone()
        assert row is not None and row[0:2] == ('cutover-write-boundary', 'healthy')
        assert json.loads(row[2]) == {'phase': 'prepared', 'snapshot_sha256': snapshot,
                                      'cutover': checkpoint, 'first_write_utc': None}
    db.execute("create trigger d1_cutover_payments_insert after insert on payments begin update d1_runtime_health set status='obsolete'; end")
    for sql in statements(['payments']):
        db.execute(sql)
    db.execute('insert into payments values(1)')
    assert db.execute("select json_extract(details,'$.phase') from d1_runtime_health").fetchone()[0] == 'prepared'
    db.execute("update d1_runtime_health set details=json_set(details,'$.phase','armed')")
    db.commit()
    assert db.execute(preparation(snapshot, checkpoint)).fetchone() is None
    db.execute('insert into payments values(2)')
    assert json.loads(db.execute('select details from d1_runtime_health').fetchone()[0])['first_table'] == 'payments'
    db.rollback()
    assert db.execute("select json_extract(details,'$.phase') from d1_runtime_health").fetchone()[0] == 'armed'
    db.execute('insert into payments values(2)')
    first = db.execute('select details from d1_runtime_health').fetchone()[0]
    assert db.execute(preparation(snapshot, checkpoint)).fetchone() is None
    db.execute('delete from payments where id=1')
    assert db.execute('select details from d1_runtime_health').fetchone()[0] == first
    assert db.execute("select status from d1_runtime_health").fetchone()[0] == 'healthy'
    assert db.execute("select count(*) from d1_runtime_health where status<>'healthy'").fetchone()[0] == 0
    print(json.dumps({'status': 'passed', 'missing_boundary_created': True, 'preparation_idempotent': True,
                      'armed_and_written_boundaries_preserved': True, 'healthy_monitor_status': True,
                      'transaction_rollback_preserves_boundary': True, 'first_mutation_is_immutable': True}))


if __name__ == '__main__':
    if sys.argv[1:] == ['--self-test']:
        verify()
    elif len(sys.argv) == 4 and sys.argv[1] == '--prepare':
        print(json.dumps({'sql': preparation(sys.argv[2], sys.argv[3]), 'required_returned_rows': 1,
                          'required_status': 'healthy', 'required_phase': 'prepared',
                          'snapshot_sha256': sys.argv[2], 'cutover': sys.argv[3]}))
    else:
        print(json.dumps(statements(json.load(sys.stdin))))
