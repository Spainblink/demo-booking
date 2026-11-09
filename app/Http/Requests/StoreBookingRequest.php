<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    /**
     * Разрешить запрос только аутентифицированному пользователю.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Правила валидации входных данных для создания брони.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * Сообщения об ошибках валидации.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите комнату.',
            'room_id.exists' => 'Указанная комната не найдена.',
            'title.required' => 'Укажите название встречи.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.date' => 'Время начала должно быть в формате ISO 8601.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.date' => 'Время окончания должно быть в формате ISO 8601.',
            'ends_at.after' => 'Время окончания должно быть позже времени начала.',
        ];
    }
}
