<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Rules\ValidGeoJson;
use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use function pathinfo;
use const PATHINFO_FILENAME;
use JsonException;

class DatasetIngestRequest extends FormRequest
{
    use ResolvesRoles;

    protected function prepareForValidation(): void
    {
        $this->decodeJsonArrayInput('metadata');
        $this->decodeJsonArrayInput('schema');

        if (! $this->filled('name')) {
            $file = $this->file('file');

            if ($file !== null) {
                $originalName = $file->getClientOriginalName() ?? '';
                $inferredName = (string) pathinfo($originalName, PATHINFO_FILENAME);

                if ($inferredName === '' && $originalName !== '') {
                    $inferredName = $originalName;
                }

                if ($inferredName !== '') {
                    $this->merge(['name' => substr($inferredName, 0, 255)]);
                }
            }
        }

        if (! $this->filled('source_type') && $this->file('file') !== null) {
            $this->merge(['source_type' => 'file']);
        }
    }

    public function authorize(): bool
    {
        $role = $this->resolveRole($this->user());

        return in_array($role, [Role::Admin, Role::Analyst], true);
    }

    /**
     * @return array<string, array<int, string|Rule>>
     */
    public function rules(): array
    {
        $maxKb = max((int) config('api.payload_limits.ingest', 20_480), 1);
        $mimeRules = config('api.allowed_ingest_mimes', []);
        $fileRules = array_filter([
            'required_if:source_type,file',
            'file',
            'max:' . $maxKb,
            $mimeRules !== [] ? 'mimetypes:' . implode(',', $mimeRules) : null,
            new ValidGeoJson(),
        ]);

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'source_type' => ['required', Rule::in(['file', 'url'])],
            'file' => $fileRules,
            'source_uri' => ['required_if:source_type,url', 'url'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function decodeJsonArrayInput(string $key): void
    {
        $value = $this->input($key);

        if (! is_string($value) || trim($value) === '') {
            return;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (is_array($decoded)) {
            $this->merge([$key => $decoded]);
        }
    }
}
