<?php

declare(strict_types=1);

use App\Notification\Presentation\Http\Controller\CreateNotificationBatchController;
use App\Notification\Presentation\Http\Controller\GetNotificationBatchStatusController;
use App\Notification\Presentation\Http\Controller\GetNotificationHistoryController;
use App\Notification\Presentation\Http\Controller\GetRecipientNotificationsController;
use Illuminate\Support\Facades\Route;

Route::get("/v1/health", static fn (): array => [
    "status" => "ok",
    "service" => "notification-service",
]);

Route::post("/v1/notification-batches", CreateNotificationBatchController::class);
Route::get("/v1/notification-batches/{batchId}", GetNotificationBatchStatusController::class);
Route::get("/v1/notifications/{notificationId}/history", GetNotificationHistoryController::class);
Route::get("/v1/recipients/{recipientId}/notifications", GetRecipientNotificationsController::class);
