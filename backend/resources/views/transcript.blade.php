<!doctype html>
<html lang="{{ $conversation->language }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Conversation — {{ $conversation->visitor_name ?: 'Visitor' }}</title>
  <style>
    :root { --accent: {{ $accent }}; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f4f6f9; color: #29323d;
      font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; font-size: 14px; }
    .wrap { max-width: 760px; margin: 0 auto; padding: 16px; }
    .head { padding: 4px 4px 14px; border-bottom: 1px solid #e6eaf0; margin-bottom: 16px; }
    .head h1 { margin: 0; font-size: 18px; font-weight: 600; }
    .head .sub { margin-top: 3px; font-size: 12.5px; color: #8a94a3; }
    .thread { display: flex; flex-direction: column; gap: 12px; }
    .row { display: flex; flex-direction: column; max-width: 78%; }
    .row.visitor { align-self: flex-end; align-items: flex-end; }
    .row.agent, .row.bot, .row.system { align-self: flex-start; }
    .sender { font-size: 11px; color: #8a94a3; margin: 0 4px 2px; }
    .bubble { padding: 8px 12px; border-radius: 12px; line-height: 1.4;
      white-space: pre-wrap; word-break: break-word; background: #fff; border: 1px solid #e6eaf0; }
    .row.visitor .bubble { background: var(--accent); color: #fff; border-color: var(--accent); }
    .row.bot .bubble { border-style: dashed; }
    .system { align-self: center; color: #8a94a3; font-size: 12px; }
    .time { font-size: 10.5px; opacity: .7; margin-top: 4px; }
    .att-img { display: block; margin-top: 6px; }
    .att-img img { max-width: 220px; max-height: 220px; border-radius: 8px; display: block; }
    .att-file { display: inline-flex; align-items: center; gap: 6px; margin-top: 6px; padding: 6px 9px;
      border-radius: 8px; background: rgba(0,0,0,.05); text-decoration: none; color: inherit; font-size: 12.5px; }
    .row.visitor .att-file { background: rgba(255,255,255,.2); color: #fff; }
    .empty { color: #8a94a3; text-align: center; padding: 40px; }
    @media (prefers-color-scheme: dark) {
      body { background: #1b2430; color: #e6eaf0; }
      .head { border-color: #2b3646; } .head .sub, .sender, .system, .time { color: #97a1b0; }
      .bubble { background: #232f3e; border-color: #2b3646; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="head">
      <h1>{{ $conversation->visitor_name ?: 'Visitor' }}</h1>
      <div class="sub">
        # {{ $conversation->inbox->name }} ·
        {{ str_replace('_', ' ', $conversation->status->value) }} ·
        {{ $conversation->created_at->format('M j, Y g:i A') }}
      </div>
    </div>

    <div class="thread">
      @forelse ($conversation->messages as $message)
        @php $type = $message->sender_type->value; @endphp
        @if ($type === 'system')
          <div class="system">{{ $message->body }}</div>
        @else
          <div class="row {{ $type }}">
            <div class="sender">
              @switch($type)
                @case('visitor') {{ $conversation->visitor_name ?: 'Visitor' }} @break
                @case('bot') {{ $botName }} @break
                @default {{ $message->agent?->displayName() ?: 'Agent' }}
              @endswitch
            </div>
            <div class="bubble">
              @if ($message->body !== '')<div class="body">{{ $message->body }}</div>@endif
              @foreach ($message->attachments as $att)
                @php $url = rtrim(config('app.url'), '/') . '/api/v1/public/attachments/' . $att->uuid; @endphp
                @if ($att->isImage())
                  <a class="att-img" href="{{ $url }}" target="_blank" rel="noopener">
                    <img src="{{ $url }}" alt="{{ $att->name }}" loading="lazy">
                  </a>
                @else
                  <a class="att-file" href="{{ $url }}" target="_blank" rel="noopener">&#128206; {{ $att->name }}</a>
                @endif
              @endforeach
              <div class="time">{{ $message->created_at->format('g:i A') }}</div>
            </div>
          </div>
        @endif
      @empty
        <div class="empty">No messages in this conversation.</div>
      @endforelse
    </div>
  </div>
</body>
</html>
