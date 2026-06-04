# WLED Mock — Ambilight Visualizer

A development mock for the WLED HTTP API. Accepts ambilight LED colour data and visualises the result in the browser as a glow around a TV bezel.

## Requirements

- PHP 8.2+
- Composer (`composer install`)
- Node.js + npm (only needed to rebuild frontend assets)

## Running

Two processes must run simultaneously if you want to be able to use both websockets and the JSON API or the visual representation of the LED strip around a TV. Open two terminal windows:

**Terminal 1 — WebSocket server**

```bash
php bin/console app:websocket-server
```

Listens on `ws://0.0.0.0:8001` by default. Pass `--port=XXXX` to use a different port (also update `WS_PORT` in `assets/App.vue` and rebuild).

**Terminal 2 — HTTP server**

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000> in your browser.

## Configuration

Copy `.env` to `.env.local` and override as needed:

| Variable      | Default | Description                        |
|---------------|---------|------------------------------------|
| `LED_COUNT`   | `270`   | Number of LEDs in the strip        |

## API

The mock accepts a subset of the WLED JSON API on `http://localhost:8000`:

| Method | Path           | Description                        |
|--------|----------------|------------------------------------|
| GET    | `/json/info`   | Device info (LED count, firmware)  |
| GET    | `/json/state`  | Current LED state                  |
| POST   | `/json`        | Update state / set LED colours     |
| POST   | `/json/state`  | Same as above                      |
| POST   | `/json/reset`  | Turn all LEDs off                  |

## WebSocket

The WebSocket server at `ws://localhost:8001` implements WLED's live-update protocol. It is bidirectional.

### Server → client (push)

Whenever state changes (via HTTP API or another WebSocket client) all connected clients receive the new state within one poll tick (~40 ms). Send `{"v":true}` immediately after connecting to request the current state without waiting for the next change.

The push payload matches WLED's `/json/si` envelope:

```json
{
  "state": { "on": true, "bri": 255, "seg": [...], "leds": [[r,g,b], ...] },
  "info":  { "ver": "0.14.0-mock", "leds": { "count": 270 }, ... }
}
```

(`leds` inside `state` is a mock extension — real WLED serves per-pixel data via the live stream instead.)

### Client → server (commands)

Send any JSON that the [JSON API](#api) accepts. The connection is kept open indefinitely — send as many frames as needed without reconnecting.

#### Setting LED colours

Colours are carried in the `seg[0].i` field. Two formats are supported:

**Flat format** — a single array of `R, G, B` byte triplets, applied sequentially from LED 0. This is the most efficient format for full-strip updates:

```js
const ws = new WebSocket('ws://localhost:8001');

// Build a flat [R, G, B, R, G, B, ...] array for all LEDs
const colours = [];
for (const [r, g, b] of myLedColours) {
  colours.push(r, g, b);
}

ws.send(JSON.stringify({ seg: [{ i: colours }] }));
```

**Indexed format** — interleaved LED index and `[R, G, B]` tuple pairs. Useful for sparse updates where only some LEDs change:

```js
// Set LED 0 to red, LED 5 to green, LED 12 to blue
ws.send(JSON.stringify({
  seg: [{ i: [0, [255, 0, 0],  5, [0, 255, 0],  12, [0, 0, 255]] }]
}));
```

#### Other commands

```js
// Adjust brightness (0–255) or toggle power
ws.send(JSON.stringify({ on: true, bri: 200 }));

// Combine colour and brightness in one message
ws.send(JSON.stringify({ bri: 180, seg: [{ i: colours }] }));

// Request an immediate full state push (WLED {"v":true} command)
ws.send(JSON.stringify({ v: true }));
```

## Frontend assets

The built assets are committed. To rebuild after changing files under `assets/`:

```bash
npm install
npm run build
```
