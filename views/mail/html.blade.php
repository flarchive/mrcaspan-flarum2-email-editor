{{-- Email Editor: replaces core's mail::html layout. Custom layout off = core's own layout, with only the title / greeting / sign-off switches applied. --}}
@inject('emailEditorLayout', 'MrCaspan\EmailEditor\LayoutSettings')
@if ($emailEditorLayout->enabled())
@include('email-editor::html')
@else
@php($emailEditorShow = $emailEditorLayout->all())
@include('email-editor-core::html', [
    'greeting' => $emailEditorShow['show_greeting'] ? ($greeting ?? null) : false,
    'signoff' => $emailEditorShow['show_signoff'] ? ($signoff ?? null) : false,
    'header' => $emailEditorShow['show_title'] ? ($header ?? '') : '',
])
@endif
