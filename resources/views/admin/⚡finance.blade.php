<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new #[Layout('layouts.app')] // Ensure this matches your admin layout file
#[Title('Finance Overview')]
class extends Component
{
    use WithPagination;

    public $search = '';
    public $filter = 'month'; // Options: today, week, month, year, all, custom
    public $customStart = '';
    public $customEnd = '';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingFilter()
    {
        $this->resetPage();
    }

    public function setFilter($range)
    {
        $this->filter = $range;
        $this->resetPage();
    }

    // Centralized date filter query modifier
    private function applyDateFilter($query)
    {
        $now = now();

        switch ($this->filter) {
            case 'today':
                $query->whereDate('created_at', $now->today());
                break;
            case 'week':
                $query->whereBetween('created_at', [$now->startOfWeek(), $now->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('created_at', $now->month)->whereYear('created_at', $now->year);
                break;
            case 'year':
                $query->whereYear('created_at', $now->year);
                break;
            case 'custom':
                if ($this->customStart && $this->customEnd) {
                    $query->whereBetween('created_at', [
                        Carbon::parse($this->customStart)->startOfDay(),
                        Carbon::parse($this->customEnd)->endOfDay()
                    ]);
                }
                break;
            case 'all':
            default:
                // No date filter
                break;
        }

        return $query;
    }

    #[Computed]
    public function metrics()
    {
        // Change 'mpesa_transactions' to your exact table name if different
        $query = DB::table('mpesa_transactions');

        // Example: if you only want to sum successful transactions, uncomment below:
        // $query->where('status', 'Completed');

        return [
            'today' => (clone $query)->whereDate('created_at', now()->today())->sum('amount'),
            'week'  => (clone $query)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->sum('amount'),
            'month' => (clone $query)->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('amount'),
            'all'   => (clone $query)->sum('amount'),
        ];
    }

    #[Computed]
    public function chartData()
    {
        $query = DB::table('mpesa_transactions')
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total');

        // Apply the same date filter to the chart
        $query = $this->applyDateFilter($query);

        $results = $query->groupBy('date')->orderBy('date', 'asc')->get();

        return [
            'labels' => $results->pluck('date')->map(fn($date) => Carbon::parse($date)->format('M d'))->toArray(),
            'data'   => $results->pluck('total')->toArray(),
        ];
    }

    #[Computed]
    public function transactions()
    {
        $query = DB::table('mpesa_transactions');

        if ($this->search) {
            $query->where(function($q) {
                $q->where('receipt_number', 'like', '%' . $this->search . '%')
                  ->orWhere('phone_number', 'like', '%' . $this->search . '%');
            });
        }

        $query = $this->applyDateFilter($query);

        return $query->orderBy('created_at', 'desc')->paginate(15);
    }
};
?>

<div class="min-h-screen  text-white space-y-8"
     x-data="{
        chartInstance: null,
        initChart() {
            // Load Chart.js dynamically if not present
            if (typeof Chart === 'undefined') {
                let script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
                script.onload = () => this.renderChart();
                document.head.appendChild(script);
            } else {
                this.renderChart();
            }
        },
        renderChart() {
            const ctx = document.getElementById('incomeChart').getContext('2d');
            const data = @js($this->chartData);

            if(this.chartInstance) {
                this.chartInstance.destroy();
            }

            this.chartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels.length ? data.labels : ['No Data'],
                    datasets: [{
                        label: 'Income (KES)',
                        data: data.data.length ? data.data : [0],
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#dc2626',
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#94a3b8' }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#94a3b8' }
                        }
                    }
                }
            });
        }
     }"
     x-init="initChart()"
     @effect="renderChart()"> {{-- Re-renders chart smoothly when Livewire updates chartData --}}

    {{-- HEADER & FILTERS --}}
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-black tracking-tight">Financial Overview</h1>
            <p class="text-slate-400 text-sm mt-1">Track and manage M-Pesa revenue streams</p>
        </div>

        <div class="flex flex-wrap items-center gap-2 bg-[#111111] p-1.5 rounded-xl border border-slate-800">
            @foreach(['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time', 'custom' => 'Custom'] as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        class="px-4 py-2 text-sm font-bold rounded-lg transition-all {{ $filter === $key ? 'bg-red-600 text-white shadow-lg shadow-red-900/20' : 'text-slate-400 hover:text-white hover:bg-white/5' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- CUSTOM DATE RANGE PICKER --}}
    @if($filter === 'custom')
        <div class="flex items-center gap-4 p-4 bg-[#111111] border border-slate-800 rounded-2xl animate-fade-in-down">
            <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 uppercase">From:</label>
                <input type="date" wire:model.live="customStart" class="bg-zinc-900 border border-slate-700 text-white rounded-lg px-3 py-1.5 text-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none">
            </div>
            <div class="flex items-center gap-3">
                <label class="text-xs font-bold text-slate-500 uppercase">To:</label>
                <input type="date" wire:model.live="customEnd" class="bg-zinc-900 border border-slate-700 text-white rounded-lg px-3 py-1.5 text-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none">
            </div>
        </div>
    @endif

    {{-- TOP METRIC CARDS --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
        <div class="bg-[#111111] border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-24 h-24 bg-emerald-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-2">Today's Income</p>
            <h3 class="text-3xl font-black text-white">KES {{ number_format($this->metrics['today']) }}</h3>
        </div>

        <div class="bg-[#111111] border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-24 h-24 bg-blue-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-2">This Week</p>
            <h3 class="text-3xl font-black text-white">KES {{ number_format($this->metrics['week']) }}</h3>
        </div>

        <div class="bg-[#111111] border border-slate-800 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-24 h-24 bg-indigo-500/10 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-sm font-bold text-slate-400 uppercase tracking-widest mb-2">This Month</p>
            <h3 class="text-3xl font-black text-white">KES {{ number_format($this->metrics['month']) }}</h3>
        </div>

        <div class="bg-gradient-to-br from-red-900/40 to-[#111111] border border-red-900/50 rounded-2xl p-6 shadow-xl relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-24 h-24 bg-red-500/20 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-sm font-bold text-red-400 uppercase tracking-widest mb-2">Total All Time</p>
            <h3 class="text-3xl font-black text-white">KES {{ number_format($this->metrics['all']) }}</h3>
        </div>
    </div>

    {{-- INCOME GRAPH --}}
    <div class="bg-[#111111] border border-slate-800 rounded-2xl p-6 shadow-xl" wire:ignore>
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-bold text-white tracking-wide">Revenue Trend</h3>
        </div>
        <div class="w-full h-72">
            <canvas id="incomeChart"></canvas>
        </div>
    </div>

    {{-- TRANSACTIONS TABLE --}}
    <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-xl overflow-hidden flex flex-col">

        {{-- Table Toolbar --}}
        <div class="p-6 border-b border-slate-800 flex flex-col sm:flex-row justify-between items-center gap-4">
            <h3 class="text-lg font-bold text-white tracking-wide">M-Pesa Payment History</h3>

            <div class="relative w-full sm:w-72">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <svg class="w-4 h-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
                <input type="text"
                       wire:model.live.debounce.300ms="search"
                       class="w-full bg-zinc-900 border border-slate-700 text-white rounded-xl pl-10 pr-4 py-2.5 text-sm focus:border-red-500 focus:ring-1 focus:ring-red-500 outline-none transition-all placeholder-slate-500"
                       placeholder="Search Receipt or Phone...">
            </div>
        </div>

        {{-- Table Data --}}
        <div class="overflow-x-auto w-full">
            <table class="w-full text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-black/50 border-b border-slate-800 text-xs uppercase tracking-widest text-slate-400 font-bold">
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4">Receipt No.</th>
                        <th class="px-6 py-4">Phone Number</th>
                        <th class="px-6 py-4">Amount</th>
                        <th class="px-6 py-4">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    @forelse($this->transactions as $trx)
                        <tr class="hover:bg-white/[0.02] transition-colors">
                            <td class="px-6 py-4 text-sm text-slate-300">
                                {{ Carbon::parse($trx->created_at)->format('M d, Y h:i A') }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm font-mono font-bold text-white bg-zinc-800 px-2 py-1 rounded">
                                    {{ $trx->receipt_number ?? 'N/A' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-300">
                                {{ $trx->phone_number ?? 'N/A' }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-sm font-black text-emerald-400">
                                    KES {{ number_format($trx->amount, 2) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $status = strtolower($trx->status ?? 'completed');
                                    $isSuccess = in_array($status, ['completed', 'success']);
                                @endphp
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider border {{ $isSuccess ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20' }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $isSuccess ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    {{ $trx->status ?? 'Completed' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-16 text-center">
                                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-zinc-900 border border-slate-800 mb-4">
                                    <svg class="w-8 h-8 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-white mb-1">No Transactions Found</h3>
                                <p class="text-sm text-slate-500">Try adjusting your filters or search query.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if($this->transactions->hasPages())
            <div class="p-6 border-t border-slate-800 bg-black/30">
                {{ $this->transactions->links(data: ['scrollTo' => false]) }}
            </div>
        @endif

    </div>
</div>
