<?php

namespace App\Http\Requests\Api\V1\Beer;

use Illuminate\Foundation\Http\FormRequest;

class ImportBreweryRequest extends FormRequest
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
            'obdb_id' => ['required', 'string', 'max:64'],
        ];
    }
}
