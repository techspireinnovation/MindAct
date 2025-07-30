<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Validator;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Project::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }
    public function projectList(Request $request): JsonResponse
    {
        try {
            $projects = Project::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->get(['id', 'name'])
                ->map(fn($project) => ['id' => $project->id, 'name' => $project->name])
                ->values()
                ->toArray();

            return response()->json([
                "message" => "Project List Received !!",
                "data" => $projects
            ]);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Project not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function projectDetails(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $projectName = $request->project_name;
            $projectDetails = Project::where('company_id', $request->company_id)
                ->where('name', $projectName)
                ->whereNull('deleted_at')
                ->firstOrFail();

            return response()->json([
                "message" => "Project Details Received !!",
                "data" => $projectDetails
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Project not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Project::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('projects')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'nullable|boolean',
                'address' => 'nullable|string|max:255',
                'contact_person' => 'nullable|string|max:255',
                'starting_date' => 'nullable|string|max:255',
                'ending_date' => 'nullable|string|max:255',
                'budget' => 'nullable|numeric',
                'manager_name' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'company_id' => 'required|integer|exists:companies,id'
            ]);
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Bank::where('company_id', $item->company_id)
                    ->where('id', '!=', $id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            // Explicit boolean handling (optional, since validation ensures boolean)
            if ($request->has('is_active')) {
                $validated['is_active'] = (bool) $request->input('is_active');
            }
            if ($request->has('is_primary')) {
                $validated['is_primary'] = (bool) $request->input('is_primary');
            }


            $item->update($validated);
            $item->refresh();


            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('projects')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->input('company_id', $request->company_id))
                        ->whereNull('deleted_at');

                }),
            ],
            'is_active' => 'boolean|required',
            'is_primary' => 'nullable|boolean',
            'address' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'starting_date' => 'nullable|string|max:255',
            'ending_date' => 'nullable|string|max:255',
            'budget' => 'nullable|numeric',
            'manager_name' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:255',
            'company_id' => 'required|integer|exists:companies,id'
        ]);

        if (!empty($validated['is_primary'])) {
            Project::where('company_id', $validated['company_id'])
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $validated['is_primary'] = $validated['is_primary'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $item = Project::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Project::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Project::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Project deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}
