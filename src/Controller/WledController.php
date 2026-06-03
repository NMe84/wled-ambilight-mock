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
        return new JsonResponse($this->ledState->buildInfoResponse());
    }

    #[Route('/json/state', name: 'wled_state_get', methods: ['GET'])]
    public function getState(): JsonResponse
    {
        $state = $this->ledState->getState();

        return new JsonResponse($this->ledState->buildStateResponse($state));
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

        return new JsonResponse($this->ledState->buildStateResponse($state));
    }

    #[Route('/json/reset', name: 'wled_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        $state = $this->ledState->reset();

        return new JsonResponse($this->ledState->buildStateResponse($state));
    }
}
