<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Services\MockInsurerService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InsurerController extends Controller
{
    protected MockInsurerService $insurerService;

    public function __construct(MockInsurerService $insurerService)
    {
        $this->insurerService = $insurerService;
    }

    /**
     * Submit a policy request to the mock insurer.
     *
     * @param Request $request
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit(Request $request, string $id)
    {
        $policy = PolicyRequest::findOrFail($id);

        if ($policy->status === 'ISSUED') {
            return response()->json([
                'message' => 'Policy has already been issued and cannot be re-submitted',
                'data' => $policy,
            ], 400);
        }

        // Increment submission attempt count
        $policy->attempt_count = $policy->attempt_count + 1;

        $simulation = $request->header('X-Simulation', $request->input('simulation'));

        try {
            $result = $this->insurerService->submit($policy, $simulation);

            $policy->status = $result['status'];
            $policy->provider_reference = $result['provider_reference'];
            if (!empty($result['policy_number'])) {
                $policy->policy_number = $result['policy_number'];
            }
            $policy->last_response = json_encode($result);
            $policy->save();

            return response()->json([
                'message' => 'Policy submitted to insurer successfully',
                'data' => $policy,
            ], 200);
        } catch (Exception $e) {
            $policy->status = 'FAILED';
            $policy->last_response = json_encode([
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            $policy->save();

            $statusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 502;

            return response()->json([
                'message' => 'Insurer submission failed: ' . $e->getMessage(),
                'data' => $policy,
            ], $statusCode);
        }
    }

    /**
     * Process asynchronous callback / webhook from mock insurer.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function callback(Request $request)
    {
        $data = $request->validate([
            'provider_reference' => 'required|string',
            'status' => ['required', Rule::in(['ISSUED', 'FAILED', 'REJECTED'])],
            'policy_number' => 'nullable|string|required_if:status,ISSUED',
            'message' => 'nullable|string',
        ]);

        $policy = PolicyRequest::where('provider_reference', $data['provider_reference'])->first();

        if (!$policy) {
            return response()->json([
                'message' => 'Policy request not found for provider reference: ' . $data['provider_reference'],
            ], 404);
        }

        // Idempotency: safe handling of duplicate callbacks
        if ($policy->status === 'ISSUED') {
            return response()->json([
                'message' => 'Callback already processed (duplicate callback ignored)',
                'data' => $policy,
            ], 200);
        }

        if ($data['status'] === 'ISSUED') {
            $policy->status = 'ISSUED';
            $policy->policy_number = $data['policy_number'];
        } else {
            $policy->status = 'FAILED';
        }

        $policy->last_response = json_encode($data);
        $policy->save();

        return response()->json([
            'message' => 'Insurer callback processed successfully',
            'data' => $policy,
        ], 200);
    }

    /**
     * Provider status lookup to check external issuance outcome.
     *
     * @param string $providerReference
     * @return \Illuminate\Http\JsonResponse
     */
    public function statusLookup(string $providerReference)
    {
        $status = $this->insurerService->checkStatus($providerReference);
        return response()->json($status, 200);
    }

    /**
     * Reconcile a pending or timed-out policy request by querying the insurer.
     *
     * @param string $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function reconcile(string $id)
    {
        $policy = PolicyRequest::findOrFail($id);

        if ($policy->status === 'ISSUED') {
            return response()->json([
                'message' => 'Policy already confirmed as ISSUED',
                'data' => $policy,
            ], 200);
        }

        if (!$policy->provider_reference) {
            return response()->json([
                'message' => 'Cannot reconcile: no provider reference registered for this request',
            ], 422);
        }

        $result = $this->insurerService->checkStatus($policy->provider_reference);

        if ($result['status'] === 'ISSUED') {
            $policy->status = 'ISSUED';
            $policy->policy_number = $result['policy_number'];
            $policy->last_response = json_encode($result);
            $policy->save();
        }

        return response()->json([
            'message' => 'Policy reconciliation successful',
            'data' => $policy,
        ], 200);
    }
}
