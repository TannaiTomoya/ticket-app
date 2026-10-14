<x-mail::message>
# チケットの購入が完了しました

{{ $order->user->name }} 様

「{{ $order->event->title }}」のチケットをお買い上げいただき、ありがとうございます。

- 日時: {{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
- 会場: {{ $order->event->venue }}
- 注文番号: {{ $order->id }}

<x-mail::table>
| 券種 | 単価 | 枚数 | 小計 |
|:-----|-----:|-----:|-----:|
@foreach ($order->items as $item)
| {{ $item->ticketType->name }} | {{ number_format($item->unit_price) }}円 | {{ $item->quantity }}枚 | {{ number_format($item->subtotal()) }}円 |
@endforeach
| **合計** | | | **{{ number_format($order->total_amount) }}円** |
</x-mail::table>

<x-mail::button :url="route('orders.show', $order)">
注文の詳細とチケットを見る
</x-mail::button>

当日は、注文詳細に表示されるチケットのコードを受付でお見せください。

{{ config('app.name') }}
</x-mail::message>
