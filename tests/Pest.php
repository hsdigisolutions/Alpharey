<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Fixture table for tenancy + audit tests (Tests\Fixtures\ScopedItem).
 * Created on demand because it is not part of the application schema.
 */
function createScopedItemsTable(): void
{
    if (Schema::hasTable('scoped_items')) {
        return;
    }

    Schema::create('scoped_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('company_id')->nullable()->index();
        $table->string('name');
        $table->string('secret')->nullable();
        $table->timestamps();
    });
}
