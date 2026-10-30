export function createInteractionGate(onBusy = () => {}, { cooldown = 800, schedule = setTimeout } = {}) {
    let busy = false;

    return {
        get busy() { return busy; },
        async run(action) {
            if (busy) return false;
            busy = true;
            onBusy(true);
            try {
                await action();
                return true;
            } finally {
                schedule(() => {
                    busy = false;
                    onBusy(false);
                }, cooldown);
            }
        },
    };
}

if (typeof window !== 'undefined') window.createInteractionGate = createInteractionGate;
