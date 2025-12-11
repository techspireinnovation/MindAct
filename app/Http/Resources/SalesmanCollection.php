<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SalesmanCollection extends ResourceCollection
{
    public function toArray(Request $request): array
    {
        return [
            'data' => SalesmanResource::collection($this->collection),
        ];
    }

    public function with($request): array
    {
        // $this->resource is the original resource passed (the paginator)
        if ($this->resource instanceof AbstractPaginator) {
            $paginator = $this->resource;

            return [
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'last'  => $paginator->url($paginator->lastPage()),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                ],
            ];
        }

        return [];
    }

    public function toResponse($request)
    {
        $response = [
            'success' => 'Salesmen List',
            'data'    => $this->toArray($request)['data'],
        ];

        $additional = $this->with($request);
        if (!empty($additional)) {
            $response = array_merge($response, $additional);
        }

        return response()->json($response);
    }
}