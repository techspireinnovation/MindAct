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
            'barcode' => $this->barcode, 
            'hs_code' => $this->hs_code, 
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id,
            'measure_unit_id' => $this->measure_unit_id,
            'is_vatable' => $this->is_vatable,
            'is_active' => $this->is_active,
            'product_type_id' => $this->product_type_id,
            'price' => $this->price,
            'wholesale_price' => $this->wholesale_price,
            'retail_price' => $this->retail_price,
            'mrp_price' => $this->mrp_price,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'product_lists' => ProductListResource::collection($this->whenLoaded('productLists')),
            $this->mergeWhen(
                $this->product_field_number && config("product_fields.$this->product_field_number"),
                [
                    'product_fields' => config("product_fields.$this->product_field_number")
                ]
            ),


        ];
    }


}
