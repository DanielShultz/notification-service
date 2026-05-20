#!/usr/bin/env sh

set -eu

API_URL="${API_URL:-http://127.0.0.1:8013}"
PROJECT_DIR="${PROJECT_DIR:-$(pwd)}"
CLEANUP_AFTER="${CLEANUP_AFTER:-1}"
RUN_ID="$(date +%s)"

cd "$PROJECT_DIR"

purge_queue() {
    docker compose exec -T rabbitmq rabbitmqctl purge_queue "$1" >/dev/null
}

sql() {
    docker compose exec -T postgres psql -U notification_service -d notification_service -t -A -c "$1"
}

post_batch() {
    idempotency_key="$1"
    message="$2"
    recipient_id="$3"

    payload="{\"channel\":\"sms\",\"traffic_type\":\"transactional\",\"message\":\"$message\",\"recipient_ids\":[\"$recipient_id\"]}"

    curl -sS \
        -X POST "$API_URL/api/v1/notification-batches" \
        -H "Accept: application/json" \
        -H "Content-Type: application/json" \
        -H "Idempotency-Key: $idempotency_key" \
        -d "$payload"
}

extract_batch_id() {
    sed -n 's/.*"batch_id":"\([^"]*\)".*/\1/p'
}

wait_for_status() {
    idempotency_key="$1"
    expected_status="$2"

    attempt=1
    while [ "$attempt" -le 20 ]; do
        status="$(sql "select n.status from notifications n join notification_batches b on b.id = n.batch_id where b.idempotency_key = '$idempotency_key' limit 1;")"

        if [ "$status" = "$expected_status" ]; then
            return 0
        fi

        sleep 1
        attempt=$((attempt + 1))
    done

    echo "Expected status '$expected_status' for '$idempotency_key', got '$status'" >&2
    return 1
}

queue_messages() {
    queue_name="$1"

    docker compose exec -T rabbitmq rabbitmqctl list_queues name messages \
        | awk -v queue="$queue_name" '$1 == queue { print $2 }'
}

wait_for_queue_messages() {
    queue_name="$1"
    minimum_messages="$2"

    attempt=1
    while [ "$attempt" -le 20 ]; do
        messages="$(queue_messages "$queue_name")"

        if [ "${messages:-0}" -ge "$minimum_messages" ]; then
            return 0
        fi

        sleep 1
        attempt=$((attempt + 1))
    done

    echo "Expected at least $minimum_messages messages in '$queue_name', got ${messages:-0}" >&2
    return 1
}

purge_queue notifications.high
purge_queue notifications.default
purge_queue notifications.retry
purge_queue notifications.dlq

success_key="smoke-success-$RUN_ID"
success_recipient="smoke-success-recipient-$RUN_ID"
success_response="$(post_batch "$success_key" "Smoke success $RUN_ID" "$success_recipient")"
success_batch_id="$(printf "%s" "$success_response" | extract_batch_id)"

if [ -z "$success_batch_id" ]; then
    echo "Could not create success batch: $success_response" >&2
    exit 1
fi

wait_for_status "$success_key" delivered

success_history_count="$(sql "select count(*) from notification_status_history h join notifications n on n.id = h.notification_id join notification_batches b on b.id = n.batch_id where b.idempotency_key = '$success_key' and h.status in ('queued', 'sent', 'delivered');")"

if [ "$success_history_count" -ne 3 ]; then
    echo "Expected queued/sent/delivered history for success batch, got $success_history_count records" >&2
    exit 1
fi

retry_key="smoke-retry-$RUN_ID"
retry_recipient="smoke-retry-recipient-$RUN_ID"
retry_response="$(post_batch "$retry_key" "[temporary-failure] Smoke retry $RUN_ID" "$retry_recipient")"
retry_batch_id="$(printf "%s" "$retry_response" | extract_batch_id)"

if [ -z "$retry_batch_id" ]; then
    echo "Could not create retry batch: $retry_response" >&2
    exit 1
fi

wait_for_status "$retry_key" retry_scheduled
wait_for_queue_messages notifications.retry 1

dlq_key="smoke-dlq-$RUN_ID"
dlq_recipient="smoke-dlq-recipient-$RUN_ID"
dlq_response="$(post_batch "$dlq_key" "[permanent-failure] Smoke DLQ $RUN_ID" "$dlq_recipient")"
dlq_batch_id="$(printf "%s" "$dlq_response" | extract_batch_id)"

if [ -z "$dlq_batch_id" ]; then
    echo "Could not create DLQ batch: $dlq_response" >&2
    exit 1
fi

wait_for_status "$dlq_key" dropped

dlq_messages="$(queue_messages notifications.dlq)"

if [ "${dlq_messages:-0}" -lt 1 ]; then
    echo "Expected at least one message in notifications.dlq, got ${dlq_messages:-0}" >&2
    exit 1
fi

if [ "$CLEANUP_AFTER" = "1" ]; then
    purge_queue notifications.high
    purge_queue notifications.default
    purge_queue notifications.retry
    purge_queue notifications.dlq
fi

echo "Full chain smoke passed"
echo "Success batch: $success_batch_id"
echo "Retry batch: $retry_batch_id"
echo "DLQ batch: $dlq_batch_id"
