{{-- Email Editor: replaces core's mail::html layout. Custom layout off = core's own layout, unchanged. --}}
@inject('emailEditorLayout', 'MrCaspan\EmailEditor\LayoutSettings')
@if ($emailEditorLayout->enabled())
@include('email-editor::html')
@else
@include('email-editor-core::html')
@endif
