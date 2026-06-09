<?php

namespace App\Http\Controllers;

use App\Models\ItTask;
use App\Models\Onboarding;
use App\Models\Offboarding;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ItTaskController extends Controller
{
    // ── My tasks page ─────────────────────────────────────────────────────
    public function index()
    {
        $user  = Auth::user();
        if (!$user->isIt() && !$user->isSuperadmin()) abort(403);

        $tasks = ItTask::with([
                'onboarding.personalDetail', 'onboarding.workDetail',
                'offboarding',
                'assignedBy',
            ])
            ->where('assigned_to', $user->id)
            ->orderByRaw("FIELD(status, 'in_progress', 'pending', 'done')")
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('it.tasks', compact('tasks'));
    }

    // ── Update my task status ─────────────────────────────────────────────
    public function updateStatus(Request $request, ItTask $task)
    {
        $user = Auth::user();
        if ($task->assigned_to !== $user->id && !$user->isItManager() && !$user->isSuperadmin()) {
            abort(403);
        }

        $request->validate(['status' => 'required|in:pending,in_progress,done']);

        $task->update([
            'status'       => $request->status,
            'completed_at' => $request->status === 'done' ? now() : null,
        ]);

        // Sync to onboarding OR offboarding depending on task context
        if ($task->onboarding_id) {
            $task->syncToOnboarding();
        }
        if ($task->offboarding_id) {
            $task->syncToOffboarding();
        }

        return back()->with('success', 'Task status updated.');
    }

    // ── IT Manager assigns PIC to an onboarding ───────────────────────────
    public function assignPic(Request $request, Onboarding $onboarding)
    {
        $user = Auth::user();
        if (!$user->isItManager() && !$user->isSuperadmin()) abort(403);

        $picUserId = $request->input('assigned_pic_user_id');

        // Remove PIC — clear tasks and PIC assignment, reset statuses
        if (!$picUserId) {
            DB::transaction(function () use ($onboarding) {
                ItTask::where('onboarding_id', $onboarding->id)
                    ->whereIn('task_type', ['asset_preparation', 'work_email'])
                    ->delete();
                $onboarding->update([
                    'assigned_pic_user_id'     => null,
                    'asset_preparation_status' => 'pending',
                    'work_email_status'        => 'pending',
                ]);
            });
            return back()->with('success', 'PIC removed.');
        }

        $request->validate(['assigned_pic_user_id' => 'required|exists:users,id']);

        $taskDefs = [
            ['type' => 'asset_preparation', 'title' => 'Asset Preparation — ' . ($onboarding->personalDetail?->full_name ?? 'New Hire')],
            ['type' => 'work_email',        'title' => 'Work Email / Google ID Setup — ' . ($onboarding->personalDetail?->full_name ?? 'New Hire')],
        ];

        // Atomic + idempotent: updateOrCreate keyed on (onboarding_id, task_type)
        // so a double-submit / race can't produce duplicate task rows. The unique
        // index on (onboarding_id, task_type) is the DB-level backstop.
        DB::transaction(function () use ($onboarding, $picUserId, $user, $taskDefs) {
            $onboarding->update([
                'assigned_pic_user_id'     => $picUserId,
                'asset_preparation_status' => 'pending',
                'work_email_status'        => 'pending',
            ]);

            foreach ($taskDefs as $def) {
                ItTask::updateOrCreate(
                    ['onboarding_id' => $onboarding->id, 'task_type' => $def['type']],
                    [
                        'assigned_to'  => $picUserId,
                        'assigned_by'  => $user->id,
                        'title'        => $def['title'],
                        'status'       => 'pending',
                        'completed_at' => null,
                    ]
                );
            }
        });

        return back()->with('success', 'PIC assigned and tasks created.');
    }

    // ── IT Manager assigns PIC to an offboarding ─────────────────────────
    public function assignOffboardingPic(Request $request, Offboarding $offboarding)
    {
        $user = Auth::user();
        if (!$user->isItManager() && !$user->isSuperadmin()) abort(403);

        $picUserId = $request->input('assigned_pic_user_id');

        // Remove PIC — clear tasks and PIC assignment, reset statuses
        if (!$picUserId) {
            DB::transaction(function () use ($offboarding) {
                ItTask::where('offboarding_id', $offboarding->id)
                    ->whereIn('task_type', ['asset_cleaning', 'deactivation'])
                    ->delete();
                $offboarding->update([
                    'assigned_pic_user_id'  => null,
                    'asset_cleaning_status' => 'pending',
                    'deactivation_status'   => 'pending',
                ]);
            });
            return back()->with('success', 'PIC removed.');
        }

        $request->validate(['assigned_pic_user_id' => 'required|exists:users,id']);

        $empName = $offboarding->full_name ?? 'Employee';

        $taskDefs = [
            ['type' => 'asset_cleaning', 'title' => 'Asset Retrieval & Cleaning — ' . $empName],
            ['type' => 'deactivation',   'title' => 'Work Email/GID Deactivation — ' . $empName],
        ];

        // Atomic + idempotent: updateOrCreate keyed on (offboarding_id, task_type)
        // so a double-submit / race can't produce duplicate task rows. The unique
        // index on (offboarding_id, task_type) is the DB-level backstop.
        DB::transaction(function () use ($offboarding, $picUserId, $user, $taskDefs) {
            $offboarding->update([
                'assigned_pic_user_id'  => $picUserId,
                'asset_cleaning_status' => 'pending',
                'deactivation_status'   => 'pending',
            ]);

            foreach ($taskDefs as $def) {
                ItTask::updateOrCreate(
                    ['offboarding_id' => $offboarding->id, 'task_type' => $def['type']],
                    [
                        'onboarding_id' => null,
                        'assigned_to'   => $picUserId,
                        'assigned_by'   => $user->id,
                        'title'         => $def['title'],
                        'status'        => 'pending',
                        'completed_at'  => null,
                    ]
                );
            }
        });

        return back()->with('success', 'PIC assigned and offboarding tasks created.');
    }

    // ── Re-assign or update task by IT Manager ────────────────────────────
    public function reassign(Request $request, ItTask $task)
    {
        $user = Auth::user();
        if (!$user->isItManager() && !$user->isSuperadmin()) abort(403);

        $request->validate(['assigned_to' => 'required|exists:users,id']);
        $task->update(['assigned_to' => $request->assigned_to, 'status' => 'pending', 'completed_at' => null]);

        return back()->with('success', 'Task re-assigned.');
    }
}