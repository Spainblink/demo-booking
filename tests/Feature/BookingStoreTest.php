<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', $this->validPayload($room))
            ->assertUnauthorized();
    }

    public function test_creates_booking_for_authenticated_user_ignoring_body_user_id(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $room = Room::factory()->create();

        Sanctum::actingAs($author);

        $response = $this->postJson('/api/bookings', [
            ...$this->validPayload($room),
            'user_id' => $other->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('user_id', $author->id)
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('title', 'Планёрка');

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'user_id' => $author->id,
            'room_id' => $room->id,
        ]);

        $this->assertDatabaseMissing('bookings', [
            'user_id' => $other->id,
            'room_id' => $room->id,
        ]);
    }

    public function test_rejects_unknown_room(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            'room_id' => 999999,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_rejects_overlapping_booking(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();

        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Пересечение',
            'starts_at' => '2026-11-02T10:30:00+03:00',
            'ends_at' => '2026-11-02T11:30:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_allows_adjacent_bookings(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();

        Booking::factory()->for($room)->for($user)->create([
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Впритык',
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ])->assertCreated();
    }

    public function test_rejects_non_quarter_hour_minutes(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Не кратно 15',
            'starts_at' => '2026-11-02T10:10:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_booking_before_office_workday(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Слишком рано',
            'starts_at' => '2026-11-02T08:00:00+03:00',
            'ends_at' => '2026-11-02T09:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_booking_ending_after_office_workday(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Слишком поздно',
            'starts_at' => '2026-11-02T19:00:00+03:00',
            'ends_at' => '2026-11-02T21:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_when_ends_at_is_not_after_starts_at(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Нулевая длительность',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_rejects_duration_longer_than_4_hours(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Слишком длинно',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T14:15:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_uses_office_timezone_for_work_hours(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $moscowRoom = Room::factory()->for(Office::factory()->create([
            'name' => 'Москва',
            'timezone' => 'Europe/Moscow',
        ]))->create();

        $novosibirskRoom = Room::factory()->for(Office::factory()->novosibirsk())->create();

        // 02:00 UTC = 05:00 Москва (до 09:00) и 09:00 Новосибирск (рабочее время)
        $this->postJson('/api/bookings', [
            'room_id' => $moscowRoom->id,
            'title' => 'Москва рано',
            'starts_at' => '2026-11-02T02:00:00Z',
            'ends_at' => '2026-11-02T03:00:00Z',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->postJson('/api/bookings', [
            'room_id' => $novosibirskRoom->id,
            'title' => 'Новосибирск ок',
            'starts_at' => '2026-11-02T02:00:00Z',
            'ends_at' => '2026-11-02T03:00:00Z',
        ])->assertCreated();
    }

    public function test_accepts_iso8601_with_arbitrary_offset(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $room = Room::factory()->create();

        // 07:00 UTC = 10:00 Europe/Moscow
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'С другим смещением',
            'starts_at' => '2026-11-02T07:00:00Z',
            'ends_at' => '2026-11-02T08:00:00Z',
        ])->assertCreated();
    }

    /**
     * @return array{room_id: int, title: string, starts_at: string, ends_at: string}
     */
    private function validPayload(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ];
    }
}
