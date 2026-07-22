<?php

namespace App\Http\Requests;

use App\Rules\TestToolSchemaPayload;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'config' => ['required', new TestToolSchemaPayload('environment')],
        ];
    }
}
