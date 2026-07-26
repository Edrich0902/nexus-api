<?php

namespace App\Http\Requests\Api\V1\Library;

use App\Models\Library\LibraryBook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLibraryBookFromCatalogRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:255'],
            'authors' => ['nullable', 'string', 'max:512'],
            'isbn' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', Rule::in(LibraryBook::STATUSES)],
            'rating' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
        ];
    }
}
