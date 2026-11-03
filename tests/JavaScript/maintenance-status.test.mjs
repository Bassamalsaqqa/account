import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isUpgradeResponse } from '../../resources/js/maintenance-status.js';

test('upgrade feedback requires an explicit maintenance page, not a network or generic 503 error', () => {
    assert.equal(isUpgradeResponse(503, '<body data-accounting-upgrade="true">Updating</body>'), true);
    assert.equal(isUpgradeResponse(503, null), false);
    assert.equal(isUpgradeResponse(503, '<body>Service unavailable</body>'), false);
    assert.equal(isUpgradeResponse(500, '<body data-accounting-upgrade="true">Updating</body>'), false);
});
