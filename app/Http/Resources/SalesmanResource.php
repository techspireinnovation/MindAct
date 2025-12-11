<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesmanResource extends JsonResource
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
            'mobile' => $this->mobile,
            'salesman_code' => $this->salesman_code,
            'pan_number' => $this->pan_number,
            'address' => $this->address,
            'working_office' => $this->working_office,
            'joining_date' => $this->joining_date,
            'designation' => $this->designation,
            'dob' => $this->dob,
            'area' => $this->area,
            'ward_no' => $this->ward_no,
            'state' => $this->state,
            'country' => $this->country,
            'citizenship_number' => $this->citizenship_number,
            'nationality' => $this->nationality,
            'zone' => $this->zone,
            'district' => $this->district,
            'vdc_municipality' => $this->vdc_municipality,
            'is_active' => $this->is_active,
            'is_primary' => $this->is_primary,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

        ];
    }

    public function toResponse($request)
    {
        return response()->json([
            'success' => 'Salesman details received!',
            'data' => $this->toArray($request),
        ]);
    }
}
