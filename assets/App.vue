<template>
  <div class="layout">
    <header class="header">
      <h1>WLED Mock — Ambilight Visualizer</h1>
      <div class="status" :class="online ? 'online' : 'offline'">
        {{ online ? `● Online · ${ledCount} LEDs` : '● Offline' }}
      </div>
    </header>

    <main class="main">
      <!-- Config panel -->
      <aside class="config-panel">
        <h2>Strip Configuration</h2>

        <div class="field-group">
          <label>Bottom-left segment (cm)</label>
          <input type="number" v-model.number="config.bottomLeft" min="0" max="500" step="1" @change="saveConfig" />
        </div>
        <div class="field-group">
          <label>Left &amp; right sides (cm each)</label>
          <input type="number" v-model.number="config.vertical" min="0" max="500" step="1" @change="saveConfig" />
        </div>
        <div class="field-group">
          <label>Top segment (cm)</label>
          <input type="number" v-model.number="config.top" min="1" max="500" step="1" @change="saveConfig" />
        </div>
        <div class="field-group">
          <label>Bottom-right segment (cm)</label>
          <input type="number" v-model.number="config.bottomRight" min="0" max="500" step="1" @change="saveConfig" />
        </div>
        <div class="field-group">
          <label>LED density (LEDs/m)</label>
          <select v-model.number="config.density" @change="saveConfig">
            <option :value="30">30 / m</option>
            <option :value="60">60 / m</option>
            <option :value="96">96 / m</option>
            <option :value="144">144 / m</option>
          </select>
        </div>
        <div class="field-group">
          <label>Wiring direction</label>
          <div class="radio-group">
            <label><input type="radio" v-model="config.clockwise" :value="true" @change="saveConfig" /> Clockwise</label>
            <label><input type="radio" v-model="config.clockwise" :value="false" @change="saveConfig" /> Counter-clockwise</label>
          </div>
        </div>

        <div class="led-count-info">
          Calculated: <strong>{{ computedLedCount }}</strong> LEDs
          <span v-if="ledCount && computedLedCount !== ledCount" class="mismatch">
            (server has {{ ledCount }})
          </span>
        </div>

        <button class="btn-reset" @click="resetLeds">Turn all off</button>
      </aside>

      <!-- Visualization -->
      <div class="visualization">
        <svg
          v-if="config.top > 0 && config.vertical > 0"
          :viewBox="`0 0 ${svgWidth} ${svgHeight}`"
          :style="{ width: '100%', maxWidth: svgWidth + 'px', overflow: 'visible' }"
          overflow="visible"
          xmlns="http://www.w3.org/2000/svg"
        >
          <defs>
            <filter id="glow" x="-300%" y="-300%" width="700%" height="700%">
              <feGaussianBlur stdDeviation="55" />
            </filter>
          </defs>

          <!-- LED glows — rendered FIRST so the TV bezel covers the inner half -->
          <template v-for="(pos, i) in ledPositions" :key="`glow-${i}`">
            <circle
              v-if="isLit(i)"
              :cx="pos.x"
              :cy="pos.y"
              r="120"
              :fill="`rgba(${ledColor(i).join(',')},0.6)`"
              filter="url(#glow)"
            />
          </template>

          <!-- TV bezel — on top of glows, clips glow on the inside -->
          <rect
            :x="TV_OFFSET - BEZEL"
            :y="TV_OFFSET - BEZEL"
            :width="displayW + 2 * BEZEL"
            :height="displayH + 2 * BEZEL"
            fill="#1c1c1c"
            rx="12"
          />

          <!-- TV screen -->
          <rect
            :x="TV_OFFSET"
            :y="TV_OFFSET"
            :width="displayW"
            :height="displayH"
            fill="#080808"
          />

          <!-- Screen glare line -->
          <line
            :x1="TV_OFFSET + 8"
            :y1="TV_OFFSET + 8"
            :x2="TV_OFFSET + displayW - 8"
            :y2="TV_OFFSET + 8"
            stroke="rgba(255,255,255,0.04)"
            stroke-width="2"
          />

          <!-- LED bodies — rendered last so they sit on top of the bezel -->
          <template v-for="(pos, i) in ledPositions" :key="`led-${i}`">
            <circle
              :cx="pos.x"
              :cy="pos.y"
              r="2.5"
              :fill="isLit(i) ? `rgb(${ledColor(i).join(',')})` : '#2a2a2a'"
            />
          </template>

          <!-- Connector indicator -->
          <rect
            v-if="connectorPos"
            :x="connectorPos.x - 6"
            :y="connectorPos.y - 3"
            width="12"
            height="6"
            fill="#555"
            rx="2"
          />
        </svg>
        <div v-else class="config-prompt">
          Configure strip dimensions to see the visualization.
        </div>
      </div>
    </main>

    <!-- Manual request panel -->
    <footer class="request-panel">
      <h2>Manual API Request → POST /json</h2>
      <div class="request-row">
        <textarea
          v-model="manualPayload"
          rows="4"
          spellcheck="false"
          placeholder='{"seg":[{"i":[255,0,0, 0,255,0, 0,0,255]}]}'
        ></textarea>
        <div class="request-actions">
          <button class="btn-send" @click="sendManual" :disabled="sending">
            {{ sending ? 'Sending…' : 'Send' }}
          </button>
          <button class="btn-secondary" @click="loadExample('rainbow')">Rainbow</button>
          <button class="btn-secondary" @click="loadExample('off')">Off</button>
          <button class="btn-secondary" @click="loadExample('white')">White</button>
        </div>
      </div>
      <pre v-if="lastResponse" class="response-preview">{{ lastResponse }}</pre>
    </footer>
  </div>
</template>

<script>
const STORAGE_KEY = 'wled-mock-config';
const DISPLAY_MAX_W = 700;
const TV_OFFSET = 160;
const BEZEL = 14;
const LED_OFFSET = 7; // mid-bezel — glows spread outward, TV covers the inner half

const DEFAULT_CONFIG = {
  bottomLeft: 50,
  vertical: 81,
  top: 144,
  bottomRight: 50,
  density: 60,
  clockwise: true,
};

export default {
  name: 'App',

  data() {
    return {
      config: { ...DEFAULT_CONFIG },
      leds: [],
      ledCount: null,
      online: false,
      sending: false,
      manualPayload: '',
      lastResponse: null,
      pollTimer: null,
      TV_OFFSET,
      BEZEL,
    };
  },

  computed: {
    computedLedCount() {
      const { bottomLeft, vertical, top, bottomRight, density } = this.config;
      const totalCm = bottomLeft + vertical + top + vertical + bottomRight;
      return Math.round(totalCm / 100 * density);
    },

    scale() {
      return DISPLAY_MAX_W / this.config.top;
    },

    displayW() {
      return DISPLAY_MAX_W;
    },

    displayH() {
      return Math.round(this.config.vertical * this.scale);
    },

    svgWidth() {
      return this.displayW + 2 * TV_OFFSET;
    },

    svgHeight() {
      return this.displayH + 2 * TV_OFFSET;
    },

    ledPositions() {
      const { bottomLeft, vertical, top, bottomRight, density, clockwise } = this.config;
      const cmPerLed = 100 / density;
      const totalCm = bottomLeft + vertical + top + vertical + bottomRight;
      const count = Math.round(totalCm / cmPerLed);
      const sc = this.scale;
      const W = this.displayW;
      const H = this.displayH;
      const O = TV_OFFSET;

      const positions = [];

      for (let i = 0; i < count; i++) {
        const cm = i * cmPerLed;
        let x, y;

        if (cm < bottomLeft) {
          // Bottom-left: from connector-side going toward left corner
          x = O + (bottomLeft - cm) * sc;
          y = O + H + LED_OFFSET;
        } else if (cm < bottomLeft + vertical) {
          // Left side: going up
          const p = cm - bottomLeft;
          x = O - LED_OFFSET;
          y = O + H - p * sc;
        } else if (cm < bottomLeft + vertical + top) {
          // Top: going right
          const p = cm - bottomLeft - vertical;
          x = O + p * sc;
          y = O - LED_OFFSET;
        } else if (cm < bottomLeft + 2 * vertical + top) {
          // Right side: going down
          const p = cm - bottomLeft - vertical - top;
          x = O + W + LED_OFFSET;
          y = O + p * sc;
        } else {
          // Bottom-right: from right corner toward connector-side
          const p = cm - bottomLeft - 2 * vertical - top;
          x = O + W - p * sc;
          y = O + H + LED_OFFSET;
        }

        positions.push({ x: Math.round(x), y: Math.round(y) });
      }

      if (!clockwise) {
        positions.reverse();
      }

      return positions;
    },

    connectorPos() {
      const { bottomLeft, top, clockwise } = this.config;
      const sc = this.scale;
      const O = TV_OFFSET;
      const H = this.displayH;
      const W = this.displayW;

      if (clockwise) {
        // Connector is at right end of BL segment
        return { x: O + bottomLeft * sc, y: O + H + LED_OFFSET };
      } else {
        // Connector is at right end of BR segment (i.e., left end of gap from right)
        return { x: O + W - this.config.bottomRight * sc, y: O + H + LED_OFFSET };
      }
    },
  },

  methods: {
    ledColor(i) {
      const led = this.leds[i];
      if (!led) return [0, 0, 0];
      return led;
    },

    isLit(i) {
      const c = this.ledColor(i);
      return c[0] > 0 || c[1] > 0 || c[2] > 0;
    },

    saveConfig() {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.config));
    },

    loadConfig() {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved) {
        try {
          this.config = { ...DEFAULT_CONFIG, ...JSON.parse(saved) };
        } catch {
          this.config = { ...DEFAULT_CONFIG };
        }
      }
    },

    async pollState() {
      try {
        const res = await fetch('/json/state');
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        this.leds = data.leds || [];
        this.online = true;
      } catch {
        this.online = false;
      }
    },

    async fetchInfo() {
      try {
        const res = await fetch('/json/info');
        if (!res.ok) return;
        const data = await res.json();
        this.ledCount = data.leds?.count ?? null;
      } catch {
        // ignore
      }
    },

    async sendManual() {
      if (!this.manualPayload.trim()) return;
      this.sending = true;
      this.lastResponse = null;
      try {
        const res = await fetch('/json', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: this.manualPayload,
        });
        const data = await res.json();
        this.lastResponse = JSON.stringify(data, null, 2);
        this.leds = data.leds || this.leds;
      } catch (e) {
        this.lastResponse = 'Error: ' + e.message;
      } finally {
        this.sending = false;
      }
    },

    async resetLeds() {
      try {
        const res = await fetch('/json/reset', { method: 'POST' });
        const data = await res.json();
        this.leds = data.leds || [];
      } catch (e) {
        console.error(e);
      }
    },

    loadExample(type) {
      const count = this.computedLedCount;
      if (type === 'off') {
        this.manualPayload = JSON.stringify({ on: false }, null, 2);
      } else if (type === 'white') {
        const flat = Array(count * 3).fill(255);
        this.manualPayload = JSON.stringify({ on: true, bri: 128, seg: [{ i: flat }] }, null, 2);
      } else if (type === 'rainbow') {
        const flat = [];
        for (let i = 0; i < count; i++) {
          const h = (i / count) * 360;
          const [r, g, b] = hsvToRgb(h, 1, 1);
          flat.push(r, g, b);
        }
        this.manualPayload = JSON.stringify({ on: true, bri: 200, seg: [{ i: flat }] }, null, 2);
      }
    },
  },

  mounted() {
    this.loadConfig();
    this.fetchInfo();
    this.pollState();
    this.pollTimer = setInterval(() => this.pollState(), 250);
  },

  beforeUnmount() {
    clearInterval(this.pollTimer);
  },
};

function hsvToRgb(h, s, v) {
  const c = v * s;
  const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
  const m = v - c;
  let r, g, b;
  if (h < 60) { r = c; g = x; b = 0; }
  else if (h < 120) { r = x; g = c; b = 0; }
  else if (h < 180) { r = 0; g = c; b = x; }
  else if (h < 240) { r = 0; g = x; b = c; }
  else if (h < 300) { r = x; g = 0; b = c; }
  else { r = c; g = 0; b = x; }
  return [Math.round((r + m) * 255), Math.round((g + m) * 255), Math.round((b + m) * 255)];
}
</script>

<style scoped>
.layout {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  background: #0d0d0d;
  color: #ddd;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 24px;
  background: #161616;
  border-bottom: 1px solid #2a2a2a;
}

.header h1 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: #fff;
}

.status {
  font-size: 0.8rem;
  padding: 3px 10px;
  border-radius: 12px;
}
.status.online { color: #4caf50; }
.status.offline { color: #f44336; }

.main {
  display: flex;
  flex: 1;
  gap: 0;
}

/* Config panel */
.config-panel {
  width: 240px;
  flex-shrink: 0;
  background: #161616;
  border-right: 1px solid #2a2a2a;
  padding: 20px 16px;
  overflow-y: auto;
}

.config-panel h2 {
  margin: 0 0 16px;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #888;
}

.field-group {
  margin-bottom: 14px;
}

.field-group label {
  display: block;
  font-size: 0.75rem;
  color: #999;
  margin-bottom: 4px;
}

.field-group input[type="number"],
.field-group select {
  width: 100%;
  background: #1e1e1e;
  border: 1px solid #333;
  color: #ddd;
  padding: 6px 8px;
  border-radius: 4px;
  font-size: 0.85rem;
}

.field-group input[type="number"]:focus,
.field-group select:focus {
  outline: none;
  border-color: #555;
}

.radio-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.radio-group label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.8rem;
  color: #bbb;
  margin: 0;
  cursor: pointer;
}

.led-count-info {
  margin: 16px 0;
  font-size: 0.8rem;
  color: #888;
  padding: 8px;
  background: #1a1a1a;
  border-radius: 4px;
}

.led-count-info strong {
  color: #ddd;
}

.mismatch {
  color: #f4a440;
  display: block;
  margin-top: 2px;
}

.btn-reset {
  width: 100%;
  padding: 7px;
  background: #2a2a2a;
  border: 1px solid #444;
  color: #bbb;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.8rem;
}

.btn-reset:hover {
  background: #333;
  color: #fff;
}

/* Visualization */
.visualization {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  overflow: visible;
}

.visualization svg {
  display: block;
}

.config-prompt {
  color: #555;
  font-size: 0.9rem;
}

/* Request panel */
.request-panel {
  background: #161616;
  border-top: 1px solid #2a2a2a;
  padding: 16px 24px;
}

.request-panel h2 {
  margin: 0 0 10px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #888;
}

.request-row {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}

.request-row textarea {
  flex: 1;
  background: #1a1a1a;
  border: 1px solid #333;
  color: #ddd;
  padding: 8px 10px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 0.8rem;
  resize: vertical;
}

.request-row textarea:focus {
  outline: none;
  border-color: #555;
}

.request-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.btn-send {
  padding: 7px 20px;
  background: #2a5caa;
  border: none;
  color: #fff;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.85rem;
  font-weight: 600;
}

.btn-send:hover:not(:disabled) { background: #3a6cba; }
.btn-send:disabled { opacity: 0.5; cursor: default; }

.btn-secondary {
  padding: 5px 12px;
  background: #2a2a2a;
  border: 1px solid #444;
  color: #bbb;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.78rem;
}

.btn-secondary:hover { background: #333; color: #fff; }

.response-preview {
  margin: 10px 0 0;
  padding: 8px 10px;
  background: #0f0f0f;
  border: 1px solid #2a2a2a;
  border-radius: 4px;
  font-size: 0.75rem;
  color: #aaa;
  max-height: 120px;
  overflow-y: auto;
  white-space: pre-wrap;
}
</style>
