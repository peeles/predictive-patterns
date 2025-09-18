<?php

namespace App\Http\Requests;

use App\Rules\BoundingBox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Validate aggregation requests coming from the frontend heatmap interfaces.
 */
class HexAggregationRequest extends FormRequest
{
    /**
     * The endpoints are public, so we do not gate access here.
     */
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
            'bbox' => ['required', 'string', new BoundingBox()],
            'resolution' => ['nullable', 'integer', Rule::in([6, 7, 8])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * Decode the comma-separated bounding box string into numeric coordinates.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function boundingBox(): array
    {
        $raw = preg_replace('/\s+/', '', (string) $this->input('bbox'));

        return array_map(static fn (string $value): float => (float) $value, explode(',', $raw));
    }

    /**
     * Requested H3 resolution defaults to 7 when omitted.
     */
    public function resolution(): int
    {
        return (int) ($this->input('resolution') ?? 7);
    }

    /**
     * Parse the optional lower temporal bound.
     */
    public function from(): ?CarbonImmutable
    {
        $value = $this->input('from');

        return $value ? CarbonImmutable::parse($value) : null;
    }

    /**
     * Parse the optional upper temporal bound.
     */
    public function to(): ?CarbonImmutable
    {
        $value = $this->input('to');

        return $value ? CarbonImmutable::parse($value) : null;
    }
}
