<?php

namespace App\Controller;

use App\Service\LedStateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WledController extends AbstractController
{
    public function __construct(
        private readonly LedStateService $ledState,
    ) {
    }

    #[Route('/', name: 'frontend')]
    public function index(): Response
    {
        return $this->render('index.html.twig');
    }

    #[Route('/json/info', name: 'wled_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        $ledCount = count($this->ledState->getState()['leds']);

        return new JsonResponse([
            'ver' => '0.14.0-mock',
            'vid' => 1000000,
            'leds' => [
                'count' => $ledCount,
                'rgbw' => false,
                'wv' => false,
                'fps' => 60,
                'pwr' => 0,
                'maxpwr' => 5500,
                'maxseg' => 32,
            ],
            'name' => 'WLED Mock',
            'udpport' => 21324,
            'live' => false,
            'ws' => -1,
            'fxcount' => 117,
            'palcount' => 70,
            'arch' => 'mock',
            'brand' => 'WLED',
            'product' => 'Mock',
            'mac' => '00:00:00:00:00:00',
            'ip' => '127.0.0.1',
        ]);
    }

    #[Route('/json/state', name: 'wled_state_get', methods: ['GET'])]
    public function getState(): JsonResponse
    {
        $state = $this->ledState->getState();

        return new JsonResponse($this->buildStateResponse($state));
    }

    #[Route('/json', name: 'wled_json_post', methods: ['POST'])]
    #[Route('/json/state', name: 'wled_state_post', methods: ['POST'])]
    public function postState(Request $request): JsonResponse
    {
        set_time_limit(1);

        $body = $request->getContent();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $state = $this->ledState->applyUpdate($data);

        return new JsonResponse($this->buildStateResponse($state));
    }

    #[Route('/json/reset', name: 'wled_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        $state = $this->ledState->reset();

        return new JsonResponse($this->buildStateResponse($state));
    }

    private function buildStateResponse(array $state): array
    {
        $ledCount = count($state['leds']);

        return [
            'on' => $state['on'],
            'bri' => $state['bri'],
            'transition' => 0,
            'mainseg' => 0,
            'seg' => [[
                'id' => 0,
                'start' => 0,
                'stop' => $ledCount,
                'len' => $ledCount,
                'on' => $state['on'],
                'bri' => $state['bri'],
                'col' => $state['leds'][0] ?? [0, 0, 0],
                'fx' => 0,
                'sel' => true,
                'rev' => false,
            ]],
            'leds' => $state['leds'],
        ];
    }
}
