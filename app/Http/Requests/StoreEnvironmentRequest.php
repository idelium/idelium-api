<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOwnedProject;
use App\Rules\TestToolSchemaPayload;
use Illuminate\Foundation\Http\FormRequest;

class StoreEnvironmentRequest extends FormRequest
{
    use ValidatesOwnedProject;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'config' => ['required', new TestToolSchemaPayload('environment')],
            'idProject' => $this->ownedProjectRules(),
        ];
    }
}
