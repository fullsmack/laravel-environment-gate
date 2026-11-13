<?php

use FullSmack\EnvironmentGate\Database\TokenTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration
{
    public function getConnection(): ?string
    {
        return TokenTable::connection();
    }

    /**
     * Skip this migration (without recording it as run) on environments
     * where the gate or its database tokens are disabled, e.g. production.
     * Enabling the package later makes the next `migrate` create the table.
     * Only relevant for a published copy, as the package only loads its own
     * migration where it is enabled.
     */
    public function shouldRun(): bool
    {
        return TokenTable::active();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create(TokenTable::name(), function (Blueprint $table)
        {
            $table->id();
            $table->string('label', 100)->unique();
            $table->char('token_hash', 64)->unique();
            $table->string('description')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(TokenTable::name());
    }
};
