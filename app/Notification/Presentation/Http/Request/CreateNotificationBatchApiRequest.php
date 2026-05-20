<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateNotificationBatchApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            "channel" => ["required", "string", Rule::in(["sms", "email"])],
            "traffic_type" => ["required", "string", Rule::in(["transactional", "marketing"])],
            "message" => ["required", "string", "min:1", "max:2000"],
            "recipient_ids" => ["required", "array", "min:1", "max:1000"],
            "recipient_ids.*" => ["required", "string", "distinct", "max:128"],
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string)$this->header("Idempotency-Key", ""));
    }
}
