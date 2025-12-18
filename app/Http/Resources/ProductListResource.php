<?php

namespace App\Http\Resources;

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

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'measure_unit_id' => $this->measure_unit_id,
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
