const queues = new Map();
const allowed = new Set(['scanCameraBarcode', 'incrementQuantity', 'decrementQuantity', 'stepQuantity', 'addLine', 'saveDraft', 'save']);
const identityFields = ['id', 'product_id', 'product_unit_id', 'item_description', 'unit_price', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id', 'tax_inclusive', 'lot_id', 'lots', 'lot_allocations', 'warehouse_id'];
const keyFor = wire => wire.$id || wire.id || wire;
const copy = value => JSON.parse(JSON.stringify(value));
const lineFor = (wire, index) => wire.lines?.[index] ?? wire.$get?.(`lines.${index}`) ?? wire.get?.(`lines.${index}`);
const identityFor = line => Object.fromEntries(identityFields.map(key => [key, line?.[key] ?? null]));
const same = (first, second) => JSON.stringify(first) === JSON.stringify(second);
const error = message => new Error(message);
function stateFor(wire) {
    const key = keyFor(wire);
    if (!queues.has(key)) queues.set(key, {wire, jobs: [], active: null, open: null, blocked: false, failed: [], confirmed: new Map()});
    return queues.get(key);
}
function announce(state) {
    if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('document-operation-state', {detail: {id: state.wire.$id, busy: isDocumentBusy(state.wire), blocked: state.blocked}}));
}
export function isDocumentBusy(wire) {
    const state = queues.get(keyFor(wire));
    return Boolean(state && (state.active || state.jobs.length));
}
export function isDocumentBlocked(wire) { return Boolean(queues.get(keyFor(wire))?.blocked); }
function seal(state) {
    const job = state.open;
    if (!job) return;
    clearTimeout(job.timer);
    job.ready = true;
    state.open = null;
}
function settle(job, method, result) { for (const deferred of job.waiters) deferred[method](result); }
function quarantine(state, job, failure) {
    state.blocked = true;
    state.failed = [job];
    seal(state);
    for (const queued of state.jobs.splice(0)) {
        if (queued.kind === 'quantity') state.failed.push(queued);
        settle(queued, 'reject', error('Document operation blocked; review pending quantity changes'));
    }
    settle(job, 'reject', failure);
}
async function drain(state) {
    if (state.active || state.blocked || !state.jobs[0]?.ready) return;
    const job = state.jobs.shift();
    state.active = job;
    announce(state);
    try {
        let result;
        if (job.kind === 'quantity') {
            const current = lineFor(state.wire, job.index);
            if (!current || !same(identityFor(current), job.identity)) throw error('Line changed; review pending quantity changes');
            const confirmed = state.confirmed.get(job.index);
            const expected = confirmed && same(confirmed.identity, job.identity) ? confirmed.quantity : job.expected;
            result = await state.wire.batchStepQuantity(job.index, expected, job.identity, [...job.directions]);
            if (!result?.ok || typeof result.quantity !== 'string') throw error(result?.message || 'Quantity change refused; review the line');
            state.confirmed.set(job.index, {identity: job.identity, quantity: result.quantity});
        } else {
            result = await state.wire[job.method](...job.args);
            state.confirmed.clear();
        }
        settle(job, 'resolve', result);
    } catch (failure) {
        if (job.kind === 'quantity') quarantine(state, job, failure);
        else settle(job, 'reject', failure);
    } finally {
        state.active = null;
        announce(state);
        if (!state.blocked && !state.jobs.length) queues.delete(keyFor(state.wire));
        else void drain(state);
    }
}
function waiter(job) { return new Promise((resolve, reject) => job.waiters.push({resolve, reject})); }
export function flushQuantityBatch(wire) {
    const state = queues.get(keyFor(wire));
    if (state) { seal(state); void drain(state); }
}
export function enqueueQuantityStep(wire, index, direction, options = {}) {
    if (!wire || !Number.isSafeInteger(index) || index < 0 || !['up', 'down'].includes(direction)) return Promise.reject(error('Invalid quantity operation'));
    const state = stateFor(wire);
    if (state.blocked) return Promise.reject(error('Document operation blocked; review pending quantity changes'));
    const line = lineFor(wire, index);
    if (!line || typeof line.quantity !== 'string') return Promise.reject(error('Invalid quantity line'));
    const identity = copy(identityFor(line));
    let job = state.open;
    if (!job || job.index !== index || !same(job.identity, identity) || job.directions.length >= 100) {
        if (state.jobs.length >= 10) return Promise.reject(error('Document queue full'));
        seal(state);
        job = {kind: 'quantity', index, expected: line.quantity, identity, directions: [], waiters: [], ready: false, timer: null, created: Date.now()};
        state.jobs.push(job);
        state.open = job;
    }
    job.directions.push(direction);
    const promise = waiter(job);
    clearTimeout(job.timer);
    const delay = Math.min(150, Math.max(0, options.debounceMs ?? 100), Math.max(0, 300 - (Date.now() - job.created)));
    if (job.directions.length === 100) seal(state);
    else job.timer = setTimeout(() => { if (state.open === job) seal(state); void drain(state); }, delay);
    announce(state);
    void drain(state);
    return promise;
}
export function enqueueDocumentOperation(wire, method, args = []) {
    if (!wire || !allowed.has(method)) return Promise.reject(error('Invalid document operation'));
    const state = stateFor(wire);
    if (state.blocked) return Promise.reject(error('Document operation blocked; review pending quantity changes'));
    if (state.jobs.length >= 10) return Promise.reject(error('Document queue full'));
    seal(state);
    const job = {kind: 'operation', method, args, waiters: [], ready: true};
    const promise = waiter(job);
    state.jobs.push(job);
    announce(state);
    void drain(state);
    return promise;
}
// Explicit recovery never automatically replays an ambiguous request or a queued Save.
// The user first reviews the still-visible confirmed quantity and chooses to discard unconfirmed taps.
export async function resyncDocumentQueue(wire) {
    const state = queues.get(keyFor(wire));
    if (!state?.blocked) return;
    if (state.active || state.jobs.length) throw error('Wait for the current request to finish');
    await wire.recalculate();
    state.failed = [];
    state.confirmed.clear();
    state.blocked = false;
    queues.delete(keyFor(wire));
    announce(state);
}
if (typeof window !== 'undefined') {
    Object.assign(window, {enqueueDocumentOperation, enqueueQuantityStep, isDocumentBusy, isDocumentBlocked, resyncDocumentQueue, flushQuantityBatch});
}
