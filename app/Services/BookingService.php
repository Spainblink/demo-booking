<?php

namespace App\Services;

use App\Enums\BookingRule;
use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * Создать бронь для аутентифицированного пользователя после проверки бизнес-правил.
     *
     * @param  User  $user  Автор брони (из Bearer-токена)
     * @param  array{room_id: int, title: string, starts_at: string, ends_at: string}  $data  Данные брони
     * @return Booking
     *
     * @throws ValidationException
     */
    public function create(User $user, array $data): Booking
    {
        $room = $this->findRoomOrFailValidation((int) $data['room_id']);
        $startsAt = CarbonImmutable::parse($data['starts_at']);
        $endsAt = CarbonImmutable::parse($data['ends_at']);

        $this->assertSlotAlignment($startsAt, $endsAt);
        $this->assertDuration($startsAt, $endsAt);
        $this->assertWithinOfficeHours($room, $startsAt, $endsAt);

        return DB::transaction(function () use ($user, $room, $data, $startsAt, $endsAt): Booking {
            $this->lockRoomOrFailValidation($room->id);

            $this->assertNoOverlap($room->id, $startsAt, $endsAt);

            $booking = Booking::query()->create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'title' => $data['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            Log::channel('bookings')->info('Booking created', [
                'booking_id' => $booking->id,
                'room_id' => $booking->room_id,
                'user_id' => $booking->user_id,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'ends_at' => $booking->ends_at?->toIso8601String(),
                'title' => $booking->title,
            ]);

            return $booking;
        });
    }

    /**
     * Отменить бронь. Доступно только автору.
     *
     * @param  User  $user  Текущий пользователь (из Bearer-токена)
     * @param  Booking  $booking  Отменяемая бронь
     * @return void
     *
     * @throws AuthorizationException
     */
    public function cancel(User $user, Booking $booking): void
    {
        if ($booking->user_id !== $user->id) {
            throw new AuthorizationException('Отменить бронь может только её автор.');
        }

        $booking->delete();
    }

    /**
     * Найти комнату с офисом или вернуть понятную ошибку валидации.
     *
     * @param  int  $roomId  Идентификатор комнаты
     * @return Room
     *
     * @throws ValidationException
     */
    private function findRoomOrFailValidation(int $roomId): Room
    {
        $room = Room::query()->with('office')->find($roomId);

        if ($room === null) {
            throw ValidationException::withMessages([
                'room_id' => 'Указанная комната не найдена.',
            ]);
        }

        return $room;
    }

    /**
     * Заблокировать комнату в транзакции без ожидания чужой блокировки.
     *
     * Если строка уже залочена другим запросом — сразу 422, а не «комната не найдена».
     *
     * @param  int  $roomId  Идентификатор комнаты
     * @return void
     *
     * @throws ValidationException
     */
    private function lockRoomOrFailValidation(int $roomId): void
    {
        try {
            $locked = Room::query()
                ->whereKey($roomId)
                ->lock('FOR UPDATE NOWAIT')
                ->first();
        } catch (QueryException $exception) {
            if ($this->isLockNotAvailable($exception)) {
                throw ValidationException::withMessages([
                    'room_id' => 'Комната временно занята другим запросом. Повторите попытку.',
                ]);
            }

            throw $exception;
        }

        if ($locked === null) {
            throw ValidationException::withMessages([
                'room_id' => 'Указанная комната не найдена.',
            ]);
        }
    }

    /**
     * Проверить, что PostgreSQL не смог взять блокировку строки (SQLSTATE 55P03).
     *
     * @param  QueryException  $exception  Исключение запроса
     * @return bool
     */
    private function isLockNotAvailable(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return $sqlState === '55P03'
            || str_contains(strtolower($exception->getMessage()), 'could not obtain lock');
    }

    /**
     * Проверить, что начало и конец попадают на сетку 15 минут.
     *
     * @param  CarbonImmutable  $startsAt  Время начала
     * @param  CarbonImmutable  $endsAt  Время окончания
     * @return void
     *
     * @throws ValidationException
     */
    private function assertSlotAlignment(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        if (! $this->isAlignedToSlot($startsAt) || ! $this->isAlignedToSlot($endsAt)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Время начала и окончания должно быть кратно 15 минутам (например, 10:00, 10:15).',
            ]);
        }
    }

    /**
     * Проверить, что длительность от 15 минут до 4 часов.
     *
     * @param  CarbonImmutable  $startsAt  Время начала
     * @param  CarbonImmutable  $endsAt  Время окончания
     * @return void
     *
     * @throws ValidationException
     */
    private function assertDuration(CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $durationMinutes = $startsAt->diffInMinutes($endsAt);

        if (
            $durationMinutes < BookingRule::MinDurationMinutes->value()
            || $durationMinutes > BookingRule::MaxDurationMinutes->value()
        ) {
            throw ValidationException::withMessages([
                'ends_at' => 'Длительность брони должна быть от 15 минут до 4 часов.',
            ]);
        }
    }

    /**
     * Проверить, что бронь целиком укладывается в 09:00–20:00 по часовому поясу офиса.
     *
     * @param  Room  $room  Комната с загруженным офисом
     * @param  CarbonImmutable  $startsAt  Время начала
     * @param  CarbonImmutable  $endsAt  Время окончания
     * @return void
     *
     * @throws ValidationException
     */
    private function assertWithinOfficeHours(
        Room $room,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $timezone = $room->office->timezone;
        $localStart = $startsAt->timezone($timezone);
        $localEnd = $endsAt->timezone($timezone);

        $workdayStart = $localStart->setTime(BookingRule::WorkdayStartHour->value(), 0, 0);
        $workdayEnd = $localStart->setTime(BookingRule::WorkdayEndHour->value(), 0, 0);

        if (
            $localStart->toDateString() !== $localEnd->toDateString()
            || $localStart->lt($workdayStart)
            || $localEnd->gt($workdayEnd)
        ) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронь должна целиком укладываться в рабочее время 09:00–20:00 по местному времени офиса.',
            ]);
        }
    }

    /**
     * Проверить отсутствие пересечений с другими бронями комнаты.
     *
     * Стыкующиеся интервалы разрешены: если у существующей брони ends_at равен
     * starts_at новой (или наоборот), это не пересечение. Поэтому сравнения строгие:
     * existing.starts_at < new.ends_at AND existing.ends_at > new.starts_at.
     *
     * @param  int  $roomId  Идентификатор комнаты
     * @param  CarbonImmutable  $startsAt  Время начала
     * @param  CarbonImmutable  $endsAt  Время окончания
     * @return void
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(
        int $roomId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        $hasConflict = Booking::query()
            ->where('room_id', $roomId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($hasConflict) {
            throw ValidationException::withMessages([
                'room_id' => 'Комната занята в указанный интервал.',
            ]);
        }
    }

    /**
     * Проверить, что метка времени без секунд и минуты кратны 15.
     *
     * @param  CarbonImmutable  $moment  Проверяемый момент времени
     * @return bool
     */
    private function isAlignedToSlot(CarbonImmutable $moment): bool
    {
        return $moment->second === 0
            && $moment->microsecond === 0
            && ($moment->minute % BookingRule::SlotMinutes->value()) === 0;
    }
}
