<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;

class CustomerDirectory
{
    /** @return list<string> "Name <email>" lines, alphabetical */
    public function lines(): array
    {
        return Customer::query()->alphabetical()->get()
            ->map(fn (Customer $c) => "{$c->name} <{$c->email}>")
            ->all();
    }

    /** @return list<string> packing-slip lines for a customer's orders */
    public function packingSlip(Customer $customer): array
    {
        return $customer->orders()->orderBy('id')->get()
            ->map(fn (Order $o) => trim("#{$o->id} for {$customer->name} {$o->notes}"))
            ->all();
    }
}
