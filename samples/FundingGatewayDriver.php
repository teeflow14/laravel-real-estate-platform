<?php

// File: app/Services/Funding/Contracts/FundingGatewayDriver.php
//
// What every online deposit gateway implements, built in or added by a
// plugin. The drivers used to claim App\Core\Contracts\PaymentGatewayContract,
// whose methods (key/initiate/callback/refund) none of them had, so PHP
// refused to load any driver and every online deposit failed. This is the
// shape the funding flow has always called.

namespace App\Services\Funding\Contracts;

use App\DataTransfer\GatewayVerificationResult;
use App\Models\FundingSession;
use Illuminate\Http\Request;

interface FundingGatewayDriver
{
    /** The gateway code, e.g. "stripe"; also the webhook path /webhooks/funding/{code}. */
    public function code(): string;

    /** The name shown to administrators. */
    public function label(): string;

    /**
     * The keys an administrator enters under Funding → Gateways, stored
     * encrypted as settings "gateway.{code}.{key}".
     *
     * @return array<string, string> key => label
     */
    public function credentials(): array;

    /**
     * Currencies the provider accepts, offered when the administrator adds one.
     *
     * @return array<string, string> code => symbol
     */
    public function supportedCurrencies(): array;

    /**
     * Start the payment at the provider.
     *
     * @return array{redirect_url: ?string, external_reference: ?string}
     */
    public function initialize(FundingSession $session): array;

    /** Ask the provider where the payment stands (the customer came back, or a webhook was slow). */
    public function verify(FundingSession $session): GatewayVerificationResult;

    /** A provider notification, or null when its signature does not check out. */
    public function handleWebhook(Request $request): ?GatewayVerificationResult;
}

// End of file: app/Services/Funding/Contracts/FundingGatewayDriver.php
