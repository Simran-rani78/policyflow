<?php

namespace App\Services;

use App\Models\PolicyRequest;
use Exception;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Submit a policy request to the mock insurer.
     *
     * @param PolicyRequest $policy
     * @param string|null $simulation
     * @return array
     * @throws Exception
     */
    public function submit(PolicyRequest $policy, ?string $simulation = null): array
    {
        $providerRef = $policy->provider_reference ?: 'INS-' . strtoupper(Str::random(12));

        // Reliability Scenario 1: Temporary insurer failure
        if ($simulation === 'temporary_failure') {
            throw new Exception('Mock Insurer unavailable: 503 Service Unavailable', 503);
        }

        // Reliability Scenario 2: Network timeout after insurer generates reference
        if ($simulation === 'timeout') {
            // Save provider reference before timeout so reconciliation/late callback is possible
            $policy->update([
                'provider_reference' => $providerRef,
                'last_response' => 'Insurer connection timed out during submission',
            ]);
            throw new Exception('Connection timed out waiting for insurer response', 504);
        }

        // Immediate synchronous issuance simulation
        if ($simulation === 'immediate_issue') {
            $policyNumber = 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            return [
                'provider_reference' => $providerRef,
                'status' => 'ISSUED',
                'policy_number' => $policyNumber,
                'message' => 'Policy issued immediately by insurer',
            ];
        }

        // Standard asynchronous workflow: insurer accepts submission, pending callback
        return [
            'provider_reference' => $providerRef,
            'status' => 'SUBMITTED',
            'policy_number' => null,
            'message' => 'Policy request accepted by insurer; awaiting issuance callback',
        ];
    }

    /**
     * Generate mock callback payload for a given policy request.
     *
     * @param PolicyRequest $policy
     * @param string $status
     * @return array
     */
    public function generateCallbackPayload(PolicyRequest $policy, string $status = 'ISSUED'): array
    {
        $providerRef = $policy->provider_reference ?: 'INS-' . strtoupper(Str::random(12));
        $policyNumber = $status === 'ISSUED'
            ? 'POL-' . date('Ymd') . '-' . strtoupper(Str::random(6))
            : null;

        return [
            'provider_reference' => $providerRef,
            'status' => $status,
            'policy_number' => $policyNumber,
            'message' => $status === 'ISSUED'
                ? 'Policy successfully underwritten and issued'
                : 'Policy underwriting rejected by insurer',
        ];
    }

    /**
     * Check status with provider for reconciliation.
     *
     * @param string $providerReference
     * @return array
     */
    public function checkStatus(string $providerReference): array
    {
        // For reconciliation: simulate that insurer created the policy internally
        $policyNumber = 'POL-' . date('Ymd') . '-' . substr(md5($providerReference), 0, 8);
        return [
            'provider_reference' => $providerReference,
            'status' => 'ISSUED',
            'policy_number' => strtoupper($policyNumber),
            'message' => 'Policy reconciled successfully from insurer records',
        ];
    }
}
