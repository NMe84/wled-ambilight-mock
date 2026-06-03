# WLED Mock — Ambilight Visualizer

A development mock for the WLED HTTP API. Accepts ambilight LED colour data and visualises the result in the browser as a glow around a TV bezel.

## Requirements

- PHP 8.2+
- Composer (`composer install`)
- Node.js + npm (only needed to rebuild frontend assets)

## Running

Two processes must run simultaneously. Open two terminal windows:

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

On connect the server immediately pushes the current LED state. Subsequently, whenever state changes (via HTTP API or another WebSocket client) all connected clients receive the new state within one poll tick (~40 ms).

The push payload matches WLED's `/json/si` envelope:

```json
{
  "state": { "on": true, "bri": 255, "seg": [...], "leds": [[r,g,b], ...] },
  "info":  { "ver": "0.14.0-mock", "leds": { "count": 270 }, ... }
}
```

(`leds` inside `state` is a mock extension — real WLED serves per-pixel data via the live stream instead.)

### Client → server (commands)

Send any JSON that the [JSON API](#api) accepts. State updates and colour commands both work:

```js
const ws = new WebSocket('ws://localhost:8001');

// Set colours (flat format: sequential R,G,B values from LED 0)
ws.send(JSON.stringify({ seg: [{ i: [255, 0, 0,  0, 255, 0,  0, 0, 255] }] }));

// Set colours (indexed format: [index, [r,g,b], index, [r,g,b], ...])
ws.send(JSON.stringify({ seg: [{ i: [0, [255, 0, 0], 5, [0, 255, 0]] }] }));

// Adjust brightness or power
ws.send(JSON.stringify({ on: true, bri: 200 }));

// Request a full state push (WLED {"v":true} command)
ws.send(JSON.stringify({ v: true }));
```

## Frontend assets

The built assets are committed. To rebuild after changing files under `assets/`:

```bash
npm install
npm run build
```
