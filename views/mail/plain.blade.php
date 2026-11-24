{{-- Email Editor: replaces core's mail::plain layout. Custom layout off = core's own layout, unchanged. --}}
@inject('emailEditorLayout', 'MrCaspan\EmailEditor\LayoutSettings')
@if ($emailEditorLayout->enabled())
@include('email-editor::plain')
@else
@include('email-editor-core::plain')
@endif
