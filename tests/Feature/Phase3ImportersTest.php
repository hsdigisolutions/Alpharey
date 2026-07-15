<?php

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Services\LegacyImport\Importers\ClientsImporter;
use App\Services\LegacyImport\Importers\ProjectsImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 importers against an in-memory legacy stand-in (live dump still
 * pending — DATA_MIGRATION.md §1).
 */
beforeEach(function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');

    Schema::connection('legacy')->create('clients', function (Blueprint $t): void {
        $t->id();
        $t->string('name');
        $t->string('nif')->nullable();
        $t->string('client_type')->nullable();
        $t->boolean('active')->default(true);
    });
    Schema::connection('legacy')->create('projects', function (Blueprint $t): void {
        $t->id();
        $t->string('name');
        $t->string('code')->nullable();
        $t->unsignedBigInteger('client_id')->nullable();
        $t->string('status')->nullable();
        $t->string('priority')->nullable();
        $t->decimal('budget', 14, 2)->nullable();
    });

    Company::factory()->create(['name' => 'Empresa Uno']);
});

it('imports shared clients 1:1', function (): void {
    DB::connection('legacy')->table('clients')->insert([
        ['name' => 'Cliente Uno', 'nif' => 'A1', 'client_type' => 'municipality', 'active' => 1],
        ['name' => 'Cliente Dos', 'nif' => 'B2', 'client_type' => 'weird', 'active' => 0],
    ]);

    $result = app(ClientsImporter::class)->run();

    expect($result->imported)->toBe(2);
    $uno = Client::query()->where('name', 'Cliente Uno')->firstOrFail();
    expect($uno->client_type->value)->toBe('municipality');
    // Unknown legacy type falls back to 'company'
    expect(Client::query()->where('name', 'Cliente Dos')->value('client_type'))->toBe(ClientType::Company);
});

it('imports projects to company 1 and remaps the client id', function (): void {
    DB::connection('legacy')->table('clients')->insert([['name' => 'C', 'nif' => 'X', 'client_type' => 'company', 'active' => 1]]);
    DB::connection('legacy')->table('projects')->insert([
        ['name' => 'Obra Uno', 'code' => 'OLD-P1', 'client_id' => 1, 'status' => 'in_progress', 'priority' => 'high', 'budget' => 50000],
    ]);

    app(ClientsImporter::class)->run(); // must run first (projects remap client ids)
    $result = app(ProjectsImporter::class)->run();

    expect($result->imported)->toBe(1)->and($result->exceptions)->toBe(0);

    $project = Project::withoutGlobalScopes()->where('name', 'Obra Uno')->firstOrFail();
    $newClient = Client::query()->where('name', 'C')->firstOrFail();

    expect($project->company_id)->toBe(Company::query()->value('id'))
        ->and($project->client_id)->toBe($newClient->id)
        ->and($project->status->value)->toBe('in_progress');
});

it('reports a project whose client was not imported', function (): void {
    DB::connection('legacy')->table('projects')->insert([
        ['name' => 'Huérfano', 'code' => 'OLD-P9', 'client_id' => 999, 'status' => 'active', 'priority' => 'medium', 'budget' => 1000],
    ]);

    $result = app(ProjectsImporter::class)->run();

    // Still imports the project (client set null) but flags the exception
    expect($result->imported)->toBe(1)
        ->and($result->exceptions)->toBe(1);

    if ($result->exceptionsReportPath !== null) {
        unlink($result->exceptionsReportPath);
    }
});
