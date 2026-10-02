<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Guest checkouts have no customer. No foreign key: imported legacy orders.
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_pence')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
