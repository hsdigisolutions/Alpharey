<?php

namespace App\Exports;

use App\Models\ProductionTask;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Exports the CURRENT FILTERED VIEW of the production-tasks screen — driven by
 * the same query the list shows. Quantities are internal tracking, never client
 * billing (the sheet carries no wage or client-rate data).
 *
 * @implements WithMapping<ProductionTask>
 */
class ProductionTasksExport implements FromQuery, WithHeadings, WithMapping
{
    /**
     * @param  Builder<ProductionTask>  $query
     */
    public function __construct(private Builder $query) {}

    /**
     * @return Builder<ProductionTask>
     */
    public function query(): Builder
    {
        return $this->query->with('project:id,name,code');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Tarea / Task',
            'Obra / Project',
            'Categoría / Category',
            'Nº casa / House no.',
            'Previsto / Planned',
            'Hecho / Done',
            'Unidad / Unit',
            'Progreso % / Progress %',
            'Ponderación % / Weightage %',
            'Estado / Status',
        ];
    }

    /**
     * @param  ProductionTask  $task
     * @return list<string|float|null>
     */
    public function map($task): array
    {
        return [
            $task->name,
            $task->project?->name,
            $task->category->value,
            $task->house_number,
            (float) $task->planned_quantity,
            (float) $task->completed_quantity,
            $task->unit,
            $task->progressPercent(),
            (float) $task->weightage,
            $task->status->value,
        ];
    }
}
