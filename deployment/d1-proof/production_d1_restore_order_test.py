import importlib.util
from pathlib import Path
import sqlite3
import unittest

spec = importlib.util.spec_from_file_location('restore_order', Path(__file__).with_name('production-d1-restore-order.py'))
restore_order = importlib.util.module_from_spec(spec)
spec.loader.exec_module(restore_order)


class RestoreOrderTest(unittest.TestCase):
    def test_recovery_preserves_foreign_keys_sequences_and_trigger_state(self):
        original = '''PRAGMA defer_foreign_keys=ON;
CREATE TABLE children(id INTEGER PRIMARY KEY AUTOINCREMENT,parent_id INTEGER REFERENCES parents(id),value TEXT);
INSERT INTO children VALUES(8,3,'quote'' and; newline
value');
CREATE TABLE parents(id INTEGER PRIMARY KEY);
INSERT INTO parents VALUES(3);
CREATE TABLE counters(n INTEGER);
INSERT INTO counters VALUES(12);
DELETE FROM sqlite_sequence;
INSERT INTO sqlite_sequence VALUES('children',99);
CREATE TRIGGER count_children AFTER INSERT ON children BEGIN UPDATE counters SET n=n+1; END;
'''
        source = sqlite3.connect(':memory:')
        source.execute('PRAGMA foreign_keys=ON')
        with self.assertRaisesRegex(sqlite3.OperationalError, 'no such table'):
            source.executescript('BEGIN;\n' + original + '\nCOMMIT;')
        source.close()
        restored = sqlite3.connect(':memory:')
        restored.execute('PRAGMA foreign_keys=ON')
        restored.executescript(restore_order.ordered_restore_sql(original))
        self.assertEqual(restored.execute('PRAGMA foreign_key_check').fetchall(), [])
        self.assertEqual(restored.execute('SELECT * FROM children').fetchall(), [(8, 3, "quote' and; newline\nvalue")])
        self.assertEqual(restored.execute('SELECT seq FROM sqlite_sequence WHERE name=\'children\'').fetchone()[0], 99)
        self.assertEqual(restored.execute('SELECT n FROM counters').fetchone()[0], 12)
        restored.execute("INSERT INTO children(parent_id,value) VALUES(3,'new')")
        self.assertEqual(restored.execute('SELECT max(id) FROM children').fetchone()[0], 100)
        self.assertEqual(restored.execute('SELECT n FROM counters').fetchone()[0], 13)
        restored.close()

    def test_non_nullable_cycles_fail_before_a_restore_is_prepared(self):
        original = '''CREATE TABLE a(id INTEGER PRIMARY KEY,b_id INTEGER NOT NULL REFERENCES b(id));
CREATE TABLE b(id INTEGER PRIMARY KEY,a_id INTEGER NOT NULL REFERENCES a(id));'''
        with self.assertRaisesRegex(ValueError, 'Non-nullable foreign-key cycle'):
            restore_order.ordered_restore_sql(original)


if __name__ == '__main__':
    unittest.main()
