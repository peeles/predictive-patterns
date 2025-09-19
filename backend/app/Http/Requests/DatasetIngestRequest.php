<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Support\ResolvesRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DatasetIngestRequest extends FormRequest
{
    use ResolvesRoles;

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
}
