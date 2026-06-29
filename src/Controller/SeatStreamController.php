<?php

namespace App\Controller;

use App\Repository\EventSeatRepository;
use App\Service\SessionTokenService;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Endpoint SSE pour les mises à jour de statut des sièges en temps réel.
 * Accessible sans auth Symfony (token validé manuellement via ?token=).
 * Le client EventSource ne supporte pas les headers personnalisés.
 */
#[Route('/api/widget/events/{eventId}/stream', methods: ['GET'])]
class SeatStreamController extends AbstractController
{
    public function __construct(
        private SessionTokenService $sessionTokenService,
        private EventSeatRepository $eventSeatRepository,
        private string $redisUrl,
    ) {
    }

    public function __invoke(string $eventId, Request $request): StreamedResponse
    {
        $token = $request->query->get('token', '');

        try {
            $claims = $this->sessionTokenService->decode($token);
        } catch (\Exception) {
            return new StreamedResponse(function () {
                echo "event: error\ndata: {\"message\":\"Invalid or expired token\"}\n\n";
                flush();
            }, 401, $this->sseHeaders());
        }

        if (($claims['event_id'] ?? '') !== $eventId) {
            return new StreamedResponse(function () {
                echo "event: error\ndata: {\"message\":\"Token not valid for this event\"}\n\n";
                flush();
            }, 403, $this->sseHeaders());
        }

        $eventUuid = Uuid::fromString($eventId);

        return new StreamedResponse(function () use ($eventUuid) {
            // Désactiver le buffering PHP pour le streaming
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            set_time_limit(0);
            ignore_user_abort(true);

            // Snapshot initial
            $seats = $this->eventSeatRepository->findByEventId($eventUuid);
            $initial = array_map(fn($s) => [
                'seatKey' => $s->getSeatKey(),
                'status'  => $s->getStatus()->value,
            ], $seats);

            echo "event: snapshot\ndata: " . json_encode($initial) . "\n\n";
            flush();

            // Abonnement Redis pub/sub (connexion dédiée — Predis 3.x API)
            $subscriber = new Client($this->redisUrl);
            $channel    = "seats:{$eventUuid}";

            $pubsub = $subscriber->pubSubLoop();
            $pubsub->subscribe($channel);

            foreach ($pubsub as $message) {
                if (connection_aborted()) {
                    $pubsub->unsubscribe($channel);
                    break;
                }

                if ($message->kind === 'message') {
                    echo "event: update\ndata: {$message->payload}\n\n";
                    flush();
                }
            }
        }, 200, $this->sseHeaders());
    }

    private function sseHeaders(): array
    {
        return [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ];
    }
}
