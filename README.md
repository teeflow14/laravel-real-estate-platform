# Laravel Real Estate Platform

A **property marketplace** built with **Laravel 13**: agencies, agents and private owners list properties for sale, rent or short-let, and visitors search, enquire, book viewings and pay for short-let stays from a wallet. Listing plans, a ledger-backed wallet, KYC, a plugin system and a page builder make it a complete product that an operator installs on their own server.

> This repository is a **showcase**. The full source code is private because the platform is a commercial product.
> It contains an overview, the architecture and a few representative files. I'm happy to walk through the complete codebase on a call.

**Author:** Alabi Babatunde, PHP/Laravel backend developer · [LinkedIn](https://www.linkedin.com/in/tundeblog)

---

## At a glance

| | |
|---|---|
| Framework | Laravel 13, PHP 8.4, MySQL / MariaDB |
| Front end | Blade, Tailwind CSS, Alpine.js |
| Domain models | 92 Eloquent models |
| HTTP layer | 115 controllers (public site, customer portal, admin panel, JSON API) |
| Automated tests | 1,000+ PHPUnit test methods |

## Features

**Public site and customer portal**
- Property search by type, purpose, location, price, bedrooms and amenities, with a JSON API
- **Multi-currency search:** listings in different currencies are compared and sorted in the visitor's chosen currency using live exchange rates
- Property pages with media galleries, enquiries and viewing appointments
- **Short-let bookings** with an availability calendar, owner date blocks and wallet payment
- Listing plans and subscriptions for owners and agents, with paid listing charges
- Wallet funding through online gateways (webhook-confirmed) or admin-approved manual methods, and withdrawals with PIN and whitelist checks
- KYC tiers, two-factor authentication, devices, notifications and support tickets

**Admin panel**
- Properties, agencies, agents, locations, property types, amenities and listing plans
- Funding gateways, manual methods, withdrawals, KYC review
- Page builder, CMS revisions, menus, themes, blog, email campaigns and templates
- Plugins (signed packages), staff roles and permissions, security events, audit log, backups and updater

## Architecture

The platform separates a reusable **Core** (auth, security, settings, CMS and page builder, plugins, notifications, payment and withdrawal contracts, the ledger, dynamic forms, audit logging) from the **Real Estate product** (properties, agencies, listing billing, short-lets, viewings).

- **All money movement goes through the wallet ledger.** Wallet holds, ledger entries and journal entries are written inside DB transactions with row locks. Balances are never edited directly.
- **Payment providers are drivers** behind `FundingGatewayDriver`, registered in a `GatewayRegistry`. Plugins can add gateways at runtime. A disabled plugin's gateway disappears and its webhook stops answering, while its history stays.
- **Themes change presentation only.** They add no routes, queries or business logic.

## Code samples

| File | What it shows |
|---|---|
| [`samples/PropertySearch.php`](samples/PropertySearch.php) | A composable search query with optional filters, **safe LIKE escaping**, and SQL-level currency conversion so mixed-currency listings sort correctly |
| [`samples/ShortletBookingService.php`](samples/ShortletBookingService.php) | Booking inside a transaction with row locks: eligibility checks, overlap prevention, overflow-safe money in minor units, and a wallet debit linked to the booking |
| [`samples/GatewayRegistry.php`](samples/GatewayRegistry.php) | A registry of built-in and plugin-provided payment drivers, with an allow-list so only known drivers can be resolved |
| [`samples/FundingGatewayDriver.php`](samples/FundingGatewayDriver.php) | The payment-driver contract |
| [`samples/tests/ShortletBookingTest.php`](samples/tests/ShortletBookingTest.php) | Feature tests for short-let booking rules |
