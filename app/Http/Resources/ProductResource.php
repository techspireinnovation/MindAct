<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Models\MeasureUnit;
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


        $unitIds = collect([$this->measure_unit_id]);

        $productListUnitIds = $this->productLists->pluck('measure_unit_id');
        $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();

        $measureUnits = MeasureUnit::whereIn('id', $allUnitIds)
            // ->where('company_id', $this->company_id) 
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'quantity'])
            ->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'measure_unit_quantity' => $unit->quantity ?? null,
                ];
            });

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
            'product_field_number' => $this->product_field_number,
            'brand_id' => $this->brand_id,
            'measure_unit_id' => $this->measure_unit_id,
            'is_vatable' => $this->is_vatable,
            'is_active' => $this->is_active,
            'product_type_id' => $this->product_type_id,
            'purchase_rate' => number_format($this->purchase_rate, 2, '.', ''),
            'purchase_rate_vat' => number_format($this->purchase_rate_vat, 2, '.', ''),
            'wholesale_price' => number_format($this->wholesale_price, 2, '.', ''),
            'retail_price' => number_format($this->retail_price, 2, '.', ''),
            'wholesale_price_vat' => number_format($this->wholesale_price_vat, 2, '.', ''),
            'retail_price_vat' => number_format($this->retail_price_vat, 2, '.', ''),
            'wholesale_profit_percent' => number_format($this->wholesale_profit_percent, 2, '.', ''),
            'retail_profit_percent' => number_format($this->retail_profit_percent, 2, '.', ''),
            'mrp_price' => number_format($this->mrp_price, 2, '.', ''),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'measure_units' => $measureUnits,
            'product_lists' => ProductListResource::collection($this->whenLoaded('productLists')),
            $this->mergeWhen(
                $this->product_field_number && config("product_fields.$this->product_field_number"),
                function () {
                    $field = config("product_fields.$this->product_field_number");

                    return [
                        'product_fields' => [
                            [
                                'key' => (string) $this->product_field_number,
                                'label' => $field['label'],
                                'fields' => $field['fields'],
                            ]
                        ]
                    ];
                }
            ),


        ];
    }


}
