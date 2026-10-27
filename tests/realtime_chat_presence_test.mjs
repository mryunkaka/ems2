import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const widgetSource = await readFile(new URL('../assets/js/realtime-chat-widget.js', import.meta.url), 'utf8');
const helperStart = widgetSource.indexOf('    function filterFreshPresence(');
const helperEnd = widgetSource.indexOf('    function subscribeMessages(', helperStart);
assert.notEqual(helperStart, -1, 'presence filter should exist in the widget');
assert.notEqual(helperEnd, -1, 'presence filter should end before subscribeMessages');

const testContext = {};
runInNewContext(
    widgetSource.slice(helperStart, helperEnd) + '\nglobalThis.filterFreshPresence = filterFreshPresence;',
    testContext
);
const { filterFreshPresence } = testContext;
const LIVE_PRESENCE_TTL_MS = 90_000;

test('keeps only presence entries with a recent lastSeenAt', () => {
    const now = 1_800_000_000_000;
    const entries = {
        active: { name: 'Active user', lastSeenAt: now - 30_000 },
        stale: { name: 'Stale user', lastSeenAt: now - 120_000 },
        missing: { name: 'No heartbeat' },
        malformed: null,
    };

    assert.deepEqual(
        Array.from(filterFreshPresence(entries, now), ([id]) => id),
        ['active']
    );
});

test('allows a small server clock skew but rejects timestamps far in the future', () => {
    const now = 1_800_000_000_000;
    const entries = {
        smallSkew: { lastSeenAt: now + 10_000 },
        farFuture: { lastSeenAt: now + 60_000 },
    };

    assert.deepEqual(
        Array.from(filterFreshPresence(entries, now), ([id]) => id),
        ['smallSkew']
    );
});
