<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use App\Http\Resources\ProductCategoryResource;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProductCategoryCollection extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => ProductCategoryResource::collection($this->collection),
        ];
    }


    public function with($request)
    {
      
        if ($this->resource instanceof AbstractPaginator) {
            return [
                'meta' => [
                    'current_page' => $this->currentPage(),
                    'per_page' => $this->perPage(),
                    'total' => $this->total(),
                    'last_page' => $this->lastPage(),
                ],
                'links' => [
                    'first' => $this->url(1),
                    'last' => $this->url($this->lastPage()),
                    'prev' => $this->previousPageUrl(),
                    'next' => $this->nextPageUrl(),
                ],
            ];
        }


        return [];
    }
    public function toResponse($request)
    {
        $response = [
            'success' => 'Product Category List',
            'data' => $this->toArray($request)['data'],
        ];

        $with = $this->with($request);
        if (!empty($with)) {
            $response = array_merge($response, $with);
        }

        return response()->json($response);
    }
}
