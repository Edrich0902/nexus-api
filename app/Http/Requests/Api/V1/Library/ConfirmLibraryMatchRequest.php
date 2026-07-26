<?php

namespace App\Http\Requests\Api\V1\Library;

use App\Models\Library\LibraryBook;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmLibraryMatchRequest extends FormRequest
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
            'ol_work_key' => ['required', 'string', 'max:64'],
        ];
    }
}
