import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildReportPayload,
    createIdempotencyKey,
    detectViewportBucket,
    resetProblemReporterState,
    sanitizeObservedFailure,
} from '../../resources/js/problem-reporter.js';

test('createIdempotencyKey generates valid RFC-4122 v4 UUIDs', () => {
    const k1 = createIdempotencyKey();
    const k2 = createIdempotencyKey();
    const uuidRe = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
    assert.match(k1, uuidRe);
    assert.match(k2, uuidRe);
    assert.notEqual(k1, k2);
});

test('sanitizeObservedFailure accepts only server-format request IDs and HTTP 4xx/5xx status', () => {
    const validId = 'req_' + 'a'.repeat(32);
    assert.deepEqual(
        sanitizeObservedFailure({ requestId: validId, status: 500, payload: 'secret', url: '/invoices' }),
        { requestId: validId, status: 500 },
    );
    assert.deepEqual(
        sanitizeObservedFailure({ requestId: 'forged-client-id', status: 503 }),
        { requestId: null, status: 503 },
    );
    assert.equal(sanitizeObservedFailure({ requestId: 'bad', status: 200 }), null);
    assert.equal(sanitizeObservedFailure(null), null);
});

test('buildReportPayload excludes screen diagnostics when toggle is disabled', () => {
    const payload = buildReportPayload({
        idempotencyKey: '11111111-1111-4111-8111-111111111111',
        category: 'save_error',
        note: '  Save button did nothing  ',
        includeDiagnostics: false,
        routeName: 'invoices.create',
        pageRequestId: 'req_' + 'b'.repeat(32),
        observedFailure: { requestId: 'req_' + 'c'.repeat(32), status: 500 },
        viewport: 'mobile',
    });

    assert.deepEqual(payload, {
        idempotency_key: '11111111-1111-4111-8111-111111111111',
        category: 'save_error',
        note: 'Save button did nothing',
        include_diagnostics: false,
    });
    assert.equal('screen' in payload, false);
});

test('buildReportPayload includes only bounded allowlisted diagnostics when enabled', () => {
    const pageId = 'req_' + 'd'.repeat(32);
    const failId = 'req_' + 'e'.repeat(32);
    const payload = buildReportPayload({
        idempotencyKey: '22222222-2222-4222-8222-222222222222',
        category: 'calculation',
        note: 'Total stays unchanged',
        includeDiagnostics: true,
        routeName: 'purchases.create',
        pageRequestId: pageId,
        observedFailure: { requestId: failId, status: 500 },
        viewport: 'desktop',
    });

    assert.deepEqual(payload, {
        idempotency_key: '22222222-2222-4222-8222-222222222222',
        category: 'calculation',
        note: 'Total stays unchanged',
        include_diagnostics: true,
        screen: {
            route_name: 'purchases.create',
            page_request_id: pageId,
            livewire_failed: true,
            failed_request_id: failId,
            failed_status: 500,
            viewport: 'desktop',
        },
    });
});

test('buildReportPayload handles network failure honestly without fabricating failed_request_id', () => {
    const pageId = 'req_' + 'f'.repeat(32);
    const payload = buildReportPayload({
        idempotencyKey: '33333333-3333-4333-8333-333333333333',
        category: 'other',
        note: 'Network dropped',
        includeDiagnostics: true,
        routeName: 'dashboard',
        pageRequestId: pageId,
        observedFailure: { requestId: null, status: 503 },
        viewport: 'tablet',
    });

    assert.equal('failed_request_id' in payload.screen, false);
    assert.equal(payload.screen.failed_status, 503);
    assert.equal(payload.screen.livewire_failed, true);
});

test('detectViewportBucket maps breakpoints consistently', () => {
    assert.equal(detectViewportBucket(390), 'mobile');
    assert.equal(detectViewportBucket(768), 'tablet');
    assert.equal(detectViewportBucket(1280), 'desktop');
    resetProblemReporterState();
});
