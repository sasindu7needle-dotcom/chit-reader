@if($driveImports->isNotEmpty())
<section class="table-card drive-upload-card" data-drive-imports>
    <div class="section-heading"><div><h2>Google Drive imports</h2></div></div>
    @foreach($driveImports as $import)
        <div class="drive-import-row">
            <b>Import #{{ $import->id }} &middot; {{ ucfirst($import->kind) }}</b>
            <p>{{ in_array($import->status, ['queued', 'listing']) ? 'Finding images...' : ($import->status === 'failed' ? 'Could not read Drive' : 'Finished finding images') }}</p>
            <p>{{ number_format($import->counts->sum()) }} found &middot; {{ number_format($import->counts['imported'] ?? 0) }} imported &middot; {{ number_format($import->counts['queued'] ?? 0) }} pending &middot; {{ number_format($import->counts['failed'] ?? 0) }} failed</p>
            @if($import->status === 'listed' && $import->counts->sum() === 0)<p>No supported images found directly inside this folder.</p>@endif
            @if($import->error_information)<p class="preview-error">{{ $import->error_information }}</p>@endif
            @foreach($import->failures as $failure)<p class="preview-error">{{ $failure->filename }}: {{ $failure->error_information }}</p>@endforeach
            @if($import->status === 'failed' || ($import->counts['failed'] ?? 0) > 0)
                <form method="post" action="{{ route('drive-imports.retry', $import->id) }}">@csrf<button class="button secondary">Retry failed imports</button></form>
            @endif
        </div>
    @endforeach
</section>
@endif
@auth
@if($driveImports->contains(fn ($import) => in_array($import->status, ['queued', 'listing']) || ($import->counts['queued'] ?? 0) > 0) || $documents->contains(fn ($document) => in_array($document->processing_status, ['queued', 'processing'])))
<script>
setTimeout(() => {
    if (!document.hidden && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
        window.location.reload();
    }
}, 15000);
</script>
@endif
@endauth
