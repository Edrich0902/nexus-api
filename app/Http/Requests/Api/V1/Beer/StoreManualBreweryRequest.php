<?php

namespace App\Http\Requests\Api\V1\Beer;

use Illuminate\Foundation\Http\FormRequest;

class StoreManualBreweryRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'brewery_type' => ['nullable', 'string', 'max:64'],
            'address_1' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state_province' => ['nullable', 'string', 'max:128'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:128'],
            'website_url' => ['nullable', 'url', 'max:1024'],
            'phone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
