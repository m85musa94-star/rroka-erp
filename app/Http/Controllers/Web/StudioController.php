<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\StudioAsset;
use App\Services\StudioStorage;
use App\Support\ActivityLog;
use App\Support\ListView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudioController extends Controller
{
    public function index(Request $request): View
    {
        $cat = fn (string $c) => fn ($q) => $q->where('category', $c);
        $filters = [];
        foreach (StudioAsset::CATEGORIES as $c) {
            $filters[strtolower($c)] = ['label' => __("rroka.studio_category.$c"), 'group' => 'category', 'apply' => $cat($c)];
        }
        $lv = new ListView($request,
            filters: $filters + [
                'with_project' => ['label' => __('مرتبطة بمشروع'), 'group' => 'link', 'apply' => fn ($q) => $q->whereNotNull('project_id')],
                'unused' => ['label' => __('لم تُستخدم في عرض سعر'), 'group' => 'use', 'apply' => fn ($q) => $q->whereDoesntHave('quotationLines')],
                'this_month' => ['label' => __('هذا الشهر'), 'group' => 'date', 'apply' => fn ($q) => $q->where('created_at', '>=', now()->startOfMonth())],
            ],
            groups: [
                'category' => ['label' => __('التصنيف'), 'key' => fn ($a) => $a->category, 'title' => fn ($a) => __("rroka.studio_category.$a->category")],
                'client' => ['label' => __('العميل'), 'key' => fn ($a) => $a->client_id ?? 0, 'title' => fn ($a) => $a->client?->business_name ?? __('بلا عميل')],
                'project' => ['label' => __('المشروع'), 'key' => fn ($a) => $a->project_id ?? 0, 'title' => fn ($a) => $a->project ? $a->project->project_no.' — '.$a->project->title : __('بلا مشروع')],
            ],
            views: ['kanban', 'list'],
            keep: ['client_id', 'project_id'],
        );

        $query = $lv->applyFilters(StudioAsset::query()->with('client:id,business_name', 'project:id,project_no,title')->orderByDesc('id'))
            ->when($request->integer('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->integer('project_id'), fn ($q, $id) => $q->where('project_id', $id));
        if ($lv->q !== '') {
            $s = $lv->q;
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$s}%")->orWhere('tags', 'ilike', "%{$s}%")
                ->orWhere('asset_no', 'ilike', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c->where('business_name', 'ilike', "%{$s}%"))
                ->orWhereHas('project', fn ($p) => $p->where('title', 'ilike', "%{$s}%")->orWhere('project_no', 'ilike', "%{$s}%")));
        }

        return view('studio.index', [
            'lv' => $lv,
            'assets' => $lv->group ? null : $query->paginate(48)->withQueryString(),
            'groups' => $lv->group ? $lv->grouped($query->limit(1000)->get()) : null,
            'ready' => StudioStorage::isReady(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('studio.form', [
            'asset' => new StudioAsset([
                'client_id' => $request->integer('client_id') ?: null,
                'project_id' => $request->integer('project_id') ?: null,
                'category' => $request->query('category', $request->integer('client_id') ? 'CLIENT_REFERENCE' : 'FINISHED_WORK'),
            ]),
            ...$this->choices(),
            'ready' => StudioStorage::isReady(),
        ]);
    }

    public function store(Request $request, StudioStorage $storage): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', 'max:20480', 'mimetypes:image/jpeg,image/png,image/webp'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        $meta = collect($data)->except('files', 'title')->all();

        $created = [];
        $existing = [];
        try {
            foreach ($request->file('files') as $file) {
                $title = $data['title'] ?? null;
                $title = $title ? (count($data['files']) > 1 ? $title.' ('.(count($created) + count($existing) + 1).')' : $title)
                    : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                [$asset, $existed] = $storage->store($file, $meta + ['title' => $title], $request->user()->id);
                $existed ? $existing[] = $asset : $created[] = $asset;
            }
        } catch (\Throwable $e) {
            // The request transaction rolls back: remove the files already written for it.
            foreach ($created as $asset) {
                $storage->deleteFiles($asset);
            }
            throw $e;
        }

        $message = __('تم رفع :n صورة.', ['n' => count($created)]);
        if ($existing) {
            $message .= ' '.__('صور موجودة مسبقًا لم تُكرَّر: :list', ['list' => collect($existing)->pluck('asset_no')->join(__('، '))]);
        }
        $target = count($created) === 1 && ! $existing ? route('studio.show', $created[0]) : route('studio.index');

        return redirect($target)->with('ok', $message);
    }

    public function show(StudioAsset $asset): View
    {
        return view('studio.show', [
            'asset' => $asset->load('client', 'project', 'quotationLines'),
            'quotations' => Quotation::whereIn('id', $asset->quotationLines->pluck('quotation_id'))->orderByDesc('id')->get(['id', 'quotation_no', 'status']),
            'uploader' => $asset->uploaded_by ? DB::table('users')->where('id', $asset->uploaded_by)->value('name') : null,
            'activity' => ActivityLog::for(['studio_assets' => [$asset->id]]),
        ]);
    }

    public function edit(StudioAsset $asset): View
    {
        return view('studio.form', ['asset' => $asset, ...$this->choices(), 'ready' => true]);
    }

    public function update(Request $request, StudioAsset $asset): RedirectResponse
    {
        $asset->update($request->validate($this->rules() + ['title' => ['required', 'string', 'max:200']]));

        return redirect()->route('studio.show', $asset)->with('ok', __('تم تحديث بيانات الصورة.'));
    }

    public function destroy(StudioAsset $asset, StudioStorage $storage): RedirectResponse
    {
        $asset->delete(); // the database refuses if a quotation uses it
        $storage->deleteFiles($asset);

        return redirect()->route('studio.index')->with('ok', __('تم حذف الصورة.'));
    }

    /** Streams the image after the permission check; nothing is publicly reachable. */
    public function file(StudioAsset $asset, string $variant): StreamedResponse
    {
        $path = $variant === 'thumb' && $asset->thumb_path ? $asset->thumb_path : $asset->path;
        $mime = $path === $asset->path ? $asset->mime_type : 'image/jpeg';
        abort_unless(Storage::disk($asset->disk)->exists($path), 404);

        return Storage::disk($asset->disk)->response($path, $asset->asset_no, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Images a quotation for the given customer may use (for the picker). */
    public function picker(Request $request): JsonResponse
    {
        $clientId = $request->integer('client_id') ?: null;
        $q = trim((string) $request->query('q', ''));
        $assets = StudioAsset::query()->usableFor($clientId)->with('client:id,business_name')
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('title', 'ilike', "%{$q}%")
                ->orWhere('tags', 'ilike', "%{$q}%")->orWhere('asset_no', 'ilike', "%{$q}%")))
            ->when($request->query('category'), fn ($query, $c) => $query->where('category', $c))
            ->orderByRaw('client_id = ? desc nulls last', [$clientId ?? 0])->orderByDesc('id')
            ->limit(60)->get();

        return response()->json($assets->map(fn ($a) => [
            'id' => $a->id, 'no' => $a->asset_no, 'title' => $a->title,
            'category' => __("rroka.studio_category.$a->category"),
            'client' => $a->client?->business_name, 'thumb' => $a->url(),
        ]));
    }

    private function rules(): array
    {
        return [
            'category' => ['required', Rule::in(StudioAsset::CATEGORIES)],
            'client_id' => ['nullable', 'integer', 'exists:clients,id', 'required_if:category,CLIENT_REFERENCE'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'tags' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function choices(): array
    {
        return [
            'clients' => Client::orderBy('business_name')->get(['id', 'business_name', 'client_no']),
            'projects' => Project::orderByDesc('id')->get(['id', 'project_no', 'title', 'client_id']),
        ];
    }
}
