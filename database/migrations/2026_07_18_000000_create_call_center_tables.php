<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->string('phone')->unique();
            $table->timestamps();
        });

        Schema::create('operators', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('available')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamp('last_call_at')->nullable();
            $table->timestamps();

            $table->index(['available', 'last_call_at']);
        });

        Schema::create('calls', function (Blueprint $table): void {
            $table->id();
            $table->string('phone');
            $table->string('status')->default('new');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedInteger('attempts_assign')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('phone');
        });

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('aggregate_type');
            $table->foreignId('aggregate_id');
            $table->json('payload_json');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['type', 'aggregate_type', 'aggregate_id']);
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('operators');
        Schema::dropIfExists('clients');
    }
};
