<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTestCycleRequest extends FormRequest
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
            'config' => ['required'],
            'idProject' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')->where(
                    fn ($query) => $query->where(
                        'idCostumer',
                        $this->user()->idCostumer
                    )
                ),
            ],
        ];
    }
}
