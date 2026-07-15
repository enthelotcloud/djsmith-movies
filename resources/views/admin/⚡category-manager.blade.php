<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithFileUploads;

    public $editingId = null;
    public $name = '';
    public $description = '';
    public $poster;
    public $showModal = false;

    #[Computed]
    public function categories() {
        return DB::table('moviecategories')->orderBy('name')->get();
    }

    public function showCreateForm() {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit($id) {
        $this->resetForm();
        $cat = DB::table('moviecategories')->where('id', $id)->first();

        $this->editingId = $cat->id;
        $this->name = $cat->name;
        $this->description = $cat->description;

        $this->showModal = true;
    }

    public function save() {
        $this->validate([
            'name' => 'required|string|max:255|unique:moviecategories,name,' . $this->editingId,
            'poster' => 'nullable|image|max:2048' // 2MB max
        ]);

        $data = [
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'description' => $this->description,
        ];

        if ($this->poster) {
            $data['poster'] = $this->poster->store('categories', 'b2');
        }

        if ($this->editingId) {
            $data['updated_at'] = now();
            DB::table('moviecategories')->where('id', $this->editingId)->update($data);
            $this->dispatch('notify-toast', type: 'success', message: 'Category updated!');
        } else {
            $data['created_at'] = now();
            $data['updated_at'] = now();
            DB::table('moviecategories')->insert($data);
            $this->dispatch('notify-toast', type: 'success', message: 'Category created!');
        }

        $this->showModal = false;
        unset($this->categories);
    }

    public function delete($id) {
        DB::table('moviecategories')->where('id', $id)->delete();
        unset($this->categories);
        $this->dispatch('notify-toast', type: 'success', message: 'Category deleted.');
    }

    public function resetForm() {
        $this->reset(['editingId', 'name', 'description', 'poster']);
    }
};
?>

{{-- 🚨 FLUX UI TOAST INTEGRATION (NO ALERTS) 🚨 --}}
<div class="max-w-6xl mx-auto space-y-8 relative" x-data @notify-toast.window="Flux.toast({ text: $event.detail.message, variant: $event.detail.type })">

    <div class="flex justify-between items-center bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Categories</h1>
            <p class="text-slate-400 mt-1">Manage genres and tags for your VOD catalog.</p>
        </div>
        <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm">
            + New Category
        </button>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-6">
        @forelse($this->categories as $cat)
            <div class="bg-[#111111] border border-slate-800 rounded-2xl p-5 text-center shadow-lg group hover:border-red-600/50 transition duration-300 relative">

                <button wire:click="delete({{ $cat->id }})" wire:confirm="Delete this category?" class="absolute top-2 right-2 w-6 h-6 bg-red-900/80 text-white rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition hover:bg-red-600 z-10">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                @if($cat->poster)
                    <img src="{{ Storage::disk('b2')->url($cat->poster) }}" class="w-24 h-24 mx-auto rounded-full object-cover shadow-xl border-2 border-slate-800 group-hover:border-red-500 transition">
                @else
                    <div class="w-24 h-24 mx-auto rounded-full bg-gradient-to-br from-red-900 to-black flex items-center justify-center shadow-xl border-2 border-slate-800 group-hover:border-red-500 transition">
                        <span class="text-white text-3xl font-black uppercase tracking-widest">{{ Str::substr($cat->name, 0, 1) }}</span>
                    </div>
                @endif

                <h3 class="mt-4 font-bold text-white truncate">{{ $cat->name }}</h3>
                <p class="text-[10px] text-slate-500 mt-1 line-clamp-2 min-h-[30px]">{{ $cat->description ?? 'No description.' }}</p>

                <button wire:click="edit({{ $cat->id }})" class="mt-4 w-full py-2 rounded-lg bg-zinc-900 text-slate-300 text-xs font-bold hover:bg-red-600 hover:text-white transition">
                    Edit Category
                </button>
            </div>
        @empty
            <div class="col-span-full py-12 text-center bg-[#111111] border border-slate-800 rounded-2xl">
                <p class="text-slate-500 font-medium">No categories found. Create "Action", "Thriller", or "Series" to get started.</p>
            </div>
        @endforelse
    </div>

    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
            <div class="bg-[#111111] border border-slate-800 w-full max-w-md rounded-2xl shadow-2xl overflow-hidden p-6">
                <h2 class="text-xl font-bold text-white mb-6">{{ $editingId ? 'Edit Category' : 'Create Category' }}</h2>

                <form wire:submit="save" class="space-y-5">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Name</label>
                        <input type="text" wire:model="name" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-red-500">
                        @error('name') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Description (Optional)</label>
                        <textarea wire:model="description" rows="3" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-3 text-white focus:border-red-500"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Poster Image (Optional)</label>
                        <input type="file" wire:model="poster" accept="image/*" class="w-full bg-black text-slate-300 p-2 border border-slate-700 rounded-xl text-sm">
                        <p class="text-[10px] text-slate-500 mt-1">If left empty, a stylish letter icon will be generated automatically.</p>
                        @error('poster') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex gap-3 pt-4 border-t border-slate-800">
                        <button type="submit" wire:loading.attr="disabled" class="flex-1 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition">
                            <span wire:loading.remove wire:target="save">{{ $editingId ? 'Update' : 'Create' }}</span>
                            <span wire:loading wire:target="save">Saving...</span>
                        </button>
                        <button type="button" wire:click="$set('showModal', false)" class="flex-1 py-3 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-700 text-slate-300 font-medium transition">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
