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

        if ($idempotencyKey === "") {
            return response()->json([
                "message" => "Idempotency-Key header is required.",
            ], 422);
        }

        if (strlen($idempotencyKey) > 255 || preg_match('/\A[A-Za-z0-9._:-]+\z/', $idempotencyKey) !== 1) {
            return response()->json([
                "message" => "Idempotency-Key header must be 255 characters or less and contain only letters, digits, dots, underscores, colons, or hyphens.",
            ], 422);
        }

        try {
            $response = $useCase->execute(new CreateNotificationBatchRequest(
                NotificationChannel::from((string)$request->validated("channel")),
                NotificationTrafficType::from((string)$request->validated("traffic_type")),
                (string)$request->validated("message"),
                $request->validated("recipient_ids"),
                $idempotencyKey,
            ));
        } catch (IdempotencyKeyReserved) {
            return response()->json([
                "message" => "Request with this Idempotency-Key is already being processed.",
            ], 409);
        }

        return response()->json([
            "batch_id" => $response->batchId,
            "notification_count" => $response->notificationCount,
            "duplicate" => $response->duplicate,
        ], $response->duplicate ? 200 : 202);
    }
}
