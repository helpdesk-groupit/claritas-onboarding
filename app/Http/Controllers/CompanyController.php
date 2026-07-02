<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    private function authorizeSuperadmin(): void
    {
        if (! Auth::user()->isSuperadmin() && ! Auth::user()->isHrManager() && ! Auth::user()->isHrExecutive()) {
            abort(403);
        }
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
            'name' => 'required|string|max:255|unique:companies,name',
            'company_group' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:1000',
            'registration_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'kwsp_number' => 'nullable|string|max:100',
            'tin_number' => 'nullable|string|max:100',
            'socso_number' => 'nullable|string|max:100',
            'eis_number' => 'nullable|string|max:100',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048|valid_file_content',
        ]);

        $data = $request->only('name', 'company_group', 'address', 'registration_number', 'phone', 'kwsp_number', 'tin_number', 'socso_number', 'eis_number');

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }

        Company::create($data);

        return back()->with('success', 'Company "'.$request->name.'" registered successfully.');
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeSuperadmin();

        $request->validate([
            'name' => 'required|string|max:255|unique:companies,name,'.$company->id,
            'company_group' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:1000',
            'registration_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'kwsp_number' => 'nullable|string|max:100',
            'tin_number' => 'nullable|string|max:100',
            'socso_number' => 'nullable|string|max:100',
            'eis_number' => 'nullable|string|max:100',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp,svg|max:2048|valid_file_content',
        ]);

        $data = $request->only('name', 'company_group', 'address', 'registration_number', 'phone', 'kwsp_number', 'tin_number', 'socso_number', 'eis_number');

        if ($request->hasFile('logo')) {
            // Delete old logo if present
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }

        $oldName = $company->name;
        $oldAddress = $company->address;
        $company->update($data);

        // Cascade a rename onto employees so their company stays exactly the registered name
        // (the source of truth) — otherwise the two drift apart and company-scoped features
        // (announcements, tickets, claims) stop matching.
        if ($oldName !== $company->name) {
            \App\Models\Employee::where('company', $oldName)->update(['company' => $company->name]);
        }

        // Cascade an address change onto employees who were using the company's previous
        // default address (office_location is a per-employee copy taken at registration).
        // Only rows that still match the OLD address are updated, so a staff member with a
        // custom office (e.g. a branch or remote address) is left untouched.
        if (! empty($oldAddress) && $oldAddress !== $company->address) {
            \App\Models\Employee::where('company', $company->name)
                ->where('office_location', $oldAddress)
                ->update(['office_location' => $company->address]);
        }

        return back()->with('success', 'Company updated successfully.');
    }

    public function destroy(Company $company)
    {
        $this->authorizeSuperadmin();
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
        }
        $company->delete();

        return back()->with('success', 'Company "'.$company->name.'" removed.');
    }
}
