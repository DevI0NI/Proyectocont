import * as THREE from 'three';

const RIB_TILE = 0.56;
let ribTexture = null;

/**
 * Grayscale corrugated steel tile; the material color tints it, so every status color keeps working.
 */
export function corrugatedTexture() {
    if (ribTexture) return ribTexture;
    const canvas = document.createElement('canvas');
    canvas.width = 128;
    canvas.height = 128;
    const context = canvas.getContext('2d');
    const ribs = 4;
    const ribWidth = canvas.width / ribs;
    for (let rib = 0; rib < ribs; rib++) {
        const gradient = context.createLinearGradient(rib * ribWidth, 0, (rib + 1) * ribWidth, 0);
        gradient.addColorStop(0, '#b2b2b2');
        gradient.addColorStop(0.18, '#ffffff');
        gradient.addColorStop(0.5, '#e4e4e4');
        gradient.addColorStop(0.78, '#c8c8c8');
        gradient.addColorStop(1, '#a6a6a6');
        context.fillStyle = gradient;
        context.fillRect(rib * ribWidth, 0, ribWidth, canvas.height);
    }
    context.fillStyle = 'rgba(40, 40, 40, 0.55)';
    context.fillRect(0, 0, canvas.width, 9);
    context.fillRect(0, canvas.height - 9, canvas.width, 9);
    ribTexture = new THREE.CanvasTexture(canvas);
    ribTexture.wrapS = THREE.RepeatWrapping;
    ribTexture.colorSpace = THREE.SRGBColorSpace;
    ribTexture.anisotropy = 4;

    return ribTexture;
}

/**
 * Box shaped like a shipping container (length along Z) whose UVs repeat the ribs by real size,
 * so the long sides get more ribs than the door end.
 */
export function createContainerGeometry(width, height, length) {
    const geometry = new THREE.BoxGeometry(width, height, length);
    const uv = geometry.attributes.uv;
    const faceWidths = [length, length, width, width, width, width];
    for (let face = 0; face < 6; face++) {
        for (let vertex = 0; vertex < 4; vertex++) {
            const index = face * 4 + vertex;
            uv.setX(index, uv.getX(index) * (faceWidths[face] / RIB_TILE));
        }
    }
    uv.needsUpdate = true;
    geometry.userData.containerSize = { width, height, length };

    return geometry;
}

/**
 * Frame edges, corner castings line work and the door with its four lock bars on the +Z end.
 */
export function createContainerDetailsGeometry(width, height, length) {
    const x = width / 2;
    const y = height / 2;
    const z = length / 2 + 0.004;
    const points = [];
    const segment = (ax, ay, az, bx, by, bz) => points.push(ax, ay, az, bx, by, bz);
    segment(0, -y * 0.92, z, 0, y * 0.92, z);
    [-0.62, -0.22, 0.22, 0.62].forEach((ratio) => segment(ratio * x, -y * 0.8, z, ratio * x, y * 0.8, z));
    [-0.62, -0.22, 0.22, 0.62].forEach((ratio) => {
        segment(ratio * x - 0.03, y * 0.25, z, ratio * x + 0.03, y * 0.25, z);
        segment(ratio * x - 0.03, -y * 0.25, z, ratio * x + 0.03, -y * 0.25, z);
    });
    const details = new THREE.BufferGeometry();
    details.setAttribute('position', new THREE.Float32BufferAttribute(points, 3));
    const edges = new THREE.EdgesGeometry(new THREE.BoxGeometry(width, height, length));
    const merged = new THREE.BufferGeometry();
    const edgePositions = edges.attributes.position.array;
    const detailPositions = details.attributes.position.array;
    const all = new Float32Array(edgePositions.length + detailPositions.length);
    all.set(edgePositions);
    all.set(detailPositions, edgePositions.length);
    merged.setAttribute('position', new THREE.BufferAttribute(all, 3));
    edges.dispose();
    details.dispose();

    return merged;
}

/**
 * Adds the frame and door lines as a child of the container mesh so they follow hover scaling.
 */
export function addContainerDetails(mesh, detailsGeometry, material) {
    const lines = new THREE.LineSegments(detailsGeometry, material);
    lines.raycast = () => {};
    mesh.add(lines);

    return lines;
}
