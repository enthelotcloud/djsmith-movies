<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use App\Models\Plan;
use Illuminate\Support\Facades\Gate;

new #[Layout('layouts.app')] class extends Component {
    
    // Form States
    public $planId = null;
    public $name = '';
    public $price = 0.00;
    public $duration_minutes = 43200; // Default: 30 days
    public $can_download = false;
    public $is_active = true;

    // Modal States
    public $isEditModalOpen = false;
    public $isDeleteModalOpen = false;
    public $modalTitle = 'Create Subscription Plan';

    public function mount()
    {
        // Double-tap security: Ensure only admins can load this component
        Gate::authorize('admin');
    }

    #[Computed]
    public function plans()
    {
        return Plan::orderBy('price', 'asc')->get();
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->modalTitle = 'Create Subscription Plan';
        $this->isEditModalOpen = true;
    }

    public function edit($id)
    {
        $this->resetForm();
        $plan = Plan::findOrFail($id);
        
        $this->planId = $plan->id;
        $this->name = $plan->name;
        $this->price = $plan->price;
        $this->duration_minutes = $plan->duration_minutes;
        $this->can_download = $plan->can_download;
        $this->is_active = $plan->is_active;

        $this->modalTitle = 'Edit Subscription Plan';
        $this->isEditModalOpen = true;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:1',
            'can_download' => 'boolean',
            'is_active' => 'boolean',
        ]);

        Plan::updateOrCreate(
            ['id' => $this->planId],
            [
                'name' => $this->name,
                'price' => $this->price,
                'duration_minutes' => $this->duration_minutes,
                'can_download' => $this->can_download,
                'is_active' => $this->is_active,
            ]
        );

        $this->closeModals();
        session()->flash('message', 'Subscription plan saved successfully.');
    }

    public function toggleActive($id)
    {
        $plan = Plan::findOrFail($id);
        $plan->update(['is_active' => !$plan->is_active]);
        
        $status = $plan->is_active ? 'activated' : 'deactivated';
        session()->flash('message', "Plan {$plan->name} has been {$status}.");
    }

    public function openDeleteModal($id)
    {
        $this->planId = $id;
        $this->isDeleteModalOpen = true;
    }

    public function deletePlan()
    {
        if ($this->planId) {
            Plan::findOrFail($this->planId)->delete();
            session()->flash('message', 'Subscription plan permanently deleted.');
        }
        $this->closeModals();
    }

    public function resetForm()
    {
        $this->reset(['planId', 'name', 'price', 'duration_minutes', 'can_download', 'is_active']);
        $this->resetValidation();
    }

    public function closeModals()
    {
        $this->isEditModalOpen = false;
        $this->isDeleteModalOpen = false;
        $this->resetForm();
    }
};
?>

<div class="w-full text-slate-200 font-sans min-h-screen relative">

    {{-- Global Loading Overlay --}}
    <div wire:loading wire:target="save, deletePlan, toggleActive" class="fixed inset-0 z-[100] bg-slate-950/80 backdrop-blur-sm flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-700 p-8 rounded-2xl flex flex-col items-center shadow-2xl">
            <svg class="w-10 h-10 text-emerald-500 animate-spin mb-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span class="text-sm font-bold text-slate-300">Processing...</span>
        </div>
    </div>

    {{-- Header & Action Bar --}}
    <div class="mb-6 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-sm flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">Subscription Plans</h1>
            <p class="text-sm text-slate-400 mt-1">Manage pricing tiers, access durations, and download rights.</p>
        </div>

        <button wire:click="openCreateModal" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-bold rounded-xl transition shadow-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Create New Plan
        </button>
    </div>

    {{-- System Messages --}}
    @if(session()->has('message'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" class="mb-6 bg-emerald-900/30 border border-emerald-500/30 text-emerald-400 px-4 py-3 rounded-xl flex items-center gap-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm font-medium">{{ session('message') }}</span>
        </div>
    @endif

    {{-- Data Table --}}
    <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                <thead class="bg-slate-950 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                    <tr>
                        <th class="px-6 py-4">Plan Name</th>
                        <th class="px-6 py-4">Price (KES)</th>
                        <th class="px-6 py-4">Duration</th>
                        <th class="px-6 py-4">Features</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    @forelse($this->plans as $plan)
                        <tr wire:key="plan-{{ $plan->id }}" class="hover:bg-slate-800/20 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-slate-200 font-bold text-base">{{ $plan->name }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="text-emerald-400 font-mono font-bold text-lg">
                                    {{ number_format($plan->price, 0) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @if($plan->duration_minutes < 60)
                                    <span class="text-slate-300">{{ $plan->duration_minutes }} Minutes</span>
                                @elseif($plan->duration_minutes < 1440)
                                    <span class="text-slate-300">{{ floor($plan->duration_minutes / 60) }} Hours</span>
                                @else
                                    <span class="text-slate-300">{{ floor($plan->duration_minutes / 1440) }} Days</span>
                                @endif
                                <div class="text-[10px] text-slate-500 font-mono mt-0.5">({{ $plan->duration_minutes }}m)</div>
                            </td>
                            <td class="px-6 py-4">
                                @if($plan->can_download)
                                    <span class="px-2.5 py-1 bg-blue-900/30 text-blue-400 border border-blue-700/50 rounded-md text-[11px] font-medium flex items-center gap-1 w-max">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        Downloads Allowed
                                    </span>
                                @else
                                    <span class="text-slate-500 text-xs flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                        Streaming Only
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @if($plan->is_active)
                                    <span class="flex items-center gap-2 text-emerald-500 text-xs font-medium">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Public
                                    </span>
                                @else
                                    <span class="flex items-center gap-2 text-slate-500 text-xs font-medium">
                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span> Hidden
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="toggleActive({{ $plan->id }})" class="p-2 text-slate-400 hover:text-amber-400 hover:bg-slate-800 rounded-lg transition" title="{{ $plan->is_active ? 'Hide Plan' : 'Publish Plan' }}">
                                        @if($plan->is_active)
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                        @else
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        @endif
                                    </button>

                                    <button wire:click="edit({{ $plan->id }})" class="p-2 text-slate-400 hover:text-blue-400 hover:bg-slate-800 rounded-lg transition" title="Edit Plan">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    </button>

                                    <button wire:click="openDeleteModal({{ $plan->id }})" class="p-2 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded-lg transition" title="Delete Plan">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500">
                                No plans created yet. Click "Create New Plan" to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ══════════════════ CREATE / EDIT MODAL ══════════════════ --}}
    @if($isEditModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 overflow-y-auto">
            <div class="bg-slate-900 border border-slate-800 w-full max-w-2xl rounded-2xl shadow-xl my-auto">
                <div class="px-8 py-5 border-b border-slate-800 flex justify-between items-center bg-slate-900 rounded-t-2xl">
                    <h3 class="text-lg font-bold text-white">{{ $modalTitle }}</h3>
                    <button wire:click="closeModals" class="text-slate-500 hover:text-slate-300 transition"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>

                <div class="p-8">
                    <form wire:submit="save" class="space-y-6">
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-400 mb-1.5 uppercase tracking-wider">Plan Name</label>
                            <input type="text" wire:model="name" placeholder="e.g., Premium 30 Days" class="w-full bg-slate-950 border @error('name') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-3 text-slate-200 text-lg font-bold focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                            @error('name') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-400 mb-1.5 uppercase tracking-wider">Price (KES)</label>
                                <input type="number" step="0.01" wire:model="price" class="w-full bg-slate-950 border @error('price') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-emerald-400 font-mono text-lg focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                                @error('price') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>

                            <div x-data="{ mins: @entangle('duration_minutes') }">
                                <label class="block text-xs font-bold text-slate-400 mb-1.5 uppercase tracking-wider">Duration (In Minutes)</label>
                                <input type="number" wire:model="duration_minutes" class="w-full bg-slate-950 border @error('duration_minutes') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                                <p class="text-[11px] text-slate-500 mt-1.5 font-mono">
                                    Helper: <span x-text="(mins / 60).toFixed(1)"></span> Hours | <span x-text="(mins / 1440).toFixed(1)"></span> Days
                                </p>
                                @error('duration_minutes') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-800">
                            <label class="flex items-center gap-3 p-4 rounded-xl border border-slate-800 bg-slate-950/50 cursor-pointer hover:border-slate-700 transition">
                                <input type="checkbox" wire:model="can_download" class="w-5 h-5 rounded border-slate-700 text-blue-600 focus:ring-blue-600 bg-slate-900">
                                <div>
                                    <div class="text-sm font-bold text-white">Allow Downloads</div>
                                    <div class="text-xs text-slate-500">Users can save movies offline</div>
                                </div>
                            </label>

                            <label class="flex items-center gap-3 p-4 rounded-xl border border-slate-800 bg-slate-950/50 cursor-pointer hover:border-slate-700 transition">
                                <input type="checkbox" wire:model="is_active" class="w-5 h-5 rounded border-slate-700 text-emerald-600 focus:ring-emerald-600 bg-slate-900">
                                <div>
                                    <div class="text-sm font-bold text-white">Publish Plan</div>
                                    <div class="text-xs text-slate-500">Make visible to clients immediately</div>
                                </div>
                            </label>
                        </div>

                        <div class="flex justify-end gap-3 pt-6 border-t border-slate-800">
                            <button type="button" wire:click="closeModals" class="px-5 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium transition">Cancel</button>
                            <button type="submit" class="px-5 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white font-medium transition shadow-sm">{{ $planId ? 'Save Changes' : 'Create Plan' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════ DELETE MODAL ══════════════════ --}}
    @if($isDeleteModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80">
            <div class="bg-slate-900 border border-slate-800 w-full max-w-sm rounded-2xl shadow-xl">
                <div class="p-8 text-center">
                    <div class="w-16 h-16 bg-red-900/30 text-red-500 rounded-full flex items-center justify-center mx-auto mb-5 border border-red-500/20">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Delete Plan?</h3>
                    <p class="text-sm text-slate-400 mb-8">This action is permanent. Existing subscriptions using this plan will still function until they expire.</p>

                    <div class="flex justify-between gap-3">
                        <button type="button" wire:click="closeModals" class="w-1/2 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium transition">Cancel</button>
                        <button type="button" wire:click="deletePlan" class="w-1/2 py-2.5 rounded-lg bg-red-600 hover:bg-red-500 text-white font-medium transition shadow-sm">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>