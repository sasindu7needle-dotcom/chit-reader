<div class="review-fields">
    <h2>Check form details</h2>
    <p>Compare with the original. Edit anything incorrect and confirm fields marked for checking.</p>
    @if($selected->error_information)
        <div class="notice error">{{ $selected->error_information }}</div>
    @endif
    @if($selected->fields->count())
        @php
            $qualityIssues = $selected->fields->first()->evidence['quality']['issues'] ?? [];
        @endphp
        @if($qualityIssues)
            <div class="notice" role="status"><b>Image quality needs review</b><br>{{ implode(' ', $qualityIssues) }} Compare extracted values with the original before approving. Upload a clearer image if you cannot confirm them.</div>
        @endif
        @php
            $normalizer = app(\App\Services\FieldNormalizationService::class);
            $editable = $selected->processing_status === 'review';
        @endphp
        <form method="post" action="/documents/{{ $selected->id }}/review" id="review-form" data-has-errors="{{ $errors->any() ? 'true' : 'false' }}">
            @csrf
            <div class="review-summary" role="status" aria-live="polite">
                <b data-review-summary>Check the marked fields before approving.</b>
                <button type="button" class="text-link" data-next-issue>Go to next issue ↓</button>
            </div>
            @foreach(array_keys(config('ocr.fields')) as $key)
                @php
                    $field = $selected->fields->firstWhere('field_key', $key);
                @endphp
                @continue(!$field)
                @php
                    $value = old('fields.'.$key, $normalizer->forReview($key, $field->reviewer_status ? $field->reviewed_value : $field->raw_text));
                    $decision = old('decisions.'.$key, $field->reviewer_status ?: ($field->recognition_status === 'blank' ? 'blank' : (in_array($field->recognition_status, ['uncertain', 'conflicting']) ? 'unreadable' : 'accepted')));
                    $formatIssues = $decision === 'blank' ? [] : $normalizer->normalize($key, $value)['issues'];
                    $formatError = $errors->first('fields.'.$key) ?: implode(' ', $formatIssues);
                    $decisionError = $errors->first('decisions.'.$key);
                    $needsCheck = $decision === 'unreadable' || $formatError || $decisionError;
                @endphp
                <div class="review-field {{ $needsCheck ? 'has-issue' : '' }}" id="review-{{ $key }}" data-review-field data-key="{{ $key }}">
                    <div class="review-field-heading">
                        <label for="value-{{ $key }}">{{ config('ocr.fields.'.$key) }}</label>
                        <span class="review-indicator" data-field-status>{{ $needsCheck ? 'Check this field' : ($decision === 'blank' ? 'Blank' : 'Confirmed') }}</span>
                    </div>
                    <input id="value-{{ $key }}" name="fields[{{ $key }}]" value="{{ $value }}" data-review-value data-box="{{ json_encode($field->evidence['first']['bounding_box'] ?? []) }}" aria-describedby="issue-{{ $key }}" aria-invalid="{{ $formatError ? 'true' : 'false' }}" @readonly(!$editable)>
                    <p class="field-issue" id="issue-{{ $key }}" data-field-issue @if(!$needsCheck) hidden @endif>{{ $formatError ?: ($decisionError ?: 'Compare with the original, then confirm this value or mark it blank.') }}</p>
                    @if($editable)
                        <label class="review-decision-label" for="decision-{{ $key }}">Your check</label>
                        <select id="decision-{{ $key }}" name="decisions[{{ $key }}]" data-review-decision>
                            <option value="unreadable" @selected($decision === 'unreadable')>Check against original</option>
                            <option value="accepted" @selected($decision === 'accepted')>Matches original</option>
                            <option value="corrected" @selected($decision === 'corrected') hidden>Edited to match original</option>
                            <option value="blank" @selected($decision === 'blank')>Blank on original</option>
                        </select>
                    @endif
                    <details class="evidence-details">
                        <summary>Compare recognition evidence</summary>
                        <p>First reading: {{ $field->evidence['first']['raw_text'] ?? 'Blank / unreadable' }}</p>
                        <p>Second reading: {{ $field->evidence['second']['raw_text'] ?? 'Blank / unreadable' }}</p>
                        @if($field->evidence['crop']['path'] ?? null)
                            <img src="/documents/{{ $selected->id }}/crop/{{ $key }}" alt="Original {{ config('ocr.fields.'.$key) }}" loading="lazy">
                        @endif
                    </details>
                </div>
            @endforeach
            @if($editable)
                <div class="review-actions">
                    <button name="action" value="draft" class="button secondary">Save draft</button>
                    <button name="action" value="approve" class="button primary">Approve record ✓</button>
                </div>
            @endif
        </form>
    @else
        <p>Extraction has not finished. Check the processing queue for updates.</p>
    @endif
</div>
