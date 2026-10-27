<section class="table-card drive-upload-card">
    <div class="section-heading"><div><h2>Import from Google Drive</h2><p>Paste an image or folder link.</p></div></div>
    <form method="post" action="{{ route('drive-imports.store') }}" class="drive-import-form">
        @csrf
        <label for="drive-link">Google Drive file or folder link</label>
        <input id="drive-link" name="drive_link" type="url" value="{{ old('drive_link') }}" placeholder="https://drive.google.com/drive/folders/..." required maxlength="2048" aria-describedby="drive-help">
        <p id="drive-help">Set Drive sharing to <b>Anyone with the link - Viewer</b>. </p>
        @auth
            <button class="button primary">Import &amp; process</button>
        @else
            <a href="{{ route('login') }}" class="button primary">Sign in to import</a>
        @endauth
    </form>
</section>
