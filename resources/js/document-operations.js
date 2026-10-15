const queues = new Map();
const allowed = new Set(['scanCameraBarcode', 'incrementQuantity', 'decrementQuantity', 'stepQuantity', 'addLine', 'saveDraft', 'save']);
const keyFor = wire => wire.$id || wire.id || wire;
const announce = (wire, busy) => {
    if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('document-operation-state', {detail:{id:wire.$id, busy}}));
};
export function isDocumentBusy(wire) { return queues.has(keyFor(wire)); }
export function enqueueDocumentOperation(wire, method, args = []) {
    if (!wire || !allowed.has(method)) return Promise.reject(new Error('Invalid document operation'));
    const key = keyFor(wire);
    let state = queues.get(key);
    if (state && state.waiting >= 10) return Promise.reject(new Error('Document queue full'));
    if (!state) { state = {tail:Promise.resolve(), waiting:0}; queues.set(key,state); }
    state.waiting++;
    announce(wire,true);
    const task = state.tail.then(() => wire[method](...args));
    const settled = task.finally(() => {
        state.waiting--;
        if (!state.waiting) { queues.delete(key); announce(wire,false); }
    });
    state.tail = settled.catch(() => {});
    return settled;
}
if (typeof window !== 'undefined') {
    window.enqueueDocumentOperation = enqueueDocumentOperation;
    window.isDocumentBusy = isDocumentBusy;
}