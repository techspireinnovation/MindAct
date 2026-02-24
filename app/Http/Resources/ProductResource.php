<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
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
            'note' => $this->note,
            'product_code' => $this->product_code,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'measure_unit_id' => $this->measure_unit_id,
            'is_vatable' => $this->is_vatable,
            'is_active' => $this->is_active,
            'product_type_id' => $this->product_type_id,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'product_lists' => ProductListResource::collection($this->whenLoaded('productLists')),
            

        ];
    }


}
