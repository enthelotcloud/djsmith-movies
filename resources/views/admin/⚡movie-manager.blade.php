<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
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
    public $type = 'movie';
    public $title;
    public $description;
    public $selectedCategories = [];
    public $poster_path;
    public $is_premium = true;
    public $status = 'ready';
    public $duration_minutes = 120;

    // Upload & Link State
    public $uploadMethod = 'link';
    public $manual_video_path = '';
    public $video;

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
            'editingId', 'title', 'description', 'selectedCategories', 'poster_path',
            'manual_video_path', 'video', 'searchQuery', 'searchResults', 'tmdbError', 'type'
        ]);
        $this->type = 'movie';
        $this->status = 'ready';
        $this->is_premium = true;
        $this->uploadMethod = 'link';
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
        try {
            $endpoint = $this->type === 'series' ? 'search/tv' : 'search/movie';

            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->get("https://api.themoviedb.org/3/{$endpoint}", [
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
        }
        $this->isSearching = false;
    }

    public function selectMovie($tmdbId)
    {
        try {
            $endpoint = $this->type === 'series' ? 'tv' : 'movie';
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))->get("https://api.themoviedb.org/3/{$endpoint}/{$tmdbId}");

            if ($response->successful()) {
                $media = $response->json();
                $this->title = $this->type === 'series' ? $media['name'] : $media['title'];
                $this->description = $media['overview'];

                if (isset($media['runtime'])) $this->duration_minutes = $media['runtime'];
                if (isset($media['episode_run_time'][0])) $this->duration_minutes = $media['episode_run_time'][0];

                // 🚨 DOWNLOAD AND OWN THE IMAGE 🚨
                if (isset($media['poster_path'])) {
                    $imageContent = Http::get("https://image.tmdb.org/t/p/w780" . $media['poster_path'])->body();
                    $filename = 'posters/' . Str::slug($this->title) . '-' . time() . '.jpg';

                    // Saves the physical file to your Backblaze bucket forever
                    Storage::disk('b2')->put($filename, $imageContent);

                    $this->poster_path = $filename;
                }

                $this->searchResults = [];
                $this->searchQuery = '';
            }
        } catch (\Exception $e) {
            $this->tmdbError = "Failed to fetch details or download poster.";
        }
    }

    public function edit($id)
    {
        $this->resetForm();
        $movie = DB::table('movies')->where('id', $id)->first();

        $this->editingId = $movie->id;
        $this->type = $movie->type;
        $this->title = $movie->title;
        $this->description = $movie->description;
        $this->manual_video_path = $movie->video_path;
        $this->poster_path = $movie->thumbnail_path;
        $this->is_premium = (bool) $movie->is_premium;
        $this->status = $movie->status;
        $this->duration_minutes = floor($movie->duration_in_seconds / 60);
        $this->uploadMethod = 'link';

        $this->selectedCategories = DB::table('category_movie')
            ->where('movie_id', $id)
            ->pluck('moviecategory_id')
            ->toArray();

        $this->currentView = 'form';
    }

    public function saveMovie()
    {
        $rules = [
            'title' => 'required|string|max:255',
            'type' => 'required|in:movie,series',
            'status' => 'required|in:uploading,processing,ready,hidden',
            'selectedCategories' => 'required|array|min:1',
        ];

        if ($this->type === 'movie' && !$this->editingId) {
            if ($this->uploadMethod === 'file') {
                $rules['video'] = 'required|mimes:mp4,mkv,avi,webm|max:1500000';
            } else {
                $rules['manual_video_path'] = 'required|string';
            }
        }

        $this->validate($rules);

        $data = [
            'type' => $this->type,
            'title' => $this->title,
            'slug' => Str::slug($this->title) . '-' . uniqid(),
            'description' => $this->description,
            'thumbnail_path' => $this->poster_path, // Saved as 'posters/movie-name.jpg'
            'duration_in_seconds' => $this->duration_minutes * 60,
            'is_premium' => $this->is_premium,
            'status' => $this->status,
            'updated_at' => now(),
        ];

        if ($this->type === 'movie') {
            $data['video_disk'] = 'b2';
            if ($this->uploadMethod === 'file' && $this->video) {
                $data['video_path'] = $this->video->storeAs(
                    'movies/' . Str::slug($this->title),
                    $this->video->getClientOriginalName(),
                    'b2'
                );
            } elseif ($this->uploadMethod === 'link') {
                $data['video_path'] = $this->manual_video_path;
            }
            if ($this->editingId && $this->uploadMethod === 'file' && !$this->video) {
                unset($data['video_path']);
            }
        } else {
            $data['video_disk'] = null;
            $data['video_path'] = null;
        }

        if ($this->editingId) {
            DB::table('movies')->where('id', $this->editingId)->update($data);
            $movieId = $this->editingId;
            $this->dispatch('notify-toast', type: 'success', message: 'Updated successfully!');
        } else {
            $data['created_at'] = now();
            $movieId = DB::table('movies')->insertGetId($data);
            $this->dispatch('notify-toast', type: 'success', message: 'Saved to catalog!');
        }

        DB::table('category_movie')->where('movie_id', $movieId)->delete();
        $pivotData = [];
        foreach($this->selectedCategories as $catId) {
            $pivotData[] = ['movie_id' => $movieId, 'moviecategory_id' => $catId];
        }
        if(!empty($pivotData)) {
            DB::table('category_movie')->insert($pivotData);
        }

        $this->showList();
        unset($this->movies);
    }

    public function delete($id)
    {
        DB::table('movies')->where('id', $id)->delete();
        unset($this->movies);
        $this->dispatch('notify-toast', type: 'success', message: 'Deleted from database.');
    }
};
?>

{{-- HTML REMAINS EXACTLY THE SAME AS PREVIOUSLY PROVIDED --}}
<div class="max-w-6xl mx-auto space-y-8 relative" x-data @notify-toast.window="Flux.toast({ text: $event.detail.message, variant: $event.detail.type })">

    <div class="flex justify-between items-center bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Movie Studio</h1>
            <p class="text-slate-400 mt-1">Manage your VOD catalog and Backblaze storage.</p>
        </div>
        @if($currentView === 'list')
            <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm">+ Add Content</button>
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
                                    @if($movie->thumbnail_path)
                                        {{-- Render private bucket poster securely in admin panel too --}}
                                        <img src="{{ Storage::disk('b2')->temporaryUrl($movie->thumbnail_path, now()->addHours(2)) }}" class="w-10 h-14 object-cover rounded shadow">
                                    @else
                                        <div class="w-10 h-14 bg-zinc-800 rounded flex items-center justify-center text-[8px]">No Img</div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white text-base flex items-center gap-2">
                                            {{ $movie->title }}
                                            @if($movie->type === 'series')
                                                <span class="px-1.5 py-0.5 rounded bg-indigo-900/50 text-indigo-400 text-[9px] uppercase tracking-wider">Series</span>
                                            @endif
                                        </div>
                                        @if($movie->is_premium)
                                            <span class="text-[10px] font-bold text-amber-500 uppercase tracking-wider">Premium Plan</span>
                                        @else
                                            <span class="text-[10px] font-bold text-emerald-500 uppercase tracking-wider">Free to Watch</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($this->getMovieCategories($movie->id) as $catName)
                                            <span class="px-2 py-0.5 rounded-full bg-zinc-800 text-slate-300 text-[10px]">{{ $catName }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    @if($movie->status === 'ready')
                                        <span class="px-2.5 py-1 rounded bg-emerald-950/50 text-emerald-500 border border-emerald-900/50 text-[10px] font-bold uppercase">Ready</span>
                                    @else
                                        <span class="px-2.5 py-1 rounded bg-amber-950/50 text-amber-500 border border-amber-900/50 text-[10px] font-bold uppercase">{{ $movie->status }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right space-x-3">
                                    @if($movie->type === 'series')
                                        <a href="#" class="text-emerald-400 hover:text-emerald-300 font-bold transition">Manage Episodes</a>
                                    @endif
                                    <button wire:click="edit({{ $movie->id }})" class="text-indigo-400 hover:text-indigo-300 font-bold transition">Edit</button>
                                    <button wire:click="delete({{ $movie->id }})" wire:confirm="Delete this completely?" class="text-red-500 hover:text-red-400 font-bold transition">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500">Your catalog is empty. Time to add some content!</td></tr>
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
            <div class="bg-black border border-slate-800 rounded-2xl shadow-lg p-6 relative z-20 mb-8">
                <h3 class="text-lg font-bold text-white mb-4">1. Auto-Fill with TMDB ({{ ucfirst($type) }})</h3>
                <div class="relative">
                    <input type="text" wire:model.live.debounce.500ms="searchQuery" placeholder="Type a {{ $type }} name..." class="w-full bg-[#111111] border border-slate-700 rounded-xl pl-4 pr-4 py-4 text-white focus:border-red-500 transition">
                </div>
                @if($tmdbError) <p class="text-sm text-red-500 mt-2 font-bold">{{ $tmdbError }}</p> @endif
                @if(count($searchResults) > 0)
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
                        @foreach($searchResults as $result)
                            <div wire:click="selectMovie({{ $result['id'] }})" class="cursor-pointer group relative">
                                @if(isset($result['poster_path']) && $result['poster_path'])
                                    <img src="https://image.tmdb.org/t/p/w200{{ $result['poster_path'] }}" class="w-full rounded-lg shadow-lg opacity-80 group-hover:opacity-100">
                                @endif
                                <div class="mt-2 text-xs font-bold text-slate-300 truncate group-hover:text-red-500">{{ $result['title'] ?? $result['name'] ?? 'Unknown' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-6 relative z-10">
            <form wire:submit="saveMovie" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="col-span-1">
                        <label class="block text-xs font-bold text-slate-500 mb-2 uppercase">Poster</label>
                        @if($poster_path)
                            <img src="{{ Storage::disk('b2')->temporaryUrl($poster_path, now()->addHours(2)) }}" class="w-full rounded-xl border border-slate-700">
                            <p class="text-[10px] text-emerald-500 mt-2 font-bold uppercase text-center">✓ Saved to B2</p>
                        @endif
                    </div>
                    <div class="col-span-1 md:col-span-3 space-y-4">
                        <input type="text" wire:model="title" placeholder="Title" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white">
                        <textarea wire:model="description" rows="3" placeholder="Synopsis" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white"></textarea>

                        <div class="bg-black border border-slate-800 rounded-xl p-4">
                            <label class="block text-xs font-bold text-slate-500 mb-3 uppercase">Categories</label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                @foreach($this->categories as $cat)
                                    <label class="flex items-center gap-2 cursor-pointer text-slate-300 hover:text-white">
                                        <input type="checkbox" wire:model="selectedCategories" value="{{ $cat->id }}" class="rounded bg-zinc-900 text-red-600"> {{ $cat->name }}
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <select wire:model="status" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white">
                                <option value="ready">Ready (Visible)</option>
                                <option value="hidden">Hidden</option>
                            </select>
                            <label class="flex items-center gap-3 cursor-pointer mt-2 text-white">
                                <input type="checkbox" wire:model="is_premium" class="rounded bg-black text-red-600"> Premium Content
                            </label>
                        </div>
                    </div>
                </div>

                @if($type === 'movie')
                    <div class="pt-6 border-t border-slate-800">
                        <div class="flex items-center justify-between mb-4">
                            <label class="block text-xs font-bold text-slate-500 uppercase">Video Source</label>
                            <div class="bg-black border border-slate-800 rounded-lg p-1 flex">
                                <button type="button" wire:click="$set('uploadMethod', 'link')" class="px-4 py-1.5 text-sm rounded-md {{ $uploadMethod === 'link' ? 'bg-zinc-800 text-white' : 'text-slate-500' }}">Manual Link</button>
                                <button type="button" wire:click="$set('uploadMethod', 'file')" class="px-4 py-1.5 text-sm rounded-md {{ $uploadMethod === 'file' ? 'bg-zinc-800 text-white' : 'text-slate-500' }}">Browser Upload</button>
                            </div>
                        </div>
                        @if($uploadMethod === 'link')
                            <input type="text" wire:model="manual_video_path" placeholder="e.g. night agent 1.mp4" class="w-full bg-black border border-slate-700 rounded-xl px-4 py-3 font-mono text-slate-300">
                        @else
                            <input type="file" wire:model="video" class="w-full bg-black text-slate-300 p-2 border border-slate-700 rounded-xl">
                        @endif
                    </div>
                @endif

                <button type="submit" class="w-full py-4 rounded-xl {{ $type === 'series' ? 'bg-indigo-600' : 'bg-red-600' }} text-white font-bold text-lg">
                    {{ $editingId ? 'Update Catalog' : 'Save to Catalog' }}
                </button>
            </form>
        </div>
    @endif
</div>
