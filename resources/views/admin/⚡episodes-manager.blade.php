<?php

use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

new class extends Component {
    use WithFileUploads, WithPagination;

    public $currentView = 'list';
    public $editingId = null;

    // TMDB Search State
    public $searchQuery = '';
    public $searchResults = [];
    public $isSearching = false;
    public $tmdbError = '';

    // Catalog List Filters & State
    public $catalogSearch = '';
    public $filterStatus = '';
    public $filterPremium = '';
    public $filterSeason = '';

    // Bulk Delete State
    public $selectedEpisodes = [];
    public $selectAll = false;
    public $showDeleteModal = false;
    public $deleteId = null;
    public $isBulkDelete = false;

    // Form State
    public $title;
    public $slug;
    public $description;
    public $excerpt;
    public $selectedSeasons = []; // Replaces selectedCategories
    public $poster_path;
    public $poster_upload;
    public $is_premium = true;
    public $status = 'ready';
    public $duration_minutes = 45;

    // Upload & Link State
    public $uploadMethod = 'link';
    public $manual_video_path = '';
    public $video;

    // Base64 String for the Encryption Key
    public $enc_key_base64;

    // Error tracking
    public $formErrors = [];

    // Reset pagination when filters are updated
    public function updated($propertyName)
    {
        if (in_array($propertyName, ['catalogSearch', 'filterStatus', 'filterPremium', 'filterSeason'])) {
            $this->resetPage();
        }
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedEpisodes = $this->episodes->pluck('id')->map(fn($id) => (string)$id)->toArray();
        } else {
            $this->selectedEpisodes = [];
        }
    }

    #[Computed]
    public function episodes()
    {
        $query = DB::table('episodes')->orderBy('created_at', 'desc');

        if (!empty($this->catalogSearch)) {
            $query->where(function($q) {
                $q->where('title', 'like', '%' . $this->catalogSearch . '%')
                  ->orWhere('description', 'like', '%' . $this->catalogSearch . '%');
            });
        }

        if ($this->filterStatus !== '') {
            $query->where('status', $this->filterStatus);
        }

        if ($this->filterPremium !== '') {
            $query->where('is_premium', $this->filterPremium);
        }

        if ($this->filterSeason !== '') {
            $query->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('episode_season')
                  ->whereColumn('episode_season.episode_id', 'episodes.id')
                  ->where('episode_season.season_id', $this->filterSeason);
            });
        }

        return $query->paginate(15);
    }

    #[Computed]
    public function seasons()
    {
        // Join with series so we can display "Merlin - Season 1" in the UI
        return DB::table('seasons')
            ->join('series', 'seasons.series_id', '=', 'series.id')
            ->select('seasons.id', 'seasons.name as season_name', 'series.title as series_title')
            ->orderBy('series.title')
            ->orderBy('seasons.name')
            ->get();
    }

    public function getEpisodeSeasons($episodeId)
    {
        return DB::table('episode_season')
            ->join('seasons', 'episode_season.season_id', '=', 'seasons.id')
            ->join('series', 'seasons.series_id', '=', 'series.id')
            ->where('episode_season.episode_id', $episodeId)
            ->selectRaw('CONCAT(series.title, " - ", seasons.name) as full_name')
            ->pluck('full_name')
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
            'editingId', 'title', 'slug', 'description', 'excerpt', 'selectedSeasons', 'poster_path', 'poster_upload',
            'manual_video_path', 'video', 'searchQuery', 'searchResults', 'tmdbError', 'formErrors',
            'enc_key_base64', 'selectedEpisodes', 'selectAll'
        ]);
        $this->status = 'ready';
        $this->is_premium = true;
        $this->uploadMethod = 'link';
        $this->duration_minutes = 45;
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

            // Changed to search/tv for episodes/series
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/search/tv", [
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

    public function selectShow($tmdbId)
    {
        $this->tmdbError = '';

        try {
            if (!env('TMDB_BEARER_TOKEN')) throw new \Exception('TMDB API token not configured');

            // Changed to /tv/{id}
            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/tv/{$tmdbId}");

            if ($response->successful()) {
                $media = $response->json();

                // TV endpoints use 'name' instead of 'title'
                $this->title = ($media['name'] ?? '') . ' - Episode ';
                $this->description = $media['overview'] ?? '';

                $this->excerpt = Str::limit($this->description, 160);
                if (!$this->editingId) {
                    $this->slug = Str::slug($this->title) . '-' . substr(uniqid(), -5);
                }

                $this->duration_minutes = $media['episode_run_time'][0] ?? 45;

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
            $episode = DB::table('episodes')->where('id', $id)->first();
            if (!$episode) throw new \Exception('Episode not found');

            $this->editingId = $episode->id;
            $this->title = $episode->title;
            $this->slug = $episode->slug;
            $this->description = $episode->description;
            $this->excerpt = $episode->excerpt ?? Str::limit($episode->description, 160);
            $this->manual_video_path = $episode->video_path;
            $this->poster_path = $episode->thumbnail;
            $this->is_premium = (bool) ($episode->is_premium ?? false);
            $this->status = $episode->status ?? 'ready';
            $this->duration_minutes = floor(($episode->duration_in_seconds ?? 0) / 60);

            $this->selectedSeasons = DB::table('episode_season')
                ->where('episode_id', $id)
                ->pluck('season_id')
                ->toArray();

            $this->currentView = 'form';
        } catch (\Exception $e) {
            $this->dispatch('notify-toast', type: 'error', message: 'Failed to load episode for editing');
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

    public function saveEpisode()
    {
        $this->formErrors = [];

        try {
            $this->validate([
                'title' => 'required|min:1|max:255',
                'slug' => 'required|string|max:255',
                'description' => 'required|string|min:10',
                'excerpt' => 'nullable|string|max:255',
                'selectedSeasons' => 'required|array|min:1',
                'duration_minutes' => 'required|integer|min:1',
                'status' => 'required|in:ready,hidden',
                'is_premium' => 'boolean',
                'poster_upload' => 'nullable|image|max:3072|mimes:jpg,jpeg,png,webp',
                'manual_video_path' => 'nullable|string|max:500',
                'video' => 'nullable|file|max:2048000',
                'enc_key_base64' => 'nullable|string',
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
                // Assuming you add 'enc_key' to episodes table, or mapping it manually
                // Since episodes table migration didn't explicitly have enc_key, if you need it, make sure it's added.
                // Using fallback logic to prevent crash if column doesn't exist yet, but assuming you added it.
                $episode = DB::table('episodes')->where('id', $this->editingId)->first();
                $existingEncKey = $episode->enc_key ?? null;
            }

            // ==========================================
            // 🚨 BULLETPROOF KEY STORAGE
            // ==========================================
            $encKeyPath = $existingEncKey;

            if (!empty($this->enc_key_base64)) {
                $directory = storage_path('app/video_keys');
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $keyFilename = $this->slug . '.key';
                $absolutePath = $directory . '/' . $keyFilename;

                $cleanBase64 = preg_replace('#^data:.*?;base64,#i', '', $this->enc_key_base64);
                $keyData = base64_decode($cleanBase64);

                $bytesWritten = file_put_contents($absolutePath, $keyData);

                if ($bytesWritten === false) {
                    throw new \Exception("Server denied write permissions to: " . $absolutePath);
                }

                $encKeyPath = 'video_keys/' . $keyFilename;
            }

            // ==========================================
            // STEP 2: Process the Poster Upload
            // ==========================================
            if ($this->poster_upload) {
                $filename = 'posters/episodes/' . $this->slug . '-' . time() . '.' . $this->poster_upload->getClientOriginalExtension();
                $stored = $this->poster_upload->storeAs('posters/episodes', $filename, 'public');

                if (!$stored) throw new \Exception('Failed to store poster locally.');
                $this->poster_path = $filename;
            }

            if (!$this->poster_path && !$this->poster_upload) {
                $this->formErrors['poster_upload'] = ['An episode poster is required.'];
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
                'updated_at' => now(),
            ];

            // Add enc_key only if the column exists in your episodes table (recommend adding it if you haven't!)
            if (\Schema::hasColumn('episodes', 'enc_key')) {
                $data['enc_key'] = $encKeyPath;
            }

            // ==========================================
            // STEP 4: Process Video Source
            // ==========================================
            if ($this->uploadMethod === 'file' && $this->video) {
                // Changed from movies/ to series/ as requested
                $videoPath = $this->video->storeAs('series/' . $this->slug, $this->video->getClientOriginalName(), 'b2');
                if (!$videoPath) throw new \Exception('Failed to upload video to B2');
                $data['video_path'] = $videoPath;
            } elseif (!empty($this->manual_video_path)) {
                $data['video_path'] = $this->manual_video_path;
            }

            // ==========================================
            // STEP 5: Commit to Database
            // ==========================================
            if ($this->editingId) {
                DB::table('episodes')->where('id', $this->editingId)->update($data);
                $episodeId = $this->editingId;
            } else {
                $data['created_at'] = now();
                $episodeId = DB::table('episodes')->insertGetId($data);
            }

            // Sync Pivot Table (Episode <-> Season)
            DB::table('episode_season')->where('episode_id', $episodeId)->delete();
            $pivotData = [];
            foreach($this->selectedSeasons as $seasonId) {
                $pivotData[] = [
                    'episode_id' => $episodeId,
                    'season_id' => $seasonId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('episode_season')->insert($pivotData);

            DB::commit();

            $this->dispatch('notify-toast', type: 'success', message: $this->editingId ? 'Episode updated successfully!' : 'Episode published!');
            $this->showList();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Episode Save Error: ' . $e->getMessage());
            if (empty($this->formErrors)) {
                $this->dispatch('notify-toast', type: 'error', message: 'Save Failed! Check application logs.');
            }
        }
    }

    public function confirmDelete($id = null)
    {
        $this->isBulkDelete = is_null($id);

        if ($this->isBulkDelete && empty($this->selectedEpisodes)) {
            $this->dispatch('notify-toast', type: 'error', message: 'No episodes selected.');
            return;
        }

        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function executeDelete()
    {
        try {
            DB::beginTransaction();

            $idsToDelete = $this->isBulkDelete ? $this->selectedEpisodes : [$this->deleteId];

            if (!empty($idsToDelete)) {
                DB::table('episode_season')->whereIn('episode_id', $idsToDelete)->delete();
                DB::table('episodes')->whereIn('id', $idsToDelete)->delete();
            }

            DB::commit();

            // Reset States
            $this->selectedEpisodes = [];
            $this->selectAll = false;
            $this->showDeleteModal = false;
            $this->deleteId = null;

            $this->dispatch('notify-toast', type: 'success', message: $this->isBulkDelete ? 'Selected episodes deleted.' : 'Episode deleted from database.');
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
            <h1 class="text-3xl font-black text-white">Episode Studio</h1>
            <p class="text-slate-400 mt-1 text-sm">Manage your Series Episodes.</p>
        </div>
        @if($currentView === 'list')
            <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm active:scale-95">+ Add Episode</button>
        @else
            <button wire:click="showList" class="px-6 py-3 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-700 text-white font-bold transition shadow-sm">← Back to Episodes</button>
        @endif
    </div>

    @if($currentView === 'list')
        <!-- Filters & Bulk Actions -->
        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-4 mb-6 flex flex-col md:flex-row gap-4 items-center justify-between">
            <div class="flex-1 w-full flex flex-col md:flex-row gap-3">
                <input type="text" wire:model.live.debounce.300ms="catalogSearch" placeholder="Search episodes..." class="w-full md:w-64 bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white text-sm focus:ring-1 focus:ring-red-600 focus:outline-none transition">

                <select wire:model.live="filterStatus" class="w-full md:w-40 bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white text-sm focus:ring-1 focus:ring-red-600 focus:outline-none transition">
                    <option value="">All Statuses</option>
                    <option value="ready">Live / Ready</option>
                    <option value="hidden">Hidden / Draft</option>
                </select>

                <select wire:model.live="filterPremium" class="w-full md:w-40 bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white text-sm focus:ring-1 focus:ring-red-600 focus:outline-none transition">
                    <option value="">All Types</option>
                    <option value="1">Premium</option>
                    <option value="0">Free</option>
                </select>

                <select wire:model.live="filterSeason" class="w-full md:w-56 bg-black border border-slate-700 rounded-xl px-4 py-2.5 text-white text-sm focus:ring-1 focus:ring-red-600 focus:outline-none transition">
                    <option value="">All Seasons</option>
                    @foreach($this->seasons as $season)
                        <option value="{{ $season->id }}">{{ $season->series_title }} - {{ $season->season_name }}</option>
                    @endforeach
                </select>
            </div>

            @if(count($selectedEpisodes) > 0)
                <div class="flex-shrink-0 animate-in fade-in zoom-in duration-200">
                    <button wire:click="confirmDelete()" class="px-5 py-2.5 bg-red-600/10 border border-red-600/50 text-red-500 rounded-xl hover:bg-red-600 hover:text-white transition font-bold text-sm shadow-sm">
                        Delete Selected ({{ count($selectedEpisodes) }})
                    </button>
                </div>
            @endif
        </div>

        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4 w-12 text-center">
                                <input type="checkbox" wire:model.live="selectAll" class="rounded bg-black border-slate-700 text-red-600 focus:ring-red-600 focus:ring-offset-black">
                            </th>
                            <th class="px-6 py-4">Title & Poster</th>
                            <th class="px-6 py-4">Assigned Seasons</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @forelse($this->episodes as $episode)
                            <tr class="hover:bg-zinc-900/50 transition-colors {{ in_array($episode->id, $selectedEpisodes) ? 'bg-red-900/10' : '' }}">
                                <td class="px-6 py-4 text-center">
                                    <input type="checkbox" wire:model.live="selectedEpisodes" value="{{ $episode->id }}" class="rounded bg-black border-slate-700 text-red-600 focus:ring-red-600 focus:ring-offset-black transition">
                                </td>
                                <td class="px-6 py-4 flex items-center gap-4">
                                    @if($episode->thumbnail)
                                        <img src="{{ str_starts_with($episode->thumbnail, 'http') ? $episode->thumbnail : Storage::disk('public')->url($episode->thumbnail) }}" class="w-10 h-14 object-cover rounded shadow border border-slate-700" alt="{{ $episode->title }}">
                                    @else
                                        <div class="w-10 h-14 bg-zinc-800 rounded flex items-center justify-center text-[8px]">No Img</div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white text-base flex items-center gap-2">
                                            {{ $episode->title }}
                                        </div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-[10px] font-bold {{ $episode->is_premium ? 'text-amber-500' : 'text-emerald-500' }} uppercase tracking-wider">
                                                {{ $episode->is_premium ? 'Premium Plan' : 'Free' }}
                                            </span>
                                            @if(isset($episode->enc_key) && $episode->enc_key)
                                                <span class="px-1.5 py-0.5 rounded bg-blue-950 text-blue-400 text-[8px] uppercase tracking-wider font-bold border border-blue-900/50" title="Encryption Key Enabled">Encrypted</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($this->getEpisodeSeasons($episode->id) as $seasonName)
                                            <span class="px-2 py-0.5 rounded-full bg-zinc-800 text-slate-300 text-[10px] border border-slate-700">{{ $seasonName }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2.5 py-1 rounded {{ $episode->status === 'ready' ? 'bg-emerald-950/50 text-emerald-500' : 'bg-amber-950/50 text-amber-500' }} text-[10px] font-bold uppercase">
                                        {{ $episode->status }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right space-x-3">
                                    <button wire:click="edit({{ $episode->id }})" class="text-indigo-400 hover:text-indigo-300 font-bold transition">Edit</button>
                                    <button wire:click="confirmDelete({{ $episode->id }})" class="text-red-500 hover:text-red-400 font-bold transition">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-16 text-center text-slate-500 italic">No episodes found matching your criteria.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination Links -->
            @if($this->episodes->hasPages())
                <div class="p-4 border-t border-slate-800 bg-zinc-900/30">
                    {{ $this->episodes->links() }}
                </div>
            @endif
        </div>
    @else
        @if(!$editingId)
            <div class="bg-black border border-slate-800 rounded-2xl shadow-lg p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-white">1. Auto-Fill via TMDB (Optional)</h3>
                </div>
                <input type="text" wire:model.live.debounce.500ms="searchQuery" placeholder="Search TMDB for the TV Show..." class="w-full bg-[#111111] border border-slate-700 rounded-xl px-4 py-4 text-white focus:ring-1 focus:ring-red-600 focus:outline-none transition">

                @if($tmdbError)
                    <div class="mt-4 p-3 bg-red-950/30 border border-red-800 rounded-lg text-red-400 text-sm">{{ $tmdbError }}</div>
                @endif

                @if(count($searchResults) > 0)
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
                        @foreach($searchResults as $result)
                            <div wire:click="selectShow({{ $result['id'] }})" class="cursor-pointer group">
                                <div class="relative aspect-[2/3] overflow-hidden rounded-lg bg-zinc-900 border border-slate-800">
                                    @if(isset($result['poster_path']))
                                        <img src="https://image.tmdb.org/t/p/w200{{ $result['poster_path'] }}" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition" alt="{{ $result['name'] ?? 'Poster' }}">
                                    @endif
                                    <div class="absolute inset-0 bg-red-600/0 group-hover:bg-red-600/20 transition"></div>
                                </div>
                                <!-- TV endpoints use 'name' instead of 'title' -->
                                <div class="mt-2 text-[11px] font-bold text-slate-400 truncate group-hover:text-white">{{ $result['name'] ?? 'Unknown' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-6">
            <form wire:submit="saveEpisode" class="space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <!-- POSTER PREVIEW PANEL -->
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
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Episode Title (e.g. Merlin - E1)</label>
                                <input type="text" wire:model.live.debounce.300ms="title" placeholder="Episode Title" class="w-full bg-black border {{ isset($formErrors['title']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-lg font-bold focus:outline-none focus:ring-1 focus:ring-red-600 transition">
                                @if(isset($formErrors['title'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['title'][0] }}</p> @endif
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Auto-Generated Slug</label>
                                <input type="text" wire:model="slug" class="w-full bg-[#111111] border {{ isset($formErrors['slug']) ? 'border-red-500' : 'border-slate-800' }} rounded-xl px-4 py-3 text-slate-400 font-mono text-sm focus:outline-none" {{ $editingId ? 'readonly' : '' }}>
                                @if(isset($formErrors['slug'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['slug'][0] }}</p> @endif
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 flex justify-between">
                                Short Excerpt <span class="text-slate-600 font-normal normal-case">Max 160 characters</span>
                            </label>
                            <textarea wire:model="excerpt" rows="2" maxlength="160" placeholder="Brief summary for UI cards..." class="w-full bg-black border {{ isset($formErrors['excerpt']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-red-600 transition"></textarea>
                            @if(isset($formErrors['excerpt'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['excerpt'][0] }}</p> @endif
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Full Description</label>
                            <textarea wire:model.live.debounce.1000ms="description" rows="4" placeholder="Synopsis..." class="w-full bg-black border {{ isset($formErrors['description']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-slate-300 text-sm focus:outline-none focus:ring-1 focus:ring-red-600 transition"></textarea>
                            @if(isset($formErrors['description'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['description'][0] }}</p> @endif
                        </div>

                        <!-- SEASONS ASSIGNMENT (Replacing Categories) -->
                        <div class="bg-black border {{ isset($formErrors['selectedSeasons']) ? 'border-red-500' : 'border-slate-800' }} rounded-xl p-4 transition">
                            <label class="block text-xs font-bold text-slate-500 mb-3 uppercase tracking-widest">Assign to Season</label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                @forelse($this->seasons as $season)
                                    <label class="flex items-center gap-2 cursor-pointer text-slate-400 hover:text-white p-2 rounded-lg bg-zinc-900 border border-slate-800 hover:border-slate-600 transition">
                                        <input type="checkbox" wire:model="selectedSeasons" value="{{ $season->id }}" class="rounded bg-black text-red-600 border-slate-700 focus:ring-red-600 focus:ring-offset-black">
                                        <span class="text-xs font-bold">{{ $season->series_title }} <span class="text-slate-500 font-normal">- {{ $season->season_name }}</span></span>
                                    </label>
                                @empty
                                    <div class="col-span-3 text-sm text-slate-500 italic p-2">No seasons created yet. You'll need to create Series and Seasons first to assign this episode!</div>
                                @endforelse
                            </div>
                            @if(isset($formErrors['selectedSeasons'])) <p class="text-red-500 text-xs mt-2 font-bold">{{ $formErrors['selectedSeasons'][0] }}</p> @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 border-t border-slate-800 pt-5">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Duration (Minutes)</label>
                                <input type="number" wire:model="duration_minutes" placeholder="45" class="w-full bg-black border {{ isset($formErrors['duration_minutes']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:ring-1 focus:ring-red-600 transition">
                                @if(isset($formErrors['duration_minutes'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['duration_minutes'][0] }}</p> @endif
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Publish Status</label>
                                <select wire:model="status" class="w-full bg-black border {{ isset($formErrors['status']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:ring-1 focus:ring-red-600 transition">
                                    <option value="ready">Live / Ready</option>
                                    <option value="hidden">Hidden / Draft</option>
                                </select>
                                @if(isset($formErrors['status'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['status'][0] }}</p> @endif
                            </div>

                            <div class="flex items-center pt-8">
                                <label class="flex items-center gap-3 cursor-pointer text-white">
                                    <input type="checkbox" wire:model="is_premium" class="rounded bg-black text-red-600 border-slate-700 focus:ring-red-600 focus:ring-offset-black transition">
                                    <span class="text-xs font-bold uppercase tracking-widest text-amber-500">Premium Episode</span>
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
                                    <button type="button" wire:click="$set('uploadMethod', 'link')" class="px-3 py-1 text-[9px] font-black rounded-md {{ $uploadMethod === 'link' ? 'bg-zinc-800 text-white' : 'text-slate-500' }} transition">LINK/PATH</button>
                                    <button type="button" wire:click="$set('uploadMethod', 'file')" class="px-3 py-1 text-[9px] font-black rounded-md {{ $uploadMethod === 'file' ? 'bg-zinc-800 text-white' : 'text-slate-500' }} transition">UPLOAD B2</button>
                                </div>
                            </div>

                            @if($uploadMethod === 'link')
                                <input type="text" wire:model="manual_video_path" placeholder="e.g. series/merlin-e1/master.m3u8" class="w-full bg-[#111111] border {{ isset($formErrors['manual_video_path']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 font-mono text-sm text-red-400 focus:outline-none focus:ring-1 focus:ring-red-600 transition">
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

                        <!-- 🚨 WAF-SAFE HIDDEN INPUT ENCRYPTION KEY PANEL 🚨 -->
                        <div class="bg-black border border-slate-800 rounded-xl p-5"
                             x-data="{
                                keyLoaded: false,
                                handleKeySelect(event) {
                                    const file = event.target.files[0];
                                    if (!file) {
                                        this.keyLoaded = false;
                                        $refs.hiddenKeyInput.value = '';
                                        $refs.hiddenKeyInput.dispatchEvent(new Event('input', { bubbles: true }));
                                        return;
                                    }

                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        // Strip out 'data:...' to avoid WAF blocks in production
                                        const pureBase64 = e.target.result.split(',')[1];

                                        // Assign it to the hidden input
                                        $refs.hiddenKeyInput.value = pureBase64;

                                        // Force Livewire to recognize the change instantly
                                        $refs.hiddenKeyInput.dispatchEvent(new Event('input', { bubbles: true }));

                                        this.keyLoaded = true;
                                    };
                                    reader.readAsDataURL(file);
                                }
                             }">

                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-4 flex justify-between items-center">
                                Encryption Key (.key)
                                <span class="text-[9px] font-normal text-slate-600 italic normal-case">Will auto-rename to {slug}.key</span>
                            </label>

                            <!-- Visible File Input to capture the file locally -->
                            <input type="file" accept=".key" @change="handleKeySelect" class="w-full text-xs text-slate-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-blue-950 file:text-blue-500 hover:file:bg-blue-900 cursor-pointer transition">

                            <!-- Hidden input actually bound to Livewire -->
                            <input type="hidden" wire:model="enc_key_base64" x-ref="hiddenKeyInput">

                            <div x-show="keyLoaded" class="mt-3" style="display: none;">
                                <p class="text-[9px] text-blue-500 font-bold uppercase tracking-wider">✓ Key ready to deploy</p>
                            </div>

                            @if($editingId)
                                @php
                                    // Use proper fallback in case column doesn't exist
                                    $activeKey = \Schema::hasColumn('episodes', 'enc_key') ? DB::table('episodes')->where('id', $editingId)->value('enc_key') : null;
                                @endphp
                                @if($activeKey)
                                    <div class="mt-4 p-2.5 bg-emerald-950/30 border border-emerald-900/50 rounded-lg">
                                        <p class="text-[9px] text-emerald-500 font-bold uppercase tracking-wider mb-1">✓ Active Secure Key Path</p>
                                        <p class="text-[10px] text-slate-300 font-mono">{{ $activeKey }}</p>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full py-5 rounded-2xl bg-red-600 hover:bg-red-700 text-white font-black text-xl transition shadow-2xl active:scale-[0.99]">
                    {{ $editingId ? 'COMMIT CHANGES' : 'PUBLISH EPISODE' }}
                </button>
            </form>
        </div>
    @endif

    {{-- DELETE CONFIRMATION MODAL --}}
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/80 backdrop-blur-sm px-4 animate-in fade-in duration-200">
            <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-2xl p-6 max-w-sm w-full animate-in zoom-in-95 duration-200">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-full bg-red-600/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                    <h3 class="text-xl font-black text-white">Confirm Deletion</h3>
                </div>

                <p class="text-slate-400 text-sm mb-6 pl-1">
                    {{ $isBulkDelete ? "Are you sure you want to delete ".count($selectedEpisodes)." selected episodes? This action cannot be undone and will permanently remove them." : "Are you sure you want to delete this episode? This action cannot be undone and will permanently remove it." }}
                </p>

                <div class="flex gap-3 justify-end">
                    <button wire:click="$set('showDeleteModal', false)" class="px-5 py-2.5 rounded-xl bg-zinc-900 border border-slate-700 text-white font-bold hover:bg-zinc-800 transition">Cancel</button>
                    <button wire:click="executeDelete" class="px-5 py-2.5 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold shadow-lg shadow-red-600/20 transition">Yes, Delete</button>
                </div>
            </div>
        </div>
    @endif
</div>
