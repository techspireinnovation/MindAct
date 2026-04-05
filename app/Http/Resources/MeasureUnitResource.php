<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeasureUnitResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'base_unit_id' => $this->base_unit_id ?? null,
            'base_unit_name' => $this->baseUnit->name ?? null,
            'is_active' => $this->is_active,
            'is_primary' => $this->is_primary,
            'symbol' => $this->symbol,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

        ];
    }


}
