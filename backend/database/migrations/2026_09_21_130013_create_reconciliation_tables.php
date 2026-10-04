<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('run_reference')->unique(); // REC-20260921-001
            $table->string('provider');                // SIMULATED_GATEWAY, NIBSS, INTERSWITCH
            $table->string('source_file')->nullable();
            $table->date('reconciliation_date');
            $table->unsignedInteger('total_internal_records')->default(0);
            $table->unsignedInteger('total_provider_records')->default(0);
            $table->unsignedInteger('matched_records')->default(0);
            $table->unsignedInteger('mismatched_records')->default(0);
            $table->unsignedBigInteger('matched_volume_minor')->default(0);
            $table->unsignedBigInteger('mismatched_volume_minor')->default(0);
            $table->string('status')->default('PROCESSING'); // PROCESSING, COMPLETED, FAILED
            $table->jsonb('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('internal_reference')->nullable();
            $table->string('provider_reference')->nullable();
            
            // Exception types: MISSING_IN_INTERNAL, MISSING_IN_PROVIDER, AMOUNT_MISMATCH, STATUS_MISMATCH
            $table->string('exception_type'); 
            $table->unsignedBigInteger('internal_amount_minor')->nullable();
            $table->unsignedBigInteger('provider_amount_minor')->nullable();
            $table->string('internal_status')->nullable();
            $table->string('provider_status')->nullable();
            $table->jsonb('discrepancy_details')->nullable();
            
            // Workflow: OPEN, INVESTIGATING, RESOLVED, WRITTEN_OFF
            $table->string('status')->default('OPEN');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['reconciliation_run_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('reconciliation_runs');
    }
};
