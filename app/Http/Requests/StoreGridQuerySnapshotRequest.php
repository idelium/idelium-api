<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGridQuerySnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resourceType' => ['required', Rule::in(['projects'])],
            'query' => ['nullable', 'array'],
            'query.q' => ['nullable', 'string', 'max:200'],
            'query.search' => ['nullable', 'string', 'max:200'],
            'query.sort' => ['nullable', Rule::in(['id', 'name', 'description', 'created_at', 'updated_at'])],
            'query.direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'query.f' => ['nullable', 'array'],
            'query.f.id' => ['nullable', 'integer', 'min:1'],
            'query.f.name' => ['nullable', 'string', 'max:200'],
        ];
    }
}
