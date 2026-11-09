<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreLockTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Не оборачивать тест в транзакцию default-соединения,
     * иначе второе соединение не увидит комнату и не сможет проверить NOWAIT.
     *
     * @return array<int, string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_rejects_when_room_row_is_locked_by_another_connection(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->create();

        Sanctum::actingAs($user);

        $default = (string) config('database.default');
        config([
            'database.connections.locking' => config('database.connections.'.$default),
        ]);

        DB::connection('locking')->beginTransaction();

        try {
            DB::connection('locking')
                ->table('rooms')
                ->where('id', $room->id)
                ->lockForUpdate()
                ->first();

            $this->postJson('/api/bookings', [
                'room_id' => $room->id,
                'title' => 'Параллельный запрос',
                'starts_at' => '2026-11-02T10:00:00+03:00',
                'ends_at' => '2026-11-02T11:00:00+03:00',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['room_id']);
        } finally {
            DB::connection('locking')->rollBack();
            DB::purge('locking');
        }
    }
}
