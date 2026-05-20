<?php

declare(strict_types=1);

namespace App\Notification\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CreateNotificationBatchApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => trim((string) $this->header('Idempotency-Key', '')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'channel' => ['required', 'string', Rule::in(['sms', 'email'])],
            'traffic_type' => ['required', 'string', Rule::in(['transactional', 'marketing'])],
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'recipient_ids.*' => ['required', 'string', 'distinct', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'idempotency_key.required' => 'Idempotency-Key header is required.',
            'idempotency_key.max' => 'Idempotency-Key header must be 255 characters or less and contain only letters, digits, dots, underscores, colons, or hyphens.',
            'idempotency_key.regex' => 'Idempotency-Key header must be 255 characters or less and contain only letters, digits, dots, underscores, colons, or hyphens.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'idempotency_key' => 'Idempotency-Key header',
        ];
    }

    public function idempotencyKey(): string
    {
        return Str::of((string) $this->validated('idempotency_key'))->trim()->toString();
    }
}
