<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('description')->nullable();
            $table->decimal('rent', 10, 2);
            $table->enum('status', ['available', 'occupied', 'maintenance'])->default('available');
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->decimal('rent', 10, 2);
            $table->decimal('deposit', 10, 2)->default(0);
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['rent', 'electricity', 'water']);
            $table->string('period', 7);
            $table->decimal('amount', 10, 2);
            $table->date('due_at');
            $table->date('paid_at')->nullable();
            $table->decimal('late_fee', 10, 2)->default(0);
            $table->enum('status', ['pending', 'paid', 'void'])->default('pending');
            $table->decimal('previous_reading', 12, 3)->nullable();
            $table->decimal('current_reading', 12, 3)->nullable();
            $table->decimal('rate', 10, 4)->nullable();
            $table->decimal('consumption', 12, 3)->nullable();
            $table->timestamps();
            $table->unique(['contract_id', 'type', 'period']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('paid_at');
            $table->string('voucher_path');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('rooms');
    }
};
