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
            'location_id' => $this->location_id,
            'minimum_stock' => $this->minimum_stock,
            'brand_id' => $this->brand_id,
            'measure_unit_id' => $this->measure_unit_id,
            'is_vatable' => $this->is_vatable,
            'is_active' => $this->is_active,
            'product_type_id' => $this->product_type_id,
            'purchase_rate' => $this->purchase_rate,
            'purchase_rate_vat' => $this->purchase_rate_vat,
            'wholesale_price' => $this->wholesale_price,
            'retail_price' => $this->retail_price,
            'wholesale_price_vat' => $this->wholesale_price_vat,
            'retail_price_vat' => $this->retail_price_vat,
            'wholesale_profit_percent' => $this->wholesale_profit_percent,
            'retail_profit_percent' => $this->retail_profit_percent,
            'mrp_price' => $this->mrp_price,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'product_lists' => ProductListResource::collection($this->whenLoaded('productLists')),
            $this->mergeWhen(
                $this->product_field_number && config("product_fields.$this->product_field_number"),
                function () {
                    $field = config("product_fields.$this->product_field_number");

                    return [
                        'product_fields' => [
                            'key' => (string) $this->product_field_number, // 👈 ID (1,2,3)
                            'label' => $field['label'],                  // 👈 Bike, Computer
                            'fields' => $field['fields'],                // 👈 same fields
                        ]
                    ];
                }
            ),


        ];
    }


}
