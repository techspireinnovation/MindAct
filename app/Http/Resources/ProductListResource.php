<?php

namespace App\Http\Resources;

use App\Models\MeasureUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {

    $quantity = MeasureUnit::where('id', $this->measure_unit_id)
        ->value('quantity');

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'measure_unit_id' => $this->measure_unit_id,
           
            'quantity' => $quantity,
            'barcode' => $this->barcode,
            'hs_code' => $this->category_id,
            'price' => $this->price,
            'discount' => $this->discount,
            'final_price' => $this->final_price,
            'is_primary' => $this->is_primary,
            'primary_measure_unit_id' => $this->primary_measure_unit_id,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            

        ];
    }


}
