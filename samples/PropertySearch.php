<?php

namespace App\Services\Properties;

use App\Models\Currency;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public listing search behind the catalogue pages and the JSON API.
 * Every filter is optional; only published listings are ever returned.
 *
 * Listings in every currency are searched together. The chosen currency
 * (the site's base currency by default) is the one prices are compared
 * and sorted in: other listings are converted with the exchange rates
 * kept on the currencies screen. A listing in a currency the site does not
 * have enabled only matches price filters set in its own currency.
 */
class PropertySearch
{
    /** @param array<string, mixed> $filters */
    public function query(array $filters): Builder
    {
        $currency = $filters['currency'] ?? base_currency_code();
        [$price, $bindings] = $this->priceIn($currency);

        $query = Property::query()->published()->with(['propertyType', 'listingPurpose', 'location', 'media', 'amenities', 'agent', 'agency'])
            ->select('properties.*')
            ->selectRaw("{$price} as search_price_minor", $bindings)
            ->selectRaw('? as search_currency', [$currency]);

        foreach (['property_type_id', 'listing_purpose_id', 'location_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (! empty($filters['q'])) {
            // The visitor's text is matched literally: % and _ are LIKE
            // wildcards and would otherwise widen the search.
            $term = '%'.addcslashes((string) $filters['q'], '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('title', 'like', $term)
                ->orWhereHas('location', fn (Builder $l) => $l->where('name', 'like', $term)->orWhere('city', 'like', $term)
                    ->orWhere('state', 'like', $term)->orWhere('neighborhood', 'like', $term)));
        }

        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->whereRaw("{$price} {$operator} ?", [...$bindings, (int) round((float) $filters[$key] * 100)]);
            }
        }

        if (isset($filters['bedrooms'])) {
            $query->where('bedrooms', '>=', $filters['bedrooms']);
        }

        foreach ($filters['amenities'] ?? [] as $id) {
            $query->whereHas('amenities', fn (Builder $a) => $a->whereKey($id));
        }

        if (! empty($filters['featured'])) {
            $query->where('featured', true);
        }

        return match ($filters['sort'] ?? 'latest') {
            'price_low' => $query->orderByRaw("({$price}) is null", $bindings)->orderByRaw($price, $bindings)->orderBy('properties.id'),
            'price_high' => $query->orderByRaw("({$price}) is null", $bindings)->orderByRaw("{$price} desc", $bindings)->orderByDesc('properties.id'),
            'featured' => $query->orderByDesc('featured')->orderByDesc('published_at')->orderByDesc('id'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * SQL for a listing's price in minor units of the given currency, with
     * its bindings. Null when the listing cannot be converted.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function priceIn(string $currency): array
    {
        $rate = Currency::query()->enabled()->where('code', $currency)->value('exchange_rate');

        if ($rate === null || (float) $rate <= 0) {
            return ['(case when properties.currency = ? then properties.price_minor end)', [$currency]];
        }

        // exchange_rate is units of the base currency per unit of that currency.
        return ['(case when properties.currency = ? then properties.price_minor'
            .' else properties.price_minor * (select c.exchange_rate from currencies c where c.code = properties.currency and c.is_enabled = ?) / ? end)',
            [$currency, true, (float) $rate]];
    }
}
