<?php

namespace App\Services\Autocomplete;

use Illuminate\Support\Facades\DB;

/**
 * Suggests previously-typed line-item descriptions so an admin does not retype
 * the same text. The pool is the company's invoice line items (sales AND
 * purchase) plus its expense line items — the same "description" semantic pool.
 *
 * Tenancy: the *_line_items tables carry no company_id, so isolation is the
 * JOIN to the company-owned parent (invoices / expenses) filtered by the acting
 * company. The company id comes from the caller (CurrentCompany), never from
 * request input. Only descriptions are returned — never amounts, ids, or names.
 */
class DescriptionAutocomplete
{
    public const MIN_CHARS = 2;

    public const MAX_RESULTS = 8;

    /**
     * @return list<string>
     */
    public function descriptions(string $query, int $companyId): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_CHARS) {
            return [];
        }

        // Escape LIKE wildcards so a literal % or _ typed by the admin does not
        // widen the match; default backslash escape works on MySQL + SQLite.
        $like = '%'.addcslashes($query, '\\%_').'%';

        $invoice = DB::table('invoice_line_items as li')
            ->join('invoices as i', 'i.id', '=', 'li.invoice_id')
            ->where('i.company_id', $companyId)
            ->whereNotNull('li.description')
            ->where('li.description', '<>', '')
            ->where('li.description', 'like', $like)
            ->groupBy('li.description')
            ->select('li.description', DB::raw('MAX(li.created_at) as used_at'));

        $expense = DB::table('expense_line_items as eli')
            ->join('expenses as e', 'e.id', '=', 'eli.expense_id')
            ->where('e.company_id', $companyId)
            ->whereNotNull('eli.description')
            ->where('eli.description', '<>', '')
            ->where('eli.description', 'like', $like)
            ->groupBy('eli.description')
            ->select('eli.description', DB::raw('MAX(eli.created_at) as used_at'));

        // Merge both pools in PHP (portable — no UNION quirks between drivers)
        // and collapse duplicate descriptions keeping the most recent use.
        $mostRecent = [];
        foreach ($invoice->get()->concat($expense->get()) as $row) {
            $description = (string) $row->description;
            $usedAt = (string) $row->used_at;
            if (! isset($mostRecent[$description]) || $usedAt > $mostRecent[$description]) {
                $mostRecent[$description] = $usedAt;
            }
        }

        // Most recently used first.
        arsort($mostRecent);

        return array_slice(array_keys($mostRecent), 0, self::MAX_RESULTS);
    }
}
