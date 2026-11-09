<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $booking = Booking::factory()->create();

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertUnauthorized();
    }

    public function test_author_can_cancel_own_booking(): void
    {
        $author = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($author);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_other_user_cannot_cancel_booking(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $booking = Booking::factory()->for($author)->create();

        Sanctum::actingAs($stranger);

        $this->deleteJson('/api/bookings/'.$booking->id)
            ->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }
}
