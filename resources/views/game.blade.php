@verbatim
(function () {
  "use strict";

  const DATA = JSON.parse(document.getElementById("rt-data").textContent);
  const CONFIG = JSON.parse(document.getElementById("rt-config").textContent);
  const wrap = document.getElementById("rt-wrap");
  const canvas = document.getElementById("rt-canvas");
  const ctx = canvas.getContext("2d");
  const hud = document.getElementById("rt-hud");
  const radioEl = document.getElementById("rt-radio");
  const startOverlay = document.getElementById("rt-start");
  const modal = document.getElementById("rt-modal");
  const frame = document.getElementById("rt-frame");
  const rand = (a, b) => a + Math.random() * (b - a);
  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));
  const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);

  // ================================================================ building the world
  const SCALE = 1.7;            // custom map pixels -> world pixels
  const LAND_R = 220;           // land around every node
  const PLAZA_R = 78;           // the roundabout every road meets at
  const GAP = 520;              // water between islands
  const islands = [];
  const nodesById = new Map();
  const roads = [];             // in-island roads (custom map edges) and bridges
  const buildings = [];         // solid
  const stops = [];             // parking spots: stop here to visit

  function roadWidth(port) {
    const g = port && port.speed ? port.speed / 1e9 : 1;
    return g >= 100 ? 74 : g >= 25 ? 60 : g >= 10 ? 48 : 36;
  }

  // simple force layout for islands without positions (the LLDP island)
  function autoLayout(island) {
    const n = island.nodes.length, k = 330;
    island.nodes.forEach((node, i) => {
      const a = (i / n) * Math.PI * 2;
      node.x = Math.cos(a) * 200 * Math.sqrt(n);
      node.y = Math.sin(a) * 200 * Math.sqrt(n);
    });
    const byId = new Map(island.nodes.map(nd => [nd.id, nd]));
    for (let it = 0; it < 300; it++) {
      const f = new Map(island.nodes.map(nd => [nd.id, { x: 0, y: 0 }]));
      for (const a of island.nodes) for (const b of island.nodes) {
        if (a === b) continue;
        const dx = a.x - b.x, dy = a.y - b.y, d = Math.max(1, Math.hypot(dx, dy));
        f.get(a.id).x += dx / d * k * k / d; f.get(a.id).y += dy / d * k * k / d;
      }
      for (const e of island.edges) {
        const a = byId.get(e.from), b = byId.get(e.to);
        if (!a || !b) continue;
        const dx = b.x - a.x, dy = b.y - a.y, d = Math.max(1, Math.hypot(dx, dy)), pull = d * d / k;
        f.get(a.id).x += dx / d * pull; f.get(a.id).y += dy / d * pull;
        f.get(b.id).x -= dx / d * pull; f.get(b.id).y -= dy / d * pull;
      }
      const t = 40 * (1 - it / 300) + 2;
      for (const nd of island.nodes) {
        const v = f.get(nd.id), m = Math.max(1, Math.hypot(v.x, v.y));
        nd.x += v.x / m * Math.min(m, t); nd.y += v.y / m * Math.min(m, t);
      }
    }
    island.nodes.forEach(nd => { nd.x /= SCALE; nd.y /= SCALE; });   // back to "map pixels"
  }

  function buildIslands() {
    for (const raw of DATA.islands) {
      if (!raw.nodes.length) continue;
      const island = { id: raw.id, name: raw.name, url: raw.url, group: raw.group, nodes: [], edges: raw.edges, raw };
      island.nodes = raw.nodes.map(n => ({ ...n }));
      if (raw.layout === "auto") autoLayout(island);
      for (const n of island.nodes) { n.lx = n.x * SCALE; n.ly = n.y * SCALE; n.island = island; }
      const cx = island.nodes.reduce((s, n) => s + n.lx, 0) / island.nodes.length;
      const cy = island.nodes.reduce((s, n) => s + n.ly, 0) / island.nodes.length;
      island.lcx = cx; island.lcy = cy;
      island.r = Math.max(...island.nodes.map(n => Math.hypot(n.lx - cx, n.ly - cy))) + LAND_R + 40;
      islands.push(island);
    }
    // place them: the island with most bridges in the middle, the others in the direction its bridges point
    const byId = new Map(islands.map(i => [String(i.id), i]));
    // bridges from the discovered island to the island where the LLDP neighbour lives
    for (const isl of islands) {
      for (const br of isl.raw.bridges || []) {
        const n = isl.nodes.find(m => m.id === br.from);
        const other = islands.find(o => o !== isl && o.nodes.some(m => m.device && m.device.id === br.to_device));
        if (n && other) { n.bridgeToDevice = br.to_device; n.bridge = n.bridge || other.id; n.deviceBridge = true; }
      }
    }
    const degree = i => i.nodes.filter(n => n.bridge && byId.has(String(n.bridge))).length
      + islands.reduce((s, o) => s + o.nodes.filter(n => String(n.bridge) === String(i.id)).length, 0);
    const order = [...islands].sort((a, b) => degree(b) - degree(a));
    const placed = [];
    const overlaps = (isl, x, y) => placed.some(p => Math.hypot(p.cx - x, p.cy - y) < p.r + isl.r + GAP * 0.6);
    const place = (isl, x, y) => { isl.cx = x; isl.cy = y; placed.push(isl); };
    const tryAround = (from, isl, angle) => {
      for (let step = 0; step < 72; step++) {
        const a = angle + (step % 2 ? 1 : -1) * Math.ceil(step / 2) * (Math.PI / 18);
        const d = from.r + isl.r + GAP;
        const x = from.cx + Math.cos(a) * d, y = from.cy + Math.sin(a) * d;
        if (!overlaps(isl, x, y)) return place(isl, x, y);
      }
      place(isl, from.cx + from.r + isl.r + GAP * 3, from.cy + placed.length * 400);
    };
    if (order.length) place(order[0], 0, 0);
    const queue = order.length ? [order[0]] : [];
    while (queue.length) {
      const a = queue.shift();
      for (const n of a.nodes) {
        const b = n.bridge && byId.get(String(n.bridge));
        if (!b || placed.includes(b)) continue;
        tryAround(a, b, Math.atan2(n.ly - a.lcy, n.lx - a.lcx));
        queue.push(b);
      }
      // and islands that bridge *to* this one: next to the node they connect to
      for (const b of islands) {
        if (placed.includes(b)) continue;
        const m = b.nodes.find(x => String(x.bridge) === String(a.id));
        if (!m) continue;
        const t = (m.bridgeToDevice && a.nodes.find(x => x.device && x.device.id === m.bridgeToDevice))
          || a.nodes.find(x => String(x.bridge) === String(b.id));
        tryAround(a, b, t ? Math.atan2(t.ly - a.lcy, t.lx - a.lcx) : Math.random() * Math.PI * 2);
        queue.push(b);
      }
    }
    let k = 0;
    for (const isl of order) {                  // islands nobody bridges to: around the edge
      if (placed.includes(isl)) continue;
      tryAround(order[0], isl, Math.PI * 0.75 + k++ * 0.9);
    }
    for (const isl of islands) {
      for (const n of isl.nodes) { n.x = n.lx - isl.lcx + isl.cx; n.y = n.ly - isl.lcy + isl.cy; nodesById.set(n.id, n); }
      isl.hull = convexHull(isl.nodes);
    }
    hub = order[0] || null;
  }
  let hub = null;
  // convex hull of the nodes: the inside of an island is land too, not just the strips along its roads
  function convexHull(points) {
    const p = points.map(n => ({ x: n.x, y: n.y })).sort((a, b) => a.x - b.x || a.y - b.y);
    if (p.length < 3) return p;
    const cross = (o, a, b) => (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
    const lower = [], upper = [];
    for (const q of p) { while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], q) <= 0) lower.pop(); lower.push(q); }
    for (const q of [...p].reverse()) { while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], q) <= 0) upper.pop(); upper.push(q); }
    return lower.slice(0, -1).concat(upper.slice(0, -1));
  }
  function inHull(hull, px, py) {
    if (hull.length < 3) return false;
    let sign = 0;
    for (let i = 0; i < hull.length; i++) {
      const a = hull[i], b = hull[(i + 1) % hull.length];
      const c = Math.sign((b.x - a.x) * (py - a.y) - (b.y - a.y) * (px - a.x));
      if (c !== 0) { if (sign && c !== sign) return false; sign = c; }
    }
    return true;
  }

  function buildRoads() {
    for (const isl of islands) {
      for (const e of isl.edges) {
        const a = nodesById.get(e.from), b = nodesById.get(e.to);
        if (!a || !b || a === b) continue;
        roads.push(makeRoad(a, b, e.port, e.label, "road", isl));
      }
    }
    // bridges: a node that links to another map, to the node there that links back (else the nearest node)
    const done = new Set();
    for (const isl of islands) {
      for (const n of isl.nodes) {
        if (!n.bridge) continue;
        const other = islands.find(o => String(o.id) === String(n.bridge));
        if (!other || other === isl) continue;
        const back = (n.bridgeToDevice && other.nodes.find(m => m.device && m.device.id === n.bridgeToDevice))
          || other.nodes.find(m => String(m.bridge) === String(isl.id))
          || other.nodes.reduce((best, m) => (dist(m, n) < dist(best, n) ? m : best));
        const key = [n.id, back.id].sort().join("|");
        if (done.has(key)) continue;
        done.add(key);
        const r = makeRoad(n, back, null, `${isl.name} ⇄ ${other.name}`, "bridge", null);
        r.width = 56; r.islands = [isl, other];
        roads.push(r);
      }
    }
  }
  function makeRoad(a, b, port, label, kind, island) {
    const len = Math.max(1, dist(a, b)), ux = (b.x - a.x) / len, uy = (b.y - a.y) / len;
    const r = { a, b, port, label, kind, island, len, ux, uy, width: roadWidth(port), cars: [] };
    r.minX = Math.min(a.x, b.x) - 80; r.maxX = Math.max(a.x, b.x) + 80;
    r.minY = Math.min(a.y, b.y) - 80; r.maxY = Math.max(a.y, b.y) + 80;
    if (port && kind === "road") {        // a lay-by with a P at the middle: the port's page
      const side = 1, off = r.width / 2 + 36;
      const mx = (a.x + b.x) / 2 - uy * off * side, my = (a.y + b.y) / 2 + ux * off * side;
      stops.push({ x: mx - 30, y: my - 20, w: 60, h: 40, kind: "port", road: r, key: `port-${port.id}` });
    }
    return r;
  }

  // every node gets a roundabout; its building / sign goes into the widest gap between its roads
  function placeBuildings() {
    for (const n of nodesById.values()) {
      const angles = roads.filter(r => r.a === n || r.b === n)
        .map(r => (r.a === n ? Math.atan2(r.uy, r.ux) : Math.atan2(-r.uy, -r.ux))).sort((p, q) => p - q);
      let dir = -Math.PI / 2;
      if (angles.length === 1) dir = angles[0] + Math.PI;
      else if (angles.length > 1) {
        let best = -1;
        for (let i = 0; i < angles.length; i++) {
          const a1 = angles[i], a2 = i + 1 < angles.length ? angles[i + 1] : angles[0] + Math.PI * 2;
          if (a2 - a1 > best) { best = a2 - a1; dir = (a1 + a2) / 2; }
        }
      }
      // the widest gap is preferred, but the building must not stand on someone else's road or building
      const candidates = [dir];
      for (let i = 0; i < 16; i++) candidates.push(dir + (i + 1) * Math.PI / 8);
      let bestScore = -Infinity;
      for (const c of candidates) {
        const cx = n.x + Math.cos(c) * 200, cy = n.y + Math.sin(c) * 200;
        let clear = Infinity;
        for (const r of roads) clear = Math.min(clear, segDist(cx, cy, r) - r.width / 2 - 95);
        for (const m of nodesById.values()) if (m !== n) clear = Math.min(clear, Math.hypot(cx - m.x, cy - m.y) - PLAZA_R - 100);
        for (const b of buildings) clear = Math.min(clear, Math.hypot(cx - (b.x + b.w / 2), cy - (b.y + b.h / 2)) - 190);
        for (const r of roads) if (r.a === n || r.b === n) {
          const ra = r.a === n ? Math.atan2(r.uy, r.ux) : Math.atan2(-r.uy, -r.ux);
          const diff = Math.abs(Math.atan2(Math.sin(c - ra), Math.cos(c - ra)));
          clear = Math.min(clear, (diff - 0.6) * 200);      // not on top of its own roads either
        }
        const score = Math.min(clear, 60) - (c === dir ? 0 : 5);
        if (score > bestScore) { bestScore = score; n.dir = c; }
      }
      dir = n.dir;
      const bx = n.x + Math.cos(dir) * 200, by = n.y + Math.sin(dir) * 200;
      if (n.device) {
        const b = { x: bx - 86, y: by - 48, w: 172, h: 96, node: n };
        buildings.push(b); n.building = b;
        const px = n.x + Math.cos(dir) * 112, py = n.y + Math.sin(dir) * 112;
        stops.push({ x: px - 34, y: py - 21, w: 68, h: 42, kind: "device", node: n, key: `device-${n.device.id}` });
      } else {
        n.sign = { x: bx, y: by };
      }
    }
  }

  buildIslands();
  buildRoads();
  placeBuildings();
  const bounds = { minX: Infinity, minY: Infinity, maxX: -Infinity, maxY: -Infinity };
  for (const isl of islands) {
    bounds.minX = Math.min(bounds.minX, isl.cx - isl.r); bounds.maxX = Math.max(bounds.maxX, isl.cx + isl.r);
    bounds.minY = Math.min(bounds.minY, isl.cy - isl.r); bounds.maxY = Math.max(bounds.maxY, isl.cy + isl.r);
  }
  if (!islands.length) {
    Object.assign(bounds, { minX: -500, minY: -500, maxX: 500, maxY: 500 });
    startOverlay.innerHTML = "<span>🏝️ No islands to drive on yet<small>Road Trip builds islands from the custom maps you can see "
      + "(Maps → Custom Maps) and from LLDP/CDP neighbours. Create a custom map, link a few devices and come back!</small></span>";
    startOverlay.style.cursor = "default";
  }
  const SEA = 900;

  // ================================================================ surfaces
  function segDist(px, py, r) {
    const t = clamp(((px - r.a.x) * r.ux + (py - r.a.y) * r.uy), 0, r.len);
    return Math.hypot(px - (r.a.x + r.ux * t), py - (r.a.y + r.uy * t));
  }
  function roadAt(px, py) {
    let best = null, bestD = Infinity;
    for (const r of roads) {
      if (px < r.minX || px > r.maxX || py < r.minY || py > r.maxY) continue;
      const d = segDist(px, py, r);
      if (d <= r.width / 2 + 2 && d < bestD) { best = r; bestD = d; }
    }
    return best;
  }
  function plazaAt(px, py) {
    for (const n of nodesById.values()) if (Math.abs(px - n.x) < PLAZA_R && Math.abs(py - n.y) < PLAZA_R && Math.hypot(px - n.x, py - n.y) <= PLAZA_R) return n;
    return null;
  }
  function islandAt(px, py) {
    for (const isl of islands) {
      if (Math.hypot(px - isl.cx, py - isl.cy) > isl.r + 60) continue;
      if (inHull(isl.hull, px, py)) return isl;
      for (const n of isl.nodes) if (Math.hypot(px - n.x, py - n.y) <= LAND_R) return isl;
      for (const r of roads) if (r.island === isl && segDist(px, py, r) <= LAND_R * 0.62) return isl;
    }
    return null;
  }
  function surfaceAt(px, py) {
    if (plazaAt(px, py) || roadAt(px, py)) return "road";
    return islandAt(px, py) ? "land" : "water";
  }

  // ================================================================ per-browser memory
  const VISITED_KEY = "librenms-roadtrip-visited", MUTE_KEY = "librenms-roadtrip-muted";
  let visited = new Set(), muted = false;
  try { visited = new Set(JSON.parse(localStorage.getItem(VISITED_KEY) || "[]")); muted = localStorage.getItem(MUTE_KEY) === "1"; } catch (e) { /* no storage */ }
  function markVisited(key) {
    visited.add(key);
    try { localStorage.setItem(VISITED_KEY, JSON.stringify([...visited])); } catch (e) { /* no storage */ }
  }

  // ================================================================ sound (Web Audio, generated)
  let audio = null, noiseBuffer = null;
  const VOLUME = 0.5;
  function initAudio() {
    if (audio) { audio.ctx.resume(); return; }
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return;
    const ac = new AC();
    const master = ac.createGain(); master.gain.value = muted ? 0 : VOLUME; master.connect(ac.destination);
    const osc = ac.createOscillator(); osc.type = "sawtooth"; osc.frequency.value = 40;
    const sub = ac.createOscillator(); sub.type = "square"; sub.frequency.value = 20;
    const filter = ac.createBiquadFilter(); filter.type = "lowpass"; filter.frequency.value = 300; filter.Q.value = 4;
    const engine = ac.createGain(); engine.gain.value = 0;
    osc.connect(filter); sub.connect(filter); filter.connect(engine); engine.connect(master); osc.start(); sub.start();
    noiseBuffer = ac.createBuffer(1, ac.sampleRate, ac.sampleRate);
    const d = noiseBuffer.getChannelData(0);
    for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
    const sea = ac.createBufferSource(); sea.buffer = noiseBuffer; sea.loop = true;
    const seaF = ac.createBiquadFilter(); seaF.type = "lowpass"; seaF.frequency.value = 500;
    const seaG = ac.createGain(); seaG.gain.value = 0;
    sea.connect(seaF); seaF.connect(seaG); seaG.connect(master); sea.start();
    audio = { ctx: ac, master, osc, sub, filter, engine, seaG };
  }
  function setMuted(v) {
    muted = v;
    try { localStorage.setItem(MUTE_KEY, v ? "1" : "0"); } catch (e) { /* no storage */ }
    if (audio) audio.master.gain.setTargetAtTime(v ? 0 : VOLUME, audio.ctx.currentTime, 0.02);
  }
  function tone(freq, start, dur, type, vol, end) {
    if (!audio) return;
    const t = audio.ctx.currentTime + start, o = audio.ctx.createOscillator(), g = audio.ctx.createGain();
    o.type = type; o.frequency.setValueAtTime(freq, t);
    if (end) o.frequency.exponentialRampToValueAtTime(end, t + dur);
    g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(vol, t + 0.015);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(audio.master); o.start(t); o.stop(t + dur + 0.05);
  }
  function noise(dur, type, freq, vol, end) {
    if (!audio) return;
    const t = audio.ctx.currentTime, s = audio.ctx.createBufferSource(); s.buffer = noiseBuffer;
    const f = audio.ctx.createBiquadFilter(); f.type = type; f.frequency.setValueAtTime(freq, t); f.Q.value = 1.4;
    if (end) f.frequency.exponentialRampToValueAtTime(end, t + dur);
    const g = audio.ctx.createGain(); g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    s.connect(f); f.connect(g); g.connect(audio.master); s.start(t); s.stop(t + dur + 0.05);
  }
  const sfx = {
    bump: s => { noise(0.25, "lowpass", 380, Math.min(0.9, 0.25 + s / 500)); tone(70, 0, 0.18, "sine", 0.5); },
    skid: () => noise(0.35, "bandpass", 2300, 0.18),
    horn: () => { tone(415, 0, 0.38, "square", 0.12); tone(523, 0, 0.38, "square", 0.1); },
    park: () => tone(990, 0, 0.07, "triangle", 0.15),
    arrive: () => [660, 880, 1320].forEach((f, i) => tone(f, i * 0.09, 0.32, "sine", 0.22)),
    sad: () => [392, 370, 349, 311].forEach((f, i) => tone(f, i * 0.28, 0.32, "sawtooth", 0.08)),
    splash: () => { noise(0.6, "lowpass", 1500, 0.5, 300); tone(240, 0, 0.25, "sine", 0.2, 90); },
    peep: () => { tone(1900, 0, 0.06, "sine", 0.08, 2300); tone(1900, 0.1, 0.06, "sine", 0.08, 2400); },
    squawk: () => { tone(1300, 0, 0.16, "square", 0.12, 500); tone(1500, 0.18, 0.2, "square", 0.12, 450); },
    spin: () => noise(1.2, "bandpass", 2600, 0.22, 900),
    radio: () => { tone(1200, 0, 0.05, "square", 0.04); tone(900, 0.07, 0.05, "square", 0.04); },
    ping: () => tone(1760, 0, 0.12, "sine", 0.12),
  };

  // ================================================================ the car
  const car = { x: 0, y: 0, angle: 0, moveAngle: 0, speed: 0, w: 24, l: 44, spin: 0, spinRate: 0 };
  let START = { x: 0, y: 0, angle: -Math.PI / 2 };
  (function chooseStart() {
    const want = CONFIG.start && stops.find(s => s.kind === "device" && s.node.device.id === CONFIG.start);
    const first = want || stops.find(s => s.kind === "device" && s.node.island === hub) || stops.find(s => s.kind === "device");
    if (first) {
      const n = first.node;
      START = { x: n.x, y: n.y, angle: n.dir + Math.PI };  // on the roundabout, the building behind you
      if (want) START = { x: first.x + first.w / 2 - Math.cos(n.dir) * 50, y: first.y + first.h / 2 - Math.sin(n.dir) * 50, angle: n.dir };
    }
    Object.assign(car, { x: START.x, y: START.y, angle: START.angle, moveAngle: START.angle });
  })();
  const keys = {};
  const stats = { safe: 0, bumped: 0, splashes: 0 };
  let started = false, paused = false;
  let parkedAt = null, parkTimer = 0, lastVisit = null, skidCd = 0, bumpCd = 0, splashCd = 0;
  let lastSafe = { x: car.x, y: car.y };
  const MAX = { road: 520, land: 230 };

  function jamFactor(r) {               // busy roads are slow: the traffic is in the way
    if (!r || !r.port || r.kind !== "road") return 1;
    const pct = Math.max(r.port.in_pct || 0, r.port.out_pct || 0);
    return pct >= 90 ? 0.35 : pct >= 75 ? 0.7 : 1;
  }

  function update(dt) {
    const up = keys.ArrowUp || keys.KeyW, down = keys.ArrowDown || keys.KeyS;
    const left = keys.ArrowLeft || keys.KeyA, right = keys.ArrowRight || keys.KeyD, brake = keys.Space;
    skidCd -= dt; bumpCd -= dt; splashCd -= dt;
    const surface = surfaceAt(car.x, car.y);
    const road = surface === "road" ? roadAt(car.x, car.y) : null;
    if (car.spin > 0) {
      car.spin -= dt; car.angle += car.spinRate * dt; car.spinRate *= Math.pow(0.35, dt);
      car.speed -= Math.sign(car.speed) * Math.min(Math.abs(car.speed), 330 * dt);
      if (car.spin <= 0) { car.moveAngle = car.angle; car.speed *= 0.5; }
    } else {
      const max = (MAX[surface] || 230) * jamFactor(road);
      if (up) car.speed += (car.speed < 0 ? 900 : 420) * dt;
      else if (down) car.speed -= (car.speed > 0 ? 900 : 300) * dt;
      else car.speed -= Math.sign(car.speed) * Math.min(Math.abs(car.speed), 260 * dt);
      if (brake) car.speed -= Math.sign(car.speed) * Math.min(Math.abs(car.speed), 1400 * dt);
      const hard = brake || (down && car.speed > 0) || (up && car.speed < 0);
      if (hard && Math.abs(car.speed) > 230 && skidCd <= 0) { sfx.skid(); skidCd = 0.3; }
      if (car.speed > max) car.speed = Math.max(max, car.speed - 700 * dt);
      car.speed = Math.max(-180, car.speed);
      const grip = Math.min(1, Math.abs(car.speed) / 120);
      car.angle += ((right ? 1 : 0) - (left ? 1 : 0)) * 2.8 * grip * dt * Math.sign(car.speed || 1);
      car.moveAngle = car.angle;
    }
    const nx = car.x + Math.cos(car.moveAngle) * car.speed * dt, ny = car.y + Math.sin(car.moveAngle) * car.speed * dt;
    if (surfaceAt(nx, ny) === "water") {                   // the sea stops you - with a splash
      if (Math.abs(car.speed) > 60 && splashCd <= 0) { sfx.splash(); splashCd = 0.6; stats.splashes++; splash(nx, ny); }
      car.speed *= -0.25;
    } else {
      car.x = nx; car.y = ny;
    }
    if (surfaceAt(car.x, car.y) === "water") { car.x = lastSafe.x; car.y = lastSafe.y; car.speed = 0; }
    else lastSafe = { x: car.x, y: car.y };
    collide();
    updatePenguins(dt);
    updateSplashes(dt);

    const spot = stops.find(p => car.x >= p.x && car.x <= p.x + p.w && car.y >= p.y && car.y <= p.y + p.h) || null;
    if (spot !== parkedAt) { parkedAt = spot; parkTimer = 0; if (spot && spot !== lastVisit) sfx.park(); }
    if (!spot) lastVisit = null;
    if (spot && Math.abs(car.speed) < 12 && car.spin <= 0 && lastVisit !== spot) {
      parkTimer += dt;
      if (parkTimer > 0.6) { lastVisit = spot; visit(spot); }
    }
  }
  function collide() {
    const r = 15;
    for (const b of buildings) {
      if (car.x < b.x - 40 || car.x > b.x + b.w + 40 || car.y < b.y - 40 || car.y > b.y + b.h + 40) continue;
      const cx = clamp(car.x, b.x, b.x + b.w), cy = clamp(car.y, b.y, b.y + b.h);
      const dx = car.x - cx, dy = car.y - cy, d2 = dx * dx + dy * dy;
      if (d2 < r * r) {
        const d = Math.sqrt(d2) || 0.01;
        car.x = cx + dx / d * r; car.y = cy + dy / d * r;
        if (d2 === 0) car.y = b.y + b.h + r;
        if (Math.abs(car.speed) > 40 && bumpCd <= 0) { sfx.bump(Math.abs(car.speed)); bumpCd = 0.25; }
        car.speed *= -0.3;
      }
    }
  }

  // splashes
  const splashes = [];
  function splash(x, y) { for (let i = 0; i < 14; i++) splashes.push({ x, y, vx: rand(-90, 90), vy: rand(-90, 90), t: 0 }); }
  function updateSplashes(dt) {
    for (const s of splashes) { s.t += dt; s.x += s.vx * dt; s.y += s.vy * dt; s.vx *= 0.92; s.vy *= 0.92; }
    for (let i = splashes.length - 1; i >= 0; i--) if (splashes[i].t > 0.8) splashes.splice(i, 1);
  }

  // ================================================================ penguins
  const penguins = [];
  let penguinTimer = rand(5, 9);
  function updatePenguins(dt) {
    penguinTimer -= dt;
    if (penguinTimer <= 0) { penguinTimer = rand(6, 13); spawnPenguin(); }
    for (const p of penguins) {
      p.t += dt;
      if (p.state === "walk") {
        p.x += Math.cos(p.dir) * 38 * dt; p.y += Math.sin(p.dir) * 38 * dt; p.walked += 38 * dt;
        const d = Math.hypot(car.x - p.x, car.y - p.y);
        p.closest = Math.min(p.closest, d);
        if (d < 26) {
          if (Math.abs(car.speed) > 60 && car.spin <= 0) hitPenguin(p);
          else if (car.speed > 0) car.speed = 0;
        }
        if (p.walked > p.distance) { p.state = "gone"; if (!p.hit && p.closest < 220) stats.safe++; }
      } else if (p.state === "tumble") {
        p.x += p.vx * dt; p.y += p.vy * dt; p.vx *= Math.pow(0.2, dt); p.vy *= Math.pow(0.2, dt);
        p.rot += p.vr * dt; p.vr *= Math.pow(0.3, dt);
        if (p.t > 1.6) { p.state = "walk"; p.t = 0; p.rot = 0; p.walked = Math.max(p.walked, p.distance - 80); }
      }
    }
    for (let i = penguins.length - 1; i >= 0; i--) if (penguins[i].state === "gone") penguins.splice(i, 1);
  }
  function spawnPenguin(force) {
    if (!force && (!started || paused || penguins.length >= 3 || Math.abs(car.speed) < 100)) return null;
    const ahead = force ? 260 : rand(360, 560);
    const fx = car.x + Math.cos(car.moveAngle) * ahead, fy = car.y + Math.sin(car.moveAngle) * ahead;
    const r = roadAt(fx, fy);
    if (!force && !r) return null;
    const dir = car.moveAngle + (Math.random() < 0.5 ? -1 : 1) * Math.PI / 2, back = r ? r.width / 2 + 40 : 90;
    const p = { x: fx - Math.cos(dir) * back, y: fy - Math.sin(dir) * back, dir, t: 0, walked: 0, distance: back * 2,
                state: "walk", rot: 0, vr: 0, vx: 0, vy: 0, closest: Infinity, hit: false };
    penguins.push(p); sfx.peep();
    return p;
  }
  function hitPenguin(p) {
    p.state = "tumble"; p.t = 0; p.hit = true;
    p.vx = Math.cos(car.moveAngle) * car.speed * 0.9 + rand(-60, 60); p.vy = Math.sin(car.moveAngle) * car.speed * 0.9 + rand(-60, 60);
    p.vr = rand(-14, 14); stats.bumped++;
    car.spin = 1.7; car.spinRate = (Math.random() < 0.5 ? -1 : 1) * rand(9, 12); car.moveAngle = car.angle;
    sfx.squawk(); sfx.spin();
  }

  // ================================================================ problems: N drives you to the next one
  let target = null, targetIndex = -1;
  function problems() {
    const list = [];
    for (const n of nodesById.values()) {
      const d = n.device;
      if (!d) continue;
      if (d.state === "down") list.push({ x: n.x, y: n.y, text: `${d.short} is down`, rank: 0 });
      else if (d.alerts > 0) list.push({ x: n.x, y: n.y, text: `${d.short}: ${d.alerts} active alert${d.alerts > 1 ? "s" : ""}`, rank: 1 });
      else if (d.state === "rebooted") list.push({ x: n.x, y: n.y, text: `${d.short} just rebooted`, rank: 3 });
    }
    for (const r of roads) {
      if (!r.port) continue;
      const mx = (r.a.x + r.b.x) / 2, my = (r.a.y + r.b.y) / 2;
      if (!r.port.up) list.push({ x: mx, y: my, text: `Link down: ${r.port.device} ${r.port.name}`, rank: 1 });
      else if (Math.max(r.port.in_pct || 0, r.port.out_pct || 0) >= 90) {
        list.push({ x: mx, y: my, text: `Traffic jam: ${r.port.device} ${r.port.name} ${Math.round(Math.max(r.port.in_pct, r.port.out_pct))}%`, rank: 2 });
      }
    }
    return list.sort((a, b) => a.rank - b.rank || dist(a, car) - dist(b, car));
  }
  function nextProblem() {
    const list = problems();
    if (!list.length) { target = { text: "No problems - all green! 🎉", none: true }; setTimeout(() => { if (target && target.none) target = null; }, 2500); return; }
    targetIndex = (targetIndex + 1) % (list.length + 1);
    target = targetIndex === list.length ? null : list[targetIndex];
    if (target) sfx.ping();
  }

  // ================================================================ visiting
  function visit(spot) {
    markVisited(spot.key);
    paused = true;
    Object.keys(keys).forEach(k => { keys[k] = false; });
    car.speed = 0;
    let title, url;
    if (spot.kind === "device") {
      const d = spot.node.device;
      title = `${{ up: "🟢", down: "🔴", disabled: "⚪", rebooted: "🟠" }[d.state] || "🖥️"} ${d.name}`;
      url = d.url;
      if (d.state === "down") sfx.sad(); else sfx.arrive();
    } else {
      const p = spot.road.port;
      title = `📊 ${p.device} ${p.name}${p.alias ? " - " + p.alias : ""}`;
      url = p.url;
      sfx.arrive();
    }
    document.getElementById("rt-modal-title").textContent = title;
    document.getElementById("rt-modal-open").href = url;
    frame.src = url;
    modal.classList.add("rt-open");
    document.getElementById("rt-modal-close").focus();
  }
  function leave() {
    if (!modal.classList.contains("rt-open")) return;
    modal.classList.remove("rt-open"); frame.src = "about:blank"; paused = false; canvas.focus();
  }
  document.getElementById("rt-modal-close").addEventListener("click", leave);
  modal.addEventListener("click", e => { if (e.target === modal) leave(); });
  frame.addEventListener("load", () => {
    try {
      const doc = frame.contentDocument, st = doc.createElement("style");
      st.textContent = "nav.navbar { display: none !important; } body { padding-top: 0 !important; }";
      doc.head.appendChild(st);
      frame.contentWindow.addEventListener("keydown", e => { if (e.key === "Escape") leave(); });
    } catch (e) { /* other origin */ }
  });

  // ================================================================ input
  const GAME_KEYS = ["ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", "KeyW", "KeyA", "KeyS", "KeyD", "Space"];
  window.addEventListener("keydown", e => {
    if (e.key === "Escape") { leave(); return; }
    if (!started || paused || document.activeElement !== canvas) return;
    if (GAME_KEYS.includes(e.code)) { keys[e.code] = true; e.preventDefault(); }
    if (e.code === "KeyR") Object.assign(car, { x: START.x, y: START.y, angle: START.angle, moveAngle: START.angle, speed: 0, spin: 0 });
    if (e.code === "KeyH" && !e.repeat) sfx.horn();
    if (e.code === "KeyM" && !e.repeat) setMuted(!muted);
    if (e.code === "KeyN" && !e.repeat) nextProblem();
    if (e.code === "KeyO" && !e.repeat) overview = !overview;
  });
  window.addEventListener("keyup", e => { if (GAME_KEYS.includes(e.code)) keys[e.code] = false; });
  canvas.addEventListener("blur", () => Object.keys(keys).forEach(k => { keys[k] = false; }));
  function start() { if (!islands.length) return; started = true; initAudio(); startOverlay.style.display = "none"; canvas.focus(); }
  startOverlay.addEventListener("click", start);
  canvas.addEventListener("click", () => { if (!started) start(); initAudio(); canvas.focus(); });

  // ================================================================ the car radio: LibreNMS event log
  const SEV = { 0: "#94a3b8", 1: "#22c55e", 2: "#38bdf8", 3: "#facc15", 4: "#fb923c", 5: "#ef4444" };
  let radioIndex = -1;
  function radioNext() {
    const list = DATA.radio || [];
    if (!list.length) { radioEl.innerHTML = "📻 Radio LibreNMS: all quiet on the network"; return; }
    radioIndex = (radioIndex + 1) % list.length;
    const e = list[radioIndex], time = (e.time || "").slice(11, 16);
    radioEl.style.opacity = 0;
    setTimeout(() => {
      radioEl.innerHTML = `📻 <b>${escapeHtml(time)}</b><span class="rt-sev" style="background:${SEV[e.severity] || "#94a3b8"}"></span>`
        + `<b>${escapeHtml(e.device || "")}</b> ${escapeHtml(e.message)}`;
      radioEl.style.opacity = 1;
      if (started && !paused) sfx.radio();
    }, 400);
  }
  radioNext();
  setInterval(radioNext, 7000);

  // keep traffic and status fresh
  function refresh() {
    fetch(CONFIG.refresh, { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(r => (r.ok ? r.json() : null)).then(fresh => {
        if (!fresh) return;
        const devs = new Map(), ports = new Map();
        for (const isl of fresh.islands) {
          for (const n of isl.nodes) if (n.device) devs.set(n.device.id, n.device);
          for (const e of isl.edges) if (e.port) ports.set(e.port.id, e.port);
        }
        for (const n of nodesById.values()) if (n.device && devs.has(n.device.id)) n.device = devs.get(n.device.id);
        for (const r of roads) if (r.port && ports.has(r.port.id)) r.port = ports.get(r.port.id);
        DATA.radio = fresh.radio;
      }).catch(() => { /* keep the old data */ });
  }
  setInterval(refresh, 60000);

  // ================================================================ drawing
  let viewW = 0, viewH = 0, dpr = 1;
  function resize() {
    dpr = window.devicePixelRatio || 1; viewW = wrap.clientWidth; viewH = wrap.clientHeight;
    canvas.width = Math.round(viewW * dpr); canvas.height = Math.round(viewH * dpr);
  }
  window.addEventListener("resize", resize);
  resize();

  const icons = new Map();
  function icon(url) {
    if (!url) return null;
    if (!icons.has(url)) { const img = new Image(); img.src = url; icons.set(url, img); }
    const img = icons.get(url);
    return img.complete && img.naturalWidth > 0 ? img : null;
  }
  function roundRect(x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  const fitCache = new Map();
  function fitText(text, max) {
    const key = ctx.font + "|" + max + "|" + text;
    let out = fitCache.get(key);
    if (out === undefined) {
      out = text;
      if (ctx.measureText(out).width > max) {
        while (out.length > 1 && ctx.measureText(out + "…").width > max) out = out.slice(0, -1);
        out += "…";
      }
      fitCache.set(key, out);
    }
    return out;
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  }
  // LibreNMS custom map colours: green - yellow - orange - red, purple above 100 %
  function utilColour(pct) {
    if (pct === null || pct === undefined) return "#94a3b8";
    if (pct > 100) return "#a855f7";
    return `hsl(${Math.round(120 - clamp(pct, 0, 100) * 1.2)} 85% 48%)`;
  }
  const fmtBps = b => (b >= 1e9 ? (b / 1e9).toFixed(1) + " Gb/s" : b >= 1e6 ? (b / 1e6).toFixed(0) + " Mb/s" : b >= 1e3 ? (b / 1e3).toFixed(0) + " kb/s" : Math.round(b) + " b/s");
  const fmtSpeed = s => (s >= 1e9 ? s / 1e9 + "G" : s >= 1e6 ? s / 1e6 + "M" : "?");
  const visible = (x0, y0, x1, y1, v) => x1 > v.x && x0 < v.x + v.w && y1 > v.y && y0 < v.y + v.h;
  const STATE_COLOURS = { up: "#22c55e", down: "#ef4444", disabled: "#94a3b8", rebooted: "#f59e0b" };
  const TYPE_ROOF = { network: "#2563eb", firewall: "#dc2626", server: "#334155", wireless: "#7c3aed", storage: "#0d9488",
                      printer: "#a16207", power: "#ca8a04", workstation: "#0891b2", environment: "#16a34a" };

  function drawSea(v, now) {
    ctx.fillStyle = "#1d6fa5"; ctx.fillRect(v.x, v.y, v.w, v.h);
    ctx.strokeStyle = "rgba(255,255,255,.12)"; ctx.lineWidth = 3;
    const step = 140, t = now / 1000;
    ctx.beginPath();
    for (let y = Math.floor(v.y / step) * step; y < v.y + v.h; y += step) {
      for (let x = Math.floor(v.x / step) * step; x < v.x + v.w; x += step) {
        const ox = ((x * 7 + y * 3) % 97), wob = Math.sin(t * 1.3 + x * 0.01 + y * 0.02) * 6;
        ctx.moveTo(x + ox, y + wob); ctx.quadraticCurveTo(x + ox + 15, y - 8 + wob, x + ox + 30, y + wob);
      }
    }
    ctx.stroke();
  }
  function drawLand(isl) {
    const layer = (color, extra) => {
      ctx.fillStyle = color; ctx.strokeStyle = color; ctx.lineCap = "round";
      for (const n of isl.nodes) { ctx.beginPath(); ctx.arc(n.x, n.y, LAND_R + extra, 0, Math.PI * 2); ctx.fill(); }
      if (isl.hull.length >= 3) {
        ctx.beginPath(); isl.hull.forEach((q, i) => (i ? ctx.lineTo(q.x, q.y) : ctx.moveTo(q.x, q.y))); ctx.closePath(); ctx.fill();
      }
      for (const r of roads) {
        if (r.island !== isl) continue;
        ctx.lineWidth = LAND_R * 1.24 + extra * 2;
        ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke();
      }
    };
    layer("rgba(255,255,255,.18)", 34);    // surf
    layer("#e9d8a6", 22);                   // beach
    layer("#5b9a4e", 0);                    // grass
    ctx.lineCap = "butt";
  }
  function drawRoad(r) {
    ctx.lineCap = "round";
    if (r.kind === "bridge") {
      // pillars, deck, railings
      ctx.fillStyle = "rgba(0,0,0,.25)";
      for (let t = 120; t < r.len - 60; t += 150) {
        const px = r.a.x + r.ux * t, py = r.a.y + r.uy * t;
        ctx.beginPath(); ctx.ellipse(px + 6, py + 10, r.width / 2 + 8, 14, Math.atan2(r.uy, r.ux) + Math.PI / 2, 0, Math.PI * 2); ctx.fill();
      }
      ctx.strokeStyle = "#8b5e34"; ctx.lineWidth = r.width + 16;
      ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke();
      ctx.strokeStyle = "#a8a29e"; ctx.lineWidth = r.width;
      ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke();
      ctx.strokeStyle = "#fafaf9"; ctx.lineWidth = 3; ctx.setLineDash([26, 20]);
      ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke(); ctx.setLineDash([]);
      return;
    }
    const down = r.port && !r.port.up;
    ctx.strokeStyle = down ? "#57534e" : "#3b3f46"; ctx.lineWidth = r.width + 6;
    ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke();
    ctx.strokeStyle = down ? "#6b7280" : "#41454d"; ctx.lineWidth = r.width;
    ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke();
    if (!down) {
      ctx.strokeStyle = "#e5c34a"; ctx.lineWidth = 3; ctx.setLineDash([24, 18]);
      ctx.beginPath(); ctx.moveTo(r.a.x, r.a.y); ctx.lineTo(r.b.x, r.b.y); ctx.stroke(); ctx.setLineDash([]);
    }
    ctx.lineCap = "butt";
  }
  // traffic: little cars per direction - more of them and slower the busier the link, in its utilisation colour
  function drawTraffic(r, now) {
    if (!r.port || !r.port.up) return;
    const t = now / 1000;
    for (const [pct, sign] of [[r.port.out_pct, 1], [r.port.in_pct, -1]]) {
      if (!pct || pct < 0.5) continue;
      const n = clamp(Math.ceil(pct / 7), 1, 16), speed = 40 + (100 - clamp(pct, 0, 100)) * 1.6;
      const lane = sign * r.width / 4, color = utilColour(pct);
      for (let i = 0; i < n; i++) {
        let s = ((t * speed + (i / n) * r.len) % r.len);
        if (sign < 0) s = r.len - s;
        if (s < PLAZA_R || s > r.len - PLAZA_R) continue;
        const x = r.a.x + r.ux * s - r.uy * lane, y = r.a.y + r.uy * s + r.ux * lane;
        ctx.save(); ctx.translate(x, y); ctx.rotate(Math.atan2(r.uy * sign, r.ux * sign));
        ctx.fillStyle = color; roundRect(-9, -5, 18, 10, 3); ctx.fill();
        ctx.fillStyle = "rgba(255,255,255,.7)"; ctx.fillRect(3, -4, 3, 8);
        ctx.restore();
      }
    }
  }
  function drawRoadSign(r) {
    const mx = (r.a.x + r.b.x) / 2, my = (r.a.y + r.b.y) / 2;
    if (r.kind === "bridge") {
      ctx.font = "700 16px system-ui, sans-serif"; ctx.textAlign = "center"; ctx.textBaseline = "middle";
      const w = ctx.measureText(r.label).width + 24;
      ctx.fillStyle = "#14532d"; roundRect(mx - w / 2, my - 44, w, 28, 6); ctx.fill();
      ctx.fillStyle = "#fff"; ctx.fillText(r.label, mx, my - 30); ctx.textAlign = "left";
      return;
    }
    if (!r.port) return;
    const p = r.port, down = !p.up;
    const text = down ? `⛔ ${p.name} - ROAD CLOSED` : `${p.name} · ${fmtSpeed(p.speed)} · ▲${Math.round(p.out_pct || 0)}% ▼${Math.round(p.in_pct || 0)}%`;
    ctx.font = "700 13px system-ui, sans-serif"; ctx.textAlign = "center"; ctx.textBaseline = "middle";
    const w = ctx.measureText(text).width + 18;
    const off = r.width / 2 + 22, sx = mx + r.uy * off, sy = my - r.ux * off;
    ctx.fillStyle = down ? "#b91c1c" : "#1e3a8a"; roundRect(sx - w / 2, sy - 13, w, 26, 6); ctx.fill();
    ctx.fillStyle = "#fff"; ctx.fillText(text, sx, sy); ctx.textAlign = "left";
    if (down) {                                            // barriers
      for (const s of [PLAZA_R + 30, r.len - PLAZA_R - 30]) {
        const bx = r.a.x + r.ux * s, by = r.a.y + r.uy * s;
        ctx.save(); ctx.translate(bx, by); ctx.rotate(Math.atan2(r.uy, r.ux) + Math.PI / 2);
        for (let i = -r.width / 2; i < r.width / 2; i += 12) { ctx.fillStyle = (i / 12) % 2 ? "#fff" : "#dc2626"; ctx.fillRect(i, -5, 12, 10); }
        ctx.restore();
      }
    }
  }
  function drawPlaza(n) {
    ctx.fillStyle = "#41454d"; ctx.beginPath(); ctx.arc(n.x, n.y, PLAZA_R, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#4d7c3f"; ctx.beginPath(); ctx.arc(n.x, n.y, PLAZA_R * 0.32, 0, Math.PI * 2); ctx.fill();
    ctx.strokeStyle = "rgba(255,255,255,.5)"; ctx.lineWidth = 2; ctx.setLineDash([10, 10]);
    ctx.beginPath(); ctx.arc(n.x, n.y, PLAZA_R * 0.66, 0, Math.PI * 2); ctx.stroke(); ctx.setLineDash([]);
  }
  function drawStop(p) {
    ctx.fillStyle = "rgba(255,255,255,.6)"; roundRect(p.x, p.y, p.w, p.h, 6); ctx.fill();
    ctx.strokeStyle = parkedAt === p ? "#2563eb" : "#fff"; ctx.lineWidth = 3; ctx.stroke();
    ctx.fillStyle = parkedAt === p ? "#2563eb" : "#475569"; ctx.font = "700 21px system-ui, sans-serif";
    ctx.textAlign = "center"; ctx.textBaseline = "middle";
    ctx.fillText(p.kind === "port" ? "P📊" : "P", p.x + p.w / 2, p.y + p.h / 2 + 1); ctx.textAlign = "left";
  }
  function drawBuilding(n, now) {
    const b = n.building, d = n.device, state = d.state, t = now / 1000;
    const dark = state === "down", grey = state === "disabled";
    ctx.fillStyle = "rgba(0,0,0,.25)"; roundRect(b.x + 7, b.y + 7, b.w, b.h, 8); ctx.fill();
    ctx.fillStyle = grey ? "#9ca3af" : dark ? "#1f2937" : (TYPE_ROOF[d.type] || "#2563eb");
    roundRect(b.x, b.y, b.w, b.h, 8); ctx.fill();
    ctx.fillStyle = dark ? "#374151" : grey ? "#d1d5db" : "rgba(255,255,255,.92)";
    roundRect(b.x + 7, b.y + 7, b.w - 14, b.h - 14, 6); ctx.fill();
    const img = icon(d.icon);
    if (img) { ctx.globalAlpha = dark ? 0.35 : 1; ctx.drawImage(img, b.x + 14, b.y + 14, 34, 34); ctx.globalAlpha = 1; }
    ctx.fillStyle = dark ? "#e5e7eb" : "#0f172a"; ctx.textBaseline = "middle";
    ctx.font = "700 13px system-ui, sans-serif"; ctx.fillText(fitText(d.short, b.w - 66), b.x + 54, b.y + 24);
    ctx.font = "11px system-ui, sans-serif"; ctx.fillStyle = dark ? "#cbd5e1" : "#475569";
    ctx.fillText(fitText(d.hardware || d.os, b.w - 66), b.x + 54, b.y + 40);
    ctx.fillText(fitText(d.location || d.type, b.w - 24), b.x + 14, b.y + 66);
    // status light
    ctx.fillStyle = STATE_COLOURS[state] || "#94a3b8";
    if (state === "down" && Math.floor(t * 2) % 2) ctx.fillStyle = "#7f1d1d";
    ctx.beginPath(); ctx.arc(b.x + b.w - 16, b.y + 16, 7, 0, Math.PI * 2); ctx.fill();
    if (grey) {                                            // boarded up
      ctx.strokeStyle = "#78350f"; ctx.lineWidth = 7;
      ctx.beginPath(); ctx.moveTo(b.x + 10, b.y + 20); ctx.lineTo(b.x + b.w - 10, b.y + b.h - 20); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(b.x + b.w - 10, b.y + 20); ctx.lineTo(b.x + 10, b.y + b.h - 20); ctx.stroke();
      ctx.fillStyle = "#111827"; ctx.font = "700 12px system-ui, sans-serif"; ctx.textAlign = "center";
      ctx.fillText("DISABLED", b.x + b.w / 2, b.y + b.h / 2); ctx.textAlign = "left";
    }
    if (state === "rebooted") {                            // scaffolding
      ctx.strokeStyle = "#f59e0b"; ctx.lineWidth = 2;
      for (let x = b.x - 6; x <= b.x + b.w + 6; x += 22) { ctx.beginPath(); ctx.moveTo(x, b.y - 6); ctx.lineTo(x, b.y + b.h + 6); ctx.stroke(); }
      ctx.beginPath(); ctx.moveTo(b.x - 6, b.y + b.h / 2); ctx.lineTo(b.x + b.w + 6, b.y + b.h / 2); ctx.stroke();
      ctx.font = "16px system-ui, sans-serif"; ctx.fillText("🚧", b.x + b.w - 26, b.y + b.h - 14);
    }
    if (dark) {                                            // smoke
      for (let i = 0; i < 6; i++) {
        const age = (t * 0.6 + i / 6) % 1;
        ctx.fillStyle = `rgba(80,80,80,${0.45 * (1 - age)})`;
        ctx.beginPath(); ctx.arc(b.x + b.w * 0.7 + Math.sin(age * 6 + i) * 10, b.y - age * 90, 10 + age * 22, 0, Math.PI * 2); ctx.fill();
      }
      ctx.font = "20px system-ui, sans-serif"; ctx.fillText("🔥", b.x + b.w - 32, b.y + b.h - 16);
    }
    if (d.alerts > 0) {                                    // flashing beacon with the number of alerts
      const on = Math.floor(t * 3) % 2;
      ctx.fillStyle = on ? "#ef4444" : "#7f1d1d";
      ctx.beginPath(); ctx.arc(b.x + 8, b.y + 8, 13, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = "#fff"; ctx.font = "700 13px system-ui, sans-serif"; ctx.textAlign = "center";
      ctx.fillText(String(d.alerts), b.x + 8, b.y + 9); ctx.textAlign = "left";
    }
    if (visited.has(`device-${d.id}`)) { ctx.font = "16px system-ui, sans-serif"; ctx.fillText("🚩", b.x + b.w - 24, b.y + b.h - 34); }
  }
  function drawSign(n, now) {
    const s = n.sign;
    if (n.bridge) {                                        // bridge head with the status of the far island
      const other = islands.find(o => String(o.id) === String(n.bridge));
      const text = `🌉 ${other ? other.name : n.label}`;
      ctx.font = "700 16px system-ui, sans-serif"; ctx.textAlign = "center"; ctx.textBaseline = "middle";
      const w = ctx.measureText(text).width + 28;
      ctx.fillStyle = "#78350f"; ctx.fillRect(s.x - w / 2 + 8, s.y + 16, 6, 30); ctx.fillRect(s.x + w / 2 - 14, s.y + 16, 6, 30);
      ctx.fillStyle = "#166534"; roundRect(s.x - w / 2, s.y - 18, w, 36, 7); ctx.fill();
      ctx.strokeStyle = "#fff"; ctx.lineWidth = 2; roundRect(s.x - w / 2 + 3, s.y - 15, w - 6, 30, 5); ctx.stroke();
      ctx.fillStyle = "#fff"; ctx.fillText(text, s.x, s.y);
      if (n.bridge_down) {
        const on = Math.floor(now / 400) % 2;
        ctx.fillStyle = on ? "#facc15" : "#a16207"; ctx.beginPath(); ctx.arc(s.x + w / 2 + 14, s.y, 9, 0, Math.PI * 2); ctx.fill();
        ctx.font = "700 12px system-ui, sans-serif"; ctx.fillStyle = "#fde047";
        ctx.fillText("⚠ devices down over there", s.x, s.y + 34);
      }
      ctx.textAlign = "left";
      return;
    }
    // other nodes (e.g. "Internet"): a landmark
    ctx.font = "44px system-ui, sans-serif"; ctx.textAlign = "center"; ctx.textBaseline = "middle";
    ctx.fillText(/internet|wan|cloud/i.test(n.label) ? "☁️" : "📍", s.x, s.y - 8);
    ctx.font = "700 15px system-ui, sans-serif"; ctx.fillStyle = "#0f172a";
    ctx.fillText(n.label, s.x, s.y + 30); ctx.textAlign = "left";
  }
  function drawIslandName(isl) {
    const size = Math.round(34 / Math.min(1, zoom * 1.6));      // readable from far above too
    const top = Math.min(...isl.nodes.map(n => n.y)) - LAND_R - 20 - size / 2;
    ctx.font = `800 ${size}px system-ui, sans-serif`; ctx.textAlign = "center"; ctx.textBaseline = "middle";
    ctx.lineWidth = size / 4; ctx.strokeStyle = "rgba(15,23,42,.55)"; ctx.strokeText(isl.name, isl.cx, top);
    ctx.fillStyle = "#fff"; ctx.fillText(isl.name, isl.cx, top); ctx.textAlign = "left";
  }
  function drawPenguin(p) {
    ctx.save(); ctx.translate(p.x, p.y);
    ctx.rotate(p.state === "tumble" ? p.rot : p.dir + Math.PI / 2 + Math.sin(p.t * 12) * 0.18);
    const step = Math.sin(p.t * 12) * 3;
    ctx.fillStyle = "rgba(0,0,0,.25)"; ctx.beginPath(); ctx.ellipse(3, 4, 11, 13, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#f97316";
    ctx.beginPath(); ctx.ellipse(-5, 11 + step, 4, 3, 0, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(5, 11 - step, 4, 3, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#111827"; ctx.beginPath(); ctx.ellipse(0, 0, 10, 13, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#f8fafc"; ctx.beginPath(); ctx.ellipse(0, 2, 6.5, 9, 0, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#111827";
    ctx.beginPath(); ctx.ellipse(-10, 1, 3, 7, 0.3, 0, Math.PI * 2); ctx.fill();
    ctx.beginPath(); ctx.ellipse(10, 1, 3, 7, -0.3, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#fff"; ctx.beginPath(); ctx.arc(-3, -7, 2, 0, Math.PI * 2); ctx.arc(3, -7, 2, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = "#f97316"; ctx.beginPath(); ctx.moveTo(-2.5, -4); ctx.lineTo(2.5, -4); ctx.lineTo(0, 0); ctx.fill();
    ctx.restore();
    ctx.textAlign = "center"; ctx.textBaseline = "middle";
    if (p.state === "tumble") { ctx.font = "16px system-ui, sans-serif"; ctx.fillText("💫", p.x, p.y - 22); }
    else if (Math.hypot(car.x - p.x, car.y - p.y) < 320 && Math.abs(car.speed) > 150) {
      ctx.fillStyle = "#fde047"; ctx.font = "700 22px system-ui, sans-serif"; ctx.fillText("!", p.x, p.y - 24);
    }
    ctx.textAlign = "left";
  }
  function drawCar() {
    ctx.save(); ctx.translate(car.x, car.y); ctx.rotate(car.angle);
    ctx.fillStyle = "rgba(0,0,0,.3)"; roundRect(-car.l / 2 + 4, -car.w / 2 + 4, car.l, car.w, 7); ctx.fill();
    ctx.fillStyle = "#111827"; [[-13, -14], [9, -14], [-13, 10], [9, 10]].forEach(([x, y]) => ctx.fillRect(x, y, 10, 4));
    ctx.fillStyle = "#dc2626"; roundRect(-car.l / 2, -car.w / 2, car.l, car.w, 7); ctx.fill();
    ctx.fillStyle = "#bfdbfe"; roundRect(2, -car.w / 2 + 4, 11, car.w - 8, 3); ctx.fill();
    ctx.fillStyle = "#93c5fd"; roundRect(-16, -car.w / 2 + 5, 8, car.w - 10, 3); ctx.fill();
    ctx.fillStyle = "#fde68a"; ctx.fillRect(car.l / 2 - 3, -car.w / 2 + 3, 3, 5); ctx.fillRect(car.l / 2 - 3, car.w / 2 - 8, 3, 5);
    ctx.restore();
  }
  function drawCompass() {                               // arrow from the car to the selected problem
    if (!target || target.none) return;
    const a = Math.atan2(target.y - car.y, target.x - car.x), d = Math.hypot(target.x - car.x, target.y - car.y);
    ctx.save(); ctx.translate(car.x, car.y); ctx.rotate(a);
    ctx.fillStyle = "rgba(239,68,68,.9)";
    ctx.beginPath(); ctx.moveTo(70, 0); ctx.lineTo(48, -13); ctx.lineTo(48, 13); ctx.closePath(); ctx.fill();
    ctx.restore();
    if (d < 140) target = null;
  }
  function drawWorld(v, now) {
    drawSea(v, now);
    for (const isl of islands) if (visible(isl.cx - isl.r - 60, isl.cy - isl.r - 60, isl.cx + isl.r + 60, isl.cy + isl.r + 60, v)) drawLand(isl);
    for (const r of roads) if (r.kind === "bridge" && visible(r.minX, r.minY, r.maxX, r.maxY, v)) drawRoad(r);
    for (const r of roads) if (r.kind === "road" && visible(r.minX, r.minY, r.maxX, r.maxY, v)) drawRoad(r);
    for (const n of nodesById.values()) if (visible(n.x - PLAZA_R, n.y - PLAZA_R, n.x + PLAZA_R, n.y + PLAZA_R, v)) drawPlaza(n);
    for (const r of roads) if (visible(r.minX, r.minY, r.maxX, r.maxY, v)) drawTraffic(r, now);
    for (const p of stops) if (visible(p.x, p.y, p.x + p.w, p.y + p.h, v)) drawStop(p);
    for (const r of roads) if (visible(r.minX - 200, r.minY - 60, r.maxX + 200, r.maxY + 60, v)) drawRoadSign(r);
    for (const n of nodesById.values()) {
      if (n.building && visible(n.building.x - 20, n.building.y - 120, n.building.x + n.building.w + 20, n.building.y + n.building.h + 20, v)) drawBuilding(n, now);
      else if (n.sign && visible(n.sign.x - 200, n.sign.y - 60, n.sign.x + 200, n.sign.y + 60, v)) drawSign(n, now);
    }
    for (const isl of islands) if (visible(isl.cx - 1200, isl.cy - isl.r - 400, isl.cx + 1200, isl.cy + isl.r, v)) drawIslandName(isl);
    for (const p of penguins) drawPenguin(p);
    ctx.fillStyle = "rgba(255,255,255,.85)";
    for (const s of splashes) { ctx.beginPath(); ctx.arc(s.x, s.y, 5 * (1 - s.t), 0, Math.PI * 2); ctx.fill(); }
    drawCompass();
    drawCar();
  }
  function drawMinimap() {
    const mw = 200, W = bounds.maxX - bounds.minX + 400, H = bounds.maxY - bounds.minY + 400;
    const mh = clamp(mw * H / W, 70, 200), s = Math.min(mw / W, mh / H);
    const x0 = viewW - mw - 14, y0 = viewH - mh - 14, ox = bounds.minX - 200, oy = bounds.minY - 200;
    ctx.fillStyle = "rgba(15,23,42,.8)"; roundRect(x0 - 6, y0 - 6, mw + 12, mh + 12, 7); ctx.fill();
    ctx.fillStyle = "#1d6fa5"; ctx.fillRect(x0, y0, mw, mh);
    ctx.strokeStyle = "#d6c08a"; ctx.lineWidth = 2;
    for (const r of roads) if (r.kind === "bridge") { ctx.beginPath(); ctx.moveTo(x0 + (r.a.x - ox) * s, y0 + (r.a.y - oy) * s); ctx.lineTo(x0 + (r.b.x - ox) * s, y0 + (r.b.y - oy) * s); ctx.stroke(); }
    for (const isl of islands) {
      ctx.fillStyle = "#5b9a4e";
      for (const n of isl.nodes) { ctx.beginPath(); ctx.arc(x0 + (n.x - ox) * s, y0 + (n.y - oy) * s, Math.max(2, LAND_R * s), 0, Math.PI * 2); ctx.fill(); }
      for (const n of isl.nodes) {
        if (n.device && (n.device.state === "down" || n.device.alerts > 0)) {
          ctx.fillStyle = "#ef4444"; ctx.beginPath(); ctx.arc(x0 + (n.x - ox) * s, y0 + (n.y - oy) * s, 3, 0, Math.PI * 2); ctx.fill();
        }
      }
    }
    if (target && !target.none) { ctx.strokeStyle = "#fde047"; ctx.lineWidth = 2; ctx.beginPath(); ctx.arc(x0 + (target.x - ox) * s, y0 + (target.y - oy) * s, 6, 0, Math.PI * 2); ctx.stroke(); }
    ctx.fillStyle = "#fff"; ctx.beginPath(); ctx.arc(x0 + (car.x - ox) * s, y0 + (car.y - oy) * s, 4, 0, Math.PI * 2); ctx.fill();
  }

  const totalStops = stops.filter(s => s.kind === "device").length;
  function drawHud() {
    const isl = islandAt(car.x, car.y), road = roadAt(car.x, car.y), plaza = plazaAt(car.x, car.y);
    let where = isl ? `🏝️ ${isl.name}` : road && road.kind === "bridge" ? `🌉 ${road.label}` : "🌊 At sea";
    if (car.spin > 0) where = "🌀 Spinning out!";
    let roadInfo = "";
    if (road && road.port) {
      const p = road.port;
      roadInfo = p.up
        ? `<div class="rt-road">🛣️ <b>${escapeHtml(p.device)} ${escapeHtml(p.name)}</b>${p.alias ? " · " + escapeHtml(p.alias) : ""}<br>`
          + `${fmtSpeed(p.speed)} · ▲ ${Math.round(p.out_pct || 0)}% (${fmtBps(p.out_bps)}) · ▼ ${Math.round(p.in_pct || 0)}% (${fmtBps(p.in_bps)})`
          + `${jamFactor(road) < 1 ? "<br>🚦 Heavy traffic - slow down" : ""}</div>`
        : `<div class="rt-road">⛔ <b>${escapeHtml(p.device)} ${escapeHtml(p.name)}</b> is down - road closed</div>`;
    } else if (plaza && plaza.device) {
      roadInfo = `<div class="rt-road">🔄 Roundabout at <b>${escapeHtml(plaza.device.short)}</b></div>`;
    }
    const parked = parkedAt ? `<div>🅿️ ${escapeHtml(parkedAt.kind === "device" ? parkedAt.node.device.short : parkedAt.road.port.name)}${Math.abs(car.speed) < 12 ? "" : " - stop here to visit"}</div>` : "";
    const seen = stops.filter(s => s.kind === "device" && visited.has(s.key)).length;
    const nav = target ? `<div class="rt-road">🧭 ${escapeHtml(target.text)}${target.none ? "" : ` · ${Math.round(Math.hypot(target.x - car.x, target.y - car.y) / 10)} m`}</div>` : "";
    hud.innerHTML = `<div class="rt-big">${Math.round(Math.abs(car.speed) / 5)} km/h</div><div>${escapeHtml(where)}</div>${parked}${roadInfo}${nav}`
      + `<div class="rt-muted" style="margin-top:4px">🚩 Visited ${seen} of ${totalStops} · 🐧 ${stats.safe} safe, ${stats.bumped} bumped · ${muted ? "🔇" : "🔊"} (M)</div>`;
  }

  // ================================================================ main loop
  let camX = car.x, camY = car.y, zoom = 1, last = performance.now(), hudTimer = 0, overview = false;
  function loop(now) {
    const dt = Math.min(0.05, (now - last) / 1000); last = now;
    if (started && !paused) update(dt);
    if (audio) {
      const t = audio.ctx.currentTime, v = Math.abs(car.speed), run = started && !paused, thr = keys.ArrowUp || keys.KeyW || keys.ArrowDown || keys.KeyS;
      const pitch = 38 + v * 0.17 + (thr ? 6 : 0);
      audio.osc.frequency.setTargetAtTime(pitch, t, 0.06); audio.sub.frequency.setTargetAtTime(pitch / 2, t, 0.06);
      audio.filter.frequency.setTargetAtTime(260 + v * 1.6 + (thr ? 350 : 0), t, 0.06);
      audio.engine.gain.setTargetAtTime(run ? 0.05 + (thr ? 0.04 : 0) + Math.min(v, 520) / 520 * 0.05 : 0, t, 0.08);
      const nearSea = run && (roadAt(car.x, car.y) || {}).kind === "bridge";
      audio.seaG.gain.setTargetAtTime(nearSea ? 0.06 : 0.015 * (run ? 1 : 0), t, 0.3);
    }
    let lookX = car.x + Math.cos(car.moveAngle) * car.speed * 0.35, lookY = car.y + Math.sin(car.moveAngle) * car.speed * 0.35;
    let wantZoom = Math.max(0.5, 1 - Math.abs(car.speed) / 1300);
    if (overview) {                                        // O: the whole archipelago from above
      lookX = (bounds.minX + bounds.maxX) / 2; lookY = (bounds.minY + bounds.maxY) / 2 - 250;   // room for the names
      wantZoom = Math.min(viewW / (bounds.maxX - bounds.minX + 400), viewH / (bounds.maxY - bounds.minY + 1300));
    }
    camX += (lookX - camX) * Math.min(1, dt * 4); camY += (lookY - camY) * Math.min(1, dt * 4);
    zoom += (wantZoom - zoom) * Math.min(1, dt * 3);
    const v = { x: camX - viewW / 2 / zoom, y: camY - viewH / 2 / zoom, w: viewW / zoom, h: viewH / zoom };
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.save(); ctx.translate(viewW / 2, viewH / 2); ctx.scale(zoom, zoom); ctx.translate(-camX, -camY);
    drawWorld(v, now);
    ctx.restore();
    if (!overview) drawMinimap();
    hudTimer -= dt;
    if (hudTimer <= 0) { drawHud(); hudTimer = 0.1; }
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);

  // for automated tests
  window.libreRoadTrip = {
    car, islands, roads, stops, buildings, keys, stats, penguins, sfx,
    teleport(x, y, a) { Object.assign(car, { x, y, angle: a ?? car.angle, moveAngle: a ?? car.angle, speed: 0 }); },
    surfaceAt, nextProblem, target: () => target, spawnPenguin, start, refresh,
    setOverview(v) { overview = v; }, isOverview: () => overview,
  };
})();
@endverbatim
