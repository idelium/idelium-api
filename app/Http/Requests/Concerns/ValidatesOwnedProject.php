<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesOwnedProject
{
    protected function ownedProjectRules(): array
    {
        return [
            'required',
            'integer',
            Rule::exists('projects', 'id')->where(
                fn ($query) => $query->where(
                    'idCostumer',
                    $this->user()->idCostumer
                )
            ),
        ];
    }
}
