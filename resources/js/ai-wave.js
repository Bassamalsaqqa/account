// Floating point here represents audio samples only, never economic quantities.
export function encodePcmWave(chunks, sampleRate, maxSeconds = 60) {
    if (!Number.isInteger(sampleRate) || sampleRate < 8000 || sampleRate > 48000) throw new Error('audio_invalid');
    const count = Math.min(chunks.reduce((sum, chunk) => sum + chunk.length, 0), sampleRate * maxSeconds);
    if (!count) throw new Error('audio_invalid');
    const buffer = new ArrayBuffer(44 + count * 2);
    const view = new DataView(buffer);
    const text = (offset, value) => [...value].forEach((char, index) => view.setUint8(offset + index, char.charCodeAt(0)));
    text(0, 'RIFF'); view.setUint32(4, 36 + count * 2, true); text(8, 'WAVE'); text(12, 'fmt ');
    view.setUint32(16, 16, true); view.setUint16(20, 1, true); view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true); view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true); view.setUint16(34, 16, true); text(36, 'data'); view.setUint32(40, count * 2, true);
    let index = 0;
    for (const chunk of chunks) {
        for (const value of chunk) {
            if (index === count) break;
            const sample = Number.isFinite(value) ? Math.max(-1, Math.min(1, value)) : 0;
            view.setInt16(44 + index++ * 2, Math.round(sample * (sample < 0 ? 32768 : 32767)), true);
        }
        if (index === count) break;
    }
    return buffer;
}
