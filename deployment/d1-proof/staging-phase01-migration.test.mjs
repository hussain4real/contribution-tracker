import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFileSync, readdirSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import {test} from 'node:test';
import {buildStagingPhase01Batch, migrationFingerprint, migrations, migrationsQuery, schemaQuery, stagingDatabaseId} from './staging-phase01-migration.mjs';

function fixture() {
    const db = new DatabaseSync(':memory:');
    db.exec(`create table migrations (id integer primary key autoincrement, migration text not null, batch integer not null);
        insert into migrations(migration,batch) values ('existing_migration',14);
        create table payment_batches(id integer primary key autoincrement);
        create table contributions(id integer primary key autoincrement);
        create table family_members(id integer primary key autoincrement);
        create table families(id integer primary key autoincrement);
        create table d1_staging_assertions (id text primary key, passed integer not null constraint d1_staging_fresh_snapshot check (passed = 1));
        create table d1_staging_sequences(name text primary key,next_id integer not null);
        create table d1_staging_read_revision(id integer primary key,revision integer not null);
        insert into d1_staging_read_revision values(1,0);
        create trigger existing_parent_trigger after insert on families begin update d1_staging_read_revision set revision=revision+1 where id=1; end;`);
    return db;
}

function inventory(db) {
    return {databaseId: stagingDatabaseId, fingerprint: migrationFingerprint,
        schema: db.prepare(schemaQuery).all(), migrationRows: db.prepare(migrationsQuery).all()};
}

function applyBatch(db, statements) {
    db.exec('begin');
    try {
        for (const statement of statements) db.prepare(statement.sql).run(...statement.params);
        db.exec('commit');
    } catch (error) {
        db.exec('rollback');
        throw error;
    }
}

test('staging batch is pinned to the checked-in migration files', () => {
    const directory = new URL('../../database/migrations/', import.meta.url);
    const hash = createHash('sha256');
    for (const file of readdirSync(directory).filter(name => name.endsWith('.php')).sort()) {
        hash.update(`database/migrations/${file}\0`);
        hash.update(readFileSync(new URL(file, directory)));
        hash.update('\0');
    }
    assert.equal(hash.digest('hex'), migrationFingerprint);
});

test('staging batch applies schema, tracking, sequences and revision triggers atomically', () => {
    const db = fixture();
    try {
        const before = inventory(db);
        const statements = buildStagingPhase01Batch(before);
        applyBatch(db, statements);
        assert.equal(db.prepare('select count(*) n from migrations where batch=15').get().n, migrations.length);
        assert.equal(db.prepare("select count(*) n from d1_staging_sequences where name in ('payment_receipt_deliveries','contribution_reminder_deliveries')").get().n, 2);
        assert.equal(db.prepare("select count(*) n from sqlite_master where type='trigger' and name like 'd1_read_revision_%'").get().n, 6);
        assert.equal(db.prepare("select count(*) n from sqlite_master where type='trigger' and name='existing_parent_trigger'").get().n, 1);
        db.exec("insert into payment_batches default values; insert into payment_receipt_deliveries(payment_batch_id,channel,status) values(1,'email','queued')");
        assert.equal(db.prepare('select revision from d1_staging_read_revision where id=1').get().revision, 1);
        assert.throws(() => applyBatch(db, statements));
        assert.equal(db.prepare('select count(*) n from migrations where batch=15').get().n, migrations.length);
    } finally { db.close(); }
});

test('staging batch rejects drift before applying any migration', () => {
    for (const change of [
        db => db.exec("insert into migrations(migration,batch) values ('concurrent',15)"),
        db => db.exec('drop trigger existing_parent_trigger'),
        db => db.exec("insert into d1_staging_sequences(name,next_id) values('payment_receipt_deliveries',1)"),
    ]) {
        const db = fixture();
        try {
            const statements = buildStagingPhase01Batch(inventory(db));
            change(db);
            assert.throws(() => applyBatch(db, statements));
            assert.equal(db.prepare("select count(*) n from sqlite_master where name='payment_receipt_deliveries'").get().n, 0);
            assert.equal(db.prepare('select count(*) n from migrations where migration=?').get(migrations[0]).n, 0);
        } finally { db.close(); }
    }
});

test('a later statement failure rolls back earlier migration statements', () => {
    const db = fixture();
    try {
        const statements = buildStagingPhase01Batch(inventory(db));
        statements.splice(4, 0, {sql: 'insert into missing_fixture values (1)', params: []});
        assert.throws(() => applyBatch(db, statements));
        assert.equal(db.prepare("select count(*) n from sqlite_master where name='payment_receipt_deliveries'").get().n, 0);
        assert.equal(db.prepare('select count(*) n from d1_staging_assertions').get().n, 0);
        assert.equal(db.prepare('select count(*) n from migrations where migration=?').get(migrations[0]).n, 0);
    } finally { db.close(); }
});

test('failed foreign key verification rolls back the migration', () => {
    const db = fixture();
    try {
        db.exec('pragma foreign_keys=off; create table fixture_child(id integer primary key, family_id integer references families(id)); insert into fixture_child values(1,999)');
        const statements = buildStagingPhase01Batch(inventory(db));
        assert.throws(() => applyBatch(db, statements));
        assert.equal(db.prepare("select count(*) n from sqlite_master where name='payment_receipt_deliveries'").get().n, 0);
        assert.equal(db.prepare('select count(*) n from d1_staging_assertions').get().n, 0);
    } finally { db.close(); }
});

test('staging batch refuses wrong target and changed migration fingerprint', () => {
    const db = fixture();
    try {
        const before = inventory(db);
        assert.throws(() => buildStagingPhase01Batch({...before,databaseId:'production'}), /target/);
        assert.throws(() => buildStagingPhase01Batch({...before,fingerprint:'changed'}), /fingerprint/);
        const changedSchema = before.schema.map(row => row.name === 'd1_staging_assertions' ? {...row,sql:'create table d1_staging_assertions(id text,passed integer)'} : row);
        assert.throws(() => buildStagingPhase01Batch({...before,schema:changedSchema}), /assertion table/);
    } finally { db.close(); }
});
