<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartyResource extends JsonResource
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
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'type' => $this->type,
            'bank_account_number' => $this->bank_account_number,
            'bank_id' => $this->bank_id,
            'area' => $this->area,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'contact_person_phone' => $this->contact_person_phone,
            'contact_person' => $this->contact_person,
            
            
            'pan_number' => $this->pan_number,
            'vdc_municipality' => $this->vdc_municipality,
            'district' => $this->district,
            'opening_balance' => $this->opening_balance,
            'billing_address' => $this->billing_address,
            'is_active' => $this->is_active,
            
           
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),

        ];
    }

    public function toResponse($request)
    {
        return response()->json([
            'success' => 'Party details received!',
            'data' => $this->toArray($request),
        ]);
    }
}
