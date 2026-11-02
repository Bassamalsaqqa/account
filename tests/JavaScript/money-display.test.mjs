import test from 'node:test';
import assert from 'node:assert/strict';
import { displayMoney } from '../../resources/js/money-display.js';

test('billing previews use two decimals with exact rounding and no floating point conversion', () => {
    for (const [input, expected] of [
        ['280.000000', '280.00'], ['1', '1.00'], ['1.2', '1.20'],
        ['9007199254740993.125000', '9,007,199,254,740,993.13'],
        ['999.995', '1,000.00'], ['-1.125', '-1.13'],
        ['-0.000001', '0.00'], ['0', '0.00'], ['0001.250000', '1.25'],
    ]) {
        assert.equal(displayMoney(input), expected);
    }
});

test('incomplete or malformed billing input is preserved for server validation', () => {
    for (const input of ['', '-', '1.', '1e3', 'invalid', '1,234']) {
        assert.equal(displayMoney(input), input);
    }
});
