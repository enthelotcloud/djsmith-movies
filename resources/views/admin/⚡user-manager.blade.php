<?php

use App\Models\User;
use App\Models\Subscription;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    // --- FILTERS ---
    public $search = '';
    public $roleFilter = '';

    // --- MODAL STATES ---
    public $isEditModalOpen = false;
    public $isPasswordModalOpen = false;
    public $isDeleteModalOpen = false;
    public $modalTitle = 'Edit User Profile';

    // --- FORM DATA ---
    public $userId = null;
    public $name = '';
    public $email = '';
    public $phone = '';
    public $role = 'client';
    public $wallet_balance = 0.00;
    public $referral_code = '';
    public $referred_by = null;

    // --- PASSWORD & DELETE DATA ---
    public $newPassword = '';
    public $viewingUser = null;

    public function updatingSearch() { $this->resetPage(); }
    public function updatingRoleFilter() { $this->resetPage(); }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with(['currentSubscription', 'subscriptions']) // Eager load for performance
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                      ->orWhere('email', 'like', "%{$this->search}%")
                      ->orWhere('phone', 'like', "%{$this->search}%")
                      ->orWhere('referral_code', 'like', "%{$this->search}%");
                });
            })
            ->when($this->roleFilter, fn($q) => $q->where('role', $this->roleFilter))
            ->orderBy('created_at', 'desc')
            ->paginate(10);
    }

    #[Computed]
    public function potentialReferrers()
    {
        return User::select('id', 'name', 'referral_code')
            ->orderBy('name')
            ->get();
    }

    public function openCreateModal()
    {
        $this->resetForm();
        $this->modalTitle = 'Create New User';
        $this->isEditModalOpen = true;
    }

    public function edit($id)
    {
        $this->resetForm();
        $user = User::findOrFail($id);
        $this->userId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone;
        $this->role = $user->role;
        $this->wallet_balance = $user->wallet_balance;
        $this->referral_code = $user->referral_code;
        $this->referred_by = $user->referred_by;

        $this->modalTitle = 'Edit User Profile';
        $this->isEditModalOpen = true;
    }

    public function save()
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($this->userId)],
            'phone' => ['nullable', 'string', Rule::unique('users')->ignore($this->userId)],
            'role' => 'required|in:admin,staff,client',
            'wallet_balance' => 'required|numeric|min:0',
            'referral_code' => ['nullable', 'string', Rule::unique('users')->ignore($this->userId)],
            'referred_by' => 'nullable|exists:users,id',
        ]);

        $isNew = is_null($this->userId);
        $plainPassword = null;

        // Auto-generate referral code if left blank
        $finalReferralCode = $this->referral_code ?: Str::upper(Str::random(8));

        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'wallet_balance' => $this->wallet_balance,
            'referral_code' => $finalReferralCode,
            'referred_by' => $this->referred_by ?: null,
        ];

        if ($isNew) {
            $plainPassword = Str::password(12, true, true, true, false);
            $data['password'] = Hash::make($plainPassword);
        } else {
            // Prevent removing one's own admin rights
            if ($this->userId === Auth::id() && $this->role !== 'admin') {
                $data['role'] = 'admin';
                session()->flash('error', 'Action denied: You cannot revoke your own admin role.');
            }
        }

        $user = User::updateOrCreate(['id' => $this->userId], $data);

        // Ensure new users have a default "inactive" subscription record
        if ($isNew) {
            Subscription::create([
                'user_id' => $user->id,
                'status' => 'inactive',
                'auto_renew' => false,
            ]);
        }

        $this->closeModals();

        if ($isNew) {
            $this->dispatch('user-created', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $plainPassword,
                'referral_code' => $user->referral_code,
                'phone' => $user->phone,
            ]);
        } else {
            if (!session()->has('error')) {
                session()->flash('message', 'User profile updated successfully.');
            }
        }
    }

    public function openDeleteModal($id)
    {
        if ($id === Auth::id()) {
            session()->flash('error', 'Error: You cannot delete your own account.');
            return;
        }

        $this->viewingUser = User::findOrFail($id);
        $this->userId = $id;
        $this->isDeleteModalOpen = true;
    }

    public function deleteUser()
    {
        if ($this->userId && $this->userId !== Auth::id()) {
            User::findOrFail($this->userId)->delete();
            session()->flash('message', 'User data permanently deleted.');
        }
        $this->closeModals();
    }

    public function openPasswordModal($id)
    {
        $this->viewingUser = User::findOrFail($id);
        $this->newPassword = '';
        $this->isPasswordModalOpen = true;
    }

    public function generatePassword()
    {
        $this->newPassword = Str::password(16, true, true, true, false);
        $this->dispatch('password-generated');
    }

    public function updatePassword()
    {
        $this->validate(['newPassword' => 'required|string|min:8']);
        $this->viewingUser->update(['password' => Hash::make($this->newPassword)]);
        session()->flash('message', 'Password updated for ' . $this->viewingUser->name);
        $this->closeModals();
    }

    public function resetForm()
    {
        $this->reset(['userId', 'name', 'email', 'phone', 'role', 'wallet_balance', 'referral_code', 'referred_by']);
        $this->resetValidation();
    }

    public function closeModals()
    {
        $this->isEditModalOpen = false;
        $this->isPasswordModalOpen = false;
        $this->isDeleteModalOpen = false;
        $this->resetForm();
    }
};
?>

<div class="w-full text-slate-200 font-sans min-h-screen relative">

    {{-- Global Loading Overlay --}}
    <div wire:loading wire:target="save, deleteUser, updatePassword" class="fixed inset-0 z-[100] bg-slate-950/80 backdrop-blur-sm flex items-center justify-center">
        <div class="bg-slate-900 border border-slate-700 p-8 rounded-2xl flex flex-col items-center shadow-2xl">
            <svg class="w-10 h-10 text-blue-500 animate-spin mb-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span class="text-sm font-bold text-slate-300">Processing...</span>
        </div>
    </div>

    {{-- Header & Action Bar --}}
    <div class="mb-6 bg-slate-900 border border-slate-800 p-6 rounded-2xl shadow-sm flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
        <div>
            <h1 class="text-2xl font-bold text-white tracking-tight">User Management</h1>
            <p class="text-sm text-slate-400 mt-1">Manage accounts, wallets, roles, and subscriptions.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3 w-full lg:w-auto">
            <div class="relative flex-1 lg:w-64">
                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search name, phone, ref..." class="w-full pl-10 pr-4 py-2 bg-slate-950 border border-slate-700 rounded-xl text-sm text-white focus:ring-1 focus:ring-blue-500 focus:border-blue-500 transition-colors">
            </div>

            <select wire:model.live="roleFilter" class="bg-slate-950 border border-slate-700 rounded-xl text-sm text-slate-300 py-2 pl-4 pr-8 focus:ring-1 focus:ring-blue-500 appearance-none">
                <option value="">All Roles</option>
                <option value="admin">Admins</option>
                <option value="staff">Staff</option>
                <option value="client">Clients</option>
            </select>

            <button wire:click="openCreateModal" class="px-5 py-2 bg-blue-600 hover:bg-blue-500 text-white text-sm font-semibold rounded-xl transition shadow-sm flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Add User
            </button>
        </div>
    </div>

    {{-- System Messages --}}
    @if(session()->has('message'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" class="mb-6 bg-blue-900/30 border border-blue-500/30 text-blue-400 px-4 py-3 rounded-xl flex items-center gap-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm font-medium">{{ session('message') }}</span>
        </div>
    @endif

    @if(session()->has('error'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" class="mb-6 bg-red-900/30 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl flex items-center gap-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-sm font-medium">{{ session('error') }}</span>
        </div>
    @endif

    {{-- Data Table --}}
    <div class="bg-slate-900 border border-slate-800 rounded-2xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                <thead class="bg-slate-950 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                    <tr>
                        <th class="px-6 py-4">User Details</th>
                        <th class="px-6 py-4">Role & Ref</th>
                        <th class="px-6 py-4">Wallet</th>
                        <th class="px-6 py-4">Sub Status</th>
                        <th class="px-6 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/50">
                    @forelse($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}" class="hover:bg-slate-800/20 transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="h-10 w-10 rounded-lg bg-blue-900 text-blue-400 flex items-center justify-center font-bold">
                                        {{ substr($user->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <div class="text-slate-200 font-medium">{{ $user->name }}</div>
                                        <div class="text-[11px] text-slate-500 mt-0.5">{{ $user->email }}</div>
                                        <div class="text-[11px] text-slate-500">{{ $user->phone ?? 'No Phone' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div>
                                    @if($user->role === 'admin')
                                        <span class="px-2.5 py-1 bg-indigo-900/50 text-indigo-400 border border-indigo-700/50 rounded-md text-[11px] font-medium">Admin</span>
                                    @elseif($user->role === 'staff')
                                        <span class="px-2.5 py-1 bg-sky-900/50 text-sky-400 border border-sky-700/50 rounded-md text-[11px] font-medium">Staff</span>
                                    @else
                                        <span class="px-2.5 py-1 bg-slate-800 text-slate-300 border border-slate-700 rounded-md text-[11px] font-medium">Client</span>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-500 font-mono mt-2" title="Referral Code">Ref: {{ $user->referral_code ?? 'None' }}</div>
                            </td>
                            <td class="px-6 py-4 font-mono">
                                <span class="{{ $user->wallet_balance > 0 ? 'text-emerald-400' : 'text-slate-500' }}">
                                    KES {{ number_format($user->wallet_balance, 2) }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $subStatus = $user->currentSubscription->status ?? 'inactive';
                                @endphp
                                
                                @if($subStatus === 'active')
                                    <span class="flex items-center gap-2 text-emerald-500 text-xs font-medium">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Active
                                    </span>
                                @elseif($subStatus === 'suspended')
                                    <span class="flex items-center gap-2 text-red-500 text-xs font-medium">
                                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span> Suspended
                                    </span>
                                @elseif($subStatus === 'not paid')
                                    <span class="flex items-center gap-2 text-amber-500 text-xs font-medium">
                                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span> Not Paid
                                    </span>
                                @else
                                    <span class="flex items-center gap-2 text-slate-500 text-xs font-medium">
                                        <span class="h-1.5 w-1.5 rounded-full bg-slate-500"></span> Inactive
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="openPasswordModal({{ $user->id }})" class="p-2 text-slate-400 hover:text-amber-400 hover:bg-slate-800 rounded-lg transition" title="Reset Password">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                    </button>

                                    <button wire:click="edit({{ $user->id }})" class="p-2 text-slate-400 hover:text-blue-400 hover:bg-slate-800 rounded-lg transition" title="Edit User">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    </button>

                                    @if($user->id !== auth()->id())
                                        <button wire:click="openDeleteModal({{ $user->id }})" class="p-2 text-slate-400 hover:text-red-400 hover:bg-slate-800 rounded-lg transition" title="Delete User">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-slate-500">
                                No users found matching your criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($this->users->hasPages())
            <div class="px-6 py-4 bg-slate-900 border-t border-slate-800">
                {{ $this->users->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    {{-- ══════════════════ CREATE / EDIT USER MODAL ══════════════════ --}}
    @if($isEditModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 overflow-y-auto">
            <div class="bg-slate-900 border border-slate-800 w-full max-w-4xl rounded-2xl shadow-xl my-auto">
                <div class="px-8 py-5 border-b border-slate-800 flex justify-between items-center bg-slate-900 rounded-t-2xl">
                    <h3 class="text-lg font-bold text-white">{{ $modalTitle }}</h3>
                    <button wire:click="closeModals" class="text-slate-500 hover:text-slate-300 transition"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>

                <div class="p-8 max-h-[75vh] overflow-y-auto">
                    <form wire:submit="save" class="space-y-8">

                        {{-- CORE IDENTITY --}}
                        <div>
                            <h4 class="text-sm font-semibold text-blue-400 mb-4 border-b border-slate-800 pb-2">Identity & Access</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Full Name</label>
                                    <input type="text" wire:model="name" class="w-full bg-slate-950 border @error('name') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                                    @error('name') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Email Address</label>
                                    <input type="email" wire:model="email" class="w-full bg-slate-950 border @error('email') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                                    @error('email') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Phone Number</label>
                                    <input type="text" wire:model="phone" placeholder="e.g. 0712345678" class="w-full bg-slate-950 border @error('phone') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                                    @error('phone') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">System Role</label>
                                    <select wire:model="role" class="w-full bg-slate-950 border @error('role') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition" @if($userId === auth()->id()) disabled @endif>
                                        <option value="client">Client</option>
                                        <option value="staff">Staff</option>
                                        <option value="admin">Administrator</option>
                                    </select>
                                    @error('role') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>

                        {{-- FINANCIALS & REFERRALS --}}
                        <div>
                            <h4 class="text-sm font-semibold text-blue-400 mb-4 border-b border-slate-800 pb-2">Financials & Referrals</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Wallet Balance (KES)</label>
                                    <input type="number" step="0.01" wire:model="wallet_balance" class="w-full bg-slate-950 border @error('wallet_balance') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-emerald-400 font-mono focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                                    <p class="text-[10px] text-slate-500 mt-1">Admins can manually add funds by editing this value.</p>
                                    @error('wallet_balance') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Custom Referral Code (Optional)</label>
                                    <input type="text" wire:model="referral_code" placeholder="Leave blank to auto-generate" class="w-full bg-slate-950 border @error('referral_code') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition uppercase">
                                    @error('referral_code') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-slate-400 mb-1.5">Referred By</label>
                                    <select wire:model="referred_by" class="w-full bg-slate-950 border @error('referred_by') border-red-500 ring-1 ring-red-500 @else border-slate-700 @enderror rounded-lg px-4 py-2.5 text-slate-200 focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition">
                                        <option value="">No Referrer</option>
                                        @foreach($this->potentialReferrers as $u)
                                            @if($u->id !== $userId)
                                                <option value="{{ $u->id }}">{{ $u->name }} (Ref: {{ $u->referral_code }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    @error('referred_by') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-6 border-t border-slate-800">
                            <button type="button" wire:click="closeModals" class="px-5 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium transition">Cancel</button>
                            <button type="submit" class="px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-white font-medium transition shadow-sm">{{ $userId ? 'Save Profile' : 'Create User' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════ SMART COMMUNICATOR / SUCCESS MODAL ══════════════════ --}}
    <div x-data="{
        showShareModal: false,
        newUser: {},
        greeting: '',
        messageTemplate: '',

        init() {
            window.addEventListener('user-created', (e) => {
                this.newUser = e.detail[0];
                this.generateMessage();
                this.copyToClipboard();
                this.showShareModal = true;
            });
        },

        generateMessage() {
            const hour = new Date().getHours();
            this.greeting = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');
            this.messageTemplate = `${this.greeting} ${this.newUser.name},\n\nYour portal account has been securely created.\n\nPlease sign in to access your dashboard.\n\n📧 Email: ${this.newUser.email}\n🔑 Password: ${this.newUser.password}\n🎫 Referral Code: ${this.newUser.referral_code || 'N/A'}\n\nLogin link: ${window.location.origin}/login\n\nPlease keep these credentials secure.`;
        },

        copyToClipboard() {
            navigator.clipboard.writeText(this.messageTemplate);
        },

        openWhatsApp() {
            if(!this.newUser.phone) return alert('No phone number provided for this user.');
            let num = this.newUser.phone.replace(/[^0-9]/g, '');
            window.open(`https://wa.me/${num}?text=${encodeURIComponent(this.messageTemplate)}`, '_blank');
        },

        openEmail() {
            window.open(`mailto:${this.newUser.email}?subject=Your Portal Access Details&body=${encodeURIComponent(this.messageTemplate)}`, '_blank');
        },

        openSMS() {
            if(!this.newUser.phone) return alert('No phone number provided for this user.');
            let num = this.newUser.phone.replace(/[^0-9]/g, '');
            window.open(`sms:${num}?body=${encodeURIComponent(this.messageTemplate)}`, '_blank');
        }
    }">
        <div x-show="showShareModal" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-950/80">
            <div @click.away="showShareModal = false" class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl shadow-xl p-8 text-center relative">

                <div class="absolute -top-10 right-0 text-emerald-500 text-sm font-semibold flex items-center gap-1 bg-emerald-900/20 px-3 py-1.5 rounded-lg border border-emerald-500/30">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Credentials Auto-Copied!
                </div>

                <div class="w-16 h-16 bg-emerald-900/30 text-emerald-500 rounded-full flex items-center justify-center mx-auto mb-5 border border-emerald-500/20">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>

                <h3 class="text-xl font-bold text-white mb-2">User Created Successfully</h3>
                <p class="text-sm text-slate-400 mb-6">How would you like to send the login details to <strong x-text="newUser.name" class="text-slate-200"></strong>?</p>

                <div class="flex flex-col gap-3 mb-6">
                    <button @click="openWhatsApp()" class="w-full py-2.5 rounded-lg bg-[#25D366]/10 text-[#25D366] hover:bg-[#25D366]/20 border border-[#25D366]/30 font-medium transition flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        Send via WhatsApp
                    </button>

                    <button @click="openEmail()" class="w-full py-2.5 rounded-lg bg-blue-900/30 text-blue-400 hover:bg-blue-800/40 border border-blue-700/50 font-medium transition flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        Send via Email
                    </button>

                    <button @click="openSMS()" class="w-full py-2.5 rounded-lg bg-slate-800 text-slate-300 hover:bg-slate-700 border border-slate-700 font-medium transition flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                        Send via SMS Text
                    </button>
                </div>

                <button @click="showShareModal = false" class="text-xs font-medium text-slate-500 hover:text-slate-300 underline underline-offset-2">Dismiss & Close</button>
            </div>
        </div>
    </div>

    {{-- ══════════════════ PASSWORD RESET MODAL ══════════════════ --}}
    @if($isPasswordModalOpen)
        <div x-data="{
                copied: false,
                copyPassword() {
                    let pwd = $wire.newPassword;
                    if (!pwd) return;
                    let name = '{{ $viewingUser ? addslashes($viewingUser->name) : 'Client' }}';
                    let hour = new Date().getHours();
                    let greeting = hour < 12 ? 'Good morning' : (hour < 18 ? 'Good afternoon' : 'Good evening');

                    let msg = `${greeting} ${name},\n\nYour portal password has been securely reset by an administrator.\n\nYour new temporary password is: ${pwd}\n\nPlease keep this secure and do not share it with anyone.`;

                    navigator.clipboard.writeText(msg).then(() => {
                        this.copied = true;
                        setTimeout(() => this.copied = false, 3000);
                    });
                }
            }"
            @password-generated.window="copyPassword()"
            class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80">

            <div class="bg-slate-900 border border-slate-800 w-full max-w-md rounded-2xl shadow-xl">
                <div class="p-8">
                    <h3 class="text-xl font-bold text-white mb-2">Reset Password</h3>
                    <p class="text-sm text-slate-400 mb-6">Generate a new password for <strong class="text-slate-200">{{ $viewingUser->name }}</strong>.</p>

                    <div class="mb-6 relative">
                        <label class="block text-xs font-medium text-slate-400 mb-1.5">New Password</label>
                        <div class="flex gap-2">
                            <input type="text" wire:model="newPassword" placeholder="Click generate..." readonly class="w-full bg-slate-950 border border-slate-700 rounded-lg px-4 py-2.5 text-blue-400 font-mono tracking-wider focus:outline-none">
                            <button type="button" wire:click="generatePassword" class="px-4 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-slate-200 transition" title="Auto-Generate">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            </button>
                        </div>
                        @error('newPassword') <span class="text-xs text-red-500 mt-2 block font-bold">{{ $message }}</span> @enderror

                        <div x-show="copied" x-transition.opacity class="absolute -top-8 right-0 text-emerald-500 text-xs font-semibold flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg> Message Copied!
                        </div>
                    </div>

                    <div class="flex justify-between items-center gap-3">
                        <button type="button" @click="copyPassword()" class="text-xs font-medium text-blue-400 hover:text-blue-300 underline underline-offset-2">Copy Message Manually</button>

                        <div class="flex gap-2">
                            <button type="button" wire:click="closeModals" class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium transition">Cancel</button>
                            <button type="button" wire:click="updatePassword" class="px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white text-sm font-medium transition shadow-sm">Save Password</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ══════════════════ DELETE USER MODAL ══════════════════ --}}
    @if($isDeleteModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80">
            <div class="bg-slate-900 border border-slate-800 w-full max-w-sm rounded-2xl shadow-xl">
                <div class="p-8 text-center">
                    <div class="w-16 h-16 bg-red-900/30 text-red-500 rounded-full flex items-center justify-center mx-auto mb-5 border border-red-500/20">
                        <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">Delete User?</h3>
                    <p class="text-sm text-slate-400 mb-8">This action is permanent and will remove <strong class="text-slate-200">{{ $viewingUser->name }}</strong> from the database.</p>

                    <div class="flex justify-between gap-3">
                        <button type="button" wire:click="closeModals" class="w-1/2 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium transition">Cancel</button>
                        <button type="button" wire:click="deleteUser" class="w-1/2 py-2.5 rounded-lg bg-red-600 hover:bg-red-500 text-white font-medium transition shadow-sm">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>