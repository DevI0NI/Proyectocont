const characterCount = (value) => (value.match(/[a-z0-9]/gi) || []).length;

export function initializePlateInput(input) {
    const limit = Number(input.dataset.plateMax);
    let previousValue = input.value;
    let selectionStart = input.selectionStart;
    let selectionEnd = input.selectionEnd;

    input.addEventListener('beforeinput', () => {
        previousValue = input.value;
        selectionStart = input.selectionStart;
        selectionEnd = input.selectionEnd;
    });
    input.addEventListener('input', () => {
        const count = characterCount(input.value);
        if (count > limit && count >= characterCount(previousValue)) {
            input.value = previousValue;
            input.setSelectionRange(selectionStart, selectionEnd);
            return;
        }
        previousValue = input.value;
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.container-form input[data-plate-max]').forEach(initializePlateInput);
    });
}
