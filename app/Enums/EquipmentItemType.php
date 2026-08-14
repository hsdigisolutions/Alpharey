<?php

namespace App\Enums;

enum EquipmentItemType: string
{
    case Safety = 'safety';
    case Tool = 'tool';
    case Machine = 'machine';
    // A consumable (cement, paint, screws) — quantity-tracked, used up via a
    // Usage movement, never issued to a worker or assigned to a project.
    case Consumable = 'consumable';

    public function isConsumable(): bool
    {
        return $this === self::Consumable;
    }
}
