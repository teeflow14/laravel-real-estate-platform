<?php

// File: app/Services/Funding/GatewayRegistry.php
// Mirrors the banking reference's PaymentGatewayRegistry pattern. New
// gateway drivers register here; nothing else in the codebase should
// instantiate a gateway class directly.
//
// The built-in drivers are listed below. Plugins add theirs while they are
// active (PluginContext::gateway() → extend()), so a gateway whose plugin is
// switched off or removed is simply not available: customers stop seeing it
// and its webhook stops answering, while its settings and history stay.
// A singleton, so what the plugins add lasts for the whole request.

namespace App\Services\Funding;

use App\Models\FundingGateway;
use App\Services\Funding\Contracts\FundingGatewayDriver;
use App\Services\Funding\Gateways\PayPalGateway;
use App\Services\Funding\Gateways\StripeGateway;
use InvalidArgumentException;
use RuntimeException;

class GatewayRegistry
{
    /**
     * Built into the platform, together with manual payment methods. Every
     * other gateway (Paystack, Flutterwave, NOWPayments, Razorpay, Mollie…)
     * is a plugin.
     */
    private const DRIVERS = [
        'stripe' => StripeGateway::class,
        'paypal' => PayPalGateway::class,
    ];

    /** @var array<string, class-string<FundingGatewayDriver>> code => driver added by an active plugin */
    private array $plugins = [];

    public function resolve(FundingGateway $gateway): FundingGatewayDriver
    {
        if (! in_array($gateway->driver, $this->availableDrivers(), true)) {
            throw new RuntimeException("Unknown or disallowed gateway driver: {$gateway->driver}");
        }

        return app($gateway->driver);
    }

    /** @return list<class-string<FundingGatewayDriver>> */
    public function availableDrivers(): array
    {
        return array_values(array_merge(self::DRIVERS, $this->plugins));
    }

    /** A plugin's gateway, for this request. */
    public function extend(string $code, string $driver): void
    {
        if ($this->isBuiltIn($code)) {
            throw new InvalidArgumentException("The gateway \"{$code}\" is built in.");
        }

        if (! is_subclass_of($driver, FundingGatewayDriver::class)) {
            throw new InvalidArgumentException("{$driver} is not a funding gateway driver.");
        }

        $this->plugins[$code] = $driver;
    }

    public function isBuiltIn(string $code): bool
    {
        return isset(self::DRIVERS[$code]);
    }

    public function has(string $code): bool
    {
        return isset(self::DRIVERS[$code]) || isset($this->plugins[$code]);
    }

    public function driver(string $code): FundingGatewayDriver
    {
        $class = self::DRIVERS[$code] ?? $this->plugins[$code] ?? throw new RuntimeException("No gateway \"{$code}\".");

        return app($class);
    }

    /** The credential fields of a gateway's driver, or none when it is not available. */
    public function credentialsFor(FundingGateway $gateway): array
    {
        return in_array($gateway->driver, $this->availableDrivers(), true) ? app($gateway->driver)->credentials() : [];
    }
}

// End of file: app/Services/Funding/GatewayRegistry.php
