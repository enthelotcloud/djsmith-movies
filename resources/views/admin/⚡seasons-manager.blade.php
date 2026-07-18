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
    public $series_id;
    public $name;
    public $slug;
    public $excerpt;
    public $poster_path;
    public $poster_upload;
    public $trailer_url;

    // Error tracking
    public $formErrors = [];

    #[Computed]
    public function seasons()
    {
        return DB::table('seasons')
            ->join('series', 'seasons.series_id', '=', 'series.id')
            ->select('seasons.*', 'series.title as series_title')
            ->orderBy('series.title')
            ->orderBy('seasons.name')
            ->get();
    }

    #[Computed]
    public function seriesList()
    {
        return DB::table('series')->orderBy('title')->get();
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
            'editingId', 'series_id', 'name', 'slug', 'excerpt', 'poster_path', 'poster_upload',
            'trailer_url', 'searchQuery', 'searchResults', 'tmdbError', 'formErrors'
        ]);
    }

    public function updatedName($value)
    {
        if (!$this->editingId) {
            $parentSeries = DB::table('series')->where('id', $this->series_id)->first();
            $prefix = $parentSeries ? $parentSeries->slug . '-' : '';
            $this->slug = Str::slug($prefix . $value) . '-' . substr(uniqid(), -5);
        }
    }

    public function updatedSeriesId($value)
    {
        if (!empty($this->name)) {
            $this->updatedName($this->name);
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

    public function selectShowArtwork($tmdbId)
    {
        $this->tmdbError = '';

        try {
            if (!env('TMDB_BEARER_TOKEN')) throw new \Exception('TMDB API token not configured');

            $response = Http::withToken(env('TMDB_BEARER_TOKEN'))
                ->timeout(10)
                ->get("https://api.themoviedb.org/3/tv/{$tmdbId}");

            if ($response->successful()) {
                $media = $response->json();

                if (isset($media['poster_path']) && $media['poster_path']) {
                    $this->poster_path = "https://image.tmdb.org/t/p/w780" . $media['poster_path'];
                }

                if (empty($this->excerpt) && isset($media['overview'])) {
                    $this->excerpt = Str::limit($media['overview'], 160);
                }

                $this->searchResults = [];
                $this->searchQuery = '';
                unset($this->formErrors['poster_upload']);
            }
        } catch (\Exception $e) {
            $this->tmdbError = "Failed to fetch artwork: " . $e->getMessage();
            $this->dispatch('notify-toast', type: 'error', message: $this->tmdbError);
        }
    }

    public function edit($id)
    {
        $this->resetForm();

        try {
            $season = DB::table('seasons')->where('id', $id)->first();
            if (!$season) throw new \Exception('Season not found');

            $this->editingId = $season->id;
            $this->series_id = $season->series_id;
            $this->name = $season->name;
            $this->slug = $season->slug;
            $this->excerpt = $season->excerpt;
            $this->poster_path = $season->poster;
            $this->trailer_url = $season->trailer_url;

            $this->currentView = 'form';
        } catch (\Exception $e) {
            $this->dispatch('notify-toast', type: 'error', message: 'Failed to load season for editing');
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

    public function saveSeason()
    {
        $this->formErrors = [];

        try {
            $this->validate([
                'series_id' => 'required|exists:series,id',
                'name' => 'required|min:1|max:255',
                'slug' => 'required|string|max:255',
                'excerpt' => 'nullable|string|max:255',
                'trailer_url' => 'nullable|url|max:255',
                'poster_upload' => 'nullable|image|max:3072|mimes:jpg,jpeg,png,webp',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->formErrors = $e->errors();
            $this->dispatch('notify-toast', type: 'error', message: 'Please fix the validation errors below.');
            return;
        }

        try {
            DB::beginTransaction();

            if ($this->poster_upload) {
                $filename = 'posters/seasons/' . $this->slug . '-' . time() . '.' . $this->poster_upload->getClientOriginalExtension();
                $stored = $this->poster_upload->storeAs('posters/seasons', $filename, 'public');

                if (!$stored) throw new \Exception('Failed to store season artwork.');
                $this->poster_path = $filename;
            }

            if (!$this->poster_path && !$this->poster_upload) {
                $this->formErrors['poster_upload'] = ['Season poster asset is required.'];
                throw new \Exception('A poster image is required');
            }

            $data = [
                'series_id' => $this->series_id,
                'name' => $this->name,
                'slug' => $this->slug,
                'excerpt' => $this->excerpt,
                'poster' => $this->poster_path,
                'trailer_url' => $this->trailer_url,
                'updated_at' => now(),
            ];

            if ($this->editingId) {
                DB::table('seasons')->where('id', $this->editingId)->update($data);
            } else {
                $data['created_at'] = now();
                DB::table('seasons')->insert($data);
            }

            DB::commit();

            $this->dispatch('notify-toast', type: 'success', message: $this->editingId ? 'Season details updated!' : 'New Season deployed!');
            $this->showList();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Season Save Error: ' . $e->getMessage());
            if (empty($this->formErrors)) {
                $this->dispatch('notify-toast', type: 'error', message: 'Save Failed! Check logs.');
            }
        }
    }

    public function delete($id)
    {
        try {
            DB::table('seasons')->where('id', $id)->delete();
            unset($this->seasons);
            $this->dispatch('notify-toast', type: 'success', message: 'Season metadata dropped.');
        } catch (\Exception $e) {
            $this->dispatch('notify-toast', type: 'error', message: 'Failed to delete: ' . $e->getMessage());
        }
    }
};
?>

<div class="max-w-6xl mx-auto space-y-8 relative" x-data @notify-toast.window="Flux.toast({ text: $event.detail.message, variant: $event.detail.type })">

    <div class="flex justify-between items-center bg-[#111111] border border-slate-800 p-6 rounded-2xl shadow-lg">
        <div>
            <h1 class="text-3xl font-black text-white">Season Studio</h1>
            <p class="text-slate-400 mt-1 text-sm">Manage show seasons and collections.</p>
        </div>
        @if($currentView === 'list')
            <button wire:click="showCreateForm" class="px-6 py-3 rounded-xl bg-red-600 hover:bg-red-700 text-white font-bold transition shadow-sm active:scale-95">+ Add Season</button>
        @else
            <button wire:click="showList" class="px-6 py-3 rounded-xl bg-zinc-900 hover:bg-zinc-800 border border-slate-700 text-white font-bold transition shadow-sm">← Back to Seasons</button>
        @endif
    </div>

    @if($currentView === 'list')
        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-slate-400 whitespace-nowrap">
                    <thead class="bg-zinc-900 border-b border-slate-800 uppercase text-[11px] font-semibold text-slate-500">
                        <tr>
                            <th class="px-6 py-4">Series Name</th>
                            <th class="px-6 py-4">Season Collection Name</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        @forelse($this->seasons as $season)
                            <tr class="hover:bg-zinc-900/50 transition-colors">
                                <td class="px-6 py-4 font-bold text-slate-400">
                                    {{ $season->series_title }}
                                </td>
                                <td class="px-6 py-4 flex items-center gap-4">
                                    @if($season->poster)
                                        <img src="{{ str_starts_with($season->poster, 'http') ? $season->poster : Storage::disk('public')->url($season->poster) }}" class="w-8 h-12 object-cover rounded shadow border border-slate-700" alt="{{ $season->name }}">
                                    @else
                                        <div class="w-8 h-12 bg-zinc-800 rounded flex items-center justify-center text-[8px]">No Img</div>
                                    @endif
                                    <div>
                                        <div class="font-bold text-white text-base">
                                            {{ $season->name }}
                                        </div>
                                        <div class="text-[10px] text-slate-500 font-mono">slug: {{ $season->slug }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right space-x-3">
                                    <button wire:click="edit({{ $season->id }})" class="text-indigo-400 hover:text-indigo-300 font-bold transition">Edit</button>
                                    <button wire:click="delete({{ $season->id }})" wire:confirm="Delete this Season partition? Cconnected episode pivot trees will decouple." class="text-red-500 hover:text-red-400 font-bold transition">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-6 py-12 text-center text-slate-500 italic">No seasons managed yet. Add a collection parameters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        @if(!$editingId)
            <div class="bg-black border border-slate-800 rounded-2xl shadow-lg p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-white">1. Auto-Fetch Artwork via TMDB (Optional)</h3>
                </div>
                <input type="text" wire:model.live.debounce.500ms="searchQuery" placeholder="Search show configurations to copy poster artwork..." class="w-full bg-[#111111] border border-slate-700 rounded-xl px-4 py-4 text-white focus:ring-1 focus:ring-red-600">

                @if($tmdbError)
                    <div class="mt-4 p-3 bg-red-950/30 border border-red-800 rounded-lg text-red-400 text-sm">{{ $tmdbError }}</div>
                @endif

                @if(count($searchResults) > 0)
                    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mt-6">
                        @foreach($searchResults as $result)
                            <div wire:click="selectShowArtwork({{ $result['id'] }})" class="cursor-pointer group">
                                <div class="relative aspect-[2/3] overflow-hidden rounded-lg bg-zinc-900 border border-slate-800">
                                    @if(isset($result['poster_path']))
                                        <img src="https://image.tmdb.org/t/p/w200{{ $result['poster_path'] }}" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition" alt="Artwork preview">
                                    @endif
                                    <div class="absolute inset-0 bg-red-600/0 group-hover:bg-red-600/20 transition"></div>
                                </div>
                                <div class="mt-2 text-[11px] font-bold text-slate-400 truncate group-hover:text-white">{{ $result['name'] ?? 'Unknown' }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        <div class="bg-[#111111] border border-slate-800 rounded-2xl shadow-lg p-6">
            <form wire:submit="saveSeason" class="space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                    <!-- POSTER PREVIEW PANEL -->
                    <div class="col-span-1 space-y-4"
                         x-data="{ isUploading: false, progress: 0 }"
                         x-on:livewire-upload-start="isUploading = true"
                         x-on:livewire-upload-finish="isUploading = false; progress = 0"
                         x-on:livewire-upload-error="isUploading = false; progress = 0"
                         x-on:livewire-upload-progress="progress = $event.detail.progress">

                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Season Artwork</label>

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
                                <div class="text-red-600 font-bold text-xs mb-2 italic">PROCESSING ARTWORK...</div>
                                <div class="w-full bg-zinc-800 h-2 rounded-full overflow-hidden mt-2">
                                    <div class="bg-red-600 h-full transition-all duration-300" :style="'width: ' + progress + '%'"></div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-2 relative">
                            <input type="file" wire:model="poster_upload" id="poster_upload" class="sr-only" accept="image/png, image/jpeg, image/jpg, image/webp">
                            <label for="poster_upload" class="block w-full text-center py-2.5 rounded-lg bg-zinc-900 hover:bg-zinc-800 text-white text-xs font-bold border border-slate-700 cursor-pointer transition">
                                Upload Local Variant
                            </label>

                            @if(isset($formErrors['poster_upload']))
                                <p class="text-[10px] text-red-500 font-bold mt-2 text-center leading-tight">{{ $formErrors['poster_upload'][0] }}</p>
                            @endif
                        </div>
                    </div>

                    <!-- METADATA PANEL -->
                    <div class="col-span-1 md:col-span-3 space-y-5">
                        <div class="bg-black border {{ isset($formErrors['series_id']) ? 'border-red-500' : 'border-slate-800' }} rounded-xl p-5">
                            <label class="block text-[10px] font-black tracking-wider text-slate-400 uppercase mb-3">Parent Series Assignment</label>
                            <select wire:model.live="series_id" class="w-full bg-zinc-950 border border-slate-700 rounded-xl px-4 py-3 text-white text-sm focus:border-red-600">
                                <option value="">-- Choose Series Title profile --</option>
                                @foreach($this->seriesList as $show)
                                    <option value="{{ $show->id }}">{{ $show->title }}</option>
                                @endforeach
                            </select>
                            @if(isset($formErrors['series_id'])) <p class="text-red-500 text-xs mt-2 font-bold">{{ $formErrors['series_id'][0] }}</p> @endif
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Season Name (e.g. Season 1)</label>
                                <input type="text" wire:model.live.debounce.300ms="name" placeholder="Season Name" class="w-full bg-black border {{ isset($formErrors['name']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-lg font-bold" {{ empty($series_id) ? 'disabled placeholder="Select series first"' : '' }}>
                                @if(isset($formErrors['name'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['name'][0] }}</p> @endif
                            </div>

                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Auto-Generated Slug</label>
                                <input type="text" wire:model="slug" class="w-full bg-[#111111] border {{ isset($formErrors['slug']) ? 'border-red-500' : 'border-slate-800' }} rounded-xl px-4 py-3 text-slate-400 font-mono text-sm" readonly>
                                @if(isset($formErrors['slug'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['slug'][0] }}</p> @endif
                            </div>
                        </div>

                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 flex justify-between">
                                Short Excerpt Overview <span class="text-slate-600 font-normal normal-case">Max 160 characters</span>
                            </label>
                            <textarea wire:model="excerpt" rows="2" maxlength="160" placeholder="Brief summary notes for cards view..." class="w-full bg-black border {{ isset($formErrors['excerpt']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-slate-300 text-sm"></textarea>
                            @if(isset($formErrors['excerpt'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['excerpt'][0] }}</p> @endif
                        </div>

                        <div class="border-t border-slate-800 pt-5">
                            <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2">Season Trailer URL (Optional)</label>
                            <input type="text" wire:model="trailer_url" placeholder="https://youtube.com/..." class="w-full bg-black border {{ isset($formErrors['trailer_url']) ? 'border-red-500' : 'border-slate-700' }} rounded-xl px-4 py-3 text-white text-sm">
                            @if(isset($formErrors['trailer_url'])) <p class="text-red-500 text-xs mt-1 font-bold">{{ $formErrors['trailer_url'][0] }}</p> @endif
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full py-5 rounded-2xl bg-red-600 hover:bg-red-700 text-white font-black text-xl transition shadow-2xl active:scale-[0.99]" {{ empty($series_id) ? 'disabled' : '' }}>
                    {{ $editingId ? 'COMMIT CHANGES' : 'DEPLOY SEASON' }}
                </button>
            </form>
        </div>
    @endif
</div>
