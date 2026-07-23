<?php

namespace App\Livewire\AuditLogs;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SRS Sec. 15.1: a read-only, filterable view over the append-only
 * audit_logs table — Administrator-only (the only role with audit_logs,view
 * in the permission catalog). No create/update/delete surface here at all;
 * every row already exists, written either by AuditLog::record() calls
 * across the app or by the schema's own trg_audit_* triggers on settings
 * tables (Part E, Section 13e).
 */
class AuditLogViewer extends Component
{
    use WithPagination, AuthorizesModuleActions;

    #[Url]
    public string $module = '';

    #[Url]
    public string $action = '';

    #[Url]
    public ?int $userId = null;

    #[Url]
    public string $recordType = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $period = '';

    public ?int $expandedId = null;

    public function mount(): void
    {
        $this->authorizeAction('audit_logs', 'view');
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
        if (in_array($property, ['module', 'action', 'userId', 'recordType', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function clearFilters(): void
    {
        $this->reset(['module', 'action', 'userId', 'recordType', 'dateFrom', 'dateTo', 'period']);
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.audit-logs.audit-log-viewer', [
            'logs' => $this->query()->paginate(20),
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function query()
    {
        return AuditLog::with('user')
            ->when($this->module, fn ($q) => $q->where('module', $this->module))
            ->when($this->action, fn ($q) => $q->where('action', $this->action))
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->when($this->recordType, fn ($q) => $q->where('record_type', 'like', "%{$this->recordType}%"))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at');
    }
}
