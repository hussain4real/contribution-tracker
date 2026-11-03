// Render-only migration batch. Applying it requires a separately verified
// staging write freeze, checkpoint, isolated restore and target identity.
export const migrationFingerprint = '66079cf9ea98cd29209f5886719bbe45b3c56a9c6cc1c1e4b5f91ee15d6d0150';
export const stagingDatabaseId = 'f96058f1-0aa3-42f3-9ee2-f796554d6ef4';
export const replacementDatabase = Object.freeze({
    name: 'familyfunds-d1-staging-clone-20261103t092540z',
    id: '43e11f90-07d7-49fc-930f-2a33a474d930',
});
export const migrations = [
    '2026_10_30_143906_create_payment_receipt_deliveries_table',
    '2026_10_30_145419_add_reminder_channels_to_family_members_table',
    '2026_10_30_145656_add_reminder_settings_to_families_table',
    '2026_10_30_150441_create_contribution_reminder_deliveries_table',
    '2026_11_01_070818_add_idempotency_key_to_expenses_table',
    '2026_11_01_075204_add_idempotency_key_to_fund_adjustments_table',
];

export const schemaQuery = `select type,name,tbl_name,sql from sqlite_master
    where (name in ('payment_batches','contributions','family_members','families','expenses','fund_adjustments','migrations',
        'd1_staging_assertions','d1_staging_sequences','d1_staging_read_revision')
        or tbl_name in ('payment_batches','contributions','family_members','families','expenses','fund_adjustments'))
    and name not like 'sqlite_%' order by type,name`;
export const migrationsQuery = 'select migration,batch from migrations order by migration,batch';

const schemaStatements = [
    'create table "payment_receipt_deliveries" ("id" integer primary key autoincrement not null, "payment_batch_id" integer not null, "channel" varchar not null, "status" varchar not null, "provider_message_id" varchar, "attempted_at" datetime, "accepted_at" datetime, "failed_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("payment_batch_id") references "payment_batches"("id") on delete cascade)',
    'create unique index "payment_receipt_deliveries_payment_batch_id_channel_unique" on "payment_receipt_deliveries" ("payment_batch_id", "channel")',
    'create index "payment_receipt_deliveries_provider_message_id_index" on "payment_receipt_deliveries" ("provider_message_id")',
    'alter table "family_members" add column "reminder_channels" text',
    'alter table "families" add column "reminder_settings" text',
    'create table "contribution_reminder_deliveries" ("id" integer primary key autoincrement not null, "contribution_id" integer not null, "reminder_type" varchar not null, "channel" varchar not null, "status" varchar not null, "provider_message_id" varchar, "attempted_at" datetime, "accepted_at" datetime, "failed_at" datetime, "created_at" datetime, "updated_at" datetime, foreign key("contribution_id") references "contributions"("id") on delete cascade)',
    'create index "reminder_deliveries_contribution_created_idx" on "contribution_reminder_deliveries" ("contribution_id", "created_at")',
    'create index "contribution_reminder_deliveries_provider_message_id_index" on "contribution_reminder_deliveries" ("provider_message_id")',
    'alter table "expenses" add column "idempotency_key" varchar',
    'create unique index "expenses_family_id_idempotency_key_unique" on "expenses" ("family_id", "idempotency_key")',
    'alter table "fund_adjustments" add column "idempotency_key" varchar',
    'create unique index "fund_adjustments_family_id_idempotency_key_unique" on "fund_adjustments" ("family_id", "idempotency_key")',
];

export function buildStagingPhase01Batch(inventory) {
    if (inventory.databaseId !== stagingDatabaseId) throw new Error('Unexpected staging target');
    return buildPhase01Batch(inventory);
}

export function buildReplacementPhase01Batch(inventory) {
    if (inventory.databaseId !== replacementDatabase.id || inventory.databaseName !== replacementDatabase.name) {
        throw new Error('Unexpected replacement target');
    }
    return buildPhase01Batch(inventory);
}

function buildPhase01Batch({fingerprint, schema, migrationRows}) {
    if (fingerprint !== migrationFingerprint) throw new Error('Unexpected migration fingerprint');
    if (!Array.isArray(schema) || !Array.isArray(migrationRows) || schema.length === 0 || migrationRows.length === 0) throw new Error('Missing preflight inventory');
    if (migrationRows.some(row => typeof row.migration !== 'string' || !Number.isSafeInteger(row.batch) || row.batch < 1) || migrationRows.some(row => migrations.includes(row.migration))) throw new Error('Invalid migration inventory');
    if (schema.some(row => !row || !['table', 'index', 'trigger'].includes(row.type) || typeof row.name !== 'string' || typeof row.tbl_name !== 'string' || !(row.sql === null || typeof row.sql === 'string'))) throw new Error('Invalid schema inventory');
    const required = ['payment_batches','contributions','family_members','families','expenses','fund_adjustments','migrations','d1_staging_assertions','d1_staging_sequences','d1_staging_read_revision'];
    if (required.some(name => !schema.some(row => row.type === 'table' && row.name === name))) throw new Error('Incomplete schema inventory');
    const assertionTable = schema.find(row => row.type === 'table' && row.name === 'd1_staging_assertions');
    if (assertionTable.sql?.replace(/\s+/g, ' ').trim().toLowerCase() !== 'create table d1_staging_assertions (id text primary key, passed integer not null constraint d1_staging_fresh_snapshot check (passed = 1))') throw new Error('Unexpected staging assertion table');
    const expectedSchema = JSON.stringify(schema.map(({type,name,tbl_name,sql}) => [type,name,tbl_name,sql]));
    const expectedMigrations = JSON.stringify(migrationRows.map(({migration,batch}) => [migration,batch]));
    const schemaGuard = `select coalesce(json_group_array(json_array(type,name,tbl_name,sql)),'[]') from (${schemaQuery})`;
    const migrationsGuard = `select coalesce(json_group_array(json_array(migration,batch)),'[]') from (${migrationsQuery})`;
    const guardId = 'phase01-migration-guard';
    const statements = [{sql: `insert into d1_staging_assertions(id,passed) select ?, case when
        (${schemaGuard}) = ? and (${migrationsGuard}) = ?
        and (select count(*) from sqlite_master where name in ('payment_receipt_deliveries','contribution_reminder_deliveries')) = 0
        and (select count(*) from pragma_table_info('family_members') where name='reminder_channels') = 0
        and (select count(*) from pragma_table_info('families') where name='reminder_settings') = 0
        and (select count(*) from d1_staging_sequences where name in ('payment_receipt_deliveries','contribution_reminder_deliveries')) = 0
        and (select count(*) from d1_staging_read_revision where id=1) = 1 then 1 else 0 end`, params: [guardId, expectedSchema, expectedMigrations]}];
    statements.push(...schemaStatements.map(sql => ({sql, params: []})));
    for (const table of ['payment_receipt_deliveries','contribution_reminder_deliveries']) {
        statements.push({sql: 'insert into d1_staging_sequences(name,next_id) values (?,coalesce((select seq from sqlite_sequence where name=?),0))', params: [table,table]});
        for (const operation of ['insert','update','delete']) statements.push({sql: `create trigger "d1_read_revision_${table}_${operation}" after ${operation} on "${table}" begin update d1_staging_read_revision set revision=revision+1 where id=1; end`, params: []});
    }
    const nextBatch = Math.max(...migrationRows.map(row => row.batch)) + 1;
    if (!Number.isSafeInteger(nextBatch)) throw new Error('Invalid next migration batch');
    for (const migration of migrations) statements.push({sql: 'insert into migrations(migration,batch) values (?,?)', params: [migration,nextBatch]});
    statements.push({sql: `update d1_staging_assertions set passed = case when
        (select count(*) from pragma_foreign_key_check) = 0
        and (select count(*) from pragma_quick_check where quick_check <> 'ok') = 0
        and (select count(*) from pragma_quick_check) = 1 then 1 else 0 end where id=?`, params: [guardId]});
    statements.push({sql: 'delete from d1_staging_assertions where id=?', params: [guardId]});
    return statements;
}
