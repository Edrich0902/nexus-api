<?php

namespace App\Http\Requests\Api\V1\Cellar;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmWineMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'wineapi_id' => ['required', 'uuid'],
        ];
    }
}
