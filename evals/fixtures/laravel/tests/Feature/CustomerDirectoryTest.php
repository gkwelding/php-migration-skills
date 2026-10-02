<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Services\CustomerDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_lines_are_alphabetical_by_name(): void
    {
        Customer::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        Customer::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->assertSame(
            ['Ada Lovelace <ada@example.com>', 'Grace Hopper <grace@example.com>'],
            (new CustomerDirectory)->lines(),
        );
    }

    public function test_packing_slip_lists_orders_with_notes(): void
    {
        $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);
        $first = Order::factory()->for($customer)->create(['notes' => 'Leave at door']);
        $second = Order::factory()->for($customer)->create();

        $this->assertSame(
            ["#{$first->id} for Ada Lovelace Leave at door", "#{$second->id} for Ada Lovelace"],
            (new CustomerDirectory)->packingSlip($customer),
        );
    }

    public function test_guest_orders_have_no_customer(): void
    {
        $order = Order::factory()->create(['customer_id' => null]);

        $this->assertNull($order->customer);
    }
}
