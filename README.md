# Notification Service

Микросервис массовой отправки SMS и Email уведомлений.

Проект реализован как тестовое задание на backend-разработчика: асинхронная обработка через RabbitMQ, приоритезация transactional-трафика, retry/DLQ, идемпотентность через Redis, хранение статусов в PostgreSQL и автоматизированные проверки основных сценариев.

## Возможности

- Массовое создание уведомлений через HTTP API.
- Каналы доставки: `sms`, `email`.
- Типы трафика: `transactional`, `marketing`.
- Приоритетная обработка: transactional-сообщения читаются из `notifications.high` раньше marketing-сообщений из `notifications.default`.
- Статусы доставки: `queued`, `processing`, `sent`, `delivered`, `retry_scheduled`, `dropped`.
- История изменения статусов по каждому уведомлению.
- API истории всех уведомлений конкретного получателя.
- Идемпотентность создания batch через обязательный заголовок `Idempotency-Key`.
- Retry при временной ошибке провайдера.
- DLQ при постоянной ошибке или исчерпании попыток.
- Защита от повторной бизнес-отправки при at-least-once доставке RabbitMQ.
- Mock provider вместо реальных SMS/Email шлюзов.
- OpenAPI и Swagger UI для проверки API.

## Стек

- PHP 8.4.
- Laravel 13.
- PostgreSQL 15.
- RabbitMQ 3.13 Management.
- Redis 7.
- Docker Compose.
- Swagger UI.

## Архитектура

Код основной предметной области лежит в `app/Notification` и разделен на слои:

- `Presentation` — HTTP controllers, Form Requests, console entrypoints.
- `Application` — use cases, DTO, ports.
- `Domain` — enum'ы и бизнес-термины.
- `Infrastructure` — PostgreSQL repositories, RabbitMQ, Redis, mock provider.

Контроллеры тонкие: валидируют вход, вызывают use case и формируют JSON. Use case'ы работают через интерфейсы портов, поэтому бизнес-логика не зависит напрямую от Eloquent, RabbitMQ или Redis.

PostgreSQL является источником истины для текущего статуса и истории. RabbitMQ используется только как транспорт задач доставки.

## Запуск

Проект запускается одной командой Docker Compose.

```bash
docker compose up -d --build
```

Если используется старый Docker Compose v1, команда аналогичная:

```bash
docker-compose up -d --build
```

Контейнер `app` автоматически выполняет `php artisan migrate --force` перед запуском HTTP-сервера. `worker` стартует только после успешного healthcheck приложения.

## Адреса

- API: `http://localhost:8013`
- Healthcheck: `http://localhost:8013/api/v1/health`
- Swagger UI: `http://localhost:8014`
- OpenAPI YAML: `http://localhost:8014/openapi.yaml`
- RabbitMQ Management: `http://localhost:15673`
- RabbitMQ user: `notification_service`
- RabbitMQ password: `notification_service`

## API

Полное описание API доступно в Swagger UI:

```text
http://localhost:8014
```

Основные endpoints:

- `POST /api/v1/notification-batches` — создать batch уведомлений.
- `GET /api/v1/notification-batches/{batchId}` — получить состояние batch.
- `GET /api/v1/notifications/{notificationId}/history` — получить историю одного уведомления.
- `GET /api/v1/recipients/{recipientId}/notifications` — получить историю уведомлений получателя.
- `GET /api/v1/health` — проверить доступность сервиса.

## Пример создания batch

```bash
curl -X POST http://localhost:8013/api/v1/notification-batches \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: demo-001" \
  -d '{
    "channel": "sms",
    "traffic_type": "transactional",
    "message": "Access code: 1234",
    "recipient_ids": ["user-100", "user-101"]
  }'
```

Повторный запрос с тем же `Idempotency-Key` вернет уже созданный `batch_id` и не создаст повторные уведомления.

## Проверка статусов

Статус batch:

```bash
curl http://localhost:8013/api/v1/notification-batches/{batch_id}
```

История одного уведомления:

```bash
curl http://localhost:8013/api/v1/notifications/{notification_id}/history
```

История уведомлений получателя:

```bash
curl http://localhost:8013/api/v1/recipients/{recipient_id}/notifications
```

## Очереди RabbitMQ

Используется четыре очереди:

- `notifications.high` — transactional-уведомления.
- `notifications.default` — marketing-уведомления.
- `notifications.retry` — отложенные повторные попытки после временных ошибок.
- `notifications.dlq` — сообщения, которые нельзя обработать автоматически.

Worker сначала читает `notifications.high`, затем `notifications.default`. Это дает transactional-сообщениям приоритет над marketing-рассылками.

Payload сообщения минимальный:

```json
{
  "notification_id": "uuid",
  "attempt": 1,
  "trace_id": "uuid",
  "traffic_type": "transactional"
}
```

Retry сохраняет исходный `traffic_type`: после задержки transactional-сообщения возвращаются в `notifications.high`, marketing-сообщения — в `notifications.default`.

## Retry и DLQ

Внешние SMS/Email шлюзы заменены mock provider'ом.

Поведение mock provider:

- Обычный текст сообщения приводит к успешной доставке: `queued -> processing -> sent -> delivered`.
- Текст с `[temporary-failure]` имитирует временную ошибку и отправляет сообщение в retry.
- Текст с `[permanent-failure]` имитирует постоянную ошибку и переводит уведомление в `dropped`, а сообщение отправляет в DLQ.

Если временная ошибка повторяется до лимита `NOTIFICATION_MAX_ATTEMPTS`, уведомление также переводится в `dropped` и сообщение уходит в DLQ.

## At-Least-Once и идемпотентность

RabbitMQ дает at-least-once доставку, поэтому одно сообщение может быть получено повторно. На уровне бизнес-логики worker проверяет текущий статус уведомления:

- если статус уже `delivered` или `dropped`, provider повторно не вызывается;
- если статус уже `processing`, значит другое выполнение worker забрало уведомление, поэтому provider повторно не вызывается;
- если статус `sent`, worker считает, что provider уже успешно ответил, и завершает запись статуса как `delivered`;
- если статус `queued` или `retry_scheduled`, worker атомарно переводит уведомление в `processing`, выполняет отправку и пишет переходы статуса в PostgreSQL. Статус `sent` выставляется только после успешного ответа provider'а.

Создание batch защищено отдельно через `Idempotency-Key` и Redis. Повтор одного и того же HTTP-запроса не создает новые уведомления.

## Тестирование

Unit и feature-тесты:

```bash
docker compose run --rm test
```

Проверяются:

- создание batch;
- обязательность `Idempotency-Key`;
- дедубликация повторного запроса;
- API чтения batch, уведомления и истории получателя;
- сериализация сообщений RabbitMQ;
- success/retry/DLQ сценарии use case доставки;
- защита от повторной отправки уже финализированного уведомления;
- маршрутизация transactional-трафика в high-priority очередь.

Интеграционный smoke полной цепочки:

```bash
sh scripts/smoke_full_chain.sh
```

Smoke проверяет реальный путь:

```text
HTTP API -> PostgreSQL -> RabbitMQ -> worker -> mock provider -> PostgreSQL
```

Скрипт создает успешный batch и дожидается `delivered`, затем создает batch с `[temporary-failure]`, дожидается `retry_scheduled` и проверяет `notifications.retry`. После этого создает batch с `[permanent-failure]`, дожидается `dropped` и проверяет DLQ. В начале smoke очищает очереди, а после успешного прогона по умолчанию очищает retry/DLQ-сообщения, чтобы не засорять локальное окружение.

Если нужно оставить retry/DLQ-сообщения для ручной проверки:

```bash
CLEANUP_AFTER=0 sh scripts/smoke_full_chain.sh
```

## Полезные команды

Логи приложения и worker:

```bash
docker compose logs -f app worker
```

Однократная обработка одного сообщения:

```bash
docker compose exec app php artisan notifications:consume --once
```

Повторная публикация зависших `queued` уведомлений, если batch был сохранен в PostgreSQL, но публикация в RabbitMQ оборвалась:

```bash
docker compose exec app php artisan notifications:recover-queued
```

Recovery по умолчанию берет только `queued` уведомления старше `NOTIFICATION_RECOVERY_MIN_AGE_SECONDS`, чтобы не дублировать свежие сообщения, которые уже лежат в RabbitMQ и ждут worker.

Состояние очередей:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages
```

Остановка проекта:

```bash
docker compose down
```
