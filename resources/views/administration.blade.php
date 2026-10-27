@auth
@if($page==='upload' && \Illuminate\Support\Facades\DB::table('form_templates')->exists())
<div class="notice"><label>Form template <select name="template_id" form="upload-form"><option value="">Detect printed labels automatically</option>@foreach(\Illuminate\Support\Facades\DB::table('form_templates')->get() as $template)<option value="{{ $template->id }}" @selected((string) request('template') === (string) $template->id)>{{ $template->name }}</option>@endforeach</select></label></div>
@endif
@if($page==='students')<div class="export-bar"><a href="/export?format=xlsx" class="button secondary">↓ Export Excel (.xlsx)</a><p>Exports include your verified student inquiries.</p></div>@endif
@if(auth()->user()->role==='admin' && $page==='settings')
<section class="table-card settings-team-card"><div class="section-heading"><div><h2>Add a team member</h2><p>Each member can access their own uploaded documents.</p></div></div><form class="admin-form" method="post" action="/admin/users">@csrf<label>Full name<input name="name" required></label><label>Email address<input type="email" name="email" required></label><label>Initial password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Role<select name="role"><option value="reviewer">Reviewer</option><option value="admin">Administrator</option></select></label><button class="button primary">Create account ＋</button></form></section>
@endif
@endauth
