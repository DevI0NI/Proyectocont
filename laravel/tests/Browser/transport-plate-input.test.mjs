import assert from 'node:assert/strict';
import test from 'node:test';
import { initializePlateInput } from '../../resources/js/transport-plate-input.js';

class PlateInput extends EventTarget {
    dataset = { plateMax:'8' };
    constructor(value = '') {
        super();
        this.value = value;
        this.selectionStart = this.selectionEnd = value.length;
        initializePlateInput(this);
    }
    setSelectionRange(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    }
    replace(text, start = this.value.length, end = start) {
        this.setSelectionRange(start, end);
        this.dispatchEvent(new Event('beforeinput'));
        this.value = this.value.slice(0, start) + text + this.value.slice(end);
        this.dispatchEvent(new Event('input'));
    }
}

test('stops the ninth alphanumeric character while typing', () => {
    const input = new PlateInput('C1234567');
    input.replace('8');
    assert.equal(input.value, 'C1234567');
    assert.equal(input.selectionStart, 8);
});
test('rejects pasting an overlong plate instead of silently saving a truncated identifier', () => {
    const input = new PlateInput();
    input.replace('C1234567890');
    assert.equal(input.value, '');
});
test('spaces and hyphens do not count toward the eight-character limit', () => {
    const input = new PlateInput();
    input.replace('R-E 1-2 3-4 5-6');
    assert.equal(input.value, 'R-E 1-2 3-4 5-6');
    input.replace('7');
    assert.equal(input.value, 'R-E 1-2 3-4 5-6');
});
test('allows replacing selected characters and deleting from a full plate', () => {
    const input = new PlateInput('C1234567');
    input.replace('AB', 0, 2);
    assert.equal(input.value, 'AB234567');
    input.replace('', 6, 8);
    assert.equal(input.value, 'AB2345');
    input.replace('89');
    assert.equal(input.value, 'AB234589');
});
test('preserves legacy values on initialization and allows shortening them', () => {
    const input = new PlateInput('C123456789');
    assert.equal(input.value, 'C123456789');
    input.replace('', 9, 10);
    assert.equal(input.value, 'C12345678');
    input.replace('', 8, 9);
    assert.equal(input.value, 'C1234567');
});
