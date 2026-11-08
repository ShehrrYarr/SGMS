<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Period + branch filters shared by the report pages (kept in the URL so a
 * report can be bookmarked or shared).
 */
trait WithReportFilters
{
    #[Url(except: 'this_month')]
    public string $range = 'this_month';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** '' = current working scope, 'all' = every branch the user may see, or a branch id. */
    #[Url(except: '')]
    public string $branch = '';

    /** Route of the report page (Livewire updates run on a different route). */
    #[Locked]
    public string $reportRoute = '';

    public function mountWithReportFilters(): void
    {
        $this->reportRoute = (string) request()->route()?->getName();
    }

    public static function ranges(): array
    {
        return [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'last_7' => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_quarter' => 'This quarter',
            'this_year' => 'This year',
            'last_year' => 'Last year',
            'custom' => 'Custom range',
        ];
    }

    public function updatedRange(): void
    {
        if ($this->range === 'custom') {
            [$from, $to] = $this->period();
            $this->from = $from->toDateString();
            $this->to = $to->toDateString();
        } else {
            $this->from = $this->to = '';
        }
    }

    public function updatedFrom(): void
    {
        $this->range = 'custom';
    }

    public function updatedTo(): void
    {
        $this->range = 'custom';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function period(): array
    {
        $today = today();

        [$from, $to] = match ($this->range) {
            'today' => [$today, $today],
            'yesterday' => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_7' => [$today->copy()->subDays(6), $today],
            'last_30' => [$today->copy()->subDays(29), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$today->copy()->startOfQuarter(), $today],
            'this_year' => [$today->copy()->startOfYear(), $today],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
            'custom' => [$this->parseDate($this->from) ?? $today->copy()->startOfMonth(), $this->parseDate($this->to) ?? $today],
            default => [$today->copy()->startOfMonth(), $today],
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        // Cap very long custom ranges to keep reports fast.
        if ($from->diffInDays($to) > 1830) {
            $from = $to->copy()->subDays(1830);
        }

        return [$from->copy()->startOfDay(), $to->copy()->startOfDay()];
    }

    /** The period of equal length immediately before the selected one. */
    protected function previousPeriod(): array
    {
        [$from, $to] = $this->period();
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    protected function periodLabel(): string
    {
        [$from, $to] = $this->period();

        return $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y');
    }

    protected function reportBranchIds(): array
    {
        $context = tenancy();

        return match (true) {
            $this->branch === 'all' => $context->allowedBranchIds(),
            $this->branch !== '' && $context->canAccessBranch((int) $this->branch) => [(int) $this->branch],
            default => $context->branchScopeIds(),
        };
    }

    protected function reportBranchLabel(): string
    {
        $ids = $this->reportBranchIds();
        $branches = tenancy()->branches();

        return count($ids) === 1 ? ($branches->firstWhere('id', $ids[0])?->name ?? 'Branch') : 'All branches';
    }

    protected function filterViewData(): array
    {
        $context = tenancy();

        return [
            'ranges' => static::ranges(),
            'branchOptions' => $context->branches()->whereIn('id', $context->allowedBranchIds())->values(),
            'periodLabel' => $this->periodLabel(),
            'branchLabel' => $this->reportBranchLabel(),
        ];
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return $value !== '' ? Carbon::parse($value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
