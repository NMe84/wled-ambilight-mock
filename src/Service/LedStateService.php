<?php

namespace App\Service;

class LedStateService
{
    private string $stateFile;

    public function __construct(
        private readonly int $ledCount,
        private readonly string $varDir,
    ) {
        $this->stateFile = $varDir . '/led_state.json';
    }

    public function getStateFilePath(): string
    {
        return $this->stateFile;
    }

    public function getState(): array
    {
        $state = $this->readState();

        // Rebuild LED array if count changed
        if (count($state['leds']) !== $this->ledCount) {
            $state['leds'] = array_fill(0, $this->ledCount, [0, 0, 0]);
            $this->writeState($state);
        }

        return $state;
    }

    public function applyUpdate(array $data): array
    {
        $state = $this->getState();

        if (array_key_exists('on', $data)) {
            $state['on'] = (bool) $data['on'];
        }

        if (array_key_exists('bri', $data)) {
            $state['bri'] = max(0, min(255, (int) $data['bri']));
        }

        if (!empty($data['seg']) && is_array($data['seg'])) {
            if (!array_key_exists('on', $data)) {
                $state['on'] = true;
            }
            foreach ($data['seg'] as $seg) {
                if (!isset($seg['i']) || !is_array($seg['i'])) {
                    continue;
                }
                $this->applySegmentColors($state['leds'], $seg['i']);
            }
        }

        $this->writeState($state);

        return $state;
    }

    private function applySegmentColors(array &$leds, array $colorData): void
    {
        // Detect format: flat [r,g,b,r,g,b,...] vs indexed [idx,[r,g,b],...]
        $isFlat = true;
        foreach ($colorData as $val) {
            if (is_array($val)) {
                $isFlat = false;
                break;
            }
        }

        if ($isFlat) {
            // Flat format: r,g,b values for LEDs starting at index 0
            $ledIndex = 0;
            for ($i = 0; $i + 2 < count($colorData); $i += 3) {
                if ($ledIndex >= count($leds)) {
                    break;
                }
                $leds[$ledIndex] = [
                    max(0, min(255, (int) $colorData[$i])),
                    max(0, min(255, (int) $colorData[$i + 1])),
                    max(0, min(255, (int) $colorData[$i + 2])),
                ];
                $ledIndex++;
            }
            return;
        }

        // Indexed format: pixel_index, [r,g,b], pixel_index, [r,g,b], ...
        $i = 0;
        while ($i < count($colorData) - 1) {
            $ledIndex = (int) $colorData[$i];
            $color = $colorData[$i + 1];
            if (is_array($color) && $ledIndex < count($leds)) {
                $leds[$ledIndex] = [
                    max(0, min(255, (int) ($color[0] ?? 0))),
                    max(0, min(255, (int) ($color[1] ?? 0))),
                    max(0, min(255, (int) ($color[2] ?? 0))),
                ];
            }
            $i += 2;
        }
    }

    public function reset(): array
    {
        $state = [
            'on' => true,
            'bri' => 255,
            'leds' => array_fill(0, $this->ledCount, [0, 0, 0]),
        ];
        $this->writeState($state);

        return $state;
    }

    private function readState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [
                'on' => true,
                'bri' => 255,
                'leds' => array_fill(0, $this->ledCount, [0, 0, 0]),
            ];
        }

        $json = file_get_contents($this->stateFile);
        $state = json_decode($json, true);

        if (!is_array($state) || !isset($state['leds'])) {
            return [
                'on' => true,
                'bri' => 255,
                'leds' => array_fill(0, $this->ledCount, [0, 0, 0]),
            ];
        }

        return $state;
    }

    private function writeState(array $state): void
    {
        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0777, true);
        }
        file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }
}
