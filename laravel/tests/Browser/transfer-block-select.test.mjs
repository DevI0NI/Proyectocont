import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/views/transfers/create.blade.php', import.meta.url), 'utf8');
const start = source.indexOf('function syncBlockAvailability(');
const end = source.indexOf('async function loadBlocks(', start);

test('transfer blocks cannot open without a patio, during loading, or with no usable blocks', () => {
    const context = vm.createContext({ Event });
    vm.runInContext(source.slice(start, end), context);
    const patio = { value: '' };
    const block = new EventTarget();
    block.dataset = {};
    block.options = [{ value: '' }, { value: '5', disabled: false }];

    context.syncBlockAvailability(patio, block);
    assert.equal(block.disabled, true);
    patio.value = '3';
    block.dataset.loading = 'true';
    context.syncBlockAvailability(patio, block);
    assert.equal(block.disabled, true);
    block.dataset.loading = 'false';
    context.syncBlockAvailability(patio, block);
    assert.equal(block.disabled, false);
    context.syncBlockAvailability(patio, block, false);
    assert.equal(block.disabled, true);
    block.options = [{ value: '' }, { value: '5', disabled: true }];
    context.syncBlockAvailability(patio, block);
    assert.equal(block.disabled, true);
    patio.value = '';
    context.syncBlockAvailability(patio, block);
    assert.equal(block.disabled, true);
});
