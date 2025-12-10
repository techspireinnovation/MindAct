<?php

namespace App\Http\Controllers;
use App\Http\Resources\BrandCollection;
use App\Http\Resources\BrandResource;
use App\Interfaces\BrandRepositoryInterface;
use App\Http\Requests\BrandRequest\StoreRequest;
use App\Http\Requests\BrandRequest\UpdateRequest;
use App\Models\Brand;
use App\Repositories\BrandRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Sagautam5\LocalStateNepal\Entities\Province;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;



class BrandController extends Controller
{

    protected $repository;

    public function __construct(BrandRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(Request $request)
    {
        try {
            $filters = $request->only('keywords');
            $brands = $this->repository->list($filters);


            return new BrandCollection($brands);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred !!'
            ], 500);
        }
    }



    public function brandDetails(Request $request)
    {
        try {

            $brandName = $request->brand_name;
            $brandDetails = $this->repository->brandDetails($brandName);
            return new BrandResource($brandDetails);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Brand not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function getListProvice()
    {
        $province = new Province('np');
        $provincesData = $province->allProvinces();
        return response()->json($provincesData);

    }

    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $item = $this->repository->update($id, $validated);

            return response()->json($item, 200);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function store(StoreRequest $request): JsonResponse
    {

        $validated = $request->validated();




        $item = $this->repository->create($validated);
        return response()->json($item, 201);
    }

    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return new BrandResource($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Brand deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'true',
                'message' => 'Brand not found!'
            ], 404);
        } catch (QueryException $e) {
            \Log::error($e->getMessage());
            return response()->json([
                'error' => 'query_error',
                'message' => $e->getMessage()
            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the brand.'
            ], 500);
        }
    }

    public function activeBrandList(Request $request)
    {
        try {
            $brands = $this->repository->activeBrandList();
            return BrandResource::collection($brands)
                ->map(fn($resource) => [
                    'id' => $resource->id,
                    'name' => $resource->name,
                ]);


        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Brand not Found !"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }




}
