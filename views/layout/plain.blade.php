@inject('emailEditorLayout', 'MrCaspan\EmailEditor\LayoutSettings')
@php
    $L = $emailEditorLayout->all();
    $greetingText = (! isset($greeting) || $greeting !== false) && $L['show_greeting']
        ? trim($translator->trans('core.email.greeting', ['displayName' => $username ?? '']))
        : '';
    $signoffText = (! isset($signoff) || $signoff !== false) && $L['show_signoff']
        ? trim($translator->trans('core.email.signoff', ['forumTitle' => $settings->get('forum_title')]))
        : '';
@endphp
@if ($L['show_title'])
{!! $formatter->htmlToPlain($header ?? '') !!}

@endif
@if ($greetingText !== '')
{!! $greetingText !!}

@endif
{!! $formatter->htmlToPlain($content ?? '') !!}

@if ($signoffText !== '')
- {!! $signoffText !!} -

@endif

{!! $formatter->htmlToPlain($footer ?? '') !!}
@if ($L['footer_text'] !== '')

{!! $formatter->htmlToPlain($formatter->convert($L['footer_text'])) !!}
@endif
