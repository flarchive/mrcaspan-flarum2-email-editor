{{--
    Email Editor custom HTML layout. Based on core's mail::html layout, with
    the options from the Email Editor admin page. Tables are used for the
    outer frame because Outlook ignores most CSS on <div>s.
--}}
@inject('url', 'Flarum\Http\UrlGenerator')
@inject('emailEditorLayout', 'MrCaspan\EmailEditor\LayoutSettings')
@php
    $L = $emailEditorLayout->all();
    $font = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"';
    $linkColor = $L['color_accent'] ?: null;
    $greetingText = (! isset($greeting) || $greeting !== false) && $L['show_greeting']
        ? $translator->trans('core.email.greeting', ['displayName' => $username ?? ''])
        : '';
    $signoffText = (! isset($signoff) || $signoff !== false) && $L['show_signoff']
        ? $translator->trans('core.email.signoff', ['forumTitle' => $settings->get('forum_title')])
        : '';
@endphp
<!DOCTYPE html>
<html lang="{{ $translator->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $settings->get('forum_title') }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: {{ $L['color_bg'] }};
        }
        .EmailEditor-body {
            font-family: {!! $font !!};
            font-size: 14px;
            line-height: 1.5;
            color: {{ $L['color_text'] }};
        }
        @if ($linkColor)
        .EmailEditor-body a {
            color: {{ $linkColor }};
        }
        @endif
        .EmailEditor-body h2 {
            font-size: 20px;
            margin: 16px 0 8px;
            color: {{ $linkColor ?: $L['color_text'] }};
        }
        .content-preview {
            background-color: #f7f7f7;
            color: #333333;
            padding: 15px;
            margin: 15px 0;
            border: 1px solid {{ $L['color_border'] }};
            border-radius: 5px;
        }
        .footer {
            margin-top: 20px;
            font-size: 12px;
            opacity: 0.75;
        }
        .signoff {
            margin-top: 20px;
            font-style: italic;
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: {{ $L['color_bg'] }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $L['color_bg'] }}" style="background-color: {{ $L['color_bg'] }};">
    <tr>
        <td align="center" style="padding: 20px 10px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $L['color_card'] }}" style="width: 100%; max-width: 600px; background-color: {{ $L['color_card'] }}; border: 1px solid {{ $L['color_border'] }}; border-radius: 6px;">
                <tr>
                    <td class="EmailEditor-body" align="left" style="padding: 20px; font-family: {!! $font !!}; font-size: 14px; line-height: 1.5; color: {{ $L['color_text'] }};">

                        <div class="header">
                            <div class="Header-title" style="text-align: {{ $L['logo_align'] }};">
                                <a href="{{ $url->to('forum')->base() }}" id="home-link" style="{{ $linkColor ? 'color: '.$linkColor.';' : '' }} text-decoration: none; font-size: 20px; font-weight: bold;">
                                    @if ($logoUrl)
                                        <img src="{{ $logoUrl }}" alt="{{ $settings->get('forum_title') }}" class="Header-logo"@if ($logoWidth ?? null) width="{{ $logoWidth }}" height="{{ $logoHeight }}"@endif style="display: {{ $L['logo_align'] === 'center' ? 'inline-block' : 'block' }}; border: 0; height: auto; max-width: 100%;">
                                    @else
                                        {{ $settings->get('forum_title') }}
                                    @endif
                                </a>
                            </div>
                            @if ($L['show_title'])
                                {{ $header ?? '' }}
                            @endif
                        </div>

                        <div class="content">
                            @if (trim(strip_tags($greetingText)) !== '')
                                <div class="greeting">
                                    <p>{!! $greetingText !!}</p>
                                </div>
                            @endif
                            <div class="main-content">
                                {{ $content ?? '' }}
                            </div>
                            @if (trim(strip_tags($signoffText)) !== '')
                                <div class="signoff">
                                    <p>{!! $signoffText !!}</p>
                                </div>
                            @endif
                        </div>

                        <div class="footer">
                            {{ $footer ?? '' }}
                            @if ($L['footer_text'] !== '')
                                <div class="EmailEditor-footerText">{!! $formatter->convert($L['footer_text']) !!}</div>
                            @endif
                        </div>

                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
