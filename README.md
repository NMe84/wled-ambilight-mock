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

## Frontend assets

The built assets are committed. To rebuild after changing files under `assets/`:

```bash
npm install
npm run build
```
