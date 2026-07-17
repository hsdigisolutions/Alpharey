<?php

namespace App\Http\Requests;

use App\Enums\EquipmentItemType;
use App\Models\EquipmentCategory;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEquipmentItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->routeIs('inventory.items.store')
            ? Gate::allows('inventory.create')
            : Gate::allows('inventory.edit');
    }

    /**
     * total_stock / available_stock are absent on purpose: the movement ledger
     * owns them (see StockMovementService). `opening_stock` is accepted
     * instead, and is recorded as a stock_in movement.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->route('item');

        return [
            'name' => ['required', 'string', 'max:150'],
            'sku' => [
                'required', 'string', 'max:60',
                Rule::unique('equipment_items', 'sku')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($item?->id),
            ],
            'equipment_category_id' => ['nullable', 'integer'],
            'item_type' => ['required', Rule::enum(EquipmentItemType::class)],
            'unit' => ['required', 'string', 'max:20'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('equipment_category_id')) {
                return;
            }

            // Either a shared default or this company's own — never another
            // company's category.
            $exists = EquipmentCategory::query()
                ->forCompany(app(CurrentCompany::class)->id())
                ->whereKey($this->integer('equipment_category_id'))
                ->exists();

            if (! $exists) {
                $validator->errors()->add('equipment_category_id', __('ui.inventory.category_not_found'));
            }
        });
    }
}
