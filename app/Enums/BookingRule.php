<?php

namespace App\Enums;

/**
 * Бизнес-ограничения бронирования переговорных.
 */
enum BookingRule
{
    /**
     * Начало рабочего дня офиса (час).
     */
    case WorkdayStartHour;

    /**
     * Конец рабочего дня офиса (час).
     */
    case WorkdayEndHour;

    /**
     * Минимальная длительность брони в минутах.
     */
    case MinDurationMinutes;

    /**
     * Максимальная длительность брони в минутах.
     */
    case MaxDurationMinutes;

    /**
     * Шаг сетки слотов в минутах.
     */
    case SlotMinutes;

    /**
     * Числовое значение ограничения.
     *
     * @return int
     */
    public function value(): int
    {
        return match ($this) {
            self::WorkdayStartHour => 9,
            self::WorkdayEndHour => 20,
            self::MinDurationMinutes, self::SlotMinutes => 15,
            self::MaxDurationMinutes => 240,
        };
    }
}
