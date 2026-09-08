<?php

namespace App\Http\Controllers;

use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class AnnouncementController extends Controller
{
    /**
     * An author may always act on their own announcement; reaching somebody
     * else's needs the explicit "Colleagues' Announcements" grant from
     * Role Management → Manage Access.
     */
    private function authorizeOwner(Announcement $announcement): void
    {
        if ($announcement->created_by === Auth::id()) {
            return;
        }

        abort_unless(Auth::user()->canManageOthersAnnouncements(), 403);
    }

    /**
     * The module gate — the By Page row. Every route here is behind it, so
     * "No Access" means the page refuses, not merely that the link is hidden.
     */
    private function authorizeView(): void
    {
        abort_unless(Auth::user()->canViewAnnouncements(), 403);
    }

    /** One capability row under announcements.actions.* — edit / delete. */
    private function authorizeAction(string $action): void
    {
        $this->authorizeView();

        abort_unless(Auth::user()->canDoAnnouncementAction($action), 403);
    }

    public function index()
    {
        $this->authorizeView();

        try {
            $query = Announcement::with('creator.employee')->orderByDesc('created_at');

            // Own announcements only, unless the "Colleagues' Announcements"
            // grant is set — the behaviour this page has always had.
            if (! Auth::user()->canManageOthersAnnouncements()) {
                $query->where('created_by', Auth::id());
            }

            $announcements = $query->paginate(20);
        } catch (\Throwable $e) {
            // Table may not exist on production yet — show empty state gracefully
            \Illuminate\Support\Facades\Log::error('Announcements table error: ' . $e->getMessage());
            $announcements = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 20);
        }

        return view('hr.announcements.index', compact('announcements'));
    }

    /**
     * AJAX feed for the dashboard announcements widget's in-widget pager.
     * Available to every authenticated user (NOT HR-gated) — scoped per-company
     * via visibleTo() so a user only ever sees announcements addressed to them.
     * Returns the same server-rendered markup the widget paints on first load.
     */
    public function feed(Request $request)
    {
        $company = Auth::user()->employee?->company;
        $page    = max(1, (int) $request->integer('page', 1));

        try {
            $announcements = Announcement::with('creator.employee')
                ->visibleTo($company)
                ->orderByDesc('created_at')
                ->paginate(5, ['*'], 'page', $page);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Announcement feed error: ' . $e->getMessage());
            $announcements = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 5, $page);
        }

        $html = view('partials.announcement-items', [
            'announcements' => $announcements,
            'isFirstPage'   => $announcements->currentPage() === 1,
        ])->render();

        return response()->json([
            'html'         => $html,
            'current_page' => $announcements->currentPage(),
            'last_page'    => $announcements->lastPage(),
            'total'        => $announcements->total(),
        ]);
    }

    public function create()
    {
        $this->authorizeView();
        abort_unless(Auth::user()->canPublishAnnouncement(), 403);

        $companies = Company::orderBy('name')->pluck('name');
        return view('hr.announcements.create', compact('companies'));
    }

    public function store(Request $request)
    {
        $this->authorizeView();
        abort_unless(Auth::user()->canPublishAnnouncement(), 403);

        $user = Auth::user();
        $request->validate($this->rules($user));

        $announcement = Announcement::create([
            'title'            => $request->title,
            'body'             => $user->canEditAnnouncementField('body') ? $request->body : null,
            'companies'        => $this->resolveCompanies($request, $user),
            'attachment_paths' => $this->storeUploads($request, $user) ?: null,
            'created_by'       => Auth::id(),
        ]);

        $this->sendNotifications($announcement);

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement published and notifications sent.');
    }

    public function edit(Announcement $announcement)
    {
        $this->authorizeAction('edit');
        $this->authorizeOwner($announcement);

        $companies = Company::orderBy('name')->pluck('name');
        return view('hr.announcements.edit', compact('announcement', 'companies'));
    }

    public function update(Request $request, Announcement $announcement)
    {
        $this->authorizeAction('edit');
        $this->authorizeOwner($announcement);

        $user = Auth::user();
        $request->validate($this->rules($user, isUpdate: true));

        // Every attribute below falls back to what is already stored when the
        // user may not edit that field. A value the form did not offer must not
        // be settable by a crafted POST — a hidden control is a courtesy, the
        // refusal here is the rule.
        $attributes = [
            'title'     => $user->canEditAnnouncementField('title') ? $request->title : $announcement->title,
            'body'      => $user->canEditAnnouncementField('body') ? $request->body : $announcement->body,
            'companies' => $this->resolveCompanies($request, $user, $announcement),
        ];

        if ($user->canEditAnnouncementField('attachments')) {
            // Handle existing attachment removals
            $kept = $request->input('keep_attachments', []);
            foreach ($announcement->attachment_paths ?? [] as $path) {
                if (! in_array($path, $kept)) {
                    Storage::disk('public')->delete($path);
                }
            }

            $mergedPaths = array_values(array_merge($kept, $this->storeUploads($request, $user)));
            $attributes['attachment_paths'] = $mergedPaths ?: null;
        }

        $announcement->update($attributes);

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement updated successfully.');
    }

    public function destroy(Announcement $announcement)
    {
        $this->authorizeAction('delete');
        $this->authorizeOwner($announcement);

        foreach ($announcement->attachment_paths ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $announcement->delete();

        return back()->with('success', 'Announcement deleted.');
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Validation rules for the fields this user may actually edit.
     *
     * A field they cannot edit carries no rule, because it is never read from
     * the request — requiring a value for a control the form did not render
     * would bounce the save over something the operator deliberately withheld.
     * `title` is always present on create, since canPublishAnnouncement()
     * already refuses when it is not editable.
     */
    private function rules(User $user, bool $isUpdate = false): array
    {
        $rules = [];

        if ($user->canEditAnnouncementField('title')) {
            $rules['title'] = 'required|string|max:255';
        }

        if ($user->canEditAnnouncementField('body')) {
            $rules['body'] = 'nullable|string|max:1000';
        }

        if ($user->canEditAnnouncementField('companies')) {
            $rules['companies'] = 'nullable|array';
            $rules['companies.*'] = 'string|max:255';
        }

        if ($user->canEditAnnouncementField('attachments')) {
            $rules['attachments'] = 'nullable|array|max:10';
            $rules['attachments.*'] = 'file|mimes:pdf,jpg,jpeg,png,gif,webp|max:10240|valid_file_content';

            if ($isUpdate) {
                $rules['keep_attachments'] = 'nullable|array';
                $rules['keep_attachments.*'] = 'nullable|string';
            }
        }

        return $rules;
    }

    /** Uploaded files, or none at all when the user may not attach any. */
    private function storeUploads(Request $request, User $user): array
    {
        if (! $user->canEditAnnouncementField('attachments') || ! $request->hasFile('attachments')) {
            return [];
        }

        $paths = [];
        foreach ($request->file('attachments') as $file) {
            if ($file && $file->isValid()) {
                $paths[] = $file->store('announcements', 'public');
            }
        }

        return $paths;
    }

    /**
     * Who the announcement is addressed to. A null column means EVERY company.
     *
     * Withholding "Target Companies" narrows a NEW announcement to the
     * publisher's own company rather than leaving it null — null is the widest
     * possible reach, so inheriting the blank-form default would hand somebody
     * more reach for having less permission, which is backwards. A publisher
     * with no company on record has nothing to narrow to and keeps the
     * group-wide default; the form states which of the two applies, so the
     * audience is never a surprise.
     *
     * On an EDIT the stored targeting is left exactly as it is: re-scoping
     * somebody else's audience as a side effect of a body correction would
     * silently change who an already-published notice reaches.
     */
    private function resolveCompanies(Request $request, User $user, ?Announcement $existing = null): ?array
    {
        if ($user->canEditAnnouncementField('companies')) {
            return $request->filled('companies') ? $request->companies : null;
        }

        if ($existing) {
            return $existing->companies;
        }

        $own = trim((string) ($user->employee?->company ?? ''));

        return $own === '' ? null : [$own];
    }

    private function sendNotifications(Announcement $announcement): void
    {
        $annId = $announcement->id;

        // Send after the HTTP response is returned to avoid gateway timeouts (504).
        app()->terminating(function () use ($annId) {
            $ann = Announcement::find($annId);
            if (!$ann) return;

            $query = Employee::whereNull('active_until')->whereNotNull('company_email');
            if (!empty($ann->companies)) {
                // Match tolerant of the Sdn Bhd / Sdn. Bhd. variant so targeted employees aren't
                // missed when their stored company differs only by the period (see Announcement).
                $targets = collect($ann->companies)
                    ->flatMap(fn ($c) => Announcement::companyNameVariants((string) $c))
                    ->unique()->values()->all();
                $query->whereIn('company', $targets);
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