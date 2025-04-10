<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class CompanyController extends Controller
{
    // Display a listing
    public function index(): JsonResponse
    {
        return response()->json(Company::paginate(10));
    }

    // Store a new resource
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'licence_issue_date' => 'string|max:255',
            'working_date' => 'string|max:255',
            'reg_number' => 'string|max:255',
            'full_address' => 'string|max:255',
            'email_address' => 'string|max:255',
            'website' => 'string|max:255',
            'fax' => 'string|max:255',
            'logo' => 'string|max:255',
            'province' => 'string|max:255',
            'district' => 'string|max:255',
            'palika_name' => 'string|max:255',
            'ward_number' => 'string|max:255',
            'contact_number' => 'string|max:255',
            'contact_person' => 'string|max:255',
            'contact_person_position' => 'string|max:255',
            'agreement_holder_name' => 'string|max:255',
            'phone' => 'string|max:255',
            'position' => 'string|max:255',
            'license_number' => 'string|max:255',
            'activation_key' => 'string|max:255',
            'url_link' => 'string|max:255',
            'admin_email' => 'required|string|email|max:255|unique:users,email',
            'admin_name' => 'required|string|max:255',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $post = Company::create($validated);

        //create company admin
        $companyAdmin = User::create([
            'email' => $request->admin_email,
            'name' => $request->admin_name,
            'password' => Hash::make($request->password),
        ]);
        $role = Role::firstOrCreate(['name' => 'company_admin']);
        $companyAdmin->assignRole($role);

        return response()->json($post, 201);
    }

    // Show a single resource
    public function show(Company $post): JsonResponse
    {
        return response()->json($post);
    }

    // Update a resource
    public function update(Request $request, Company $post): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'body' => 'sometimes|required|string',
        ]);

        $post->update($validated);

        return response()->json($post);
    }

    // Delete a resource
    public function destroy(Company $post): JsonResponse
    {
        $post->delete();

        return response()->json(['message' => 'Company deleted']);
    }
}
