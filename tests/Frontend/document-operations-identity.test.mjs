import test from 'node:test';
import assert from 'node:assert/strict';
import {
    touchQuantityStepper,
    enqueueQuantityStep,
} from '../../resources/js/document-operations.js';

function lineWith(rowKey, productId, quantity = '1') {
    return {
        row_key: rowKey,
        id: null,
        product_id: productId,
        product_unit_id: 1,
        item_description: `Item ${productId}`,
        unit_price: '10.00',
        unit_cost: '5.00',
        discount_type: 'none',
        discount_value: '0',
        tax_rate_id: null,
        tax_inclusive: false,
        lot_id: null,
        lots: [],
        lot_allocations: [],
        warehouse_id: null,
        purchase_line_id: null,
        sales_invoice_line_id: null,
        quantity,
    };
}

test('touchQuantityStepper routes tap to live index via rowKey after preceding row deletion', async () => {
    const calls = [];
    const wire = {
        $id: 'row-identity-stepper',
        lines: [
            lineWith('uuid-row-a', 101, '2'),
            lineWith('uuid-row-b', 102, '5'),
        ],
        async batchStepQuantity(index, expected, identity, directions, field) {
            calls.push({ index, expected, rowKey: identity.row_key, directions, field });
            const next = String(Number(expected) + directions.length);
            this.lines[index].quantity = next;
            return { ok: true, quantity: next, minimumReached: false };
        },
        async recalculate() {},
        async $refresh() {},
    };

    const rowBStepper = touchQuantityStepper(wire, {
        index: 1,
        rowKey: 'uuid-row-b',
        quantum: '1',
        min: '0',
        debounceMs: 0,
    });
    Object.assign(rowBStepper, {
        $refs: { qtyInput: { value: '5' }, stepperMeta: { dataset: { lineIndex: '1', rowKey: 'uuid-row-b' } } },
    });

    // Simulate deleting row 0 before user taps row B's stepper
    wire.lines.splice(0, 1);
    assert.equal(wire.lines.length, 1);
    assert.equal(wire.lines[0].row_key, 'uuid-row-b');

    await rowBStepper.tap('up');

    assert.equal(calls.length, 1);
    assert.equal(calls[0].index, 0);
    assert.equal(calls[0].rowKey, 'uuid-row-b');
    assert.equal(calls[0].expected, '5');
    assert.equal(wire.lines[0].quantity, '6');
});

test('touchQuantityStepper rejects cleanly if rowKey no longer exists instead of mutating survivor', async () => {
    const calls = [];
    const wire = {
        $id: 'row-identity-deleted',
        lines: [
            lineWith('uuid-row-b', 102, '5'),
        ],
        async batchStepQuantity(...args) {
            calls.push(args);
            return { ok: true, quantity: '99' };
        },
        async recalculate() {},
    };

    const staleRowAStepper = touchQuantityStepper(wire, {
        index: 0,
        rowKey: 'uuid-row-deleted',
        quantum: '1',
        min: '0',
        debounceMs: 0,
    });
    Object.assign(staleRowAStepper, {
        $refs: { qtyInput: { value: '2' }, stepperMeta: { dataset: { lineIndex: '0', rowKey: 'uuid-row-deleted' } } },
    });

    await assert.rejects(staleRowAStepper.tap('up'), /Line changed/);
    assert.equal(calls.length, 0);
    assert.equal(wire.lines[0].quantity, '5');
    assert.equal(staleRowAStepper.failed, true);
});

test('deleting earlier row while quantity step is debounced rejects queued step instead of mutating shifted row', async () => {
    const calls = [];
    const wire = {
        $id: 'debounce-shift-rejection',
        lines: [
            lineWith('uuid-row-0', 101, '1'),
            lineWith('uuid-row-1', 101, '1'), // Identical product/SKU!
        ],
        async batchStepQuantity(...args) {
            calls.push(args);
            return { ok: true, quantity: '2' };
        },
        async recalculate() {},
    };

    const pending = enqueueQuantityStep(wire, 1, 'up', { debounceMs: 30, rowKey: 'uuid-row-1' });
    // Now delete row 0 while row 1's debounced job held index 1
    wire.lines.splice(0, 1);
    await assert.rejects(pending, /Line changed/);
    assert.equal(calls.length, 0);
    assert.equal(wire.lines[0].row_key, 'uuid-row-1');
    assert.equal(wire.lines[0].quantity, '1');
});
