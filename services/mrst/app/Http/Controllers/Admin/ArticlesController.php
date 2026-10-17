<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrawlSource;
use App\Models\SourceDocument;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticlesController extends Controller
{
    public function index(Request $request): View
    {
        $sourceId = (int) $request->query('source_id', 0);

        $query = SourceDocument::query()
            ->with('source')
            ->orderByDesc('fetched_at')
            ->orderByDesc('id');
        if ($sourceId > 0) {
            $query->where('source_id', $sourceId);
        }

        return view('admin.articles.index', [
            'documents'      => $query->paginate(25)->withQueryString(),
            'sources'        => CrawlSource::query()->where('is_enabled', true)->orderBy('name')->get(),
            'activeSourceId' => $sourceId > 0 ? $sourceId : null,
        ]);
    }
}
