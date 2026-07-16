<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithFileUploads;

    public $currentView = 'list';
    public $editingId = null;

    // TMDB Search State
    public $searchQuery = '';
    public $searchResults = [];
    public $isSearching = false;
    public $tmdbError = '';
    public $savePosterToB2 = true;

    // Form State
    public $type = 'movie';
    public $title;
    public $description;
    public $selectedCategories = [];
    public $poster_path;
    public $poster_upload;
    public $is_premium = true;
    public $status = 'ready';
    public $duration_minutes = 120;

    // Upload & Link State
    public $uploadMethod = 'link';
    public $manual_video_path = '';
    public $video;

    // Error tracking
    public $formErrors = [];

    #[Computed]
    public function movies()
    {
        return DB::table('movies')->orderBy('created_at', 'desc')->get();
    }

    #[Computed]
    public function categories()
    {
        return DB::table('moviecategories')->orderBy('name')->get();
    }

    public function getMovieCategories($movieId)
    {
        return DB::table('category_movie')
            ->join('moviecategories', 'category_movie.moviecategory_id', '=', 'moviecategories.id')
            ->where('category_movie.movie_id', $movieId)
            ->pluck('moviecategories.name')
            ->toArray();
    }

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
            'editingId', 'title', 'description', 'selectedCategories', 'poster_path', 'poster_upload',
            'manual_video_path', 'video', 'searchQuery', 'searchResults', 'tmdbError', 'formErrors'
        ]);
        $this->type = 'movie';
        $this->status = 'ready';
        $this->is_premium = true;
        $this->uploadMethod = 'link';
        $this->duration_minutes = 120;
    }

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
        $this->tmdbError = '';

        try {
            if (!env('TMDB_BEARER_TOKEN')) {
                throw new \Exception('TMDB API token not configured');
            }

            $endpoint = $this->type === 'series' ? 'search/tv' : 'search/movie';
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/{$endpoint}", [
                    'query' => $this->searchQuery,
                    'include_adult' => false,
                ]);

            if ($response->successful()) {
                $this->searchResults = collect($response->json('results'))->take(6)->toArray();
                if (empty($this->searchResults)) {
                    $this->tmdbError = "No results found for '{$this->searchQuery}'";
                }
            } else {
                $this->tmdbError = "TMDB API Error: " . $response->status();
            }
        } catch (\Exception $e) {
            $this->tmdbError = "Search failed: " . $e->getMessage();
        }

        $this->isSearching = false;
    }

    public function selectMovie($tmdbId)
    {
        $this->tmdbError = '';

        try {
            if (!env('TMDB_BEARER_TOKEN')) throw new \Exception('TMDB API token not configured');

            $endpoint = $this->type === 'series' ? 'tv' : 'movie';
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/{$endpoint}/{$tmdbId}");

            if ($response->successful()) {
                $media = $response->json();
                $this->title = $this->type === 'series' ? ($media['name'] ?? '') : ($media['title'] ?? '');
                $this->description = $media['overview'] ?? '';

                if (isset($media['poster_path']) && $media['poster_path']) {
                    $tmdbUrl = "https://image.tmdb.org/t/p/w780" . $media['poster_path'];

                    if ($this->savePosterToB2) {
                        try {
                            $imageContent = Http::timeout(30)->get($tmdbUrl)->body();
                            $filename = 'posters/' . Str::slug($this->title) . '-' . time() . '.jpg';

                            if (Storage::disk('b2')->put($filename, $imageContent)) {
                                $this->poster_path = $filename;
                                $this->dispatch('notify-toast', type: 'success', message: 'Poster saved to B2 successfully!');
                            }
                        } catch (\Exception $e) {
                            $this->poster_path = $tmdbUrl;
                            $this->dispatch('notify-toast', type: 'warning', message: 'Could not save to B2, using TMDB URL instead');
                        }
                    } else {
                        $this->poster_path = $tmdbUrl;
                    }
                }

                $this->poster_upload = null;
                $this->searchResults = [];
                $this->searchQuery = '';
            }
        } catch (\Exception $e) {
            $this->tmdbError = "Failed to fetch details: " . $e->getMessage();
            $this->dispatch('notify-toast', type: 'error', message: $this->tmdbError);
        }
    }

    public function edit($id)
    {
        $this->resetForm();

        try {
            $movie = DB::table('movies')->where('id', $id)->first();
            if (!$movie) throw new \Exception('Movie not found');

            $this->editingId = $movie->id;
            $this->type = $movie->type ?? 'movie';
            $this->title = $movie->title;
            $this->description = $movie->description;
            $this->manual_video_path = $movie->video_path;
            $this->poster_path = $movie->thumbnail;
            $this->is_premium = (bool) ($movie->is_premium ?? false);
            $this->status = $movie->status ?? 'ready';
            $this->duration_minutes = floor(($movie->duration_in_seconds ?? 0) / 60);

            $this->selectedCategories = DB::table('category_movie')
                ->where('movie_id', $id)
                ->pluck('moviecategory_id')
                ->toArray();

            $this->currentView = 'form';
        } catch (\Exception $e) {
            $this->dispatch('notify-toast', type: 'error', message: 'Failed to load movie for editing');
            $this->showList();
        }
    }

    // Instantly catch if the user uploads a 10MB image instead of dying silently
    public function updatedPosterUpload()
    {
        try {
            $this->validateOnly('poster_upload', [
                'poster_upload' => 'image|max:3072|mimes:jpg,jpeg,png,webp'
            ]);
            unset($this->formErrors['poster_upload']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->formErrors['poster_upload'] = $e->errors()['poster_upload'];
            $this->poster_upload = null;
            $this->dispatch('notify-toast', type: 'error', message: $this->formErrors['poster_upload'][0]);
        }
    }

    public function saveMovie()
    {
        $this->formErrors = [];

        try {
            $this->validate([
                'title' => 'required|min:1|max:255',
                'type' => 'required|in:movie,series',
                'selectedCategories' => 'required|array|min:1',
                'poster_upload' => 'nullable|image|max:3072|mimes:jpg,jpeg,png,webp',
                'manual_video_path' => 'nullable|string|max:500',
                'video' => 'nullable|file|max:2048000',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->formErrors = $e->errors();
            $this->dispatch('notify-toast', type: 'error', message: 'Please fix the validation errors');
            throw $e;
        }

        try {
            DB::beginTransaction();

            // 1. Process custom poster upload first
            if ($this->poster_upload) {
                $filename = 'posters/' . Str::slug($this->title) . '-' . time() . '.' . $this->poster_upload->getClientOriginalExtension();
                $stored = $this->poster_upload->storeAs('posters', $filename, 'b2');

                if (!$stored) throw new \Exception('Failed to store poster on B2');

                $this->poster_path = $filename;
            }

            if (!$this->poster_path && !$this->poster_upload) {
                throw new \Exception('A poster image is required');
            }

            // 2. Prepare Data (Removed the ghost 'thumbnail_path' column!)
            $data = [
                'type' => $this->type,
                'title' => $this->title,
                'slug' => Str::slug($this->title) . '-' . uniqid(),
                'description' => $this->description,
                'thumbnail' => $this->poster_path, // ONLY use thumbnail
                'duration_in_seconds' => ($this->duration_minutes ?? 0) * 60,
                'is_premium' => $this->is_premium,
                'status' => $this->status,
                'updated_at' => now(),
            ];

            if ($this->type === 'movie') {
                $data['video_disk'] = 'b2';

                if ($this->uploadMethod === 'file' && $this->video) {
                    $videoPath = $this->video->storeAs('movies/' . Str::slug($this->title), $this->video->getClientOriginalName(), 'b2');
                    if (!$videoPath) throw new \Exception('Failed to upload video to B2');
                    $data['video_path'] = $videoPath;
                } elseif (!empty($this->manual_video_path)) {
                    $data['video_path'] = $this->manual_video_path;
                }
            }

            // 3. Save to Database
            if ($this->editingId) {
                DB::table('movies')->where('id', $this->editingId)->update($data);
                $movieId = $this->editingId;
                $this->dispatch('notify-toast', type: 'success', message: 'Updated successfully!');
            } else {
                $data['created_at'] = now();
                $movieId = DB::table('movies')->insertGetId($data);
                $this->dispatch('notify-toast', type: 'success', message: 'Saved to catalog!');
            }

            // 4. Update Categories
            DB::table('category_movie')->where('movie_id', $movieId)->delete();
            $pivotData = [];
            foreach($this->selectedCategories as $catId) {
                $pivotData[] = ['movie_id' => $movieId, 'moviecategory_id' => $catId, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('category_movie')->insert($pivotData);

            DB::commit();
            $this->showList();

        } catch (\Exception $e) {
            DB::rollBack();
            if (empty($this->formErrors)) {
                $this->dispatch('notify-toast', type: 'error', message: 'Failed to save: ' . $e->getMessage());
            }
        }
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();
            DB::table('category_movie')->where('movie_id', $id)->delete();
            DB::table('movies')->where('id', $id)->delete();
            DB::commit();

            unset($this->movies);
            $this->dispatch('notify-toast', type: 'success', message: 'Deleted from database.');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->dispatch('notify-toast', type: 'error', message: 'Failed to delete: ' . $e->getMessage());
        }
    }
};
?>

<div class="max-w-6xl mx-auto space-y-8 relative" x-data @notify-toast.window="Flux.toast({ text: $event.detail.message, variant: $event.detail.type })">

    <div class="flex justify-between items-center bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Movie Studio</h1>
            <p class="text-slate-400 mt-1 text-sm">Manage your VOD catalog and Backblaze storage.</p>
        </div>
        @if($currentView === 'list')
            <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm active:scale-95">+ Add Content</button>
        @else
            <button wire:click="showList" class="px-6 py-3 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-700 text-white font-bold transition shadow-sm">← Back to Catalog</button>
        @endif
    </div>

    @if($currentView === 'list')
        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Title & Poster</th>
                            <th class="px-6 py-4">Categories</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @forelse($this->movies as $movie)
                            <tr class="hover:bg-zinc-900/50 transition-colors">
                                <td class="px-6 py-4 flex items-center gap-4">
                                    @if($movie->thumbnail)
                                        <img src="{{ str_starts_with($movie->thumbnail, 'http') ? $movie->thumbnail : Storage::disk('b2')->url($movie->thumbnail) }}" class="w-10 h-14 object-cover rounded shadow border border-slate-700" alt="{{ $movie->title }}">
                                    @else
                                        <div class="w-10 h-14 bg-zinc-800 rounded flex items-center justify-center text-[8px]">No Img</div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white text-base flex items-center gap-2">
                                            {{ $movie->title }}
                                            <span class="px-1.5 py-0.5 rounded bg-zinc-800 text-slate-400 text-[9px] uppercase tracking-wider">{{ $movie->type }}</span>
                                        </div>
                                        <span class="text-[10px] font-bold {{ $movie->is_premium ? 'text-amber-500' : 'text-emerald-500' }} uppercase tracking-wider">
                                            {{ $movie->is_premium ? 'Premium Plan' : 'Free' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($this->getMovieCategories($movie->id) as $catName)
                                            <span class="px-2 py-0.5 rounded-full bg-zinc-800 text-slate-300 text-[10px] border border-slate-700">{{ $catName }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded {{ $movie->status === 'ready' ? 'bg-emerald-950/50 text-emerald-500' : 'bg-amber-950/50 text-amber-500' }} text-[10px] font-bold uppercase">
                                        {{ $movie->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right space-x-3">
                                    <button wire:click="edit({{ $movie->id }})" class="text-indigo-400 hover:text-indigo-300 font-bold transition">Edit</button>
                                    <button wire:click="delete({{ $movie->id }})" wire:confirm="Delete this asset?" class="text-red-500 hover:text-red-400 font-bold transition">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500 italic">No content found in the catalog.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="flex items-center gap-4 mb-6">
            <button wire:click="$set('type', 'movie')" class="flex-1 py-3 rounded-xl font-bold transition border {{ $type === 'movie' ? 'bg-red-600 border-red-500 text-white shadow-lg' : 'bg-black border-slate-800 text-slate-400 hover:text-white' }}">Standalone Movie</button>
            <button wire:click="$set('type', 'series')" class="flex-1 py-3 rounded-xl font-bold transition border {{ $type === 'series' ? 'bg-indigo-600 border-indigo-500 text-white shadow-lg' : 'bg-black border-slate-800 text-slate-400 hover:text-white' }}">TV Series</button>
        </div>

        @if(!$editingId)
            <div class="bg-black border border-slate-800 rounded-2xl shadow-lg p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-white">1. Auto-Fill Metadata</h3>
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-bold text-slate-400">
                        <input type="checkbox" wire:model.live="savePosterToB2" class="rounded bg-zinc-900 text-red-600 border-slate-700">
                        Auto-Store Poster to B2
                    </label>
                </div>
                <input type="text" wire:model.live.debounce.500ms="searchQuery" placeholder="Search for {{ $type }}..." class="w-full bg-[#111111] border border-slate-700 rounded-xl px-4 py-4 text-white focus:ring-1 focus:ring-red-600">

                @if($tmdbError)
                    <div class="mt-4 p-3 bg-red-950/30 border border-red-800 rounded-lg text-red-400 text-sm">{{ $tmdbError }}</div>
                @endif

                @if(count($searchResults) > 0)
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
                        @foreach($searchResults as $result)
                            <div wire:click="selectMovie({{ $result['id'] }})" class="cursor-pointer group">
                                <div class="relative aspect-[2/3] overflow-hidden rounded-lg">
                                    @if(isset($result['poster_path']))
                                        <img src="https://image.tmdb.org/t/p/w200{{ $result['poster_path'] }}" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition" alt="{{ $result['title'] ?? $result['name'] ?? 'Poster' }}">
                                    @endif
                                    <div class="absolute inset-0 bg-red-600/0 group-hover:bg-red-600/20 transition"></div>
                                </div>
                                <div class="mt-2 text-[11px] font-bold text-slate-400 truncate group-hover:text-white">{{ $result['title'] ?? $result['name'] }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-6">
            <form wire:submit="saveMovie" class="space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <div class="col-span-1 space-y-4"
                         x-data="{ isUploading: false, progress: 0 }"
                         x-on:livewire-upload-start="isUploading = true"
                         x-on:livewire-upload-finish="isUploading = false; progress = 0"
                         x-on:livewire-upload-error="isUploading = false; progress = 0"
                         x-on:livewire-upload-progress="progress = $event.detail.progress">

                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Poster Preview</label>

                        <div class="relative w-full aspect-[2/3] rounded-xl overflow-hidden bg-black border border-slate-800 shadow-2xl flex items-center justify-center">
                            @if($poster_upload)
                                <img src="{{ $poster_upload->temporaryUrl() }}" class="w-full h-full object-cover" alt="Poster preview">
                                <div class="absolute inset-0 border-2 border-red-600 rounded-xl"></div>
                            @elseif($poster_path)
                                <img src="{{ str_starts_with($poster_path, 'http') ? $poster_path : Storage::disk('b2')->url($poster_path) }}" class="w-full h-full object-cover" alt="Poster preview">
                            @else
                                <span class="text-slate-800 font-black text-2xl uppercase italic">No File</span>
                            @endif

                            <div x-show="isUploading" class="absolute inset-0 bg-black/90 flex flex-col items-center justify-center p-4" style="display: none;">
                                <div class="text-red-600 font-bold text-xs mb-2 italic">UPLOADING...</div>
                                <div class="w-full bg-zinc-800 h-1.5 rounded-full overflow-hidden">
                                    <div class="bg-red-600 h-full transition-all" :style="'width: ' + progress + '%'"></div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-2">
                            <input type="file" wire:model="poster_upload" id="poster_upload" class="sr-only" accept="image/png, image/jpeg, image/jpg, image/webp">
                            <label for="poster_upload" class="block w-full text-center py-2.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-white text-xs font-bold border border-slate-700 cursor-pointer transition">
                                Upload New Image
                            </label>

                            @if(isset($formErrors['poster_upload']))
                                <p class="text-[10px] text-red-500 font-bold mt-2 text-center leading-tight">{{ $formErrors['poster_upload'][0] }}</p>
                            @elseif($poster_path && !str_starts_with($poster_path, 'http'))
                                <p class="text-[9px] text-emerald-500 font-bold mt-2 text-center uppercase tracking-tighter">✓ Stored on B2</p>
                            @endif
                        </div>
                    </div>

                    <div class="col-span-1 md:col-span-3 space-y-4">
                        <div>
                            <input type="text" wire:model="title" placeholder="Entry Title" class="w-full bg-black border {{ isset($formErrors['title']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-lg font-bold">
                            @if(isset($formErrors['title'])) <p class="text-red-500 text-xs mt-1">{{ $formErrors['title'][0] }}</p> @endif
                        </div>

                        <textarea wire:model="description" rows="4" placeholder="Synopsis..." class="w-full bg-black border border-slate-700 rounded-xl px-4 py-3 text-slate-300"></textarea>

                        <div class="bg-black border border-slate-800 rounded-xl p-4">
                            <label class="block text-xs font-bold text-slate-500 mb-3 uppercase tracking-widest">Category Taxonomy</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                @foreach($this->categories as $cat)
                                    <label class="flex items-center gap-2 cursor-pointer text-slate-400 hover:text-white">
                                        <input type="checkbox" wire:model="selectedCategories" value="{{ $cat->id }}" class="rounded bg-zinc-900 text-red-600 border-slate-700">
                                        <span class="text-xs">{{ $cat->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @if(isset($formErrors['selectedCategories'])) <p class="text-red-500 text-xs mt-2">{{ $formErrors['selectedCategories'][0] }}</p> @endif
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Publish Status</label>
                                <select wire:model="status" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-3 text-white text-sm">
                                    <option value="ready">Live / Ready</option>
                                    <option value="hidden">Hidden / Draft</option>
                                </select>
                            </div>
                            <div class="flex items-center pt-6">
                                <label class="flex items-center gap-3 cursor-pointer text-white">
                                    <input type="checkbox" wire:model="is_premium" class="rounded bg-black text-red-600 border-slate-700">
                                    <span class="text-xs font-bold uppercase tracking-widest text-amber-500">Premium Content</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                @if($type === 'movie')
                    <div class="pt-8 border-t border-slate-800">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-black text-white uppercase tracking-widest">Video Stream Asset</h3>
                            <div class="bg-black border border-slate-800 rounded-lg p-1 flex">
                                <button type="button" wire:click="$set('uploadMethod', 'link')" class="px-4 py-1.5 text-[10px] font-black rounded-md {{ $uploadMethod === 'link' ? 'bg-zinc-800 text-white' : 'text-slate-500' }}">DIRECT LINK / PATH</button>
                                <button type="button" wire:click="$set('uploadMethod', 'file')" class="px-4 py-1.5 text-[10px] font-black rounded-md {{ $uploadMethod === 'file' ? 'bg-zinc-800 text-white' : 'text-slate-500' }}">FILE UPLOAD</button>
                            </div>
                        </div>

                        @if($uploadMethod === 'link')
                            <input type="text" wire:model="manual_video_path" placeholder="e.g. movies/john-wick/playlist.m3u8" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-4 font-mono text-sm text-red-400">
                        @else
                            <div x-data="{ isUploading: false, progress: 0 }"
                                 x-on:livewire-upload-start="isUploading = true"
                                 x-on:livewire-upload-finish="isUploading = false"
                                 x-on:livewire-upload-error="isUploading = false"
                                 x-on:livewire-upload-progress="progress = $event.detail.progress"
                                 class="space-y-4">
                                <input type="file" wire:model="video" class="w-full bg-black text-slate-400 p-4 border border-dashed border-slate-800 rounded-xl cursor-pointer hover:border-red-600 transition">

                                <div x-show="isUploading" class="p-6 bg-zinc-950 border border-slate-800 rounded-xl" style="display: none;">
                                    <div class="flex justify-between text-xs font-black text-white mb-3 italic">
                                        <span>UPLOADING RAW VIDEO TO BACKBLAZE B2...</span>
                                        <span x-text="progress + '%'"></span>
                                    </div>
                                    <div class="w-full bg-black h-3 rounded-full overflow-hidden border border-slate-800">
                                        <div class="bg-red-600 h-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                                    </div>
                                </div>
                            </div>
                        @endif
                        @if(isset($formErrors['video'])) <p class="text-red-500 text-xs mt-2">{{ $formErrors['video'][0] }}</p> @endif
                    </div>
                @endif

                <button type="submit" class="w-full py-5 rounded-2xl {{ $type === 'series' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-red-600 hover:bg-red-700' }} text-white font-black text-xl transition shadow-2xl active:scale-[0.99]">
                    {{ $editingId ? 'COMMIT CHANGES' : 'PUBLISH TO CATALOG' }}
                </button>
            </form>
        </div>
    @endif
</div>
