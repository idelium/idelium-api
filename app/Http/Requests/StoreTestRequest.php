<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOwnedProject;
use Illuminate\Foundation\Http\FormRequest;

class StoreTestRequest extends FormRequest
{
    use ValidatesOwnedProject;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'config' => ['required'],
            'idProject' => $this->ownedProjectRules(),
        ];
    }
}
