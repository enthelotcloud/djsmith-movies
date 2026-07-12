<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithFileUploads;

    public $editingId = null;
    public $name, $description, $poster;
    public $showModal = false;

    #[Computed]
    public function categories() {
        return DB::table('moviecategories')->orderBy('name')->get();
    }

    public function save() {
        $this->validate(['name' => 'required|unique:moviecategories,name,' . $this->editingId]);

        $data = [
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'description' => $this->description,
        ];

        if ($this->poster) {
            $data['poster'] = $this->poster->store('posters', 'b2');
        }

        if ($this->editingId) {
            DB::table('moviecategories')->where('id', $this->editingId)->update($data + ['updated_at' => now()]);
        } else {
            DB::table('moviecategories')->insert($data + ['created_at' => now()]);
        }

        $this->showModal = false;
        $this->reset(['name', 'description', 'poster', 'editingId']);
    }

    public function edit($id) {
        $cat = DB::table('moviecategories')->find($id);
        $this->editingId = $cat->id;
        $this->name = $cat->name;
        $this->description = $cat->description;
        $this->showModal = true;
    }
};
?>

<div class="max-w-5xl mx-auto p-6">
    <button wire:click="$set('showModal', true)" class="bg-red-600 px-4 py-2 rounded-lg text-white font-bold">+ New Category</button>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mt-8">
        @foreach($this->categories as $cat)
            <div class="bg-[#111111] p-4 rounded-xl border border-slate-800 text-center">
                {{-- Dynamic Letter Icon if no poster --}}
                @if($cat->poster)
                    <img src="{{ Storage::disk('b2')->url($cat->poster) }}" class="w-20 h-20 mx-auto rounded-full object-cover">
                @else
                    <div class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-red-600 to-black flex items-center justify-center text-white text-3xl font-black">
                        {{ Str::upper(Str::substr($cat->name, 0, 1)) }}
                    </div>
                @endif
                <h3 class="mt-4 font-bold text-white">{{ $cat->name }}</h3>
                <button wire:click="edit({{ $cat->id }})" class="text-xs text-slate-500 mt-2">Edit</button>
            </div>
        @endforeach
    </div>

    {{-- Modal logic same as previous components... --}}
</div>
