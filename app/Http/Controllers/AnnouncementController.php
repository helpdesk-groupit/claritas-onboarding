<?php

namespace App\Http\Controllers;

use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    private function authorizeOwner(Announcement $announcement): void
    {
        if ($announcement->created_by !== Auth::id()) {
            abort(403);
        }
    }

    private function authorizeHrManager(): void
    {
        $u = Auth::user();
        $isManager = $u->employee?->work_role === 'manager';
        if (
            !$u->isHrManager() &&
            !$u->isSuperadmin() &&
            !$u->isSystemAdmin() &&
            !$u->isItManager() &&
            !$isManager
        ) {
            abort(403);
        }
    }

    public function index()
    {
        $this->authorizeHrManager();

        try {
            $announcements = Announcement::with('creator')
                ->where('created_by', Auth::id())
                ->orderByDesc('created_at')
                ->paginate(20);
        } catch (\Throwable $e) {
            // Table may not exist on production yet — show empty state gracefully
            \Illuminate\Support\Facades\Log::error('Announcements table error: ' . $e->getMessage());
            $announcements = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        }

        return view('hr.announcements.index', compact('announcements'));
    }

    public function create()
    {
        $this->authorizeHrManager();

        $companies = Company::orderBy('name')->pluck('name');
        return view('hr.announcements.create', compact('companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeHrManager();

        $request->validate([
            'title'         => 'required|string|max:255',
            'body'          => 'nullable|string|max:500',
            'companies'     => 'nullable|array',
            'companies.*'   => 'string|max:255',
            'attachments'   => 'nullable|array|max:10',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png,gif,webp|max:10240',
        ]);

        // Store attachments
        $paths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                if ($file && $file->isValid()) {
                    $paths[] = $file->store('announcements', 'public');
                }
            }
        }

        // null companies = all companies
        $companies = $request->filled('companies') ? $request->companies : null;

        $announcement = Announcement::create([
            'title'            => $request->title,
            'body'             => $request->body,
            'companies'        => $companies,
            'attachment_paths' => !empty($paths) ? $paths : null,
            'created_by'       => Auth::id(),
        ]);

        $this->sendNotifications($announcement);

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement published and notifications sent.');
    }

    public function edit(Announcement $announcement)
    {
        $this->authorizeHrManager();
        $this->authorizeOwner($announcement);

        $companies = Company::orderBy('name')->pluck('name');
        return view('hr.announcements.edit', compact('announcement', 'companies'));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->authorizeHrManager();
        $this->authorizeOwner($announcement);

        $request->validate([
            'title'               => 'required|string|max:255',
            'body'                => 'nullable|string|max:500',
            'companies'           => 'nullable|array',
            'companies.*'         => 'string|max:255',
            'attachments'         => 'nullable|array|max:10',
            'attachments.*'       => 'file|mimes:pdf,jpg,jpeg,png,gif,webp|max:10240',
            'keep_attachments'    => 'nullable|array',
            'keep_attachments.*'  => 'nullable|string',
        ]);

        // Handle existing attachment removals
        $kept = $request->input('keep_attachments', []);
        foreach ($announcement->attachment_paths ?? [] as $path) {
            if (!in_array($path, $kept)) {
                Storage::disk('public')->delete($path);
            }
        }

        // Store new attachments
        $newPaths = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                if ($file && $file->isValid()) {
                    $newPaths[] = $file->store('announcements', 'public');
                }
            }
        }

        $mergedPaths = array_values(array_merge($kept, $newPaths));
        $companies   = $request->filled('companies') ? $request->companies : null;

        $announcement->update([
            'title'            => $request->title,
            'body'             => $request->body,
            'companies'        => $companies,
            'attachment_paths' => !empty($mergedPaths) ? $mergedPaths : null,
        ]);

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement updated successfully.');
    }

    public function destroy(Announcement $announcement)
    {
        $this->authorizeHrManager();
        $this->authorizeOwner($announcement);

        foreach ($announcement->attachment_paths ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $announcement->delete();

        return back()->with('success', 'Announcement deleted.');
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function sendNotifications(Announcement $announcement): void
    {
        $annId = $announcement->id;

        // Send after the HTTP response is returned to avoid gateway timeouts (504).
        app()->terminating(function () use ($annId) {
            $ann = Announcement::find($annId);
            if (!$ann) return;

            $query = Employee::whereNull('active_until')->whereNotNull('company_email');
            if (!empty($ann->companies)) {
                $query->whereIn('company', $ann->companies);
            }

            foreach ($query->get() as $employee) {
                try {
                    Mail::to($employee->company_email)
                        ->send(new AnnouncementMail($ann, $employee));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Announcement email failed for employee #{$employee->id}: " . $e->getMessage()
                    );
                }
            }
        });
    }
}