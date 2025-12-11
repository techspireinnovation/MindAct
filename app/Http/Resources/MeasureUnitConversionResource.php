<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeasureUnitConversionResource extends JsonResource
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
            'product_id' => $this->product_id,
            'is_active' => $this->is_active,
            'from_unit_id' => $this->from_unit_id,
            'to_unit_id' => $this->to_unit_id,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

        ];
    }

    public function toResponse($request)
    {
        return response()->json([
            'success' => 'Measure Unit Conversion details received!',
            'data' => $this->toArray($request),
        ]);
    }
}
