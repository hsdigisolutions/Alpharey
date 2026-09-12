<?php

namespace App\Console\Commands;

use App\Models\DeploymentCharge;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Deployments\DeploymentChargeService;
use App\Services\Deployments\DeploymentSettlementService;
use Illuminate\Console\Command;

/**
 * One-time (idempotent) backfill: mint the home-side inter-company invoice for
 * every already-completed deployment charge that predates the invoice feature
 * (2026-09-12). A charge is eligible once it is LOCKED (the deployment completed
 * and invoiced_at was stamped). Charges still accruing get no invoice.
 *
 * If a charge was already marked paid (settlement_status), the invoice is minted
 * AND settled through the single settlement writer, so all three records agree.
 * Safe to re-run — invoices key off deployment_charge_id and the settlement is
 * idempotent.
 */
class BackfillDeploymentInvoices extends Command
{
    protected $signature = 'deployments:backfill-invoices {--dry-run : List what would change without writing}';

    protected $description = 'Generate home-side invoices for completed deployment charges that have none yet';

    public function handle(DeploymentChargeService $charges, DeploymentSettlementService $settlement): int
    {
        $dry = (bool) $this->option('dry-run');

        $eligible = DeploymentCharge::query()
            ->whereNotNull('invoiced_at') // locked / completed
            ->get()
            ->filter(fn (DeploymentCharge $c) => Invoice::query()->withoutGlobalScope(CompanyScope::class)
                ->where('deployment_charge_id', $c->id)->doesntExist());

        if ($eligible->isEmpty()) {
            $this->info('No deployment charges need an invoice — nothing to backfill.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '').'Charges needing an invoice: '.$eligible->count());

        foreach ($eligible as $charge) {
            $line = "  charge #{$charge->id} home={$charge->home_company_id} host={$charge->host_company_id} amount={$charge->amount} settlement={$charge->settlement_status}";
            if ($dry) {
                $this->line($line.'  → would generate invoice'.($charge->settlement_status === 'paid' ? ' (paid)' : ''));

                continue;
            }

            $charges->ensureHomeInvoice($charge);

            // Mirror an already-paid charge onto the fresh invoice + expense.
            if ($charge->settlement_status === 'paid') {
                $settlement->settle($charge, true, $charge->paid_by);
            }

            $invoice = Invoice::query()->withoutGlobalScope(CompanyScope::class)
                ->where('deployment_charge_id', $charge->id)->first();
            $this->line($line."  → {$invoice?->number}".($charge->settlement_status === 'paid' ? ' (marked paid)' : ''));
        }

        $this->info($dry ? 'Dry run complete — nothing written.' : 'Backfill complete.');

        return self::SUCCESS;
    }
}
