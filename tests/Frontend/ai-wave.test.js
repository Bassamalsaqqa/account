import test from 'node:test';
import assert from 'node:assert/strict';
import { encodePcmWave } from '../../resources/js/ai-wave.js';

test('PCM captures actual mono samples with a canonical bounded WAV header', () => {
    const wave = new DataView(encodePcmWave([new Float32Array([0, 1, -1, 2, NaN])], 48000));
    assert.equal(wave.byteLength, 54);
    assert.equal(wave.getUint32(4, true), 46);
    assert.equal(wave.getUint32(24, true), 48000);
    assert.equal(wave.getUint32(28, true), 96000);
    assert.equal(wave.getUint16(22, true), 1);
    assert.equal(wave.getUint16(34, true), 16);
    assert.deepEqual([0, 1, 2, 3, 4].map(i => wave.getInt16(44 + i * 2, true)), [0, 32767, -32768, 32767, 0]);
});

test('duration is capped by actual sample count and invalid rates/empty capture fail', () => {
    const wave = new DataView(encodePcmWave([new Float32Array(8000 * 61)], 8000));
    assert.equal(wave.byteLength, 44 + 8000 * 60 * 2);
    assert.equal(wave.getUint32(40, true), 8000 * 60 * 2);
    for (const rate of [0, 7999, 48001, 8000.5]) assert.throws(() => encodePcmWave([new Float32Array(1)], rate));
    assert.throws(() => encodePcmWave([], 8000));
});
