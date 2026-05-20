import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { correctTranscriptMemberNameCasing } from '../../resources/js/lib/transcript-corrections.js';

describe('transcript corrections', () => {
    it('preserves ordinary words that only sound similar to member names', () => {
        const text = correctTranscriptMemberNameCasing(
            'I am standing with my family today',
            ['Sadiya Yusuf', 'Jamila Ahmed'],
        );

        assert.equal(text, 'I am standing with my family today');
    });

    it('only normalizes exact member-name token casing', () => {
        const text = correctTranscriptMemberNameCasing(
            'please show jamila and sadiya payments',
            ['Sadiya Yusuf', 'Jamila Ahmed'],
        );

        assert.equal(text, 'please show Jamila and Sadiya payments');
    });
});
