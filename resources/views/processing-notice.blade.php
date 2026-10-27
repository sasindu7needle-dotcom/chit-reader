@auth
@if(in_array($page,['dashboard','upload','queue','review']))
<div id="processing-notices">
@if(!filled(config('ocr.key')))
<div class="notice error" role="alert"><b>OCR setup required</b><br>Gemini API key is missing. Add <code>GEMINI_API_KEY</code> to the server <code>.env</code> file. Your uploaded originals are safe; retry failed forms after adding the key.</div>
@elseif($documents->contains(fn($document)=>$document->processing_status==='queued'&&$document->created_at->lt(now()->subMinute())))
<div class="notice" role="status"><b>Waiting for a processing worker</b><br>If this status persists, start the local worker with <code>powershell -File scripts/start-worker.ps1</code>.</div>
@endif
@foreach($documents->where('processing_status','failed') as $failedDocument)
<div class="notice error" role="alert"><b>Form #{{ $failedDocument->id }} could not be processed</b><br>{{ $failedDocument->error_information }} <a href="/?page=review&document={{ $failedDocument->id }}">View document ↗</a></div>
@endforeach
</div>
<div id="processing-monitor"></div>
<script src="{{ asset('processing.js') }}?v={{ filemtime(public_path('processing.js')) }}" defer></script>
@endif
@endauth
