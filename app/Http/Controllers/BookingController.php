<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /**
     * @param  BookingService  $bookingService  Сервис бронирования
     * @return void
     */
    public function __construct(private BookingService $bookingService) {}

    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    /**
     * Создать бронь от имени аутентифицированного пользователя.
     *
     * @param  StoreBookingRequest  $request  Валидированный запрос на создание
     * @return Booking
     *
     * @throws ValidationException
     */
    public function store(StoreBookingRequest $request): Booking
    {
        return $this->bookingService->create(
            $request->user(),
            $request->validated(),
        );
    }

    /**
     * Отменить бронь. Доступно только автору.
     *
     * @param  Request  $request  HTTP-запрос
     * @param  Booking  $booking  Отменяемая бронь
     * @return Response
     *
     * @throws AuthorizationException
     */
    public function destroy(Request $request, Booking $booking): Response
    {
        $this->bookingService->cancel($request->user(), $booking);

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
