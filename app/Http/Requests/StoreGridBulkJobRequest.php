<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGridBulkJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'querySnapshotId' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['archive', 'export', 'tag'])],
            'payload' => ['nullable', 'array'],
            'payload.tags' => ['required_if:action,tag', 'array', 'min:1', 'max:10'],
            'payload.tags.*' => ['string', 'max:40', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
        ];
    }
}
