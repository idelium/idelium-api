<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesOwnedProject;
use Illuminate\Foundation\Http\FormRequest;

class StorePluginRequest extends FormRequest
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
            'code' => ['required'],
            'description' => ['required', 'string', 'max:255'],
            'idProject' => $this->ownedProjectRules(),
        ];
    }
}
