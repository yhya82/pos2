<?php

namespace App\Livewire\AuditLogs;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SRS Sec. 15.1: a read-only, filterable view over the append-only
 * audit_logs table — Administrator-only. No create/update/delete surface here
 * at all; every row already exists, written either by AuditLog::record() /
 * AuditLog::security() calls across the app or by the schema's own trg_audit_*
 * triggers on settings tables (Part E, Section 13e). The table itself is
 * append-only in the database (UPDATE/DELETE are blocked by triggers), so
 * not even an administrator can edit or remove history.
 *
 * Four views of the same trail: everything that happened (Activity), the
 * security events (Security: logins, failed logins, lockouts, denied
 * requests, ignored tampering), a per-person summary (Who did what), and
 * every stock movement.
 */
class AuditLogViewer extends Component
{
    use WithPagination, AuthorizesModuleActions;

    /** After this many in the period, a person is flagged for a look. */
    private const FLAG_FAILED_LOGINS = 5;

    private const FLAG_DENIED = 3;

    #[Url]
    public string $tab = 'activity';

    #[Url]
    public string $module = '';

    #[Url]
    public string $action = '';

    #[Url]
    public ?int $userId = null;

    #[Url]
    public string $recordType = '';

    #[Url]
    public string $movementType = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $period = '';

    public ?int $expandedId = null;

    public function mount(): void
    {
        $this->authorizeAction('audit_logs', 'view');
        // Strictly the Administrator role — not something a permission grid can hand out.
        abort_unless(auth()->user()->isAdministrator(), 403);

        if (! in_array($this->tab, ['activity', 'security', 'people', 'movements'], true)) {
            $this->tab = 'activity';
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['activity', 'security', 'people', 'movements'], true)) {
            return;
        }

        $this->tab = $tab;
        $this->expandedId = null;
        $this->resetPage();
    }

    public function setPeriod(string $period): void
    {
        $now = now();

        [$from, $to] = match ($period) {
            'day' => [$now->copy(), $now->copy()],
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [null, null],
        };

        if (! $from) {
            return;
        }

        $this->period = $period;
        $this->dateFrom = $from->toDateString();
        $this->dateTo = $to->toDateString();
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->period = '';
    }

    public function updatedDateTo(): void
    {
        $this->period = '';
    }

    public function updating($property): void
    {
        if (in_array($property, ['module', 'action', 'userId', 'recordType', 'movementType', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset(['module', 'action', 'userId', 'recordType', 'movementType', 'dateFrom', 'dateTo', 'period']);
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.audit-logs.audit-log-viewer', [
            'logs' => in_array($this->tab, ['activity', 'security'], true) ? $this->query()->paginate(20) : null,
            'people' => $this->tab === 'people' ? $this->peopleSummary() : null,
            'movements' => $this->tab === 'movements' ? $this->movementsQuery()->paginate(20) : null,
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::query()
                ->when($this->tab === 'security', fn ($q) => $q->where('module', 'security'))
                ->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'alerts' => $this->alerts(),
            'peopleFrom' => $this->peopleRange()[0],
            'flagFailedLogins' => self::FLAG_FAILED_LOGINS,
            'flagDenied' => self::FLAG_DENIED,
        ]);
    }

    private function query()
    {
        return AuditLog::with('user')
            ->when($this->tab === 'security', fn ($q) => $q->where('module', 'security'))
            ->when($this->module && $this->tab !== 'security', fn ($q) => $q->where('module', $this->module))
            ->when($this->action, fn ($q) => $q->where('action', $this->action))
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->when($this->recordType, fn ($q) => $q->where('record_type', 'like', "%{$this->recordType}%"))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * The last 24 hours of things worth a glance, plus who is locked out right
     * now — shown above every tab so an administrator sees trouble without
     * having to go looking for it.
     *
     * @return array{failed_logins: int, denied: int, tampering: int, locked: int}
     */
    private function alerts(): array
    {
        $since = now()->subDay();

        $counts = DB::table('audit_logs')
            ->where('module', 'security')
            ->where('created_at', '>=', $since)
            ->selectRaw("SUM(action IN ('login_failed','login_blocked','rate_limited')) AS failed_logins,
                SUM(action = 'access_denied') AS denied,
                SUM(action = 'tamper_ignored') AS tampering")
            ->first();

        return [
            'failed_logins' => (int) ($counts->failed_logins ?? 0),
            'denied' => (int) ($counts->denied ?? 0),
            'tampering' => (int) ($counts->tampering ?? 0),
            'locked' => User::where('locked_until', '>', now())->count(),
        ];
    }

    /**
     * The range the "Who did what" tab summarises: the chosen dates, or the
     * last seven days when none are chosen.
     *
     * @return array{0: string, 1: string}
     */
    private function peopleRange(): array
    {
        return [
            $this->dateFrom ?: now()->subDays(6)->toDateString(),
            $this->dateTo ?: now()->toDateString(),
        ];
    }

    private function peopleSummary()
    {
        [$from, $to] = $this->peopleRange();

        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->whereDate('a.created_at', '>=', $from)
            ->whereDate('a.created_at', '<=', $to)
            ->selectRaw("a.user_id,
                COALESCE(u.name, 'No account / system') AS name,
                r.name AS role,
                SUM(a.module <> 'security') AS actions,
                SUM(a.module = 'sales' AND a.action = 'create') AS sales,
                SUM(a.action = 'void') AS voids,
                SUM(a.module = 'returns' AND a.action = 'create') AS refunds,
                SUM(a.action = 'login') AS logins,
                SUM(a.action IN ('login_failed','login_blocked','rate_limited')) AS failed_logins,
                SUM(a.action = 'access_denied') AS denied,
                SUM(a.action = 'tamper_ignored') AS tampering,
                MAX(a.created_at) AS last_seen")
            ->groupBy('a.user_id', 'u.name', 'r.name')
            ->orderByRaw('(SUM(a.action = \'tamper_ignored\') * 100 + SUM(a.action = \'access_denied\') * 10 + SUM(a.action IN (\'login_failed\',\'login_blocked\',\'rate_limited\'))) DESC')
            ->orderByDesc('actions')
            ->get();
    }

    private function movementsQuery()
    {
        return DB::table('inventory_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('batches as b', 'b.id', '=', 'm.batch_id')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->when($this->movementType, fn ($q) => $q->where('m.movement_type', $this->movementType))
            ->when($this->userId, fn ($q) => $q->where('m.user_id', $this->userId))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('m.created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('m.created_at', '<=', $this->dateTo))
            ->selectRaw('m.id, m.created_at, p.name AS product_name, b.batch_code, b.id AS batch_id, m.movement_type, m.quantity, m.previous_qty, m.new_qty, m.reference_table, m.reference_id, m.reason, u.name AS user_name')
            ->orderByDesc('m.created_at')
            ->orderByDesc('m.id');
    }
}
