import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = readFileSync(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

for (const [initialize, nextFunction, registry, attribute, parse, dispose] of [
    ['initYard3d', 'parseIngressLocationScene', 'yardScenes', 'yardScene', 'parseYardScene', 'disposeYardScene'],
    ['initIngressLocation3d', 'initIngressLocation3dScenes', 'ingressLocationScenes', 'scene', 'parseIngressLocationScene', 'disposeIngressLocationScene'],
]) {
    test(`${initialize} preserves unchanged scenes but invalidates data and theme changes`, () => {
        let theme = 'dark';
        let disposals = 0;
        const canvas = { dataset: { [attribute]: '{"block":1}' }, closest: () => null, clientWidth: 100, clientHeight: 100 };
        const scenes = new Map([[canvas, { signature: JSON.stringify([canvas.dataset[attribute], theme]) }]]);
        const context = vm.createContext({
            [registry]: scenes,
            [parse]: () => ({}),
            [dispose]: () => { disposals++; },
            document: { documentElement: { getAttribute: () => theme } },
        });
        const start = source.indexOf(`function ${initialize}(canvas)`);
        const end = source.indexOf(`function ${nextFunction}(`, start);
        vm.runInContext(source.slice(start, end), context);

        context[initialize](canvas);
        context[initialize](canvas);
        assert.equal(disposals, 0);
        canvas.dataset[attribute] = '{}';
        context[initialize](canvas);
        assert.equal(disposals, 1);
        canvas.dataset[attribute] = '{"block":1}';
        theme = 'light';
        context[initialize](canvas);
        assert.equal(disposals, 2);
    });

    test(`${initialize} stops rendering and releases the scene after leaving its view`, () => {
        let disposals = 0;
        const canvas = { isConnected: false };
        const context = vm.createContext({
            canvas,
            [dispose]: (removed) => { assert.equal(removed, canvas); disposals++; },
        });
        const initializer = source.indexOf(`function ${initialize}(canvas)`);
        const start = source.indexOf('function animate() {', initializer);
        const end = source.indexOf('\n    }', start) + '\n    }'.length;
        vm.runInContext(source.slice(start, end), context);

        context.animate();

        assert.equal(disposals, 1);
    });
}
