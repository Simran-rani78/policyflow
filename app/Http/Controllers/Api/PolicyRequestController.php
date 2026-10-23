<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PolicyRequestController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_request_id' => 'required|string|max:100',
            'agent_id' => 'required|string|max:100',
            'customer_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date|before:today',
            'destination' => ['required', Rule::in(['DOMESTIC', 'INTERNATIONAL'])],
            'trip_start' => 'required|date',
            'trip_end' => 'required|date|after_or_equal:trip_start',
            'plan_code' => ['required', Rule::in(['BASIC', 'PLUS', 'PREMIUM'])],
            'sum_insured' => ['required', Rule::in([100000, 300000, 500000])],
        ]);

        $existing = PolicyRequest::where('agent_id', $data['agent_id'])
            ->where('client_request_id', $data['client_request_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Existing request returned',
                'data' => $existing,
            ], 200);
        }

        $start = Carbon::parse($data['trip_start']);
        $end = Carbon::parse($data['trip_end']);
        $age = (int) Carbon::parse($data['date_of_birth'])->diffInYears($start);
        $days = (int) $start->diffInDays($end) + 1;

        $baseRate = [
            'BASIC' => 12,
            'PLUS' => 20,
            'PREMIUM' => 30,
        ][$data['plan_code']];

        $ageMultiplier = match (true) {
            $age <= 17 => 1.10,
            $age <= 60 => 1.00,
            $age <= 70 => 1.50,
            default => 2.00,
        };

        $destinationMultiplier =
            $data['destination'] === 'DOMESTIC' ? 1.00 : 1.80;

        $coverageMultiplier = match ((int) $data['sum_insured']) {
            100000 => 1.00,
            300000 => 1.25,
            500000 => 1.50,
        };

        $premium = round(
            $baseRate * $days * $ageMultiplier
            * $destinationMultiplier * $coverageMultiplier,
            2
        );

        try {
            $policy = DB::transaction(function () use ($data, $premium) {
                return PolicyRequest::create([
                    ...$data,
                    'premium' => $premium,
                    'status' => 'VALIDATED',
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $existing = PolicyRequest::where('agent_id', $data['agent_id'])
                ->where('client_request_id', $data['client_request_id'])
                ->first();

            if (!$existing) {
                throw $e;
            }

            return response()->json([
                'message' => 'Existing request returned',
                'data' => $existing,
            ], 200);
        }

        return response()->json([
            'message' => 'Policy request created',
            'data' => $policy,
        ], 201);
    }

    public function index(Request $request)
    {
        return PolicyRequest::query()
            ->when(
                $request->query('status'),
                fn ($query, $status) => $query->where('status', $status)
            )
            ->orderByDesc('id')
            ->paginate(10);
    }

    public function show(string $id)
    {
        return PolicyRequest::findOrFail($id);
    }
}
