<?php

namespace App\Controller;

use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/admin/events/{eventId}/stream', methods: ['GET'])]
#[IsGranted('ROLE_BACKOFFICE')]
class AdminSeatStreamController extends AbstractController
{
    public function __construct(
        private readonly string $redisUrl,
    ) {
    }

    public function __invoke(string $eventId): StreamedResponse
    {
        $eventUuid = Uuid::fromString($eventId);

        return new StreamedResponse(function () use ($eventUuid) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            set_time_limit(0);
            ignore_user_abort(true);

            echo "event: connected\ndata: {\"eventId\":\"{$eventUuid}\"}\n\n";
            flush();

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
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }
}
