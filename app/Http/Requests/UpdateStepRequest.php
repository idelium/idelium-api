<?php

namespace App\Http\Requests;

use App\Rules\TestToolSchemaPayload;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'config' => ['required', new TestToolSchemaPayload('step')],
        ];
    }
}
