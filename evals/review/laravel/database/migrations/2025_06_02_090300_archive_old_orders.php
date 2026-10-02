<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Order;

return new class extends Migration
{
    public function up(): void
    {
        foreach (Order::where('created_at', '<', '2025-01-01')->get() as $order) {
            $order->update(['status' => 'archived']);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Irreversible: the previous statuses were not recorded.');
    }
};
