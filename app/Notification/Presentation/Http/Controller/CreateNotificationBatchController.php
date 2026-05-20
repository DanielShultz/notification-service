<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Controller;

use App\Http\Controllers\Controller;
use App\Notification\Application\DTO\CreateNotificationBatchRequest;
use App\Notification\Application\Exception\IdempotencyKeyReserved;
use App\Notification\Application\UseCase\CreateNotificationBatch;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationTrafficType;
use App\Notification\Presentation\Http\Request\CreateNotificationBatchApiRequest;
use Illuminate\Http\JsonResponse;

final class CreateNotificationBatchController extends Controller
{
    public function __invoke(
        CreateNotificationBatchApiRequest $request,
        CreateNotificationBatch $useCase,
    ): JsonResponse {
        $idempotencyKey = $request->idempotencyKey();

        try {
            $response = $useCase->execute(new CreateNotificationBatchRequest(
                NotificationChannel::from((string) $request->validated('channel')),
                NotificationTrafficType::from((string) $request->validated('traffic_type')),
                (string) $request->validated('message'),
                $request->validated('recipient_ids'),
                $idempotencyKey,
            ));
        } catch (IdempotencyKeyReserved) {
            return response()->json([
                'message' => 'Request with this Idempotency-Key is already being processed.',
            ], 409);
        }

        return response()->json([
            'batch_id' => $response->batchId,
            'notification_count' => $response->notificationCount,
            'duplicate' => $response->duplicate,
        ], $response->duplicate ? 200 : 202);
    }
}
