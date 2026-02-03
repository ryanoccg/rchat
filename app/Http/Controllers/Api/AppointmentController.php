<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Calendar\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppointmentController extends Controller
{
    /**
     * Get all appointments
     */
    public function index(Request $request)
    {
        $companyId = $request->company_id;

        $query = Appointment::where('company_id', $companyId)
            ->with(['customer', 'conversation']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('start_time', [$request->start_date, $request->end_date]);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $appointments = $query->orderBy('start_time', 'desc')
            ->paginate($request->get('per_page', 20));

        return AppointmentResource::collection($appointments);
    }

    /**
     * Get upcoming appointments
     */
    public function upcoming(Request $request)
    {
        $companyId = $request->company_id;

        $appointments = Appointment::where('company_id', $companyId)
            ->upcoming()
            ->with(['customer'])
            ->limit($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => AppointmentResource::collection($appointments),
        ]);
    }

    /**
     * Get available dates for booking
     */
    public function availableDates(Request $request)
    {
        $companyId = $request->company_id;

        try {
            $service = new AppointmentService($companyId);
            $days = $request->get('days', 14);
            $dates = $service->getAvailableDates($days);

            return response()->json([
                'data' => $dates,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get available time slots for a specific date
     */
    public function availableSlots(Request $request)
    {
        $request->validate([
            'date' => 'required|date|after_or_equal:today',
        ]);

        $companyId = $request->company_id;

        try {
            $service = new AppointmentService($companyId);
            $slots = $service->getAvailableSlots($request->date);

            return response()->json([
                'date' => $request->date,
                'slots' => $slots,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Create a new appointment
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'start_time' => 'required|date|after:now',
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'nullable|email|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'customer_id' => 'nullable|exists:customers,id',
            'conversation_id' => 'nullable|exists:conversations,id',
            'title' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $companyId = $request->company_id;

        try {
            $service = new AppointmentService($companyId);

            $customer = null;
            if (!empty($validated['customer_id'])) {
                $customer = Customer::find($validated['customer_id']);
            }

            $conversation = null;
            if (!empty($validated['conversation_id'])) {
                $conversation = \App\Models\Conversation::find($validated['conversation_id']);
            }

            $appointment = $service->bookAppointment($validated, $customer, $conversation);

            return response()->json([
                'message' => 'Appointment booked successfully',
                'data' => new AppointmentResource($appointment->load(['customer'])),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to book appointment', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get a specific appointment
     */
    public function show(Request $request, $id)
    {
        $companyId = $request->company_id;

        $appointment = Appointment::where('company_id', $companyId)
            ->with(['customer', 'conversation'])
            ->findOrFail($id);

        return response()->json([
            'data' => new AppointmentResource($appointment),
        ]);
    }

    /**
     * Update an appointment
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'status' => 'nullable|in:pending,confirmed,completed,no_show',
        ]);

        $companyId = $request->company_id;

        $appointment = Appointment::where('company_id', $companyId)
            ->findOrFail($id);

        $appointment->update($validated);

        return response()->json([
            'message' => 'Appointment updated successfully',
            'data' => new AppointmentResource($appointment),
        ]);
    }

    /**
     * Cancel an appointment
     */
    public function cancel(Request $request, $id)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $companyId = $request->company_id;

        $appointment = Appointment::where('company_id', $companyId)
            ->findOrFail($id);

        if ($appointment->status === Appointment::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'Appointment is already cancelled',
            ], 400);
        }

        try {
            $service = new AppointmentService($companyId);
            $appointment = $service->cancelAppointment($appointment, $request->reason);

            return response()->json([
                'message' => 'Appointment cancelled successfully',
                'data' => new AppointmentResource($appointment),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reschedule an appointment
     */
    public function reschedule(Request $request, $id)
    {
        $validated = $request->validate([
            'start_time' => 'required|date|after:now',
        ]);

        $companyId = $request->company_id;

        $appointment = Appointment::where('company_id', $companyId)
            ->findOrFail($id);

        if ($appointment->status === Appointment::STATUS_CANCELLED) {
            return response()->json([
                'message' => 'Cannot reschedule a cancelled appointment',
            ], 400);
        }

        try {
            $service = new AppointmentService($companyId);
            $appointment = $service->rescheduleAppointment($appointment, $validated['start_time']);

            return response()->json([
                'message' => 'Appointment rescheduled successfully',
                'data' => new AppointmentResource($appointment),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
