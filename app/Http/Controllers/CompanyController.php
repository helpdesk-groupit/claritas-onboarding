<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class CompanyController extends Controller
{
    private function authorizeSuperadmin(): void
    {
        if (!Auth::user()->isSuperadmin()) abort(403);
    }

    public function index()
    {
        $this->authorizeSuperadmin();
        $companies = Company::orderBy('name')->paginate(20);
        return view('superadmin.companies', compact('companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeSuperadmin();

        $request->validate([
            'name'                => 'required|string|max:255|unique:companies,name',
            'address'             => 'nullable|string|max:1000',
            'registration_number' => 'nullable|string|max:100',
            'logo'                => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        ]);

        $data = $request->only('name', 'address', 'registration_number');

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }

        Company::create($data);

        return back()->with('success', 'Company "' . $request->name . '" registered successfully.');
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeSuperadmin();

        $request->validate([
            'name'                => 'required|string|max:255|unique:companies,name,' . $company->id,
            'address'             => 'nullable|string|max:1000',
            'registration_number' => 'nullable|string|max:100',
            'logo'                => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048',
        ]);

        $data = $request->only('name', 'address', 'registration_number');

        if ($request->hasFile('logo')) {
            // Delete old logo if present
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }

        $company->update($data);

        return back()->with('success', 'Company updated successfully.');
    }

    public function destroy(Company $company)
    {
        $this->authorizeSuperadmin();
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }
        $company->delete();
        return back()->with('success', 'Company "' . $company->name . '" removed.');
    }
}