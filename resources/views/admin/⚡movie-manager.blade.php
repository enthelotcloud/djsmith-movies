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

    // Form State
    public $title;
    public $slug;
    public $description;
    public $excerpt;
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

    // NEW: Base64 String for the Encryption Key
    public $enc_key_base64;

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
            'editingId', 'title', 'slug', 'description', 'excerpt', 'selectedCategories', 'poster_path', 'poster_upload',
            'manual_video_path', 'video', 'searchQuery', 'searchResults', 'tmdbError', 'formErrors',
            'enc_key_base64'
        ]);
        $this->status = 'ready';
        $this->is_premium = true;
        $this->uploadMethod = 'link';
        $this->duration_minutes = 120;
    }

    public function updatedTitle($value)
    {
        if (!$this->editingId) {
            $this->slug = Str::slug($value) . '-' . substr(uniqid(), -5);
        }
    }

    public function updatedDescription($value)
    {
        if (empty($this->excerpt)) {
            $this->excerpt = Str::limit($value, 160);
        }
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

            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/search/movie", [
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

            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/movie/{$tmdbId}");

            if ($response->successful()) {
                $media = $response->json();

                $this->title = $media['title'] ?? '';
                $this->description = $media['overview'] ?? '';

                $this->excerpt = Str::limit($this->description, 160);
                if (!$this->editingId) {
                    $this->slug = Str::slug($this->title) . '-' . substr(uniqid(), -5);
                }

                $this->duration_minutes = $media['runtime'] ?? 120;

                if (isset($media['poster_path']) && $media['poster_path']) {
                    $this->poster_path = "https://image.tmdb.org/t/p/w780" . $media['poster_path'];
                }

                $this->poster_upload = null;
                $this->searchResults = [];
                $this->searchQuery = '';
                unset($this->formErrors['poster_upload']);
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
            $this->title = $movie->title;
            $this->slug = $movie->slug;
            $this->description = $movie->description;
            $this->excerpt = $movie->excerpt ?? Str::limit($movie->description, 160);
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

    public function updatedPosterUpload()
    {
        try {
            $this->validateOnly('poster_upload', [
                'poster_upload' => 'image|max:3072|mimes:jpg,jpeg,png,webp'
            ]);
            unset($this->formErrors['poster_upload']);
            if ($this->poster_upload) {
                $this->poster_path = null;
            }
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
                'slug' => 'required|string|max:255',
                'description' => 'required|string|min:10',
                'excerpt' => 'nullable|string|max:255',
                'selectedCategories' => 'required|array|min:1',
                'duration_minutes' => 'required|integer|min:1',
                'status' => 'required|in:ready,hidden',
                'is_premium' => 'boolean',
                'poster_upload' => 'nullable|image|max:3072|mimes:jpg,jpeg,png,webp',
                'manual_video_path' => 'nullable|string|max:500',
                'video' => 'nullable|file|max:2048000',
                'enc_key_base64' => 'nullable|string', // Validating as string now
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->formErrors = $e->errors();
            $this->dispatch('notify-toast', type: 'error', message: 'Please fix the validation errors below.');
            return;
        }

        try {
            DB::beginTransaction();

            $existingEncKey = null;
            if ($this->editingId) {
                $existingEncKey = DB::table('movies')->where('id', $this->editingId)->value('enc_key');
            }

            // ==========================================
            // STEP 1: Process the Base64 Encryption Key
            // ==========================================
            $encKeyPath = $existingEncKey;

            if ($this->enc_key_base64) {
                $keyFilename = $this->slug . '.key';

                if (!Storage::disk('local')->exists('video_keys')) {
                    Storage::disk('local')->makeDirectory('video_keys');
                }

                // Strip out the "data:application/octet-stream;base64," prefix
                $base64String = preg_replace('#^data:.*?;base64,#i', '', $this->enc_key_base64);

                // Decode back to raw binary data
                $keyData = base64_decode($base64String);

                // Write directly to storage bypassing Livewire upload rules entirely
                if (Storage::disk('local')->put('video_keys/' . $keyFilename, $keyData)) {
                    $encKeyPath = 'video_keys/' . $keyFilename;
                    Log::info("Base64 Encryption key saved successfully to: " . $encKeyPath);
                } else {
                    throw new \Exception('Failed to write encryption key to local storage.');
                }
            }

            // ==========================================
            // STEP 2: Process the Poster Upload
            // ==========================================
            if ($this->poster_upload) {
                $filename = 'posters/' . $this->slug . '-' . time() . '.' . $this->poster_upload->getClientOriginalExtension();
                $stored = $this->poster_upload->storeAs('posters', $filename, 'public');

                if (!$stored) throw new \Exception('Failed to store poster locally.');
                $this->poster_path = $filename;
            }

            if (!$this->poster_path && !$this->poster_upload) {
                $this->formErrors['poster_upload'] = ['A movie poster is required.'];
                throw new \Exception('A poster image is required');
            }

            // ==========================================
            // STEP 3: Build Database Payload
            // ==========================================
            $data = [
                'title' => $this->title,
                'slug' => $this->slug,
                'description' => $this->description,
                'excerpt' => $this->excerpt,
                'thumbnail' => $this->poster_path,
                'duration_in_seconds' => (int) $this->duration_minutes * 60,
                'is_premium' => $this->is_premium,
                'status' => $this->status,
                'type' => 'movie',
                'video_disk' => 'b2',
                'enc_key' => $encKeyPath,
                'updated_at' => now(),
            ];

            // ==========================================
            // STEP 4: Process Video Source
            // ==========================================
            if ($this->uploadMethod === 'file' && $this->video) {
                $videoPath = $this->video->storeAs('movies/' . $this->slug, $this->video->getClientOriginalName(), 'b2');
                if (!$videoPath) throw new \Exception('Failed to upload video to B2');
                $data['video_path'] = $videoPath;
            } elseif (!empty($this->manual_video_path)) {
                $data['video_path'] = $this->manual_video_path;
            }

            // ==========================================
            // STEP 5: Commit to Database
            // ==========================================
            if ($this->editingId) {
                DB::table('movies')->where('id', $this->editingId)->update($data);
                $movieId = $this->editingId;
            } else {
                $data['created_at'] = now();
                $movieId = DB::table('movies')->insertGetId($data);
            }

            DB::table('category_movie')->where('movie_id', $movieId)->delete();
            $pivotData = [];
            foreach($this->selectedCategories as $catId) {
                $pivotData[] = [
                    'movie_id' => $movieId,
                    'moviecategory_id' => $catId
                ];
            }
            DB::table('category_movie')->insert($pivotData);

            DB::commit();

            $this->dispatch('notify-toast', type: 'success', message: $this->editingId ? 'Movie updated successfully!' : 'Movie published to catalog!');
            $this->showList();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Movie Save Error: ' . $e->getMessage());
            if (empty($this->formErrors)) {
                $this->dispatch('notify-toast', type: 'error', message: 'Save Failed! Check application logs.');
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
            $this->dispatch('notify-toast', type: 'success', message: 'Movie deleted from database.');
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
            <p class="text-slate-400 mt-1 text-sm">Manage your VOD catalog.</p>
        </div>
        @if($currentView === 'list')
            <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm active:scale-95">+ Add Movie</button>
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
                                        <img src="{{ str_starts_with($movie->thumbnail, 'http') ? $movie->thumbnail : Storage::disk('public')->url($movie->thumbnail) }}" class="w-10 h-14 object-cover rounded shadow border border-slate-700" alt="{{ $movie->title }}">
                                    @else
                                        <div class="w-10 h-14 bg-zinc-800 rounded flex items-center justify-center text-[8px]">No Img</div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white text-base flex items-center gap-2">
                                            {{ $movie->title }}
                                        </div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-[10px] font-bold {{ $movie->is_premium ? 'text-amber-500' : 'text-emerald-500' }} uppercase tracking-wider">
                                                {{ $movie->is_premium ? 'Premium Plan' : 'Free' }}
                                            </span>
                                            @if($movie->enc_key)
                                                <span class="px-1.5 py-0.5 rounded bg-blue-950 text-blue-400 text-[8px] uppercase tracking-wider font-bold border border-blue-900/50" title="Encryption Key Enabled">Encrypted</span>
                                            @endif
                                        </div>
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
                                    <button wire:click="delete({{ $movie->id }})" wire:confirm="Delete this movie?" class="text-red-500 hover:text-red-400 font-bold transition">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500 italic">No movies found in the catalog.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @if(!$editingId)
            <div class="bg-black border border-slate-800 rounded-2xl shadow-lg p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-white">1. Auto-Fill via TMDB (Optional)</h3>
                </div>
                <input type="text" wire:model.live.debounce.500ms="searchQuery" placeholder="Search TMDB for a movie..." class="w-full bg-[#111111] border border-slate-700 rounded-xl px-4 py-4 text-white focus:ring-1 focus:ring-red-600">

                @if($tmdbError)
                    <div class="mt-4 p-3 bg-red-950/30 border border-red-800 rounded-lg text-red-400 text-sm">{{ $tmdbError }}</div>
                @endif

                @if(count($searchResults) > 0)
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
                        @foreach($searchResults as $result)
                            <div wire:click="selectMovie({{ $result['id'] }})" class="cursor-pointer group">
                                <div class="relative aspect-[2/3] overflow-hidden rounded-lg bg-zinc-900 border border-slate-800">
                                    @if(isset($result['poster_path']))
                                        <img src="https://image.tmdb.org/t/p/w200{{ $result['poster_path'] }}" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition" alt="{{ $result['title'] ?? 'Poster' }}">
                                    @endif
                                    <div class="absolute inset-0 bg-red-600/0 group-hover:bg-red-600/20 transition"></div>
                                </div>
                                <div class="mt-2 text-[11px] font-bold text-slate-400 truncate group-hover:text-white">{{ $result['title'] ?? 'Unknown' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-6">
            <form wire:submit="saveMovie" class="space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <!-- POSTER PREVIEW PANEL WITH PROGRESS -->
                    <div class="col-span-1 space-y-4"
                         x-data="{ isUploading: false, progress: 0 }"
                         x-on:livewire-upload-start="isUploading = true"
                         x-on:livewire-upload-finish="isUploading = false; progress = 0"
                         x-on:livewire-upload-error="isUploading = false; progress = 0"
                         x-on:livewire-upload-progress="progress = $event.detail.progress">

                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Poster Preview</label>

                        <div class="relative w-full aspect-[2/3] rounded-xl overflow-hidden bg-black border {{ isset($formErrors['poster_upload']) ? 'border-red-500' : 'border-slate-800' }} shadow-2xl flex items-center justify-center">
                            @if($poster_upload)
                                <img src="{{ $poster_upload->temporaryUrl() }}" class="w-full h-full object-cover" alt="Poster preview">
                                <div class="absolute inset-0 border-2 border-red-600 rounded-xl"></div>
                            @elseif($poster_path)
                                <img src="{{ str_starts_with($poster_path, 'http') ? $poster_path : Storage::disk('public')->url($poster_path) }}" class="w-full h-full object-cover" alt="Poster preview">
                            @else
                                <span class="text-slate-800 font-black text-2xl uppercase italic">No File</span>
                            @endif

                            <div x-show="isUploading" class="absolute inset-0 bg-black/90 flex flex-col items-center justify-center p-4" style="display: none;">
                                <div class="text-red-600 font-bold text-xs mb-2 italic">UPLOADING IMAGE...</div>
                                <div class="w-full bg-zinc-800 h-2 rounded-full overflow-hidden mt-2">
                                    <div class="bg-red-600 h-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 relative">
                            <input type="file" wire:model="poster_upload" id="poster_upload" class="sr-only" accept="image/png, image/jpeg, image/jpg, image/webp">
                            <label for="poster_upload" class="block w-full text-center py-2.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-white text-xs font-bold border border-slate-700 cursor-pointer transition">
                                Upload Local Image
                            </label>

                            @if(isset($formErrors['poster_upload']))
                                <p class="text-[10px] text-red-500 font-bold mt-2 text-center leading-tight">{{ $formErrors['poster_upload'][0] }}</p>
                            @elseif($poster_path && str_starts_with($poster_path, 'http'))
                                <p class="text-[9px] text-emerald-500 font-bold mt-2 text-center uppercase tracking-tighter">✓ Loaded from TMDB</p>
                            @endif
                        </div>
                    </div>

                    <!-- METADATA PANEL -->
                    <div class="col-span-1 md:col-span-3 space-y-5">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Movie Title</label>
                                <input type="text" wire:model.live.debounce.300ms="title" placeholder="Movie Title" class="w-full bg-black border {{ isset($formErrors['title']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-lg font-bold">
                                @if(isset($formErrors['title'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['title'][0] }}</p> @endif
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Auto-Generated Slug</label>
                                <input type="text" wire:model="slug" class="w-full bg-[#111111] border {{ isset($formErrors['slug']) ? 'border-red-500' : 'border-slate-800' }} rounded-xl px-4 py-3 text-slate-400 font-mono text-sm" {{ $editingId ? 'readonly' : '' }}>
                                @if(isset($formErrors['slug'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['slug'][0] }}</p> @endif
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 flex justify-between">
                                Short Excerpt <span class="text-slate-600 font-normal normal-case">Max 160 characters</span>
                            </label>
                            <textarea wire:model="excerpt" rows="2" maxlength="160" placeholder="Brief summary for UI cards..." class="w-full bg-black border {{ isset($formErrors['excerpt']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-slate-300 text-sm"></textarea>
                            @if(isset($formErrors['excerpt'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['excerpt'][0] }}</p> @endif
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Full Description</label>
                            <textarea wire:model.live.debounce.1000ms="description" rows="4" placeholder="Synopsis..." class="w-full bg-black border {{ isset($formErrors['description']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-slate-300 text-sm"></textarea>
                            @if(isset($formErrors['description'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['description'][0] }}</p> @endif
                        </div>

                        <div class="bg-black border {{ isset($formErrors['selectedCategories']) ? 'border-red-500' : 'border-slate-800' }} rounded-xl p-4">
                            <label class="block text-xs font-bold text-slate-500 mb-3 uppercase tracking-widest">Movie Categories</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                @foreach($this->categories as $cat)
                                    <label class="flex items-center gap-2 cursor-pointer text-slate-400 hover:text-white">
                                        <input type="checkbox" wire:model="selectedCategories" value="{{ $cat->id }}" class="rounded bg-zinc-900 text-red-600 border-slate-700">
                                        <span class="text-xs">{{ $cat->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @if(isset($formErrors['selectedCategories'])) <p class="text-red-500 text-xs mt-2 font-bold">{{ $formErrors['selectedCategories'][0] }}</p> @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 border-t border-slate-800 pt-5">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Duration (Minutes)</label>
                                <input type="number" wire:model="duration_minutes" placeholder="120" class="w-full bg-black border {{ isset($formErrors['duration_minutes']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-sm">
                                @if(isset($formErrors['duration_minutes'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['duration_minutes'][0] }}</p> @endif
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Publish Status</label>
                                <select wire:model="status" class="w-full bg-black border {{ isset($formErrors['status']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-sm">
                                    <option value="ready">Live / Ready</option>
                                    <option value="hidden">Hidden / Draft</option>
                                </select>
                                @if(isset($formErrors['status'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['status'][0] }}</p> @endif
                            </div>

                            <div class="flex items-center pt-8">
                                <label class="flex items-center gap-3 cursor-pointer text-white">
                                    <input type="checkbox" wire:model="is_premium" class="rounded bg-black text-red-600 border-slate-700">
                                    <span class="text-xs font-bold uppercase tracking-widest text-amber-500">Premium Video</span>
                                </label>
                                @if(isset($formErrors['is_premium'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['is_premium'][0] }}</p> @endif
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MEDIA UPLOADS (VIDEO & KEY) -->
                <div class="pt-8 border-t border-slate-800">
                    <h3 class="text-sm font-black text-white uppercase tracking-widest mb-4">Stream Assets</h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                        <!-- VIDEO SOURCE PANEL -->
                        <div class="bg-black border border-slate-800 rounded-xl p-5">
                            <div class="flex justify-between items-center mb-4">
                                <label class="block text-[10px] font-bold text-slate-500 uppercase">Video Source</label>
                                <div class="bg-[#111111] border border-slate-800 rounded-lg p-1 flex">
                                    <button type="button" wire:click="$set('uploadMethod', 'link')" class="px-3 py-1 text-[9px] font-black rounded-md {{ $uploadMethod === 'link' ? 'bg-zinc-800 text-white' : 'text-slate-500' }}">LINK/PATH</button>
                                    <button type="button" wire:click="$set('uploadMethod', 'file')" class="px-3 py-1 text-[9px] font-black rounded-md {{ $uploadMethod === 'file' ? 'bg-zinc-800 text-white' : 'text-slate-500' }}">UPLOAD B2</button>
                                </div>
                            </div>

                            @if($uploadMethod === 'link')
                                <input type="text" wire:model="manual_video_path" placeholder="e.g. movies/john-wick/playlist.m3u8" class="w-full bg-[#111111] border {{ isset($formErrors['manual_video_path']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 font-mono text-sm text-red-400">
                                @if(isset($formErrors['manual_video_path'])) <p class="text-red-500 text-xs mt-2 font-bold">{{ $formErrors['manual_video_path'][0] }}</p> @endif
                            @else
                                <div x-data="{ isUploading: false, progress: 0 }"
                                     x-on:livewire-upload-start="isUploading = true"
                                     x-on:livewire-upload-finish="isUploading = false"
                                     x-on:livewire-upload-error="isUploading = false"
                                     x-on:livewire-upload-progress="progress = $event.detail.progress"
                                     class="space-y-4">
                                    <input type="file" wire:model="video" class="w-full bg-[#111111] text-slate-400 p-3 border border-dashed {{ isset($formErrors['video']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl cursor-pointer hover:border-red-600 transition text-sm">

                                    <div x-show="isUploading" class="mt-2" style="display: none;">
                                        <div class="flex justify-between text-[10px] font-black text-slate-400 mb-1 italic">
                                            <span>UPLOADING TO BACKBLAZE...</span>
                                            <span x-text="progress + '%'"></span>
                                        </div>
                                        <div class="w-full bg-zinc-900 h-1.5 rounded-full overflow-hidden">
                                            <div class="bg-red-600 h-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                                        </div>
                                    </div>
                                </div>
                                @if(isset($formErrors['video'])) <p class="text-red-500 text-xs mt-2 font-bold">{{ $formErrors['video'][0] }}</p> @endif
                            @endif
                        </div>

                        <!-- 🚨 BULLETPROOF ENCRYPTION KEY PANEL (ALPINE.JS BASE64 METHOD) 🚨 -->
                        <div class="bg-black border border-slate-800 rounded-xl p-5">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-4 flex justify-between items-center">
                                Encryption Key (.key)
                                <span class="text-[9px] font-normal text-slate-600 italic normal-case">Will auto-rename to {slug}.key</span>
                            </label>

                            <div x-data="{
                                    isReady: false,
                                    handleKeySelect(event) {
                                        const file = event.target.files[0];
                                        if (!file) return;

                                        const reader = new FileReader();
                                        reader.onload = (e) => {
                                            // Sends the raw file data as a string to Livewire
                                            @this.set('enc_key_base64', e.target.result);
                                            this.isReady = true;
                                        };
                                        reader.readAsDataURL(file);
                                    }
                                 }">

                                <input type="file" accept=".key" @change="handleKeySelect" class="w-full text-xs text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-blue-950 file:text-blue-500 hover:file:bg-blue-900 cursor-pointer transition">

                                <div x-show="isReady || @js(!empty($enc_key_base64))" class="mt-3" style="display: none;">
                                    <p class="text-[9px] text-blue-500 font-bold uppercase tracking-wider">✓ Key loaded and ready to attach</p>
                                </div>
                            </div>

                            @if($editingId)
                                @php $activeKey = DB::table('movies')->where('id', $editingId)->value('enc_key'); @endphp
                                @if($activeKey)
                                    <div class="mt-4 p-2.5 bg-emerald-950/30 border border-emerald-900/50 rounded-lg">
                                        <p class="text-[9px] text-emerald-500 font-bold uppercase tracking-wider mb-1">✓ Active Secure Key Path</p>
                                        <p class="text-[10px] text-slate-300 font-mono">{{ $activeKey }}</p>
                                    </div>
                                @else
                                    <div class="mt-4 p-2.5 bg-red-950/30 border border-red-900/50 rounded-lg">
                                        <p class="text-[9px] text-red-500 font-bold uppercase tracking-wider">⚠️ No key attached</p>
                                    </div>
                                @endif
                            @endif
                        </div>

                    </div>
                </div>

                <button type="submit" class="w-full py-5 rounded-2xl bg-red-600 hover:bg-red-700 text-white font-black text-xl transition shadow-2xl active:scale-[0.99]">
                    {{ $editingId ? 'COMMIT CHANGES' : 'PUBLISH MOVIE' }}
                </button>
            </form>
        </div>
    @endif
</div>
