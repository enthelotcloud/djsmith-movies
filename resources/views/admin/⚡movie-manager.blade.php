<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

new class extends Component {
    use WithFileUploads;

    public $currentView = 'list';
    public $editingId = null;

    // TMDB Search State
    public $searchQuery = '';
    public $searchResults = [];
    public $isSearching = false;
    public $tmdbError = '';

    // Form State
    public $title;
    public $description;
    public $moviecategory_id;
    public $poster_path;
    public $is_premium = true;
    public $status = 'ready';
    public $duration_minutes = 120;

    // Upload & Link State
    public $uploadMethod = 'link'; // Default to 'link' for your 4TB catalog
    public $manual_video_path = '';
    public $video;

    #[Computed]
    public function movies()
    {
        return DB::table('movies')
            ->leftJoin('moviecategories', 'movies.moviecategory_id', '=', 'moviecategories.id')
            ->select('movies.*', 'moviecategories.name as category_name')
            ->orderBy('movies.created_at', 'desc')
            ->get();
    }

    #[Computed]
    public function categories()
    {
        return DB::table('moviecategories')->orderBy('name')->get();
    }

    // --- NAVIGATION LOGIC ---
    public function showCreateForm()
    {
        $this->resetForm();
        $this->currentView = 'form';
    }

    public function showList()
    {
        $this->currentView = 'list';
        $this->resetForm();
    }

    public function resetForm()
    {
        $this->reset([
            'editingId', 'title', 'description', 'moviecategory_id', 'poster_path',
            'manual_video_path', 'video', 'searchQuery', 'searchResults', 'tmdbError'
        ]);
        $this->status = 'ready';
        $this->is_premium = true;
        $this->uploadMethod = 'link';
    }

    // --- TMDB AJAX LOGIC ---
    public function updatedSearchQuery($value)
    {
        $this->tmdbError = '';

        if (strlen($value) >= 3) {
            $this->searchTmdb();
        } else {
            $this->searchResults = [];
        }
    }

    public function searchTmdb()
    {
        $this->isSearching = true;

        try {
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->get('https://api.themoviedb.org/3/search/movie', [
                    'query' => $this->searchQuery,
                    'include_adult' => false,
                ]);

            if ($response->successful()) {
                $this->searchResults = collect($response->json('results'))->take(6)->toArray();
            } else {
                $this->tmdbError = "TMDB API Error: " . $response->status();
            }
        } catch (\Exception $e) {
            $this->tmdbError = "Failed to connect to TMDB.";
            Log::error('TMDB Search Error: ' . $e->getMessage());
        }

        $this->isSearching = false;
    }

    public function selectMovie($tmdbId)
    {
        try {
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))->get("https://api.themoviedb.org/3/movie/{$tmdbId}");

            if ($response->successful()) {
                $movie = $response->json();
                $this->title = $movie['title'];
                $this->description = $movie['overview'];
                if (isset($movie['runtime'])) $this->duration_minutes = $movie['runtime'];

                // Auto-download poster to B2
                if (isset($movie['poster_path'])) {
                    $imageContent = Http::get("https://image.tmdb.org/t/p/w780" . $movie['poster_path'])->body();
                    $filename = 'posters/' . Str::slug($this->title) . '-' . time() . '.jpg';
                    Storage::disk('b2')->put($filename, $imageContent);
                    $this->poster_path = $filename;
                }

                $this->searchResults = [];
                $this->searchQuery = '';
            }
        } catch (\Exception $e) {
            $this->tmdbError = "Failed to fetch movie details.";
        }
    }

    // --- CRUD LOGIC ---
    public function edit($id)
    {
        $this->resetForm();
        $movie = DB::table('movies')->where('id', $id)->first();

        $this->editingId = $movie->id;
        $this->title = $movie->title;
        $this->moviecategory_id = $movie->moviecategory_id;
        $this->description = $movie->description;
        $this->manual_video_path = $movie->video_path;
        $this->poster_path = $movie->thumbnail_path;
        $this->is_premium = (bool) $movie->is_premium;
        $this->status = $movie->status;
        $this->duration_minutes = floor($movie->duration_in_seconds / 60);
        $this->uploadMethod = 'link'; // Always default to link when editing

        $this->currentView = 'form';
    }

    public function saveMovie()
    {
        $rules = [
            'title' => 'required|string|max:255',
            'moviecategory_id' => 'required|integer',
            'status' => 'required|in:uploading,processing,ready,hidden',
        ];

        if (!$this->editingId) {
            if ($this->uploadMethod === 'file') {
                $rules['video'] = 'required|mimes:mp4,mkv,avi,webm|max:1500000'; // 1.5GB Max
            } else {
                $rules['manual_video_path'] = 'required|string';
            }
        }

        $this->validate($rules);

        $data = [
            'moviecategory_id' => $this->moviecategory_id,
            'title' => $this->title,
            'slug' => Str::slug($this->title) . '-' . uniqid(),
            'description' => $this->description,
            'thumbnail_path' => $this->poster_path,
            'duration_in_seconds' => $this->duration_minutes * 60,
            'is_premium' => $this->is_premium,
            'status' => $this->status,
            'updated_at' => now(),
            'video_disk' => 'b2',
        ];

        // Determine final path based on upload method
        if ($this->uploadMethod === 'file' && $this->video) {
            $data['video_path'] = $this->video->storeAs(
                'movies/' . Str::slug($this->title),
                $this->video->getClientOriginalName(),
                'b2'
            );
        } elseif ($this->uploadMethod === 'link') {
            $data['video_path'] = $this->manual_video_path;
        }

        if ($this->editingId) {
            // Prevent overwriting the path if they edit but don't provide a new file
            if ($this->uploadMethod === 'file' && !$this->video) {
                unset($data['video_path']);
            }
            DB::table('movies')->where('id', $this->editingId)->update($data);
            $this->dispatch('notify-toast', type: 'success', message: 'Movie updated successfully!');
        } else {
            $data['created_at'] = now();
            DB::table('movies')->insert($data);
            $this->dispatch('notify-toast', type: 'success', message: 'Movie linked & saved!');
        }

        $this->showList();
        unset($this->movies);
    }

    public function delete($id)
    {
        DB::table('movies')->where('id', $id)->delete();
        unset($this->movies);
        $this->dispatch('notify-toast', type: 'success', message: 'Movie deleted.');
    }
};
?>

<div class="max-w-6xl mx-auto space-y-8 relative" x-data @notify-toast.window="alert($event.detail.message)">

    {{-- HEADER --}}
    <div class="flex justify-between items-center bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Movie Studio</h1>
            <p class="text-slate-400 mt-1">Manage your VOD catalog and Backblaze storage.</p>
        </div>
        @if($currentView === 'list')
            <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm">
                + Add Movie
            </button>
        @else
            <button wire:click="showList" class="px-6 py-3 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-700 text-white font-bold transition shadow-sm">
                ← Back to Catalog
            </button>
        @endif
    </div>

    @if($currentView === 'list')
        {{-- ══════════════════ LIST VIEW ══════════════════ --}}
        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Title & Poster</th>
                            <th class="px-6 py-4">Category</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @forelse($this->movies as $movie)
                            <tr class="hover:bg-zinc-900/50 transition-colors">
                                <td class="px-6 py-4 flex items-center gap-4">
                                    @if($movie->thumbnail_path)
                                        <img src="{{ Storage::disk('b2')->url($movie->thumbnail_path) }}" class="w-10 h-14 object-cover rounded shadow">
                                    @else
                                        <div class="w-10 h-14 bg-zinc-800 rounded flex items-center justify-center text-[8px]">No Img</div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white text-base">{{ $movie->title }}</div>
                                        @if($movie->is_premium)
                                            <span class="text-[10px] font-bold text-amber-500 uppercase tracking-wider">Premium Plan</span>
                                        @else
                                            <span class="text-[10px] font-bold text-emerald-500 uppercase tracking-wider">Free to Watch</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4">{{ $movie->category_name ?? 'Uncategorized' }}</td>
                                <td class="px-6 py-4">
                                    @if($movie->status === 'ready')
                                        <span class="px-2.5 py-1 rounded bg-emerald-950/50 text-emerald-500 border border-emerald-900/50 text-[10px] font-bold uppercase">Ready</span>
                                    @else
                                        <span class="px-2.5 py-1 rounded bg-amber-950/50 text-amber-500 border border-amber-900/50 text-[10px] font-bold uppercase">{{ $movie->status }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right space-x-3">
                                    <button wire:click="edit({{ $movie->id }})" class="text-indigo-400 hover:text-indigo-300 font-bold transition">Edit</button>
                                    <button wire:click="delete({{ $movie->id }})" wire:confirm="Delete this movie entirely?" class="text-red-500 hover:text-red-400 font-bold transition">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500">Your catalog is empty. Time to add some movies!</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        {{-- ══════════════════ FORM / UPLOAD VIEW ══════════════════ --}}

        {{-- AJAX TMDB Search --}}
        @if(!$editingId)
            <div class="bg-black border border-slate-800 rounded-2xl shadow-lg p-6 relative z-20">
                <h3 class="text-lg font-bold text-white mb-4">1. Auto-Fill Metadata with TMDB</h3>

                <div class="relative">
                    <div class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none">
                        <svg class="w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <input type="text" wire:model.live.debounce.500ms="searchQuery" placeholder="Type a movie name..." class="w-full bg-[#111111] border border-slate-700 rounded-xl pl-12 pr-4 py-4 text-white focus:border-red-500 focus:ring-1 focus:ring-red-500 transition">

                    <div wire:loading wire:target="searchQuery" class="absolute inset-y-0 right-0 flex items-center pr-4">
                        <svg class="w-5 h-5 animate-spin text-red-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    </div>
                </div>

                @if($tmdbError)
                    <p class="text-sm text-red-500 mt-2 font-bold">{{ $tmdbError }}</p>
                @endif

                @if(count($searchResults) > 0)
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
                        @foreach($searchResults as $result)
                            <div wire:click="selectMovie({{ $result['id'] }})" class="cursor-pointer group relative">
                                @if(isset($result['poster_path']) && $result['poster_path'])
                                    <img src="https://image.tmdb.org/t/p/w200{{ $result['poster_path'] }}" class="w-full h-auto rounded-lg shadow-lg group-hover:ring-2 ring-red-500 transition opacity-80 group-hover:opacity-100">
                                @else
                                    <div class="w-full aspect-[2/3] bg-zinc-900 rounded-lg flex items-center justify-center text-xs text-slate-500 text-center p-2 border border-slate-800">No Image</div>
                                @endif
                                <div class="mt-2 text-xs font-bold text-slate-300 truncate group-hover:text-red-500">{{ $result['title'] ?? 'Unknown' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Form Details --}}
        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-6 relative z-10">
            <h3 class="text-lg font-bold text-white mb-6">{{ $editingId ? 'Edit Movie Details' : '2. Metadata & Video Link' }}</h3>

            <form wire:submit="saveMovie" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

                    {{-- Poster Preview --}}
                    <div class="col-span-1">
                        <label class="block text-xs font-bold text-slate-500 mb-2 uppercase">Movie Poster</label>
                        @if($poster_path)
                            <img src="{{ Storage::disk('b2')->url($poster_path) }}" class="w-full rounded-xl shadow-2xl border border-slate-700">
                            <p class="text-[10px] text-emerald-500 mt-2 font-bold uppercase tracking-widest text-center">✓ Saved to B2</p>
                        @else
                            <div class="w-full aspect-[2/3] bg-black border-2 border-dashed border-slate-800 rounded-xl flex items-center justify-center text-slate-600 text-xs text-center p-4">
                                Search TMDB above to generate poster
                            </div>
                        @endif
                    </div>

                    {{-- Details --}}
                    <div class="col-span-1 md:col-span-3 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Title</label>
                                <input type="text" wire:model="title" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-red-500">
                                @error('title') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Category</label>
                                <select wire:model="moviecategory_id" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-red-500">
                                    <option value="">Select Category...</option>
                                    @foreach($this->categories as $cat)
                                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                    @endforeach
                                </select>
                                @error('moviecategory_id') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Synopsis</label>
                            <textarea wire:model="description" rows="3" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white focus:border-red-500"></textarea>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 mb-1 uppercase">Status</label>
                                <select wire:model="status" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white">
                                    <option value="ready">Ready (Visible)</option>
                                    <option value="hidden">Hidden</option>
                                    <option value="processing">Processing</option>
                                </select>
                            </div>
                            <div class="flex items-center mt-6">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input type="checkbox" wire:model="is_premium" class="w-5 h-5 rounded border-slate-700 bg-black text-red-600 focus:ring-red-600">
                                    <span class="text-sm font-bold text-white">Premium Content</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 🚨 THE NEW SOURCE TOGGLE 🚨 --}}
                <div class="pt-6 border-t border-slate-800">
                    <div class="flex items-center justify-between mb-4">
                        <label class="block text-xs font-bold text-slate-500 uppercase">Video Source</label>
                        <div class="bg-black border border-slate-800 rounded-lg p-1 flex">
                            <button type="button" wire:click="$set('uploadMethod', 'link')" class="px-4 py-1.5 text-sm font-bold rounded-md transition {{ $uploadMethod === 'link' ? 'bg-zinc-800 text-white shadow' : 'text-slate-500 hover:text-white' }}">Manual Link</button>
                            <button type="button" wire:click="$set('uploadMethod', 'file')" class="px-4 py-1.5 text-sm font-bold rounded-md transition {{ $uploadMethod === 'file' ? 'bg-zinc-800 text-white shadow' : 'text-slate-500 hover:text-white' }}">Browser Upload</button>
                        </div>
                    </div>

                    @if($uploadMethod === 'link')
                        <div>
                            <input type="text" wire:model="manual_video_path" placeholder="e.g. movies/action/die-hard.mp4" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-3 font-mono text-slate-300 focus:border-red-500 focus:ring-1 focus:ring-red-500 transition">
                            <p class="text-[11px] text-slate-500 mt-2">Paste the exact path of the file you uploaded via the Backblaze CLI.</p>
                            @error('manual_video_path') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @else
                        <div x-data="{ isUploading: false, progress: 0 }"
                             x-on:livewire-upload-start="isUploading = true"
                             x-on:livewire-upload-finish="isUploading = false; progress = 100"
                             x-on:livewire-upload-error="isUploading = false"
                             x-on:livewire-upload-progress="progress = $event.detail.progress">

                            <div class="relative flex items-center justify-center w-full">
                                <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-700 border-dashed rounded-xl cursor-pointer bg-black hover:bg-zinc-900 transition hover:border-red-500" :class="{'border-red-500 bg-zinc-900': isUploading}">
                                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                        <svg x-show="!isUploading" class="w-8 h-8 mb-3 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                        <p class="text-sm text-slate-400" x-show="!isUploading"><span class="font-bold text-white">Click to select file</span> (Max 1.5GB)</p>
                                        <p class="text-sm font-bold text-red-500" x-show="isUploading" x-text="`Uploading: ${progress}%`"></p>
                                    </div>
                                    <input type="file" wire:model="video" class="hidden" accept="video/mp4,video/x-m4v,video/*" :disabled="isUploading" />
                                </label>
                            </div>
                            @error('video') <span class="text-xs text-red-500 mt-1 block">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>

                <div class="pt-4">
                    <button type="submit" wire:loading.attr="disabled" class="w-full py-4 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition text-lg disabled:opacity-50 flex justify-center items-center gap-2">
                        <span wire:loading.remove wire:target="saveMovie">{{ $editingId ? 'Update Movie' : 'Save Movie to Catalog' }}</span>
                        <span wire:loading wire:target="saveMovie">Saving...</span>
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>
